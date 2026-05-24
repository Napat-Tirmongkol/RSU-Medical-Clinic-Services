<?php
// user/api_walkin_qr.php — Generate QR PNG for Walk-in registration URL
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$campaignId = (int)($_GET['campaign'] ?? 0);
if ($campaignId <= 0) {
    http_response_code(400);
    exit;
}

$token  = hash_hmac('sha256', "qr:walkin:{$campaignId}", QR_SLOT_SECRET);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$url    = $scheme . '://' . $host . $base . '/user/walkin.php?cid=' . $campaignId . '&t=' . $token;

$libPath = __DIR__ . '/../assets/phpqrcode/phpqrcode.php';
if (!file_exists($libPath)) {
    http_response_code(500);
    echo 'QR library not found';
    exit;
}
require_once $libPath;

if (!defined('QR_CACHEABLE')) define('QR_CACHEABLE', false);

// Larger size param for poster quality (12 px per module, 4 module margin)
$size   = max(4, min(16, (int)($_GET['size'] ?? 10)));
$margin = max(1, min(6,  (int)($_GET['margin'] ?? 3)));

header('Content-Type: image/png');
QRcode::png($url, false, QR_ECLEVEL_M, $size, $margin);
exit;
