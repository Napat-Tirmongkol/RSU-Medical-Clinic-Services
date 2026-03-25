<?php
// ajax/get_items_for_type.php
// (เนเธเธฅเนเนเธซเธกเน)

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin เนเธฅเธฐเธ•เธฑเนเธเธเนเธฒ Header
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}
header('Content-Type: application/json');

// 3. เธชเธฃเนเธฒเธเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเน€เธเนเธเธเธณเธ•เธญเธ
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

// 4. เธฃเธฑเธ ID
$type_id = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;

if ($type_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน';
    echo json_encode($response);
    exit;
}

try {
    // 5. (Query เธ—เธตเน 1) เธ”เธถเธเธเนเธญเธกเธนเธฅ "เธเธฃเธฐเน€เธ เธ—" (เน€เธเธทเนเธญเนเธเนเธเธทเนเธญ)
    $stmt_type = $pdo->prepare("SELECT name FROM borrow_categories WHERE id = ?");
    $stmt_type->execute([$type_id]);
    $type_data = $stmt_type->fetch(PDO::FETCH_ASSOC);

    if (!$type_data) {
        throw new Exception("เนเธกเนเธเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน (ID: $type_id)");
    }

    // 6. (Query เธ—เธตเน 2) เธ”เธถเธเธเนเธญเธกเธนเธฅ "เธเธดเนเธ" เธญเธธเธเธเธฃเธ“เน (items)
    $stmt_items = $pdo->prepare("SELECT * FROM borrow_items WHERE type_id = ? ORDER BY id ASC");
    $stmt_items->execute([$type_id]);
    $items_data = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    // 7. เธชเนเธเธเนเธญเธกเธนเธฅเธเธฅเธฑเธ
    $response['status'] = 'success';
    $response['type'] = $type_data;
    $response['items'] = $items_data;


} catch (PDOException $e) {
    $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage();
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// 8. เธชเนเธเธเธณเธ•เธญเธ
echo json_encode($response);
exit;
?>