<?php
// staff/ajax_scan_checkin.php
session_start();
header('Content-Type: application/json; charset=utf-8');

$viaStaffLogin  = !empty($_SESSION['staff_logged_in']) && $_SESSION['staff_logged_in'] === true;
$viaPortalLogin = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true
               && !empty($_SESSION['is_ecampaign_staff']);

if (!$viaStaffLogin && !$viaPortalLogin) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

// [ISO 27001] ตรวจสอบสิทธิ์การเข้าถึงแบบ Real-time สำหรับ Staff
try {
    $p = db();
    $staffId = $_SESSION['staff_id'] ?? ($_SESSION['admin_id'] ?? 0);
    $uname   = $_SESSION['staff_username'] ?? ($_SESSION['admin_username'] ?? '');
    
    $check = $p->prepare("SELECT 1 FROM sys_staff WHERE (id = ? OR username = ?) AND access_ecampaign = 1 AND account_status = 'active' LIMIT 1");
    $check->execute([$staffId, $uname]);
    
    if (!$check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์เข้าถึงระบบ (Access Denied)']);
        exit;
    }
} catch (Exception $e) { /* continue on db fail */ }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request']);
    exit;
}

validate_csrf_or_die();

$qrData     = trim($_POST['qr_data'] ?? '');
$campaignId = isset($_POST['campaign_id']) && (int)$_POST['campaign_id'] > 0
              ? (int)$_POST['campaign_id']
              : null;

// ── ดึง booking ID จาก QR data (รองรับทั้ง "BOOKING-ID:42" และตัวเลขล้วน) ──
$appointmentId = (int) preg_replace('/[^0-9]/', '', $qrData);

if ($appointmentId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'อ่านข้อมูล QR Code ได้: ' . $qrData . ' (ไม่พบรหัสตัวเลข)']);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT b.*, c.title AS campaign_title, c.id AS c_id,
               s.full_name, s.student_personnel_id,
               sl.slot_date, sl.start_time, sl.end_time
        FROM camp_bookings b
        JOIN camp_list   c  ON b.campaign_id = c.id
        JOIN sys_users   s  ON b.student_id  = s.id
        LEFT JOIN camp_slots sl ON b.slot_id = sl.id
        WHERE b.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $appointmentId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        echo json_encode(['status' => 'error', 'message' => "ไม่พบข้อมูลการจองรหัส #{$appointmentId} ในระบบ"]);
        exit;
    }

    // ── ถ้าระบุ campaign_id ให้ตรวจว่า booking นี้อยู่แคมเปญเดียวกัน ──
    if ($campaignId !== null && (int)$booking['c_id'] !== $campaignId) {
        echo json_encode([
            'status'  => 'error',
            'message' => "QR นี้เป็นของแคมเปญ \"{$booking['campaign_title']}\" ไม่ใช่แคมเปญที่เปิดอยู่",
        ]);
        exit;
    }

    // ── Validation ────────────────────────────────────────────────────────
    if (in_array($booking['status'], ['cancelled', 'cancelled_by_admin'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'คิวนี้ถูกยกเลิกไปแล้ว']);
        exit;
    }
    if ($booking['status'] === 'booked') {
        echo json_encode(['status' => 'warning', 'message' => 'คิวนี้ยังไม่ได้รับการอนุมัติ กรุณาให้ Admin อนุมัติก่อน']);
        exit;
    }

    // ── เช็คอินได้เฉพาะตรงวันนัดเท่านั้น ──────────────────────────────────
    if (!empty($booking['slot_date'])) {
        $tz      = new DateTimeZone('Asia/Bangkok');
        $today   = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
        $slotDay = (new DateTimeImmutable($booking['slot_date'], $tz))->format('Y-m-d');
        $dateStr = date('d/m/Y', strtotime($booking['slot_date']));

        if ($slotDay > $today) {
            echo json_encode([
                'status'  => 'error',
                'message' => "ยังไม่ถึงวันรับบริการ — นัดหมายวันที่ {$dateStr}",
            ]);
            exit;
        }
        if ($slotDay < $today) {
            echo json_encode([
                'status'  => 'error',
                'message' => "เลยวันรับบริการไปแล้ว — นัดหมายวันที่ {$dateStr}",
            ]);
            exit;
        }
    }

    if (!empty($booking['attended_at'])) {
        $time = date('H:i', strtotime($booking['attended_at']));
        echo json_encode(['status' => 'warning', 'message' => "เช็คอินซ้ำ! ได้เช็คอินไปแล้วเวลา {$time} น."]);
        exit;
    }

    // ── ตรวจสอบว่าเป็นขั้นตอนยืนยัน (Confirm) หรือไม่ ────────────────────────
    $isConfirm = (isset($_POST['confirm']) && $_POST['confirm'] === '1');

    if (!$isConfirm) {
        // ขั้นตอน Preview: ส่งข้อมูลกลับไปให้กดยืนยัน โดยยังไม่บันทึกเวลา
        echo json_encode([
            'status' => 'preview',
            'data'   => [
                'name'            => $booking['full_name'],
                'student_id'      => $booking['student_personnel_id'] ?? '',
                'campaign'        => $booking['campaign_title'],
                'slot_label'      => (date('d/m/Y', strtotime($booking['slot_date'] ?? ''))) . (isset($booking['start_time']) ? ' ' . substr($booking['start_time'], 0, 5) . '–' . substr($booking['end_time'], 0, 5) . ' น.' : ''),
            ],
        ]);
        exit;
    }

    // ── บันทึกเวลาเช็คอิน (เมื่อกดยืนยันแล้ว) ─────────────────────────────────
    $pdo->prepare("UPDATE camp_bookings SET attended_at = NOW() WHERE id = :id")
        ->execute([':id' => $appointmentId]);

    // ── นับจำนวน attended ของแคมเปญนี้ (สำหรับ real-time counter) ────────
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM camp_bookings
        WHERE campaign_id = :cid AND attended_at IS NOT NULL
    ");
    $cntStmt->execute([':cid' => $booking['c_id']]);
    $newCount = (int)$cntStmt->fetchColumn();

    // ── สร้าง slot label ──────────────────────────────────────────────────
    $slotLabel = '';
    if (!empty($booking['slot_date'])) {
        $slotLabel = date('d/m/Y', strtotime($booking['slot_date']));
        if (!empty($booking['start_time'])) {
            $slotLabel .= ' ' . substr($booking['start_time'], 0, 5) . '–' . substr($booking['end_time'], 0, 5) . ' น.';
        }
    }

    echo json_encode([
        'status' => 'success',
        'data'   => [
            'name'            => $booking['full_name'],
            'student_id'      => $booking['student_personnel_id'] ?? '',
            'campaign'        => $booking['campaign_title'],
            'slot_label'      => $slotLabel ?: $booking['campaign_title'],
            'attended_total'  => $newCount,
        ],
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()]);
}
