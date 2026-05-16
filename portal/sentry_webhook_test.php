<?php
/**
 * portal/sentry_webhook_test.php
 *
 * Browser-based smoke test for the Sentry webhook receiver.
 * Same as tools/sentry_webhook_test.php but driven from the admin UI:
 *
 *   1. Visit this page while logged in as superadmin
 *   2. Click "Send Test Webhook"
 *   3. Page POSTs to itself, server signs a sample payload and fires a
 *      loopback request to /api/sentry_webhook.php, then shows the HTTP
 *      result plus the latest rows in sys_sentry_events.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? '';
if ($adminRole !== 'superadmin') {
    http_response_code(403);
    exit('Superadmin only.');
}

// ── Resolve our own receiver URL (same host, same path prefix) ───────────────
$scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$scheme   = !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? (string)$_SERVER['HTTP_X_FORWARDED_PROTO'] : $scheme;
$host     = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$basePath = preg_replace('#/portal/[^/]+$#', '', (string)$_SERVER['SCRIPT_NAME']);
$targetUrl = $scheme . '://' . $host . $basePath . '/api/sentry_webhook.php';

$result = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $result = ['error' => 'CSRF token invalid — refresh and try again.'];
    } else {
        $secretsPath = __DIR__ . '/../config/secrets.php';
        $secret = '';
        if (file_exists($secretsPath)) {
            $s = require $secretsPath;
            if (is_array($s)) $secret = (string)($s['SENTRY_WEBHOOK_SECRET'] ?? '');
        }

        if ($secret === '') {
            $result = ['error' => 'SENTRY_WEBHOOK_SECRET is empty in config/secrets.php — paste the Client Secret from your Sentry Internal Integration page first.'];
        } else {
            $payload = [
                'action'       => 'created',
                'installation' => ['uuid' => 'browser-test-' . bin2hex(random_bytes(6))],
                'data' => [
                    'issue' => [
                        'id'          => (string)random_int(100000, 999999),
                        'title'       => '[TEST] ErrorException: Browser webhook test',
                        'culprit'     => 'portal/sentry_webhook_test.php',
                        'level'       => 'warning',
                        'environment' => 'production',
                        'permalink'   => 'https://sentry.io/organizations/example/issues/test/',
                        'metadata'    => ['value' => 'Sent from portal/sentry_webhook_test.php'],
                    ],
                ],
                'actor' => ['type' => 'application', 'id' => 'browser-test', 'name' => 'Browser test'],
            ];
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $sig  = hash_hmac('sha256', $body, $secret);

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
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $response = curl_exec($ch);
            $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            curl_close($ch);

            $result = [
                'url'      => $targetUrl,
                'status'   => $status,
                'response' => is_string($response) ? $response : '',
                'curl_err' => $err,
            ];
        }
    }
}

// ── Latest events from DB ────────────────────────────────────────────────────
$latest = [];
try {
    $pdo = db();
    $rows = $pdo->query("SELECT id, resource, action, level, title, environment, received_at
                         FROM sys_sentry_events
                         ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $latest = $rows ?: [];
} catch (Throwable) { /* table may not exist yet */ }

