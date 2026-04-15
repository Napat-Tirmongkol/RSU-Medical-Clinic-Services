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

$isSuccess = !$curlErr && ($httpCode >= 200 && $httpCode < 300);
$logStatus  = $isSuccess ? 'success' : 'error';
$logMessage = $curlErr
    ? 'เชื่อมต่อ Plesk ไม่ได้: ' . $curlErr
    : ($isSuccess ? 'Git Pull สำเร็จ' : 'Plesk webhook ตอบกลับ HTTP ' . $httpCode);
$logDetail  = $curlErr ? 'curl error' : ('HTTP ' . $httpCode . ($result ? ' — ' . mb_substr($result, 0, 500) : ''));

// บันทึกลง DB
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_git_pull_log (
        id          int          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        triggered_by varchar(100) NOT NULL DEFAULT '',
        status      enum('success','error') NOT NULL,
        message     varchar(500) DEFAULT NULL,
        detail      text         DEFAULT NULL,
        created_at  timestamp    NULL DEFAULT current_timestamp()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->prepare("INSERT INTO sys_git_pull_log (triggered_by, status, message, detail) VALUES (:by, :st, :msg, :det)")
        ->execute([
            ':by'  => $_SESSION['admin_username'] ?? 'unknown',
            ':st'  => $logStatus,
            ':msg' => $logMessage,
            ':det' => $logDetail,
        ]);
} catch (Exception $e) {
    // ล้มเหลว log ก็ไม่ block response
}

if ($curlErr) {
    echo json_encode(['status' => 'error', 'message' => $logMessage, 'detail' => 'ลอง localhost:8443 แต่ล้มเหลว']);
    exit;
}

if ($isSuccess) {
    echo json_encode(['status' => 'success', 'message' => $logMessage, 'detail' => 'Plesk webhook ตอบกลับ HTTP ' . $httpCode]);
} else {
    echo json_encode(['status' => 'error', 'message' => $logMessage, 'detail' => $result]);
}
