<?php
// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}

// 3. เธ•เธฑเนเธเธเนเธฒ Header เนเธซเนเธ•เธญเธเธเธฅเธฑเธเน€เธเนเธ JSON
header('Content-Type: application/json');

// 4. เธชเธฃเนเธฒเธเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเน€เธเนเธเธเธณเธ•เธญเธ
$response = [
    'status' => 'error', 
    'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ',
    'equipment' => null
];

// 5. เธฃเธฑเธ ID เธญเธธเธเธเธฃเธ“เนเธเธฒเธ URL
$equipment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($equipment_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธญเธธเธเธเธฃเธ“เน';
    echo json_encode($response);
    exit;
}

try {
    // 6. (เนเธเนเนเธ) เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน
    $stmt = $pdo->prepare("SELECT * FROM borrow_categories WHERE id = ?");
    $stmt->execute([$equipment_id]);
    $equipment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($equipment) {
        $response['status'] = 'success';
        $response['equipment'] = $equipment;
        $response['message'] = 'เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธณเน€เธฃเนเธ';
    } else {
        $response['message'] = 'เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธญเธธเธเธเธฃเธ“เน';
    }

} catch (PDOException $e) {
    $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
}

// 7. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>