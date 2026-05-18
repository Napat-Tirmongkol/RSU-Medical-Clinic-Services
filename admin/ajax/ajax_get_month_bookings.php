<?php
// admin/ajax_get_month_bookings.php
session_start();
header('Content-Type: application/json');

// เช็คสิทธิ์ Admin
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config.php';

$pdo = db();

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));

$bookingsByDay = [];

try {
    $sql = "
        SELECT 
            a.id AS appointment_id, 
            a.status, 
            s.full_name, 
            s.student_personnel_id, 
            s.phone_number,
            t.slot_date, 
            t.start_time, 
            t.end_time,
            c.title AS campaign_title
        FROM camp_bookings a
        JOIN sys_users s ON a.student_id = s.id
        JOIN camp_slots t ON a.slot_id = t.id
        JOIN camp_list c ON a.campaign_id = c.id
        WHERE t.slot_date >= :start 
          AND t.slot_date <= :end
          AND a.status IN ('booked', 'confirmed') 
        ORDER BY t.start_time ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $allBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // จัดกลุ่มข้อมูลตามวันที่ (Day)
    foreach ($allBookings as $b) {
        $day = (int)date('d', strtotime($b['slot_date']));
        $bookingsByDay[$day][] = $b;
    }
    
    echo json_encode(['status' => 'success', 'data' => $bookingsByDay]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'ระบบขัดข้อง กรุณาลองอีกครั้ง']);
}
