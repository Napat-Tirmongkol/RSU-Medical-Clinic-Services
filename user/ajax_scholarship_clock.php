<?php
/**
 * user/ajax_scholarship_clock.php — รับ clock in/out จากนักศึกษาทุน
 * Body: action (clock_in|clock_out), lat, lng, accuracy
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
        if ($row) {
            $userId = (int)$row['id'];
            $_SESSION['user_id'] = $userId;
        }
    } catch (PDOException $e) {}
}

if (!$userId) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = (string)($_POST['action'] ?? '');
if (!in_array($action, ['clock_in', 'clock_out'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

$lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
$accuracy = isset($_POST['accuracy']) ? (float)$_POST['accuracy'] : 0;

if ($lat === null || $lng === null) {
    echo json_encode(['ok' => false, 'error' => 'ไม่พบข้อมูลตำแหน่ง GPS']);
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
$settings = get_scholarship_settings($pdo);

// คำนวณระยะจากคลินิก (ถ้าตั้งพิกัดไว้)
$distanceM = null;
$withinRadius = 0;
if ($settings['clinic_lat'] !== null && $settings['clinic_lng'] !== null) {
    $distanceM = scholarship_distance_meters(
        (float)$settings['clinic_lat'], (float)$settings['clinic_lng'],
        $lat, $lng
    );
    $withinRadius = $distanceM <= (float)$settings['radius_m'] ? 1 : 0;
} else {
    // ยังไม่ตั้งพิกัด → ผ่าน (อนุโลม) แต่ flag = 0
    $withinRadius = 0;
}

// หา shift ที่ active ตอนนี้ (สำหรับ clock_in)
$activeShift = find_active_scholarship_shift($pdo, $studentId, 'now');

// State validation
$lastLog = get_latest_scholarship_log($pdo, $studentId);

if ($action === 'clock_in') {
    if ($lastLog && $lastLog['action'] === 'clock_in' && $lastLog['status'] !== 'rejected') {
        echo json_encode(['ok' => false, 'error' => 'คุณยังไม่ได้ออกงานครั้งก่อน']);
        exit;
    }
    if (!$activeShift) {
        echo json_encode(['ok' => false, 'error' => 'ยังไม่ถึงเวลาเข้างาน หรือไม่อยู่ในกะที่ลงทะเบียนไว้']);
        exit;
    }
    if ($settings['clinic_lat'] !== null && !$withinRadius) {
        echo json_encode([
            'ok' => false,
            'error' => sprintf('คุณอยู่นอกพื้นที่คลินิก (ห่าง %.0f ม. รัศมีที่อนุญาต %d ม.)',
                $distanceM, (int)$settings['radius_m'])
        ]);
        exit;
    }
    $shiftId = (int)$activeShift['id'];
} else { // clock_out
    if (!$lastLog || $lastLog['action'] !== 'clock_in' || $lastLog['status'] === 'rejected') {
        echo json_encode(['ok' => false, 'error' => 'ยังไม่ได้เข้างาน หรือคำขอเข้างานก่อนหน้าถูกปฏิเสธ']);
        exit;
    }
    // shift_id ใช้ shift เดียวกับ clock_in (ถ้ามี)
    $shiftId = $lastLog['shift_id'] ? (int)$lastLog['shift_id'] : null;
    // ไม่จำกัดเวลาออกงาน — เลยกะได้ (overtime)
}

// Insert log
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

try {
    $stmt = $pdo->prepare("INSERT INTO sys_scholarship_clock_logs
        (student_id, shift_id, action, event_at, gps_lat, gps_lng, gps_accuracy,
         distance_m, within_radius, ip_address, user_agent, status)
        VALUES (:sid, :shift, :act, NOW(), :lat, :lng, :acc, :dist, :wr, :ip, :ua, :status)");
    $stmt->execute([
        ':sid' => $studentId,
        ':shift' => $shiftId,
        ':act' => $action,
        ':lat' => $lat,
        ':lng' => $lng,
        ':acc' => $accuracy,
        ':dist' => $distanceM,
        ':wr' => $withinRadius,
        ':ip' => $ip,
        ':ua' => $ua,
        ':status' => $settings['require_approval'] ? 'pending' : 'approved',
    ]);

    echo json_encode([
        'ok' => true,
        'message' => $settings['require_approval']
            ? ($action === 'clock_in' ? 'ส่งคำขอเข้างานแล้ว รอเจ้าหน้าที่อนุมัติ' : 'ส่งคำขอออกงานแล้ว รอเจ้าหน้าที่อนุมัติ')
            : ($action === 'clock_in' ? 'เข้างานสำเร็จ' : 'ออกงานสำเร็จ'),
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    error_log('[ajax_scholarship_clock] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'บันทึกไม่สำเร็จ']);
}
