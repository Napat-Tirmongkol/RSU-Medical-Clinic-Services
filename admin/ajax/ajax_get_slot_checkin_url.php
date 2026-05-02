<?php
// admin/ajax/ajax_get_slot_checkin_url.php — Return check-in URL for a slot
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$slotId = (int)($_GET['slot'] ?? 0);
if ($slotId <= 0) {
    echo json_encode(['url' => '']);
    exit;
}

$token  = hash_hmac('sha256', "qr:slot:{$slotId}", QR_SLOT_SECRET);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$url    = $scheme . '://' . $host . $base . '/user/checkin.php?slot=' . $slotId . '&token=' . $token;

echo json_encode(['url' => $url]);
