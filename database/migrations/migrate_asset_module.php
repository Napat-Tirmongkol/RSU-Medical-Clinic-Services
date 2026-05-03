<?php
/**
 * database/migrations/migrate_asset_module.php
 * สร้างตารางสำหรับโมดูลครุภัณฑ์สำนักงาน (asset_*)
 *  - asset_categories  : หมวดหมู่ครุภัณฑ์
 *  - asset_locations   : จุดใช้งาน/สถานที่
 *  - assets            : ทะเบียนครุภัณฑ์หลัก (mapping ตาม Excel เดิม)
 *  - asset_movements   : ประวัติการย้าย/เปลี่ยนสถานะ
 *  - asset_attachments : เอกสารแนบ (เผื่ออนาคต)
 *
 * รันครั้งเดียว ผ่านเบราว์เซอร์/CLI:
 *   php database/migrations/migrate_asset_module.php
 * ทุก statement เป็น IF NOT EXISTS — รันซ้ำได้ปลอดภัย
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) asset_categories ────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS asset_categories (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(20)  NULL UNIQUE,
        name        VARCHAR(150) NOT NULL,
        description VARCHAR(255) NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง asset_categories'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'asset_categories: ' . $e->getMessage()];
}

// ── 2) asset_locations ─────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS asset_locations (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(150) NOT NULL,
        building    VARCHAR(100) NULL,
        floor       VARCHAR(50)  NULL,
        note        VARCHAR(255) NULL,
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง asset_locations'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'asset_locations: ' . $e->getMessage()];
}

// ── 3) assets (ทะเบียนหลัก, mapping ตาม Excel) ────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS assets (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        asset_code      VARCHAR(50)  NOT NULL UNIQUE COMMENT 'รหัสภายในระบบ AST-YYYY-####',
        rsu_asset_code  VARCHAR(100) NULL COMMENT 'หมายเลขเครื่อง S/N ฝ่ายจัดซื้อพัสดุ มรส',
        serial_number   VARCHAR(100) NULL COMMENT 'หมายเลขเครื่อง S/N',
        name            VARCHAR(255) NOT NULL COMMENT 'ชื่ออุปกรณ์',
        brand           VARCHAR(150) NULL COMMENT 'ยี่ห้ออุปกรณ์',
        quantity        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'จำนวน',
        purchase_date   DATE NULL COMMENT 'วันที่ซื้อ',
        warranty_text   VARCHAR(255) NULL COMMENT 'การรับประกัน (ข้อความตาม Excel)',
        warranty_until  DATE NULL COMMENT 'วันสิ้นสุดประกัน (คำนวณ/กรอกเอง)',
        vendor          VARCHAR(255) NULL COMMENT 'บริษัทที่ซื้อ',
        category_id     INT UNSIGNED NULL,
        location_id     INT UNSIGNED NULL COMMENT 'จุดใช้งาน',
        custodian_id    INT UNSIGNED NULL COMMENT 'ผู้รับผิดชอบ (sys_staff.id)',
        status          ENUM('in_use','repair','disposed','lost','reserve') NOT NULL DEFAULT 'in_use',
        image           VARCHAR(500) NULL COMMENT 'path รูปภาพ',
        note            TEXT NULL COMMENT 'หมายเหตุ',
        imported_at     DATETIME NULL COMMENT 'ประทับเวลา (Excel timestamp)',
        created_by      INT UNSIGNED NULL,
        updated_by      INT UNSIGNED NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status     (status),
        INDEX idx_location   (location_id),
        INDEX idx_category   (category_id),
        INDEX idx_custodian  (custodian_id),
        INDEX idx_purchase   (purchase_date),
        INDEX idx_serial     (serial_number),
        INDEX idx_rsu_code   (rsu_asset_code),
        FULLTEXT KEY ft_search (name, brand, serial_number, rsu_asset_code, vendor, note)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง assets'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'assets: ' . $e->getMessage()];
}

// ── 4) asset_movements ─────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS asset_movements (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        asset_id        INT UNSIGNED NOT NULL,
        action          ENUM('create','move','status_change','update','delete') NOT NULL,
        from_location_id INT UNSIGNED NULL,
        to_location_id   INT UNSIGNED NULL,
        from_status     VARCHAR(30) NULL,
        to_status       VARCHAR(30) NULL,
        reason          VARCHAR(500) NULL,
        moved_by        INT UNSIGNED NULL,
        moved_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_asset (asset_id),
        INDEX idx_moved_at (moved_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง asset_movements'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'asset_movements: ' . $e->getMessage()];
}

// ── 5) asset_attachments ───────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS asset_attachments (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        asset_id    INT UNSIGNED NOT NULL,
        file_path   VARCHAR(500) NOT NULL,
        file_name   VARCHAR(255) NOT NULL,
        mime_type   VARCHAR(100) NULL,
        uploaded_by INT UNSIGNED NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_asset (asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง asset_attachments'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'asset_attachments: ' . $e->getMessage()];
}

// ── 6) Seed locations จาก Excel เดิม ───────────────────────────────────────
$seedLocations = [
    'เคาน์เตอร์พยาบาล',
    'ห้องประชุม 216D',
    'ห้องสังเกตอาการ',
    'ธุรการ',
    'ห้องยา',
    'นักจิตวิทยา',
    'นศ.เภสัช',
    'ผอ.',
    'MT',
    'รถพยาบาล',
    'สนง.คลินิก',
    'สนง.บัญชี',
    'ห้องฉุกเฉิน',
];
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO asset_locations (name) VALUES (?)");
    $count = 0;
    foreach ($seedLocations as $name) {
        $stmt->execute([$name]);
        $count += $stmt->rowCount();
    }
    $results[] = ['ok' => true, 'msg' => "Seed locations: เพิ่มใหม่ {$count} รายการ (รวม " . count($seedLocations) . " ค่า)"];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'seed locations: ' . $e->getMessage()];
}

// ── 7) Seed categories ตัวอย่าง ────────────────────────────────────────────
$seedCategories = [
    ['IT',  'อุปกรณ์ไอที (คอมพิวเตอร์/printer/network)'],
    ['MED', 'อุปกรณ์การแพทย์'],
    ['OFF', 'เฟอร์นิเจอร์/สำนักงาน'],
    ['ELC', 'เครื่องใช้ไฟฟ้า'],
    ['OTH', 'อื่น ๆ'],
];
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO asset_categories (code, name) VALUES (?, ?)");
    $count = 0;
    foreach ($seedCategories as [$code, $name]) {
        $stmt->execute([$code, $name]);
        $count += $stmt->rowCount();
    }
    $results[] = ['ok' => true, 'msg' => "Seed categories: เพิ่มใหม่ {$count} รายการ"];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'seed categories: ' . $e->getMessage()];
}

// ── 8) Upload directory ────────────────────────────────────────────────────
$uploadDir = __DIR__ . '/../../asset/uploads/';
if (!is_dir($uploadDir)) {
    if (@mkdir($uploadDir, 0755, true)) {
        $results[] = ['ok' => true, 'msg' => 'สร้างโฟลเดอร์ asset/uploads/'];
    } else {
        $results[] = ['ok' => false, 'msg' => 'สร้างโฟลเดอร์ asset/uploads/ ไม่สำเร็จ'];
    }
} else {
    $results[] = ['ok' => true, 'msg' => 'โฟลเดอร์ asset/uploads/ มีอยู่แล้ว'];
}

// ── Output ─────────────────────────────────────────────────────────────────
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
    <title>Migration: Asset Module</title>
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
    <h1>Migration: โมดูลครุภัณฑ์สำนักงาน</h1>
    <?php foreach ($results as $r): ?>
        <div class="item <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>
    <div class="warn">
        เมื่อรันสำเร็จแล้ว ควรลบไฟล์นี้ทิ้ง หรือจำกัดสิทธิ์การเข้าถึงทันที
    </div>
</body>
</html>
