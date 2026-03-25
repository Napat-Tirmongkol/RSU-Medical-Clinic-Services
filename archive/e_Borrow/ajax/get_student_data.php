<?php
// get_student_data.php
// เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธ (sys_users) เธชเธณเธซเธฃเธฑเธ Popup เนเธเนเนเธ

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
    'student' => null // (เธเธเธเธทเนเธญเธ•เธฑเธงเนเธเธฃ student เนเธงเน)
];

// 4. เธฃเธฑเธ ID เธเธนเนเนเธเนเธเธฒเธ (Student ID) เธเธฒเธ URL
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($student_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธเธนเนเนเธเนเธเธฒเธ';
    echo json_encode($response);
    exit;
}

try {
    // 5. (SQL) เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฒเธ sys_users
    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        $response['status'] = 'success';
        $response['student'] = $student; 
        $response['message'] = 'เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธณเน€เธฃเนเธ';
    } else {
        $response['message'] = 'เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธ';
    }

} catch (PDOException $e) {
    $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
}

// 6. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>
