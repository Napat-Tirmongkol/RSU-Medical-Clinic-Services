<?php
// admin/google_login.php
session_start();
require_once __DIR__ . '/../config.php';

// ดึงการตั้งค่าจากความลับ (secrets)
$secrets = require __DIR__ . '/../config/secrets.php';

$clientId = $secrets['GOOGLE_CLIENT_ID'] ?? '';
$redirectUri = $secrets['GOOGLE_REDIRECT_URI'] ?? '';

if (empty($clientId) || empty($redirectUri)) {
    die("Error: Google Client ID or Redirect URI is not configured in secrets.php");
}

// สร้าง State เพื่อป้องกัน CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['google_auth_state'] = $state;

$params = [
    'response_type' => 'code',
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'scope'         => 'email profile',
    'state'         => $state,
    'prompt'        => 'select_account'
];

$authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params);

header("Location: " . $authUrl);
exit;
