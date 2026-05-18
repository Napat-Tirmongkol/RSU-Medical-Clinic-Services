<?php
// api/log_js_error.php — รับ JavaScript frontend errors แล้ว log ลง sys_error_logs
declare(strict_types=1);

require_once __DIR__ . '/../includes/rate_limit.php';

// เฉพาะ POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// IP-based rate limit (max 20 JS error reports per IP per minute). Replaces
// session-based bucket which an unauthenticated attacker could bypass by simply
// rotating cookies. No session_start() needed for this public endpoint.
if (!rate_limit_ip_check('js_err', 20, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    exit;
}
rate_limit_ip_hit('js_err', 20, 60);

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
