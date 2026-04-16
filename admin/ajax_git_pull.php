<?php
// admin/ajax_git_pull.php
// Trigger Plesk Git webhook ผ่าน localhost (same server)
// เฉพาะ Superadmin เท่านั้น

require_once __DIR__ . '/../config.php';
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

// 3. เตรียมข้อมูลก่อนเริ่ม Pull
$oldHash = '';
try {
    $oldHash = trim(shell_exec('git rev-parse HEAD 2>&1') ?: '');
} catch (Exception $e) {}

// 4. เรียก Plesk webhook ผ่าน localhost
$webhookUuid = 'bd4d08cc-a594-54de-3c44-37ab0ee3b638';
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

// 5. ดึงข้อมูลหลัง Pull (ลองวนลูปสั้นๆ เผื่อ Plesk ใช้เวลาอัปเดตไฟล์สักครู่)
$newHash = $oldHash;
$changes = '';
if ($isSuccess) {
    for ($i = 0; $i < 3; $i++) {
        clearstatcache();
        $current = trim(shell_exec('git rev-parse HEAD 2>&1') ?: '');
        if ($current && $current !== $oldHash) {
            $newHash = $current;
            break;
        }
        usleep(500000); // รอ 0.5s
    }

    if ($oldHash && $newHash && $oldHash !== $newHash) {
        // ดึงรายการ commit ที่อัปเดตมา
        $changes = trim(shell_exec("git log --oneline --no-merges {$oldHash}..{$newHash} 2>&1") ?: '');
    }
}

$logStatus  = $isSuccess ? 'success' : 'error';
$logMessage = $curlErr
    ? 'เชื่อมต่อ Plesk ไม่ได้: ' . $curlErr
    : ($isSuccess ? 'Git Pull สำเร็จ' : 'Plesk webhook ตอบกลับ HTTP ' . $httpCode);

// ปรับปรุง logDetail ให้บอกรายละเอียดที่อัปเดต
if ($isSuccess) {
    if ($changes) {
        $logDetail = "Updated: " . mb_substr($oldHash, 0, 7) . " → " . mb_substr($newHash, 0, 7) . "\n" . $changes;
    } else {
        $logDetail = ($oldHash === $newHash) ? "Already up to date (HEAD: " . mb_substr($oldHash, 0, 7) . ")" : "Plesk webhook success (HTTP $httpCode)";
    }
} else {
    $logDetail = $curlErr ? 'curl error' : ('HTTP ' . $httpCode . ($result ? ' — ' . mb_substr($result, 0, 500) : ''));
}

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
