<?php
// get_student_data.php
// ดึงข้อมูลผู้ใช้งาน (sys_users) สำหรับ Popup แก้ไข

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. ตรวจสอบสิทธิ์ Admin และตั้งค่า Header
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}
header('Content-Type: application/json');

// 3. สร้างตัวแปรสำหรับเก็บคำตอบ
$response = [
    'status' => 'error',
    'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ',
    'student' => null // (คงชื่อตัวแปร student ไว้)
];

// 4. รับ ID ผู้ใช้งาน (Student ID) จาก URL
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($student_id == 0) {
    $response['message'] = 'ไม่ได้ระบุ ID ผู้ใช้งาน';
    echo json_encode($response);
    exit;
}

try {
    // 5. (SQL) ดึงข้อมูลจาก sys_users
    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $response['status'] = 'success';
        $response['student'] = $student; 
        $response['message'] = 'ดึงข้อมูลสำเร็จ';
    } else {
        $response['message'] = 'ไม่พบข้อมูลผู้ใช้งาน';
    }

} catch (PDOException $e) {
    $response['message'] = 'เกิดข้อผิดพลาด DB: ' . $e->getMessage(); // ◀️ (แก้ไข)
}

// 6. ส่งคำตอบ (JSON) กลับไปให้ JavaScript
echo json_encode($response);
exit;
?>
