<?php
// line_callback.php (เวอร์ชันอัปเกรต: เช็ค 2 ตาราง)

session_start();
require_once(__DIR__ . '/includes/line_config.php');
require_once(__DIR__ . '/../../config/db_connect.php');
require_once(__DIR__ . '/includes/log_function.php');

// --- (ฟังก์ชัน die_with_error ... เหมือนเดิม) ---
function die_with_error($message) {
    echo "
        <head><title>เกิดข้อผิดพลาด</title><link rel='stylesheet' href='CSS/style.css'></head>
        <body style='display: flex; justify-content: center; align-items: center; height: 100vh; background: #f4f4f4;'>
            <div style='background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center;'>
                <h1 style='color: #dc3545;'>เกิดข้อผิดพลาด</h1>
                <p>" . htmlspecialchars($message) . "</p>
                <a href='login.php'>กลับไปหน้า Login</a>
            </div>
        </body>
    ";
    exit;
}
// ---------------------------------

// 1. ตรวจสอบ 'state' และ 'code' (เหมือนเดิม)
if (!isset($_GET['state']) || !isset($_SESSION['line_login_state']) || $_GET['state'] !== $_SESSION['line_login_state']) {
    die_with_error('State ไม่ถูกต้อง (Invalid State). กรุณาลองใหม่อีกครั้ง');
}
unset($_SESSION['line_login_state']);
if (!isset($_GET['code'])) {
    die_with_error('ไม่ได้รับ Authorization Code จาก LINE');
}
$code = $_GET['code'];

// 3. แลก Code เป็น Access Token (เหมือนเดิม)
$token_url = "https://api.line.me/oauth2/v2.1/token";
$post_data = [ /* ... (ข้อมูล post_data ... เหมือนเดิม) ... */ 
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
    die_with_error('ไม่สามารถแลก Access Token ได้. ' . ($response_data['error_description'] ?? ''));
}
$id_token = $response_data['id_token'];

// 4. ดึง line_user_id (sub) (เหมือนเดิม)
try {
    $id_token_parts = explode('.', $id_token);
    $payload_json = base64_decode(str_replace(['-', '_'], ['+', '/'], $id_token_parts[1]));
    $payload_data = json_decode($payload_json, true);
    if (!$payload_data || !isset($payload_data['sub'])) {
        throw new Exception("ไม่พบ 'sub' (User ID) ใน ID Token");
    }
    $line_user_id = $payload_data['sub'];
} catch (Exception $e) {
    die_with_error('ไม่สามารถถอดรหัส ID Token ได้: ' . $e->getMessage()); // ◀️ (แก้ไข)
}

// 5. ค้นหาในฐานข้อมูล (เริ่มจาก 'sys_staff' ก่อน)
try {
    // 5.1 (เช็คที่ 1) คนนี้เป็น "พนักงาน" ที่ผูก LINE ไว้หรือไม่?
    $stmt_user = $pdo->prepare("SELECT * FROM sys_staff WHERE linked_line_user_id = ?");
    $stmt_user->execute([$line_user_id]);
    $staff_user = $stmt_user->fetch(PDO::FETCH_ASSOC);

    if ($staff_user) {
        // --- สถานการณ์ 0: เจอ! (เป็นพนักงาน/Admin) ---
        if (isset($staff_user['account_status']) && $staff_user['account_status'] == 'disabled') {
        die_with_error('บัญชีพนักงานของคุณถูกระงับการใช้งานชั่วคราว กรุณาติดต่อผู้ดูแลระบบ');
    }
        // สร้าง Session "พนักงาน"
        $_SESSION['user_id'] = $staff_user['id'];
        $_SESSION['full_name'] = $staff_user['full_name'];
        $_SESSION['role'] = $staff_user['role']; 

        $log_desc = "พนักงาน '{$staff_user['full_name']}' (ID: {$staff_user['id']}) ได้เข้าสู่ระบบ (ผ่าน LINE)";
        log_action($pdo, $staff_user['id'], 'login_line', $log_desc);
        
        // ส่งไปหน้า Dashboard "Admin"
        header("Location: admin/index.php"); 
        exit;
    }
    
    // 5.2 (เช็คที่ 2) ถ้าไม่ใช่พนักงาน, เป็น "ผู้ใช้งาน" ที่เคยลงทะเบียนหรือยัง?
    $stmt_student = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = ?");
    $stmt_student->execute([$line_user_id]);
    $student = $stmt_student->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        // --- สถานการณ์ที่ 1: เจอ (เป็นผู้ใช้งาน) ---
        
        // สร้าง Session "ผู้ใช้งาน"
        $_SESSION['student_id'] = $student['id']; // (ห้ามเปลี่ยนชื่อตัวแปรนี้)
        $_SESSION['student_line_id'] = $student['line_user_id'];
        $_SESSION['student_full_name'] = $student['full_name'];
        $_SESSION['user_role'] = $student['status'];

        // ส่งไปหน้า Dashboard "ผู้ใช้งาน"
        header("Location: index.php"); 
        exit;

    } else {
        // --- สถานการณ์ที่ 2: ไม่เจอ (Login ครั้งแรก) ---
        
        $_SESSION['line_id_to_register'] = $line_user_id;
        if(isset($payload_data['name'])) {
            $_SESSION['line_name_to_register'] = $payload_data['name'];
        }
        // ส่งไปหน้า "สร้างโปรไฟล์"
        header("Location: create_profile.php");
        exit;
    }

} catch (PDOException $e) {
    die_with_error('เกิดข้อผิดพลาดในการค้นหาฐานข้อมูล: ' . $e->getMessage()); // ◀️ (แก้ไข)
}
?>
