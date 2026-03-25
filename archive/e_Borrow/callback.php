<?php
// line_callback.php (เน€เธงเธญเธฃเนเธเธฑเธเธญเธฑเธเน€เธเธฃเธ•: เน€เธเนเธ 2 เธ•เธฒเธฃเธฒเธ)

session_start();
require_once(__DIR__ . '/includes/line_config.php');
require_once(__DIR__ . '/../../config/db_connect.php');
require_once(__DIR__ . '/includes/log_function.php');

// --- (เธเธฑเธเธเนเธเธฑเธ die_with_error ... เน€เธซเธกเธทเธญเธเน€เธ”เธดเธก) ---
function die_with_error($message) {
    echo "
        <head><title>เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”</title><link rel='stylesheet' href='CSS/style.css'></head>
        <body style='display: flex; justify-content: center; align-items: center; height: 100vh; background: #f4f4f4;'>
            <div style='background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center;'>
                <h1 style='color: #dc3545;'>เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”</h1>
                <p>" . htmlspecialchars($message) . "</p>
                <a href='login.php'>เธเธฅเธฑเธเนเธเธซเธเนเธฒ Login</a>
            </div>
        </body>
    ";
    exit;
}
// ---------------------------------

// 1. เธ•เธฃเธงเธเธชเธญเธ 'state' เนเธฅเธฐ 'code' (เน€เธซเธกเธทเธญเธเน€เธ”เธดเธก)
if (!isset($_GET['state']) || !isset($_SESSION['line_login_state']) || $_GET['state'] !== $_SESSION['line_login_state']) {
    die_with_error('State เนเธกเนเธ–เธนเธเธ•เนเธญเธ (Invalid State). เธเธฃเธธเธ“เธฒเธฅเธญเธเนเธซเธกเนเธญเธตเธเธเธฃเธฑเนเธ');
}
unset($_SESSION['line_login_state']);
if (!isset($_GET['code'])) {
    die_with_error('เนเธกเนเนเธ”เนเธฃเธฑเธ Authorization Code เธเธฒเธ LINE');
}
$code = $_GET['code'];

// 3. เนเธฅเธ Code เน€เธเนเธ Access Token (เน€เธซเธกเธทเธญเธเน€เธ”เธดเธก)
$token_url = "https://api.line.me/oauth2/v2.1/token";
$post_data = [ /* ... (เธเนเธญเธกเธนเธฅ post_data ... เน€เธซเธกเธทเธญเธเน€เธ”เธดเธก) ... */ 
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => LINE_LOGIN_CALLBACK_URL,
    'client_id' => LINE_LOGIN_CHANNEL_ID,
    'client_secret' => LINE_LOGIN_CHANNEL_SECRET
];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$response_json = curl_exec($ch);
curl_close($ch);
$response_data = json_decode($response_json, true);
if (!isset($response_data['id_token'])) {
    die_with_error('เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เนเธฅเธ Access Token เนเธ”เน. ' . ($response_data['error_description'] ?? ''));
}
$id_token = $response_data['id_token'];

// 4. เธ”เธถเธ line_user_id (sub) (เน€เธซเธกเธทเธญเธเน€เธ”เธดเธก)
try {
    $id_token_parts = explode('.', $id_token);
    $payload_json = base64_decode(str_replace(['-', '_'], ['+', '/'], $id_token_parts[1]));
    $payload_data = json_decode($payload_json, true);
    if (!$payload_data || !isset($payload_data['sub'])) {
        throw new Exception("เนเธกเนเธเธ 'sub' (User ID) เนเธ ID Token");
    }
    $line_user_id = $payload_data['sub'];
} catch (Exception $e) {
    die_with_error('เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธ–เธญเธ”เธฃเธซเธฑเธช ID Token เนเธ”เน: ' . $e->getMessage()); // โ—€๏ธ (เนเธเนเนเธ)
}

