<?php
// ไฟล์ทดสอบชั่วคราว — ลบทิ้งหลังใช้งาน

// ทดสอบโหลด config/db_connect.php จริงๆ
echo "Path: " . realpath(__DIR__ . '/../../config/db_connect.php') . "<br>";

try {
    require_once(__DIR__ . '/../../config/db_connect.php');
    echo "✅ โหลด db_connect.php สำเร็จ<br>";

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM sys_staff");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "✅ ตาราง sys_staff: " . $row['total'] . " แถว<br>";

} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}
?>
