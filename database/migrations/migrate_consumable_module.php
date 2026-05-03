<?php
/**
 * database/migrations/migrate_consumable_module.php
 * โมดูลวัสดุสิ้นเปลือง (consumables)
 *  - consumables             : ทะเบียนวัสดุสิ้นเปลือง (track เป็นชิ้น)
 *  - consumable_categories   : หมวดหมู่
 *  - consumable_transactions : การเคลื่อนไหว (รับเข้า/เบิก/ปรับปรุง/จำหน่าย)
 *
 * Locations: ใช้ตารางเดียวกับโมดูลครุภัณฑ์ (asset_locations)
 * Faculties: ใช้ตาราง sys_faculties ที่มีอยู่
 *
 * รัน: php database/migrations/migrate_consumable_module.php (รันซ้ำได้ปลอดภัย)
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) consumable_categories ──────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consumable_categories (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(20)  NULL UNIQUE,
        name        VARCHAR(150) NOT NULL,
        description VARCHAR(255) NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง consumable_categories'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'consumable_categories: ' . $e->getMessage()];
}

// ── 2) consumables ─────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consumables (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code            VARCHAR(50)  NOT NULL UNIQUE COMMENT 'CSM-YYYY-####',
        name            VARCHAR(255) NOT NULL,
        brand           VARCHAR(150) NULL,
        category_id     INT UNSIGNED NULL,
        location_id     INT UNSIGNED NULL COMMENT 'จุดจัดเก็บ (asset_locations.id)',
        unit_pack       VARCHAR(50)  NULL DEFAULT 'กล่อง' COMMENT 'หน่วยบรรจุภัณฑ์ใหญ่',
        unit_piece      VARCHAR(50)  NOT NULL DEFAULT 'ชิ้น' COMMENT 'หน่วยย่อย',
        pack_size       INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'จำนวนชิ้นต่อ 1 หน่วยบรรจุภัณฑ์',
        qty_on_hand     INT NOT NULL DEFAULT 0 COMMENT 'คงเหลือ (จำนวนชิ้น)',
        min_stock       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จุดสั่งซื้อ',
        image           VARCHAR(500) NULL,
        note            TEXT NULL,
        status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_by      INT UNSIGNED NULL,
        updated_by      INT UNSIGNED NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status   (status),
        INDEX idx_category (category_id),
        INDEX idx_location (location_id),
        INDEX idx_qty      (qty_on_hand),
        FULLTEXT KEY ft_search (name, brand, note)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง consumables'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'consumables: ' . $e->getMessage()];
}

// ── 3) consumable_transactions ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS consumable_transactions (
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        consumable_id    INT UNSIGNED NOT NULL,
        txn_type         ENUM('receive','issue','adjust','dispose') NOT NULL,
        qty_change       INT NOT NULL COMMENT 'จำนวนชิ้นที่เปลี่ยน (+ รับเข้า, - เบิก/จำหน่าย)',
        unit_input       ENUM('pack','piece') NOT NULL DEFAULT 'piece' COMMENT 'หน่วยที่ผู้ใช้กรอก',
        qty_input        INT NOT NULL COMMENT 'ค่าที่ผู้ใช้กรอก (เช่น 1 กล่อง)',
        balance_after    INT NOT NULL COMMENT 'คงเหลือหลังทำรายการ (ชิ้น)',
        faculty_id       INT NULL COMMENT 'หน่วยงาน/คณะที่เบิก (sys_faculties.id)',
        requester_name   VARCHAR(255) NULL COMMENT 'ผู้มารับ/ติดต่อ',
        purpose          VARCHAR(500) NULL COMMENT 'วัตถุประสงค์',
        reference        VARCHAR(120) NULL COMMENT 'เลขที่เอกสาร/อ้างอิง',
        note             TEXT NULL,
        txn_date         DATE NOT NULL,
        created_by       INT UNSIGNED NULL,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_consumable (consumable_id),
        INDEX idx_type       (txn_type),
        INDEX idx_faculty    (faculty_id),
        INDEX idx_txn_date   (txn_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง consumable_transactions'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'consumable_transactions: ' . $e->getMessage()];
}

// ── 4) Seed categories ────────────────────────────────────────────────────
$seedCategories = [
    ['MED', 'เวชภัณฑ์ทางการแพทย์'],
    ['CTM', 'ถุงยาง / Contraception'],
    ['STN', 'เครื่องเขียน / สำนักงาน'],
    ['CLN', 'อุปกรณ์ทำความสะอาด'],
    ['OTH', 'อื่น ๆ'],
];
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO consumable_categories (code, name) VALUES (?, ?)");
    $count = 0;
    foreach ($seedCategories as [$code, $name]) {
        $stmt->execute([$code, $name]);
        $count += $stmt->rowCount();
    }
    $results[] = ['ok' => true, 'msg' => "Seed categories: เพิ่มใหม่ {$count} รายการ"];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'seed categories: ' . $e->getMessage()];
}

// ── 5) Upload directory ───────────────────────────────────────────────────
$uploadDir = __DIR__ . '/../../consumables/uploads/';
if (!is_dir($uploadDir)) {
    if (@mkdir($uploadDir, 0755, true)) {
        $results[] = ['ok' => true, 'msg' => 'สร้างโฟลเดอร์ consumables/uploads/'];
    } else {
        $results[] = ['ok' => false, 'msg' => 'สร้างโฟลเดอร์ consumables/uploads/ ไม่สำเร็จ'];
    }
} else {
    $results[] = ['ok' => true, 'msg' => 'โฟลเดอร์ consumables/uploads/ มีอยู่แล้ว'];
}

// ── Output ────────────────────────────────────────────────────────────────
$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    foreach ($results as $r) {
        echo ($r['ok'] ? '[OK] ' : '[ERR] ') . $r['msg'] . PHP_EOL;
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Migration: Consumables Module</title>
    <style>
        body { font-family: 'Prompt', sans-serif; max-width: 700px; margin: 60px auto; padding: 20px; background: #f9fafb; }
        h1   { font-size: 1.5rem; color: #0f172a; }
        .item { padding: 12px 16px; border-radius: 10px; margin-bottom: 10px; font-weight: 700; font-size: 14px; }
        .ok  { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .err { background: #fff1f2; color: #dc2626; border: 1px solid #fecaca; }
        .warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; margin-top: 20px; padding: 14px; border-radius: 10px; font-size: 13px; }
    </style>
</head>
<body>
    <h1>Migration: โมดูลวัสดุสิ้นเปลือง</h1>
    <?php foreach ($results as $r): ?>
        <div class="item <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>
    <div class="warn">
        เมื่อรันสำเร็จแล้ว ควรลบไฟล์นี้ทิ้ง หรือจำกัดสิทธิ์การเข้าถึงทันที<br>
        Locations ใช้ร่วมกับโมดูลครุภัณฑ์ (asset_locations) — ถ้ายังไม่ได้รัน migrate_asset_module.php กรุณารันก่อน
    </div>
</body>
</html>
