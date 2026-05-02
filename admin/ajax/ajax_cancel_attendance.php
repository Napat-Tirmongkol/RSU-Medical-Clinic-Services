<?php
// admin/ajax/ajax_cancel_attendance.php — Undo check-in, return user to original booking queue
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

validate_csrf_or_die();

$bookingId = (int)($_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid booking ID']);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT b.id, b.status, b.attended_at,
               u.full_name, cl.title AS campaign_title
        FROM camp_bookings b
        JOIN sys_users u  ON b.student_id  = u.id
        JOIN camp_list cl ON b.campaign_id = cl.id
        WHERE b.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการจองนี้']);
        exit;
    }

    // Must be completed (staff check-in) or have attended_at set (QR check-in)
    $isCompleted  = $booking['status'] === 'completed';
    $hasAttendedAt = !empty($booking['attended_at']);

    if (!$isCompleted && !$hasAttendedAt) {
        echo json_encode(['status' => 'error', 'message' => 'ยังไม่ได้เช็คอิน ไม่สามารถยกเลิกได้']);
        exit;
    }

    // Revert: clear attended_at + set status back to confirmed
    $newStatus = $isCompleted ? 'confirmed' : $booking['status'];

    $pdo->prepare("
        UPDATE camp_bookings
        SET attended_at = NULL,
            status = :st
        WHERE id = :id
    ")->execute([':st' => $newStatus, ':id' => $bookingId]);

    $adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? 0) ?: null;
    log_activity(
        'cancel_attendance',
        "ยกเลิกการเข้าร่วม ID: {$bookingId} — {$booking['full_name']} — {$booking['campaign_title']}",
        $adminId
    );

    echo json_encode([
        'status'     => 'success',
        'message'    => 'ยกเลิกการเข้าร่วมสำเร็จ ผู้ใช้กลับไปอยู่ในคิวเดิมแล้ว',
        'new_status' => $newStatus,
    ]);

} catch (PDOException $e) {
    log_error_to_db('ajax_cancel_attendance: ' . $e->getMessage(), 'error', 'ajax_cancel_attendance.php');
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในระบบ']);
}
