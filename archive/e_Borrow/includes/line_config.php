<?php
// line_config.php
// เก็บค่าลับสำหรับ LINE Login

// ==========================================
// Load Secrets from config/secrets.php
// ==========================================
$secretsPath = __DIR__ . '/../../config/secrets.php';
$secrets = file_exists($secretsPath) ? require $secretsPath : [];

define('LINE_LOGIN_CHANNEL_ID', $secrets['EBORROW_LINE_LOGIN_ID'] ?? 'YOUR_EBORROW_ID');
define('LINE_LOGIN_CHANNEL_SECRET', $secrets['EBORROW_LINE_LOGIN_SECRET'] ?? 'YOUR_EBORROW_SECRET');
define('LINE_MESSAGING_API_TOKEN', $secrets['EBORROW_LINE_MESSAGE_TOKEN'] ?? 'YOUR_EBORROW_TOKEN');

// 1. (แก้ไข) กำหนด Base URL ให้ถูกต้อง (ตามโฟลเดอร์ของคุณ)
$base_url = "https://healthycampus.rsu.ac.th/e_Borrow_test";

// 2. (แก้ไข) สร้าง Path ที่ถูกต้องโดยใช้ Base URL
define('LINE_LOGIN_CALLBACK_URL', $base_url . '/callback.php');
define('STAFF_LOGIN_URL', $base_url . '/admin/login.php');

?>