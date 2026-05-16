<?php
/**
 * tools/sentry_webhook_test.php
 *
 * CLI helper: send a fake Sentry issue.created webhook to our own receiver
 * to verify signature path + DB write end to end.
 *
 * Usage (on the production server, in the project root):
 *   php tools/sentry_webhook_test.php
 *   php tools/sentry_webhook_test.php https://healthycampus.rsu.ac.th/e-campaignv2/api/sentry_webhook.php
 *
 * The script:
 *   - Reads SENTRY_WEBHOOK_SECRET from config/secrets.php
 *   - Builds a sample issue.created payload
 *   - Signs it with HMAC-SHA256 (the same algorithm the receiver verifies)
 *   - POSTs to the target URL with the Sentry-Hook-* headers
 *   - Prints the HTTP status + response body
 *
 * After running, check the DB:
 *   SELECT id, resource, action, title, received_at
 *   FROM sys_sentry_events ORDER BY id DESC LIMIT 5;
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$defaultUrl = 'http://127.0.0.1/api/sentry_webhook.php';
$targetUrl  = $argv[1] ?? $defaultUrl;

// ── Load secret ──────────────────────────────────────────────────────────────
$secretsPath = __DIR__ . '/../config/secrets.php';
if (!file_exists($secretsPath)) {
    fwrite(STDERR, "Missing config/secrets.php\n"); exit(1);
}
$s = require $secretsPath;
$secret = (string)($s['SENTRY_WEBHOOK_SECRET'] ?? '');
if ($secret === '') {
    fwrite(STDERR, "SENTRY_WEBHOOK_SECRET is empty in config/secrets.php\n"); exit(1);
}

// ── Build sample payload (issue.created) ────────────────────────────────────
$payload = [
    'action'       => 'created',
    'installation' => ['uuid' => 'test-' . bin2hex(random_bytes(6))],
    'data' => [
        'issue' => [
            'id'          => (string)random_int(100000, 999999),
            'title'       => '[TEST] ErrorException: Synthetic webhook test',
            'culprit'     => 'tools/sentry_webhook_test.php',
            'level'       => 'warning',
            'environment' => 'production',
            'permalink'   => 'https://sentry.io/organizations/example/issues/test/',
            'metadata'    => ['value' => 'Sent from tools/sentry_webhook_test.php'],
        ],
    ],
    'actor' => ['type' => 'application', 'id' => 'cli-test', 'name' => 'CLI test'],
];
$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
$sig  = hash_hmac('sha256', $body, $secret);

// ── POST ────────────────────────────────────────────────────────────────────
$ch = curl_init($targetUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Sentry-Hook-Resource: issue',
        'Sentry-Hook-Signature: ' . $sig,
        'Sentry-Hook-Timestamp: ' . time(),
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => false, // self-signed in dev — flip on in prod
]);
$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

echo "POST   $targetUrl\n";
echo "Status $status\n";
if ($err)      echo "cURL   $err\n";
if ($response) echo "Body   $response\n";

if ($status === 202) {
    echo "\n✓ Receiver accepted. Check sys_sentry_events for the new row.\n";
} elseif ($status === 401) {
    echo "\n✗ Signature mismatch — secret on server differs from this script.\n";
} else {
    echo "\n✗ Unexpected status — check error logs.\n";
}
