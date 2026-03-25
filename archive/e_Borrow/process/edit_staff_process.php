<?php
// edit_staff_process.php
// (เนเธเธฅเนเนเธซเธกเน)

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
    $user_id      = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $username     = isset($_POST['username']) ? trim($_POST['username']) : '';
    $new_password = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';
    $full_name    = isset($_POST['full_name']) ? trim($_POST['full_name']) : null;
    $role         = isset($_POST['role']) ? trim($_POST['role']) : null;

    if ($user_id == 0 || empty($username)) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเธ—เธตเนเธชเนเธเธกเธฒเนเธกเนเธเธฃเธเธ–เนเธงเธ (ID เธซเธฃเธทเธญ Username)';
        echo json_encode($response);
        exit;
    }

    // 6. เธ”เธณเน€เธเธดเธเธเธฒเธฃ UPDATE
    try {
        // 6.1 เธ”เธถเธเธเนเธญเธกเธนเธฅเน€เธ”เธดเธก
        $stmt_get = $pdo->prepare("SELECT username, full_name, role, linked_line_user_id FROM sys_staff WHERE id = ?");
        $stmt_get->execute([$user_id]);
        $current_data = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if (!$current_data) {
            throw new Exception("เนเธกเนเธเธเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธ ID: $user_id");
        }

        // 6.2 เธ•เธฃเธงเธเธชเธญเธ Username เธเนเธณ
        if ($current_data['username'] != $username) {
            $stmt_check = $pdo->prepare("SELECT id FROM sys_staff WHERE username = ?");
            $stmt_check->execute([$username]);
            if ($stmt_check->fetch()) {
                throw new Exception("Username '$username' เธเธตเนเธ–เธนเธเนเธเนเธเธฒเธเนเธฅเนเธง");
            }
        }

        // 6.3 (เธ•เธฃเธฃเธเธฐ) เธ–เนเธฒเน€เธเนเธเธเธฑเธเธเธตเธ—เธตเนเธเธนเธเธเธฑเธ LINE
        if ($current_data['linked_line_user_id']) {
            $sql = "UPDATE sys_staff SET username = ?";
            $params = [$username];
        } 
        // (เธ–เนเธฒเน€เธเนเธเธเธฑเธเธเธตเธเธเธ•เธด)
        else {
           	if (!in_array($role, ['admin', 'employee', 'editor'])) {
                throw new Exception("เธชเธดเธ—เธเธดเน (Role) เธ—เธตเนเธชเนเธเธกเธฒเนเธกเนเธ–เธนเธเธ•เนเธญเธ");
            }
            $sql = "UPDATE sys_staff SET username = ?, full_name = ?, role = ?";
            $params = [$username, $full_name, $role];
        }
        
        // 6.4 (เธ•เธฃเธฃเธเธฐ) เธ–เนเธฒเธกเธตเธเธฒเธฃเธเธฃเธญเธ "เธฃเธซเธฑเธชเธเนเธฒเธเนเธซเธกเน"
        if (!empty($new_password)) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $sql .= ", password_hash = ?";
            $params[] = $password_hash;
        }

        // 6.5 (เธฃเธงเธกเธฃเนเธฒเธ)
        $sql .= " WHERE id = ?";
        $params[] = $user_id;
        
        $stmt_update = $pdo->prepare($sql);
        $stmt_update->execute($params);

        // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
        if ($stmt_update->rowCount() > 0) {
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเนเธเนเนเธเธเนเธญเธกเธนเธฅเธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธ (UID: {$user_id}, Username: {$username})";
            if (!empty($new_password)) {
                $log_desc .= " (เธกเธตเธเธฒเธฃ Reset เธฃเธซเธฑเธชเธเนเธฒเธ)";
            }
            log_action($pdo, $admin_user_id, 'edit_staff', $log_desc);
        }
        // โ—€๏ธ --- (เธเธเธชเนเธงเธ Log) --- โ—€๏ธ

        // 7. เธ–เนเธฒเธชเธณเน€เธฃเนเธ
        $response['status'] = 'success';
        $response['message'] = 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเนเธเธฅเธเธชเธณเน€เธฃเนเธ';

    } catch (Exception $e) {
        $response['message'] = $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

// 8. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>
