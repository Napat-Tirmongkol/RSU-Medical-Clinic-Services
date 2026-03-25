<?php
// [แก้ไข: process/add_staff_process.php]
// แก้ไขชื่อคอลัมน์ให้ตรงกับ DB: password -> password_hash และลบ created_at ออก

// 1. ตั้งค่าการแสดงผล Error
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. กำหนด Header เป็น JSON
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/db_connect.php'; 
session_start();

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ'];

try {
    // 3. ตรวจสอบสิทธิ์ (Admin เท่านั้น)
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin'])) {
        throw new Exception('คุณไม่มีสิทธิ์ดำเนินการนี้ (Access Denied)');
    }

    // 4. ตรวจสอบ Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid Request Method');
    }

    // 5. รับค่าจาก Form
    $username  = trim($_POST['username'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $role      = trim($_POST['role'] ?? 'employee');

    // 6. Validation
    if (empty($username) || empty($password) || empty($full_name)) {
        throw new Exception('กรุณากรอกข้อมูลให้ครบถ้วน (Username, Password, ชื่อ-สกุล)');
    }

    // 7. เช็ค Username ซ้ำ (ตาราง sys_staff)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sys_staff WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception("Username '$username' มีผู้ใช้งานแล้ว กรุณาใช้ชื่ออื่น");
    }

    // 8. สร้าง Hash Password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // 9. บันทึกข้อมูล (แก้ไขชื่อคอลัมน์ให้ตรงกับ SQL)
    // - เปลี่ยน password เป็น password_hash
    // - ลบ created_at ออก (เพราะในตารางไม่มี)
    $sql = "INSERT INTO sys_staff (username, password_hash, full_name, role) 
            VALUES (:username, :password, :full_name, :role)";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':username' => $username,
        ':password' => $hashed_password, // map เข้ากับ password_hash
        ':full_name' => $full_name,
        ':role' => $role
    ]);

    if ($result) {
        $response = [
            'status' => 'success', 
            'message' => 'เพิ่มบัญชีพนักงานเรียบร้อยแล้ว'
        ];
    } else {
        throw new Exception('เกิดข้อผิดพลาดในการบันทึกข้อมูลลงฐานข้อมูล');
    }

} catch (PDOException $e) {
    // กรณี Database Error
    $response['message'] = 'Database Error: ' . $e->getMessage();
} catch (Exception $e) {
    // กรณี Error ทั่วไป
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>
