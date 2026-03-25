<?php
// ajax/get_equipment_type_data.php
// (ไฟล์ใหม่)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. ตรวจสอบสิทธิ์ Admin (เพราะไฟล์นี้ดึงข้อมูลสำหรับ Admin)
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}
header('Content-Type: application/json');

// 3. สร้างตัวแปรสำหรับเก็บคำตอบ
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

// 4. รับ ID
$type_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($type_id == 0) {
    $response['message'] = 'ไม่ได้ระบุ ID ประเภทอุปกรณ์';
    echo json_encode($response);
    exit;
}

try {
    // 5. ดึงข้อมูล
    $stmt = $pdo->prepare("SELECT * FROM borrow_categories WHERE id = ?");
    $stmt->execute([$type_id]);
    $type_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($type_data) {
        $response['status'] = 'success';
        $response['equipment_type'] = $type_data;
    } else {
        $response['message'] = 'ไม่พบข้อมูลประเภทอุปกรณ์ (ID: ' . $type_id . ')';
    }

} catch (PDOException $e) {
    $response['message'] = 'เกิดข้อผิดพลาด DB: ' . $e->getMessage();
}

// 6. ส่งคำตอบ
echo json_encode($response);
exit;
?>