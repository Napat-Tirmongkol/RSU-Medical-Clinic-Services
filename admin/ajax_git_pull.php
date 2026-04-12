<?php
// admin/ajax_git_pull.php
// Trigger Plesk Git webhook ผ่าน localhost (same server)
// เฉพาะ Superadmin เท่านั้น

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

// 3. เรียก Plesk webhook ผ่าน localhost (bypass external network/VPN)
//    Plesk รันบนเครื่องเดียวกัน → localhost:8443 ใช้งานได้เสมอ
$webhookUuid = 'dd095230-b1b5-111b-594e-1ce4dd1ec34f';
$webhookUrl  = 'https://127.0.0.1:8443/modules/git/public/web-hook.php?uuid=' . $webhookUuid;

$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'RSU-HealthHub/1.0',
]);

$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'เชื่อมต่อ Plesk ไม่ได้: ' . $curlErr,
        'detail'  => 'ลอง localhost:8443 แต่ล้มเหลว',
    ]);
    exit;
}

if ($httpCode >= 200 && $httpCode < 300) {
    echo json_encode([
        'status'  => 'success',
        'message' => 'Git Pull สำเร็จ',
        'detail'  => 'Plesk webhook ตอบกลับ HTTP ' . $httpCode,
    ]);
} else {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Plesk webhook ตอบกลับ HTTP ' . $httpCode,
        'detail'  => $result,
    ]);
}
