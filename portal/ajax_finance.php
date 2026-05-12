<?php
// portal/ajax_finance.php — Finance module (cash book) AJAX
// Entities: txn (income/expense rows), category (CRUD)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper = ($adminRole === 'superadmin');
$canFinance = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_finance']);
if (!$canFinance) { echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้โมดูลการเงิน']); exit; }

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// ── Self-heal schema ─────────────────────────────────────────────────────
function ensure_finance_schema(PDO $pdo): void {
    static $done = false; if ($done) return;
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
        // Self-heal: เพิ่ม columns ใหม่สำหรับ receipt + cross-module sourcing
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD COLUMN receipt_no VARCHAR(30) NULL UNIQUE"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD COLUMN source_module VARCHAR(50) NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD COLUMN source_id BIGINT UNSIGNED NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD INDEX idx_source (source_module, source_id)"); } catch (PDOException) {}
        // First-run seed (idempotent via INSERT IGNORE)
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sys_finance_categories")->fetchColumn();
        if ($cnt === 0) {
            $defaults = [
                ['ค่ารักษาผู้ป่วย','income','fa-stethoscope','#0ea5e9',1],
                ['ค่ายา/วัสดุ','income','fa-pills','#10b981',2],
                ['ค่าตรวจ Lab','income','fa-vial','#a855f7',3],
                ['Claim ประกัน','income','fa-shield-halved','#3b82f6',4],
                ['รายรับอื่นๆ','income','fa-circle-plus','#64748b',9],
                ['จัดซื้อวัสดุการแพทย์','expense','fa-syringe','#dc2626',1],
                ['จัดซื้อครุภัณฑ์','expense','fa-boxes-stacked','#d97706',2],
                ['เงินเดือน/ค่าจ้าง','expense','fa-user-tie','#6366f1',3],
                ['ค่าสาธารณูปโภค','expense','fa-bolt','#eab308',4],
                ['ค่าซ่อมบำรุง','expense','fa-wrench','#64748b',5],
                ['รายจ่ายอื่นๆ','expense','fa-circle-minus','#64748b',9],
            ];
            $ins = $pdo->prepare("INSERT IGNORE INTO sys_finance_categories (name,kind,icon,color,sort_order) VALUES (?,?,?,?,?)");
            foreach ($defaults as $d) $ins->execute($d);
        }
    } catch (PDOException $e) { error_log('[finance] schema: ' . $e->getMessage()); }
    $done = true;
}
ensure_finance_schema($pdo);

$action = $_REQUEST['action'] ?? '';

