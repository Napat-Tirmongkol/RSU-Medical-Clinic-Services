<?php
// [สร้างไฟล์ใหม่: process/cancel_request_process.php]

// 1. (สำคัญ) ใช้ "ยาม" ของนักศึกษา
@session_start();
require_once('../includes/check_student_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'Invalid request'];

// 2. ดึง ID นักศึกษาจาก Session
$student_id = $_SESSION['student_id'];

// 3. ดึง ID คำขอ (Transaction ID) จาก POST
$transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
if ($transaction_id == 0) {
    $response['message'] = 'Invalid Transaction ID';
    echo json_encode($response);
    exit;
}

try {
    // 4. เริ่ม Transaction (เพราะเราจะแก้ไข 3 ตาราง)
    $pdo->beginTransaction();

    // 5. ดึงข้อมูลคำขอ (item_id, type_id)
    // (สำคัญ: ต้องเช็คว่า transaction_id นี้ เป็นของ student_id คนนี้จริงๆ)
    $stmt_get = $pdo->prepare("SELECT item_id, type_id, approval_status 
                              FROM borrow_records 
                              WHERE id = ? AND borrower_student_id = ? FOR UPDATE");
    $stmt_get->execute([$transaction_id, $student_id]);
    $transaction = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        throw new Exception("ไม่พบคำขอของคุณ หรือคุณไม่มีสิทธิ์ยกเลิกคำขอนี้");
    }
    if ($transaction['approval_status'] != 'pending') {
        throw new Exception("ไม่สามารถยกเลิกคำขอที่ถูกดำเนินการไปแล้วได้");
    }

    $item_id = $transaction['item_id'];
    $type_id = $transaction['type_id'];

    if (empty($item_id) || empty($type_id)) {
         throw new Exception("ข้อมูลคำขอไม่สมบูรณ์ (ไม่มี Item ID หรือ Type ID)");
    }

    // 6. อัปเดต Transaction (เหมือนตอน Reject)
    // (เราจะเก็บ Log การยกเลิกไว้ในเหตุผลเลย)
    $stmt = $pdo->prepare("UPDATE borrow_records 
                          SET approval_status = 'rejected', 
                              status = 'returned',
                              reason_for_borrowing = CONCAT(COALESCE(reason_for_borrowing, ''), '\n\n(ยกเลิกโดยผู้ใช้)')
                          WHERE id = ? AND borrower_student_id = ?");
    $stmt->execute([$transaction_id, $student_id]);

    // 7. คืนสต็อก Item (เหมือนตอน Reject)
    $stmt_item = $pdo->prepare("UPDATE borrow_items SET status = 'available' WHERE id = ? AND status = 'borrowed'");
    $stmt_item->execute([$item_id]);

    // 8. คืนสต็อก Type (เหมือนตอน Reject)
    $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity + 1 WHERE id = ?");
    $stmt_type->execute([$type_id]);

    // 9. ตรวจสอบว่าสำเร็จ (แยกตรวจแต่ละขั้นตอน)
    if ($stmt->rowCount() == 0) {
        throw new Exception("ไม่สามารถอัปเดตสถานะคำขอได้");
    }
    if ($stmt_item->rowCount() == 0) {
        throw new Exception("ไม่สามารถคืนสถานะอุปกรณ์ได้ (อาจถูกคืนไปแล้ว)");
    }

    $pdo->commit();
    $response['status'] = 'success';
    $response['message'] = 'ยกเลิกคำขอเรียบร้อย อุปกรณ์ถูกคืนเข้าสต็อกแล้ว';

} catch (Throwable $e) {
    $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>