<?php
/**
 * database/migrations/migrate_org_chart.php
 * โมดูล Chain of Command / Org Chart
 *  - sys_org_positions : โครงสร้างตำแหน่ง (self-ref tree)
 *  - sys_org_members   : สมาชิกที่อยู่ในแต่ละตำแหน่ง (รวม non-medical staff)
 *
 * รัน: php database/migrations/migrate_org_chart.php (รันซ้ำได้ปลอดภัย)
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) sys_org_positions ─────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_org_positions (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        parent_id       INT NULL,
        title           VARCHAR(255) NOT NULL COMMENT 'ชื่อตำแหน่ง เช่น ผู้อำนวยการ',
        short_title     VARCHAR(100) NULL COMMENT 'ตัวย่อสำหรับการ์ด',
        description     TEXT NULL,
        level           TINYINT NOT NULL DEFAULT 0 COMMENT 'ระดับชั้น (0 = root)',
        sort_order      INT NOT NULL DEFAULT 0 COMMENT 'ลำดับพี่น้องในระดับเดียวกัน',
        card_style      ENUM('premium','simple') NOT NULL DEFAULT 'simple' COMMENT 'รูปแบบการ์ด',
        show_section_header TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'โชว์ section header เมื่อ render',
        is_active       TINYINT(1) NOT NULL DEFAULT 1,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_parent (parent_id),
        INDEX idx_active_sort (is_active, sort_order),
        CONSTRAINT fk_org_pos_parent FOREIGN KEY (parent_id) REFERENCES sys_org_positions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_org_positions'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_org_positions: ' . $e->getMessage()];
}

// ── 2) sys_org_members ───────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_org_members (
        id                 INT AUTO_INCREMENT PRIMARY KEY,
        position_id        INT NULL COMMENT 'ตำแหน่งในผัง (NULL = ยังไม่ได้จัด)',
        prefix             VARCHAR(50) NULL COMMENT 'รศ.ดร., นางสาว, นาย ฯลฯ',
        full_name          VARCHAR(255) NOT NULL,
        photo_url          VARCHAR(500) NULL,
        license_no         VARCHAR(100) NULL COMMENT 'ใบอนุญาตฯ (premium card)',
        responsibilities   TEXT NULL COMMENT 'หน้าที่/บทบาท - bullet list',
        department         VARCHAR(255) NULL,
        staff_id           INT NULL COMMENT 'FK สู่ sys_staff (admin login account ที่ลิงก์กับสมาชิก)',
        user_id            INT NULL COMMENT 'FK สู่ sys_users (เพื่อ highlight ตัวเอง)',
        display_order      INT NOT NULL DEFAULT 0,
        is_active          TINYINT(1) NOT NULL DEFAULT 1,
        created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_position (position_id),
        INDEX idx_user     (user_id),
        INDEX idx_staff    (staff_id),
        CONSTRAINT fk_org_mem_pos FOREIGN KEY (position_id) REFERENCES sys_org_positions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_org_members'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_org_members: ' . $e->getMessage()];
}

// ── 3) Upload directory สำหรับรูปสมาชิก ──────────────────────────────────
$uploadDir = __DIR__ . '/../../assets/uploads/org_members/';
if (!is_dir($uploadDir)) {
    if (@mkdir($uploadDir, 0755, true)) {
        $results[] = ['ok' => true, 'msg' => 'สร้างโฟลเดอร์ assets/uploads/org_members/'];
    } else {
        $results[] = ['ok' => false, 'msg' => 'สร้างโฟลเดอร์ assets/uploads/org_members/ ไม่สำเร็จ'];
    }
} else {
    $results[] = ['ok' => true, 'msg' => 'โฟลเดอร์ assets/uploads/org_members/ มีอยู่แล้ว'];
}

// ── Output ───────────────────────────────────────────────────────────────
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
    <title>Migration: Org Chart Module</title>
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
    <h1>Migration: โมดูล Chain of Command / Org Chart</h1>
    <?php foreach ($results as $r): ?>
        <div class="item <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>
    <div class="warn">
        เมื่อรันสำเร็จแล้ว ควรลบไฟล์นี้ทิ้ง หรือจำกัดสิทธิ์การเข้าถึงทันที<br>
        เริ่มต้นใช้งาน: ไปที่ Portal → ข้อมูลคลินิก → ผังองค์กร เพื่อเพิ่มตำแหน่งและสมาชิก
    </div>
</body>
</html>
