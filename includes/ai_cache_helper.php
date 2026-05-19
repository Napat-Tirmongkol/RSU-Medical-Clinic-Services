<?php
/**
 * includes/ai_cache_helper.php — Day-bounded answer cache for AI Q&A.
 *
 * Why: ai_qa_generate_answer() is the hottest, slowest, costliest call in
 * the LINE auto-reply path (1-3s Gemini round-trip + quota). We cache its
 * result for the rest of the calendar day so a viral question doesn't
 * burn through quota and so identical follow-ups feel instant.
 *
 * Why day-bounded: clinic facts can change at any time (admin edits
 * knowledge chunks, doctor reschedules), but day boundary is the natural
 * "stale by default" point for clinic info. Answers auto-expire at
 * midnight Asia/Bangkok — admin can also bust the cache manually via the
 * Lab UI when they push a knowledge update mid-day.
 *
 * Time-sensitive questions (Phase A detector / per-FAQ flag) BYPASS this
 * cache entirely — they always generate fresh, because their answer
 * changes within the day.
 */

declare(strict_types=1);

require_once __DIR__ . '/clinic_status_helper.php'; // CLINIC_TZ_NAME

function ensure_ai_cache_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_answer_cache (
            cache_key VARCHAR(64) PRIMARY KEY,
            question TEXT NOT NULL,
            answer MEDIUMTEXT NOT NULL,
            category VARCHAR(64) NOT NULL DEFAULT 'อื่นๆ',
            confidence FLOAT NOT NULL DEFAULT 0,
            model VARCHAR(50) NULL,
            hit_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_hit_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
        error_log('[ai_cache] schema: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Normalize a question into a stable cache key. Strips whitespace
 * around tokens and lower-cases ASCII so trivial differences ("เปิดกี่โมง"
 * vs " เปิดกี่โมง" vs "เปิดกี่โมง?") map to the same slot. Includes the
 * current Asia/Bangkok date so tomorrow's cache miss is automatic even
 * if we forget to purge.
 */
function ai_cache_key(string $question): string
{
    $norm = preg_replace('/\s+/u', ' ', mb_strtolower(trim($question), 'UTF-8'));
    $norm = rtrim((string)$norm, "?!.,;: \t\n\r\0\x0B");
    $date = (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ_NAME)))->format('Y-m-d');
    return hash('sha256', "ai-qa:{$date}:{$norm}");
}

/**
 * Look up a cached answer. Returns null on miss / expiry / DB error so
 * the caller can fall through to the generator. Bumps hit_count +
 * last_hit_at on hit so the Lab can show "popular question" stats.
 */
function ai_cache_get(PDO $pdo, string $question): ?array
{
    ensure_ai_cache_schema($pdo);
    $key = ai_cache_key($question);
    try {
        $stmt = $pdo->prepare("SELECT question, answer, category, confidence, model, hit_count
            FROM sys_ai_answer_cache
            WHERE cache_key = ? AND expires_at > NOW()
            LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $pdo->prepare("UPDATE sys_ai_answer_cache
            SET hit_count = hit_count + 1, last_hit_at = NOW()
            WHERE cache_key = ?")->execute([$key]);

        return [
            'answer'     => (string)$row['answer'],
            'category'   => (string)$row['category'],
            'confidence' => (float)$row['confidence'],
            'model'      => (string)($row['model'] ?? '') . '+cached',
            'cache_hits' => (int)$row['hit_count'] + 1,
        ];
    } catch (PDOException $e) {
        error_log('[ai_cache get] ' . $e->getMessage());
        return null;
    }
}

/**
 * Store a generated answer. Expires at end-of-day Asia/Bangkok so a
 * misfire from a single question can't poison tomorrow's replies.
 * Silent on error — caching is a best-effort optimization, never block
 * the caller.
 */
function ai_cache_put(PDO $pdo, string $question, array $result): void
{
    if (empty($result['answer'])) return; // never cache an empty answer
    ensure_ai_cache_schema($pdo);
    $key = ai_cache_key($question);
    try {
        $tz = new DateTimeZone(CLINIC_TZ_NAME);
        $expires = (new DateTimeImmutable('now', $tz))
            ->setTime(23, 59, 59)
            ->format('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO sys_ai_answer_cache
            (cache_key, question, answer, category, confidence, model, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                answer = VALUES(answer),
                category = VALUES(category),
                confidence = VALUES(confidence),
                model = VALUES(model),
                expires_at = VALUES(expires_at)
        ")->execute([
            $key,
            mb_substr($question, 0, 1000),
            (string)$result['answer'],
            (string)($result['category'] ?? 'อื่นๆ'),
            (float)($result['confidence'] ?? 0),
            (string)($result['model'] ?? ''),
            $expires,
        ]);
    } catch (PDOException $e) {
        error_log('[ai_cache put] ' . $e->getMessage());
    }
}

/**
 * Admin "bust the cache" — wipe everything so the next request
 * regenerates from current knowledge. Use after pushing a chunk update.
 * Returns the number of entries removed.
 */
function ai_cache_purge_all(PDO $pdo): int
{
    ensure_ai_cache_schema($pdo);
    try {
        return (int)$pdo->exec("DELETE FROM sys_ai_answer_cache");
    } catch (PDOException $e) {
        error_log('[ai_cache purge] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Drop a single question's cache entry — keyed by the same hash used at write
 * time. Called when admin promotes a captured question into a FAQ variant so
 * the very next ask resolves through the new Phase-1 exact match instead of
 * serving the stale gemini-derived answer.
 */
function ai_cache_invalidate_question(PDO $pdo, string $question): int
{
    ensure_ai_cache_schema($pdo);
    try {
        $stmt = $pdo->prepare("DELETE FROM sys_ai_answer_cache WHERE question_hash = :h");
        $stmt->execute([':h' => ai_cache_key($question)]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log('[ai_cache invalidate] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Housekeeping — drop entries past their expires_at. Cheap, safe to
 * call on every cache hit / cache put, but cron is fine too.
 */
function ai_cache_purge_expired(PDO $pdo): int
{
    ensure_ai_cache_schema($pdo);
    try {
        return (int)$pdo->exec("DELETE FROM sys_ai_answer_cache WHERE expires_at <= NOW()");
    } catch (PDOException $e) {
        error_log('[ai_cache purge expired] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Stats for the Lab UI: total entries, today's hits, top N popular.
 */
function ai_cache_stats(PDO $pdo, int $topN = 10): array
{
    ensure_ai_cache_schema($pdo);
    try {
        $total   = (int)$pdo->query("SELECT COUNT(*) FROM sys_ai_answer_cache WHERE expires_at > NOW()")->fetchColumn();
        $hits    = (int)$pdo->query("SELECT COALESCE(SUM(hit_count), 0) FROM sys_ai_answer_cache WHERE expires_at > NOW()")->fetchColumn();
        $top     = $pdo->query("SELECT question, hit_count, last_hit_at
            FROM sys_ai_answer_cache
            WHERE expires_at > NOW() AND hit_count > 0
            ORDER BY hit_count DESC, last_hit_at DESC
            LIMIT " . max(1, min(50, $topN)))->fetchAll(PDO::FETCH_ASSOC);
        return ['total' => $total, 'hits' => $hits, 'top' => $top];
    } catch (PDOException $e) {
        error_log('[ai_cache stats] ' . $e->getMessage());
        return ['total' => 0, 'hits' => 0, 'top' => []];
    }
}
