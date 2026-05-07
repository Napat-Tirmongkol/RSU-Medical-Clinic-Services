<?php
/**
 * database/migrations/migrate_edms_module.php
 * สร้างตารางสำหรับโมดูลสารบรรณอิเล็กทรอนิกส์ (Electronic Document Management System)
 *
 *  - sys_doc_categories  : หมวดหมู่/ความเร่งด่วน/ความลับ
 *  - sys_doc_counters    : เลขที่เอกสาร running number ต่อปี/ประเภท
 *  - sys_doc_documents   : ทะเบียนเอกสารหลัก (รับ/ส่ง/ภายใน/เวียน)
 *  - sys_doc_attachments : ไฟล์แนบ
 *  - sys_doc_routings    : การโอน/มอบหมาย
 *  - sys_doc_logs        : บันทึกการดำเนินการ (audit trail)
 *
 * นอกจากนี้:
 *  - เพิ่มคอลัมน์ access_edms ใน sys_staff (TINYINT(1) DEFAULT 0)
 *  - Seed sys_doc_categories ค่าเริ่มต้น (ปกติ/ด่วน/ด่วนมาก/ด่วนที่สุด)
 *  - สร้างโฟลเดอร์ uploads/edms/ พร้อม .htaccess กัน execute PHP
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์/CLI:
 *   php database/migrations/migrate_edms_module.php
 *   หรือเปิด /database/migrations/migrate_edms_module.php ทาง browser ครั้งเดียวแล้วลบทิ้ง
 *
 * ทุก statement เป็น IF NOT EXISTS / ตรวจคอลัมน์ก่อน — รันซ้ำได้ปลอดภัย
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) sys_doc_categories ─────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_categories (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind        ENUM('priority','confidentiality','custom') NOT NULL DEFAULT 'priority'
                    COMMENT 'priority=ความเร่งด่วน, confidentiality=ความลับ, custom=หมวดอื่น',
        code        VARCHAR(30) NOT NULL,
        name        VARCHAR(120) NOT NULL,
        color       VARCHAR(20)  NULL COMMENT 'hex/tailwind class สำหรับ badge',
        sort_order  SMALLINT NOT NULL DEFAULT 0,
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_kind_code (kind, code),
        INDEX idx_kind_active (kind, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_doc_categories'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_categories: ' . $e->getMessage()];
}

// ── 2) sys_doc_counters ───────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_counters (
        year_be     SMALLINT UNSIGNED NOT NULL COMMENT 'ปี พ.ศ.',
        doc_type    ENUM('incoming','outgoing','internal','circular') NOT NULL,
        current_no  INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (year_be, doc_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_doc_counters'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_counters: ' . $e->getMessage()];
}

// ── 3) sys_doc_documents ──────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_documents (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_type        ENUM('incoming','outgoing','internal','circular') NOT NULL
                        COMMENT 'incoming=หนังสือรับ, outgoing=หนังสือส่ง, internal=บันทึกข้อความ, circular=หนังสือเวียน',
        doc_number      VARCHAR(80) NULL COMMENT 'เลขที่เอกสาร เช่น รับ-001/2569',
        running_no      INT UNSIGNED NULL,
        year_be         SMALLINT UNSIGNED NULL,

        subject         VARCHAR(500) NOT NULL COMMENT 'เรื่อง',
        body            MEDIUMTEXT NULL COMMENT 'เนื้อหา',
        summary         TEXT NULL COMMENT 'สรุปย่อ',

        doc_date        DATE NULL COMMENT 'ลงวันที่',
        received_date   DATE NULL COMMENT 'วันที่รับ (สำหรับหนังสือรับ)',

        sender          VARCHAR(255) NULL COMMENT 'จาก (หน่วยงาน/บุคคล)',
        recipient       TEXT NULL COMMENT 'เรียน',

        priority_id     INT UNSIGNED NULL COMMENT 'FK -> sys_doc_categories.id (kind=priority)',
        confidentiality ENUM('normal','confidential','secret','top_secret') NOT NULL DEFAULT 'normal',

        status          ENUM('draft','registered','routing','in_progress','completed','archived','cancelled')
                        NOT NULL DEFAULT 'draft',

        ref_doc_id      INT UNSIGNED NULL COMMENT 'อ้างอิงเอกสารเดิม',

        created_by      INT UNSIGNED NULL COMMENT 'sys_staff.id ผู้สร้าง',
        updated_by      INT UNSIGNED NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uniq_doc_number (doc_number),
        INDEX idx_type_status (doc_type, status),
        INDEX idx_year_running (year_be, doc_type, running_no),
        INDEX idx_doc_date (doc_date),
        INDEX idx_received_date (received_date),
        INDEX idx_created_by (created_by),
        INDEX idx_ref_doc (ref_doc_id),
        FULLTEXT KEY ft_search (subject, body, summary, sender, recipient)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_doc_documents'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_documents: ' . $e->getMessage()];
}

// ── 4) sys_doc_attachments ────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_attachments (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_id        INT UNSIGNED NOT NULL,
        root_id       INT UNSIGNED NULL COMMENT 'NULL = v1 ของ chain, otherwise ชี้ไปที่ id ของ v1',
        version_no    INT NOT NULL DEFAULT 1,
        is_current    TINYINT(1) NOT NULL DEFAULT 1,
        role          ENUM('primary','supporting') NOT NULL DEFAULT 'supporting'
                          COMMENT 'primary = เอกสารหลัก (1 chain ต่อเอกสาร), supporting = ไฟล์ประกอบ',
        superseded_at DATETIME NULL,
        file_name     VARCHAR(255) NOT NULL COMMENT 'ชื่อไฟล์ต้นฉบับ',
        stored_path   VARCHAR(500) NOT NULL COMMENT 'path สัมพัทธ์ใต้ uploads/edms/',
        mime_type     VARCHAR(100) NULL,
        file_size     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sha1_hash     CHAR(40) NULL COMMENT 'ตรวจซ้ำไฟล์',
        uploaded_by   INT UNSIGNED NULL,
        uploaded_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doc (doc_id),
        INDEX idx_doc_current (doc_id, is_current),
        INDEX idx_doc_role    (doc_id, role),
        INDEX idx_root_chain (root_id, version_no),
        INDEX idx_uploaded_at (uploaded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_doc_attachments'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_attachments: ' . $e->getMessage()];
}

// ── 4b) Versioning + role columns retrofit (idempotent — old deployments) ───────
foreach ([
    "ALTER TABLE sys_doc_attachments ADD COLUMN root_id INT UNSIGNED NULL AFTER doc_id",
    "ALTER TABLE sys_doc_attachments ADD COLUMN version_no INT NOT NULL DEFAULT 1 AFTER root_id",
    "ALTER TABLE sys_doc_attachments ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1 AFTER version_no",
    "ALTER TABLE sys_doc_attachments ADD COLUMN role ENUM('primary','supporting') NOT NULL DEFAULT 'supporting' AFTER is_current",
    "ALTER TABLE sys_doc_attachments ADD COLUMN superseded_at DATETIME NULL AFTER role",
    "ALTER TABLE sys_doc_attachments ADD INDEX idx_doc_current (doc_id, is_current)",
    "ALTER TABLE sys_doc_attachments ADD INDEX idx_doc_role (doc_id, role)",
    "ALTER TABLE sys_doc_attachments ADD INDEX idx_root_chain (root_id, version_no)",
] as $alter) {
    try { $pdo->exec($alter); } catch (PDOException) {}
}

// ── 5) sys_doc_routings ───────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_routings (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_id        INT UNSIGNED NOT NULL,
        from_user_id  INT UNSIGNED NULL COMMENT 'sys_staff.id ผู้ส่งต่อ',
        to_user_id    INT UNSIGNED NULL COMMENT 'sys_staff.id ผู้รับ (อาจ NULL ถ้าโอนไปที่ฝ่าย)',
        to_dept       VARCHAR(150) NULL COMMENT 'ชื่อฝ่าย/หน่วยงาน',
        action        ENUM('forward','assign','approve','sign','return','note','close')
                      NOT NULL DEFAULT 'forward'
                      COMMENT 'forward=ส่งต่อ, assign=มอบหมาย, approve=อนุมัติ, sign=ลงนาม, return=ตีกลับ, note=บันทึก, close=ปิดเรื่อง',
        comment       TEXT NULL,
        due_date      DATE NULL,
        status        ENUM('pending','acknowledged','done','returned') NOT NULL DEFAULT 'pending',
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at  TIMESTAMP NULL,
        INDEX idx_doc (doc_id),
        INDEX idx_to_user_status (to_user_id, status),
        INDEX idx_from_user (from_user_id),
        INDEX idx_due_date (due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_doc_routings'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_routings: ' . $e->getMessage()];
}

// ── 6) sys_doc_logs ───────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_logs (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_id      INT UNSIGNED NOT NULL,
        user_id     INT UNSIGNED NULL,
        action      VARCHAR(50) NOT NULL COMMENT 'create, update, register, attach, route, complete, archive, delete',
        detail      JSON NULL COMMENT 'รายละเอียดเพิ่มเติม',
        ip_address  VARCHAR(45) NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_doc (doc_id),
        INDEX idx_user (user_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_doc_logs'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_logs: ' . $e->getMessage()];
}

// ── 7) เพิ่มคอลัมน์ access_edms ใน sys_staff ──────────────────────────────
try {
    $cols = $pdo->query("DESCRIBE sys_staff")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('access_edms', $cols, true)) {
        $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_edms TINYINT(1) NOT NULL DEFAULT 0 AFTER access_registry");
        $results[] = ['ok' => true, 'msg' => 'เพิ่มคอลัมน์ access_edms ใน sys_staff'];
    } else {
        $results[] = ['ok' => true, 'msg' => 'คอลัมน์ access_edms มีอยู่แล้ว'];
    }
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'access_edms column: ' . $e->getMessage()];
}

// ── 8) Seed sys_doc_categories (ความเร่งด่วน) ─────────────────────────────
$seedCats = [
    ['priority', 'normal',     'ปกติ',           'slate',  10],
    ['priority', 'urgent',     'ด่วน',           'amber',  20],
    ['priority', 'very_urgent','ด่วนมาก',        'orange', 30],
    ['priority', 'most_urgent','ด่วนที่สุด',     'rose',   40],
];
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO sys_doc_categories (kind, code, name, color, sort_order) VALUES (?, ?, ?, ?, ?)");
    $count = 0;
    foreach ($seedCats as [$kind, $code, $name, $color, $order]) {
        $stmt->execute([$kind, $code, $name, $color, $order]);
        $count += $stmt->rowCount();
    }
    $results[] = ['ok' => true, 'msg' => "Seed priority categories: เพิ่มใหม่ {$count} รายการ"];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'seed categories: ' . $e->getMessage()];
}

// ── 9) สร้างโฟลเดอร์ uploads/edms/ + .htaccess ────────────────────────────
$uploadDir = dirname(__DIR__, 2) . '/uploads/edms';
$htAccess  = $uploadDir . '/.htaccess';
$indexHtml = $uploadDir . '/index.html';

if (!is_dir($uploadDir)) {
    if (@mkdir($uploadDir, 0755, true)) {
        $results[] = ['ok' => true, 'msg' => 'สร้างโฟลเดอร์ uploads/edms/'];
    } else {
        $results[] = ['ok' => false, 'msg' => 'สร้างโฟลเดอร์ uploads/edms/ ไม่สำเร็จ'];
    }
} else {
    $results[] = ['ok' => true, 'msg' => 'โฟลเดอร์ uploads/edms/ มีอยู่แล้ว'];
}

if (is_dir($uploadDir) && !file_exists($htAccess)) {
    $ht = "# Block PHP/script execution under uploads/edms/\n"
        . "<FilesMatch \"\\.(php|phtml|phar|pl|py|jsp|asp|sh|cgi)$\">\n"
        . "    Order Deny,Allow\n"
        . "    Deny from all\n"
        . "</FilesMatch>\n\n"
        . "# Disable directory listing\n"
        . "Options -Indexes\n";
    if (@file_put_contents($htAccess, $ht) !== false) {
        $results[] = ['ok' => true, 'msg' => 'สร้างไฟล์ .htaccess (block PHP execution)'];
    } else {
        $results[] = ['ok' => false, 'msg' => 'สร้าง .htaccess ไม่สำเร็จ'];
    }
}

if (is_dir($uploadDir) && !file_exists($indexHtml)) {
    @file_put_contents($indexHtml, '');
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
    <title>Migration: EDMS Module</title>
    <style>
        body { font-family: 'Prompt', sans-serif; max-width: 720px; margin: 60px auto; padding: 20px; background: #f9fafb; color: #0f172a; }
        h1   { font-size: 1.6rem; margin-bottom: 4px; }
        .sub { color: #64748b; font-size: 13px; margin-bottom: 24px; font-weight: 600; }
        .item { padding: 12px 16px; border-radius: 12px; margin-bottom: 10px; font-weight: 700; font-size: 14px; }
        .ok  { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .err { background: #fff1f2; color: #dc2626; border: 1px solid #fecaca; }
        .warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; margin-top: 24px; padding: 14px 16px; border-radius: 12px; font-size: 13px; font-weight: 700; }
    </style>
</head>
<body>
    <h1>Migration: ระบบสารบรรณอิเล็กทรอนิกส์ (EDMS)</h1>
    <div class="sub">สร้างตาราง sys_doc_* + เพิ่มสิทธิ์ access_edms + เตรียมโฟลเดอร์ uploads</div>

    <?php foreach ($results as $r): ?>
        <div class="item <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>

    <div class="warn">
        เมื่อรันสำเร็จแล้ว ควรลบไฟล์นี้ทิ้งหรือจำกัดสิทธิ์การเข้าถึง เพื่อความปลอดภัย
    </div>
</body>
</html>
