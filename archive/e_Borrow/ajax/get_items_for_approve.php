<?php
// ajax/get_items_for_approve.php
require_once('../includes/check_session_ajax.php'); // เธ•เธฃเธงเธเธชเธญเธ Session
require_once(__DIR__ . '/../../../config/db_connect.php');

header('Content-Type: application/json');

if (!isset($_GET['transaction_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'เนเธกเนเธเธ Transaction ID']);
    exit;
}

$trans_id = $_GET['transaction_id'];

try {
    // 1. เธ”เธนเธงเนเธฒเธเธณเธเธญเธเธตเน เธเธญเธเธญเธธเธเธเธฃเธ“เนเธเธฃเธฐเน€เธ เธ—เนเธซเธ (Type ID) เนเธฅเธฐเธ•เธฑเธงเน€เธ”เธดเธกเธเธทเธญ ID เธญเธฐเนเธฃ
    $stmt = $pdo->prepare("SELECT type_id, item_id FROM borrow_records WHERE id = ?");
    $stmt->execute([$trans_id]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trans) {
        echo json_encode(['status' => 'error', 'message' => 'เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธณเธเธญ']);
        exit;
    }

    $type_id = $trans['type_id'];
    $current_item_id = $trans['item_id'];

    // 2. เธ”เธถเธเธฃเธฒเธขเธเธฒเธฃเธเธญเธเธ—เธตเน "เธงเนเธฒเธ" (Available) เธซเธฃเธทเธญ "เธ•เธฑเธงเธ—เธตเนเธเธญเธเธญเธขเธนเน" (เน€เธเธทเนเธญเนเธกเนเนเธซเนเธ•เธฑเธงเธกเธฑเธเน€เธญเธเธซเธฒเธขเนเธเธเธฒเธ list)
    $sql = "SELECT id, serial_number 
            FROM borrow_items 
            WHERE type_id = ? 
            AND (status = 'available' OR id = ?)";
            
    $stmt_items = $pdo->prepare($sql);
    $stmt_items->execute([$type_id, $current_item_id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'items' => $items]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>