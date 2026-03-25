<?php
// create_fine_process.php
// (เนเธเธฅเนเนเธซเธกเน) เธเธฑเธเธ—เธถเธเธเธฒเธฃ "เธชเธฃเนเธฒเธ" เธเนเธฒเธเธฃเธฑเธ

include('..includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('..includes/log_function.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $staff_id = $_SESSION['user_id'];

    if ($transaction_id == 0 || $student_id == 0 || $amount <= 0) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเนเธกเนเธเธฃเธเธ–เนเธงเธ (Transaction ID, Student ID, เธซเธฃเธทเธญ Amount)';
        echo json_encode($response);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. INSERT เธฅเธเธ•เธฒเธฃเธฒเธ borrow_fines
        $sql_fine = "INSERT INTO borrow_fines 
                        (transaction_id, student_id, amount, notes, created_by_staff_id, status) 
                     VALUES (?, ?, ?, ?, ?, 'pending')";
        $stmt_fine = $pdo->prepare($sql_fine);
        $stmt_fine->execute([$transaction_id, $student_id, $amount, $notes, $staff_id]);
        
        // 2. เธญเธฑเธเน€เธ”เธ•เธ•เธฒเธฃเธฒเธ borrow_records เนเธซเนเธกเธตเธชเธ–เธฒเธเธฐ 'pending'
        $sql_trans = "UPDATE borrow_records SET fine_status = 'pending' WHERE id = ?";
        $stmt_trans = $pdo->prepare($sql_trans);
        $stmt_trans->execute([$transaction_id]);

        if ($stmt_fine->rowCount() > 0 && $stmt_trans->rowCount() > 0) {
            
            // 3. เธเธฑเธเธ—เธถเธ Log
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$staff_id}) เนเธ”เนเธชเธฃเนเธฒเธเธเนเธฒเธเธฃเธฑเธ (TID: {$transaction_id}) 
                         เธชเธณเธซเธฃเธฑเธเธเธนเนเนเธเน (SID: {$student_id}) เธเธณเธเธงเธ: {$amount} เธเธฒเธ—";
            log_action($pdo, $staff_id, 'create_fine', $log_desc);

            $pdo->commit();
            $response['status'] = 'success';
            $response['message'] = 'เธชเธฃเนเธฒเธเธเนเธฒเธเธฃเธฑเธเธชเธณเน€เธฃเนเธ';
        } else {
            throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธญเธฑเธเน€เธ”เธ•เธเนเธญเธกเธนเธฅ Transaction เธซเธฃเธทเธญเธชเธฃเนเธฒเธ Fine เนเธ”เน");
        }

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