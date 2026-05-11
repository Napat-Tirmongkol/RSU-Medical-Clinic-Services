<?php
/**
 * user/ajax_scholarship_booking.php — นักศึกษาทุนจอง/ยกเลิกรอบงาน
 *
 * Actions:
 *   list_open    — ดูรอบที่เปิดให้จอง (excludes ที่ตัวเองจองแล้ว)
 *   list_mine    — ดูรอบที่ตัวเองจองอยู่
 *   book         — จองรอบ (สร้าง shift auto + booking)
 *   cancel       — ยกเลิกการจอง (ต้องก่อน cutoff)
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/scholarship_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$lineUserId = $_SESSION['line_user_id'] ?? '';
$userId = $_SESSION['user_id'] ?? null;
if (!$userId && $lineUserId !== '') {
    try {
        $stmt = db()->prepare("SELECT id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
        $stmt->execute([':lid' => $lineUserId]);
        $row = $stmt->fetch();
        if ($row) { $userId = (int)$row['id']; $_SESSION['user_id'] = $userId; }
    } catch (PDOException $e) {}
}
if (!$userId) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = db();
ensure_scholarship_schema($pdo);

$student = get_scholarship_student_by_user($pdo, (int)$userId);
if (!$student) {
    echo json_encode(['ok' => false, 'error' => 'บัญชีนี้ไม่ใช่นักศึกษาทุน']);
    exit;
}
if ($student['status'] !== 'active') {
    echo json_encode(['ok' => false, 'error' => 'บัญชีทุนถูกระงับ']);
    exit;
}

$studentId = (int)$student['id'];
$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'list_open':  list_open_slots($pdo, $studentId); break;
        case 'list_mine':  list_my_bookings($pdo, $studentId); break;
        case 'book':       book_slot($pdo, $studentId); break;
        case 'cancel':     cancel_booking($pdo, $studentId); break;
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('[ajax_scholarship_booking] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

// ─────────────────────────────────────────────────────────────────────

function list_open_slots(PDO $pdo, int $studentId): void
{
    // default: 30 วันข้างหน้า
    $from = (string)($_POST['from'] ?? date('Y-m-d'));
    $to   = (string)($_POST['to']   ?? date('Y-m-d', strtotime('+30 days')));
    $rows = get_open_scholarship_slots($pdo, $from, $to, ['open'], $studentId);

    // กรอง slot ที่หมดอายุ (เริ่มไปแล้ว) ออก
    $now = time();
    $beforeFilter = count($rows);
    $rows = array_values(array_filter($rows, function ($r) use ($now) {
        return strtotime($r['slot_date'] . ' ' . $r['end_time']) > $now;
    }));

    // Diagnostic log สำหรับช่วงดีบั๊ก (ดู PHP error log ถ้าผลลัพธ์ว่าง)
    if (empty($rows)) {
        $totalOpen = (int)$pdo->query("SELECT COUNT(*) FROM sys_scholarship_slots WHERE status='open'")->fetchColumn();
        $totalAny  = (int)$pdo->query("SELECT COUNT(*) FROM sys_scholarship_slots")->fetchColumn();
        error_log(sprintf(
            '[scholarship_booking] list_open empty: studentId=%d from=%s to=%s before_time_filter=%d total_open=%d total_any=%d',
            $studentId, $from, $to, $beforeFilter, $totalOpen, $totalAny
        ));
    }

    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
}

function list_my_bookings(PDO $pdo, int $studentId): void
{
    $upcomingOnly = !isset($_POST['include_past']) || empty($_POST['include_past']);
    $rows = get_student_slot_bookings($pdo, $studentId, $upcomingOnly);
    echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
}

function book_slot(PDO $pdo, int $studentId): void
{
    $slotId = (int)($_POST['slot_id'] ?? 0);
    if (!$slotId) { echo json_encode(['ok' => false, 'error' => 'missing slot_id']); return; }

    $pdo->beginTransaction();
    try {
        // Lock slot row เพื่อกัน race condition (2 นักศึกษาจอง slot สุดท้ายพร้อมกัน)
        $stmt = $pdo->prepare("SELECT * FROM sys_scholarship_slots WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $slotId]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$slot) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'ไม่พบรอบนี้']); return;
        }
        if ($slot['status'] !== 'open') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'รอบนี้ปิดรับจองแล้ว']); return;
        }
        if (strtotime($slot['slot_date'] . ' ' . $slot['end_time']) <= time()) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'รอบนี้สิ้นสุดแล้ว']); return;
        }

        // เช็คว่าจองอยู่แล้วหรือยัง
        $dup = $pdo->prepare("SELECT id FROM sys_scholarship_slot_bookings
            WHERE slot_id = :sid AND student_id = :stud AND status = 'booked' LIMIT 1");
        $dup->execute([':sid' => $slotId, ':stud' => $studentId]);
        if ($dup->fetch()) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'คุณจองรอบนี้อยู่แล้ว']); return;
        }

        // นับจำนวนคนที่จองแล้ว
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_slot_bookings
            WHERE slot_id = :sid AND status = 'booked'");
        $cntStmt->execute([':sid' => $slotId]);
        $booked = (int)$cntStmt->fetchColumn();
        if ($booked >= (int)$slot['max_capacity']) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'รอบนี้เต็มแล้ว']); return;
        }

        // กัน overlap: ห้ามจอง slot ที่ชนกับ shift หรือ booking อื่นในวันเดียวกัน
        $overlapSql = "SELECT 1 FROM sys_scholarship_slots s
            INNER JOIN sys_scholarship_slot_bookings b ON b.slot_id = s.id
            WHERE b.student_id = :stud AND b.status = 'booked'
              AND s.slot_date = :d
              AND NOT (s.end_time <= :st OR s.start_time >= :et)
            LIMIT 1";
        $ovStmt = $pdo->prepare($overlapSql);
        $ovStmt->execute([
            ':stud' => $studentId,
            ':d' => $slot['slot_date'],
            ':st' => $slot['start_time'],
            ':et' => $slot['end_time'],
        ]);
        if ($ovStmt->fetch()) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'คุณมีรอบที่ทับซ้อนเวลาเดียวกันอยู่แล้ว']); return;
        }

        // INSERT booking
        $insBook = $pdo->prepare("INSERT INTO sys_scholarship_slot_bookings
            (slot_id, student_id, status) VALUES (:sid, :stud, 'booked')");
        $insBook->execute([':sid' => $slotId, ':stud' => $studentId]);
        $bookingId = (int)$pdo->lastInsertId();

        // Auto-create shift เพื่อให้ระบบ clock-in เดิมหา shift นี้เจอ
        $hours = (strtotime($slot['slot_date'] . ' ' . $slot['end_time'])
                - strtotime($slot['slot_date'] . ' ' . $slot['start_time'])) / 3600;
        $insShift = $pdo->prepare("INSERT INTO sys_scholarship_shifts
            (student_id, slot_id, shift_date, start_time, end_time, planned_hours,
             comp_type, status, notes, created_by)
            VALUES (:stud, :slot, :d, :st, :et, :h, :ct, 'scheduled', :n, NULL)");
        $insShift->execute([
            ':stud' => $studentId,
            ':slot' => $slotId,
            ':d' => $slot['slot_date'],
            ':st' => $slot['start_time'],
            ':et' => $slot['end_time'],
            ':h' => round($hours, 2),
            ':ct' => $slot['comp_type'],
            ':n' => 'จองผ่านระบบรอบงาน',
        ]);
        $shiftId = (int)$pdo->lastInsertId();

        // Link booking → shift
        $pdo->prepare("UPDATE sys_scholarship_slot_bookings SET shift_id = :sid WHERE id = :bid")
            ->execute([':sid' => $shiftId, ':bid' => $bookingId]);

        $pdo->commit();
        echo json_encode([
            'ok' => true,
            'message' => 'จองรอบงานสำเร็จ',
            'booking_id' => $bookingId,
            'shift_id' => $shiftId,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[book_slot] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'จองไม่สำเร็จ: ' . $e->getMessage()]);
    }
}

function cancel_booking(PDO $pdo, int $studentId): void
{
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (!$bookingId) { echo json_encode(['ok' => false, 'error' => 'missing booking_id']); return; }

    $settings = get_scholarship_settings($pdo);
    $cutoffHours = (int)($settings['cancel_cutoff_hours'] ?? 24);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT b.*, s.slot_date, s.start_time, s.end_time
            FROM sys_scholarship_slot_bookings b
            INNER JOIN sys_scholarship_slots s ON s.id = b.slot_id
            WHERE b.id = :bid FOR UPDATE");
        $stmt->execute([':bid' => $bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) { $pdo->rollBack(); echo json_encode(['ok' => false, 'error' => 'ไม่พบการจอง']); return; }
        if ((int)$booking['student_id'] !== $studentId) {
            $pdo->rollBack(); echo json_encode(['ok' => false, 'error' => 'การจองนี้ไม่ใช่ของคุณ']); return;
        }
        if ($booking['status'] !== 'booked') {
            $pdo->rollBack(); echo json_encode(['ok' => false, 'error' => 'การจองนี้ถูกยกเลิกไปแล้ว']); return;
        }

        [$allowed, $err] = can_cancel_scholarship_slot($booking, $cutoffHours);
        if (!$allowed) {
            $pdo->rollBack(); echo json_encode(['ok' => false, 'error' => $err]); return;
        }

        $pdo->prepare("UPDATE sys_scholarship_slot_bookings
            SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = :r
            WHERE id = :bid")
            ->execute([':r' => mb_substr($reason, 0, 255), ':bid' => $bookingId]);

        // ยกเลิก shift ที่ auto-สร้างไว้
        if (!empty($booking['shift_id'])) {
            $pdo->prepare("UPDATE sys_scholarship_shifts SET status = 'cancelled' WHERE id = :sid")
                ->execute([':sid' => (int)$booking['shift_id']]);
        }

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'ยกเลิกการจองสำเร็จ']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[cancel_booking] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'ยกเลิกไม่สำเร็จ']);
    }
}
