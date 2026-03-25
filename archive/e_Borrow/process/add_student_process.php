<?php
// add_student_process.php
// เธฃเธฑเธเธเนเธญเธกเธนเธฅเธเธฒเธ Popup 'เน€เธเธดเนเธกเธเธนเนเนเธเนเธเธฒเธ (เนเธ”เธข Admin)'

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php'); // โ—€๏ธ (เน€เธเธดเนเธก) เน€เธฃเธตเธขเธเนเธเน Log

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin เนเธฅเธฐเธ•เธฑเนเธเธเนเธฒ Header
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}
header('Content-Type: application/json');

// 3. เธชเธฃเนเธฒเธเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเน€เธเนเธเธเธณเธ•เธญเธ
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

// 4. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเน€เธเนเธเธเธฒเธฃเธชเนเธเธเนเธญเธกเธนเธฅเนเธเธ POST เธซเธฃเธทเธญเนเธกเน
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 5. เธฃเธฑเธเธเนเธญเธกเธนเธฅเธเธฒเธเธเธญเธฃเนเธก AJAX
    $full_name    = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : null;

    if (empty($full_name)) {
        $response['message'] = 'เธเธฃเธธเธ“เธฒเธเธฃเธญเธ เธเธทเนเธญ-เธชเธเธธเธฅ';
        echo json_encode($response);
        exit;
    }
    
    if (empty($phone_number)) $phone_number = null;

    // 6. (SQL เนเธซเธกเน) เธ”เธณเน€เธเธดเธเธเธฒเธฃ INSERT เธฅเธ sys_users
    try {
        $sql = "INSERT INTO sys_users (full_name, phone_number, status, line_user_id, student_personnel_id) 
                VALUES (?, ?, 'other', NULL, '(Staff-Added)')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$full_name, $phone_number]);

        $new_student_id = $pdo->lastInsertId();

        // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
        if ($stmt->rowCount() > 0) {
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเน€เธเธดเนเธกเธเธนเนเนเธเนเธเธฒเธ (เนเธ”เธข Admin) เธเธทเนเธญ: '{$full_name}' (ID เนเธซเธกเน: {$new_student_id})";
            log_action($pdo, $admin_user_id, 'create_user_staff', $log_desc);
        }
        // โ—€๏ธ --- (เธเธเธชเนเธงเธ Log) --- โ—€๏ธ

        // 7. เธ–เนเธฒเธชเธณเน€เธฃเนเธ เนเธซเนเน€เธเธฅเธตเนเธขเธเธเธณเธ•เธญเธ
        $response['status'] = 'success';
        $response['message'] = 'เน€เธเธดเนเธกเธเธนเนเนเธเนเธเธฒเธเนเธซเธกเนเธชเธณเน€เธฃเนเธ';

    } catch (PDOException $e) {
        $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

// 8. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>
