<?php
/**
 * database/migrations/migrate_finance_module.php
 * โมดูลการเงิน (Cash book) Phase 1
 *  - sys_finance_categories   : หมวดรายรับ/รายจ่าย
 *  - sys_finance_transactions : รายการรายรับ/รายจ่าย
 *
 * รัน: php database/migrations/migrate_finance_module.php (รันซ้ำได้ปลอดภัย)
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) sys_finance_categories ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_categories (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        kind        ENUM('income','expense') NOT NULL,
        icon        VARCHAR(50)  NULL,
        color       VARCHAR(20)  DEFAULT '#64748b',
        sort_order  INT          DEFAULT 0,
        is_active   TINYINT(1)   DEFAULT 1,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_name_kind (name, kind),
        KEY idx_kind_active (kind, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_finance_categories'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_finance_categories: ' . $e->getMessage()];
}

// ── 2) sys_finance_transactions ───────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_transactions (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        txn_date        DATE NOT NULL,
        kind            ENUM('income','expense') NOT NULL,
        category_id     INT UNSIGNED NULL,
        amount          DECIMAL(12,2) NOT NULL,
        description     VARCHAR(500) NULL,
        reference       VARCHAR(100) NULL,
        payment_method  VARCHAR(50)  NULL,
        note            TEXT NULL,
        created_by      INT UNSIGNED NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_by      INT UNSIGNED NULL,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_date_kind (txn_date, kind),
        KEY idx_category (category_id),
        CONSTRAINT fk_finance_txn_cat FOREIGN KEY (category_id) REFERENCES sys_finance_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_finance_transactions'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_finance_transactions: ' . $e->getMessage()];
}

// ── 3) Seed default categories ────────────────────────────────────────────
$defaults = [
    // income
    ['ค่ารักษาผู้ป่วย',       'income',  'fa-stethoscope',     '#0ea5e9', 1],
    ['ค่ายา/วัสดุ',           'income',  'fa-pills',           '#10b981', 2],
    ['ค่าตรวจ Lab',           'income',  'fa-vial',            '#a855f7', 3],
    ['Claim ประกัน',          'income',  'fa-shield-halved',   '#3b82f6', 4],
    ['รายรับอื่นๆ',           'income',  'fa-circle-plus',     '#64748b', 9],
    // expense
    ['จัดซื้อวัสดุการแพทย์',    'expense', 'fa-syringe',         '#dc2626', 1],
    ['จัดซื้อครุภัณฑ์',         'expense', 'fa-boxes-stacked',   '#d97706', 2],
    ['เงินเดือน/ค่าจ้าง',        'expense', 'fa-user-tie',        '#6366f1', 3],
    ['ค่าสาธารณูปโภค',         'expense', 'fa-bolt',            '#eab308', 4],
    ['ค่าซ่อมบำรุง',            'expense', 'fa-wrench',          '#64748b', 5],
    ['รายจ่ายอื่นๆ',            'expense', 'fa-circle-minus',    '#64748b', 9],
];
try {
    $ins = $pdo->prepare("INSERT IGNORE INTO sys_finance_categories (name, kind, icon, color, sort_order) VALUES (?, ?, ?, ?, ?)");
    $seeded = 0;
    foreach ($defaults as $d) { if ($ins->execute($d)) $seeded += $ins->rowCount(); }
    $results[] = ['ok' => true, 'msg' => "seed default categories: {$seeded} แถว"];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'seed: ' . $e->getMessage()];
}

// ── 4) Add access_finance flag to sys_staff (optional, for fine-grained access) ──
try {
    $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_finance TINYINT(1) NOT NULL DEFAULT 0");
    $results[] = ['ok' => true, 'msg' => 'เพิ่ม column access_finance'];
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        $results[] = ['ok' => true, 'msg' => 'access_finance มีอยู่แล้ว — ข้าม'];
    } else {
        $results[] = ['ok' => false, 'msg' => 'access_finance: ' . $e->getMessage()];
    }
}

// ── Report ─────────────────────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    foreach ($results as $r) echo ($r['ok'] ? '[OK]    ' : '[ERROR] ') . $r['msg'] . "\n";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
