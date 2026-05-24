<?php
// admin/ajax/ajax_get_walkin_url.php — Return Walk-in registration URL
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$campaignId = (int)($_GET['campaign'] ?? 0);
if ($campaignId <= 0) {
    echo json_encode(['url' => '']);
    exit;
}

$token  = hash_hmac('sha256', "qr:walkin:{$campaignId}", QR_SLOT_SECRET);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$url    = $scheme . '://' . $host . $base . '/user/walkin.php?cid=' . $campaignId . '&t=' . $token;

echo json_encode(['url' => $url]);
