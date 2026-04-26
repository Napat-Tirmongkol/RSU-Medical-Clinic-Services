<?php
// admin/google_callback.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

$pdo = db();
$secrets = require __DIR__ . '/../config/secrets.php';

$clientId     = $secrets['GOOGLE_CLIENT_ID']     ?? '';
$clientSecret = $secrets['GOOGLE_CLIENT_SECRET'] ?? '';
$redirectUri  = $secrets['GOOGLE_REDIRECT_URI']  ?? '';

$code  = $_GET['code']  ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    die("Google Login Error: " . htmlspecialchars($error));
}

// 1. ตรวจสอบ state เพื่อความปลอดภัย
if (!$state || !isset($_SESSION['google_auth_state']) || !hash_equals($_SESSION['google_auth_state'], (string)$state)) {
    die("Security Error: Invalid State");
}
unset($_SESSION['google_auth_state']);

if (!$code) {
    die("Error: No Code Provided");
}

// 2. แลกเปลี่ยน Code สำหรับ Access Token
$tokenUrl = "https://oauth2.googleapis.com/token";
$postFields = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($response, true);
if (!isset($tokenData['access_token'])) {
    die("Error: Unable to get access token. " . ($tokenData['error_description'] ?? ''));
}

// 3. ใช้ Access Token ดึงข้อมูลโปรไฟล์
$profileUrl = "https://www.googleapis.com/oauth2/v2/userinfo";
$ch = curl_init($profileUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $tokenData['access_token']]);
$profileResponse = curl_exec($ch);
curl_close($ch);

$profile = json_decode($profileResponse, true);
$email = $profile['email'] ?? null;
$name = $profile['name'] ?? 'Google User';

if (!$email) {
    die("Error: Unable to retrieve Google Email");
}

// 4. ตรวจสอบสิทธิ์ในฐานข้อมูล
$stmt = $pdo->prepare("SELECT * FROM sys_admins WHERE email = :email LIMIT 1");
$stmt->execute([':email' => $email]);
$admin = $stmt->fetch();

if ($admin) {
    // พบแอดมินในระบบ -> ล็อกอินสำเร็จ
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['clinic_id']       = CLINIC_ID;
    $_SESSION['admin_id']        = $admin['id'];
    $_SESSION['admin_username']  = $admin['full_name'] ?: $name;
    $_SESSION['admin_email']     = $email;
    $_SESSION['admin_role']      = $admin['role'];

    session_regenerate_id(true);

    // บันทึกกิจกรรม: เข้าสู่ระบบ (Google)
    log_activity('Login', "Admin '{$_SESSION['admin_username']}' เข้าสู่ระบบเสร็จสมบูรณ์ผ่าน Google Login", (int)$admin['id']);

    header("Location: ../portal/index.php");
    exit;
} else {
    // ไม่พบอีเมลนี้ในรายชื่อแอดมินที่ได้รับอนุญาต
    $_SESSION['login_error'] = "ขออภัย อีเมล $email ไม่ได้รับอนุญาตให้เข้าสู่ระบบจัดการหลังบ้าน";
    header("Location: auth/login.php");
    exit;
}

