<?php
// [เธชเธฃเนเธฒเธเนเธเธฅเนเนเธซเธกเน: ajax/get_item_history.php]

@session_start();
// (1. เธ•เธฃเธงเธเธชเธญเธ Session Admin เนเธฅเธฐเน€เธเธทเนเธญเธกเธ•เนเธญ DB)
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

try {
    // (2. เธ•เธฃเธงเธเธชเธญเธ Input)
    if (!isset($_GET['item_id']) || !is_numeric($_GET['item_id'])) {
        throw new Exception('เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธญเธธเธเธเธฃเธ“เน');
    }
    $item_id = intval($_GET['item_id']);

    // (3. Query เธเธฃเธฐเธงเธฑเธ•เธดเธเธฒเธฃเธขเธทเธก)
    // เน€เธฃเธฒเธเธฐเธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฒเธ borrow_records เธ—เธตเนเธ•เธฃเธเธเธฑเธ item_id เธเธตเน
    // เนเธฅเธฐ JOIN เธเธฑเธ sys_users เน€เธเธทเนเธญเน€เธญเธฒเธเธทเนเธญเธเธนเนเธขเธทเธก
    $sql = "SELECT 
                t.borrow_date, 
                t.return_date,
                s.full_name AS borrower_name
            FROM borrow_records t
            JOIN sys_users s ON t.borrower_student_id = s.id
            WHERE t.item_id = ?
            ORDER BY t.borrow_date DESC"; // (เน€เธฃเธตเธขเธเธเธฒเธเธฅเนเธฒเธชเธธเธ”เนเธเน€เธเนเธฒเธชเธธเธ”)
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$item_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // (4. เธชเนเธเธเนเธญเธกเธนเธฅเธเธฅเธฑเธ)
    $response['status'] = 'success';
    $response['history'] = $history;

} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
