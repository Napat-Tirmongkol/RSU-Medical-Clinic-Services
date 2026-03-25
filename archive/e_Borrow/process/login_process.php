<?php
// 1. เริ่ม Session เสมอ
session_start();

// 2. ตรวจสอบว่ามีการส่งข้อมูลมาแบบ POST หรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 3. เรียกใช้ไฟล์เชื่อมต่อฐานข้อมูล
    // ◀️ (แก้ไข) เพิ่ม ../ ◀️
    require_once(__DIR__ . '/../../../config/db_connect.php');
    require_once('../includes/log_function.php');

    // 4. รับค่าจากฟอร์ม
    $username = $_POST['username'];
    $password = $_POST['password'];

    try {
        // 5. เตรียมคำสั่ง SQL
        $stmt = $pdo->prepare("SELECT * FROM sys_staff WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        // 6. ดึงข้อมูลผู้ใช้
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 7. ตรวจสอบว่า: (1) เจอผู้ใช้ และ (2) รหัสผ่านถูกต้อง
        if ($user && password_verify($password, $user['password_hash'])) {

        // (ใหม่) 7.1 ตรวจสอบว่าบัญชีถูกระงับหรือไม่
        if (isset($user['account_status']) && $user['account_status'] == 'disabled') {
            // รหัสถูก แต่บัญชีถูกระงับ
            // ◀️ (แก้ไข) เพิ่ม ../ ◀️
            header("Location: ../admin/login.php?error=disabled");
            exit;
        }

            // 8. Log in สำเร็จ! "แจกบัตรพนักงาน"
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role']; 

            $log_desc = "พนักงาน '{$user['full_name']}' (Username: {$user['username']}) ได้เข้าสู่ระบบ (ผ่าน Password)";
            log_action($pdo, $user['id'], 'login_password', $log_desc);
            
            // 9. ส่งกลับไปหน้า index.php
            // ◀️ (แก้ไข) เพิ่ม ../ ◀️
            header("Location: ../admin/index.php");
            exit;

        } else {
            // 10. Log in ไม่สำเร็จ
            // ◀️ (แก้ไข) เพิ่ม ../ ◀️
            header("Location: ../admin/login.php?error=1");
            exit;
        }

    } catch (PDOException $e) {
        die("เกิดข้อผิดพลาดในการดึงข้อมูลผู้ใช้: " . $e->getMessage()); // (ถูกต้อง)
    }

} else {
    // ถ้าเข้ามาหน้านี้ตรงๆ
    // ◀️ (แก้ไข) เพิ่ม ../ ◀️
    header("Location: ../admin/login.php");
    exit;
}
?>
