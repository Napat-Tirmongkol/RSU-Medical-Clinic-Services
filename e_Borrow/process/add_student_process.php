<?php
// add_student_process.php
// รับข้อมูลจาก Popup 'เพิ่มผู้ใช้งาน (โดย Admin)'

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');
require_once('../includes/log_function.php'); // ◀️ (เพิ่ม) เรียกใช้ Log

// 2. ตรวจสอบสิทธิ์ Admin และตั้งค่า Header
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}
header('Content-Type: application/json');

// 3. สร้างตัวแปรสำหรับเก็บคำตอบ
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

// 4. ตรวจสอบว่าเป็นการส่งข้อมูลแบบ POST หรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 5. รับข้อมูลจากฟอร์ม AJAX
    $full_name    = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : null;

    if (empty($full_name)) {
        $response['message'] = 'กรุณากรอก ชื่อ-สกุล';
        echo json_encode($response);
        exit;
    }
    
    if (empty($phone_number)) $phone_number = null;

    // 6. (SQL ใหม่) ดำเนินการ INSERT ลง sys_users
    try {
        $sql = "INSERT INTO sys_users (full_name, phone_number, status, line_user_id, student_personnel_id) 
                VALUES (?, ?, 'other', NULL, '(Staff-Added)')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$full_name, $phone_number]);

        $new_student_id = $pdo->lastInsertId();

        // ◀️ --- (เพิ่มส่วน Log) --- ◀️
        if ($stmt->rowCount() > 0) {
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) ได้เพิ่มผู้ใช้งาน (โดย Admin) ชื่อ: '{$full_name}' (ID ใหม่: {$new_student_id})";
            log_action($pdo, $admin_user_id, 'create_user_staff', $log_desc);
        }
        // ◀️ --- (จบส่วน Log) --- ◀️

        // 7. ถ้าสำเร็จ ให้เปลี่ยนคำตอบ
        $response['status'] = 'success';
        $response['message'] = 'เพิ่มผู้ใช้งานใหม่สำเร็จ';

    } catch (PDOException $e) {
        $response['message'] = 'เกิดข้อผิดพลาด DB: ' . $e->getMessage(); // ◀️ (แก้ไข)
    }

} else {
    $response['message'] = 'ต้องใช้วิธี POST เท่านั้น';
}

// 8. ส่งคำตอบ (JSON) กลับไปให้ JavaScript
echo json_encode($response);
exit;
?>
