<?php
// api/log_js_error.php — รับ JavaScript frontend errors แล้ว log ลง sys_error_logs
declare(strict_types=1);

// เฉพาะ POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Rate limit: max 20 JS errors ต่อ session ต่อนาที ป้องกัน spam
if (session_status() === PHP_SESSION_NONE) session_start();
$now = time();
$_SESSION['_js_err_bucket'] = array_filter(
    $_SESSION['_js_err_bucket'] ?? [],
    fn($t) => $t > $now - 60
);
if (count($_SESSION['_js_err_bucket']) >= 20) {
    http_response_code(429);
    exit;
}
$_SESSION['_js_err_bucket'][] = $now;

// รับ JSON body
$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    exit;
}

$message = mb_substr(trim((string)($body['message'] ?? '')), 0, 3000);
$source  = mb_substr(trim((string)($body['source']  ?? '')), 0, 300);
$stack   = mb_substr(trim((string)($body['stack']   ?? '')), 0, 4000);
$pageUrl = mb_substr(trim((string)($body['url']     ?? '')), 0, 500);
$level   = in_array($body['level'] ?? '', ['error', 'warning', 'info'], true)
           ? (string)$body['level'] : 'error';

if ($message === '') {
    http_response_code(400);
    exit;
}

// ข้าม browser extension errors (ไม่ใช่ของเรา)
foreach (['chrome-extension://', 'moz-extension://', 'safari-extension://'] as $ext) {
    if (str_contains($source, $ext)) {
        http_response_code(204);
        exit;
    }
}

// ข้าม noise ที่ไม่เกี่ยวข้อง
$noisePatterns = ['ResizeObserver loop', 'Non-Error promise rejection'];
foreach ($noisePatterns as $pattern) {
    if (str_contains($message, $pattern)) {
        http_response_code(204);
        exit;
    }
}

require_once __DIR__ . '/../includes/error_logger.php';

$context = '';
if ($pageUrl !== '') $context .= "Page: {$pageUrl}";
if ($stack !== '')   $context .= ($context ? "\n\n" : '') . "Stack:\n{$stack}";

log_error_to_db(
    $message,
    $level,
    '[JS] ' . ($source ?: 'unknown'),
    $context
);

http_response_code(204);
