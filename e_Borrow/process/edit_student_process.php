<?php
// edit_student_process.php
// (อัปเดต: รับ status, department, status_other)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('includes/log_function.php'); // ◀️ (เพิ่ม) เรียกใช้ Log

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

    // 5. รับข้อมูลจากฟอร์ม AJAX (อัปเดตตัวแปร)
    $student_id   = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $full_name    = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : null;
    $student_personnel_id = isset($_POST['student_personnel_id']) ? trim($_POST['student_personnel_id']) : null;
    
    // (ตัวแปรใหม่)
    $department   = isset($_POST['department']) ? trim($_POST['department']) : null;
    $status       = isset($_POST['status']) ? trim($_POST['status']) : '';
    $status_other = isset($_POST['status_other']) ? trim($_POST['status_other']) : null;


    // (Validation ใหม่)
    if ($student_id == 0 || empty($full_name) || empty($status)) {
        $response['message'] = 'ข้อมูลไม่ครบถ้วน (ID, ชื่อ-สกุล, หรือสถานภาพ)';
        echo json_encode($response);
        exit;
    }
    if ($status == 'other' && empty($status_other)) {
        $response['message'] = 'กรุณาระบุสถานภาพ "อื่นๆ"';
        echo json_encode($response);
        exit;
    }
    
    // (ทำให้ค่าว่างเป็น NULL)
    if (empty($phone_number)) $phone_number = null;
    if (empty($student_personnel_id)) $student_personnel_id = null;
    if (empty($department)) $department = null;
    if ($status != 'other') $status_other = null;


    // 6. (SQL ใหม่) ดำเนินการ UPDATE ตาราง sys_users
    try {
        $sql = "UPDATE sys_users 
                SET full_name = ?, 
                    phone_number = ?, 
                    student_personnel_id = ?,
                    department = ?,
                    status = ?,
                    status_other = ?
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $full_name, 
            $phone_number, 
            $student_personnel_id,
            $department,
            $status,
            $status_other,
            $student_id
        ]);

        // ◀️ --- (เพิ่มส่วน Log) --- ◀️
        if ($stmt->rowCount() > 0) {
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) ได้แก้ไขข้อมูลผู้ใช้งาน: '{$full_name}' (SID: {$student_id})";
            log_action($pdo, $admin_user_id, 'edit_user', $log_desc);
        }
        // ◀️ --- (จบส่วน Log) --- ◀️

        // 7. ถ้าสำเร็จ ให้เปลี่ยนคำตอบ
        $response['status'] = 'success';
        $response['message'] = 'บันทึกการเปลี่ยนแปลงสำเร็จ';

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
