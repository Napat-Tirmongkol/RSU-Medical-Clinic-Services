<?php
require_once __DIR__ . '/../config.php';

echo "<h2>Activity Log Fixer</h2>";

try {
    $pdo = db();
    
    echo "พยายามอัปเดตโครงสร้างตาราง...<br>";

    // เพิ่มคอลัมน์ ip_address และ user_agent
    $sql = "ALTER TABLE sys_activity_logs 
            ADD COLUMN ip_address VARCHAR(45) AFTER description,
            ADD COLUMN user_agent TEXT AFTER ip_address";
            
    $pdo->exec($sql);

    echo "<span style='color:green; font-weight:bold;'>สำเร็จ! อัปเดตโครงสร้างตารางเรียบร้อยแล้ว</span><br>";
    echo "ขณะนี้ระบบควรจะบันทึก Log ได้ตามปกติแล้วครับ<br>";
    echo "<a href='force_log.php'>กลับไปหน้าทดสอบ Log</a>";

} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<span style='color:orange;'>ตารางมีคอลัมน์เหล่านี้อยู่แล้วครับ</span>";
    } else {
        echo "<span style='color:red;'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</span>";
    }
}
