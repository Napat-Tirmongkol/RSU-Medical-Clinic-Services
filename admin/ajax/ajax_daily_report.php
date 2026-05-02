<?php
// admin/ajax/ajax_daily_report.php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo    = db();
$action = $_GET['action'] ?? 'stats';
$date   = trim($_GET['date'] ?? date('Y-m-d'));
$cid    = (int)($_GET['campaign_id'] ?? 0); // 0 = ทุก campaign
$type   = in_array($_GET['type'] ?? '', ['all','on_schedule','early','no_show','cancelled'], true)
          ? $_GET['type'] : 'all';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid date']);
    exit;
}

$yesterday = date('Y-m-d', strtotime($date . ' -1 day'));

// คืน [sql_condition_string, params_array]
// ใช้ prefix :tw เพื่อหลีกเลี่ยง named param ซ้ำกับ query หลัก
function type_where(string $type, string $date): array
{
    switch ($type) {
        case 'on_schedule':
            return [
                "s.slot_date = :tw1 AND DATE(b.attended_at) = :tw2 AND b.status NOT IN ('cancelled','cancelled_by_admin')",
                [':tw1' => $date, ':tw2' => $date],
            ];
        case 'early':
            return [
                "DATE(b.attended_at) = :tw1 AND s.slot_date > :tw2",
                [':tw1' => $date, ':tw2' => $date],
            ];
        case 'no_show':
            return [
                "s.slot_date = :tw1 AND b.attended_at IS NULL AND b.status NOT IN ('cancelled','cancelled_by_admin')",
                [':tw1' => $date],
            ];
        case 'cancelled':
            return [
                "s.slot_date = :tw1 AND b.status IN ('cancelled','cancelled_by_admin')",
                [':tw1' => $date],
            ];
        default: // all
            return [
                "(s.slot_date = :tw1 OR (DATE(b.attended_at) = :tw2 AND s.slot_date > :tw3))",
                [':tw1' => $date, ':tw2' => $date, ':tw3' => $date],
            ];
    }
}

function fetch_stats(PDO $pdo, string $date, int $cid): array
{
    $cc = $cid > 0 ? ' AND b.campaign_id = :cid' : '';
    $sql = "
        SELECT
            SUM(CASE WHEN s.slot_date = :d1 AND DATE(b.attended_at) = :d2
                          AND b.status NOT IN ('cancelled','cancelled_by_admin') THEN 1 ELSE 0 END) AS on_schedule,
            SUM(CASE WHEN DATE(b.attended_at) = :d3 AND s.slot_date > :d4
                                                                                THEN 1 ELSE 0 END) AS early_arrival,
            SUM(CASE WHEN s.slot_date = :d5 AND b.attended_at IS NULL
                          AND b.status NOT IN ('cancelled','cancelled_by_admin') THEN 1 ELSE 0 END) AS no_show,
            SUM(CASE WHEN s.slot_date = :d6 AND b.status IN ('cancelled','cancelled_by_admin')
                                                                                THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN s.slot_date = :d7 AND b.status NOT IN ('cancelled','cancelled_by_admin')
                                                                                THEN 1 ELSE 0 END) AS total_scheduled
        FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE (s.slot_date = :d8 OR (DATE(b.attended_at) = :d9 AND s.slot_date > :d10)) $cc
    ";
    $p = [':d1'=>$date,':d2'=>$date,':d3'=>$date,':d4'=>$date,':d5'=>$date,
          ':d6'=>$date,':d7'=>$date,':d8'=>$date,':d9'=>$date,':d10'=>$date];
    if ($cid > 0) $p[':cid'] = $cid;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'on_schedule'     => (int)($r['on_schedule']    ?? 0),
        'early_arrival'   => (int)($r['early_arrival']  ?? 0),
        'no_show'         => (int)($r['no_show']        ?? 0),
        'cancelled_count' => (int)($r['cancelled_count']?? 0),
        'total_scheduled' => (int)($r['total_scheduled']?? 0),
    ];
}

