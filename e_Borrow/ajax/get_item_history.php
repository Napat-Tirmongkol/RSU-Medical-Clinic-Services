<?php
// [สร้างไฟล์ใหม่: ajax/get_item_history.php]

@session_start();
// (1. ตรวจสอบ Session Admin และเชื่อมต่อ DB)
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');

header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

try {
    // (2. ตรวจสอบ Input)
    if (!isset($_GET['item_id']) || !is_numeric($_GET['item_id'])) {
        throw new Exception('ไม่ได้ระบุ ID อุปกรณ์');
    }
    $item_id = intval($_GET['item_id']);

    // (3. Query ประวัติการยืม)
    // เราจะดึงข้อมูลจาก borrow_records ที่ตรงกับ item_id นี้
    // และ JOIN กับ sys_users เพื่อเอาชื่อผู้ยืม
    $sql = "SELECT 
                t.borrow_date, 
                t.return_date,
                s.full_name AS borrower_name
            FROM borrow_records t
            JOIN sys_users s ON t.borrower_student_id = s.id
            WHERE t.item_id = ?
            ORDER BY t.borrow_date DESC"; // (เรียงจากล่าสุดไปเก่าสุด)
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$item_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // (4. ส่งข้อมูลกลับ)
    $response['status'] = 'success';
    $response['history'] = $history;

} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
