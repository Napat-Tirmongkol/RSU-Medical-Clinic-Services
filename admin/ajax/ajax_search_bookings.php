<?php
declare(strict_types=1);
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
$dateFrom   = $_POST['date_from'] ?? date('Y-m-d', strtotime('-3 months'));
$dateTo     = $_POST['date_to']   ?? date('Y-m-d', strtotime('+3 months'));
$page       = max(1, (int)($_POST['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d', strtotime('-3 months'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d', strtotime('+3 months'));

$where  = "s.slot_date BETWEEN :start AND :end AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin')";
$params = [':start' => $dateFrom, ':end' => $dateTo];

if ($q !== '') {
    // Native prepared statements (EMULATE_PREPARES=false) forbid duplicate named params —
    // use :q1/:q2/:q3 instead of :q three times
    $where .= " AND (u.full_name LIKE :q1 OR u.student_personnel_id LIKE :q2 OR c.title LIKE :q3)";
    $like = '%' . $q . '%';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $like;
}

if ($status === 'cancelled') {
    $where .= " AND b.status IN ('cancelled','cancelled_by_admin')";
} elseif ($status === 'completed') {
    // รวม QR check-in (attended_at set แต่ status อาจยังเป็น confirmed) + staff check-in (status = completed)
    $where .= " AND (b.status = 'completed' OR (b.attended_at IS NOT NULL AND b.status NOT IN ('cancelled','cancelled_by_admin')))";
} elseif ($status === 'confirmed') {
    // confirmed ที่ยังไม่ได้เช็คอิน
    $where .= " AND b.status = 'confirmed' AND b.attended_at IS NULL";
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
    $kpiParams = [':kstart' => $dateFrom, ':kend' => $dateTo];
    $kpiWhere  = 's.slot_date BETWEEN :kstart AND :kend';
    if ($campaignId > 0) {
        $kpiWhere .= ' AND b.campaign_id = :kcid';
        $kpiParams[':kcid'] = $campaignId;
    }
    $kpiStmt = $pdo->prepare("
        SELECT
            SUM(b.status = 'booked')                                                                             AS pending,
            SUM(b.status = 'confirmed' AND b.attended_at IS NULL)                                               AS confirmed,
            SUM(b.status = 'completed' OR (b.attended_at IS NOT NULL AND b.status NOT IN ('cancelled','cancelled_by_admin'))) AS completed,
            SUM(b.status IN ('cancelled','cancelled_by_admin'))                                                  AS cancelled
        FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE $kpiWhere
    ");
    $kpiStmt->execute($kpiParams);
    $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

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
    log_error_to_db('PDOException: ' . $e->getMessage(), 'error', 'ajax_search_bookings.php', $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในระบบ']);
}
