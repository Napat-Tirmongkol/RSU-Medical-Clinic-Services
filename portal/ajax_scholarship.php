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
require_once __DIR__ . '/../includes/clinic_status_helper.php';

// Authorization: superadmin หรือมี access_scholarship flag เท่านั้น
$_role = $_SESSION['admin_role'] ?? '';
if ($_role !== 'superadmin' && empty($_SESSION['access_scholarship'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied (access_scholarship required)']);
    exit;
}

$pdo = db();
ensure_scholarship_schema($pdo);

$action = $_REQUEST['action'] ?? '';
$entity = $_REQUEST['entity'] ?? '';

// CSRF (POST only — รองรับ GET เฉพาะ no-op ไม่ใช้แล้ว เพราะ CSV export ย้ายเป็น POST แล้ว)
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// CSV export ใช้ header ต่างหาก
$isCsvExport = ($entity === 'reports' || $entity === 'payouts') && $action === 'export_csv';
if (!$isCsvExport) {
    header('Content-Type: application/json; charset=utf-8');
}

$adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['student_id'] ?? 0);

try {
    switch ($entity) {
        case 'dashboard':   handle_dashboard($pdo, $action); break;
        case 'approvals':   handle_approvals($pdo, $action, $adminId); break;
        case 'students':    handle_students($pdo, $action); break;
        case 'shifts':      handle_shifts($pdo, $action, $adminId); break;
        case 'slots':       handle_slots($pdo, $action, $adminId); break;
        case 'reports':     handle_reports($pdo, $action); break;
        case 'settings':    handle_settings($pdo, $action); break;
        case 'adjustments': handle_adjustments($pdo, $action, $adminId); break;
        case 'payouts':     handle_payouts($pdo, $action, $adminId); break;
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown entity']);
    }
} catch (Throwable $e) {
    error_log('[ajax_scholarship] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

// ─────────────────────────────────────────────────────────────────────
// DASHBOARD
// ─────────────────────────────────────────────────────────────────────
function handle_dashboard(PDO $pdo, string $action): void
{
    if ($action !== 'get') { echo json_encode(['ok' => false, 'error' => 'Unknown action']); return; }

    $today = date('Y-m-d');
    $monthFrom = date('Y-m-01');
    $monthTo = date('Y-m-t');
    $from30 = date('Y-m-d', strtotime('-29 days'));

    // KPIs ─────────────────────────────────
    $activeStudents = (int)$pdo->query("SELECT COUNT(*) FROM sys_scholarship_students WHERE status='active'")->fetchColumn();
    $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM sys_scholarship_clock_logs WHERE status='pending'")->fetchColumn();
    $tsStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_shifts WHERE shift_date = :d AND status != 'cancelled'");
    $tsStmt->execute([':d' => $today]);
    $todayShifts = (int)$tsStmt->fetchColumn();

    // ชั่วโมงเดือนนี้รวมทุกคน (แยกประเภท)
    $monthSplit = ['hours' => 0.0, 'paid' => 0.0];
    $stmt = $pdo->query("SELECT id FROM sys_scholarship_students WHERE status='active'");
    $activeIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($activeIds as $sid) {
        $split = sum_scholarship_hours_split($pdo, (int)$sid, $monthFrom, $monthTo);
        $monthSplit['hours'] += $split['hours'];
        $monthSplit['paid']  += $split['paid'];
    }

    // Daily 30-day chart ──────────────────
    $daily = compute_dashboard_daily($pdo, $from30, $today);
    // เติมวันที่ขาดเป็น 0 และเรียงตามลำดับ
    $dailyArr = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $dailyArr[] = [
            'date' => $d,
            'label' => date('d/m', strtotime($d)),
            'hours' => $daily[$d]['hours'] ?? 0,
            'paid'  => $daily[$d]['paid']  ?? 0,
        ];
    }

    // Top 5 students this month ───────────
    $topRows = [];
    foreach ($activeIds as $sid) {
        $split = sum_scholarship_hours_split($pdo, (int)$sid, $monthFrom, $monthTo);
        $sum = $split['hours'] + $split['paid'];
        if ($sum <= 0) continue;
        $topRows[] = [
            'student_id' => (int)$sid,
            'hours_scholarship' => $split['hours'],
            'hours_paid'  => $split['paid'],
            'total' => $sum,
        ];
    }
    usort($topRows, fn($a, $b) => $b['total'] <=> $a['total']);
    $topRows = array_slice($topRows, 0, 5);
    // เติมชื่อ
    if ($topRows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['student_id'], $topRows));
        $names = $pdo->query("SELECT s.id, u.full_name, s.student_code, s.faculty
            FROM sys_scholarship_students s
            INNER JOIN sys_users u ON u.id = s.user_id
            WHERE s.id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
        $nameMap = [];
        foreach ($names as $n) $nameMap[$n['id']] = $n;
        foreach ($topRows as &$tr) {
            $info = $nameMap[$tr['student_id']] ?? null;
            $tr['full_name'] = $info['full_name'] ?? '-';
            $tr['student_code'] = $info['student_code'] ?? '';
            $tr['faculty'] = $info['faculty'] ?? '';
        }
    }

    // Today's shift status ────────────────
    $shStmt = $pdo->prepare("SELECT sh.*, u.full_name AS student_name, s.student_code
        FROM sys_scholarship_shifts sh
        INNER JOIN sys_scholarship_students s ON s.id = sh.student_id
        INNER JOIN sys_users u ON u.id = s.user_id
        WHERE sh.shift_date = :today AND sh.status != 'cancelled'
        ORDER BY sh.start_time ASC");
    $shStmt->execute([':today' => $today]);
    $todayList = $shStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($todayList as &$sh) {
        // ตรวจว่ามี clock_in ของวันนี้ของ student คนนี้บ้างไหม
        $cl = $pdo->prepare("SELECT action, status, event_at FROM sys_scholarship_clock_logs
            WHERE student_id = :sid AND event_at >= :start
            ORDER BY event_at DESC LIMIT 1");
        $cl->execute([':sid' => $sh['student_id'], ':start' => $today . ' 00:00:00']);
        $latest = $cl->fetch(PDO::FETCH_ASSOC);
        if (!$latest) {
            $sh['arrival_status'] = 'absent';
        } elseif ($latest['action'] === 'clock_in') {
            $sh['arrival_status'] = 'in';
        } else {
            $sh['arrival_status'] = 'out';
        }
        $sh['latest_event_at'] = $latest['event_at'] ?? null;
    }

    // Recent activity (10 latest) ─────────
    $recent = $pdo->query("SELECT l.*, u.full_name AS student_name
        FROM sys_scholarship_clock_logs l
        INNER JOIN sys_scholarship_students s ON s.id = l.student_id
        INNER JOIN sys_users u ON u.id = s.user_id
        ORDER BY l.event_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $settings = get_scholarship_settings($pdo);
    $rate = (float)$settings['pay_rate_per_hour'];
    $monthPay = $monthSplit['paid'] * $rate;

    echo json_encode([
        'ok' => true,
        'kpis' => [
            'active_students' => $activeStudents,
            'pending' => $pendingCount,
            'today_shifts' => $todayShifts,
            'month_hours' => round($monthSplit['hours'], 1),
            'month_paid' => round($monthSplit['paid'], 1),
            'month_pay' => round($monthPay, 2),
            'pay_rate' => $rate,
        ],
        'daily' => $dailyArr,
        'top' => $topRows,
        'today_list' => $todayList,
        'recent' => $recent,
    ], JSON_UNESCAPED_UNICODE);
}

function compute_dashboard_daily(PDO $pdo, string $fromDate, string $toDate): array
{
    $stmt = $pdo->prepare("SELECT student_id, action, comp_type, event_at
        FROM sys_scholarship_clock_logs
        WHERE status = 'approved'
          AND event_at >= :from AND event_at <= :to
        ORDER BY student_id ASC, event_at ASC, id ASC");
    $stmt->execute([':from' => $fromDate . ' 00:00:00', ':to' => $toDate . ' 23:59:59']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byDate = [];
    $openByStudent = [];
    foreach ($rows as $r) {
        $sid = (int)$r['student_id'];
        if ($r['action'] === 'clock_in') {
            $openByStudent[$sid] = $r;
        } elseif ($r['action'] === 'clock_out' && isset($openByStudent[$sid])) {
            $in = $openByStudent[$sid];
            $sec = max(0, strtotime($r['event_at']) - strtotime($in['event_at']));
            $h = (int)floor($sec / 3600);
            if ($h > 0) {
                $date = substr($in['event_at'], 0, 10);
                $type = $in['comp_type'] ?? 'hours';
                if (!isset($byDate[$date])) $byDate[$date] = ['hours' => 0, 'paid' => 0];
                $byDate[$date][$type] += $h;
            }
            unset($openByStudent[$sid]);
        }
    }

    // ผนวก adjustments เข้าไปด้วย
    $adj = $pdo->prepare("SELECT adjusted_date, comp_type, SUM(hours_delta) AS total
        FROM sys_scholarship_manual_adjustments
        WHERE adjusted_date >= :from AND adjusted_date <= :to
        GROUP BY adjusted_date, comp_type");
    $adj->execute([':from' => $fromDate, ':to' => $toDate]);
    foreach ($adj->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $d = $a['adjusted_date'];
        $t = $a['comp_type'];
        if (!isset($byDate[$d])) $byDate[$d] = ['hours' => 0, 'paid' => 0];
        $byDate[$d][$t] += (float)$a['total'];
    }

    return $byDate;
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
// SLOTS — รอบงานที่ admin เปิดให้นักศึกษาทุนจอง (คล้าย camp_slots)
// ─────────────────────────────────────────────────────────────────────
function handle_slots(PDO $pdo, string $action, int $adminId): void
{
    if ($action === 'list') {
        $from = (string)($_POST['from'] ?? '');
        $to   = (string)($_POST['to']   ?? '');
        $statusFilter = (string)($_POST['status_filter'] ?? 'all');
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = 20;

        $statuses = ['open','closed','cancelled'];
        if (in_array($statusFilter, $statuses, true)) $statuses = [$statusFilter];

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $where = "WHERE s.status IN ($placeholders)";
        $params = $statuses;
        if ($from) { $where .= " AND s.slot_date >= ?"; $params[] = $from; }
        if ($to)   { $where .= " AND s.slot_date <= ?"; $params[] = $to; }

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_slots s $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT s.*,
                COALESCE((SELECT COUNT(*) FROM sys_scholarship_slot_bookings b
                          WHERE b.slot_id = s.id AND b.status = 'booked'), 0) AS booked_count
            FROM sys_scholarship_slots s
            $where
            ORDER BY s.slot_date DESC, s.start_time ASC
            LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['booked_count'] = (int)$r['booked_count'];
            $r['max_capacity'] = (int)$r['max_capacity'];
            $r['available']    = max(0, $r['max_capacity'] - $r['booked_count']);
        }
        echo json_encode([
            'ok' => true, 'rows' => $rows,
            'pagination' => ['page' => $page, 'total_pages' => $totalPages, 'total' => $total],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'create' || $action === 'bulk_create') {
        // bulk_create: รับ slot_dates (CSV) + times (array ของ ['start','end'])
        // create: slot_date + start_time + end_time เดี่ยว
        $maxCap = max(1, (int)($_POST['max_capacity'] ?? 1));
        $compType = (string)($_POST['comp_type'] ?? 'hours');
        if (!in_array($compType, ['hours','paid'], true)) $compType = 'hours';
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($action === 'create') {
            $dates = [(string)($_POST['slot_date'] ?? '')];
            $times = [[
                'start' => (string)($_POST['start_time'] ?? ''),
                'end'   => (string)($_POST['end_time'] ?? ''),
            ]];
        } else {
            $datesRaw = $_POST['slot_dates'] ?? '';
            $dates = array_filter(array_map('trim', is_array($datesRaw) ? $datesRaw : explode(',', $datesRaw)));
            $timesRaw = $_POST['times'] ?? [];
            $times = [];
            if (is_array($timesRaw)) {
                foreach ($timesRaw as $t) {
                    if (is_array($t) && !empty($t['start']) && !empty($t['end'])) {
                        $times[] = ['start' => $t['start'], 'end' => $t['end']];
                    }
                }
            }
        }

        if (empty($dates) || empty($times)) {
            echo json_encode(['ok' => false, 'error' => 'กรอกวันและเวลาให้ครบ']); return;
        }

        $stmt = $pdo->prepare("INSERT INTO sys_scholarship_slots
            (slot_date, start_time, end_time, max_capacity, comp_type, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        $createdCount = 0;
        $errors = [];
        foreach ($dates as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $errors[] = "วันที่ไม่ถูกต้อง: $d"; continue;
            }
            foreach ($times as $t) {
                $start = $t['start']; $end = $t['end'];
                if (strtotime("$d $start") >= strtotime("$d $end")) {
                    $errors[] = "$d $start-$end: เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด"; continue;
                }
                try {
                    $stmt->execute([$d, $start, $end, $maxCap, $compType, $notes, $adminId]);
                    $createdCount++;
                } catch (PDOException $e) {
                    $errors[] = "$d $start-$end: " . $e->getMessage();
                }
            }
        }

        echo json_encode([
            'ok' => $createdCount > 0,
            'created' => $createdCount,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $date = (string)($_POST['slot_date'] ?? '');
        $start = (string)($_POST['start_time'] ?? '');
        $end = (string)($_POST['end_time'] ?? '');
        $maxCap = max(1, (int)($_POST['max_capacity'] ?? 1));
        $compType = (string)($_POST['comp_type'] ?? 'hours');
        if (!in_array($compType, ['hours','paid'], true)) $compType = 'hours';
        $notes = trim((string)($_POST['notes'] ?? ''));
        $status = (string)($_POST['status'] ?? 'open');
        if (!in_array($status, ['open','closed','cancelled'], true)) $status = 'open';

        if (!$id || !$date || !$start || !$end) {
            echo json_encode(['ok' => false, 'error' => 'กรอกข้อมูลให้ครบ']); return;
        }
        if (strtotime("$date $start") >= strtotime("$date $end")) {
            echo json_encode(['ok' => false, 'error' => 'เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด']); return;
        }

        // ห้ามลด max_capacity ต่ำกว่าจำนวนคนที่จองแล้ว
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_slot_bookings
            WHERE slot_id = :id AND status = 'booked'");
        $cntStmt->execute([':id' => $id]);
        $booked = (int)$cntStmt->fetchColumn();
        if ($maxCap < $booked) {
            echo json_encode(['ok' => false, 'error' => "มีนักศึกษาจองแล้ว $booked คน — ลดจำนวนต่ำกว่านี้ไม่ได้"]);
            return;
        }

        $stmt = $pdo->prepare("UPDATE sys_scholarship_slots SET
            slot_date = :d, start_time = :st, end_time = :et,
            max_capacity = :cap, comp_type = :ct, notes = :n, status = :s
            WHERE id = :id");
        $stmt->execute([
            ':d' => $date, ':st' => $start, ':et' => $end,
            ':cap' => $maxCap, ':ct' => $compType, ':n' => $notes,
            ':s' => $status, ':id' => $id,
        ]);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }

        // Hard delete: ลบ slot + booking ทั้งหมด พร้อม cancel auto-shifts
        // (เก็บ clock_logs ผูก shift_id ไว้เพื่อ audit ของชั่วโมงที่นักศึกษาทำไปแล้ว)
        $pdo->beginTransaction();
        try {
            // ดึง shift_id ทั้งหมด (เพื่อ cancel ไม่ลบ — เก็บ history clock-in/out)
            $bookings = $pdo->prepare("SELECT id, shift_id FROM sys_scholarship_slot_bookings
                WHERE slot_id = :id");
            $bookings->execute([':id' => $id]);
            $bks = $bookings->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $cancelShift = $pdo->prepare("UPDATE sys_scholarship_shifts
                SET status = 'cancelled' WHERE id = :sid");
            $activeCancelled = 0;
            foreach ($bks as $b) {
                if (!empty($b['shift_id'])) $cancelShift->execute([':sid' => (int)$b['shift_id']]);
                // นับเฉพาะ active bookings สำหรับ feedback
                // (ไม่ดึง status ออกมาด้วย — ดูจาก row ที่เคย booked)
            }

            // นับ booking ที่ยัง active ก่อนลบ (สำหรับ message)
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_scholarship_slot_bookings
                WHERE slot_id = :id AND status = 'booked'");
            $cntStmt->execute([':id' => $id]);
            $activeCancelled = (int)$cntStmt->fetchColumn();

            // Hard delete bookings + slot
            $pdo->prepare("DELETE FROM sys_scholarship_slot_bookings WHERE slot_id = :id")
                ->execute([':id' => $id]);
            $pdo->prepare("DELETE FROM sys_scholarship_slots WHERE id = :id")
                ->execute([':id' => $id]);

            $pdo->commit();
            echo json_encode(['ok' => true, 'cancelled_bookings' => $activeCancelled]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'ลบไม่สำเร็จ']);
        }
        return;
    }

    if ($action === 'calendar') {
        // คืน per-day summary: slots + bookings + clinic closure
        $from = (string)($_POST['from'] ?? date('Y-m-01'));
        $to   = (string)($_POST['to']   ?? date('Y-m-t'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid date']); return;
        }

        // 1) ดึง slot + booking + student ในช่วงเดียวกัน 1 query
        //    + check ว่า booking นี้ clock-in แล้วยัง (จาก clock_logs ผูก shift_id)
        $sql = "SELECT s.id AS slot_id, s.slot_date, s.start_time, s.end_time,
                       s.max_capacity, s.comp_type, s.status AS slot_status, s.notes AS slot_notes,
                       b.id AS booking_id, b.shift_id, b.status AS booking_status,
                       u.full_name AS student_name, ss.student_code,
                       EXISTS(SELECT 1 FROM sys_scholarship_clock_logs cl
                              WHERE cl.shift_id = b.shift_id
                                AND cl.action = 'clock_in'
                                AND cl.status != 'rejected'
                              LIMIT 1) AS attended
                FROM sys_scholarship_slots s
                LEFT JOIN sys_scholarship_slot_bookings b
                    ON b.slot_id = s.id AND b.status = 'booked'
                LEFT JOIN sys_scholarship_students ss ON ss.id = b.student_id
                LEFT JOIN sys_users u ON u.id = ss.user_id
                WHERE s.slot_date BETWEEN :from AND :to
                  AND s.status != 'cancelled'
                ORDER BY s.slot_date ASC, s.start_time ASC, b.booked_at ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $from, ':to' => $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 2) group เป็น day → slots → bookings
        $byDay = []; // 'YYYY-MM-DD' => ['slots' => [...]]
        $slotMap = []; // slot_id => &$slotRef
        foreach ($rows as $r) {
            $day = $r['slot_date'];
            if (!isset($byDay[$day])) $byDay[$day] = ['slots' => []];
            if (!isset($slotMap[$r['slot_id']])) {
                $byDay[$day]['slots'][] = [
                    'id' => (int)$r['slot_id'],
                    'start' => substr((string)$r['start_time'], 0, 5),
                    'end'   => substr((string)$r['end_time'], 0, 5),
                    'max'   => (int)$r['max_capacity'],
                    'comp_type' => $r['comp_type'],
                    'status' => $r['slot_status'],
                    'notes' => $r['slot_notes'],
                    'bookings' => [],
                    'attended_count' => 0,
                ];
                $slotMap[$r['slot_id']] = &$byDay[$day]['slots'][count($byDay[$day]['slots']) - 1];
            }
            if (!empty($r['booking_id'])) {
                $attended = (int)$r['attended'] === 1;
                $slotMap[$r['slot_id']]['bookings'][] = [
                    'name' => $r['student_name'],
                    'code' => $r['student_code'],
                    'attended' => $attended,
                ];
                if ($attended) $slotMap[$r['slot_id']]['attended_count']++;
            }
        }
        unset($slotMap);

        // 3) เติม clinic closure info ทุกวันในช่วง
        $days = [];
        $cur = new DateTime($from);
        $endDate = new DateTime($to);
        while ($cur <= $endDate) {
            $d = $cur->format('Y-m-d');
            $hours = get_clinic_hours_for_date($pdo, $d);
            $days[$d] = [
                'clinic_closed' => !empty($hours['closed']),
                'clinic_note'   => $hours['note'] ?? '',
                'clinic_source' => $hours['source'] ?? '',
                'slots'         => $byDay[$d]['slots'] ?? [],
            ];
            $cur->modify('+1 day');
        }

        echo json_encode(['ok' => true, 'from' => $from, 'to' => $to, 'days' => $days],
                         JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'bookings') {
        // ดูรายชื่อนักศึกษาที่จอง slot หนึ่ง
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
        $stmt = $pdo->prepare("SELECT b.*, u.full_name AS student_name, u.picture_url, ss.student_code
            FROM sys_scholarship_slot_bookings b
            INNER JOIN sys_scholarship_students ss ON ss.id = b.student_id
            INNER JOIN sys_users u ON u.id = ss.user_id
            WHERE b.slot_id = :id
            ORDER BY b.status ASC, b.booked_at ASC");
        $stmt->execute([':id' => $id]);
        echo json_encode([
            'ok' => true,
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ], JSON_UNESCAPED_UNICODE);
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
        $settings = get_scholarship_settings($pdo);
        echo json_encode([
            'ok' => true,
            'rows' => $rows,
            'pay_rate' => (float)$settings['pay_rate_per_hour'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'export_csv') {
        $from = (string)($_POST['from'] ?? date('Y-m-01'));
        $to   = (string)($_POST['to']   ?? date('Y-m-t'));

        $rows = compute_report_rows($pdo, $from, $to);

        $filename = "scholarship_hours_{$from}_to_{$to}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $settings = get_scholarship_settings($pdo);
        $rate = (float)$settings['pay_rate_per_hour'];

        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ชื่อ', 'รหัสนักศึกษา', 'คณะ', 'ภาคเรียน', 'จำนวนครั้งเข้างาน',
            'ชั่วโมงทุน', 'ชั่วโมงค่าตอบแทน', 'ชั่วโมงรวม',
            'อัตรา (บาท/ชม.)', 'เงินค่าตอบแทน (บาท)',
            'เป้าชั่วโมง', '% ความคืบหน้า (ทุน)']);
        foreach ($rows as $r) {
            $pct = (int)$r['max_hours'] > 0 ? round(($r['hours_scholarship'] / $r['max_hours']) * 100) . '%' : '-';
            $pay = (float)$r['hours_paid'] * $rate;
            fputcsv($out, [
                $r['full_name'], $r['student_code'], $r['faculty'], $r['semester'],
                $r['checkins'],
                number_format((float)$r['hours_scholarship'], 2),
                number_format((float)$r['hours_paid'], 2),
                number_format((float)$r['hours'], 2),
                number_format($rate, 2),
                number_format($pay, 2),
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
            'pay_rate_per_hour' => $_POST['pay_rate_per_hour'] ?? 0,
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

// ─────────────────────────────────────────────────────────────────────
// PAYOUTS — สถานะการจ่ายเงินรายเดือนให้นักศึกษา
// 2 สถานะ: pending (รอดำเนินการการเงิน) → approved (การเงินอนุมัติ พร้อมรับ)
// ─────────────────────────────────────────────────────────────────────
function handle_payouts(PDO $pdo, string $action, int $adminId): void
{
    $ym = (string)($_POST['period_ym'] ?? $_REQUEST['period_ym'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
        $ym = date('Y-m');
    }

    if ($action === 'list') {
        $statusFilter = (string)($_POST['status'] ?? '');
        if (!in_array($statusFilter, ['pending', 'approved'], true)) $statusFilter = null;
        $search = trim((string)($_POST['q'] ?? ''));

        $rows = list_scholarship_payouts($pdo, $ym, $statusFilter, $search !== '' ? $search : null);
        $summary = scholarship_payout_summary($pdo, $ym);
        $settings = get_scholarship_settings($pdo);

        echo json_encode([
            'ok' => true,
            'period_ym' => $ym,
            'period_label' => scholarship_period_thai($ym),
            'rows' => $rows,
            'summary' => $summary,
            'pay_rate' => (float)$settings['pay_rate_per_hour'],
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'generate') {
        $stats = generate_scholarship_payouts($pdo, $ym, $adminId);
        echo json_encode([
            'ok' => true,
            'period_ym' => $ym,
            'period_label' => scholarship_period_thai($ym),
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($action === 'approve' || $action === 'unapprove') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
        $newStatus = $action === 'approve' ? 'approved' : 'pending';
        $ok = set_scholarship_payout_status($pdo, $id, $newStatus, $adminId);
        $notified = false;
        if ($ok && $action === 'approve') {
            // Best-effort LINE notification → ไม่ break flow ถ้า LINE fail
            $notified = notify_student_payout_approved($pdo, $id);
        }
        echo json_encode(['ok' => $ok, 'line_notified' => $notified]);
        return;
    }

    if ($action === 'bulk_approve') {
        $ids = $_POST['ids'] ?? '';
        $idList = array_values(array_filter(array_map('intval', is_array($ids) ? $ids : explode(',', (string)$ids))));
        if (empty($idList)) { echo json_encode(['ok' => false, 'error' => 'ไม่มีรายการที่เลือก']); return; }

        $newStatus = (string)($_POST['new_status'] ?? 'approved');
        if (!in_array($newStatus, ['pending', 'approved'], true)) {
            echo json_encode(['ok' => false, 'error' => 'invalid status']); return;
        }

        $changed = 0;
        $changedIds = [];
        $pdo->beginTransaction();
        try {
            foreach ($idList as $id) {
                if (set_scholarship_payout_status($pdo, $id, $newStatus, $adminId)) {
                    $changed++;
                    $changedIds[] = $id;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[scholarship_payouts] bulk error: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Bulk update failed']);
            return;
        }

        // LINE notifications — เฉพาะตอน bulk approve (ไม่ใช่ unapprove)
        // ส่งหลัง commit เพื่อกันถ้า push ช้า/ค้าง จะไม่ lock DB tx
        $notified = 0;
        if ($newStatus === 'approved') {
            foreach ($changedIds as $id) {
                if (notify_student_payout_approved($pdo, $id)) $notified++;
            }
        }
        echo json_encode(['ok' => true, 'changed' => $changed, 'line_notified' => $notified]);
        return;
    }

    if ($action === 'update_note') {
        $id = (int)($_POST['id'] ?? 0);
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
        $stmt = $pdo->prepare("UPDATE sys_scholarship_payouts SET note = :n WHERE id = :id");
        $stmt->execute([':id' => $id, ':n' => $note]);
        echo json_encode(['ok' => true]);
        return;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'missing id']); return; }
        $stmt = $pdo->prepare("DELETE FROM sys_scholarship_payouts WHERE id = :id AND status = 'pending'");
        $stmt->execute([':id' => $id]);
        $affected = $stmt->rowCount();
        echo json_encode([
            'ok' => $affected > 0,
            'error' => $affected > 0 ? null : 'ลบไม่ได้ (อาจอนุมัติแล้ว)',
        ]);
        return;
    }

    if ($action === 'export_csv') {
        $rows = list_scholarship_payouts($pdo, $ym, null, null);
        $filename = "scholarship_payouts_{$ym}.csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ชื่อ', 'รหัสนักศึกษา', 'คณะ', 'ภาคเรียน',
            'ชั่วโมงค่าตอบแทน', 'อัตรา (บาท/ชม.)', 'ยอดเงิน (บาท)',
            'สถานะ', 'ผู้อนุมัติ', 'วันที่อนุมัติ', 'หมายเหตุ']);
        $statusLabel = ['pending' => 'รอดำเนินการการเงิน', 'approved' => 'การเงินอนุมัติ (พร้อมรับ)'];
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['full_name'], $r['student_code'], $r['faculty'], $r['semester'],
                number_format((float)$r['hours_paid'], 2),
                number_format((float)$r['pay_rate'], 2),
                number_format((float)$r['amount'], 2),
                $statusLabel[$r['status']] ?? $r['status'],
                $r['approved_by_name'] ?? '',
                $r['approved_at'] ?? '',
                $r['note'] ?? '',
            ]);
        }
        fclose($out);
        return;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
