<?php
// edit_student_process.php
// (เธญเธฑเธเน€เธ”เธ•: เธฃเธฑเธ status, department, status_other)

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

    // 5. เธฃเธฑเธเธเนเธญเธกเธนเธฅเธเธฒเธเธเธญเธฃเนเธก AJAX (เธญเธฑเธเน€เธ”เธ•เธ•เธฑเธงเนเธเธฃ)
    $student_id   = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $full_name    = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : null;
    $student_personnel_id = isset($_POST['student_personnel_id']) ? trim($_POST['student_personnel_id']) : null;
    
    // (เธ•เธฑเธงเนเธเธฃเนเธซเธกเน)
    $department   = isset($_POST['department']) ? trim($_POST['department']) : null;
    $status       = isset($_POST['status']) ? trim($_POST['status']) : '';
    $status_other = isset($_POST['status_other']) ? trim($_POST['status_other']) : null;


    // (Validation เนเธซเธกเน)
    if ($student_id == 0 || empty($full_name) || empty($status)) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเนเธกเนเธเธฃเธเธ–เนเธงเธ (ID, เธเธทเนเธญ-เธชเธเธธเธฅ, เธซเธฃเธทเธญเธชเธ–เธฒเธเธ เธฒเธ)';
        echo json_encode($response);
        exit;
    }
    if ($status == 'other' && empty($status_other)) {
        $response['message'] = 'เธเธฃเธธเธ“เธฒเธฃเธฐเธเธธเธชเธ–เธฒเธเธ เธฒเธ "เธญเธทเนเธเน"';
        echo json_encode($response);
        exit;
    }
    
    // (เธ—เธณเนเธซเนเธเนเธฒเธงเนเธฒเธเน€เธเนเธ NULL)
    if (empty($phone_number)) $phone_number = null;
    if (empty($student_personnel_id)) $student_personnel_id = null;
    if (empty($department)) $department = null;
    if ($status != 'other') $status_other = null;


    // 6. (SQL เนเธซเธกเน) เธ”เธณเน€เธเธดเธเธเธฒเธฃ UPDATE เธ•เธฒเธฃเธฒเธ sys_users
    try {
        $sql = "UPDATE sys_users 
                SET full_name = ?, 
                    phone_number = ?, 
                    student_personnel_id = ?,
                    department = ?,
                    status = ?,
                    status_other = ?
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $full_name, 
            $phone_number, 
            $student_personnel_id,
            $department,
            $status,
            $status_other,
            $student_id
        ]);

        // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
        if ($stmt->rowCount() > 0) {
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเนเธเนเนเธเธเนเธญเธกเธนเธฅเธเธนเนเนเธเนเธเธฒเธ: '{$full_name}' (SID: {$student_id})";
            log_action($pdo, $admin_user_id, 'edit_user', $log_desc);
        }
        // โ—€๏ธ --- (เธเธเธชเนเธงเธ Log) --- โ—€๏ธ

        // 7. เธ–เนเธฒเธชเธณเน€เธฃเนเธ เนเธซเนเน€เธเธฅเธตเนเธขเธเธเธณเธ•เธญเธ
        $response['status'] = 'success';
        $response['message'] = 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเนเธเธฅเธเธชเธณเน€เธฃเนเธ';

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
