<?php
// ajax/get_equipment_type_data.php
// (เนเธเธฅเนเนเธซเธกเน)

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin (เน€เธเธฃเธฒเธฐเนเธเธฅเนเธเธตเนเธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธณเธซเธฃเธฑเธ Admin)
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
$type_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($type_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน';
    echo json_encode($response);
    exit;
}

try {
    // 5. เธ”เธถเธเธเนเธญเธกเธนเธฅ
    $stmt = $pdo->prepare("SELECT * FROM borrow_categories WHERE id = ?");
    $stmt->execute([$type_id]);
    $type_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($type_data) {
        $response['status'] = 'success';
        $response['equipment_type'] = $type_data;
    } else {
        $response['message'] = 'เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน (ID: ' . $type_id . ')';
    }

} catch (PDOException $e) {
    $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage();
}

// 6. เธชเนเธเธเธณเธ•เธญเธ
echo json_encode($response);
exit;
?>