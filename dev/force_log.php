<?php
require_once __DIR__ . '/../config.php';

echo "<h2>Activity Log Debugger (Structural Check)</h2>";

try {
    $pdo = db();
    
    // 1. ตรวจสอบคอลัมน์ที่มีอยู่ในปัจจุบัน
    echo "<h3>1. ค้นหาโครงสร้างตารางปัจจุบัน:</h3>";
    $stmt = $pdo->query("DESCRIBE sys_activity_logs");
    $columns = $stmt->fetchAll();
    
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th></tr>";
    foreach ($columns as $col) {
        echo "<tr><td>{$col['Field']}</td><td>{$col['Type']}</td><td>{$col['Null']}</td></tr>";
    }
    echo "</table>";

    // 2. ทดลอง INSERT แบบ Manual เพื่อดู Error Message จริงๆ
    echo "<h3>2. ทดลอง INSERT แบบ Manual (เพื่อดู Error):</h3>";
    try {
        $sql = "INSERT INTO sys_activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([1, 'Debug', 'Test', '127.0.0.1', 'Manual Test']);
        echo "<span style='color:green;'>INSERT สำเร็จ!</span>";
    } catch (PDOException $e) {
        echo "<span style='color:red;'>INSERT ล้มเหลว: " . $e->getMessage() . "</span>";
    }

} catch (Exception $e) {
    echo "<span style='color:red;'>Error: " . $e->getMessage() . "</span>";
}
