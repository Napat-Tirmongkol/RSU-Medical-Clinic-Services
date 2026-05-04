<?php
/**
 * database/migrations/migrate_asset_stock_take.php
 * เพิ่มตารางสำหรับฟีเจอร์ตรวจนับครุภัณฑ์ประจำปี
 *  - asset_stock_takes       : รอบตรวจนับ (เช่น "ตรวจนับประจำปี 2569")
 *  - asset_stock_take_items  : รายการครุภัณฑ์ที่ต้องตรวจ + ผลการตรวจ
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์/CLI:
 *   php database/migrations/migrate_asset_stock_take.php
 */
require_once __DIR__ . '/../../config.php';

$pdo     = db();
$results = [];

// ── 1) asset_stock_takes ───────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS asset_stock_takes (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(200) NOT NULL,
        year                SMALLINT UNSIGNED NULL,
        start_date          DATE NULL,
        end_date            DATE NULL,
        scope_location_id   INT UNSIGNED NULL COMMENT 'ตรวจเฉพาะจุดใช้งานนี้ (NULL = ทั้งหมด)',
        scope_category_id   INT UNSIGNED NULL COMMENT 'ตรวจเฉพาะหมวดนี้ (NULL = ทั้งหมด)',
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
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง asset_stock_takes'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'asset_stock_takes: ' . $e->getMessage()];
}

// ── 2) asset_stock_take_items ──────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS asset_stock_take_items (
        id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stock_take_id         INT UNSIGNED NOT NULL,
        asset_id              INT UNSIGNED NOT NULL,
        expected_location_id  INT UNSIGNED NULL,
        expected_status       VARCHAR(30) NULL,
        found_status          ENUM('pending','found','not_found','wrong_location','damaged') NOT NULL DEFAULT 'pending',
        found_location_id     INT UNSIGNED NULL COMMENT 'ถ้าเจอที่อื่น (wrong_location)',
        note                  VARCHAR(500) NULL,
        checked_by            INT UNSIGNED NULL,
        checked_at            TIMESTAMP NULL,
        created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_take_asset (stock_take_id, asset_id),
        INDEX idx_take   (stock_take_id),
        INDEX idx_asset  (asset_id),
        INDEX idx_found  (found_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง asset_stock_take_items'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'asset_stock_take_items: ' . $e->getMessage()];
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
    <title>Migration: Asset Stock Take</title>
    <style>
        body { font-family: 'Prompt', sans-serif; max-width: 700px; margin: 60px auto; padding: 20px; background: #f9fafb; }
        h1   { font-size: 1.5rem; color: #0f172a; }
        .item { padding: 12px 16px; border-radius: 10px; margin-bottom: 10px; font-weight: 700; font-size: 14px; }
        .ok  { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .err { background: #fff1f2; color: #dc2626; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <h1>Migration: ตรวจนับครุภัณฑ์ประจำปี</h1>
    <?php foreach ($results as $r): ?>
        <div class="item <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>
</body>
</html>
