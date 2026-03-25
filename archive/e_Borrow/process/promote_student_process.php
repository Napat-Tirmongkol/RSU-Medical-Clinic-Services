<?php
// promote_student_process.php
// เธฃเธฑเธเธเนเธญเธกเธนเธฅเธเธฒเธ Popup "เน€เธฅเธทเนเธญเธเธเธฑเนเธ"

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
    $student_id   = isset($_POST['student_id_to_promote']) ? (int)$_POST['student_id_to_promote'] : 0;
    $line_user_id = isset($_POST['line_user_id_to_link']) ? trim($_POST['line_user_id_to_link']) : '';
    $new_username = isset($_POST['new_username']) ? trim($_POST['new_username']) : '';
    $new_password = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';
    $new_role     = isset($_POST['new_role']) ? trim($_POST['new_role']) : 'employee';

    if ($student_id == 0 || empty($line_user_id) || empty($new_username) || empty($new_password)) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเธ—เธตเนเธชเนเธเธกเธฒเนเธกเนเธเธฃเธเธ–เนเธงเธ';
        echo json_encode($response);
        exit;
    }
    if ($new_role != 'admin' && $new_role != 'employee') {
        $response['message'] = 'เธชเธดเธ—เธเธดเน (Role) เนเธกเนเธ–เธนเธเธ•เนเธญเธ';
        echo json_encode($response);
        exit;
    }

    // 6. เธ”เธณเน€เธเธดเธเธเธฒเธฃ "เน€เธฅเธทเนเธญเธเธเธฑเนเธ"
    try {
        // 6.1 เธ”เธถเธ "เธเธทเนเธญเน€เธ•เนเธก" เธเธฒเธ sys_users
        $stmt_get = $pdo->prepare("SELECT full_name FROM sys_users WHERE id = ? AND line_user_id = ?");
        $stmt_get->execute([$student_id, $line_user_id]);
        $student_full_name = $stmt_get->fetchColumn();

        if (!$student_full_name) {
            throw new Exception("เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธเธ—เธตเนเธ•เธฃเธเธเธฑเธ เธซเธฃเธทเธญเธเธนเนเนเธเนเธเธฒเธเนเธกเนเธกเธต LINE ID");
        }

        // 6.2 (เน€เธเนเธเธเนเธณ) เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒ Username เธเธตเนเธ–เธนเธเนเธเนเนเธเธซเธฃเธทเธญเธขเธฑเธ
        $stmt_check_user = $pdo->prepare("SELECT id FROM sys_staff WHERE username = ?");
        $stmt_check_user->execute([$new_username]);
        if ($stmt_check_user->fetch()) {
            throw new Exception("Username '$new_username' เธเธตเนเธ–เธนเธเนเธเนเธเธฒเธเนเธฅเนเธง");
        }

        // 6.3 (เน€เธเนเธเธเนเธณ) เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒ LINE ID เธเธตเนเธ–เธนเธเธเธนเธเนเธเธซเธฃเธทเธญเธขเธฑเธ
        $stmt_check_line = $pdo->prepare("SELECT id FROM sys_staff WHERE linked_line_user_id = ?");
        $stmt_check_line->execute([$line_user_id]);
        if ($stmt_check_line->fetch()) {
            throw new Exception("LINE ID เธเธตเนเธ–เธนเธเน€เธเธทเนเธญเธกเนเธขเธเธเธฑเธเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธเธญเธทเนเธเนเธฅเนเธง");
        }
        
        // 6.4 เน€เธเนเธฒเธฃเธซเธฑเธชเธฃเธซเธฑเธชเธเนเธฒเธเนเธซเธกเน
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

        // 6.5 (SQL) INSERT เธเนเธญเธกเธนเธฅเน€เธเนเธฒ sys_staff
        $sql = "INSERT INTO sys_staff (username, password_hash, full_name, role, linked_line_user_id) 
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_username, $password_hash, $student_full_name, $new_role, $line_user_id]);

        $new_user_id = $pdo->lastInsertId();

        // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
        if ($stmt->rowCount() > 0) {
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเน€เธฅเธทเนเธญเธเธเธฑเนเธเธเธนเนเนเธเนเธเธฒเธ (SID: {$student_id}) '{$student_full_name}' เน€เธเนเธ '{$new_role}' (UID เนเธซเธกเน: {$new_user_id})";
            log_action($pdo, $admin_user_id, 'promote_user', $log_desc);
        }
        // โ—€๏ธ --- (เธเธเธชเนเธงเธ Log) --- โ—€๏ธ

        // 7. เธ–เนเธฒเธชเธณเน€เธฃเนเธ เนเธซเนเน€เธเธฅเธตเนเธขเธเธเธณเธ•เธญเธ
        $response['status'] = 'success';
        $response['message'] = 'เน€เธฅเธทเนเธญเธเธเธฑเนเธเธเธนเนเนเธเนเธเธฒเธเธชเธณเน€เธฃเนเธ';

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

// 8. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>
