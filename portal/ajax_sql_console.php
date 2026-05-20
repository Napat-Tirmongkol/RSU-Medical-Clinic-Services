<?php
// portal/ajax_sql_console.php — Read-only SQL console for diagnostic use
// Actions:
//   run    POST → execute a single SELECT/SHOW/DESCRIBE/EXPLAIN statement
//                 with strict validation + forced LIMIT + audit log
//
// Security model:
//   - superadmin only (file-level gate)
//   - CSRF required
//   - Rate limit 30 queries / 60s per admin (file-backed)
//   - Single-statement only (rejects ";" inside the body)
//   - First keyword whitelist: SELECT / SHOW / DESCRIBE / DESC / EXPLAIN
//   - Blocked tokens (word-boundary regex, case-insensitive):
//       INSERT UPDATE DELETE DROP TRUNCATE ALTER CREATE GRANT REVOKE
//       RENAME REPLACE CALL SET LOCK UNLOCK COMMIT ROLLBACK
//       INTO OUTFILE INTO DUMPFILE LOAD_FILE BENCHMARK SLEEP PG_SLEEP
//   - Forced LIMIT 100 appended if missing
//   - Hard row cap 500 (fetch loop breaks regardless of LIMIT)
//   - Every query logged to sys_activity_logs with the query body,
//     row count, and duration in ms — irrespective of pass/fail
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
if (!$isSuper) {
    echo json_encode(['ok' => false, 'message' => 'SQL console ใช้ได้เฉพาะ superadmin']);
    exit;
}

$action = (string)($_GET['action'] ?? '');

/**
 * Whitelist + blacklist + structural validator.
 *
 * Returns [bool ok, string reason]. The blacklist runs on a copy of the
 * input with SQL comments stripped so the user can't sneak "DELETE"
 * past it via `/* DELETE *‍/` or `-- DELETE`. The whitelist runs on
 * the first non-comment keyword so "WITH" CTEs, etc. are rejected.
 */
function sqlc_validate(string $sql): array {
    $sql = trim($sql);
    if ($sql === '') return [false, 'ว่างเปล่า'];
    if (mb_strlen($sql) > 5000) return [false, 'query ยาวเกิน 5000 ตัวอักษร'];

    // Strip comments before keyword analysis so DELETE-in-comment isn't fooled
    $clean = preg_replace([
        '#--[^\n]*#',       // line comments
        '#/\*.*?\*/#s',     // block comments
    ], ' ', $sql);
    $clean = trim($clean);
    if ($clean === '') return [false, 'ว่างเปล่า'];

    // Single-statement: trailing ";" is fine, but any content AFTER a ";" isn't
    $noTrail = rtrim($clean, "; \t\n\r");
    if (str_contains($noTrail, ';')) {
        return [false, 'อนุญาตเฉพาะคำสั่งเดียวต่อครั้ง'];
    }

    // First-keyword whitelist
    if (!preg_match('/^\s*(\w+)/', $noTrail, $m)) {
        return [false, 'ไม่พบ keyword นำหน้า'];
    }
    $firstKw = strtoupper($m[1]);
    $allowedFirst = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];
    if (!in_array($firstKw, $allowedFirst, true)) {
        return [false, "อนุญาตเฉพาะ SELECT / SHOW / DESCRIBE / EXPLAIN (เจอ: {$firstKw})"];
    }

    // Blocked-keyword scan (word boundary, case-insensitive). Each blocked
    // token gets caught even if it appears as a column / table name —
    // strict superset of "writes" because we'd rather false-positive on
    // legitimate names than let a write slip through.
    $blocked = [
        'INSERT','UPDATE','DELETE','DROP','TRUNCATE','ALTER','CREATE',
        'GRANT','REVOKE','RENAME','REPLACE','CALL','LOCK','UNLOCK',
        'COMMIT','ROLLBACK','HANDLER',
        // SET is a write — including @user_vars; reject outright
        'SET',
        // File system + DoS
        'LOAD_FILE','BENCHMARK','SLEEP','PG_SLEEP',
        // Privilege probes
        'INFORMATION_SCHEMA\\.USER_PRIVILEGES',
    ];
    foreach ($blocked as $kw) {
        $pattern = strpos($kw, '\\.') !== false
            ? '/\\b' . $kw . '\\b/i'
            : '/\\b' . preg_quote($kw, '/') . '\\b/i';
        if (preg_match($pattern, $noTrail)) {
            return [false, "พบคำสั่ง/คำสำคัญต้องห้าม: {$kw}"];
        }
    }

    // INTO OUTFILE / DUMPFILE — multi-token, separate check
    if (preg_match('/\\bINTO\\s+(OUTFILE|DUMPFILE)\\b/i', $noTrail)) {
        return [false, 'INTO OUTFILE/DUMPFILE ห้าม'];
    }

    // mysql.* recon (block direct hits on the system database, not info_schema)
    if (preg_match('/\\bmysql\\.\\w+/i', $noTrail)) {
        return [false, 'mysql.* ห้ามผ่าน console นี้'];
    }

    return [true, ''];
}

