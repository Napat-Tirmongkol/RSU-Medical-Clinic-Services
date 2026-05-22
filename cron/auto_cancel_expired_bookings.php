<?php
/**
 * cron/auto_cancel_expired_bookings.php
 * ──────────────────────────────────────────────────────────────────────────────
 * ยกเลิกการจองที่ "เลยวันนัดหมายแต่ไม่มาเช็คอิน" อัตโนมัติ + แจ้งเตือนผู้ใช้
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────────────────
 *  URL     : https://healthycampus.rsu.ac.th/rsu-clinic/cron/auto_cancel_expired_bookings.php?token=YOUR_SECRET_TOKEN
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

// ── Auto-migrate: ขยาย ENUM ให้รองรับ 'expired' ──────────────────────────────
// status เดิมเป็น ENUM ที่ยังไม่มี 'expired' — UPDATE จะถูก MySQL truncate
// (SQLSTATE 01000, 1265 Data truncated) ถ้าไม่ขยายชนิดก่อน
try {
    $pdo->exec("ALTER TABLE camp_bookings
        MODIFY COLUMN status ENUM('booked','confirmed','completed','cancelled','cancelled_by_admin','expired')
        NOT NULL DEFAULT 'booked'");
    $log[] = 'ขยาย ENUM status ให้รองรับ expired แล้ว';
} catch (PDOException $e) {
    // ถ้า ENUM ไม่ตรง spec ให้ fallback เป็น VARCHAR เพื่อไม่ให้ block job
    try {
        $pdo->exec("ALTER TABLE camp_bookings MODIFY COLUMN status VARCHAR(40) NOT NULL DEFAULT 'booked'");
        $log[] = 'fallback: เปลี่ยน status เป็น VARCHAR(40)';
    } catch (PDOException $e2) {
        $log[] = 'WARN: ขยาย status ENUM ไม่สำเร็จ — ' . $e2->getMessage();
    }
}

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

// ── Step 1: Batch UPDATE ทั้งหมดก่อน (เร็ว ไม่มี delay) ──────────────────────
$ids = array_column($rows, 'booking_id');
$placeholders = implode(',', array_fill(0, count($ids), '?'));
try {
    $batchStmt = $pdo->prepare("
        UPDATE camp_bookings
        SET status = 'expired'
        WHERE id IN ({$placeholders})
          AND attended_at IS NULL
          AND status IN ('booked', 'confirmed')
    ");
    $batchStmt->execute(array_values($ids));
    $cancelled = $batchStmt->rowCount();
    $skipped   = $total - $cancelled;
    $log[] = "  ✓ Batch update: ยกเลิก {$cancelled} รายการ (ข้าม {$skipped} — เปลี่ยนสถานะระหว่างทาง)";
} catch (PDOException $e) {
    $log[] = "  ✗ Batch update error: " . $e->getMessage();
    $failed = $total;
}

// ── Step 2: ส่ง notification เฉพาะรายการที่ถูกยกเลิกจริง ─────────────────────
// ดึง ID ที่ถูก update จริง (re-query เพื่อความแน่ใจ)
$notifyStmt = $pdo->prepare("
    SELECT b.id AS booking_id, u.email, u.full_name, u.line_user_id,
           s.slot_date, s.start_time, s.end_time, c.title AS campaign_title
    FROM camp_bookings b
    JOIN camp_slots s ON b.slot_id     = s.id
    JOIN camp_list  c ON b.campaign_id = c.id
    JOIN sys_users  u ON b.student_id  = u.id
    WHERE b.id IN ({$placeholders}) AND b.status = 'expired'
");
$notifyStmt->execute(array_values($ids));
$notifyRows = $notifyStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($notifyRows as $row) {
    $bookingId = (int)$row['booking_id'];
    $email     = trim((string)($row['email'] ?? ''));
    $lineId    = trim((string)($row['line_user_id'] ?? ''));
    $name      = (string)($row['full_name'] ?? '');
    $title     = (string)($row['campaign_title'] ?? '-');
    $date      = date('d/m/Y', strtotime($row['slot_date']));
    $timeRange = substr((string)$row['start_time'], 0, 5) . ' – ' . substr((string)$row['end_time'], 0, 5) . ' น.';

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

    $log[] = "  ✓ #{$bookingId} {$name}"
           . ($email  ? ' [email]' : '')
           . ($lineId ? ' [line]'  : '');
}

// ── Summary ──────────────────────────────────────────────────────────────────
$log[] = "─────────────────────────────────";
$log[] = "ยกเลิกสำเร็จ: {$cancelled} / {$total}";
$log[] = "Email:  {$emailSent}";
$log[] = "LINE:   {$lineSent}";
$log[] = "ข้าม:   {$skipped}  (race condition)";
$log[] = "ล้มเหลว: {$failed}";
$log[] = '[' . date('Y-m-d H:i:s') . '] จบการทำงาน';

// ── Activity Log (insert ตรงโดยไม่โหลด config.php ทั้งไฟล์) ──────────────────
if ($cancelled > 0) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_activity_logs (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NULL,
            action      VARCHAR(100) NOT NULL,
            description TEXT,
            ip_address  VARCHAR(45),
            user_agent  TEXT,
            timestamp   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_action (action),
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $desc = "Cron ยกเลิกอัตโนมัติ (ไม่มาตามนัด): {$cancelled} รายการ"
              . " | Email: {$emailSent} | LINE: {$lineSent}"
              . ($failed > 0 ? " | ล้มเหลว: {$failed}" : '');

        $pdo->prepare("INSERT INTO sys_activity_logs (user_id, action, description, ip_address, user_agent)
                       VALUES (NULL, 'auto_expire_bookings', :desc, :ip, :ua)")
            ->execute([
                ':desc' => $desc,
                ':ip'   => $_SERVER['REMOTE_ADDR'] ?? 'cron',
                ':ua'   => $_SERVER['HTTP_USER_AGENT'] ?? 'cron/auto_cancel_expired_bookings',
            ]);
        $log[] = 'บันทึก Activity Log แล้ว';
    } catch (PDOException $e) {
        $log[] = 'WARN: บันทึก Activity Log ไม่สำเร็จ — ' . $e->getMessage();
    }
}

echo implode("\n", $log) . "\n";
