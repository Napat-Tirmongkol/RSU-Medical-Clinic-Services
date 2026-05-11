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
$gpsRequired = !empty($settings['gps_required']);

// ถ้าเปิด GPS check → ต้องส่งพิกัดมา
if ($gpsRequired && ($lat === null || $lng === null)) {
    echo json_encode(['ok' => false, 'error' => 'ไม่พบข้อมูลตำแหน่ง GPS — กรุณาอนุญาตให้แอปเข้าถึงตำแหน่ง']);
    exit;
}

// คำนวณระยะจากคลินิก (ถ้ามีทั้งพิกัดคลินิกและพิกัด user)
$distanceM = null;
$withinRadius = 0;
if ($lat !== null && $lng !== null
    && $settings['clinic_lat'] !== null && $settings['clinic_lng'] !== null) {
    $distanceM = scholarship_distance_meters(
        (float)$settings['clinic_lat'], (float)$settings['clinic_lng'],
        $lat, $lng
    );
    $withinRadius = $distanceM <= (float)$settings['radius_m'] ? 1 : 0;
}

// หา shift ที่ active ตอนนี้ (เพื่อ link ถ้ามี — ถ้าไม่มีก็ ad-hoc)
$activeShift = find_active_scholarship_shift($pdo, $studentId, 'now');

// Insert log inside a transaction with row-level lock เพื่อกัน race condition:
// ถ้า user ส่ง clock_in/clock_out 2 request พร้อมกัน, ไม่มี lock จะผ่าน state validation ทั้งคู่
// ทำให้เกิด log ซ้อน → admin approve เป็นชั่วโมงคูณสอง
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$pdo->beginTransaction();
try {
    // Lock latest log ของ student คนนี้ (กัน parallel clock event)
    $lockStmt = $pdo->prepare("SELECT id, action, status, shift_id, comp_type
        FROM sys_scholarship_clock_logs
        WHERE student_id = :sid
        ORDER BY event_at DESC, id DESC LIMIT 1
        FOR UPDATE");
    $lockStmt->execute([':sid' => $studentId]);
    $lastLog = $lockStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // ประเภทค่าตอบแทน
    $compType = 'hours';

    if ($action === 'clock_in') {
        if ($lastLog && $lastLog['action'] === 'clock_in' && $lastLog['status'] !== 'rejected') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'คุณยังไม่ได้ออกงานครั้งก่อน']);
            exit;
        }
        if ($gpsRequired && $settings['clinic_lat'] !== null && !$withinRadius) {
            $pdo->rollBack();
            echo json_encode([
                'ok' => false,
                'error' => sprintf('คุณอยู่นอกพื้นที่คลินิก (ห่าง %.0f ม. รัศมีที่อนุญาต %d ม.)',
                    $distanceM, (int)$settings['radius_m'])
            ]);
            exit;
        }
        $shiftId = $activeShift ? (int)$activeShift['id'] : null;
        if ($activeShift && !empty($activeShift['comp_type'])) {
            $compType = $activeShift['comp_type'];
        } else {
            $postCt = (string)($_POST['comp_type'] ?? 'hours');
            $compType = in_array($postCt, ['hours', 'paid'], true) ? $postCt : 'hours';
        }
    } else { // clock_out
        if (!$lastLog || $lastLog['action'] !== 'clock_in' || $lastLog['status'] === 'rejected') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'ยังไม่ได้เข้างาน หรือคำขอเข้างานก่อนหน้าถูกปฏิเสธ']);
            exit;
        }
        $shiftId = $lastLog['shift_id'] ? (int)$lastLog['shift_id'] : null;
        // ใช้ comp_type ที่ student เลือกตอนออกงาน (overrides clock_in)
        $postCt = (string)($_POST['comp_type'] ?? '');
        if (in_array($postCt, ['hours', 'paid'], true)) {
            $compType = $postCt;
        } else {
            $compType = $lastLog['comp_type'] ?? 'hours';
        }
    }

    $stmt = $pdo->prepare("INSERT INTO sys_scholarship_clock_logs
        (student_id, shift_id, action, comp_type, event_at, gps_lat, gps_lng, gps_accuracy,
         distance_m, within_radius, ip_address, user_agent, status)
        VALUES (:sid, :shift, :act, :ct, NOW(), :lat, :lng, :acc, :dist, :wr, :ip, :ua, :status)");
    $stmt->execute([
        ':sid' => $studentId,
        ':shift' => $shiftId,
        ':act' => $action,
        ':ct' => $compType,
        ':lat' => $lat,
        ':lng' => $lng,
        ':acc' => $accuracy,
        ':dist' => $distanceM,
        ':wr' => $withinRadius,
        ':ip' => $ip,
        ':ua' => $ua,
        ':status' => $settings['require_approval'] ? 'pending' : 'approved',
    ]);

    // เมื่อ student เลือกประเภทตอน clock_out → ปรับ clock_in log ที่จับคู่ให้ comp_type ตรงกัน
    // (sum_scholarship_hours pair in/out + อ่าน comp_type จาก clock_in)
    if ($action === 'clock_out' && $lastLog && $lastLog['action'] === 'clock_in'
        && ($lastLog['comp_type'] ?? 'hours') !== $compType) {
        $upd = $pdo->prepare("UPDATE sys_scholarship_clock_logs SET comp_type = :ct WHERE id = :id");
        $upd->execute([':ct' => $compType, ':id' => (int)$lastLog['id']]);
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => $settings['require_approval']
            ? ($action === 'clock_in' ? 'ส่งคำขอเข้างานแล้ว รอเจ้าหน้าที่อนุมัติ' : 'ส่งคำขอออกงานแล้ว รอเจ้าหน้าที่อนุมัติ')
            : ($action === 'clock_in' ? 'เข้างานสำเร็จ' : 'ออกงานสำเร็จ'),
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ajax_scholarship_clock] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'บันทึกไม่สำเร็จ']);
}
