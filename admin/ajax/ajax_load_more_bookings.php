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

$offset  = max(0, (int)($_POST['offset']  ?? 0));
$year    = (int)($_POST['year']   ?? date('Y'));
$month   = (int)($_POST['month']  ?? date('m'));
$perPage = 25;

if ($month < 1 || $month > 12) { $month = (int)date('m'); }
$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));

try {
    $pdo = db();

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
        WHERE s.slot_date BETWEEN :start AND :end
          AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin')
        ORDER BY
            CASE WHEN b.status = 'booked' THEN 0 ELSE 1 END,
            s.slot_date ASC, s.start_time ASC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':start', $startDate);
    $stmt->bindValue(':end',   $endDate);
    $stmt->bindValue(':lim',   $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off',   $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE s.slot_date BETWEEN :start AND :end
          AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin')
    ");
    $cntStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $total = (int)$cntStmt->fetchColumn();

    $newLoaded = $offset + count($bookings);

    echo json_encode([
        'success' => true,
        'html'    => render_booking_rows($bookings),
        'loaded'  => $newLoaded,
        'total'   => $total,
        'hasMore' => $newLoaded < $total,
    ]);

} catch (PDOException $e) {
    error_log('ajax_load_more_bookings: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในระบบฐานข้อมูล']);
}
