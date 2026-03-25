<?php
// process/return_process.php
// (เธญเธฑเธเน€เธ”เธ• V5 - เธฃเธญเธเธฃเธฑเธเธฃเธฐเธเธ Types/Items)

// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

$allowed_roles = ['admin', 'employee', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $item_id = isset($_POST['equipment_id']) ? (int)$_POST['equipment_id'] : 0; // (_POST[equipment_id] เธเธทเธญ item_id)
    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $staff_id = $_SESSION['user_id'];

    if ($item_id == 0 || $transaction_id == 0) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเธ—เธตเนเธชเนเธเธกเธฒเนเธกเนเธเธฃเธเธ–เนเธงเธ (Item ID เธซเธฃเธทเธญ Transaction ID)';
        echo json_encode($response);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. เธ”เธถเธเธเนเธญเธกเธนเธฅ Type ID เธเธฒเธ Item ID
        $stmt_get_type = $pdo->prepare("SELECT type_id FROM borrow_items WHERE id = ?");
        $stmt_get_type->execute([$item_id]);
        $type_id = $stmt_get_type->fetchColumn();

        if (!$type_id) {
            throw new Exception("เนเธกเนเธเธเธเธฃเธฐเน€เธ เธ—เธเธญเธเธญเธธเธเธเธฃเธ“เน (Item ID: $item_id)");
        }

        // 2. เธญเธฑเธเน€เธ”เธ• "เธเธดเนเธ" เธญเธธเธเธเธฃเธ“เน (items) เธเธฅเธฑเธเน€เธเนเธ 'available'
        $stmt_item = $pdo->prepare("UPDATE borrow_items 
                                   SET status = 'available' 
                                   WHERE id = ? AND status = 'borrowed'");
        $stmt_item->execute([$item_id]);

        if ($stmt_item->rowCount() == 0) {
             throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธญเธฑเธเน€เธ”เธ•เธชเธ–เธฒเธเธฐ Item เนเธ”เน (เธญเธฒเธเธ–เธนเธเธเธทเธเนเธเนเธฅเนเธง)");
        }
        
        // 3. เธญเธฑเธเน€เธ”เธ• "เธเธฃเธฐเน€เธ เธ—" (types) เธเธทเธเธเธณเธเธงเธ +1
        $stmt_type = $pdo->prepare("UPDATE borrow_categories 
                                   SET available_quantity = available_quantity + 1 
                                   WHERE id = ?");
        $stmt_type->execute([$type_id]);


        // 4. เธญเธฑเธเน€เธ”เธ• "เธเธฒเธฃเธขเธทเธก" (transactions)
        $stmt_trans = $pdo->prepare("UPDATE borrow_records 
                                    SET status = 'returned', 
                                        return_date = CURDATE(),
                                        return_staff_id = ?
                                    WHERE id = ? AND status = 'borrowed'");
        $stmt_trans->execute([$staff_id, $transaction_id]);
        
        if ($stmt_trans->rowCount() == 0) {
             throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธญเธฑเธเน€เธ”เธ•เธชเธ–เธฒเธเธฐ Transaction เนเธ”เน (เธญเธฒเธเธ–เธนเธเธเธทเธเนเธเนเธฅเนเธง)");
        }

        // 5. เธเธฑเธเธ—เธถเธ Log
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$staff_id}) 
                     เนเธ”เนเธเธฑเธเธ—เธถเธเธเธฒเธฃเธเธทเธเธญเธธเธเธเธฃเธ“เน (ItemID: {$item_id}, TID: {$transaction_id})";
        log_action($pdo, $staff_id, 'return_equipment', $log_desc);

        $pdo->commit();
        
        $response['status'] = 'success';
        $response['message'] = 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเธเธทเธเธญเธธเธเธเธฃเธ“เนเธชเธณเน€เธฃเนเธ';

    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

echo json_encode($response);
exit;
?>