// 5. เธเนเธเธซเธฒเนเธเธเธฒเธเธเนเธญเธกเธนเธฅ (เน€เธฃเธดเนเธกเธเธฒเธ 'sys_staff' เธเนเธญเธ)
try {
    // 5.1 (เน€เธเนเธเธ—เธตเน 1) เธเธเธเธตเนเน€เธเนเธ "เธเธเธฑเธเธเธฒเธ" เธ—เธตเนเธเธนเธ LINE เนเธงเนเธซเธฃเธทเธญเนเธกเน?
    $stmt_user = $pdo->prepare("SELECT * FROM sys_staff WHERE linked_line_user_id = ?");
    $stmt_user->execute([$line_user_id]);
    $staff_user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($staff_user) {
        // --- เธชเธ–เธฒเธเธเธฒเธฃเธ“เน 0: เน€เธเธญ! (เน€เธเนเธเธเธเธฑเธเธเธฒเธ/Admin) ---
        if (isset($staff_user['account_status']) && $staff_user['account_status'] == 'disabled') {
        die_with_error('เธเธฑเธเธเธตเธเธเธฑเธเธเธฒเธเธเธญเธเธเธธเธ“เธ–เธนเธเธฃเธฐเธเธฑเธเธเธฒเธฃเนเธเนเธเธฒเธเธเธฑเนเธงเธเธฃเธฒเธง เธเธฃเธธเธ“เธฒเธ•เธดเธ”เธ•เนเธญเธเธนเนเธ”เธนเนเธฅเธฃเธฐเธเธ');
    }
        // เธชเธฃเนเธฒเธ Session "เธเธเธฑเธเธเธฒเธ"
        $_SESSION['user_id'] = $staff_user['id'];
        $_SESSION['full_name'] = $staff_user['full_name'];
        $_SESSION['role'] = $staff_user['role']; 

        $log_desc = "เธเธเธฑเธเธเธฒเธ '{$staff_user['full_name']}' (ID: {$staff_user['id']}) เนเธ”เนเน€เธเนเธฒเธชเธนเนเธฃเธฐเธเธ (เธเนเธฒเธ LINE)";
        log_action($pdo, $staff_user['id'], 'login_line', $log_desc);
        
        // เธชเนเธเนเธเธซเธเนเธฒ Dashboard "Admin"
        header("Location: admin/index.php"); 
        exit;
    }
    
    // 5.2 (เน€เธเนเธเธ—เธตเน 2) เธ–เนเธฒเนเธกเนเนเธเนเธเธเธฑเธเธเธฒเธ, เน€เธเนเธ "เธเธนเนเนเธเนเธเธฒเธ" เธ—เธตเนเน€เธเธขเธฅเธเธ—เธฐเน€เธเธตเธขเธเธซเธฃเธทเธญเธขเธฑเธ?
    $stmt_student = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = ?");
    $stmt_student->execute([$line_user_id]);
    $student = $stmt_student->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        // --- เธชเธ–เธฒเธเธเธฒเธฃเธ“เนเธ—เธตเน 1: เน€เธเธญ (เน€เธเนเธเธเธนเนเนเธเนเธเธฒเธ) ---
        
        // เธชเธฃเนเธฒเธ Session "เธเธนเนเนเธเนเธเธฒเธ"
        $_SESSION['student_id'] = $student['id']; // (เธซเนเธฒเธกเน€เธเธฅเธตเนเธขเธเธเธทเนเธญเธ•เธฑเธงเนเธเธฃเธเธตเน)
        $_SESSION['student_line_id'] = $student['line_user_id'];
        $_SESSION['student_full_name'] = $student['full_name'];
        $_SESSION['user_role'] = $student['status'];

        // เธชเนเธเนเธเธซเธเนเธฒ Dashboard "เธเธนเนเนเธเนเธเธฒเธ"
        header("Location: index.php"); 
        exit;

    } else {
        // --- เธชเธ–เธฒเธเธเธฒเธฃเธ“เนเธ—เธตเน 2: เนเธกเนเน€เธเธญ (Login เธเธฃเธฑเนเธเนเธฃเธ) ---
        
        $_SESSION['line_id_to_register'] = $line_user_id;
        if(isset($payload_data['name'])) {
            $_SESSION['line_name_to_register'] = $payload_data['name'];
        }
        // เธชเนเธเนเธเธซเธเนเธฒ "เธชเธฃเนเธฒเธเนเธเธฃเนเธเธฅเน"
        header("Location: create_profile.php");
        exit;
    }

} catch (PDOException $e) {
    die_with_error('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธเนเธเธซเธฒเธเธฒเธเธเนเธญเธกเธนเธฅ: ' . $e->getMessage()); // โ—€๏ธ (เนเธเนเนเธ)
}
?>
