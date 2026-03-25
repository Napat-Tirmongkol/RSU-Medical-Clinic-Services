<?php
// get_staff_data.php
// (เนเธเธฅเนเนเธซเธกเน)

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin เนเธฅเธฐเธ•เธฑเนเธเธเนเธฒ Header
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}
header('Content-Type: application/json');

// 3. เธชเธฃเนเธฒเธเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเน€เธเนเธเธเธณเธ•เธญเธ
$response = [
    'status' => 'error',
    'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ',
    'staff' => null 
];

// 4. เธฃเธฑเธ ID เน€เธเนเธฒเธซเธเนเธฒเธ—เธตเน (User ID) เธเธฒเธ URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($user_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เน€เธเนเธฒเธซเธเนเธฒเธ—เธตเน';
    echo json_encode($response);
    exit;
}

try {
    // 5. (SQL) เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฒเธ sys_staff
    // (เน€เธฃเธฒเนเธกเนเธ”เธถเธ password_hash เธกเธฒ)
    $stmt = $pdo->prepare("SELECT id, username, full_name, role, linked_line_user_id FROM sys_staff WHERE id = ?");
    $stmt->execute([$user_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($staff) {
        $response['status'] = 'success';
        $response['staff'] = $staff; 
        $response['message'] = 'เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธณเน€เธฃเนเธ';
    } else {
        $response['message'] = 'เนเธกเนเธเธเธเนเธญเธกเธนเธฅเน€เธเนเธฒเธซเธเนเธฒเธ—เธตเน';
    }

} catch (PDOException $e) {
    $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
}

// 6. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>
