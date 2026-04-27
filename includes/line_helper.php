<?php
/**
 * includes/line_helper.php
 * ฟังก์ชันช่วยจัดการเกี่ยวกับ LINE Messaging API
 */
declare(strict_types=1);

/**
 * ตรวจสอบ Signature จาก LINE Webhook
 */
function verify_line_signature(string $payload, string $signature, string $channelSecret): bool {
    if (empty($signature) || empty($payload)) return false;
    $hash = hash_hmac('sha256', $payload, $channelSecret, true);
    return hash_equals(base64_encode($hash), $signature);
}

/**
 * ส่งข้อความ Reply (ใช้ Reply Token)
 */
function send_line_reply(string $replyToken, array $messages, string $accessToken): bool {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $data = [
        'replyToken' => $replyToken,
        'messages'   => $messages
    ];
    return _send_line_curl($url, $data, $accessToken);
}

/**
 * ส่งข้อความ Push (ใช้ User ID)
 */
function send_line_push(string $to, array $messages, string $accessToken): bool {
    $url = 'https://api.line.me/v2/bot/message/push';
    $data = [
        'to'       => $to,
        'messages' => $messages
    ];
    return _send_line_curl($url, $data, $accessToken);
}

/**
 * ฟังก์ชันกลางสำหรับยิง CURL ไปยัง LINE API
 */
function _send_line_curl(string $url, array $data, string $accessToken): bool {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_TIMEOUT        => 10
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        $body = $response ?: 'No response';
        $hint = '';
        if ($httpCode === 400) {
            $hint = ' (ผู้รับอาจยังไม่ได้เพิ่ม LINE OA เป็นเพื่อน หรือบล็อก OA แล้ว)';
        } elseif ($httpCode === 401) {
            $hint = ' (Channel Access Token ไม่ถูกต้องหรือหมดอายุ)';
        } elseif ($httpCode === 403) {
            $hint = ' (ไม่มีสิทธิ์ส่งข้อความ — ตรวจสอบสิทธิ์ของ Messaging API Channel)';
        }
        $errorMsg = "LINE API Error ($httpCode)$hint: " . $body;
        error_log($errorMsg);
        if (function_exists('log_error_to_db')) {
            log_error_to_db($errorMsg, 'error', 'line_helper.php', json_encode($data));
        }
        $GLOBALS['LAST_LINE_ERROR'] = $body;
    }
    
    return $httpCode === 200;
}

/**
 * ดึงข้อความตอบกลับล่าสุดจาก LINE API กรณีเกิดข้อผิดพลาด
 */
function get_last_line_error(): string {
    return (string)($GLOBALS['LAST_LINE_ERROR'] ?? '');
}

/**
 * ค้น user จาก LINE UID ระหว่างช่วง migrate provider
 * เช็ค line_user_id_new ก่อน → ถ้าไม่เจอ fallback ไป line_user_id เดิม
 *
 * @param PDO    $pdo
 * @param string $uid     UID ที่ได้จาก LINE (อาจเป็น new หรือ old)
 * @param string $columns columns ที่ต้องการ (default: *)
 * @return array|null     แถว user หรือ null ถ้าไม่เจอ
 */
function find_user_by_line_uid(PDO $pdo, string $uid, string $columns = '*'): ?array {
    if ($uid === '') return null;

    // 1. ลอง new UID ก่อน (เผื่อ user migrate แล้ว)
    try {
        $stmt = $pdo->prepare("SELECT {$columns} FROM sys_users WHERE line_user_id_new = :uid LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (PDOException $e) {
        // column ยังไม่มี — ข้ามไป fallback
    }

    // 2. fallback ไป old UID
    $stmt = $pdo->prepare("SELECT {$columns} FROM sys_users WHERE line_user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
