<?php
// user/cancel_booking.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: my_bookings.php', true, 303);
  exit;
}

validate_csrf_or_die();

$studentId = isset($_SESSION['evax_student_id']) ? (int)$_SESSION['evax_student_id'] : 0;
$appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

if ($studentId <= 0 || $appointmentId <= 0) {
  header('Location: my_bookings.php', true, 303);
  exit;
}

try {
  $pdo = db();
  
  // 📧 ดึงข้อมูลก่อนลบเพื่อนำไปใส่ในอีเมล
  $stmtInfo = $pdo->prepare("SELECT u.email, c.title, s.slot_date, s.start_time, s.end_time 
                             FROM camp_bookings b 
                             JOIN sys_users u ON b.student_id = u.id 
                             JOIN camp_list c ON b.campaign_id = c.id 
                             JOIN camp_slots s ON b.slot_id = s.id 
                             WHERE b.id = :aid AND b.student_id = :sid");
  $stmtInfo->execute([':aid' => $appointmentId, ':sid' => $studentId]);
  $bInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

  // อัปเดตสถานะเป็น cancelled ในตารางใหม่ (camp_bookings)
  $sql = "
    UPDATE camp_bookings 
    SET status = 'cancelled' 
    WHERE id = :appointment_id AND student_id = :student_id
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':appointment_id' => $appointmentId,
    ':student_id' => $studentId
  ]);

  // ส่งอีเมลถ้ามีข้อมูล
  if ($bInfo && !empty($bInfo['email'])) {
    require_once __DIR__ . '/../includes/mail_helper.php';
    notify_booking_status($bInfo['email'], 'cancelled_by_user', [
        'campaign_title' => $bInfo['title'],
        'date' => date('d/m/Y', strtotime($bInfo['slot_date'])),
        'time' => substr($bInfo['start_time'], 0, 5) . ' - ' . substr($bInfo['end_time'], 0, 5)
    ]);
  }

} catch (PDOException $e) {
  error_log("cancel_booking error: " . $e->getMessage()); echo json_encode(["status" => "error", "message" => "เกิดข้อผิดพลาด กรุณาลองใหม่"]);
}

// ยกเลิกเสร็จ เด้งกลับไปหน้าประวัติการจอง
header('Location: my_bookings.php', true, 303);
exit;
