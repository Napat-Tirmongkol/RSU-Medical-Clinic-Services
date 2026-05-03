<?php
/**
 * database/migrations/migrate_consumable_stock_take.php
 * ตารางสำหรับฟีเจอร์ตรวจนับวัสดุสิ้นเปลือง
 *  - consumable_stock_takes      : รอบตรวจนับ
 *  - consumable_stock_take_items : รายการที่ต้องตรวจ + จำนวนนับได้
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consumable_stock_takes (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(200) NOT NULL,
        year                SMALLINT UNSIGNED NULL,
        start_date          DATE NULL,
        end_date            DATE NULL,
        scope_location_id   INT UNSIGNED NULL,
        scope_category_id   INT UNSIGNED NULL,
        status              ENUM('draft','in_progress','closed') NOT NULL DEFAULT 'draft',
        note                TEXT NULL,
        created_by          INT UNSIGNED NULL,
        closed_by           INT UNSIGNED NULL,
        closed_at           TIMESTAMP NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_year   (year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง consumable_stock_takes'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'consumable_stock_takes: ' . $e->getMessage()];
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consumable_stock_take_items (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stock_take_id   INT UNSIGNED NOT NULL,
        consumable_id   INT UNSIGNED NOT NULL,
        expected_qty    INT NOT NULL DEFAULT 0 COMMENT 'snapshot qty_on_hand เมื่อเปิดรอบ',
        actual_qty      INT NULL COMMENT 'จำนวนนับจริง (ชิ้น)',
        check_status    ENUM('pending','counted','adjusted') NOT NULL DEFAULT 'pending',
        note            VARCHAR(500) NULL,
        checked_by      INT UNSIGNED NULL,
        checked_at      TIMESTAMP NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_take_item (stock_take_id, consumable_id),
        INDEX idx_take   (stock_take_id),
        INDEX idx_item   (consumable_id),
        INDEX idx_status (check_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง consumable_stock_take_items'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'consumable_stock_take_items: ' . $e->getMessage()];
}

$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    foreach ($results as $r) echo ($r['ok'] ? '[OK] ' : '[ERR] ') . $r['msg'] . PHP_EOL;
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Migration: Consumable Stock Take</title>
    <style>
        body { font-family: 'Prompt', sans-serif; max-width: 700px; margin: 60px auto; padding: 20px; background: #f9fafb; }
        h1   { font-size: 1.5rem; color: #0f172a; }
        .item { padding: 12px 16px; border-radius: 10px; margin-bottom: 10px; font-weight: 700; font-size: 14px; }
        .ok  { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .err { background: #fff1f2; color: #dc2626; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <h1>Migration: ตรวจนับวัสดุสิ้นเปลือง</h1>
    <?php foreach ($results as $r): ?>
        <div class="item <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>
</body>
</html>
