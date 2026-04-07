<?php
// 1. เริ่ม Session เสมอ
session_start();

// 2. ตรวจสอบว่ามีการส่งข้อมูลมาแบบ POST หรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 3. เรียกใช้ไฟล์เชื่อมต่อฐานข้อมูล
    try {
        require_once(__DIR__ . '/../../../config/db_connect.php');
        require_once('../includes/log_function.php');
    } catch (Throwable $e) {
        header("Location: ../admin/login.php?error=db");
        exit;
    }

    // 4. รับค่าจากฟอร์ม
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        // 5. เตรียมคำสั่ง SQL
        $stmt = $pdo->prepare("SELECT * FROM sys_staff WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        // 6. ดึงข้อมูลผู้ใช้
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 7. ตรวจสอบว่า: (1) เจอผู้ใช้ และ (2) รหัสผ่านถูกต้อง
        if ($user && password_verify($password, $user['password_hash'])) {

            // 7.1 ตรวจสอบว่าบัญชีถูกระงับหรือไม่
            if (isset($user['account_status']) && $user['account_status'] == 'disabled') {
                header("Location: ../admin/login.php?error=disabled");
                exit;
            }

            // 8. Log in สำเร็จ — regenerate session ก่อน (ป้องกัน session fixation)
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            // 8.1 SSO Bridge → e-Campaign (ถ้า staff มีสิทธิ์)
            if (!empty($user['access_ecampaign'])) {
                // Whitelist role ก่อน set session ป้องกัน privilege escalation
                $allowedCampRoles = ['admin', 'editor', 'superadmin'];
                $campRole = in_array($user['ecampaign_role'] ?? '', $allowedCampRoles, true)
                    ? $user['ecampaign_role']
                    : 'admin';

                $_SESSION['admin_logged_in']      = true;
                $_SESSION['admin_id']             = $user['id'];
                $_SESSION['admin_username']       = $user['full_name'];
                $_SESSION['admin_role']           = $campRole;
                $_SESSION['_admin_last_activity'] = time();
            }

            $log_desc = "พนักงาน '{$user['full_name']}' (Username: {$user['username']}) ได้เข้าสู่ระบบ (ผ่าน Password)";
            log_action($pdo, $user['id'], 'login_password', $log_desc);

            // 9. ส่งกลับไปหน้า index.php
            header("Location: ../admin/index.php");
            exit;

        } else {
            // 10. Log in ไม่สำเร็จ
            header("Location: ../admin/login.php?error=1");
            exit;
        }

    } catch (Throwable $e) {
        header("Location: ../admin/login.php?error=db");
        exit;
    }

} else {
    // ถ้าเข้ามาหน้านี้ตรงๆ
    // ◀️ (แก้ไข) เพิ่ม ../ ◀️
    header("Location: ../admin/login.php");
    exit;
}
?>
