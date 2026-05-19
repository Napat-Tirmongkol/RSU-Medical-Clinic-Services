<?php
/**
 * cron/purge_ai_telemetry.php
 *
 * Phase C housekeeping for the AI restoration plan:
 *  - drop telemetry rows older than 30 days   (sys_ai_telemetry)
 *  - drop expired answer-cache rows           (sys_ai_answer_cache)
 *
 * Both tables auto-create on first write, so this script is safe to
 * schedule even on an instance that hasn't seen AI traffic yet.
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────
 *  URL     : https://<host>/cron/purge_ai_telemetry.php?token=YOUR_SECRET_TOKEN
 *  Schedule: Every day at 02:15 Asia/Bangkok (after purge_error_logs)
 * ──────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

// Shares the existing purge secret with purge_error_logs.php so ops
// doesn't have to juggle two tokens. Override here if you want to
// scope this script separately.
define('AI_PURGE_SECRET_TOKEN', 'rsu_purge_a8f3k2m9x');

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(AI_PURGE_SECRET_TOKEN, $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_telemetry_helper.php';
require_once __DIR__ . '/../includes/ai_cache_helper.php';

$pdo = db();
$now = date('Y-m-d H:i:s');

// Retention windows — match the values that ship in the helpers.
$telemetryDays = 30;

echo "[{$now}] Starting AI purge...\n";

try {
    $deleted = ai_telemetry_purge_older_than($pdo, $telemetryDays);
    echo "[{$now}] sys_ai_telemetry: deleted {$deleted} rows older than {$telemetryDays} days\n";
} catch (Throwable $e) {
    echo "[{$now}] ERROR (sys_ai_telemetry): " . $e->getMessage() . "\n";
}

try {
    $deleted = ai_cache_purge_expired($pdo);
    echo "[{$now}] sys_ai_answer_cache: deleted {$deleted} expired rows\n";
} catch (Throwable $e) {
    echo "[{$now}] ERROR (sys_ai_answer_cache): " . $e->getMessage() . "\n";
}

echo "[{$now}] AI purge complete.\n";
