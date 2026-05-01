<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

validate_csrf_or_die();

$q          = trim($_POST['q'] ?? '');
$status     = $_POST['status'] ?? 'all';
$campaignId = (int)($_POST['campaign_id'] ?? 0);
$dateFrom   = $_POST['date_from'] ?? date('Y-m-01');
$dateTo     = $_POST['date_to']   ?? date('Y-m-t');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-t');

$where  = "s.slot_date BETWEEN :start AND :end AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin')";
$params = [':start' => $dateFrom, ':end' => $dateTo];

if ($q !== '') {
    $where .= " AND (u.full_name LIKE :q1 OR u.student_personnel_id LIKE :q2 OR c.title LIKE :q3)";
    $like = '%' . $q . '%';
    $params[':q1'] = $params[':q2'] = $params[':q3'] = $like;
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
    $pdo  = db();
    $stmt = $pdo->prepare("
        SELECT
            b.id AS booking_id, b.status, b.created_at,
            u.full_name, u.student_personnel_id, u.phone_number,
            s.slot_date, s.start_time, s.end_time,
            c.title AS campaign_title
        FROM camp_bookings b
        JOIN sys_users u  ON b.student_id  = u.id
        JOIN camp_slots s ON b.slot_id     = s.id
        JOIN camp_list c  ON b.campaign_id = c.id
        WHERE $where
        ORDER BY s.slot_date ASC, s.start_time ASC
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Error: ' . $e->getMessage());
}

$statusLabel = [
    'booked'             => 'รอการยืนยัน',
    'confirmed'          => 'ยืนยันแล้ว',
    'completed'          => 'เข้าร่วมแล้ว',
    'cancelled'          => 'ยกเลิก',
    'cancelled_by_admin' => 'ยกเลิก (Admin)',
];

$filename = 'bookings_' . $dateFrom . '_to_' . $dateTo . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel opens Thai text correctly
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['Booking ID', 'ชื่อ-สกุล', 'รหัสนักศึกษา/บุคลากร', 'เบอร์โทร', 'กิจกรรม', 'วันที่', 'เวลาเริ่ม', 'เวลาสิ้นสุด', 'สถานะ', 'วันที่จอง']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['booking_id'],
        $r['full_name'],
        $r['student_personnel_id'] ?? '',
        $r['phone_number'] ?? '',
        $r['campaign_title'],
        $r['slot_date'],
        substr($r['start_time'], 0, 5),
        substr($r['end_time'], 0, 5),
        $statusLabel[$r['status']] ?? $r['status'],
        $r['created_at'],
    ]);
}

fclose($out);
