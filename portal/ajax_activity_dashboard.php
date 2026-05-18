<?php
/**
 * portal/ajax_activity_dashboard.php
 * Data endpoint for the Activity Dashboard partial.
 *
 * Actions (POST or GET):
 *   snapshot   — KPI cards + 24h timeline + top admins + top actions + 30d heatmap + recent feed
 *   tick       — lightweight delta poll (last N events since :since_id)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$adminRole = $_SESSION['admin_role'] ?? '';
if ($adminRole !== 'superadmin') {
    echo json_encode(['ok' => false, 'message' => 'Permission denied — superadmin only']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'snapshot';

try {
    $pdo = db();

    // Map raw action codes → readable category labels (icon + color)
    $catMap = [
        'auth'      => ['label' => 'Authentication', 'color' => '#0ea5e9', 'icon' => 'fa-key',         'match' => ['Login','Logout','Password Reset Requested','Password Reset Successful']],
        'identity'  => ['label' => 'Identity & Access','color' => '#8b5cf6', 'icon' => 'fa-user-shield', 'match' => ['Identity Governance','Updated User Profile','Deleted Admin','Deleted Staff','Added Position','Updated Position','Deleted Position']],
        'booking'   => ['label' => 'Bookings',       'color' => '#10b981', 'icon' => 'fa-calendar-check','match' => ['New Booking','Cancel Booking','cancel_booking','approve_booking','bulk_cancel_bookings']],
        'campaign'  => ['label' => 'Campaigns',      'color' => '#06b6d4', 'icon' => 'fa-bullhorn',    'match' => ['create_campaign','delete_campaign','add_slot','edit_slot','delete_slot']],
        'insurance' => ['label' => 'Insurance',      'color' => '#f59e0b', 'icon' => 'fa-shield-heart','match_prefix' => 'insurance'],
        'announce'  => ['label' => 'Announcements',  'color' => '#ec4899', 'icon' => 'fa-volume-high', 'match_prefix' => 'Announcement'],
        'system'    => ['label' => 'System',         'color' => '#64748b', 'icon' => 'fa-gear',        'match' => ['Maintenance Toggle','Maintenance Announcement','Maintenance Whitelist','LINE Rich Menu','Migrate-LINE-Collision','Migrate-LINE-Success','clinic_data','Debug Action']],
        'other'     => ['label' => 'Other',          'color' => '#94a3b8', 'icon' => 'fa-ellipsis',    'match' => []],
    ];

    $classify = function (string $action) use ($catMap): string {
        foreach ($catMap as $key => $def) {
            if ($key === 'other') continue;
            if (!empty($def['match']) && in_array($action, $def['match'], true)) return $key;
            if (!empty($def['match_prefix']) && stripos($action, (string)$def['match_prefix']) === 0) return $key;
        }
        return 'other';
    };

    if ($action === 'tick') {
        // Lightweight poll — return events newer than :since_id, capped at 30
        $sinceId = max(0, (int)($_POST['since_id'] ?? $_GET['since_id'] ?? 0));
        $stmt = $pdo->prepare("
            SELECT a.id, a.user_id, a.action, a.description, a.ip_address, a.timestamp,
                   COALESCE(s.full_name, ad.full_name, 'System') AS actor_name
            FROM sys_activity_logs a
            LEFT JOIN sys_admins ad ON ad.id = a.user_id
            LEFT JOIN sys_staff  s  ON s.id  = a.user_id
            WHERE a.id > :since
            ORDER BY a.id ASC
            LIMIT 30
        ");
        $stmt->execute([':since' => $sinceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(function ($r) use ($classify, $catMap) {
            $cat = $classify((string)$r['action']);
            return [
                'id'        => (int)$r['id'],
                'actor'     => (string)($r['actor_name'] ?? 'System'),
                'action'    => (string)$r['action'],
                'desc'      => (string)($r['description'] ?? ''),
                'ip'        => (string)($r['ip_address'] ?? ''),
                'timestamp' => (string)$r['timestamp'],
                'cat'       => $cat,
                'cat_label' => $catMap[$cat]['label'],
                'cat_color' => $catMap[$cat]['color'],
                'cat_icon'  => $catMap[$cat]['icon'],
            ];
        }, $rows);

        $latestId = !empty($items) ? max(array_column($items, 'id')) : $sinceId;
        echo json_encode(['ok' => true, 'items' => $items, 'latest_id' => $latestId], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ─── SNAPSHOT ──────────────────────────────────────────────────────────
    if ($action !== 'snapshot') {
        echo json_encode(['ok' => false, 'message' => 'Unknown action']);
        exit;
    }

    $today = date('Y-m-d');

    // KPI #1: total actions today
    $stmt = $pdo->query("SELECT COUNT(*) FROM sys_activity_logs WHERE DATE(timestamp) = CURDATE()");
    $kpi_today = (int)$stmt->fetchColumn();

    // KPI #2: actions yesterday (for trend)
    $stmt = $pdo->query("SELECT COUNT(*) FROM sys_activity_logs WHERE DATE(timestamp) = CURDATE() - INTERVAL 1 DAY");
    $kpi_yesterday = (int)$stmt->fetchColumn();
    $kpi_today_delta_pct = $kpi_yesterday > 0
        ? round((($kpi_today - $kpi_yesterday) / $kpi_yesterday) * 100)
        : ($kpi_today > 0 ? 100 : 0);

    // KPI #3: distinct active admins (last 24h)
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM sys_activity_logs
                         WHERE timestamp >= NOW() - INTERVAL 24 HOUR AND user_id IS NOT NULL");
    $kpi_active_admins = (int)$stmt->fetchColumn();

    // KPI #4: total all-time
    $stmt = $pdo->query("SELECT COUNT(*) FROM sys_activity_logs");
    $kpi_total = (int)$stmt->fetchColumn();

    // KPI #5: peak hour today (HH:00)
    $stmt = $pdo->query("SELECT HOUR(timestamp) h, COUNT(*) c FROM sys_activity_logs
                         WHERE DATE(timestamp) = CURDATE()
                         GROUP BY HOUR(timestamp) ORDER BY c DESC LIMIT 1");
    $peakRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $kpi_peak_hour = $peakRow ? sprintf('%02d:00', (int)$peakRow['h']) : '—';
    $kpi_peak_count = $peakRow ? (int)$peakRow['c'] : 0;

    // 24h timeline — bucketed per hour (last 24h rolling)
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(timestamp, '%Y-%m-%d %H:00') AS hh, COUNT(*) c
        FROM sys_activity_logs
        WHERE timestamp >= NOW() - INTERVAL 24 HOUR
        GROUP BY hh
        ORDER BY hh ASC
    ");
    $rowsHourly = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // fill in zeros for missing hours
    $hourly = [];
    $cursor = new DateTime('-23 hours');
    $cursor->setTime((int)$cursor->format('H'), 0, 0);
    $lookup = [];
    foreach ($rowsHourly as $r) $lookup[$r['hh']] = (int)$r['c'];
    for ($i = 0; $i < 24; $i++) {
        $key = $cursor->format('Y-m-d H:i');
        $hourly[] = [
            'label' => $cursor->format('H:00'),
            'count' => $lookup[$key] ?? 0,
        ];
        $cursor->modify('+1 hour');
    }

    // Top admins (7d)
    $stmt = $pdo->query("
        SELECT a.user_id,
               COALESCE(s.full_name, ad.full_name, CONCAT('User #', a.user_id)) AS name,
               COUNT(*) c
        FROM sys_activity_logs a
        LEFT JOIN sys_admins ad ON ad.id = a.user_id
        LEFT JOIN sys_staff  s  ON s.id  = a.user_id
        WHERE a.timestamp >= NOW() - INTERVAL 7 DAY AND a.user_id IS NOT NULL
        GROUP BY a.user_id, name
        ORDER BY c DESC
        LIMIT 8
    ");
    $topAdmins = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Action breakdown (7d, by category)
    $stmt = $pdo->query("
        SELECT action, COUNT(*) c
        FROM sys_activity_logs
        WHERE timestamp >= NOW() - INTERVAL 7 DAY
        GROUP BY action
    ");
    $byAction = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $catCounts = [];
    foreach ($catMap as $k => $_) $catCounts[$k] = 0;
    foreach ($byAction as $r) {
        $cat = $classify((string)$r['action']);
        $catCounts[$cat] += (int)$r['c'];
    }
    $catChart = [];
    foreach ($catCounts as $k => $c) {
        if ($c <= 0) continue;
        $catChart[] = [
            'key'   => $k,
            'label' => $catMap[$k]['label'],
            'color' => $catMap[$k]['color'],
            'icon'  => $catMap[$k]['icon'],
            'count' => $c,
        ];
    }
    usort($catChart, fn($a, $b) => $b['count'] <=> $a['count']);

    // 30-day heatmap (DOW × hour)
    $stmt = $pdo->query("
        SELECT DAYOFWEEK(timestamp) - 1 AS dow, HOUR(timestamp) AS h, COUNT(*) AS c
        FROM sys_activity_logs
        WHERE timestamp >= NOW() - INTERVAL 30 DAY
        GROUP BY dow, h
    ");
    $heatRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $heat = array_fill(0, 7, array_fill(0, 24, 0));
    $heatMax = 0;
    foreach ($heatRaw as $r) {
        $d = (int)$r['dow']; $h = (int)$r['h']; $c = (int)$r['c'];
        if ($d >= 0 && $d < 7 && $h >= 0 && $h < 24) {
            $heat[$d][$h] = $c;
            if ($c > $heatMax) $heatMax = $c;
        }
    }

    // Recent feed (last 15)
    $stmt = $pdo->query("
        SELECT a.id, a.user_id, a.action, a.description, a.ip_address, a.timestamp,
               COALESCE(s.full_name, ad.full_name, 'System') AS actor_name
        FROM sys_activity_logs a
        LEFT JOIN sys_admins ad ON ad.id = a.user_id
        LEFT JOIN sys_staff  s  ON s.id  = a.user_id
        ORDER BY a.id DESC
        LIMIT 15
    ");
    $recentRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $recent = array_map(function ($r) use ($classify, $catMap) {
        $cat = $classify((string)$r['action']);
        return [
            'id'        => (int)$r['id'],
            'actor'     => (string)($r['actor_name'] ?? 'System'),
            'action'    => (string)$r['action'],
            'desc'      => (string)($r['description'] ?? ''),
            'ip'        => (string)($r['ip_address'] ?? ''),
            'timestamp' => (string)$r['timestamp'],
            'cat'       => $cat,
            'cat_label' => $catMap[$cat]['label'],
            'cat_color' => $catMap[$cat]['color'],
            'cat_icon'  => $catMap[$cat]['icon'],
        ];
    }, $recentRows);
    $latestId = !empty($recent) ? $recent[0]['id'] : 0;

    echo json_encode([
        'ok' => true,
        'ts' => time(),
        'kpi' => [
            'today'           => $kpi_today,
            'today_delta_pct' => $kpi_today_delta_pct,
            'yesterday'       => $kpi_yesterday,
            'active_admins'   => $kpi_active_admins,
            'total'           => $kpi_total,
            'peak_hour'       => $kpi_peak_hour,
            'peak_count'      => $kpi_peak_count,
        ],
        'hourly'      => $hourly,
        'top_admins'  => $topAdmins,
        'categories'  => $catChart,
        'heatmap'     => ['data' => $heat, 'max' => $heatMax],
        'recent'      => $recent,
        'latest_id'   => $latestId,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ajax_activity_dashboard] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
