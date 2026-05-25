<?php
/**
 * cron/sync_richmenu.php
 * ──────────────────────────────────────────────────────────────────────────────
 * Auto-link "member" rich menu ให้ user ทุกคนที่มี line_user_id ใน sys_users
 *
 * Logic ตรงกับปุ่ม "Sync ทุก member" ในหน้า admin · ปลอดภัย idempotent
 * (LINE API จะ overwrite binding เดิมด้วย richMenuId เดียวกัน — ไม่มี side effect)
 *
 * ── Time-budgeted resumable sync ─────────────────────────────────────────────
 * Each LINE API call takes ~300-500ms (Tokyo round-trip) — cron-job.org free
 * tier caps at 30s so we can only process ~50-70 users per run. The script
 * stores `last_processed_id` in sys_richmenu_sync_state and resumes from there
 * on the next call. When the table is exhausted, the cycle resets to 0 so
 * the next call starts a fresh pass.
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────────────────
 *  URL     : https://healthycampus.rsu.ac.th/e-campaignv2/cron/sync_richmenu.php?token=YOUR_SECRET_TOKEN
 *  Schedule: ทุก 5-10 นาที (แต่ละ call ใช้ ≤25s — กิน cron-job.org 30s ไม่ทัน)
 *
 *  Optional query params:
 *    ?max_seconds=25  Hard time budget per run (default 25, max 120)
 *    ?batch=100       Max users to fetch per query (default 100)
 *    ?dryrun=1        ไม่เรียก LINE API จริง (log อย่างเดียว)
 *    ?reset=1         บังคับเริ่ม cycle ใหม่จาก id 0
 *
 *  Response: text/plain log — มี HTTP 200 เสมอ (เว้นแต่ config error)
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

define('CRON_SECRET_TOKEN', 'rsu_richmenu_q7p2v9z4n');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(CRON_SECRET_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Bangkok');
header('Content-Type: text/plain; charset=utf-8');

// ── Surface fatals as plain-text response so the user sees the real cause
//    instead of a bare "500 Internal Server Error" with no log access.
set_exception_handler(function (Throwable $e) {
    echo "\n[FATAL EXCEPTION] " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  at " . $e->getFile() . ':' . $e->getLine() . "\n";
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\n[FATAL ERROR] " . $err['message'] . "\n";
        echo "  at " . $err['file'] . ':' . $err['line'] . "\n";
    }
});

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config.php';
require_once $projectRoot . '/line_api/line_richmenu_helper.php';

$maxSeconds = max(5, min(120, (int)($_GET['max_seconds'] ?? 25)));
$batch      = max(10, min(1000, (int)($_GET['batch']       ?? 100)));
$dryrun     = !empty($_GET['dryrun']);
$reset      = !empty($_GET['reset']);

$startedAt = microtime(true);
echo '[' . date('Y-m-d H:i:s') . "] sync_richmenu cron started"
   . ($dryrun ? ' (DRY RUN)' : '')
   . " · budget={$maxSeconds}s batch={$batch}\n";

$ids = line_richmenu_get_ids();
if ($ids['member'] === '') {
    echo "ERROR: ยังไม่ได้ตั้ง member richMenuId — ตั้งค่าผ่านหน้า portal ก่อน\n";
    exit;
}
echo "Target member menu: {$ids['member']}\n";

try {
    $pdo = db();

    // ── State table: tracks resume position across cron calls ────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_richmenu_sync_state (
        id INT PRIMARY KEY DEFAULT 1,
        last_processed_id INT UNSIGNED NOT NULL DEFAULT 0,
        cycle_count INT UNSIGNED NOT NULL DEFAULT 0,
        cycle_started_at TIMESTAMP NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO sys_richmenu_sync_state
                (id, last_processed_id, cycle_count, cycle_started_at)
                VALUES (1, 0, 0, NOW())");
    $state = $pdo->query("SELECT last_processed_id, cycle_count, cycle_started_at
                          FROM sys_richmenu_sync_state WHERE id = 1")
                 ->fetch(PDO::FETCH_ASSOC) ?: ['last_processed_id' => 0, 'cycle_count' => 0, 'cycle_started_at' => null];
    $lastId = (int)$state['last_processed_id'];

    if ($reset) {
        $lastId = 0;
        $pdo->exec("UPDATE sys_richmenu_sync_state
                    SET last_processed_id = 0, cycle_started_at = NOW() WHERE id = 1");
        echo "↻ reset requested — starting cycle from id 0\n";
    }
    echo "Resume from sys_users.id > {$lastId}"
       . " · cycle #" . (int)$state['cycle_count']
       . ($state['cycle_started_at'] ? " (started {$state['cycle_started_at']})" : '')
       . "\n";

    // Detect optional line_user_id_new column (added by migrate_line_user_id_new)
    $hasNewCol = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM sys_users LIKE 'line_user_id_new'");
        $hasNewCol = $colCheck && $colCheck->fetch() !== false;
    } catch (Throwable $e) {
        $hasNewCol = false;
    }

    // Only sync "real" members — skip partial rows where full_name is empty
    // (avoids case where a half-created row from line callback gets a member
    // menu before the user has actually registered).
    $fullNameGuard = "AND full_name IS NOT NULL AND TRIM(full_name) <> ''";

    if ($hasNewCol) {
        $sql = "SELECT id, COALESCE(line_user_id_new, line_user_id) AS uid
                FROM sys_users
                WHERE id > :last_id
                  AND ((line_user_id     IS NOT NULL AND line_user_id     != '')
                       OR (line_user_id_new IS NOT NULL AND line_user_id_new != ''))
                  {$fullNameGuard}
                ORDER BY id ASC
                LIMIT :lim";
    } else {
        echo "  · column line_user_id_new not found — using line_user_id only\n";
        $sql = "SELECT id, line_user_id AS uid
                FROM sys_users
                WHERE id > :last_id
                  AND line_user_id IS NOT NULL AND line_user_id != ''
                  {$fullNameGuard}
                ORDER BY id ASC
                LIMIT :lim";
    }
    $st = $pdo->prepare($sql);
    $st->bindValue(':last_id', $lastId, PDO::PARAM_INT);
    $st->bindValue(':lim',     $batch,  PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo "ERROR: query failed — " . $e->getMessage() . "\n";
    exit;
}

$total = count($rows);
echo "Found {$total} user(s) to process in this run (batch limit {$batch})\n";

$ok = 0; $fail = 0; $skip = 0; $newLastId = $lastId;
$timedOut = false;
foreach ($rows as $i => $row) {
    $uid    = (string)$row['uid'];
    $rowId  = (int)$row['id'];

    if (!$uid) { $skip++; $newLastId = $rowId; continue; }

    // Time budget check — exit cleanly so we don't get killed mid-row
    $elapsed = microtime(true) - $startedAt;
    if ($elapsed >= $maxSeconds) {
        $timedOut = true;
        echo "  ⏱  time budget reached at " . round($elapsed, 1) . "s "
           . "(processed " . ($i) . " / {$total} this batch)\n";
        break;
    }

    try {
        if ($dryrun) {
            $ok++;
        } else {
            $r = line_richmenu_link_user($uid, $ids['member']);
            $r['ok'] ? $ok++ : $fail++;
            line_richmenu_audit_log(
                $uid,
                $r['ok'] ? 'sync_ok' : 'sync_failed',
                'member',
                $ids['member'],
                $r['ok'] ? null : $r['error'],
                'cron:sync_richmenu'
            );
        }
        $newLastId = $rowId;  // only advance on successful (or attempted) row
    } catch (Throwable $e) {
        $fail++;
        $newLastId = $rowId;
        echo "  ✗ user " . substr($uid, 0, 8) . "...: " . $e->getMessage() . "\n";
    }

    // Light throttle — LINE rate limit is ~50/sec
    if (($i + 1) % 25 === 0) usleep(50000);  // 50ms every 25 rows
}

// ── Update state ────────────────────────────────────────────────────────────
$cycleDone = false;
try {
    $pdo->prepare("UPDATE sys_richmenu_sync_state
                   SET last_processed_id = :id WHERE id = 1")
        ->execute([':id' => $newLastId]);

    // If we processed everything available + budget allowed → cycle is done.
    // Check whether ANY remaining rows beyond newLastId exist; if not, reset.
    if (!$timedOut && $total < $batch) {
        // We fetched < batch which means the query exhausted the table.
        // Wrap to start of cycle.
        $pdo->exec("UPDATE sys_richmenu_sync_state
                    SET last_processed_id = 0,
                        cycle_count = cycle_count + 1,
                        cycle_started_at = NOW()
                    WHERE id = 1");
        $cycleDone = true;
    }
} catch (Throwable $e) {
    echo "WARN: state update failed — " . $e->getMessage() . "\n";
}

$elapsed = round(microtime(true) - $startedAt, 2);
echo "─────────────────────────────────────\n";
echo "DONE in {$elapsed}s\n";
echo "  processed: " . ($ok + $fail) . " (this run)\n";
echo "  success:   $ok\n";
echo "  failed:    $fail\n";
echo "  skipped:   $skip\n";
echo "  last id:   $newLastId\n";
if ($cycleDone) {
    echo "  ✓ cycle complete — next call wraps to id 0\n";
} elseif ($timedOut) {
    echo "  → next call resumes from id > $newLastId\n";
} else {
    echo "  → more rows available — next call continues\n";
}

if (!$dryrun && function_exists('log_activity')) {
    @log_activity('LINE Rich Menu',
        "Cron sync_richmenu: this_run=" . ($ok + $fail) . " ok=$ok fail=$fail "
        . "last_id=$newLastId" . ($cycleDone ? ' [cycle done]' : '')
        . " (in {$elapsed}s)");
}
