<?php
/*
 * db_connect.php
 * ไฟล์สำหรับเชื่อมต่อฐานข้อมูล
 */

// 1. ตั้งค่าตัวแปรเชื่อมต่อ (ใช้ข้อมูลใหม่ของคุณ)
$db_host = "171.102.216.219"; // <-- IP Address เซิร์ฟเวอร์ใหม่
$db_user = "healthy";         // <-- Username ใหม่
$db_pass = "61r_pl6NmNoviy3aB"; // <-- Password ใหม่
$db_name = "e_Borrow";    // <-- ชื่อฐานข้อมูลใหม่ (จากรูป image_4ccfb8.png)
$db_port = 3306;              // <-- Port (ปกติคือ 3306)

// 2. พยายามเชื่อมต่อ
try {
    // สร้างการเชื่อมต่อแบบ PDO
    // *** อัปเดต DSN ให้มี host, dbname, และ port ***
    $dsn = "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // สร้างตัวแปร $pdo เพื่อเก็บการเชื่อมต่อ
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);

} catch (PDOException $e) {
    // หากเชื่อมต่อล้มเหลว ให้หยุดทำงานและแสดงข้อผิดพลาด
    die("เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: " . $e->getMessage());
}

// กำหนดอัตราค่าปรับ (บาท ต่อ วัน)
define('FINE_RATE_PER_DAY', 10.00);
?>