<?php
// line_api/line_login.php
// จุดเริ่มต้น LINE Login สำหรับ e-campaign
declare(strict_types=1);
session_start();
require_once __DIR__ . '/line_config.php';

// บอก callback.php ว่าหลัง Login แล้วให้ไปที่ e-campaign
$_SESSION['redirect_to'] = 'ecampaign';

// สร้าง State สำหรับป้องกัน CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['line_login_state'] = $state;

$authUrl = "https://access.line.me/oauth2/v2.1/authorize?" . http_build_query([
    'response_type' => 'code',
    'client_id'     => LINE_LOGIN_CHANNEL_ID,
    'redirect_uri'  => LINE_LOGIN_CALLBACK_URL,
    'state'         => $state,
    'scope'         => 'profile openid',
]);

header("Location: {$authUrl}");
exit;
