<?php
// user/submit_booking.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
session_start();

// 1. ตรวจสอบ Login
$studentId = isset($_SESSION['evax_student_id']) ? (int)$_SESSION['evax_student_id'] : 0;
if ($studentId <= 0) {
    header('Location: index.php', true, 303);
    exit;
}

validate_csrf_or_die();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: booking_campaign.php');
    exit;
}

// 2. รับค่าจากฟอร์มหน้า booking_time.php
$slotId = isset($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
$campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
$bookingDate = $_POST['booking_date'] ?? date('Y-m-d');

if ($slotId <= 0 || $campaignId <= 0) {
    echo "<script>alert('กรุณาเลือกรอบเวลาให้ครบถ้วน'); window.history.back();</script>";
    exit;
}

try {
    $pdo = db();
    
    // 3. เช็คว่าเคยกดจองกิจกรรมนี้ไปแล้วหรือยัง
    $checkSql = "SELECT COUNT(*) FROM camp_bookings WHERE student_id = :sid AND campaign_id = :cid AND status IN ('confirmed', 'booked')";
    $stmtCheck = $pdo->prepare($checkSql);
    $stmtCheck->execute([':sid' => $studentId, ':cid' => $campaignId]);
    if ((int)$stmtCheck->fetchColumn() > 0) {
        header('Location: my_bookings.php?error=already_booked', true, 303);
        exit;
    }

    // 4. เช็คโควต้ารวมของแคมเปญ และ "ดึงค่าการตั้งค่าอนุมัติอัตโนมัติ" (is_auto_approve) มาด้วย
    $sqlCamp = "
        SELECT c.title, c.total_capacity, c.is_auto_approve,
        (SELECT COUNT(*) FROM camp_bookings WHERE campaign_id = c.id AND status IN ('booked', 'confirmed')) as used
        FROM camp_list c
        WHERE id = :cid AND status = 'active'
          AND (available_until IS NULL OR available_until >= CURDATE())
    ";
    $stmtCamp = $pdo->prepare($sqlCamp);
    $stmtCamp->execute([':cid' => $campaignId]);
    $campData = $stmtCamp->fetch(PDO::FETCH_ASSOC);

    if (!$campData || $campData['used'] >= $campData['total_capacity']) {
        echo "<script>alert('ขออภัย กิจกรรมนี้ที่นั่งเต็มหรือหมดเขตไปแล้ว กรุณาเลือกกิจกรรมอื่น'); window.location.href='booking_campaign.php';</script>";
        exit;
    }

    // 5. เช็คโควต้าของรอบเวลา (Slot)
    $sqlSlot = "
        SELECT max_capacity,
        (SELECT COUNT(*) FROM camp_bookings WHERE slot_id = t.id AND status IN ('booked', 'confirmed')) as slot_used
        FROM camp_slots t
        WHERE id = :slot_id
    ";
    $stmtSlot = $pdo->prepare($sqlSlot);
    $stmtSlot->execute([':slot_id' => $slotId]);
    $slotData = $stmtSlot->fetch(PDO::FETCH_ASSOC);

    if (!$slotData || $slotData['slot_used'] >= $slotData['max_capacity']) {
        echo "<script>alert('ขออภัย รอบเวลาที่คุณเลือกเต็มแล้ว กรุณาเลือกรอบเวลาอื่น'); window.history.back();</script>";
        exit;
    }

    // 6. 🌟 กำหนดสถานะตามการตั้งค่าแคมเปญ 🌟
    // ถ้า is_auto_approve = 1 (อนุมัติอัตโนมัติ) ให้ใช้สถานะ 'confirmed' ข้ามการรอไปเลย
    $bookingStatus = ($campData['is_auto_approve'] == 1) ? 'confirmed' : 'booked';

    // 7. บันทึกข้อมูลลงฐานข้อมูล
    $insertSql = "INSERT INTO camp_bookings (student_id, campaign_id, slot_id, status) VALUES (:sid, :cid, :slot, :status)";
    $stmtInsert = $pdo->prepare($insertSql);
    $stmtInsert->execute([
        ':sid' => $studentId, 
        ':cid' => $campaignId, 
        ':slot' => $slotId,
        ':status' => $bookingStatus
    ]);

    // 8. 📧 ส่งอีเมลแจ้งเตือนการจองสำเร็จ
    try {
        $stmtUser = $pdo->prepare("SELECT email, full_name FROM sys_users WHERE id = :sid LIMIT 1");
        $stmtUser->execute([':sid' => $studentId]);
        $uInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($uInfo && !empty($uInfo['email'])) {
            require_once __DIR__ . '/../includes/mail_helper.php';

            $slot_time = '-';
            $stmtTime  = $pdo->prepare("SELECT slot_date, start_time, end_time FROM camp_slots WHERE id = :slot_id");
            $stmtTime->execute([':slot_id' => $slotId]);
            $tInfo = $stmtTime->fetch(PDO::FETCH_ASSOC);
            if ($tInfo) {
                $slot_date = date('d M Y', strtotime($tInfo['slot_date']));
                $slot_time = substr($tInfo['start_time'], 0, 5) . ' - ' . substr($tInfo['end_time'], 0, 5);
            } else {
                $slot_date = date('d M Y', strtotime($bookingDate));
            }

            notify_booking_status($uInfo['email'], 'confirmation', [
                'campaign_title' => $campData['title'],
                'full_name'      => $uInfo['full_name'],
                'date'           => $slot_date,
                'time'           => $slot_time,
            ]);
        }
    } catch (Exception $ex) {
        error_log("Email Notification Error: " . $ex->getMessage());
    }

    header('Location: success.php');
    exit;

} catch (PDOException $e) {
    error_log("Booking Error: " . $e->getMessage());
    echo "<script>alert('เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง'); window.history.back();</script>";
    exit;
}
