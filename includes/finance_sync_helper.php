<?php
/**
 * includes/finance_sync_helper.php
 *
 * ใช้สำหรับ sync transaction จากโมดูลอื่น (e_Borrow, asset, consumables,
 * nurse_timesheet, scholarship) เข้า Cash Book (sys_finance_transactions)
 * โดย idempotent ผ่านคีย์ (source_module, source_id)
 *
 * ใช้แทนการเรียก HTTP ไป ajax_finance.php?action=txn:upsert_from_source
 * เพื่อให้ทำงานใน DB transaction context เดียวกับ caller ได้
 */
declare(strict_types=1);

function finance_sync_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            kind ENUM('income','expense') NOT NULL,
            icon VARCHAR(50) NULL, color VARCHAR(20) DEFAULT '#64748b',
            sort_order INT DEFAULT 0, is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_name_kind (name, kind),
            KEY idx_kind_active (kind, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            txn_date DATE NOT NULL,
            kind ENUM('income','expense') NOT NULL,
            category_id INT UNSIGNED NULL,
            amount DECIMAL(12,2) NOT NULL,
            description VARCHAR(500) NULL,
            reference VARCHAR(100) NULL,
            payment_method VARCHAR(50) NULL,
            note TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by INT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_date_kind (txn_date, kind), KEY idx_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD COLUMN source_module VARCHAR(50) NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD COLUMN source_id VARCHAR(50) NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions MODIFY COLUMN source_id VARCHAR(50) NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD INDEX idx_source (source_module, source_id)"); } catch (PDOException) {}
        $done = true;
    } catch (PDOException $e) {
        error_log('[finance_sync_ensure_schema] ' . $e->getMessage());
    }
}

/**
 * Upsert finance transaction จาก source module
 *
 * @param PDO   $pdo
 * @param array $p {
 *   source_module: string,  // เช่น 'eborrow_payment', 'consumables_txn'
 *   source_id:     string,  // ID อ้างอิงในโมดูลต้นทาง
 *   kind:          'income'|'expense',
 *   amount:        float,
 *   txn_date:      string YYYY-MM-DD,
 *   description:   string,
 *   category_name: string (optional),
 *   reference:     string (optional),
 *   note:          string (optional),
 *   admin_id:      int|null (optional)
 * }
 * @return array{ok:bool, id?:int, mode?:string, error?:string}
 */
function finance_sync_upsert(PDO $pdo, array $p): array
{
    finance_sync_ensure_schema($pdo);

    $srcMod  = trim((string)($p['source_module'] ?? ''));
    $srcId   = trim((string)($p['source_id']     ?? ''));
    $kind    = ($p['kind'] ?? 'expense') === 'income' ? 'income' : 'expense';
    $amount  = (float)($p['amount']   ?? 0);
    $txnDate = trim((string)($p['txn_date'] ?? date('Y-m-d')));
    $desc    = trim((string)($p['description']   ?? ''));
    $catName = trim((string)($p['category_name'] ?? ''));
    $ref     = trim((string)($p['reference']     ?? ''));
    $note    = trim((string)($p['note']          ?? ''));
    $adminId = isset($p['admin_id']) ? (int)$p['admin_id'] : null;

    if ($srcMod === '' || $srcId === '') {
        return ['ok' => false, 'error' => 'ต้องระบุ source_module + source_id'];
    }
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'จำนวนเงินต้อง > 0'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) {
        return ['ok' => false, 'error' => 'รูปแบบวันที่ไม่ถูกต้อง'];
    }

    try {
        // หา category_id จากชื่อ
        $catId = null;
        if ($catName !== '') {
            $cs = $pdo->prepare("SELECT id FROM sys_finance_categories WHERE name=? AND kind=? LIMIT 1");
            $cs->execute([$catName, $kind]);
            $catId = $cs->fetchColumn() ?: null;
        }

        // ตรวจสอบว่ามี record อยู่แล้วไหม
        $ex = $pdo->prepare("SELECT id FROM sys_finance_transactions WHERE source_module=? AND source_id=? LIMIT 1");
        $ex->execute([$srcMod, $srcId]);
        $existingId = (int)$ex->fetchColumn();

        if ($existingId > 0) {
            $pdo->prepare("UPDATE sys_finance_transactions
                SET txn_date=?, kind=?, category_id=?, amount=?, description=?,
                    reference=?, note=?, updated_by=?
                WHERE id=?")
                ->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $ref ?: null, $note ?: null, $adminId, $existingId]);
            return ['ok' => true, 'id' => $existingId, 'mode' => 'updated'];
        }
        $pdo->prepare("INSERT INTO sys_finance_transactions
            (txn_date, kind, category_id, amount, description, reference, note,
             source_module, source_id, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $ref ?: null, $note ?: null,
                       $srcMod, $srcId, $adminId, $adminId]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'mode' => 'created'];
    } catch (Throwable $e) {
        error_log('[finance_sync_upsert] ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
