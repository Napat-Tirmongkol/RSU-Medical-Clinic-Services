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
// Load Secrets from config/secrets.php
// ============================================================
$secretsPath = __DIR__ . '/../config/secrets.php';
$secrets = file_exists($secretsPath) ? require $secretsPath : [];

// 1. LINE Login Channel
define('LINE_LOGIN_CHANNEL_ID', $secrets['LINE_LOGIN_CHANNEL_ID'] ?? 'YOUR_LOGIN_CHANNEL_ID');
define('LINE_LOGIN_CHANNEL_SECRET', $secrets['LINE_LOGIN_CHANNEL_SECRET'] ?? 'YOUR_LOGIN_CHANNEL_SECRET');

// ⚠️ ต้องตรงกับ Callback URL ที่ลงทะเบียนใน LINE Developers Console เป๊ะๆ
define('LINE_LOGIN_CALLBACK_URL', 'https://healthycampus.rsu.ac.th/e-campaignv2/line_api/callback.php');

// 2. LINE Messaging API Channel
define('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? 'YOUR_CHANNEL_ACCESS_TOKEN');
define('LINE_MESSAGING_CHANNEL_SECRET', $secrets['LINE_MESSAGING_CHANNEL_SECRET'] ?? 'YOUR_MESSAGING_CHANNEL_SECRET');

// 3. LIFF (LINE Front-end Framework)
define('LINE_LIFF_ID', $secrets['LINE_LIFF_ID'] ?? 'YOUR_LIFF_ID');
