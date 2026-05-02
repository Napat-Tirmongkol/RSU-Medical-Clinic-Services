<?php
// user/api_slot_qr.php — Generate QR PNG image for a slot check-in URL
// เรียกจาก admin เท่านั้น (?slot=ID)
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/includes/auth.php';

$slotId = (int)($_GET['slot'] ?? 0);
if ($slotId <= 0) {
    http_response_code(400);
    exit;
}

// สร้าง token + URL
$token = hash_hmac('sha256', "qr:slot:{$slotId}", QR_SLOT_SECRET);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$url    = $scheme . '://' . $host . $base . '/user/checkin.php?slot=' . $slotId . '&token=' . $token;

// ใช้ phpqrcode library
$libPath = __DIR__ . '/../assets/phpqrcode/phpqrcode.php';
if (!file_exists($libPath)) {
    http_response_code(500);
    echo 'QR library not found';
    exit;
}
require_once $libPath;

if (!defined('QR_CACHEABLE')) define('QR_CACHEABLE', false);

header('Content-Type: image/png');
QRcode::png($url, false, QR_ECLEVEL_M, 8, 2);
exit;
