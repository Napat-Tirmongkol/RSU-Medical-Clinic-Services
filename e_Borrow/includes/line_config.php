<?php
// line_config.php
// �纤���Ѻ����Ѻ LINE Login

// ==========================================
// Load Secrets from config/secrets.php
// ==========================================
$secretsPath = __DIR__ . '/../../config/secrets.php';
$secrets = file_exists($secretsPath) ? require $secretsPath : [];

define('LINE_LOGIN_CHANNEL_ID', $secrets['EBORROW_LINE_LOGIN_ID'] ?? 'YOUR_EBORROW_ID');
define('LINE_LOGIN_CHANNEL_SECRET', $secrets['EBORROW_LINE_LOGIN_SECRET'] ?? 'YOUR_EBORROW_SECRET');
define('LINE_MESSAGING_API_TOKEN', $secrets['EBORROW_LINE_MESSAGE_TOKEN'] ?? 'YOUR_EBORROW_TOKEN');

// สร้าง callback URL จาก SCRIPT_NAME (web path จริง, ใช้งานได้ทั้ง Apache/IIS)
// SCRIPT_NAME ตัวอย่าง: /e-campaignv2/e_Borrow/login.php
//                        /e-campaignv2/e_Borrow/admin/login.php
// หา /e_Borrow แล้ว cut เอาเฉพาะ prefix + /e_Borrow
$_eb_script   = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$_eb_pos      = strrpos($_eb_script, '/e_Borrow');
$_eb_webpath  = $_eb_pos !== false ? substr($_eb_script, 0, $_eb_pos) . '/e_Borrow' : '/e_Borrow';
$_eb_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_eb_host     = $_SERVER['HTTP_HOST'] ?? 'healthycampus.rsu.ac.th';

define('LINE_LOGIN_CALLBACK_URL', $_eb_protocol . '://' . $_eb_host . $_eb_webpath . '/callback.php');
define('STAFF_LOGIN_URL',         $_eb_protocol . '://' . $_eb_host . $_eb_webpath . '/admin/login.php');

unset($_eb_script, $_eb_pos, $_eb_webpath, $_eb_protocol, $_eb_host);

?>