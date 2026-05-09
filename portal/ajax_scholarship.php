<?php
/**
 * portal/ajax_scholarship.php
 * Unified AJAX endpoint สำหรับระบบนักศึกษาทุน
 * Entities: approvals, students, shifts, reports, settings
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/scholarship_helper.php';

$pdo = db();
ensure_scholarship_schema($pdo);

$action = $_REQUEST['action'] ?? '';
$entity = $_REQUEST['entity'] ?? '';

// CSRF (ยกเว้น GET export_csv ที่ส่ง csrf ใน query string เช่นกัน)
if (!isset($_REQUEST['csrf_token']) || !verify_csrf_token($_REQUEST['csrf_token'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// CSV export ใช้ header ต่างหาก
if ($entity !== 'reports' || $action !== 'export_csv') {
    header('Content-Type: application/json; charset=utf-8');
}

$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['student_id'] ?? 0);

try {
    switch ($entity) {
        case 'approvals':   handle_approvals($pdo, $action, $adminId); break;
        case 'students':    handle_students($pdo, $action); break;
        case 'shifts':      handle_shifts($pdo, $action, $adminId); break;
        case 'reports':     handle_reports($pdo, $action); break;
        case 'settings':    handle_settings($pdo, $action); break;
        case 'adjustments': handle_adjustments($pdo, $action, $adminId); break;
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown entity']);
    }
} catch (Throwable $e) {
    error_log('[ajax_scholarship] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────
// APPROVALS
// ─────────────────────────────────────────────────────────────────────
function handle_approvals(PDO $pdo, string $action, int $adminId): void
{
    if ($action === 'list') {
        $q = trim((string)($_POST['q'] ?? ''));
        $where = "WHERE l.status = 'pending'";
        $params = [];
        if ($q !== '') {
            $where .= " AND (u.full_name LIKE :q1 OR s.student_code LIKE :q2 OR s.faculty LIKE :q3)";
            $like = '%' . $q . '%';
            $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
        }
        $sql = "SELECT l.*, u.full_name AS student_name, s.student_code, s.faculty,
                       sh.shift_date, sh.start_time AS sh_start, sh.end_time AS sh_end
                FROM sys_scholarship_clock_logs l
                INNER JOIN sys_scholarship_students s ON s.id = l.student_id
                INNER JOIN sys_users u ON u.id = s.user_id
                LEFT JOIN sys_scholarship_shifts sh ON sh.id = l.shift_id
                $where
                ORDER BY l.event_at ASC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['shift_label'] = $r['shift_date']
                ? sprintf('%s %s–%s', $r['shift_date'], substr((string)$r['sh_start'], 0, 5), substr((string)$r['sh_end'], 0, 5))
                : null;
            $r['distance_m'] = $r['distance_m'] !== null ? (float)$r['distance_m'] : null;
            $r['within_radius'] = (int)$r['within_radius'];
        }
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'approve' || $action === 'reject') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $reason = $action === 'reject' ? trim((string)($_POST['reason'] ?? '')) : '';
        $stmt = $pdo->prepare("UPDATE sys_scholarship_clock_logs
            SET status = :s, approved_by = :a, approved_at = NOW(), reject_reason = :r
            WHERE id = :id AND status = 'pending'");
        $ok = $stmt->execute([':s' => $newStatus, ':a' => $adminId, ':r' => $reason, ':id' => $id]);
        echo json_encode(['ok' => $ok && $stmt->rowCount() > 0]);
        return;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

// ─────────────────────────────────────────────────────────────────────
// STUDENTS
// ─────────────────────────────────────────────────────────────────────
function handle_students(PDO $pdo, string $action): void
{
    if ($action === 'list') {
        $q = trim((string)($_POST['q'] ?? ''));
        $statusF = (string)($_POST['status'] ?? '');
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = 20;

        $where = "WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $where .= " AND (u.full_name LIKE :q1 OR s.student_code LIKE :q2 OR s.faculty LIKE :q3)";
            $like = '%' . $q . '%';
            $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like;
        }
        if (in_array($statusF, ['active','inactive'], true)) {
            $where .= " AND s.status = :st";
            $params[':st'] = $statusF;
        }

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_students s INNER JOIN sys_users u ON u.id = s.user_id $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT s.*, u.full_name, u.first_name, u.last_name
                FROM sys_scholarship_students s
                INNER JOIN sys_users u ON u.id = s.user_id
                $where
                ORDER BY s.status ASC, u.full_name ASC
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // คำนวณชั่วโมงรวมต่อคน
        foreach ($rows as &$r) {
            $r['hours_total'] = sum_scholarship_hours($pdo, (int)$r['id']);
            $r['max_hours'] = (int)$r['max_hours'];
        }

        echo json_encode([
            'ok' => true,
            'rows' => $rows,
            'pagination' => ['page' => $page, 'total_pages' => $totalPages, 'total' => $total],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'search_users') {
        $q = trim((string)($_POST['q'] ?? ''));
        if ($q === '' || mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'rows' => []]); return; }
        $stmt = $pdo->prepare("SELECT id, full_name, first_name, last_name, phone_number AS phone,
                                       student_personnel_id, department, email
            FROM sys_users
            WHERE (full_name LIKE :q1 OR first_name LIKE :q2 OR last_name LIKE :q3
                   OR phone_number LIKE :q4 OR student_personnel_id LIKE :q5 OR email LIKE :q6)
            LIMIT 15");
        $like = '%' . $q . '%';
        $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like, ':q6' => $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        if (!$userId) { echo json_encode(['ok' => false, 'error' => 'กรุณาเลือก user']); return; }

        $data = [
            ':uid'     => $userId,
            ':code'    => trim((string)($_POST['student_code'] ?? '')),
            ':fac'     => trim((string)($_POST['faculty'] ?? '')),
            ':type'    => trim((string)($_POST['scholarship_type'] ?? '')),
            ':sem'     => trim((string)($_POST['semester'] ?? '')),
            ':maxh'    => max(0, (int)($_POST['max_hours'] ?? 0)),
            ':notes'   => trim((string)($_POST['notes'] ?? '')),
            ':status'  => in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active',
        ];

        if ($action === 'create') {
            $exists = $pdo->prepare("SELECT id FROM sys_scholarship_students WHERE user_id = :uid LIMIT 1");
            $exists->execute([':uid' => $userId]);
            if ($exists->fetchColumn()) { echo json_encode(['ok' => false, 'error' => 'User นี้ถูกเพิ่มเป็นนักศึกษาทุนแล้ว']); return; }

            $stmt = $pdo->prepare("INSERT INTO sys_scholarship_students
                (user_id, student_code, faculty, scholarship_type, semester, max_hours, notes, status)
                VALUES (:uid, :code, :fac, :type, :sem, :maxh, :notes, :status)");
            $stmt->execute($data);
        } else {
            if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
            $data[':id'] = $id;
            $stmt = $pdo->prepare("UPDATE sys_scholarship_students SET
                user_id = :uid, student_code = :code, faculty = :fac, scholarship_type = :type,
                semester = :sem, max_hours = :maxh, notes = :notes, status = :status
                WHERE id = :id");
            $stmt->execute($data);
        }
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
        $stmt = $pdo->prepare("DELETE FROM sys_scholarship_students WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        return;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

// ─────────────────────────────────────────────────────────────────────
// SHIFTS
// ─────────────────────────────────────────────────────────────────────
function handle_shifts(PDO $pdo, string $action, int $adminId): void
{
    if ($action === 'list') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $from = (string)($_POST['from'] ?? '');
        $to   = (string)($_POST['to']   ?? '');
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = 20;

        $where = "WHERE sh.status != 'cancelled'";
        $params = [];
        if ($studentId) { $where .= " AND sh.student_id = :sid"; $params[':sid'] = $studentId; }
        if ($from) { $where .= " AND sh.shift_date >= :from"; $params[':from'] = $from; }
        if ($to)   { $where .= " AND sh.shift_date <= :to";   $params[':to']   = $to; }

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_shifts sh $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT sh.*, u.full_name AS student_name
            FROM sys_scholarship_shifts sh
            INNER JOIN sys_scholarship_students s ON s.id = sh.student_id
            INNER JOIN sys_users u ON u.id = s.user_id
            $where
            ORDER BY sh.shift_date DESC, sh.start_time ASC
            LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode([
            'ok' => true, 'rows' => $rows,
            'pagination' => ['page' => $page, 'total_pages' => $totalPages, 'total' => $total],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $date = (string)($_POST['shift_date'] ?? '');
        $start = (string)($_POST['start_time'] ?? '');
        $end = (string)($_POST['end_time'] ?? '');
        $notes = trim((string)($_POST['notes'] ?? ''));
        $compType = (string)($_POST['comp_type'] ?? 'hours');
        if (!in_array($compType, ['hours', 'paid'], true)) $compType = 'hours';

        if (!$studentId || !$date || !$start || !$end) {
            echo json_encode(['ok' => false, 'error' => 'กรอกข้อมูลให้ครบ']); return;
        }
        if (strtotime("$date $start") >= strtotime("$date $end")) {
            echo json_encode(['ok' => false, 'error' => 'เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด']); return;
        }

        $hours = (strtotime("$date $end") - strtotime("$date $start")) / 3600;

        $params = [
            ':sid' => $studentId, ':d' => $date, ':st' => $start, ':et' => $end,
            ':h' => round($hours, 2), ':ct' => $compType, ':n' => $notes,
        ];

        if ($action === 'create') {
            $params[':by'] = $adminId;
            $stmt = $pdo->prepare("INSERT INTO sys_scholarship_shifts
                (student_id, shift_date, start_time, end_time, planned_hours, comp_type, notes, created_by)
                VALUES (:sid, :d, :st, :et, :h, :ct, :n, :by)");
            $stmt->execute($params);
        } else {
            $params[':id'] = $id;
            $stmt = $pdo->prepare("UPDATE sys_scholarship_shifts SET
                student_id = :sid, shift_date = :d, start_time = :st, end_time = :et,
                planned_hours = :h, comp_type = :ct, notes = :n
                WHERE id = :id");
            $stmt->execute($params);
        }
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
        $stmt = $pdo->prepare("UPDATE sys_scholarship_shifts SET status = 'cancelled' WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        return;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

// ─────────────────────────────────────────────────────────────────────
// REPORTS
// ─────────────────────────────────────────────────────────────────────
function handle_reports(PDO $pdo, string $action): void
{
    if ($action === 'summary') {
        $from = (string)($_POST['from'] ?? date('Y-m-01'));
        $to   = (string)($_POST['to']   ?? date('Y-m-t'));

        $rows = compute_report_rows($pdo, $from, $to);
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'export_csv') {
        $from = (string)($_GET['from'] ?? date('Y-m-01'));
        $to   = (string)($_GET['to']   ?? date('Y-m-t'));

        $rows = compute_report_rows($pdo, $from, $to);

        $filename = "scholarship_hours_{$from}_to_{$to}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ชื่อ', 'รหัสนักศึกษา', 'คณะ', 'ภาคเรียน', 'จำนวนครั้งเข้างาน',
            'ชั่วโมงทุน', 'ชั่วโมงค่าตอบแทน', 'ชั่วโมงรวม', 'เป้าชั่วโมง', '% ความคืบหน้า (ทุน)']);
        foreach ($rows as $r) {
            $pct = (int)$r['max_hours'] > 0 ? round(($r['hours_scholarship'] / $r['max_hours']) * 100) . '%' : '-';
            fputcsv($out, [
                $r['full_name'], $r['student_code'], $r['faculty'], $r['semester'],
                $r['checkins'],
                number_format((float)$r['hours_scholarship'], 2),
                number_format((float)$r['hours_paid'], 2),
                number_format((float)$r['hours'], 2),
                $r['max_hours'] > 0 ? $r['max_hours'] : '-', $pct,
            ]);
        }
        fclose($out);
        return;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

function compute_report_rows(PDO $pdo, string $from, string $to): array
{
    $stmt = $pdo->prepare("SELECT s.*, u.full_name FROM sys_scholarship_students s
        INNER JOIN sys_users u ON u.id = s.user_id
        WHERE s.status = 'active'
        ORDER BY u.full_name ASC");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    foreach ($students as $s) {
        $split = sum_scholarship_hours_split($pdo, (int)$s['id'], $from, $to);
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_clock_logs
            WHERE student_id = :sid AND action = 'clock_in' AND status = 'approved'
              AND event_at >= :from AND event_at <= :to");
        $cntStmt->execute([':sid' => $s['id'], ':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59']);
        $checkins = (int)$cntStmt->fetchColumn();

        $rows[] = [
            'student_id' => (int)$s['id'],
            'full_name' => $s['full_name'],
            'student_code' => $s['student_code'],
            'faculty' => $s['faculty'],
            'semester' => $s['semester'],
            'checkins' => $checkins,
            'hours_scholarship' => (float)$split['hours'],
            'hours_paid' => (float)$split['paid'],
            'hours' => (float)($split['hours'] + $split['paid']),
            'max_hours' => (int)$s['max_hours'],
        ];
    }
    return $rows;
}

// ─────────────────────────────────────────────────────────────────────
// MANUAL ADJUSTMENTS — admin ปรับชั่วโมงบวก/ลบด้วยมือ
// ─────────────────────────────────────────────────────────────────────
function handle_adjustments(PDO $pdo, string $action, int $adminId): void
{
    if ($action === 'list') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        if (!$studentId) { echo json_encode(['ok' => false, 'error' => 'missing student_id']); return; }
        $stmt = $pdo->prepare("SELECT a.*, COALESCE(u.full_name, '') AS created_by_name
            FROM sys_scholarship_manual_adjustments a
            LEFT JOIN sys_users u ON u.id = a.created_by
            WHERE a.student_id = :sid
            ORDER BY a.adjusted_date DESC, a.id DESC LIMIT 100");
        $stmt->execute([':sid' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'create') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $compType = (string)($_POST['comp_type'] ?? 'hours');
        $delta = (float)($_POST['hours_delta'] ?? 0);
        $date = (string)($_POST['adjusted_date'] ?? date('Y-m-d'));
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);

        if (!$studentId) { echo json_encode(['ok' => false, 'error' => 'missing student_id']); return; }
        if (!in_array($compType, ['hours', 'paid'], true)) { echo json_encode(['ok' => false, 'error' => 'invalid comp_type']); return; }
        if ($delta == 0) { echo json_encode(['ok' => false, 'error' => 'จำนวนชั่วโมงต้องไม่เป็น 0']); return; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo json_encode(['ok' => false, 'error' => 'รูปแบบวันที่ไม่ถูกต้อง']); return; }
        if ($reason === '') { echo json_encode(['ok' => false, 'error' => 'กรุณาระบุเหตุผล']); return; }

        $stmt = $pdo->prepare("INSERT INTO sys_scholarship_manual_adjustments
            (student_id, comp_type, hours_delta, adjusted_date, reason, created_by)
            VALUES (:sid, :ct, :h, :d, :r, :by)");
        $stmt->execute([
            ':sid' => $studentId, ':ct' => $compType, ':h' => $delta,
            ':d' => $date, ':r' => $reason, ':by' => $adminId,
        ]);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
        $stmt = $pdo->prepare("DELETE FROM sys_scholarship_manual_adjustments WHERE id = :id");
        $stmt->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        return;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

// ─────────────────────────────────────────────────────────────────────
// SETTINGS
// ─────────────────────────────────────────────────────────────────────
function handle_settings(PDO $pdo, string $action): void
{
    if ($action === 'save') {
        $ok = save_scholarship_settings($pdo, [
            'clinic_lat' => $_POST['clinic_lat'] ?? '',
            'clinic_lng' => $_POST['clinic_lng'] ?? '',
            'radius_m' => $_POST['radius_m'] ?? SCHOLARSHIP_DEFAULT_RADIUS_M,
            'grace_before_min' => $_POST['grace_before_min'] ?? SCHOLARSHIP_GRACE_BEFORE_MIN,
            'require_approval' => $_POST['require_approval'] ?? 0,
            'gps_required' => $_POST['gps_required'] ?? 0,
        ]);
        echo json_encode(['ok' => $ok]);
        return;
    }
    if ($action === 'get') {
        echo json_encode(['ok' => true, 'settings' => get_scholarship_settings($pdo)], JSON_UNESCAPED_UNICODE);
        return;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
