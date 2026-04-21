<?php
// process/admin_send_line_process.php
// สำหรับ Admin ส่งข้อความหา User ผ่าน LINE

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');
require_once('../includes/line_config.php');

// ตรวจสอบสิทธิ์ (Admin หรือ Editor)
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'editor'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// รับข้อมูล
$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if ($student_id == 0 || empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

try {
    // 1. ดึง LINE User ID จากตาราง sys_users
    $stmt = $pdo->prepare("SELECT line_user_id, full_name FROM sys_users WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student || empty($student['line_user_id'])) {
        throw new Exception("ผู้ใช้นี้ยังไม่ได้ผูกบัญชี LINE");
    }

    // 2. ส่งข้อความผ่าน LINE Messaging API
    $access_token = LINE_MESSAGING_API_TOKEN;
    $push_url = 'https://api.line.me/v2/bot/message/push';
    
    $data = [
        'to' => $student['line_user_id'],
        'messages' => [
            [
                'type' => 'text',
                'text' => "ข้อความจากเจ้าหน้าที่:\n\n" . $message
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
        // 3. บันทึก Log การทำงาน
        $admin_id = $_SESSION['user_id'];
        log_action($pdo, $admin_id, 'send_line_msg', "ส่งข้อความหา '{$student['full_name']}': $message");

        echo json_encode(['status' => 'success', 'message' => 'ส่งข้อความสำเร็จ']);
    } else {
        throw new Exception("LINE API Error: $http_code");
    }

} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
