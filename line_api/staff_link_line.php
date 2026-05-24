<?php
/**
 * line_api/staff_link_line.php
 * จุดเริ่มต้น LINE Login สำหรับ "เชื่อมบัญชี LINE กับ sys_staff"
 *
 * Flow:
 *   1. Staff ล็อกอินใน portal แล้ว → คลิกปุ่ม "เชื่อม LINE" ในหน้า profile
 *   2. → redirect มาที่นี่
 *   3. → สร้าง state + ตั้ง $_SESSION marker "staff_link_mode=1"
 *   4. → redirect ไป LINE OAuth (scope: profile openid)
 *   5. ผู้ใช้ login LINE → กลับมาที่ staff_link_callback.php
 *
 * Security:
 *   - ต้องมี admin session ก่อนเท่านั้น (staff login portal แล้ว)
 *   - state CSRF token
 *   - ใช้ LINE Login channel ที่ตั้งค่าไว้แล้วใน config/secrets.php
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/line_config.php';

// ── Auth check: ต้องมี admin/staff session ──────────────────────────────
$staffId = (int)($_SESSION['admin_id'] ?? 0);
if ($staffId <= 0) {
    header('Location: ../admin/auth/staff_login.php?next=' . urlencode('/portal/index.php?section=profile'));
    exit;
}

// ── Channel config check ────────────────────────────────────────────────
if (LINE_LOGIN_CHANNEL_ID === '' || LINE_LOGIN_CHANNEL_SECRET === '') {
    die('LINE Login ยังไม่ได้ตั้งค่า — กรุณาตั้งค่า LINE_LOGIN_CHANNEL_ID / SECRET ใน config/secrets.php');
}

// ── Set state + flow marker ─────────────────────────────────────────────
$state = bin2hex(random_bytes(16));
$_SESSION['line_login_state']       = $state;
$_SESSION['line_login_flow']        = 'staff_link';
$_SESSION['line_login_staff_id']    = $staffId;
$_SESSION['line_login_started_at']  = time();

// ── Redirect to LINE OAuth ──────────────────────────────────────────────
$authUrl = "https://access.line.me/oauth2/v2.1/authorize?" . http_build_query([
    'response_type' => 'code',
    'client_id'     => LINE_LOGIN_CHANNEL_ID,
    'redirect_uri'  => LINE_LOGIN_CALLBACK_URL,  // ใช้ callback เดียวกับ user flow
    'state'         => $state,
    'scope'         => 'profile openid',
    'prompt'        => 'consent',  // บังคับให้แสดงหน้าเลือกบัญชี (กัน auto-pick บัญชีล่าสุด)
]);

header("Location: {$authUrl}");
exit;
