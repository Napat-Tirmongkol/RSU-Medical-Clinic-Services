<?php
// line_api/migrate_login.php
// เริ่มต้น LINE Login กับ provider ใหม่ (provider เดียวกับ Messaging API)
// ใช้สำหรับ migrate UID ของ user ที่ login ผ่าน provider เก่ามาแล้ว
declare(strict_types=1);
session_start();
require_once __DIR__ . '/line_config.php';

// ── ตรวจ pre-condition ──────────────────────────────────────────
// 1. ต้องเปิด migrate flow
if (!defined('LINE_MIGRATE_ENABLED') || !LINE_MIGRATE_ENABLED) {
    // ถ้ายังไม่เปิด ก็ข้ามไปยัง destination เลย
    $dest = $_SESSION['migrate_final_dest'] ?? (LINE_APP_BASE_PATH . '/user/hub.php');
    unset($_SESSION['migrate_old_uid'], $_SESSION['migrate_final_dest']);
    header("Location: {$dest}");
    exit;
}

// 2. ต้องผ่าน old callback มาก่อน (มี migrate_old_uid ใน session)
if (empty($_SESSION['migrate_old_uid'])) {
    // ผู้ใช้เข้ามาตรงๆ โดยไม่ผ่าน flow ปกติ → ส่งกลับไป login ใหม่
    header('Location: ' . LINE_APP_BASE_PATH . '/line_api/line_login.php');
    exit;
}

// ── สร้าง state สำหรับป้องกัน CSRF ──────────────────────────────
$state = bin2hex(random_bytes(16));
$_SESSION['line_migrate_state'] = $state;

$authUrl = 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => LINE_LOGIN_CHANNEL_ID_NEW,
    'redirect_uri'  => LINE_LOGIN_CALLBACK_URL_NEW,
    'state'         => $state,
    'scope'         => 'profile openid',
]);

header("Location: {$authUrl}");
exit;