// ── ACTION: stats ──────────────────────────────────────────────────────────
if ($action === 'stats') {
    $today_stats = fetch_stats($pdo, $date, $cid);
    $yest_stats  = fetch_stats($pdo, $yesterday, $cid);

    $total_came   = $today_stats['on_schedule'] + $today_stats['early_arrival'];
    $no_show_rate = $today_stats['total_scheduled'] > 0
        ? round($today_stats['no_show'] / $today_stats['total_scheduled'] * 100, 1) : 0;
    $early_rate   = $total_came > 0
        ? round($today_stats['early_arrival'] / $total_came * 100, 1) : 0;

    echo json_encode([
        'status'       => 'success',
        'date'         => $date,
        'today'        => $today_stats,
        'yesterday'    => $yest_stats,
        'no_show_rate' => $no_show_rate,
        'early_rate'   => $early_rate,
        'last_refresh' => date('H:i:s'),
    ]);
    exit;
}

// ── ACTION: slots ──────────────────────────────────────────────────────────
if ($action === 'slots') {
    $cc = $cid > 0 ? ' AND cs.campaign_id = :cid' : '';
    $sql = "
        SELECT
            cs.id,
            cs.start_time,
            cs.end_time,
            cs.max_capacity,
            cl.id    AS campaign_id,
            cl.title AS campaign_title,
            COUNT(b.id)                                                                           AS total_booked,
            SUM(CASE WHEN b.status IN ('cancelled','cancelled_by_admin')             THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN b.attended_at IS NOT NULL
                          AND b.status NOT IN ('cancelled','cancelled_by_admin')     THEN 1 ELSE 0 END) AS attended,
            SUM(CASE WHEN b.attended_at IS NULL
                          AND b.status NOT IN ('cancelled','cancelled_by_admin')     THEN 1 ELSE 0 END) AS pending
        FROM camp_slots cs
        JOIN camp_list cl ON cs.campaign_id = cl.id
        LEFT JOIN camp_bookings b ON b.slot_id = cs.id
        WHERE cs.slot_date = :date $cc
        GROUP BY cs.id, cs.start_time, cs.end_time, cs.max_capacity, cl.id, cl.title
        ORDER BY cs.start_time ASC, cl.title ASC
    ";
    $p = [':date' => $date];
    if ($cid > 0) $p[':cid'] = $cid;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'slots' => $slots]);
    exit;
}

// ── ACTION: list ───────────────────────────────────────────────────────────
if ($action === 'list') {
    $cc = $cid > 0 ? ' AND b.campaign_id = :cid' : '';
    [$tw_sql, $tw_p] = type_where($type, $date);
    $base_params = array_merge($tw_p, $cid > 0 ? [':cid' => $cid] : []);

    // count
    $count_sql = "
        SELECT COUNT(*) FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE $tw_sql $cc
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($base_params);
    $total  = (int)$stmt->fetchColumn();
    $pages  = max(1, (int)ceil($total / $per));
    $offset = ($page - 1) * $per;

    // list — CASE ใช้ :vt1–:vt4 แยกจาก :tw1–:tw3 เพื่อไม่ชนกัน
    $list_sql = "
        SELECT
            b.id,
            u.full_name,
            u.student_personnel_id,
            u.phone_number,
            cl.title    AS campaign_title,
            s.slot_date,
            s.start_time,
            s.end_time,
            b.attended_at,
            b.status,
            CASE
                WHEN b.status IN ('cancelled','cancelled_by_admin')         THEN 'cancelled'
                WHEN s.slot_date = :vt1 AND DATE(b.attended_at) = :vt2     THEN 'on_schedule'
                WHEN DATE(b.attended_at) = :vt3 AND s.slot_date > :vt4     THEN 'early'
                WHEN b.attended_at IS NULL                                  THEN 'no_show'
                ELSE 'other'
            END AS visit_type
        FROM camp_bookings b
        JOIN sys_users u   ON b.student_id  = u.id
        JOIN camp_slots s  ON b.slot_id     = s.id
        JOIN camp_list  cl ON b.campaign_id = cl.id
        WHERE $tw_sql $cc
        ORDER BY
            CASE WHEN b.attended_at IS NOT NULL THEN 0 ELSE 1 END,
            b.attended_at DESC,
            s.slot_date ASC,
            s.start_time ASC
        LIMIT :lim OFFSET :off
    ";

    $list_params = array_merge($base_params, [
        ':vt1' => $date, ':vt2' => $date, ':vt3' => $date, ':vt4' => $date,
    ]);

    $stmt = $pdo->prepare($list_sql);
    foreach ($list_params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $per, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'rows'   => $rows,
        'total'  => $total,
        'page'   => $page,
        'pages'  => $pages,
        'per'    => $per,
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
