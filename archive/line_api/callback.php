<?php
// line_api/callback.php
declare(strict_types=1);
session_start();

// ดึงการตั้งค่า LINE และเชื่อมต่อ Database ของระบบหลัก (Correct path to Root)
require_once __DIR__ . '/line_config.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/error_logger.php';

// ตรวจสอบว่า User จะไปหน้าไหนหลัง Login (e-campaign หรือ e_Borrow)
$redirectTarget = $_SESSION['redirect_to'] ?? 'ecampaign';
unset($_SESSION['redirect_to']); // ล้างทันที

// รับค่าจาก LINE หลังจากล็อกอิน
$code  = $_GET['code']  ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    die("ผู้ใช้ปฏิเสธการเข้าถึง หรือเกิดข้อผิดพลาด: " . htmlspecialchars($error, ENT_QUOTES, 'UTF-8'));
}

if (!$code) {
    die("ไม่พบ Authorization Code (ไม่มีการส่งค่า Code กลับมา)");
}

// ตรวจสอบ State เพื่อป้องกัน CSRF Attack
if (!isset($_SESSION['line_login_state']) || !hash_equals($_SESSION['line_login_state'], (string)$state)) {
    die("เกิดข้อผิดด้านความปลอดภัย: State ไม่ตรงกัน (อาจเป็น CSRF Attack)");
}
unset($_SESSION['line_login_state']); // ล้าง state หลังใช้แล้ว ป้องกัน Replay

// 1. นำ Code ไปแลกเป็น Access Token
$tokenUrl = "https://api.line.me/oauth2/v2.1/token";
$data = http_build_query([
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => LINE_LOGIN_CALLBACK_URL,
    'client_id'     => LINE_LOGIN_CHANNEL_ID,
    'client_secret' => LINE_LOGIN_CHANNEL_SECRET
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log("LINE Token cURL Error: " . $curlError);
    die("ไม่สามารถเชื่อมต่อ LINE Server ได้ กรุณาลองใหม่อีกครั้ง");
}

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    die("Authentication failed. (ไม่สามารถรับ Access Token ได้)");
}

// 2. ใช้ Access Token ดึง Profile ของผู้ใช้
$ch = curl_init('https://api.line.me/v2/profile');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $tokenData['access_token']]);
$profileRes = curl_exec($ch);
curl_close($ch);

$profile = json_decode($profileRes, true);

$line_user_id = $profile['userId']      ?? null;
$displayName  = $profile['displayName'] ?? null;

if (!$line_user_id) {
    die("Authentication failed. (ไม่สามารถรับ Profile ได้)");
}

try {
    $pdo = db();

    // 3. ตรวจสอบว่าผู้ใช้ใน LINE นี้มีอยู่ในฐานข้อมูลหรือไม่
    $stmt = $pdo->prepare("SELECT id, full_name, line_user_id FROM sys_users WHERE line_user_id = :line_user_id LIMIT 1");
    $stmt->execute([':line_user_id' => $line_user_id]);
    $user = $stmt->fetch();

    if ($user) {
        // ✅ พบ User เดิม — ตั้ง Session ร่วมที่รองรับทั้ง e-campaign และ e_Borrow
        $_SESSION['line_user_id']      = $user['line_user_id'];

        // Session สำหรับ e-campaign
        $_SESSION['evax_student_id']   = (int)$user['id'];
        $_SESSION['evax_full_name']    = $user['full_name'];

        // Session สำหรับ e_Borrow (ใช้ชื่อ key เดิมที่ e_Borrow คาดหวัง)
        $_SESSION['student_id']        = (int)$user['id'];
        $_SESSION['student_full_name'] = $user['full_name'];
        $_SESSION['student_line_id']   = $user['line_user_id'];

        session_regenerate_id(true); // ป้องกัน Session Fixation

        // Redirect ตามแอปที่ผู้ใช้มาจาก
        if ($redirectTarget === 'eborrow') {
            header("Location: ../e_Borrow/index.php");
        } else {
            header("Location: ../../index.php");
        }
        exit;

    } else {
        // ❌ ไม่พบ User — ผู้ใช้ใหม่ ให้กรอกข้อมูลส่วนตัวครั้งแรก
        $_SESSION['line_user_id']      = $line_user_id;
        $_SESSION['pending_redirect']  = $redirectTarget;

        header("Location: ../../user/profile.php");
        exit;
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

