<?php
/**
 * database/migrate_announcements.php
 * สร้างตาราง sys_announcements และ sys_announcement_reads
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้ทิ้ง
 */
require_once __DIR__ . '/../config.php';

$pdo = db();
$results = [];

// ── ตาราง 1: sys_announcements ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_announcements (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title           VARCHAR(255) NOT NULL,
        content         TEXT NOT NULL,
        image_url       VARCHAR(500) NULL COMMENT 'URL รูปภาพประกอบ (optional)',
        type            ENUM('info','warning','success','urgent') NOT NULL DEFAULT 'info',
        priority        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ยิ่งสูงยิ่งแสดงก่อน',
        target_audience ENUM('all','student','staff','other') NOT NULL DEFAULT 'all',
        is_active       TINYINT(1) NOT NULL DEFAULT 1,
        show_once       TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'ถ้า 1 = แสดงเฉพาะครั้งแรกที่เข้า',
        start_date      DATE NULL,
        end_date        DATE NULL,
        created_by      INT UNSIGNED NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active_date (is_active, start_date, end_date),
        INDEX idx_priority (priority DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => '✅ สร้างตาราง sys_announcements สำเร็จ'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => '❌ sys_announcements: ' . $e->getMessage()];
}

// ── ตาราง 2: sys_announcement_reads ───────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_announcement_reads (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        announcement_id INT UNSIGNED NOT NULL,
        user_id         INT UNSIGNED NOT NULL,
        read_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_read (announcement_id, user_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = ['ok' => true, 'msg' => '✅ สร้างตาราง sys_announcement_reads สำเร็จ'];
} catch (PDOException $e) {
    $results[] = ['ok' => false, 'msg' => '❌ sys_announcement_reads: ' . $e->getMessage()];
}

// ── ตาราง Upload Directory ─────────────────────────────────────────────────
$uploadDir = __DIR__ . '/../storage/announcements/';
if (!is_dir($uploadDir)) {
    if (mkdir($uploadDir, 0755, true)) {
        $results[] = ['ok' => true, 'msg' => '✅ สร้างโฟลเดอร์ storage/announcements/ สำเร็จ'];
    } else {
        $results[] = ['ok' => false, 'msg' => '❌ ไม่สามารถสร้างโฟลเดอร์ storage/announcements/ ได้'];
    }
} else {
    $results[] = ['ok' => true, 'msg' => '✅ โฟลเดอร์ storage/announcements/ มีอยู่แล้ว'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Migration: Announcements</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 60px auto; padding: 20px; background: #f9fafb; }
        h1 { font-size: 1.5rem; color: #0f172a; }
        .item { padding: 12px 16px; border-radius: 10px; margin-bottom: 10px; font-weight: 700; font-size: 14px; }
        .ok  { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .err { background: #fff1f2; color: #dc2626; border: 1px solid #fecaca; }
        .warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; margin-top: 20px; padding: 14px; border-radius: 10px; font-size: 13px; }
    </style>
</head>
<body>
    <h1>🔧 Migration: ระบบประกาศ (Announcements)</h1>
    <?php foreach ($results as $r): ?>
        <div class="item <?= $r['ok'] ? 'ok' : 'err' ?>"><?= $r['msg'] ?></div>
    <?php endforeach; ?>
    <div class="warn">
        ⚠️ <strong>สำคัญ:</strong> เมื่อรันเสร็จแล้วให้ลบไฟล์นี้ทิ้ง หรือจำกัดการเข้าถึงทันที เพื่อความปลอดภัย
    </div>
</body>
</html>
