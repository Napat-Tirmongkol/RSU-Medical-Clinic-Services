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

// สร้าง callback URL แบบ dynamic จาก DOCUMENT_ROOT และ __DIR__
// __DIR__ = .../e_Borrow/includes → ขึ้นไป 1 ชั้น = .../e_Borrow
$_eb_docroot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$_eb_dir      = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
$_eb_webpath  = str_replace($_eb_docroot, '', $_eb_dir);
$_eb_protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_eb_host     = $_SERVER['HTTP_HOST'] ?? 'healthycampus.rsu.ac.th';

define('LINE_LOGIN_CALLBACK_URL', $_eb_protocol . '://' . $_eb_host . $_eb_webpath . '/callback.php');
define('STAFF_LOGIN_URL',         $_eb_protocol . '://' . $_eb_host . $_eb_webpath . '/admin/login.php');

unset($_eb_docroot, $_eb_dir, $_eb_webpath, $_eb_protocol, $_eb_host);

?>