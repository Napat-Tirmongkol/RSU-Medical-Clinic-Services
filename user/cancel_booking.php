<?php
// user/cancel_booking.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

validate_csrf_or_die();

$appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

if ($appointmentId <= 0) {
    header('Location: my_bookings.php');
    exit;
}

try {
    $pdo = db();
    
    // ดึงข้อมูลผู้ใช้เพื่อตรวจสอบว่าเป็นเจ้าของนัดหมายจริงหรือไม่
    $stmtU = $pdo->prepare("SELECT student_personnel_id, id FROM sys_users WHERE line_user_id = :line_id AND clinic_id = :clinic_id LIMIT 1");
    $stmtU->execute([':line_id' => $lineUserId, ':clinic_id' => clinic_id()]);
    $user = $stmtU->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die("User not found.");
    }

    $sid = $user['student_personnel_id'];
    $userId = $user['id'];

    // ตรวจสอบสถานะก่อนยกเลิก
    $stmtCheck = $pdo->prepare("SELECT status FROM camp_bookings WHERE id = :aid AND student_personnel_id = :sid");
    $stmtCheck->execute([':aid' => $appointmentId, ':sid' => $sid]);
    $currentStatus = $stmtCheck->fetchColumn();

    if (!$currentStatus) {
        die("Appointment not found or access denied.");
    }

    if (in_array($currentStatus, ['cancelled', 'cancelled_by_admin'])) {
        header('Location: my_bookings.php?msg=already_cancelled');
        exit;
    }

    // ทำการยกเลิก
    $stmt = $pdo->prepare("UPDATE camp_bookings SET status = 'cancelled' WHERE id = :aid AND student_personnel_id = :sid");
    $stmt->execute([':aid' => $appointmentId, ':sid' => $sid]);

    log_activity('Cancel Booking', "User cancelled appointment #$appointmentId", $userId);

    header('Location: my_bookings.php?msg=cancelled_success');
    exit;

} catch (PDOException $e) {
    error_log("Cancel Booking Error: " . $e->getMessage());
    header('Location: my_bookings.php?msg=error');
    exit;
}
