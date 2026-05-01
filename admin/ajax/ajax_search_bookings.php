<?php
declare(strict_types=1);

// Catch ALL PHP errors and return as JSON (debug helper)
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success' => false, 'debug_error' => "$errstr in $errfile:$errline"]);
    exit;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'debug_fatal' => "{$e['message']} in {$e['file']}:{$e['line']}"]);
    }
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking_row.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

validate_csrf_or_die();

$q          = trim($_POST['q'] ?? '');
$status     = $_POST['status'] ?? 'all';
$campaignId = (int)($_POST['campaign_id'] ?? 0);
$dateFrom   = $_POST['date_from'] ?? date('Y-m-01');
$dateTo     = $_POST['date_to']   ?? date('Y-m-t');
$page       = max(1, (int)($_POST['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-t');

// Base where (always applied)
$where  = "s.slot_date BETWEEN :start AND :end AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin')";
$params = [':start' => $dateFrom, ':end' => $dateTo];

if ($q !== '') {
    $where .= " AND (u.full_name LIKE :q OR u.student_personnel_id LIKE :q OR c.title LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

if ($status === 'cancelled') {
    $where .= " AND b.status IN ('cancelled','cancelled_by_admin')";
} elseif ($status !== 'all') {
    $where .= " AND b.status = :status";
    $params[':status'] = $status;
}

if ($campaignId > 0) {
    $where .= " AND b.campaign_id = :campaign_id";
    $params[':campaign_id'] = $campaignId;
}

try {
    $pdo = db();

    // KPI counts scoped to date+campaign only (not affected by search/status tab)
    $kpiParams = [':start' => $dateFrom, ':end' => $dateTo];
    $kpiWhere  = 's.slot_date BETWEEN :start AND :end';
    if ($campaignId > 0) {
        $kpiWhere .= ' AND b.campaign_id = :campaign_id';
        $kpiParams[':campaign_id'] = $campaignId;
    }
    $kpiStmt = $pdo->prepare("
        SELECT
            SUM(b.status = 'booked')                               AS pending,
            SUM(b.status = 'confirmed')                            AS confirmed,
            SUM(b.status = 'completed')                            AS completed,
            SUM(b.status IN ('cancelled','cancelled_by_admin'))    AS cancelled
        FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE $kpiWhere
    ");
    $kpiStmt->execute($kpiParams);
    $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

    // Total count for current filters
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM camp_bookings b
        JOIN sys_users u  ON b.student_id  = u.id
        JOIN camp_slots s ON b.slot_id     = s.id
        JOIN camp_list c  ON b.campaign_id = c.id
        WHERE $where
    ");
    $cntStmt->execute($params);
    $total      = (int)$cntStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));

    $stmt = $pdo->prepare("
        SELECT
            b.id AS booking_id, b.status, b.created_at, b.campaign_id,
            u.full_name, u.student_personnel_id, u.phone_number,
            s.slot_date, s.start_time, s.end_time,
            c.title AS campaign_title
        FROM camp_bookings b
        JOIN sys_users u  ON b.student_id  = u.id
        JOIN camp_slots s ON b.slot_id     = s.id
        JOIN camp_list c  ON b.campaign_id = c.id
        WHERE $where
        ORDER BY
            CASE WHEN b.status = 'booked' THEN 0 ELSE 1 END,
            s.slot_date ASC, s.start_time ASC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'html'        => render_booking_rows($bookings),
        'total'       => $total,
        'page'        => $page,
        'total_pages' => $totalPages,
        'kpi'         => $kpi,
    ]);

} catch (PDOException $e) {
    error_log('ajax_search_bookings: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในระบบ', 'debug_pdo' => $e->getMessage()]);
}
