<?php
// get_return_form_data.php
// (เธญเธฑเธเน€เธ”เธ•: JOIN เธ•เธฒเธฃเธฒเธ sys_users)
// เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธณเธซเธฃเธฑเธ Popup "เธขเธทเธเธขเธฑเธเธเธฒเธฃเธฃเธฑเธเธเธทเธ"

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

$allowed_roles = ['admin', 'employee', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}
header('Content-Type: application/json');

// 3. เธชเธฃเนเธฒเธเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเน€เธเนเธเธเธณเธ•เธญเธ
$response = [
    'status' => 'error', 
    'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ',
    'transaction' => null
];

// 4. เธฃเธฑเธ ID เธญเธธเธเธเธฃเธ“เนเธเธฒเธ URL
$equipment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($equipment_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธญเธธเธเธเธฃเธ“เน';
    echo json_encode($response);
    exit;
}

try {
    // 5. (SQL เนเธซเธกเน) เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฒเธฃเธขเธทเธก (Transaction) เธ—เธตเนเธขเธฑเธ "active"
    //    (JOIN เนเธซเธกเนเธเธฑเธ sys_users)
    $sql = "SELECT 
                t.id as transaction_id, 
                t.borrow_date, 
                t.due_date,
                ei.name as equipment_name, 
                ei.serial_number as equipment_serial,
                s.full_name as borrower_name, /* (เธกเธฒเธเธฒเธ sys_users) */
                s.phone_number as borrower_contact /* (เธกเธฒเธเธฒเธ sys_users) */
            FROM borrow_records t
            JOIN borrow_items ei ON t.equipment_id = ei.id
            /* (JOIN เนเธซเธกเน) เนเธเน borrower_student_id เน€เธเธทเนเธญเธกเนเธเธขเธฑเธ sys_users */
           /* (JOIN ... ) */
            LEFT JOIN sys_users s ON t.borrower_student_id = s.id
            WHERE t.equipment_id = ? AND t.status = 'borrowed'
              AND t.approval_status IN ('approved', 'staff_added') /* <-- เน€เธเธดเนเธกเธเธฃเธฃเธ—เธฑเธ”เธเธตเน */
            ORDER BY t.borrow_date DESC
            LIMIT 1"; // เน€เธญเธฒเน€เธเธเธฒเธฐเธฃเธฒเธขเธเธฒเธฃเธฅเนเธฒเธชเธธเธ”เธ—เธตเนเธขเธฑเธเนเธกเนเธเธทเธ

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$equipment_id]);
    $transaction_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($transaction_data) {
        $response['status'] = 'success';
        $response['transaction'] = $transaction_data;
        $response['message'] = 'เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธณเน€เธฃเนเธ';
    } else {
        $response['message'] = 'เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธฒเธฃเธขเธทเธก (เธญเธฒเธเธ–เธนเธเธเธทเธเนเธเนเธฅเนเธง)';
    }

} catch (PDOException $e) {
    $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
}

// 6. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>
