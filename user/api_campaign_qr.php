<?php
// user/api_campaign_qr.php — Generate QR PNG for campaign-level check-in URL
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../admin/includes/auth.php';

$campaignId = (int)($_GET['campaign'] ?? 0);
if ($campaignId <= 0) {
    http_response_code(400);
    exit;
}

$token  = hash_hmac('sha256', "qr:campaign:{$campaignId}", QR_SLOT_SECRET);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$url    = $scheme . '://' . $host . $base . '/user/checkin_campaign.php?campaign=' . $campaignId . '&token=' . $token;

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
