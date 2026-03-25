<?php
// demote_staff_process.php
// เธฃเธฑเธ ID เธเธเธฑเธเธเธฒเธ (sys_staff) เธกเธฒเน€เธเธทเนเธญเธฅเธ

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
include('includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('includes/log_function.php'); // โ—€๏ธ (เน€เธเธดเนเธก) เน€เธฃเธตเธขเธเนเธเน Log

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

    // 5. เธฃเธฑเธ ID เธเธเธฑเธเธเธฒเธ
    $user_id = isset($_POST['user_id_to_demote']) ? (int)$_POST['user_id_to_demote'] : 0;

    if ($user_id == 0) {
        $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธเธเธฑเธเธเธฒเธ';
        echo json_encode($response);
        exit;
    }
    
    if ($user_id == $_SESSION['user_id']) {
         $response['message'] = 'เธเธธเธ“เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธ”เธชเธดเธ—เธเธดเนเธเธฑเธเธเธตเธเธญเธเธ•เธฑเธงเน€เธญเธเนเธ”เน';
         echo json_encode($response);
         exit;
    }

    // 6. เธ•เธฃเธงเธเธชเธญเธ Foreign Key
    try {
        $sql_check = "SELECT COUNT(*) FROM borrow_records WHERE lending_staff_id = ?";
        $stmt_check = $pdo->prepare($sql_check);
        $stmt_check->execute([$user_id]);
        $transaction_count = $stmt_check->fetchColumn();

        if ($transaction_count > 0) {
             throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธ/เธฅเธ”เธชเธดเธ—เธเธดเนเนเธ”เน เน€เธเธทเนเธญเธเธเธฒเธเธเธเธฑเธเธเธฒเธเธเธเธเธตเนเธกเธตเธเธฃเธฐเธงเธฑเธ•เธดเธเธฒเธฃเธญเธเธธเธกเธฑเธ•เธดเธเธณเธเธญเธเนเธฒเธเธญเธขเธนเน (Foreign Key Constraint)");
        }

        // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
        // (เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธเธฑเธเธเธฒเธ "เธเนเธญเธ" เธ—เธตเนเธเธฐเธฅเธ)
        $stmt_get = $pdo->prepare("SELECT username, full_name FROM sys_staff WHERE id = ?");
        $stmt_get->execute([$user_id]);
        $staff_info = $stmt_get->fetch(PDO::FETCH_ASSOC);
        $staff_name_for_log = $staff_info ? "{$staff_info['full_name']} (Username: {$staff_info['username']})" : "ID: {$user_id}";
        // โ—€๏ธ --- (เธเธเธชเนเธงเธเธ”เธถเธเธเนเธญเธกเธนเธฅ Log) --- โ—€๏ธ

        // 8. เธ–เนเธฒเนเธกเนเธกเธตเธเธฃเธฐเธงเธฑเธ•เธด -> เธ”เธณเน€เธเธดเธเธเธฒเธฃเธฅเธเธเธฒเธ sys_staff
        $sql_delete = "DELETE FROM sys_staff WHERE id = ?";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([$user_id]);

        if ($stmt_delete->rowCount() > 0) {
            
            // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเธฅเธ/เธฅเธ”เธชเธดเธ—เธเธดเนเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธ: '{$staff_name_for_log}'";
            log_action($pdo, $admin_user_id, 'delete_staff', $log_desc);
            // โ—€๏ธ --- (เธเธเธชเนเธงเธ Log) --- โ—€๏ธ

            $response['status'] = 'success';
            $response['message'] = 'เธฅเธ”เธชเธดเธ—เธเธดเน/เธฅเธเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธเธเธฅเธฑเธเน€เธเนเธเธเธนเนเนเธเนเธเธฒเธเธชเธณเน€เธฃเนเธ';
        } else {
            throw new Exception("เนเธกเนเธเธเธเธเธฑเธเธเธฒเธเธเธเธเธตเนเนเธเธฃเธฐเธเธ (เธญเธฒเธเธ–เธนเธเธฅเธเนเธเนเธฅเนเธง)");
        }

    } catch (Exception $e) {
        $response['message'] = $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

// 9. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>
