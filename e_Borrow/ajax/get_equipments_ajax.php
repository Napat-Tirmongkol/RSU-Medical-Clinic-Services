<?php
// get_equipments_ajax.php
// (ไฟล์ใหม่) Endpoint สำหรับดึงข้อมูลอุปกรณ์ด้วย AJAX

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาด', 'data' => []];

try {
    // 1. เตรียม SQL Query พื้นฐาน (เหมือนใน manage_equipment.php)
    // (แก้ไข) เปลี่ยนเป็น Query จาก borrow_categories
    $sql = "SELECT * FROM borrow_categories";

    $conditions = [];
    $params = [];

    // 2. รับค่าตัวกรองจาก Request (GET หรือ POST ก็ได้)
    $search_query = $_REQUEST['search'] ?? '';
    $status_query = $_REQUEST['status'] ?? '';

    // 3. สร้างเงื่อนไขแบบไดนามิก
    if (!empty($search_query)) {
        $conditions[] = "(name LIKE ? OR description LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
    }

    if (!empty($status_query)) {
        // (หมายเหตุ: การกรองตามสถานะรายชิ้นในหน้านี้อาจไม่จำเป็นแล้ว)
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY name ASC";

    // 4. ดึงข้อมูลและส่งกลับเป็น JSON
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response['status'] = 'success';
    $response['data'] = $equipments;

} catch (PDOException $e) {
    $response['message'] = "เกิดข้อผิดพลาด DB: " . $e->getMessage();
}

echo json_encode($response);
?>