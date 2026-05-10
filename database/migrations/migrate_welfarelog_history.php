<?php
/**
 * database/migrations/migrate_welfarelog_history.php
 *
 * Migrate audit log จาก welfarelog เก่า → gold_card_history
 * รันหลัง migrate_welfarecard_legacy_data.php เสร็จ
 *
 * Mapping:
 *  welfarelog.pid       → gold_card_members.citizen_id (resolve member_id)
 *  welfarelog.action    → gold_card_history.action
 *  welfarelog.oldvalue  → gold_card_history.old_value
 *  welfarelog.newvalue  → gold_card_history.new_value
 *  welfarelog.uid       → gold_card_history.changed_by (admin_id หรือ NULL)
 *  welfarelog.logdate   → gold_card_history.changed_at
 *  welfarelog.ip        → gold_card_history.ip_address
 *
 * Features:
 *  ✓ Resumable      — track ด้วย legacy log_id (ดูจาก action prefix "legacy:LOG_ID:")
 *  ✓ Batch          — 500 rows ต่อรอบ
 *  ✓ Dry-run        — ?dry=1
 *  ✓ Tolerant       — pid ไม่ match → log แต่ insert เป็น member_id NULL
 *  ✓ Auth           — superadmin only
 *
 * Usage:
 *   ?dry=1            ทดสอบ
 *   (no params)       รันจริง
 *   ?reset=1          ลบ history เก่าที่มาจาก legacy ก่อน (action LIKE 'legacy:%')
 *   ?limit=1000       จำกัด (test)
 */
declare(strict_types=1);

set_time_limit(0);
ignore_user_abort(true);
@ini_set('memory_limit', '256M');
@ini_set('output_buffering', 'off');
@ob_implicit_flush(true);
while (ob_get_level()) ob_end_flush();

require_once __DIR__ . '/../../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    die('❌ Migration นี้รันได้เฉพาะ superadmin');
}

$pdo     = db();
$dryRun  = !empty($_GET['dry']);
$reset   = !empty($_GET['reset']);
$limit   = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 0;

$BATCH = 500;

