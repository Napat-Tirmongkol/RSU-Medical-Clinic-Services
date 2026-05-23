<?php
/**
 * cron/edms_sla_tick.php
 * EDMS SLA — periodic tick (cron-driven)
 *
 * Schedule: ทุก 1 ชั่วโมง (crontab: "0 * * * *")
 *
 * Job:
 *   1. Warning detection — routing ที่ใกล้ breach (เหลือ ≤ warn_at_pct% ของเวลา) แต่ยัง on_track
 *      → mark sla_state='warning' + warned_at + notify assignee
 *   2. Breach detection — routing ที่ resolve_deadline_at < NOW() แต่ยังไม่ปิด + sla_state in (on_track,warning)
 *      → mark sla_state='breached' + breached_at + escalate (superadmin + dept_head)
 *   3. Auto-mark met — routing.status='done' แต่ sla_state ยังไม่ใช่ met/breached/cancelled
 *      → mark met (best-effort cleanup)
 *
 * Concurrency: advisory lock GET_LOCK('edms_sla_tick', 0)
 *
 * Setup:
 *   Crontab:  0 * * * * curl -s "https://yourdomain.com/cron/edms_sla_tick.php?token=YOUR_TOKEN" > /dev/null
 *   Or CLI:   php /path/to/cron/edms_sla_tick.php --token=YOUR_TOKEN
 */
declare(strict_types=1);

// ── Secret Token (กัน abuse) ─────────────────────────────────────────────
define('SLA_TICK_TOKEN', getenv('EDMS_SLA_CRON_TOKEN') ?: 'rsu_sla_tick_8a7f3k2m');

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    $opts = getopt('', ['token:']);
    $token = $opts['token'] ?? '';
} else {
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
}

if (!hash_equals(SLA_TICK_TOKEN, (string)$token)) {
    if ($isCli) { fwrite(STDERR, "Forbidden: invalid token\n"); exit(1); }
    http_response_code(403);
    exit('Forbidden');
}

// ── Bootstrap ─────────────────────────────────────────────────────────────
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config.php';
require_once $projectRoot . '/includes/edms_sla_helper.php';
require_once $projectRoot . '/includes/edms_sla_notify.php';

date_default_timezone_set('Asia/Bangkok');

$pdo = db();
$log = [];
$log[] = '[' . date('Y-m-d H:i:s') . '] SLA tick start';

// ── Advisory lock (กัน cron overlap) ─────────────────────────────────────
try {
    $lock = $pdo->query("SELECT GET_LOCK('edms_sla_tick', 0)")->fetchColumn();
    if ((int)$lock !== 1) {
        $log[] = 'Another instance is running — exit';
        echo implode("\n", $log) . "\n";
        exit(0);
    }
} catch (PDOException $e) {
    $log[] = '[WARN] GET_LOCK failed: ' . $e->getMessage();
}

$stats = ['warned' => 0, 'breached' => 0, 'auto_met' => 0, 'escalated' => 0];

// ── 1. WARNING DETECTION ──────────────────────────────────────────────────
// routing ที่:
//   - sla_state = 'on_track'
//   - warned_at IS NULL
//   - resolve_deadline_at IS NOT NULL (มี policy)
//   - status = 'pending' OR 'acknowledged' (ยังไม่ปิด)
//   - เวลาที่เหลือ <= warn_at_pct% ของช่วงเวลาทั้งหมด (start → deadline)
//
// คำนวณ:
//   total_secs   = TIMESTAMPDIFF(SECOND, created_at, resolve_deadline_at)
//   remain_secs  = TIMESTAMPDIFF(SECOND, NOW(), resolve_deadline_at)
//   threshold    = total_secs * warn_at_pct / 100
//   ต้อง warn ถ้า remain_secs <= threshold AND remain_secs > 0 (ยังไม่ breach)
try {
    $sql = "SELECT r.id, r.doc_id, r.created_at, r.resolve_deadline_at, p.warn_at_pct
        FROM sys_doc_routings r
        LEFT JOIN sys_doc_sla_policies p ON p.id = r.policy_id
        WHERE r.sla_state = 'on_track'
          AND r.warned_at IS NULL
          AND r.resolve_deadline_at IS NOT NULL
          AND r.status IN ('pending','acknowledged')
          AND TIMESTAMPDIFF(SECOND, NOW(), r.resolve_deadline_at) > 0
          AND TIMESTAMPDIFF(SECOND, NOW(), r.resolve_deadline_at) <=
              ROUND(TIMESTAMPDIFF(SECOND, r.created_at, r.resolve_deadline_at) * COALESCE(p.warn_at_pct, 20) / 100)
        LIMIT 500";
    $warns = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($warns as $w) {
        $rId = (int)$w['id'];
        if (sla_mark_warned($pdo, $rId)) {
            sla_notify_assignee($pdo, $rId, 'warning');
            $stats['warned']++;
        }
    }
    $log[] = "Warning detection: " . count($warns) . " candidates, " . $stats['warned'] . " marked";
} catch (Throwable $e) {
    $log[] = "[ERROR] warning detection: " . $e->getMessage();
}

// ── 2. BREACH DETECTION ───────────────────────────────────────────────────
// routing ที่:
//   - sla_state IN ('on_track','warning')
//   - resolve_deadline_at < NOW()
//   - status IN ('pending','acknowledged')
try {
    $sql = "SELECT id, doc_id FROM sys_doc_routings
        WHERE sla_state IN ('on_track','warning')
          AND resolve_deadline_at IS NOT NULL
          AND resolve_deadline_at < NOW()
          AND status IN ('pending','acknowledged')
        LIMIT 500";
    $breaches = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($breaches as $b) {
        $rId = (int)$b['id'];
        if (sla_mark_breached($pdo, $rId)) {
            sla_notify_assignee($pdo, $rId, 'breached');
            if (sla_escalate($pdo, $rId)) $stats['escalated']++;
            $stats['breached']++;
        }
    }
    $log[] = "Breach detection: " . count($breaches) . " candidates, " . $stats['breached'] . " marked, " . $stats['escalated'] . " escalated";
} catch (Throwable $e) {
    $log[] = "[ERROR] breach detection: " . $e->getMessage();
}

// ── 3. AUTO-MARK MET (cleanup) ───────────────────────────────────────────
// routing ที่ปิดแล้ว (done) แต่ยังไม่ stamp met_at
try {
    $rs = $pdo->query("SELECT id, doc_id FROM sys_doc_routings
        WHERE status = 'done'
          AND met_at IS NULL
          AND sla_state NOT IN ('cancelled','none')
        LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rs as $r) {
        $rId = (int)$r['id'];
        if (sla_mark_met($pdo, $rId, null)) $stats['auto_met']++;
    }
    $log[] = "Auto-mark met: " . $stats['auto_met'];
} catch (Throwable $e) {
    $log[] = "[ERROR] auto-mark met: " . $e->getMessage();
}

// ── Release lock + summary ────────────────────────────────────────────────
try {
    $pdo->query("SELECT RELEASE_LOCK('edms_sla_tick')")->fetchColumn();
} catch (PDOException) {}

$log[] = '[' . date('Y-m-d H:i:s') . '] SLA tick end · stats: ' . json_encode($stats, JSON_UNESCAPED_UNICODE);

// Output
if ($isCli) {
    echo implode("\n", $log) . "\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", $log) . "\n";
}
