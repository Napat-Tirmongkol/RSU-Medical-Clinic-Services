<?php
// line_api/line_config.php
declare(strict_types = 1)
;

/**
 * ==========================================
 *  LINE Developers Console — Credentials
 *  https://developers.line.biz/console/
 * ==========================================
 *
 * [วิธีหาค่าต่างๆ]
 *  LINE_LOGIN_CHANNEL_ID     -> LINE Login Channel > Basic settings > Channel ID
 *  LINE_LOGIN_CHANNEL_SECRET -> LINE Login Channel > Basic settings > Channel secret
 *  LINE_LOGIN_CALLBACK_URL   -> ต้องตรงกับ Callback URL ใน LINE Login Channel > LINE Login tab
 *
 *  LINE_MESSAGING_CHANNEL_ACCESS_TOKEN -> Messaging API Channel > Messaging API tab > Channel access token
 *  LINE_MESSAGING_CHANNEL_SECRET       -> Messaging API Channel > Basic settings > Channel secret
 *
 *  LINE_LIFF_ID -> LINE Login Channel > LIFF tab > LIFF ID (format: XXXXXXXXXX-XXXXXXXX)
 */

// ============================================================
// Load Secrets from config/secrets.php (Correct path to Root)
// ============================================================
$secretsPath = __DIR__ . '/../../config/secrets.php';
$secrets = file_exists($secretsPath) ? require $secretsPath : [];

// 1. LINE Login Channel (Using the main channel ID or specific e-borrow keys)
// Change keys to 'LINE_LOGIN_CHANNEL_ID' to use the new unified channel
define('LINE_LOGIN_CHANNEL_ID', $secrets['LINE_LOGIN_CHANNEL_ID'] ?? '');
define('LINE_LOGIN_CHANNEL_SECRET', $secrets['LINE_LOGIN_CHANNEL_SECRET'] ?? '');

// --- Dynamic Callback URL Detection ---
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'healthycampus.rsu.ac.th';
$base_path = (strpos($host, 'dev.healthycampus') !== false) ? '' : '/e-campaignv2';

define('LINE_LOGIN_CALLBACK_URL', $protocol . $host . $base_path . '/archive/line_api/callback.php');

// 2. LINE Messaging API Channel
define('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', $secrets['EBORROW_LINE_MESSAGE_TOKEN'] ?? $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '');
define('LINE_MESSAGING_CHANNEL_SECRET', $secrets['LINE_MESSAGING_CHANNEL_SECRET'] ?? '');

// 3. LIFF (LINE Front-end Framework)
define('LINE_LIFF_ID', $secrets['LINE_LIFF_ID'] ?? '');
