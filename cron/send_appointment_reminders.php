<?php
/**
 * cron/send_appointment_reminders.php
 * ส่งอีเมลแจ้งเตือนนัดหมาย 1 วันล่วงหน้า — พร้อม delay ระหว่างแต่ละเมล
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────────────────
 *  URL     : https://healthycampus.rsu.ac.th/e-campaignv2/cron/send_appointment_reminders.php?token=YOUR_SECRET_TOKEN
 *  Schedule: ทุกวัน เวลา 08:00 (Asia/Bangkok)
 *
 * ── Delay ────────────────────────────────────────────────────────────────────
 *  REMINDER_DELAY_SECONDS = 3  (รอ 3 วินาทีระหว่างแต่ละอีเมล)
 *  ปรับเพิ่มได้หากถูกบล็อกจาก mail server (แนะนำ 3–10 วินาที)
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

// ── Secret Token ──────────────────────────────────────────────────────────────
define('REMINDER_SECRET_TOKEN', 'rsu_purge_a8f3k2m9x');

// ── หน่วงเวลาระหว่างแต่ละอีเมล (วินาที) ───────────────────────────────────────
define('REMINDER_DELAY_SECONDS', 3);

// ── ตรวจสอบ token ─────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(REMINDER_SECRET_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

// ── โหลด config ───────────────────────────────────────────────────────────────
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db_connect.php';
require_once $projectRoot . '/includes/mail_helper.php';

date_default_timezone_set('Asia/Bangkok');

$pdo    = db();
$log    = [];
$log[]  = '[' . date('Y-m-d H:i:s') . '] Appointment Reminder Job เริ่มทำงาน';
$log[]  = 'ส่งสำหรับวันที่: ' . date('Y-m-d', strtotime('+1 day'));

// ── Auto-migrate: เพิ่มคอลัมน์ reminder_sent_at ถ้ายังไม่มี ───────────────────
try {
    $pdo->exec("ALTER TABLE camp_bookings ADD COLUMN reminder_sent_at DATETIME NULL DEFAULT NULL");
    $log[] = 'สร้างคอลัมน์ reminder_sent_at เรียบร้อย';
} catch (PDOException) {
    // คอลัมน์มีอยู่แล้ว — ข้ามได้
}

// ── ดึงรายการที่ต้องส่งแจ้งเตือน ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        b.id            AS booking_id,
        b.status,
        u.email,
        u.full_name,
        s.slot_date,
        s.start_time,
        s.end_time,
        c.title         AS campaign_title
    FROM camp_bookings b
    JOIN camp_slots  s ON b.slot_id     = s.id
    JOIN camp_list   c ON b.campaign_id = c.id
    JOIN sys_users   u ON b.student_id  = u.id
    WHERE s.slot_date       = CURDATE() + INTERVAL 1 DAY
      AND b.status          IN ('booked', 'confirmed')
      AND b.reminder_sent_at IS NULL
      AND u.email           IS NOT NULL
      AND u.email           != ''
    ORDER BY b.id ASC
");
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total   = count($bookings);
$sent    = 0;
$failed  = 0;

$log[] = "พบการจองที่ต้องส่งแจ้งเตือน: {$total} รายการ";

if ($total === 0) {
    $log[] = 'ไม่มีรายการ — จบการทำงาน';
    echo implode("\n", $log);
    exit;
}

// ── ประโยค UPDATE สำหรับ mark ว่าส่งแล้ว ──────────────────────────────────────
$markStmt = $pdo->prepare("
    UPDATE camp_bookings SET reminder_sent_at = NOW() WHERE id = :id
");

// ── ส่งอีเมลทีละรายการ + delay ───────────────────────────────────────────────
foreach ($bookings as $i => $row) {
    $bookingId = (int)$row['booking_id'];
    $email     = $row['email'];
    $name      = $row['full_name'] ?? '';
    $title     = $row['campaign_title'] ?? '-';
    $date      = date('d/m/Y', strtotime($row['slot_date']));
    $timeRange = substr($row['start_time'], 0, 5) . ' – ' . substr($row['end_time'], 0, 5) . ' น.';

    $ok = false;
    try {
        $ok = notify_booking_status($email, 'reminder', [
            'campaign_title' => $title,
            'full_name'      => $name,
            'date'           => $date,
            'time'           => $timeRange,
        ]);
    } catch (Throwable $e) {
        error_log("Reminder send error booking#{$bookingId}: " . $e->getMessage());
    }

    if ($ok) {
        $markStmt->execute([':id' => $bookingId]);
        $sent++;
        $log[] = "  ✓ #{$bookingId} {$name} <{$email}> — ส่งสำเร็จ";
    } else {
        $failed++;
        $log[] = "  ✗ #{$bookingId} {$name} <{$email}> — ส่งล้มเหลว";
    }

    // หน่วงเวลาระหว่างแต่ละเมล (ยกเว้นรายการสุดท้าย)
    if ($i < $total - 1) {
        sleep(REMINDER_DELAY_SECONDS);
    }
}

// ── สรุปผล ────────────────────────────────────────────────────────────────────
$log[] = "─────────────────────────────────";
$log[] = "สรุป: ส่งสำเร็จ {$sent} / {$total} รายการ | ล้มเหลว {$failed} รายการ";
$log[] = '[' . date('Y-m-d H:i:s') . '] จบการทำงาน';

echo implode("\n", $log) . "\n";
