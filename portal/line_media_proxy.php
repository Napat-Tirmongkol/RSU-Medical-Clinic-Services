<?php
/**
 * portal/line_media_proxy.php
 *
 * Auth-gated proxy ที่ดึง content จาก LINE Messaging API (image/file/audio/video)
 * โดยใช้ message_id แล้ว stream กลับให้ browser พร้อม Content-Type ที่ถูกต้อง.
 *
 * - ต้อง login admin หรือมี access_ai
 * - line_message_id ต้องอยู่ใน sys_line_chat_messages (direction='inbound') — กันส่ง messageId มั่ว
 * - Cache ลง uploads/line_media/{YYYY}/{MM}/{hash}.{ext} เพื่อไม่ยิง LINE ทุกครั้ง
 *
 * Usage: ?msg_id=XXXXXXXXX
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/line_chat_helper.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$canAccess = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_ai']);
if (!$canAccess) { http_response_code(403); exit('Access Denied'); }

$msgId = trim((string)($_GET['msg_id'] ?? ''));
if ($msgId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $msgId)) {
    http_response_code(400); exit('Bad request');
}

$pdo = db();
// Verify the message exists in our DB (anti-SSRF — must be an inbound message we logged)
$stmt = $pdo->prepare("SELECT message_type FROM sys_line_chat_messages WHERE line_message_id = ? AND direction='inbound' LIMIT 1");
$stmt->execute([$msgId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Not found'); }

$msgType = (string)$row['message_type'];
if (!in_array($msgType, ['image','file','audio','video'], true)) {
    http_response_code(400); exit('Not a media message');
}

// Cache path — partitioned by year/month
$cacheRoot = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$mediaDir = $cacheRoot . '/line_media/' . date('Y') . '/' . date('m');
if (!is_dir($mediaDir)) { @mkdir($mediaDir, 0775, true); }

// Drop deny-all .htaccess at the line_media root once
$htRoot = $cacheRoot . '/line_media/.htaccess';
if (!is_file($htRoot)) {
    @file_put_contents($htRoot, "Order deny,allow\nDeny from all\n");
}

// Whitelist of allowed content types — also used for cache filename extension
$allowedTypes = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
    'image/webp' => 'webp', 'application/pdf' => 'pdf',
    'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
    'video/mp4' => 'mp4',
];
$allowedExts = array_unique(array_values($allowedTypes));
$extToMime = array_flip($allowedTypes);

$cacheFileBase = $mediaDir . '/' . hash('sha256', $msgId);
// Lookup cached file by whitelisted extension only — never use glob() with wildcard
foreach ($allowedExts as $ext) {
    $candidate = $cacheFileBase . '.' . $ext;
    if (is_file($candidate)) {
        line_media_stream_file($candidate, $extToMime[$ext]);
        exit;
    }
}

// Fetch from LINE Content API
$token = line_chat_load_access_token();
if ($token === '') { http_response_code(500); exit('LINE token not set'); }

$url = "https://api-data.line.me/v2/bot/message/" . urlencode($msgId) . "/content";
$ch = curl_init($url);
$headers = [];
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$headers) {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    },
]);
$bin = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !$bin) {
    error_log("[line_media_proxy] HTTP $code for msg $msgId");
    http_response_code(502); exit('LINE content fetch failed');
}

// Strict whitelist: only serve known-safe content types — never echo LINE's raw header
$rawType = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
if (!isset($allowedTypes[$rawType])) {
    error_log("[line_media_proxy] rejected content-type '$rawType' for msg $msgId");
    http_response_code(415); exit('Unsupported content type');
}
$safeMime = $rawType;          // we control this — it's in our whitelist
$ext = $allowedTypes[$rawType]; // mapped extension

$cachePath = $cacheFileBase . '.' . $ext;
file_put_contents($cachePath, $bin);

line_media_stream_file($cachePath, $safeMime);

function line_media_stream_file(string $path, string $forceType = ''): void
{
    $real = realpath($path);
    $root = realpath(__DIR__ . '/../uploads/line_media') ?: '';
    if ($real === false || $root === '' || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        http_response_code(403); exit('Path outside allowed root');
    }
    // Only accept whitelisted MIME types from caller — never sniff
    static $okMimes = [
        'image/jpeg' => 1, 'image/png' => 1, 'image/gif' => 1, 'image/webp' => 1,
        'application/pdf' => 1, 'audio/mp4' => 1, 'audio/aac' => 1, 'video/mp4' => 1,
    ];
    if ($forceType === '' || !isset($okMimes[$forceType])) {
        http_response_code(500); exit('Internal: missing/invalid mime');
    }
    $size = filesize($real);
    header('Content-Type: ' . $forceType);
    header('Content-Length: ' . $size);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; sandbox');
    readfile($real);
}
