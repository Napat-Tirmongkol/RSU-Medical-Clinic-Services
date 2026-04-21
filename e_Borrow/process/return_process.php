<?php
// process/return_process.php
// (อัปเดต V5 - รองรับระบบ Types/Items)

// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');

$allowed_roles = ['admin', 'employee', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $item_id = isset($_POST['equipment_id']) ? (int)$_POST['equipment_id'] : 0; // (_POST[equipment_id] คือ item_id)
    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $staff_id = $_SESSION['user_id'];

    if ($item_id == 0 || $transaction_id == 0) {
        $response['message'] = 'ข้อมูลที่ส่งมาไม่ครบถ้วน (Item ID หรือ Transaction ID)';
        echo json_encode($response);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. ตรวจสอบว่า item_id เป็นของ transaction_id นี้จริง และสถานะถูกต้อง
        $stmt_verify = $pdo->prepare("SELECT type_id FROM borrow_records
                                     WHERE id = ? AND item_id = ?
                                     AND status = 'borrowed'
                                     AND approval_status IN ('approved', 'staff_added')");
        $stmt_verify->execute([$transaction_id, $item_id]);
        $type_id = $stmt_verify->fetchColumn();

        if (!$type_id) {
            throw new Exception("ไม่พบรายการยืม หรืออุปกรณ์ไม่ตรงกับรายการ หรือยังไม่ได้รับการอนุมัติ");
        }

        // 2. อัปเดต "ชิ้น" อุปกรณ์ (items) กลับเป็น 'available'
        $stmt_item = $pdo->prepare("UPDATE borrow_items
                                   SET status = 'available'
                                   WHERE id = ? AND status = 'borrowed'");
        $stmt_item->execute([$item_id]);

        if ($stmt_item->rowCount() == 0) {
             throw new Exception("ไม่สามารถอัปเดตสถานะ Item ได้ (อาจถูกคืนไปแล้ว)");
        }

        // 3. อัปเดต "ประเภท" (types) คืนจำนวน +1
        $stmt_type = $pdo->prepare("UPDATE borrow_categories
                                   SET available_quantity = available_quantity + 1
                                   WHERE id = ?");
        $stmt_type->execute([$type_id]);

        // 4. อัปเดต "การยืม" (transactions)
        $stmt_trans = $pdo->prepare("UPDATE borrow_records
                                    SET status = 'returned',
                                        return_date = NOW(),
                                        return_staff_id = ?
                                    WHERE id = ? AND status = 'borrowed'
                                    AND approval_status IN ('approved', 'staff_added')");
        $stmt_trans->execute([$staff_id, $transaction_id]);

        if ($stmt_trans->rowCount() == 0) {
             throw new Exception("ไม่สามารถอัปเดตสถานะ Transaction ได้ (อาจถูกคืนไปแล้ว)");
        }

        // 5. บันทึก Log
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$staff_id}) 
                     ได้บันทึกการคืนอุปกรณ์ (ItemID: {$item_id}, TID: {$transaction_id})";
        log_action($pdo, $staff_id, 'return_equipment', $log_desc);

        $pdo->commit();
        
        $response['status'] = 'success';
        $response['message'] = 'บันทึกการคืนอุปกรณ์สำเร็จ';

    } catch (Throwable $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'ต้องใช้วิธี POST เท่านั้น';
}

echo json_encode($response);
exit;
?>