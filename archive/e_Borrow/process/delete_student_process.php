<?php
// [เนเธเนเนเธเนเธเธฅเน: napat-tirmongkol/e-borrow/E-Borrow-c4df732f98db10bf52a8e9d7299e212b6f2abd37/process/delete_student_process.php]
// delete_student_process.php
// (เธญเธฑเธเน€เธเธฃเธ•: เน€เธเธดเนเธกเธเธฒเธฃเธเธฑเธเธ—เธถเธ Log)

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
// โ… (เนเธเนเนเธ Path) เน€เธเธดเนเธก ../ เนเธฅเธฐเน€เธเธฅเธตเนเธขเธเน€เธเนเธเธขเธฒเธกเธเธญเธ AJAX
include('../includes/check_session_ajax.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php'); 

// Set header to return JSON
header('Content-Type: application/json');

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}

// 3. เธฃเธฑเธ ID เธเธนเนเนเธเนเธเธฒเธเธเธฒเธ POST
$student_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($student_id == 0) {
    echo json_encode(['status' => 'error', 'message' => 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธเธนเนเนเธเนเธเธฒเธ']);
    exit;
}

// 4. เธ•เธฃเธงเธเธชเธญเธ Foreign Key
try {
    $sql_check = "SELECT COUNT(*) FROM borrow_records WHERE borrower_student_id = ?";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([$student_id]);
    $transaction_count = $stmt_check->fetchColumn();

    if ($transaction_count > 0) {
        echo json_encode(['status' => 'error', 'message' => 'เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธเธเธนเนเนเธเนเธเธฒเธเนเธ”เน เน€เธเธทเนเธญเธเธเธฒเธเธกเธตเธเธฃเธฐเธงเธฑเธ•เธดเธเธฒเธฃเธ—เธณเธฃเธฒเธขเธเธฒเธฃเธเนเธฒเธเธญเธขเธนเน!']);
        exit;
    }

    // โ—€๏ธ --- (เนเธซเธกเน: เธชเนเธงเธ Log) --- โ—€๏ธ
    // (เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเน "เธเนเธญเธ" เธ—เธตเนเธเธฐเธฅเธ)
    $stmt_get = $pdo->prepare("SELECT full_name, line_user_id FROM sys_users WHERE id = ?");
    $stmt_get->execute([$student_id]);
    $student_info = $stmt_get->fetch(PDO::FETCH_ASSOC);
    $student_name_for_log = $student_info ? $student_info['full_name'] : "ID: {$student_id}";
    // (เนเธขเธเธฃเธฐเน€เธ เธ— Log เธฃเธฐเธซเธงเนเธฒเธ User เธ—เธตเน Admin เน€เธเธดเนเธกเน€เธญเธ เธซเธฃเธทเธญ User เธ—เธตเนเธกเธฒเธเธฒเธ LINE)
    $log_action_type = $student_info && $student_info['line_user_id'] ? 'delete_user_line' : 'delete_user_staff';
    // โ—€๏ธ --- (เธเธเธชเนเธงเธเธ”เธถเธเธเนเธญเธกเธนเธฅ Log) --- โ—€๏ธ

    // 6. เธ”เธณเน€เธเธดเธเธเธฒเธฃเธฅเธ
    $sql_delete = "DELETE FROM sys_users WHERE id = ?";
    $stmt_delete = $pdo->prepare($sql_delete);
    $stmt_delete->execute([$student_id]);

    // 7. เธ•เธฃเธงเธเธชเธญเธ
    if ($stmt_delete->rowCount() > 0) {
        
        // โ—€๏ธ --- (เนเธซเธกเน: เธเธฑเธเธ—เธถเธ Log) --- โ—€๏ธ
        $admin_user_id = $_SESSION['user_id'] ?? null;
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเธฅเธเธเธนเนเนเธเนเธเธฒเธ: '{$student_name_for_log}' (SID: {$student_id})";
        log_action($pdo, $admin_user_id, $log_action_type, $log_desc);
        // โ—€๏ธ --- (เธเธเธชเนเธงเธ Log) --- โ—€๏ธ

        echo json_encode(['status' => 'success', 'message' => 'เธฅเธเธเธนเนเนเธเนเธเธฒเธเธชเธณเน€เธฃเนเธ']);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'เนเธกเนเธเธเธเธนเนเนเธเนเธเธฒเธเธซเธฃเธทเธญเนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธเนเธ”เน']);
        exit;
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ' . $e->getMessage()]);
    exit;
}
?>
