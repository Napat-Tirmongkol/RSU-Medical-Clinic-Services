<?php
/**
 * includes/ai_telemetry_helper.php — Lightweight metric collection for
 * the AI QA path so the Lab can show health at a glance.
 *
 * Events we care about (event_type column):
 *   - gemini_call         Gemini round-trip started
 *   - gemini_success      Gemini returned a usable answer
 *   - gemini_fail         Gemini errored (timeout, quota, malformed)
 *   - cache_hit           Answer served from sys_ai_answer_cache
 *   - cache_miss          Cache miss, fell through to generator
 *   - faq_hit             Phase 1/2 matcher returned a stored FAQ
 *   - bypass_time_sensitive   Phase A short-circuit fired
 *   - fallback_used       Generator failed, served FAQ as fallback
 *   - thumbs_up / thumbs_down  User feedback on the reply
 *
 * Append-only, fire-and-forget. Never throws to caller — telemetry
 * failure must never block an actual reply.
 */

declare(strict_types=1);

function ensure_ai_telemetry_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_telemetry (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(40) NOT NULL,
            source VARCHAR(40) NULL,
            model VARCHAR(50) NULL,
            elapsed_ms INT UNSIGNED NULL,
            error_msg VARCHAR(500) NULL,
            line_user_hash VARCHAR(64) NULL,
            meta_json TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_time (event_type, created_at),
            INDEX idx_time (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('[ai_telemetry] schema: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Hash a LINE user id for telemetry — we want per-user rate analysis
 * without storing the raw id (privacy + healthcare data hygiene).
 */
function ai_telemetry_hash_user(?string $lineUserId): ?string
{
    if (!$lineUserId) return null;
    return substr(hash('sha256', 'ai-qa-user:' . $lineUserId), 0, 32);
}

/**
 * Append one telemetry event. $meta is anything you want to keep
 * (cache_hits, confidence, source_module, ...) — JSON-serialized.
 * Always safe to call; swallows all errors.
 */
function ai_telemetry_log(
    PDO $pdo,
    string $eventType,
    array $opts = []
): void {
    try {
        ensure_ai_telemetry_schema($pdo);
        $stmt = $pdo->prepare("INSERT INTO sys_ai_telemetry
            (event_type, source, model, elapsed_ms, error_msg, line_user_hash, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $eventType,
            isset($opts['source'])     ? mb_substr((string)$opts['source'], 0, 40)     : null,
            isset($opts['model'])      ? mb_substr((string)$opts['model'], 0, 50)      : null,
            isset($opts['elapsed_ms']) ? (int)$opts['elapsed_ms']                       : null,
            isset($opts['error_msg'])  ? mb_substr((string)$opts['error_msg'], 0, 500) : null,
            $opts['line_user_hash'] ?? null,
            !empty($opts['meta']) ? json_encode($opts['meta'], JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        // Telemetry is best-effort. Log to error_log so we still see
        // structural problems with the table itself, but never propagate.
        error_log('[ai_telemetry log] ' . $e->getMessage());
    }
}

/**
 * Roll-up for the Lab "AI health" widget.
 *
 * windowHours = lookback for the headline counters. Defaults to 24h
 * which lines up with the answer cache's TTL.
 */
function ai_telemetry_summary(PDO $pdo, int $windowHours = 24): array
{
    ensure_ai_telemetry_schema($pdo);
    $since = (new DateTimeImmutable("-{$windowHours} hours"))->format('Y-m-d H:i:s');
    try {
        $stmt = $pdo->prepare("SELECT event_type, COUNT(*) AS c, AVG(elapsed_ms) AS avg_ms
            FROM sys_ai_telemetry
            WHERE created_at >= ?
            GROUP BY event_type");
        $stmt->execute([$since]);
        $byType = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byType[(string)$r['event_type']] = [
                'count'  => (int)$r['c'],
                'avg_ms' => $r['avg_ms'] !== null ? (int)round((float)$r['avg_ms']) : null,
            ];
        }

        // Derived health metrics — the numbers admins actually want
        $geminiCalls = $byType['gemini_call']['count']   ?? 0;
        $geminiOk    = $byType['gemini_success']['count'] ?? 0;
        $geminiFail  = $byType['gemini_fail']['count']    ?? 0;
        $cacheHit    = $byType['cache_hit']['count']      ?? 0;
        $cacheMiss   = $byType['cache_miss']['count']     ?? 0;
        $fallback    = $byType['fallback_used']['count']  ?? 0;
        $thumbsUp    = $byType['thumbs_up']['count']      ?? 0;
        $thumbsDown  = $byType['thumbs_down']['count']    ?? 0;

        $cacheTotal  = $cacheHit + $cacheMiss;
        $thumbsTotal = $thumbsUp + $thumbsDown;

        return [
            'window_hours'      => $windowHours,
            'by_type'           => $byType,
            'gemini_calls'      => $geminiCalls,
            'gemini_success'    => $geminiOk,
            'gemini_fail'       => $geminiFail,
            'gemini_fail_rate'  => $geminiCalls > 0 ? round($geminiFail / $geminiCalls, 3) : 0.0,
            'cache_hit_rate'    => $cacheTotal > 0  ? round($cacheHit / $cacheTotal, 3)    : 0.0,
            'fallback_used'     => $fallback,
            'thumbs_up'         => $thumbsUp,
            'thumbs_down'       => $thumbsDown,
            'satisfaction_rate' => $thumbsTotal > 0 ? round($thumbsUp / $thumbsTotal, 3)  : null,
        ];
    } catch (PDOException $e) {
        error_log('[ai_telemetry summary] ' . $e->getMessage());
        return ['window_hours' => $windowHours, 'by_type' => [], 'error' => 'query_failed'];
    }
}

/**
 * Last N events for the debug timeline panel (admin-facing only).
 */
function ai_telemetry_recent(PDO $pdo, int $limit = 50): array
{
    ensure_ai_telemetry_schema($pdo);
    $limit = max(1, min(500, $limit));
    try {
        $rows = $pdo->query("SELECT event_type, source, model, elapsed_ms, error_msg, meta_json, created_at
            FROM sys_ai_telemetry
            ORDER BY id DESC
            LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    } catch (PDOException $e) {
        error_log('[ai_telemetry recent] ' . $e->getMessage());
        return [];
    }
}

/**
 * Cron / housekeeping — drop telemetry older than N days.
 */
function ai_telemetry_purge_older_than(PDO $pdo, int $days = 30): int
{
    ensure_ai_telemetry_schema($pdo);
    $days = max(1, $days);
    try {
        return (int)$pdo->exec("DELETE FROM sys_ai_telemetry
            WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
    } catch (PDOException $e) {
        error_log('[ai_telemetry purge] ' . $e->getMessage());
        return 0;
    }
}
