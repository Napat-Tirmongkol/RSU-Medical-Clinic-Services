<?php
/**
 * database/migrations/migrate_gold_card_bulk_import.php
 *
 * เพิ่มคอลัมน์สำหรับ bulk import + linking กับ sys_users
 *  - linked_user_id INT UNSIGNED NULL  (FK to sys_users.id)
 *  - source_filename VARCHAR(500)      (ชื่อไฟล์ต้นฉบับ — ใช้ trace)
 *  - citizen_id เปลี่ยนเป็น NULLABLE   (รองรับไฟล์ที่ยัง match user ไม่ได้)
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้ทิ้ง
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

try {
    $cols = $pdo->query("SHOW COLUMNS FROM gold_card_members")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');

    if (!in_array('linked_user_id', $colNames, true)) {
        $pdo->exec("ALTER TABLE gold_card_members
                    ADD COLUMN linked_user_id INT UNSIGNED NULL AFTER citizen_id,
                    ADD INDEX idx_linked_user (linked_user_id)");
        $results[] = '✅ เพิ่มคอลัมน์ linked_user_id';
    } else {
        $results[] = 'ℹ️ คอลัมน์ linked_user_id มีอยู่แล้ว';
    }

    if (!in_array('source_filename', $colNames, true)) {
        $pdo->exec("ALTER TABLE gold_card_members
                    ADD COLUMN source_filename VARCHAR(500) NULL AFTER remarks");
        $results[] = '✅ เพิ่มคอลัมน์ source_filename';
    } else {
        $results[] = 'ℹ️ คอลัมน์ source_filename มีอยู่แล้ว';
    }

    // เปลี่ยน citizen_id เป็น NULL (เพื่อรองรับไฟล์ที่ยัง match ไม่ได้)
    foreach ($cols as $c) {
        if ($c['Field'] === 'citizen_id' && strtoupper($c['Null']) === 'NO') {
            try { $pdo->exec("ALTER TABLE gold_card_members DROP INDEX uniq_citizen_id"); }
            catch (PDOException $e) { /* index อาจถูกลบไปแล้ว */ }
            $pdo->exec("ALTER TABLE gold_card_members MODIFY COLUMN citizen_id VARCHAR(13) NULL");
            // เพิ่ม unique กลับโดยเป็น UNIQUE ที่อนุญาต NULL หลายแถว
            try { $pdo->exec("ALTER TABLE gold_card_members ADD UNIQUE KEY uniq_citizen_id (citizen_id)"); }
            catch (PDOException $e) { /* อาจ duplicate */ }
            $results[] = '✅ ปรับ citizen_id เป็น NULLABLE';
            break;
        }
    }
} catch (PDOException $e) {
    $results[] = '❌ ALTER gold_card_members: ' . $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration: Gold Card Bulk Import</h2><ul>";
foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>";
echo "</ul><p><strong>เสร็จสิ้น</strong> — กรุณาลบไฟล์นี้ออกหลังรันสำเร็จ</p>";
