<?php
// get_staff_data.php
// (ไฟล์ใหม่)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');

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
    'staff' => null 
];

// 4. รับ ID เจ้าหน้าที่ (User ID) จาก URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($user_id == 0) {
    $response['message'] = 'ไม่ได้ระบุ ID เจ้าหน้าที่';
    echo json_encode($response);
    exit;
}

try {
    // 5. (SQL) ดึงข้อมูลจาก sys_staff
    // (เราไม่ดึง password_hash มา)
    $stmt = $pdo->prepare("SELECT id, username, full_name, role, linked_line_user_id FROM sys_staff WHERE id = ?");
    $stmt->execute([$user_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($staff) {
        $response['status'] = 'success';
        $response['staff'] = $staff; 
        $response['message'] = 'ดึงข้อมูลสำเร็จ';
    } else {
        $response['message'] = 'ไม่พบข้อมูลเจ้าหน้าที่';
    }

} catch (PDOException $e) {
    $response['message'] = 'เกิดข้อผิดพลาด DB: ' . $e->getMessage(); // ◀️ (แก้ไข)
}

// 6. ส่งคำตอบ (JSON) กลับไปให้ JavaScript
echo json_encode($response);
exit;
?>
