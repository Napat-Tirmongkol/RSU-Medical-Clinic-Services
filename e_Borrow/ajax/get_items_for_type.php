<?php
// ajax/get_items_for_type.php
// (ไฟล์ใหม่)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');

// 2. ตรวจสอบสิทธิ์ Admin และตั้งค่า Header
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
$type_id = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;

if ($type_id == 0) {
    $response['message'] = 'ไม่ได้ระบุ ID ประเภทอุปกรณ์';
    echo json_encode($response);
    exit;
}

try {
    // 5. (Query ที่ 1) ดึงข้อมูล "ประเภท" (เผื่อใช้ชื่อ)
    $stmt_type = $pdo->prepare("SELECT name FROM borrow_categories WHERE id = ?");
    $stmt_type->execute([$type_id]);
    $type_data = $stmt_type->fetch(PDO::FETCH_ASSOC);

    if (!$type_data) {
        throw new Exception("ไม่พบประเภทอุปกรณ์ (ID: $type_id)");
    }

    // 6. (Query ที่ 2) ดึงข้อมูล "ชิ้น" อุปกรณ์ พร้อมข้อมูลผู้ยืม
    $stmt_items = $pdo->prepare("
        SELECT i.*,
               s.full_name AS student_name,
               t.borrow_date, t.due_date
        FROM borrow_items i
        LEFT JOIN borrow_records t ON i.id = t.item_id AND t.status = 'borrowed'
        LEFT JOIN sys_users s ON t.borrower_student_id = s.id
        WHERE i.type_id = ?
        ORDER BY i.status ASC, i.id ASC
    ");
    $stmt_items->execute([$type_id]);
    $items_data = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // 7. ส่งข้อมูลกลับ
    $response['status'] = 'success';
    $response['type'] = $type_data;
    $response['items'] = $items_data;


} catch (PDOException $e) {
    $response['message'] = 'เกิดข้อผิดพลาด DB: ' . $e->getMessage();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// 8. ส่งคำตอบ
echo json_encode($response);
exit;
?>