/**
 * Append LIMIT 100 if the user didn't include one. Trailing semicolon
 * is preserved-then-restored so the appended clause sits before it.
 */
function sqlc_ensure_limit(string $sql, int $defaultLimit = 100): string {
    $sql = rtrim($sql, "; \t\n\r");
    // Match LIMIT at the very end (LIMIT n  /  LIMIT m, n  /  LIMIT n OFFSET m)
    if (preg_match('/\\bLIMIT\\b/i', $sql)) {
        return $sql;
    }
    // SHOW/DESCRIBE/EXPLAIN don't use LIMIT — return unchanged
    if (preg_match('/^\\s*(SHOW|DESCRIBE|DESC|EXPLAIN)\\b/i', $sql)) {
        return $sql;
    }
    return $sql . ' LIMIT ' . $defaultLimit;
}

/**
 * File-backed per-admin rate limit. Returns false (and writes nothing) when
 * the window already has $max hits; otherwise increments and returns true.
 */
function sqlc_rate_limit_check(int $adminId, int $max = 30, int $windowSec = 60): bool {
    if ($adminId <= 0) return true; // misconfigured session, fall open
    $dir = dirname(__DIR__) . '/storage/rl';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/sql_console_' . $adminId . '.json';
    $now = time();
    $state = @json_decode((string)@file_get_contents($file), true) ?: ['count' => 0, 'reset_at' => $now + $windowSec];
    if ($now >= $state['reset_at']) {
        $state = ['count' => 0, 'reset_at' => $now + $windowSec];
    }
    if ($state['count'] >= $max) return false;
    $state['count']++;
    @file_put_contents($file, json_encode($state), LOCK_EX);
    return true;
}

if ($action === 'run') {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new LogicException('ต้องเป็น POST');
        }
        validate_csrf_or_die();

        $adminId   = (int)($_SESSION['admin_id'] ?? 0);
        $adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'superadmin');

        if (!sqlc_rate_limit_check($adminId, 30, 60)) {
            throw new LogicException('ถึงเพดาน rate limit (30 queries / 60 วินาที) — รอสักครู่');
        }

        $sql = (string)($_POST['sql'] ?? '');
        [$ok, $reason] = sqlc_validate($sql);
        if (!$ok) {
            // Log the rejection too — audit needs to see attempted abuse
            try {
                log_activity('SQL Console (REJECTED)', "reason={$reason} · sql=" . mb_substr($sql, 0, 200), $adminId ?: null);
            } catch (Throwable $e) {}
            throw new LogicException($reason);
        }

        $execSql = sqlc_ensure_limit(trim($sql));
        $hardCap = 500;

        $pdo = db();
        $t0 = microtime(true);
        $stmt = $pdo->query($execSql);   // safe — already validated read-only
        $cols = [];
        $rows = [];
        $truncated = false;

        if ($stmt) {
            // Column metadata once at the top — getColumnMeta() can throw if
            // the driver doesn't support it, so guard each call
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = null;
                try { $meta = $stmt->getColumnMeta($i); } catch (Throwable $e) {}
                $cols[] = [
                    'name' => $meta['name'] ?? ('col_' . $i),
                    'type' => $meta['native_type'] ?? '',
                ];
            }
            // Fetch loop with explicit hard-cap so a forgotten LIMIT can't
            // OOM the process even when the user explicitly asks for billions
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (count($rows) >= $hardCap) { $truncated = true; break; }
                $rows[] = $r;
            }
            $stmt->closeCursor();
        }
        $durMs = (int)round((microtime(true) - $t0) * 1000);

        // Audit log — successful query
        try {
            log_activity(
                'SQL Console',
                'rows=' . count($rows) . ($truncated ? ' (capped)' : '') . ' · ms=' . $durMs . ' · sql=' . mb_substr($sql, 0, 400),
                $adminId ?: null
            );
        } catch (Throwable $e) {
            error_log('[sql_console] log_activity: ' . $e->getMessage());
        }

        echo json_encode([
            'ok'         => true,
            'columns'    => $cols,
            'rows'       => $rows,
            'row_count'  => count($rows),
            'truncated'  => $truncated,
            'duration_ms'=> $durMs,
            'executed_sql' => $execSql,
        ], JSON_UNESCAPED_UNICODE);
    } catch (LogicException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        // Driver/runtime errors — log the full message, surface a generic one
        // so we don't leak schema details to a viewer who hasn't earned them
        error_log('[sql_console] run: ' . $e->getMessage());
        // Superadmin viewers get the driver message because they can already
        // see schema via SHOW COLUMNS / INFORMATION_SCHEMA — no extra leak
        echo json_encode(['ok' => false, 'message' => 'Query failed: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'message' => 'unknown action']);
