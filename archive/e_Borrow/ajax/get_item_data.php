<?php
// ajax/get_item_data.php
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
$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($item_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธญเธธเธเธเธฃเธ“เน';
    echo json_encode($response);
    exit;
}

try {
    // 5. เธ”เธถเธเธเนเธญเธกเธนเธฅ
    $stmt = $pdo->prepare("SELECT * FROM borrow_items WHERE id = ?");
    $stmt->execute([$item_id]);
    $item_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($item_data) {
        $response['status'] = 'success';
        $response['item'] = $item_data;
    } else {
        $response['message'] = 'เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธญเธธเธเธเธฃเธ“เน (ID: ' . $item_id . ')';
    }

} catch (PDOException $e) {
    $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage();
}

// 6. เธชเนเธเธเธณเธ•เธญเธ
echo json_encode($response);
exit;
?>