<?php
/**
 * portal/sse_stats.php — Server-Sent Events endpoint
 * Pushes live KPI stats + recent activity to the dashboard every 15s.
 */
declare(strict_types=1);

// ── Auth (session must already be started) ───────────────────────────────────
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/../config.php';

// ── SSE Headers ───────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');   // nginx: disable proxy buffering
header('Connection: keep-alive');

// Disable PHP's own output buffering layers
if (ob_get_level()) ob_end_clean();
set_time_limit(0);

// ── Helper: send one SSE frame ────────────────────────────────────────────────
function sseEmit(string $event, array $payload): void {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ── Helper: collect stats from DB ─────────────────────────────────────────────
function collectStats(): array {
    try {
        $pdo = db();

        $users = (int)$pdo->query("SELECT COUNT(*) FROM sys_users")->fetchColumn();
        $camps = (int)$pdo->query("SELECT COUNT(*) FROM camp_list WHERE status = 'active'")->fetchColumn();

        $borrows = 0;
        if ($pdo->query("SHOW TABLES LIKE 'borrow_records'")->rowCount() > 0) {
            $borrows = (int)$pdo->query(
                "SELECT COUNT(*) FROM borrow_records WHERE approval_status = 'pending'"
            )->fetchColumn();
        }

        $activity = [];
        try {
            $activity = $pdo->query(
                "SELECT l.action, l.description, l.timestamp,
                        COALESCE(a.full_name, 'System') AS admin_name
                 FROM   sys_activity_logs l
                 LEFT   JOIN sys_admins a ON l.user_id = a.id
                 ORDER  BY l.timestamp DESC
                 LIMIT  5"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { /* activity_logs might not exist */ }

        return [
            'ok'       => true,
            'users'    => $users,
            'camps'    => $camps,
            'borrows'  => $borrows,
            'activity' => $activity,
            'ts'       => time(),
        ];

    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Server error', 'ts' => time()];
    }
}

// ── Main loop ─────────────────────────────────────────────────────────────────
$interval = 15; // seconds between pushes

while (true) {
    if (connection_aborted()) break;

    sseEmit('stats', collectStats());

    // Sleep in 1-second chunks so we detect disconnects quickly
    for ($i = 0; $i < $interval; $i++) {
        if (connection_aborted()) break 2;
        sleep(1);
    }
}
