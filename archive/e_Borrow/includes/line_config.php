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

// 1. (���) ��˹� Base URL ���١��ͧ (���������ͧ�س)
$base_url = "https://healthycampus.rsu.ac.th/e_Borrow_test";

// 2. (���) ���ҧ Path ���١��ͧ���� Base URL
define('LINE_LOGIN_CALLBACK_URL', $base_url . '/callback.php');
define('STAFF_LOGIN_URL', $base_url . '/admin/login.php');

?>