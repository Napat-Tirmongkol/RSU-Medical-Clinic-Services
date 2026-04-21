<?php
// reject_request_process.php
// (แก้ไข: เพิ่มตรรกะการคืน Item (borrow_items) และ Type (borrow_categories) กลับเข้าสต็อก)

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'Invalid request'];

$allowed_roles = ['admin', 'employee', 'editor']; // (อนุญาต employee และ editor ด้วย)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    $response['message'] = 'Unauthorized';
    echo json_encode($response);
    exit;
}

// 2. รับ ID ของ Transaction
$transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
if ($transaction_id == 0) {
    $response['message'] = 'Invalid Transaction ID';
    echo json_encode($response);
    exit;
}

try {
    // 3. (ใหม่) เริ่ม Transaction (เพราะเราจะอัปเดต 3 ตาราง)
    $pdo->beginTransaction();

    // 4. (ใหม่) ดึงข้อมูล item_id และ type_id จากคำขอก่อน
    $stmt_get = $pdo->prepare("SELECT item_id, type_id, approval_status FROM borrow_records WHERE id = ? FOR UPDATE");
    $stmt_get->execute([$transaction_id]);
    $transaction = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        throw new Exception("ไม่พบคำขอนี้");
    }
    if ($transaction['approval_status'] != 'pending') {
        throw new Exception("คำขอนี้ถูกดำเนินการไปแล้ว (ไม่ใช่ Pending)");
    }

    $item_id = $transaction['item_id'];
    $type_id = $transaction['type_id'];

    if (empty($item_id) || empty($type_id)) {
         throw new Exception("ข้อมูลคำขอไม่สมบูรณ์ (ไม่มี Item ID หรือ Type ID ที่ถูกจองไว้)");
    }

    // 5. (เดิม) อัปเดตสถานะ Transaction เป็น 'rejected' และ 'returned'
    $stmt = $pdo->prepare("UPDATE borrow_records 
                          SET approval_status = 'rejected', status = 'returned' 
                          WHERE id = ? AND approval_status = 'pending'");
    $stmt->execute([$transaction_id]);

    // 6. (ใหม่) คืนสถานะ Item (borrow_items) กลับเป็น 'available'
    $stmt_item = $pdo->prepare("UPDATE borrow_items SET status = 'available' WHERE id = ? AND status = 'borrowed'");
    $stmt_item->execute([$item_id]);

    // 7. (ใหม่) คืนจำนวนใน Type (borrow_categories) (เพิ่ม available_quantity)
    $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity + 1 WHERE id = ?");
    $stmt_type->execute([$type_id]);

    // 8. ตรวจสอบว่าสำเร็จ (อย่างน้อย 2 ตารางหลักต้องอัปเดตได้)
    if ($stmt->rowCount() > 0 && $stmt_item->rowCount() > 0) {
        
        // 9. บันทึก Log
        $admin_user_id = $_SESSION['user_id'] ?? null;
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) ได้ปฏิเสธคำขอ (TID: {$transaction_id}) และคืน Item (ID: {$item_id}) เข้าสต็อก";
        log_action($pdo, $admin_user_id, 'reject_request', $log_desc);

        // 10. (ใหม่) ยืนยัน Transaction
        $pdo->commit();
        $response = ['status' => 'success', 'message' => 'ปฏิเสธคำขอเรียบร้อย (และคืนของเข้าสต็อกแล้ว)'];
    } else {
        throw new Exception("ไม่สามารถคืนอุปกรณ์เข้าสต็อกได้ (อาจมีบางอย่างผิดพลาด)");
    }

} catch (Throwable $e) {
    // 11. (ใหม่) ย้อนกลับ Transaction หากล้มเหลว
    $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>