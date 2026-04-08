<?php
// admin/ajax_git_pull.php
// Proxy endpoint สำหรับ trigger Plesk Git webhook
// เฉพาะ Superadmin เท่านั้น — URL webhook ไม่เปิดเผยใน frontend

require_once __DIR__ . '/../portal/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

// 1. Superadmin เท่านั้น
if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์ดำเนินการ']);
    exit;
}

// 2. POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// 3. Plesk Git Webhook URL (เก็บ server-side เท่านั้น)
$webhookUrl = 'https://magical-wu.49-231-198-219.plesk.page:8443/modules/git/public/web-hook.php?uuid=dd095230-b1b5-111b-594e-1ce4dd1ec34f';

// 4. Call webhook via cURL
$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false, // Plesk ใช้ self-signed cert
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'RSU-HealthHub/1.0',
]);

$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อ Plesk ได้: ' . $curlErr]);
    exit;
}

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode(['status' => 'success', 'message' => 'Git Pull สำเร็จ ✓ (HTTP ' . $httpCode . ')']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Webhook ตอบกลับ HTTP ' . $httpCode]);
}