try {
    // ── List + summary (GET allowed) ───────────────────────────────────────
    if ($action === 'list') {
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-t');
        $kind = $_GET['kind'] ?? '';
        $catId = (int)($_GET['category_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = ['t.txn_date BETWEEN :from AND :to'];
        $params = [':from' => $from, ':to' => $to];
        if ($kind === 'income' || $kind === 'expense') { $where[] = 't.kind = :k'; $params[':k'] = $kind; }
        if ($catId > 0) { $where[] = 't.category_id = :cid'; $params[':cid'] = $catId; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_finance_transactions t {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT t.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
            FROM sys_finance_transactions t LEFT JOIN sys_finance_categories c ON c.id = t.category_id
            {$whereSql} ORDER BY t.txn_date DESC, t.id DESC LIMIT {$perPage} OFFSET {$offset}");
        // หมายเหตุ: t.* รวม receipt_no, source_module, source_id ที่เพิ่งเพิ่มแล้ว
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Summary in same date range (ignore kind/category filter for summary totals)
        $sumStmt = $pdo->prepare("SELECT
            SUM(CASE WHEN kind='income'  THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END) AS total_expense,
            COUNT(*) AS count_all
            FROM sys_finance_transactions WHERE txn_date BETWEEN :from AND :to");
        $sumStmt->execute([':from' => $from, ':to' => $to]);
        $sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $cats = $pdo->query("SELECT id, name, kind, icon, color FROM sys_finance_categories WHERE is_active=1 ORDER BY kind, sort_order, name")
            ->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'summary' => [
                'income'  => (float)($sum['total_income']  ?? 0),
                'expense' => (float)($sum['total_expense'] ?? 0),
                'net'     => (float)($sum['total_income'] ?? 0) - (float)($sum['total_expense'] ?? 0),
                'count'   => (int)($sum['count_all'] ?? 0),
            ],
            'categories' => $cats,
            'filters' => ['from' => $from, 'to' => $to, 'kind' => $kind, 'category_id' => $catId],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Below actions require POST + CSRF
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
    validate_csrf_or_die();

    if ($action === 'txn:create' || $action === 'txn:update') {
        $isCreate = $action === 'txn:create';
        $id = (int)($_POST['id'] ?? 0);
        $txnDate = trim((string)($_POST['txn_date'] ?? ''));
        $kind = ($_POST['kind'] ?? '') === 'expense' ? 'expense' : 'income';
        $catId = (int)($_POST['category_id'] ?? 0) ?: null;
        $amount = (float)($_POST['amount'] ?? 0);
        $desc = trim((string)($_POST['description'] ?? ''));
        $ref = trim((string)($_POST['reference'] ?? ''));
        $pay = trim((string)($_POST['payment_method'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if (!$txnDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) { echo json_encode(['ok' => false, 'message' => 'รูปแบบวันที่ไม่ถูกต้อง']); exit; }
        if ($amount <= 0) { echo json_encode(['ok' => false, 'message' => 'จำนวนเงินต้อง > 0']); exit; }

        if ($isCreate) {
            $stmt = $pdo->prepare("INSERT INTO sys_finance_transactions
                (txn_date, kind, category_id, amount, description, reference, payment_method, note, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $ref ?: null, $pay ?: null, $note ?: null, $adminId ?: null, $adminId ?: null]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); exit;
        } else {
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
            $stmt = $pdo->prepare("UPDATE sys_finance_transactions SET txn_date=?, kind=?, category_id=?, amount=?, description=?, reference=?, payment_method=?, note=?, updated_by=? WHERE id=?");
            $stmt->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $ref ?: null, $pay ?: null, $note ?: null, $adminId ?: null, $id]);
            echo json_encode(['ok' => true]); exit;
        }
    }

    if ($action === 'txn:delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
        $pdo->prepare("DELETE FROM sys_finance_transactions WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }

    // ── Upsert from source module (cross-module integration) ──
    // Inputs: source_module, source_id (string), kind, amount, txn_date, description, category_name
    // ถ้ามี record ที่ (source_module, source_id) อยู่แล้ว → UPDATE; ไม่งั้น INSERT
    if ($action === 'txn:upsert_from_source') {
        $srcMod = trim((string)($_POST['source_module'] ?? ''));
        $srcId  = trim((string)($_POST['source_id'] ?? ''));
        $kind   = ($_POST['kind'] ?? 'expense') === 'income' ? 'income' : 'expense';
        $amount = (float)($_POST['amount'] ?? 0);
        $txnDate = trim((string)($_POST['txn_date'] ?? date('Y-m-d')));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $catName = trim((string)($_POST['category_name'] ?? ''));
        $note   = trim((string)($_POST['note'] ?? ''));

        if ($srcMod === '' || $srcId === '') { echo json_encode(['ok' => false, 'message' => 'ต้องระบุ source_module + source_id']); exit; }
        if ($amount <= 0) { echo json_encode(['ok' => false, 'message' => 'จำนวนเงินต้อง > 0']); exit; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) { echo json_encode(['ok' => false, 'message' => 'รูปแบบวันที่ไม่ถูกต้อง']); exit; }

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
                SET txn_date=?, kind=?, category_id=?, amount=?, description=?, note=?, updated_by=?
                WHERE id=?")
                ->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $note ?: null, $adminId ?: null, $existingId]);
            echo json_encode(['ok' => true, 'id' => $existingId, 'mode' => 'updated']); exit;
        } else {
            $pdo->prepare("INSERT INTO sys_finance_transactions
                (txn_date, kind, category_id, amount, description, note, source_module, source_id, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $note ?: null, $srcMod, $srcId, $adminId ?: null, $adminId ?: null]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'mode' => 'created']); exit;
        }
    }

    // ── Assign receipt number (atomic running number per year) ──
    if ($action === 'txn:assign_receipt') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
        // ถ้ามี receipt_no แล้ว → คืนค่าเดิม
        $existing = $pdo->prepare("SELECT receipt_no, kind, txn_date FROM sys_finance_transactions WHERE id=?");
        $existing->execute([$id]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false, 'message' => 'ไม่พบรายการ']); exit; }
        if (!empty($row['receipt_no'])) { echo json_encode(['ok' => true, 'receipt_no' => $row['receipt_no']]); exit; }

        $prefix = ($row['kind'] === 'income') ? 'RCP' : 'PV'; // RCP=ใบเสร็จรับ, PV=ใบสำคัญจ่าย
        $yearBE = (int)date('Y', strtotime($row['txn_date'])) + 543;
        // หา max running number ของ prefix+year นั้น
        $like = $prefix . '-' . $yearBE . '-%';
        $maxStmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(receipt_no, " . (strlen($prefix) + 7) . ") AS UNSIGNED)) AS m
            FROM sys_finance_transactions WHERE receipt_no LIKE ?");
        $maxStmt->execute([$like]);
        $next = ((int)$maxStmt->fetchColumn()) + 1;
        $receiptNo = sprintf('%s-%d-%06d', $prefix, $yearBE, $next);
        $pdo->prepare("UPDATE sys_finance_transactions SET receipt_no=?, updated_by=? WHERE id=?")
            ->execute([$receiptNo, $adminId ?: null, $id]);
        echo json_encode(['ok' => true, 'receipt_no' => $receiptNo]); exit;
    }

    if ($action === 'category:create' || $action === 'category:update') {
        $isCreate = $action === 'category:create';
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $kind = ($_POST['kind'] ?? '') === 'expense' ? 'expense' : 'income';
        $icon = trim((string)($_POST['icon'] ?? 'fa-circle'));
        $color = trim((string)($_POST['color'] ?? '#64748b'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') { echo json_encode(['ok' => false, 'message' => 'กรุณากรอกชื่อหมวด']); exit; }

        if ($isCreate) {
            $stmt = $pdo->prepare("INSERT INTO sys_finance_categories (name, kind, icon, color, sort_order) VALUES (?, ?, ?, ?, ?)");
            try { $stmt->execute([$name, $kind, $icon, $color, $sortOrder]); }
            catch (PDOException $e) {
                if ((int)$e->errorInfo[1] === 1062) { echo json_encode(['ok' => false, 'message' => 'ชื่อหมวดซ้ำในประเภทเดียวกัน']); exit; }
                throw $e;
            }
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); exit;
        } else {
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
            $pdo->prepare("UPDATE sys_finance_categories SET name=?, kind=?, icon=?, color=?, sort_order=? WHERE id=?")
                ->execute([$name, $kind, $icon, $color, $sortOrder, $id]);
            echo json_encode(['ok' => true]); exit;
        }
    }

    if ($action === 'category:toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
        $pdo->prepare("UPDATE sys_finance_categories SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'category:delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
        $used = (int)$pdo->query("SELECT COUNT(*) FROM sys_finance_transactions WHERE category_id={$id}")->fetchColumn();
        if ($used > 0) { echo json_encode(['ok' => false, 'message' => "มีรายการใช้หมวดนี้อยู่ {$used} รายการ — ลบไม่ได้"]); exit; }
        $pdo->prepare("DELETE FROM sys_finance_categories WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['ok' => false, 'message' => 'unknown action: ' . $action]);
} catch (Throwable $e) {
    error_log('[finance] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
