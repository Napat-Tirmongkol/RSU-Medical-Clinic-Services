<?php
/**
 * cron/sync_richmenu.php
 * ──────────────────────────────────────────────────────────────────────────────
 * Auto-link "member" rich menu ให้ user ทุกคนที่มี line_user_id ใน sys_users
 *
 * Logic ตรงกับปุ่ม "Sync ทุก member" ในหน้า admin · ปลอดภัย idempotent
 * (LINE API จะ overwrite binding เดิมด้วย richMenuId เดียวกัน — ไม่มี side effect)
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────────────────
 *  URL     : https://healthycampus.rsu.ac.th/e-campaignv2/cron/sync_richmenu.php?token=YOUR_SECRET_TOKEN
 *  Schedule: สัปดาห์ละครั้ง (เช่น ทุกวันอาทิตย์ 03:00 Asia/Bangkok)
 *
 *  Optional query params:
 *    ?limit=500   จำกัดจำนวน user ที่ sync ต่อรอบ (default = ทั้งหมด)
 *    ?dryrun=1    ไม่เรียก LINE API จริง (log อย่างเดียว)
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

$limit  = max(0, (int)($_GET['limit'] ?? 0));   // 0 = unlimited
$dryrun = !empty($_GET['dryrun']);

$startedAt = microtime(true);
echo '[' . date('Y-m-d H:i:s') . "] sync_richmenu cron started" . ($dryrun ? ' (DRY RUN)' : '') . "\n";

$ids = line_richmenu_get_ids();
if ($ids['member'] === '') {
    echo "ERROR: ยังไม่ได้ตั้ง member richMenuId — ตั้งค่าผ่านหน้า portal ก่อน\n";
    exit;
}
echo "Target member menu: {$ids['member']}\n";

try {
    $pdo = db();

    // Detect whether the optional line_user_id_new column exists. It was
    // added in migrate_line_user_id_new.php to support a future channel
    // migration — older installs only have line_user_id. The original SQL
    // referenced both unconditionally and 500'd on prod hosts where the
    // migration hadn't been run yet.
    $hasNewCol = false;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM sys_users LIKE 'line_user_id_new'");
        $hasNewCol = $colCheck && $colCheck->fetch() !== false;
    } catch (Throwable $e) {
        $hasNewCol = false;
    }

    if ($hasNewCol) {
        $sql = "SELECT DISTINCT COALESCE(line_user_id_new, line_user_id) AS uid
                FROM sys_users
                WHERE (line_user_id     IS NOT NULL AND line_user_id     != '')
                   OR (line_user_id_new IS NOT NULL AND line_user_id_new != '')
                ORDER BY id ASC";
    } else {
        echo "  · column line_user_id_new not found — using line_user_id only\n";
        $sql = "SELECT DISTINCT line_user_id AS uid
                FROM sys_users
                WHERE line_user_id IS NOT NULL AND line_user_id != ''
                ORDER BY id ASC";
    }
    if ($limit > 0) $sql .= " LIMIT " . $limit;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    echo "ERROR: query failed — " . $e->getMessage() . "\n";
    exit;
}

$total = count($rows);
echo "Found $total user(s) with line_user_id\n";

$ok = 0; $fail = 0; $skip = 0;
foreach ($rows as $i => $uid) {
    if (!$uid) { $skip++; continue; }

    try {
        if ($dryrun) {
            $ok++;
        } else {
            $r = line_richmenu_link_user((string)$uid, $ids['member']);
            $r['ok'] ? $ok++ : $fail++;
            line_richmenu_audit_log(
                (string)$uid,
                $r['ok'] ? 'sync_ok' : 'sync_failed',
                'member',
                $ids['member'],
                $r['ok'] ? null : $r['error'],
                'cron:sync_richmenu'
            );
        }
    } catch (Throwable $e) {
        // Don't let one user's failure abort the whole run
        $fail++;
        echo "  ✗ user " . substr((string)$uid, 0, 8) . "...: " . $e->getMessage() . "\n";
    }

    // กัน LINE rate limit (~50 req/sec) — pause สั้นๆ ทุก 50 รายการ
    if (($i + 1) % 50 === 0) {
        echo "  progress: " . ($i + 1) . " / $total\n";
        usleep(200000); // 0.2s
    }
}

$elapsed = round(microtime(true) - $startedAt, 2);
echo "─────────────────────────────────────\n";
echo "DONE in {$elapsed}s\n";
echo "  total:   $total\n";
echo "  success: $ok\n";
echo "  failed:  $fail\n";
echo "  skipped: $skip\n";

if (!$dryrun && function_exists('log_activity')) {
    @log_activity('LINE Rich Menu', "Cron sync_richmenu: total=$total ok=$ok fail=$fail (in {$elapsed}s)");
}
