<?php
/**
 * database/migrations/migrate_edms_doc_types.php
 *
 * EDMS — ทำให้ "ประเภทเอกสาร" เพิ่ม/แก้ไขได้เอง (เดิม ENUM 4 ค่า hard-coded)
 *
 *   1) สร้างตาราง sys_doc_types (code, name, prefix, icon, color, sort_order, is_active, is_system)
 *   2) Seed 4 ประเภทเริ่มต้น (incoming, outgoing, internal, circular) เป็น is_system=1
 *   3) เปลี่ยน sys_doc_documents.doc_type จาก ENUM → VARCHAR(30)
 *   4) เปลี่ยน sys_doc_counters.doc_type จาก ENUM → VARCHAR(30)
 *
 * รันซ้ำได้ปลอดภัย (idempotent — ทุก step ตรวจสภาพปัจจุบันก่อน)
 *
 * วิธีรัน:
 *   php database/migrations/migrate_edms_doc_types.php
 *   หรือเปิด /database/migrations/migrate_edms_doc_types.php ทาง browser
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) สร้างตาราง sys_doc_types ──────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_types (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code         VARCHAR(30) NOT NULL UNIQUE COMMENT 'รหัสประเภท เช่น incoming, outgoing, order, announcement',
        name         VARCHAR(120) NOT NULL COMMENT 'ชื่อแสดงผล เช่น หนังสือรับ, หนังสือคำสั่ง',
        short_label  VARCHAR(20)  NULL COMMENT 'prefix ของเลขที่เอกสาร เช่น รับ, คำสั่ง',
        description  VARCHAR(255) NULL,
        icon         VARCHAR(60)  NULL COMMENT 'fontawesome class เช่น fa-inbox',
        tone         VARCHAR(20)  NULL COMMENT 'tailwind color token เช่น sky, emerald',
        sort_order   SMALLINT NOT NULL DEFAULT 0,
        is_active    TINYINT(1) NOT NULL DEFAULT 1,
        is_system    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ระบบสร้างให้ ลบไม่ได้แต่แก้ได้',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active_sort (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => 'สร้างตาราง sys_doc_types'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_types: ' . $e->getMessage()];
}

// ── 2) Seed 4 ประเภทเริ่มต้น ─────────────────────────────────────────────
$seedTypes = [
    ['incoming', 'หนังสือรับ',    'รับ',    'รับเข้าจากหน่วยงานภายนอก/ภายใน',  'fa-inbox',       'sky',     10],
    ['outgoing', 'หนังสือส่ง',     'ส่ง',    'ออกจากคลินิกไปยังหน่วยงานอื่น',     'fa-paper-plane', 'emerald', 20],
    ['internal', 'บันทึกข้อความ',  'บันทึก', 'หนังสือภายในระหว่างฝ่าย',           'fa-file-lines',  'violet',  30],
    ['circular', 'หนังสือเวียน',   'เวียน',  'ประกาศ/แจ้งเวียนหลายฝ่าย',         'fa-bullhorn',    'amber',   40],
];
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO sys_doc_types
        (code, name, short_label, description, icon, tone, sort_order, is_system, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)");
    $count = 0;
    foreach ($seedTypes as $row) {
        $stmt->execute($row);
        $count += $stmt->rowCount();
    }
    $results[] = ['ok' => true, 'msg' => "Seed sys_doc_types: เพิ่มใหม่ {$count} รายการ"];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'seed doc_types: ' . $e->getMessage()];
}

// ── 3) Alter sys_doc_documents.doc_type ENUM → VARCHAR(30) ─────────────
try {
    $col = $pdo->query("SHOW COLUMNS FROM sys_doc_documents LIKE 'doc_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos((string)$col['Type'], 'enum') === 0) {
        $pdo->exec("ALTER TABLE sys_doc_documents MODIFY COLUMN doc_type VARCHAR(30) NOT NULL
            COMMENT 'อ้างอิง sys_doc_types.code'");
        $results[] = ['ok' => true, 'msg' => 'sys_doc_documents.doc_type → VARCHAR(30)'];
    } else {
        $results[] = ['ok' => true, 'msg' => 'sys_doc_documents.doc_type เป็น VARCHAR แล้ว'];
    }
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_documents.doc_type: ' . $e->getMessage()];
}

// ── 4) Alter sys_doc_counters.doc_type ENUM → VARCHAR(30) ──────────────
try {
    $col = $pdo->query("SHOW COLUMNS FROM sys_doc_counters LIKE 'doc_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos((string)$col['Type'], 'enum') === 0) {
        $pdo->exec("ALTER TABLE sys_doc_counters MODIFY COLUMN doc_type VARCHAR(30) NOT NULL
            COMMENT 'อ้างอิง sys_doc_types.code'");
        $results[] = ['ok' => true, 'msg' => 'sys_doc_counters.doc_type → VARCHAR(30)'];
    } else {
        $results[] = ['ok' => true, 'msg' => 'sys_doc_counters.doc_type เป็น VARCHAR แล้ว'];
    }
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => 'sys_doc_counters.doc_type: ' . $e->getMessage()];
}

// ── Output ─────────────────────────────────────────────────────────────
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
    <title>Migration: EDMS Doc Types</title>
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
    <h1>Migration: EDMS Doc Types</h1>
    <div class="sub">เปลี่ยน doc_type จาก ENUM → ตาราง sys_doc_types (ผู้ใช้เพิ่ม/แก้ไข/ซ่อนได้)</div>

    <?php foreach ($results as $r): ?>
        <div class="item <?= $r['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>

    <div class="warn">
        รันสำเร็จแล้ว ควรลบไฟล์นี้ทิ้งหรือจำกัดสิทธิ์การเข้าถึง
    </div>
</body>
</html>
