<?php
// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');

// 2. ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}

// 3. ตั้งค่า Header ให้ตอบกลับเป็น JSON
header('Content-Type: application/json');

// 4. สร้างตัวแปรสำหรับเก็บคำตอบ
$response = [
    'status' => 'error', 
    'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ',
    'equipment' => null
];

// 5. รับ ID อุปกรณ์จาก URL
$equipment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($equipment_id == 0) {
    $response['message'] = 'ไม่ได้ระบุ ID อุปกรณ์';
    echo json_encode($response);
    exit;
}

try {
    // 6. (แก้ไข) ดึงข้อมูลประเภทอุปกรณ์
    $stmt = $pdo->prepare("SELECT * FROM borrow_categories WHERE id = ?");
    $stmt->execute([$equipment_id]);
    $equipment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($equipment) {
        $response['status'] = 'success';
        $response['equipment'] = $equipment;
        $response['message'] = 'ดึงข้อมูลสำเร็จ';
    } else {
        $response['message'] = 'ไม่พบข้อมูลอุปกรณ์';
    }

} catch (PDOException $e) {
    $response['message'] = 'เกิดข้อผิดพลาด DB: ' . $e->getMessage(); // ◀️ (แก้ไข)
}

// 7. ส่งคำตอบ (JSON) กลับไปให้ JavaScript
echo json_encode($response);
exit;
?>