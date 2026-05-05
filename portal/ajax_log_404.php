<?php
/**
 * portal/ajax_log_404.php
 *
 * Receive 404/HTTP-error reports from client-side safeFetch() wrapper
 * and persist them into sys_error_logs (level=warning, source='[JS] 404').
 *
 * Body (JSON or form): url, status, referrer, message, context (optional)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/session_guard.php';
start_secure_session();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';

header('Content-Type: application/json; charset=utf-8');

// Accept both JSON and form-encoded bodies — fetch from client may send either
$raw = file_get_contents('php://input');
$body = [];
if ($raw !== false && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
}
if (empty($body)) $body = $_POST;

$url      = trim((string)($body['url']      ?? ''));
$status   = (int)($body['status']   ?? 0);
$referrer = trim((string)($body['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
$message  = trim((string)($body['message']  ?? ''));
$context  = $body['context'] ?? null;

if ($url === '' || $status <= 0) {
    json_err('missing url/status', 400);
}

// Cap field sizes to keep log table healthy
$url      = mb_substr($url, 0, 500);
$referrer = mb_substr($referrer, 0, 500);
$message  = mb_substr($message ?: ('HTTP ' . $status . ' fetching ' . $url), 0, 1000);
$ctxJson  = $context !== null ? mb_substr(json_encode($context, JSON_UNESCAPED_UNICODE), 0, 4000) : '';

try {
    $pdo = db();

    // Best-effort table create — matches portal/error_logs.php schema
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_error_logs (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        level      ENUM('error','warning','info') NOT NULL DEFAULT 'error',
        source     VARCHAR(300)  NOT NULL DEFAULT '',
        message    TEXT          NOT NULL,
        context    TEXT          NOT NULL DEFAULT '',
        ip_address VARCHAR(45)   NOT NULL DEFAULT '',
        user_id    INT UNSIGNED  NULL,
        created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notified_at DATETIME     NULL DEFAULT NULL,
        status     ENUM('New', 'Active', 'Resolved') NOT NULL DEFAULT 'New',
        resolve_comment TEXT NULL,
        INDEX idx_level      (level),
        INDEX idx_created_at (created_at),
        INDEX idx_status     (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $level  = $status >= 500 ? 'error' : 'warning';
    $source = '[JS] HTTP ' . $status;

    $contextPayload = [
        'url'        => $url,
        'http_status'=> $status,
        'referrer'   => $referrer,
        'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        'extra'      => $ctxJson !== '' ? json_decode($ctxJson, true) : null,
    ];

    $pdo->prepare("
        INSERT INTO sys_error_logs (level, source, message, context, ip_address, user_id)
        VALUES (:lvl, :src, :msg, :ctx, :ip, :uid)
    ")->execute([
        ':lvl' => $level,
        ':src' => $source,
        ':msg' => $message,
        ':ctx' => json_encode($contextPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ':ip'  => $_SERVER['REMOTE_ADDR'] ?? '',
        ':uid' => isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null,
    ]);

    json_ok(['logged' => true]);
} catch (Throwable $e) {
    error_log('ajax_log_404: ' . $e->getMessage());
    json_err('log failed', 500);
}
