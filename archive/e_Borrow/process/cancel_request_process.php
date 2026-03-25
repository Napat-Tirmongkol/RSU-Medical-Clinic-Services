<?php
// [เธชเธฃเนเธฒเธเนเธเธฅเนเนเธซเธกเน: process/cancel_request_process.php]

// 1. (เธชเธณเธเธฑเธ) เนเธเน "เธขเธฒเธก" เธเธญเธเธเธฑเธเธจเธถเธเธฉเธฒ
@session_start();
require_once('../includes/check_student_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'Invalid request'];

// 2. เธ”เธถเธ ID เธเธฑเธเธจเธถเธเธฉเธฒเธเธฒเธ Session
$student_id = $_SESSION['student_id'];

// 3. เธ”เธถเธ ID เธเธณเธเธญ (Transaction ID) เธเธฒเธ POST
$transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
if ($transaction_id == 0) {
    $response['message'] = 'Invalid Transaction ID';
    echo json_encode($response);
    exit;
}

try {
    // 4. เน€เธฃเธดเนเธก Transaction (เน€เธเธฃเธฒเธฐเน€เธฃเธฒเธเธฐเนเธเนเนเธ 3 เธ•เธฒเธฃเธฒเธ)
    $pdo->beginTransaction();

    // 5. เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธณเธเธญ (item_id, type_id)
    // (เธชเธณเธเธฑเธ: เธ•เนเธญเธเน€เธเนเธเธงเนเธฒ transaction_id เธเธตเน เน€เธเนเธเธเธญเธ student_id เธเธเธเธตเนเธเธฃเธดเธเน)
    $stmt_get = $pdo->prepare("SELECT item_id, type_id, approval_status 
                              FROM borrow_records 
                              WHERE id = ? AND borrower_student_id = ? FOR UPDATE");
    $stmt_get->execute([$transaction_id, $student_id]);
    $transaction = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$transaction) {
        throw new Exception("เนเธกเนเธเธเธเธณเธเธญเธเธญเธเธเธธเธ“ เธซเธฃเธทเธญเธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธขเธเน€เธฅเธดเธเธเธณเธเธญเธเธตเน");
    }
    if ($transaction['approval_status'] != 'pending') {
        throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธขเธเน€เธฅเธดเธเธเธณเธเธญเธ—เธตเนเธ–เธนเธเธ”เธณเน€เธเธดเธเธเธฒเธฃเนเธเนเธฅเนเธงเนเธ”เน");
    }

    $item_id = $transaction['item_id'];
    $type_id = $transaction['type_id'];

    if (empty($item_id) || empty($type_id)) {
         throw new Exception("เธเนเธญเธกเธนเธฅเธเธณเธเธญเนเธกเนเธชเธกเธเธนเธฃเธ“เน (เนเธกเนเธกเธต Item ID เธซเธฃเธทเธญ Type ID)");
    }

    // 6. เธญเธฑเธเน€เธ”เธ• Transaction (เน€เธซเธกเธทเธญเธเธ•เธญเธ Reject)
    // (เน€เธฃเธฒเธเธฐเน€เธเนเธ Log เธเธฒเธฃเธขเธเน€เธฅเธดเธเนเธงเนเนเธเน€เธซเธ•เธธเธเธฅเน€เธฅเธข)
    $stmt = $pdo->prepare("UPDATE borrow_records 
                          SET approval_status = 'rejected', 
                              status = 'returned',
                              reason_for_borrowing = CONCAT(COALESCE(reason_for_borrowing, ''), '\n\n(เธขเธเน€เธฅเธดเธเนเธ”เธขเธเธนเนเนเธเน)')
                          WHERE id = ? AND borrower_student_id = ?");
    $stmt->execute([$transaction_id, $student_id]);

    // 7. เธเธทเธเธชเธ•เนเธญเธ Item (เน€เธซเธกเธทเธญเธเธ•เธญเธ Reject)
    $stmt_item = $pdo->prepare("UPDATE borrow_items SET status = 'available' WHERE id = ? AND status = 'borrowed'");
    $stmt_item->execute([$item_id]);

    // 8. เธเธทเธเธชเธ•เนเธญเธ Type (เน€เธซเธกเธทเธญเธเธ•เธญเธ Reject)
    $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity + 1 WHERE id = ?");
    $stmt_type->execute([$type_id]);

    // 9. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเธชเธณเน€เธฃเนเธ
    if ($stmt->rowCount() > 0 && $stmt_item->rowCount() > 0 && $stmt_type->rowCount() > 0) {
        
        $pdo->commit();
        $response['status'] = 'success';
        $response['message'] = 'เธขเธเน€เธฅเธดเธเธเธณเธเธญเน€เธฃเธตเธขเธเธฃเนเธญเธข เธญเธธเธเธเธฃเธ“เนเธ–เธนเธเธเธทเธเน€เธเนเธฒเธชเธ•เนเธญเธเนเธฅเนเธง';
    } else {
        throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธเธทเธเธญเธธเธเธเธฃเธ“เนเน€เธเนเธฒเธชเธ•เนเธญเธเนเธ”เน (เธญเธฒเธเธกเธตเธเธฒเธเธญเธขเนเธฒเธเธเธดเธ”เธเธฅเธฒเธ”)");
    }

} catch (Exception $e) {
    $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>