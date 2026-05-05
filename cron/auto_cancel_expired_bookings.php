<?php
/**
 * cron/auto_cancel_expired_bookings.php
 * ──────────────────────────────────────────────────────────────────────────────
 * ยกเลิกการจองที่ "เลยวันนัดหมายแต่ไม่มาเช็คอิน" อัตโนมัติ + แจ้งเตือนผู้ใช้
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────────────────
 *  URL     : https://healthycampus.rsu.ac.th/e-campaignv2/cron/auto_cancel_expired_bookings.php?token=YOUR_SECRET_TOKEN
 *  Schedule: ทุกวัน เวลา 02:00 (Asia/Bangkok)  — เลือกเวลาที่ traffic น้อย
 *
 * ── เกณฑ์ที่จะยกเลิก ─────────────────────────────────────────────────────────
 *  - slot_date < CURDATE()  (เลยวันแล้ว, grace = 0 วัน)
 *  - attended_at IS NULL    (ไม่มีบันทึกเช็คอิน)
 *  - status IN ('booked', 'confirmed')
 *
 *  → set status = 'expired'
 *  → ส่ง email + LINE notification ให้ผู้ใช้
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

define('CRON_SECRET_TOKEN', 'rsu_purge_a8f3k2m9x');
define('NOTIFY_DELAY_SECONDS', 2);

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(CRON_SECRET_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db_connect.php';
require_once $projectRoot . '/includes/mail_helper.php';

date_default_timezone_set('Asia/Bangkok');

$pdo = db();
$log = [];
$log[] = '[' . date('Y-m-d H:i:s') . '] Auto-Cancel Expired Bookings Job เริ่มทำงาน';

// ── Find candidates ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        b.id AS booking_id,
        b.status,
        u.email,
        u.full_name,
        u.line_user_id,
        s.slot_date,
        s.start_time,
        s.end_time,
        c.title AS campaign_title
    FROM camp_bookings b
    JOIN camp_slots s ON b.slot_id     = s.id
    JOIN camp_list  c ON b.campaign_id = c.id
    JOIN sys_users  u ON b.student_id  = u.id
    WHERE s.slot_date     < CURDATE()
      AND b.attended_at  IS NULL
      AND b.status       IN ('booked', 'confirmed')
    ORDER BY s.slot_date ASC, b.id ASC
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);

$log[] = "พบการจองที่ต้องยกเลิก: {$total} รายการ";

if ($total === 0) {
    $log[] = 'ไม่มีรายการ — จบการทำงาน';
    echo implode("\n", $log) . "\n";
    exit;
}

// Update statement — flip to 'expired' atomically (re-checks the condition
// inline so a user that races to check-in between the SELECT and UPDATE
// keeps their slot).
$updateStmt = $pdo->prepare("
    UPDATE camp_bookings
    SET status = 'expired'
    WHERE id = :id
      AND attended_at IS NULL
      AND status IN ('booked', 'confirmed')
");

$cancelled  = 0;
$emailSent  = 0;
$lineSent   = 0;
$skipped    = 0;
$failed     = 0;

foreach ($rows as $i => $row) {
    $bookingId = (int)$row['booking_id'];
    $email     = trim((string)($row['email'] ?? ''));
    $lineId    = trim((string)($row['line_user_id'] ?? ''));
    $name      = (string)($row['full_name'] ?? '');
    $title     = (string)($row['campaign_title'] ?? '-');
    $date      = date('d/m/Y', strtotime($row['slot_date']));
    $timeRange = substr((string)$row['start_time'], 0, 5) . ' – ' . substr((string)$row['end_time'], 0, 5) . ' น.';

    // 1. Cancel
    try {
        $updateStmt->execute([':id' => $bookingId]);
        if ($updateStmt->rowCount() === 0) {
            $skipped++;
            $log[] = "  ↺ #{$bookingId} {$name} — ข้าม (เปลี่ยนสถานะระหว่างทาง)";
            continue;
        }
        $cancelled++;
    } catch (PDOException $e) {
        $failed++;
        $log[] = "  ✗ #{$bookingId} DB error: " . $e->getMessage();
        continue;
    }

    // 2. Email notification (ถ้ามีอีเมล)
    if ($email !== '') {
        try {
            $ok = notify_booking_status($email, 'expired', [
                'campaign_title' => $title,
                'full_name'      => $name,
                'date'           => $date,
                'time'           => $timeRange,
            ]);
            if ($ok) $emailSent++;
        } catch (Throwable $e) {
            error_log("Expired notify email error #{$bookingId}: " . $e->getMessage());
        }
    }

    // 3. LINE push notification (ถ้ามี LINE user id)
    if ($lineId !== '') {
        try {
            $ok = send_line_notification_simple($lineId, [
                'campaign_title' => $title,
                'date'           => $date,
                'time'           => $timeRange,
            ]);
            if ($ok) $lineSent++;
        } catch (Throwable $e) {
            error_log("Expired notify LINE error #{$bookingId}: " . $e->getMessage());
        }
    }

    $log[] = "  ✓ #{$bookingId} {$name} — ยกเลิก + แจ้งเตือน"
           . ($email ? ' [email]' : '')
           . ($lineId ? ' [line]' : '');

    // Throttle to avoid hitting mail / LINE rate limits
    if ($i < $total - 1) {
        sleep(NOTIFY_DELAY_SECONDS);
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────
$log[] = "─────────────────────────────────";
$log[] = "ยกเลิกสำเร็จ: {$cancelled} / {$total}";
$log[] = "Email:  {$emailSent}";
$log[] = "LINE:   {$lineSent}";
$log[] = "ข้าม:   {$skipped}  (race condition)";
$log[] = "ล้มเหลว: {$failed}";
$log[] = '[' . date('Y-m-d H:i:s') . '] จบการทำงาน';

echo implode("\n", $log) . "\n";
