<?php
/**
 * api/sentry_webhook.php
 * Receive webhooks from a Sentry Internal Integration.
 *
 * - Verifies the HMAC-SHA256 signature using SENTRY_WEBHOOK_SECRET.
 * - Stores each event in sys_sentry_events for later inspection.
 * - Does NOT take action on the event yet (no GitHub issue / LINE notify) —
 *   that is a follow-up phase once we've watched a few real events flow in.
 *
 * Sentry sends:
 *   Headers
 *     Sentry-Hook-Resource    : "issue" | "event_alert" | "installation" | ...
 *     Sentry-Hook-Signature   : hex SHA256-HMAC of the raw body
 *     Sentry-Hook-Timestamp   : unix seconds
 *   Body (JSON), shape varies by resource — common keys: action, data, actor, installation
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// ── 1. Method guard ──────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'method not allowed';
    return;
}

// ── 2. Load secret + raw body ────────────────────────────────────────────────
$body = file_get_contents('php://input') ?: '';
$secretsPath = __DIR__ . '/../config/secrets.php';
$secret = '';
if (file_exists($secretsPath)) {
    $s = require $secretsPath;
    if (is_array($s)) $secret = (string)($s['SENTRY_WEBHOOK_SECRET'] ?? '');
}

if ($secret === '') {
    log_error_to_db('Sentry webhook: SENTRY_WEBHOOK_SECRET not configured', 'error', 'sentry_webhook.php');
    http_response_code(503);
    echo 'webhook not configured';
    return;
}

// ── 3. Verify signature ──────────────────────────────────────────────────────
$givenSig = trim((string)($_SERVER['HTTP_SENTRY_HOOK_SIGNATURE'] ?? ''));
$expectedSig = hash_hmac('sha256', $body, $secret);
if ($givenSig === '' || !hash_equals($expectedSig, $givenSig)) {
    log_error_to_db('Sentry webhook: signature mismatch', 'warning', 'sentry_webhook.php', json_encode([
        'ip'       => $_SERVER['REMOTE_ADDR'] ?? '',
        'resource' => $_SERVER['HTTP_SENTRY_HOOK_RESOURCE'] ?? '',
    ], JSON_UNESCAPED_UNICODE));
    http_response_code(401);
    echo 'invalid signature';
    return;
}

// ── 4. Parse payload ─────────────────────────────────────────────────────────
$payload = json_decode($body, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'invalid json';
    return;
}

$resource = (string)($_SERVER['HTTP_SENTRY_HOOK_RESOURCE'] ?? '');
$action   = (string)($payload['action'] ?? '');

// Extract issue/event fields best-effort — Sentry's payload shape differs per resource
$data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$issue = is_array($data['issue'] ?? null) ? $data['issue'] : (is_array($data['event'] ?? null) ? $data['event'] : []);

$sentryId    = (string)($issue['id'] ?? $issue['event_id'] ?? '');
$title       = (string)($issue['title'] ?? $issue['message'] ?? '');
$culprit     = (string)($issue['culprit'] ?? $issue['location'] ?? '');
$level       = (string)($issue['level'] ?? '');
$environment = (string)($issue['environment'] ?? ($issue['contexts']['runtime']['environment'] ?? ''));
$url         = (string)($issue['web_url'] ?? $issue['permalink'] ?? $issue['url'] ?? '');

// ── 5. Persist to sys_sentry_events ──────────────────────────────────────────
try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_sentry_events (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sentry_id    VARCHAR(64)  NOT NULL DEFAULT '',
        resource     VARCHAR(40)  NOT NULL DEFAULT '',
        action       VARCHAR(40)  NOT NULL DEFAULT '',
        level        VARCHAR(20)  NOT NULL DEFAULT '',
        title        VARCHAR(500) NOT NULL DEFAULT '',
        culprit      VARCHAR(500) NOT NULL DEFAULT '',
        environment  VARCHAR(60)  NOT NULL DEFAULT '',
        url          TEXT         NULL,
        raw_payload  MEDIUMTEXT   NULL,
        received_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sentry_id   (sentry_id),
        INDEX idx_resource    (resource),
        INDEX idx_action      (action),
        INDEX idx_received_at (received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->prepare("INSERT INTO sys_sentry_events
            (sentry_id, resource, action, level, title, culprit, environment, url, raw_payload)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            mb_substr($sentryId, 0, 64),
            mb_substr($resource, 0, 40),
            mb_substr($action, 0, 40),
            mb_substr($level, 0, 20),
            mb_substr($title, 0, 500),
            mb_substr($culprit, 0, 500),
            mb_substr($environment, 0, 60),
            $url,
            mb_substr($body, 0, 1000000), // 1MB cap
        ]);

    // Probabilistic purge — keep 90 days
    if (mt_rand(1, 100) === 1) {
        $pdo->exec("DELETE FROM sys_sentry_events WHERE received_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }
} catch (Throwable $e) {
    log_error_to_db('Sentry webhook: DB insert failed — ' . $e->getMessage(), 'error', 'sentry_webhook.php');
    http_response_code(500);
    echo 'db error';
    return;
}

// ── 6. ACK ──────────────────────────────────────────────────────────────────
http_response_code(202);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'received' => true]);
