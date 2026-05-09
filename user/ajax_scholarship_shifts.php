<?php
/**
 * user/ajax_scholarship_shifts.php
 * นักศึกษาจัดการกะของตัวเอง — list / create / delete
 * เงื่อนไข:
 * - แก้/ลบได้เฉพาะกะที่ยังไม่เริ่มและยังไม่เคยมี clock log
 * - กะวันที่ผ่านไปแล้วลบไม่ได้
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/scholarship_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Auth
$lineUserId = $_SESSION['line_user_id'] ?? '';
$userId = $_SESSION['user_id'] ?? null;
if (!$userId && $lineUserId !== '') {
    try {
        $stmt = db()->prepare("SELECT id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
        $stmt->execute([':lid' => $lineUserId]);
        $row = $stmt->fetch();
        if ($row) { $userId = (int)$row['id']; $_SESSION['user_id'] = $userId; }
    } catch (PDOException) {}
}
if (!$userId) { echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit; }

$pdo = db();
ensure_scholarship_schema($pdo);

$student = get_scholarship_student_by_user($pdo, (int)$userId);
if (!$student || $student['status'] !== 'active') {
    echo json_encode(['ok' => false, 'error' => 'ไม่มีสิทธิ์ใช้งาน']);
    exit;
}
$studentId = (int)$student['id'];
$action = (string)($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'list': {
            // list กะที่ยังไม่ผ่าน + 7 วันย้อนหลัง
            $stmt = $pdo->prepare("SELECT * FROM sys_scholarship_shifts
                WHERE student_id = :sid AND status != 'cancelled'
                  AND shift_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                ORDER BY shift_date ASC, start_time ASC");
            $stmt->execute([':sid' => $studentId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'create': {
            $date = (string)($_POST['shift_date'] ?? '');
            $start = (string)($_POST['start_time'] ?? '');
            $end = (string)($_POST['end_time'] ?? '');
            $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 200);

            if (!$date || !$start || !$end) { echo json_encode(['ok' => false, 'error' => 'กรอกข้อมูลให้ครบ']); return; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['ok' => false, 'error' => 'รูปแบบวันที่ไม่ถูกต้อง']); return; }
            if ($date < date('Y-m-d')) { echo json_encode(['ok' => false, 'error' => 'ลงตารางย้อนหลังไม่ได้']); return; }
            if (strtotime("$date $start") >= strtotime("$date $end")) {
                echo json_encode(['ok' => false, 'error' => 'เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด']); return;
            }

            // กันลงซ้ำเวลาเดียวกัน
            $dup = $pdo->prepare("SELECT id FROM sys_scholarship_shifts
                WHERE student_id = :sid AND shift_date = :d AND status != 'cancelled'
                  AND ((start_time <= :st AND end_time > :st) OR (start_time < :et AND end_time >= :et) OR (start_time >= :st AND end_time <= :et))
                LIMIT 1");
            $dup->execute([':sid' => $studentId, ':d' => $date, ':st' => $start, ':et' => $end]);
            if ($dup->fetchColumn()) {
                echo json_encode(['ok' => false, 'error' => 'มีกะคาบเกี่ยวเวลานี้อยู่แล้ว']); return;
            }

            $hours = round((strtotime("$date $end") - strtotime("$date $start")) / 3600, 2);
            $stmt = $pdo->prepare("INSERT INTO sys_scholarship_shifts
                (student_id, shift_date, start_time, end_time, planned_hours, notes, created_by)
                VALUES (:sid, :d, :st, :et, :h, :n, :by)");
            $stmt->execute([
                ':sid' => $studentId, ':d' => $date, ':st' => $start, ':et' => $end,
                ':h' => $hours, ':n' => $notes, ':by' => (int)$userId,
            ]);
            echo json_encode(['ok' => true, 'message' => 'ลงตารางเรียบร้อย']);
            return;
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
            // ลบได้เฉพาะกะของตัวเอง + วันที่ยังไม่มาถึง + ไม่มี clock log อ้างอิง
            $check = $pdo->prepare("SELECT shift_date FROM sys_scholarship_shifts WHERE id = :id AND student_id = :sid LIMIT 1");
            $check->execute([':id' => $id, ':sid' => $studentId]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'error' => 'ไม่พบกะ']); return; }
            if ($row['shift_date'] < date('Y-m-d')) {
                echo json_encode(['ok' => false, 'error' => 'ลบกะของวันที่ผ่านไปแล้วไม่ได้']); return;
            }
            $hasLog = $pdo->prepare("SELECT id FROM sys_scholarship_clock_logs WHERE shift_id = :id LIMIT 1");
            $hasLog->execute([':id' => $id]);
            if ($hasLog->fetchColumn()) {
                echo json_encode(['ok' => false, 'error' => 'กะนี้มีการลงเวลาแล้ว ลบไม่ได้']); return;
            }
            $del = $pdo->prepare("UPDATE sys_scholarship_shifts SET status = 'cancelled' WHERE id = :id AND student_id = :sid");
            $del->execute([':id' => $id, ':sid' => $studentId]);
            echo json_encode(['ok' => true]);
            return;
        }
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('[ajax_scholarship_shifts] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
