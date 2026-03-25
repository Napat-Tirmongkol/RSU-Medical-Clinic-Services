<?php
// process/admin_send_line_process.php
// เธชเธณเธซเธฃเธฑเธ Admin เธชเนเธเธเนเธญเธเธงเธฒเธกเธซเธฒ User เธเนเธฒเธ LINE

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');
require_once('../includes/line_config.php');

// เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน (Admin เธซเธฃเธทเธญ Editor)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'editor'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// เธฃเธฑเธเธเนเธญเธกเธนเธฅ
$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($student_id == 0 || empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'เธเนเธญเธกเธนเธฅเนเธกเนเธเธฃเธเธ–เนเธงเธ']);
    exit;
}

try {
    // 1. เธ”เธถเธ LINE User ID เธเธฒเธเธ•เธฒเธฃเธฒเธ sys_users
    $stmt = $pdo->prepare("SELECT line_user_id, full_name FROM sys_users WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student || empty($student['line_user_id'])) {
        throw new Exception("เธเธนเนเนเธเนเธเธตเนเธขเธฑเธเนเธกเนเนเธ”เนเธเธนเธเธเธฑเธเธเธต LINE");
    }

    // 2. เธชเนเธเธเนเธญเธเธงเธฒเธกเธเนเธฒเธ LINE Messaging API
    $access_token = LINE_MESSAGING_API_TOKEN;
    $push_url = 'https://api.line.me/v2/bot/message/push';
    
    $data = [
        'to' => $student['line_user_id'],
        'messages' => [
            [
                'type' => 'text',
                'text' => "เธเนเธญเธเธงเธฒเธกเธเธฒเธเน€เธเนเธฒเธซเธเนเธฒเธ—เธตเน:\n\n" . $message
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $push_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200) {
        // 3. เธเธฑเธเธ—เธถเธ Log เธเธฒเธฃเธ—เธณเธเธฒเธ
        $admin_id = $_SESSION['user_id'];
        log_action($pdo, $admin_id, 'send_line_msg', "เธชเนเธเธเนเธญเธเธงเธฒเธกเธซเธฒ '{$student['full_name']}': $message");

        echo json_encode(['status' => 'success', 'message' => 'เธชเนเธเธเนเธญเธเธงเธฒเธกเธชเธณเน€เธฃเนเธ']);
    } else {
        throw new Exception("LINE API Error: $http_code");
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