$csrf = get_csrf_token();
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Sentry Webhook Test</title>
<style>
    body { font-family: 'Sarabun', system-ui, sans-serif; max-width: 900px; margin: 30px auto; padding: 0 18px; color: #0f172a; }
    h1 { font-size: 20px; color: #7c3aed; margin: 0 0 4px; }
    .lead { color: #64748b; font-size: 13px; margin-bottom: 22px; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; margin-bottom: 18px; }
    .btn { background: #7c3aed; color: #fff; border: 0; padding: 10px 18px; border-radius: 8px; font-weight: 800; cursor: pointer; font-family: inherit; }
    .btn:hover { background: #6d28d9; }
    code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    .result-ok    { background: #ecfdf5; border-color: #10b981; }
    .result-fail  { background: #fef2f2; border-color: #ef4444; }
    .status { font-size: 28px; font-weight: 900; }
    .status.ok   { color: #047857; }
    .status.fail { color: #b91c1c; }
    pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; font-size: 11px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    th, td { padding: 6px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; }
    th { background: #f8fafc; font-weight: 700; }
    .muted { color: #94a3b8; font-style: italic; padding: 16px; text-align: center; }
    .back { display: inline-block; margin-top: 18px; color: #64748b; text-decoration: none; font-size: 12px; }
</style>
</head>
<body>

<h1>🧪 Sentry Webhook Smoke Test</h1>
<p class="lead">ทดสอบ <code>api/sentry_webhook.php</code> — เซ็นต์ payload จำลองด้วย Client Secret แล้วยิง POST กลับมาที่ตัวเอง ตรวจ signature path + DB write end-to-end</p>

<div class="card">
    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <p style="margin-top:0">เป้าหมาย: <code><?= htmlspecialchars($targetUrl) ?></code></p>
        <button type="submit" class="btn">▶ Send Test Webhook</button>
    </form>
</div>

<?php if ($result !== null): ?>
    <?php if (isset($result['error'])): ?>
        <div class="card result-fail">
            <div class="status fail">✗ ERROR</div>
            <p><?= htmlspecialchars($result['error']) ?></p>
        </div>
    <?php else: ?>
        <?php $ok = ($result['status'] === 202); ?>
        <div class="card <?= $ok ? 'result-ok' : 'result-fail' ?>">
            <div class="status <?= $ok ? 'ok' : 'fail' ?>">
                <?= $ok ? '✓ ' : '✗ ' ?>HTTP <?= (int)$result['status'] ?>
            </div>
            <?php if ($ok): ?>
                <p>Receiver accepted. แถวใหม่ใน <code>sys_sentry_events</code> ด้านล่าง 👇</p>
            <?php else: ?>
                <p>
                    <?php
                    if ($result['status'] === 401)      echo 'Signature mismatch — Client Secret บน server ≠ ที่ Sentry หรือยังไม่ได้ตั้ง';
                    elseif ($result['status'] === 503) echo 'SENTRY_WEBHOOK_SECRET ยังว่างใน secrets.php';
                    elseif ($result['status'] === 500) echo 'Server error — เช็ค sys_error_logs';
                    elseif ($result['status'] === 0)   echo 'cURL failure: ' . htmlspecialchars($result['curl_err']);
                    else                                echo 'Unexpected status — ดู response body';
                    ?>
                </p>
            <?php endif; ?>

            <?php if ($result['response'] !== ''): ?>
                <pre><?= htmlspecialchars($result['response']) ?></pre>
            <?php endif; ?>
            <?php if ($result['curl_err']): ?>
                <p><strong>cURL error:</strong> <?= htmlspecialchars($result['curl_err']) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <h2 style="font-size:14px;margin:0 0 12px;color:#1e40af">📋 10 events ล่าสุดจาก <code>sys_sentry_events</code></h2>
    <?php if (empty($latest)): ?>
        <div class="muted">ยังไม่มี event เข้ามา — ลอง click ปุ่มด้านบนก่อน หรือรอ Sentry alert จริง</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Resource</th>
                    <th>Action</th>
                    <th>Level</th>
                    <th>Title</th>
                    <th>Env</th>
                    <th>Received</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($latest as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= htmlspecialchars($r['resource']) ?></td>
                    <td><?= htmlspecialchars($r['action']) ?></td>
                    <td><?= htmlspecialchars($r['level']) ?></td>
                    <td><?= htmlspecialchars(mb_substr((string)$r['title'], 0, 80)) ?></td>
                    <td><?= htmlspecialchars($r['environment']) ?></td>
                    <td><?= htmlspecialchars($r['received_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<a class="back" href="index.php">← กลับ portal</a>

</body>
</html>
