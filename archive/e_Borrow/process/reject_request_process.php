<?php
// reject_request_process.php
// (เนเธเนเนเธ: เน€เธเธดเนเธกเธ•เธฃเธฃเธเธฐเธเธฒเธฃเธเธทเธ Item (borrow_items) เนเธฅเธฐ Type (borrow_categories) เธเธฅเธฑเธเน€เธเนเธฒเธชเธ•เนเธญเธ)

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'Invalid request'];

$allowed_roles = ['admin', 'employee', 'editor']; // (เธญเธเธธเธเธฒเธ• employee เนเธฅเธฐ editor เธ”เนเธงเธข)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    $response['message'] = 'Unauthorized';
    echo json_encode($response);
    exit;
}

// 2. เธฃเธฑเธ ID เธเธญเธ Transaction
$transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
if ($transaction_id == 0) {
    $response['message'] = 'Invalid Transaction ID';
    echo json_encode($response);
    exit;
}

try {
    // 3. (เนเธซเธกเน) เน€เธฃเธดเนเธก Transaction (เน€เธเธฃเธฒเธฐเน€เธฃเธฒเธเธฐเธญเธฑเธเน€เธ”เธ• 3 เธ•เธฒเธฃเธฒเธ)
    $pdo->beginTransaction();

    // 4. (เนเธซเธกเน) เธ”เธถเธเธเนเธญเธกเธนเธฅ item_id เนเธฅเธฐ type_id เธเธฒเธเธเธณเธเธญเธเนเธญเธ
    $stmt_get = $pdo->prepare("SELECT item_id, type_id, approval_status FROM borrow_records WHERE id = ? FOR UPDATE");
    $stmt_get->execute([$transaction_id]);
    $transaction = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        throw new Exception("เนเธกเนเธเธเธเธณเธเธญเธเธตเน");
    }
    if ($transaction['approval_status'] != 'pending') {
        throw new Exception("เธเธณเธเธญเธเธตเนเธ–เธนเธเธ”เธณเน€เธเธดเธเธเธฒเธฃเนเธเนเธฅเนเธง (เนเธกเนเนเธเน Pending)");
    }

    $item_id = $transaction['item_id'];
    $type_id = $transaction['type_id'];

    if (empty($item_id) || empty($type_id)) {
         throw new Exception("เธเนเธญเธกเธนเธฅเธเธณเธเธญเนเธกเนเธชเธกเธเธนเธฃเธ“เน (เนเธกเนเธกเธต Item ID เธซเธฃเธทเธญ Type ID เธ—เธตเนเธ–เธนเธเธเธญเธเนเธงเน)");
    }

    // 5. (เน€เธ”เธดเธก) เธญเธฑเธเน€เธ”เธ•เธชเธ–เธฒเธเธฐ Transaction เน€เธเนเธ 'rejected' เนเธฅเธฐ 'returned'
    $stmt = $pdo->prepare("UPDATE borrow_records 
                          SET approval_status = 'rejected', status = 'returned' 
                          WHERE id = ? AND approval_status = 'pending'");
    $stmt->execute([$transaction_id]);

    // 6. (เนเธซเธกเน) เธเธทเธเธชเธ–เธฒเธเธฐ Item (borrow_items) เธเธฅเธฑเธเน€เธเนเธ 'available'
    $stmt_item = $pdo->prepare("UPDATE borrow_items SET status = 'available' WHERE id = ? AND status = 'borrowed'");
    $stmt_item->execute([$item_id]);

    // 7. (เนเธซเธกเน) เธเธทเธเธเธณเธเธงเธเนเธ Type (borrow_categories) (เน€เธเธดเนเธก available_quantity)
    $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity + 1 WHERE id = ?");
    $stmt_type->execute([$type_id]);

    // 8. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเธชเธณเน€เธฃเนเธ (เธญเธขเนเธฒเธเธเนเธญเธข 2 เธ•เธฒเธฃเธฒเธเธซเธฅเธฑเธเธ•เนเธญเธเธญเธฑเธเน€เธ”เธ•เนเธ”เน)
    if ($stmt->rowCount() > 0 && $stmt_item->rowCount() > 0) {
        
        // 9. เธเธฑเธเธ—เธถเธ Log
        $admin_user_id = $_SESSION['user_id'] ?? null;
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเธเธเธดเน€เธชเธเธเธณเธเธญ (TID: {$transaction_id}) เนเธฅเธฐเธเธทเธ Item (ID: {$item_id}) เน€เธเนเธฒเธชเธ•เนเธญเธ";
        log_action($pdo, $admin_user_id, 'reject_request', $log_desc);

        // 10. (เนเธซเธกเน) เธขเธทเธเธขเธฑเธ Transaction
        $pdo->commit();
        $response = ['status' => 'success', 'message' => 'เธเธเธดเน€เธชเธเธเธณเธเธญเน€เธฃเธตเธขเธเธฃเนเธญเธข (เนเธฅเธฐเธเธทเธเธเธญเธเน€เธเนเธฒเธชเธ•เนเธญเธเนเธฅเนเธง)'];
    } else {
        throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธเธทเธเธญเธธเธเธเธฃเธ“เนเน€เธเนเธฒเธชเธ•เนเธญเธเนเธ”เน (เธญเธฒเธเธกเธตเธเธฒเธเธญเธขเนเธฒเธเธเธดเธ”เธเธฅเธฒเธ”)");
    }

} catch (Exception $e) {
    // 11. (เนเธซเธกเน) เธขเนเธญเธเธเธฅเธฑเธ Transaction เธซเธฒเธเธฅเนเธกเน€เธซเธฅเธง
    $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>