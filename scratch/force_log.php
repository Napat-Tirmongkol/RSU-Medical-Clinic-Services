<?php
require_once __DIR__ . '/../config.php';

echo "<h2>Activity Log Debugger</h2>";

try {
    $pdo = db();
    echo "1. Database Connected!<br>";

    // ทดสอบเขียน Log
    $testAction = "Debug Test";
    $testDesc = "ทดสอบการบันทึก Log ผ่านไฟล์ force_log.php เมื่อเวลา " . date('Y-m-d H:i:s');
    
    $res = log_activity($testAction, $testDesc);
    
    if ($res) {
        echo "<span style='color:green;'>2. Success! บันทึก Log ลงฐานข้อมูลเรียบร้อยแล้ว</span><br>";
    } else {
        echo "<span style='color:red;'>2. Failed! ฟังก์ชัน log_activity() คืนค่ากลับมาเป็น false</span><br>";
    }

    // ตรวจสอบข้อมูลในตาราง
    $stmt = $pdo->query("SELECT * FROM sys_activity_logs ORDER BY id DESC LIMIT 5");
    $logs = $stmt->fetchAll();

    echo "<h3>รายการ 5 Log ล่าสุดในฐานข้อมูล:</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
    echo "<tr><th>ID</th><th>Action</th><th>Description</th><th>Timestamp</th></tr>";
    foreach ($logs as $log) {
        echo "<tr>";
        echo "<td>{$log['id']}</td>";
        echo "<td>{$log['action']}</td>";
        echo "<td>{$log['description']}</td>";
        echo "<td>{$log['timestamp']}</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "<span style='color:red;'>Error: " . $e->getMessage() . "</span>";
}