function table_exists(PDO $pdo, string $name): bool {
    try { $pdo->query("SELECT 1 FROM `$name` LIMIT 1"); return true; }
    catch (PDOException $e) { return false; }
}
function get_columns(PDO $pdo, string $table): array {
    return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
}
function pick_col(array $available, array $candidates): ?string {
    foreach ($candidates as $c) if (in_array($c, $available, true)) return $c;
    return null;
}
function normalize_datetime(?string $d): ?string {
    if (!$d) return null;
    $d = trim($d);
    if ($d === '' || str_starts_with($d, '0000-')) return null;
    $ts = strtotime($d);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>Migrate Welfarelog → Gold Card History</title>
    <style>
        body { font-family: 'Sarabun', -apple-system, sans-serif; max-width: 1100px; margin: 20px auto; padding: 0 20px; background: #f8fafc; color: #1e293b; }
        h2 { margin-bottom: 4px; }
        .subtitle { color: #64748b; margin-bottom: 16px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; margin-right: 8px; }
        .badge.dry { background: #fef3c7; color: #b45309; }
        .badge.live { background: #dcfce7; color: #15803d; }
        .badge.reset { background: #fee2e2; color: #b91c1c; }
        .log { background: #0f172a; color: #e2e8f0; padding: 16px 20px; border-radius: 10px; font-family: 'SF Mono', monospace; font-size: 12.5px; line-height: 1.55; height: 480px; overflow-y: auto; white-space: pre-wrap; }
        .log .ok    { color: #4ade80; }
        .log .warn  { color: #fbbf24; }
        .log .err   { color: #f87171; }
        .log .info  { color: #60a5fa; }
        .log .dim   { color: #64748b; }
        .summary { background: white; padding: 20px 24px; border-radius: 10px; margin-top: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 16px; margin-top: 16px; }
        .stat { padding: 12px 16px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #6366f1; }
        .stat-num { font-size: 22px; font-weight: 700; color: #6366f1; }
        .stat-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
    </style>
</head>
<body>
    <h2>📜 Migrate Welfarelog → Gold Card History</h2>
    <p class="subtitle">โอนย้าย audit log จากระบบเก่า → gold_card_history</p>
    <div>
        <?php if ($dryRun): ?><span class="badge dry">⚠ DRY-RUN</span><?php else: ?><span class="badge live">🔥 LIVE</span><?php endif; ?>
        <?php if ($reset): ?><span class="badge reset">🗑 RESET</span><?php endif; ?>
        <?php if ($limit): ?><span class="badge dry">📏 LIMIT <?= $limit ?></span><?php endif; ?>
    </div>
    <div class="log"><?php

flush();
$startTime = microtime(true);

echo "[" . date('H:i:s') . "] เริ่ม...\n";

if (!table_exists($pdo, 'welfarelog')) {
    echo "<span class='err'>❌ ตาราง `welfarelog` ไม่พบ — กรุณา import welfarelog.sql ก่อน</span>";
    echo "</div></body></html>";
    exit;
}
echo "<span class='ok'>✓ พบ welfarelog</span>\n";

// Reset
if ($reset && !$dryRun) {
    echo "\n<span class='warn'>🗑️  ลบ gold_card_history ที่ action LIKE 'legacy:%'</span>\n";
    $del = $pdo->exec("DELETE FROM gold_card_history WHERE action LIKE 'legacy:%'");
    echo "<span class='warn'>   → ลบไป $del rows</span>\n";
}

// Detect schema
$wlCols = get_columns($pdo, 'welfarelog');
echo "\n<span class='info'>📋 welfarelog columns: " . implode(', ', $wlCols) . "</span>\n";

$colMap = [
    'pid'      => pick_col($wlCols, ['pid', 'citizen_id', 'cid']),
    'action'   => pick_col($wlCols, ['action', 'event', 'type']),
    'oldval'   => pick_col($wlCols, ['oldvalue', 'old_value', 'oldval', 'before']),
    'newval'   => pick_col($wlCols, ['newvalue', 'new_value', 'newval', 'after']),
    'uid'      => pick_col($wlCols, ['uid', 'user_id', 'admin_id', 'changed_by']),
    'logdate'  => pick_col($wlCols, ['logdate', 'log_date', 'created_at', 'date', 'timestamp']),
    'ip'       => pick_col($wlCols, ['ip', 'ip_address', 'remote_addr']),
];

echo "<span class='info'>🗺️  Column mapping:</span>\n";
foreach ($colMap as $logical => $actual) {
    $sym = $actual ? "<span class='ok'>✓</span>" : "<span class='dim'>—</span>";
    echo "   $sym $logical → " . ($actual ?? '(not found)') . "\n";
}

if (!$colMap['pid']) {
    echo "<span class='err'>\n❌ ขาด pid column — abort</span>";
    echo "</div></body></html>";
    exit;
}

// Resume support: ดูว่ามี legacy log_id ไหนไปแล้ว
$alreadyMigrated = [];
try {
    $rs = $pdo->query("SELECT old_value FROM gold_card_history WHERE action LIKE 'legacy:%log_id=%'");
    foreach ($rs as $r) {
        if (preg_match('/log_id=(\d+)/', (string)$r['old_value'], $m)) {
            $alreadyMigrated[(int)$m[1]] = true;
        }
    }
} catch (PDOException $e) {}
echo "<span class='info'>♻️  Resume: skip " . count($alreadyMigrated) . " logs ที่ migrate แล้ว</span>\n";

// Total
$total = (int)$pdo->query("SELECT COUNT(*) FROM welfarelog")->fetchColumn();
echo "<span class='info'>📊 Total welfarelog: $total</span>\n\n";

// Build SELECT
$selectFields = ['id'];
foreach ($colMap as $logical => $actual) {
    if ($actual) $selectFields[] = "`$actual` AS `c_$logical`";
}
$selectSql = "SELECT " . implode(', ', $selectFields) . " FROM welfarelog ORDER BY id ASC LIMIT ? OFFSET ?";
$stmtSelect = $pdo->prepare($selectSql);

// Match pid → member_id
$stmtMatch = $pdo->prepare("SELECT id FROM gold_card_members WHERE citizen_id = ? LIMIT 1");

// Insert
$stmtInsert = $pdo->prepare("
    INSERT INTO gold_card_history (member_id, action, old_value, new_value, changed_by, changed_at, ip_address)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$stats = [
    'processed' => 0,
    'inserted'  => 0,
    'skipped'   => 0,
    'no_member' => 0,
    'errors'    => 0,
];

$offset = 0;
$pidToMemberCache = [];

while (true) {
    if ($limit && $stats['processed'] >= $limit) break;

    $stmtSelect->execute([$BATCH, $offset]);
    $rows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) break;

    foreach ($rows as $row) {
        if ($limit && $stats['processed'] >= $limit) break 2;
        $stats['processed']++;

        $logId = (int)$row['id'];
        if (isset($alreadyMigrated[$logId])) {
            $stats['skipped']++;
            continue;
        }

        try {
            $pid = trim((string)($row['c_pid'] ?? ''));
            $action = trim((string)($row['c_action'] ?? 'unknown'));
            $oldVal = (string)($row['c_oldval'] ?? '');
            $newVal = (string)($row['c_newval'] ?? '');
            $uid    = (int)($row['c_uid'] ?? 0);
            $date   = normalize_datetime($row['c_logdate'] ?? null) ?? date('Y-m-d H:i:s');
            $ip     = trim((string)($row['c_ip'] ?? ''));

            // Resolve member_id
            $memberId = null;
            if ($pid !== '') {
                if (!array_key_exists($pid, $pidToMemberCache)) {
                    $stmtMatch->execute([$pid]);
                    $pidToMemberCache[$pid] = $stmtMatch->fetchColumn() ?: null;
                }
                $memberId = $pidToMemberCache[$pid] ?: null;
            }
            if (!$memberId) $stats['no_member']++;

            // Action prefix carries legacy log_id for resume
            $actionTagged = "legacy:" . $action;
            $oldValTagged = "log_id=$logId; pid=$pid\n" . $oldVal;

            if (!$dryRun) {
                $stmtInsert->execute([
                    $memberId,
                    mb_substr($actionTagged, 0, 50),
                    $oldValTagged,
                    $newVal,
                    $uid > 0 ? $uid : null,
                    $date,
                    $ip ?: null,
                ]);
            }
            $stats['inserted']++;
        } catch (Throwable $e) {
            $stats['errors']++;
            if ($stats['errors'] <= 5) {
                echo "<span class='err'>  ✗ log_id=$logId: " . htmlspecialchars($e->getMessage()) . "</span>\n";
            }
        }
    }

    $pct = $total > 0 ? round(($stats['processed'] / min($total, $limit ?: $total)) * 100, 1) : 100;
    $elapsed = microtime(true) - $startTime;
    $rate = $elapsed > 0 ? round($stats['processed'] / $elapsed, 1) : 0;
    echo sprintf(
        "<span class='info'>[%s] offset=%d processed=%d (%.1f%%) | inserted=%d skipped=%d | no_member=%d errors=%d | %.1f rows/s</span>\n",
        date('H:i:s'), $offset, $stats['processed'], $pct,
        $stats['inserted'], $stats['skipped'], $stats['no_member'], $stats['errors'], $rate
    );
    flush();

    $offset += $BATCH;
}

$elapsed = microtime(true) - $startTime;
echo "\n<span class='ok'>✅ เสร็จสิ้น — ใช้เวลา " . round($elapsed, 1) . "s</span>";

?></div>

<div class="summary">
    <h3 style="margin-top:0">📊 สรุปผล</h3>
    <div class="stats">
        <div class="stat"><div class="stat-num"><?= number_format($stats['processed']) ?></div><div class="stat-label">Processed</div></div>
        <div class="stat"><div class="stat-num"><?= number_format($stats['inserted']) ?></div><div class="stat-label">Inserted</div></div>
        <div class="stat"><div class="stat-num"><?= number_format($stats['skipped']) ?></div><div class="stat-label">Skipped</div></div>
        <div class="stat"><div class="stat-num" style="color:#f59e0b"><?= number_format($stats['no_member']) ?></div><div class="stat-label">No member match</div></div>
        <div class="stat"><div class="stat-num" style="color:#ef4444"><?= number_format($stats['errors']) ?></div><div class="stat-label">Errors</div></div>
    </div>

    <?php if ($dryRun): ?>
        <p style="margin-top:20px; padding: 12px 16px; background: #fef3c7; border-radius:8px; border-left: 3px solid #f59e0b;">
            <strong>💡 Dry-run:</strong> ไม่เขียน DB จริง — ลบ <code>?dry=1</code> เพื่อรันจริง
        </p>
    <?php else: ?>
        <p style="margin-top:20px; padding: 12px 16px; background: #dcfce7; border-radius:8px; border-left: 3px solid #22c55e;">
            <strong>✅ Migration ครบทั้ง 3 ขั้น:</strong> 1) schema 2) data 3) history — <strong>โปรดลบ migration files + welfarecard_old folder ออก</strong>
        </p>
    <?php endif; ?>
</div>

</body>
</html>
