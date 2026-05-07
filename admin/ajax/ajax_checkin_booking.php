<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

validate_csrf_or_die();

$bookingId = (int)($_POST['appointment_id'] ?? 0);
if ($bookingId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid booking ID']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT b.id, b.status, u.full_name, c.title AS campaign_title
        FROM camp_bookings b
        JOIN sys_users u ON b.student_id = u.id
        JOIN camp_list c ON b.campaign_id = c.id
        WHERE b.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการจองนี้']);
        exit;
    }

    if ($booking['status'] !== 'confirmed') {
        $pdo->rollBack();
        $msg = match($booking['status']) {
            'booked'             => 'การจองนี้ยังไม่ได้รับการอนุมัติ',
            'completed'          => 'ผู้ใช้นี้เช็กอินเข้าร่วมงานไปแล้ว',
            'cancelled'          => 'การจองนี้ถูกยกเลิกโดยผู้ใช้แล้ว',
            'cancelled_by_admin' => 'การจองนี้ถูกยกเลิกโดยแอดมินแล้ว',
            default              => 'ไม่สามารถเช็กอินได้',
        };
        echo json_encode(['status' => 'error', 'message' => $msg]);
        exit;
    }

    // Stamp attended_at too so the post-checkin survey hub lock can detect this booking
    $upd = $pdo->prepare("
        UPDATE camp_bookings
        SET status = 'completed', attended_at = COALESCE(attended_at, NOW())
        WHERE id = :id AND status = 'confirmed'
    ");
    $upd->execute([':id' => $bookingId]);

    if ($upd->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถอัปเดตได้ อาจมีการเปลี่ยนแปลงสถานะก่อนหน้านี้แล้ว']);
        exit;
    }

    $pdo->commit();

    $adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['staff_id'] ?? 0) ?: null;
    log_activity(
        'checkin_booking',
        "รับเข้าร่วมงาน ID: {$bookingId} — {$booking['full_name']} — {$booking['campaign_title']}",
        $adminId
    );

    // Best-effort LINE flex reminder so the user knows to fill the survey
    require_once __DIR__ . '/../../includes/survey_helper.php';
    @send_post_checkin_survey_reminder($pdo, $bookingId);

    echo json_encode(['status' => 'success', 'message' => 'รับเข้าร่วมงานสำเร็จ']);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('ajax_checkin_booking PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในระบบฐานข้อมูล']);
}
