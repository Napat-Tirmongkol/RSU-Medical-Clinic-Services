<?php
// admin/ajax_get_daily_slots.php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

validate_csrf_or_die();

$pdo    = db();
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';
$date   = trim($_POST['date'] ?? $_GET['date'] ?? '');

// ---- helpers --------------------------------------------------------
function json_err(string $msg): never {
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}
function valid_date(string $d): bool {
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

// ---- GET slots for a date -------------------------------------------
if ($action === 'get') {
    if (!valid_date($date)) json_err('Invalid date');

    $sql = "
        SELECT
            cs.id,
            cs.campaign_id,
            cl.title          AS campaign_title,
            cs.slot_date,
            cs.start_time,
            cs.end_time,
            cs.max_capacity,
            COUNT(CASE WHEN cb.status IN ('booked','confirmed') THEN 1 END) AS booked_count
        FROM camp_slots cs
        JOIN camp_list  cl ON cl.id = cs.campaign_id
        LEFT JOIN camp_bookings cb ON cb.slot_id = cs.id
        WHERE cs.slot_date = :date
        GROUP BY cs.id, cs.campaign_id, cl.title,
                 cs.slot_date, cs.start_time, cs.end_time, cs.max_capacity
        ORDER BY cs.start_time ASC, cl.title ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date' => $date]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'slots' => $slots, 'date' => $date]);
    exit;
}

// ---- EDIT a slot ----------------------------------------------------
if ($action === 'edit') {
    $id       = (int)($_POST['slot_id']      ?? 0);
    $start    = trim($_POST['start_time']    ?? '');
    $end      = trim($_POST['end_time']      ?? '');
    $capacity = (int)($_POST['max_capacity'] ?? 0);

    if ($id <= 0 || !$start || !$end || $capacity < 1) json_err('ข้อมูลไม่ครบถ้วน');

    // ตรวจสอบว่าคนที่จองอยู่ไม่เกิน capacity ใหม่
    $booked = (int) $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE slot_id = ? AND status IN ('booked','confirmed')")->execute([$id]) ? $pdo->query("SELECT COUNT(*) FROM camp_bookings WHERE slot_id = $id AND status IN ('booked','confirmed')")->fetchColumn() : 0;

    $chk = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE slot_id = ? AND status IN ('booked','confirmed')");
    $chk->execute([$id]);
    $booked = (int) $chk->fetchColumn();

    if ($booked > $capacity) {
        json_err("ไม่สามารถลดที่นั่งได้ มีผู้จองอยู่แล้ว {$booked} คน");
    }

    $pdo->prepare("UPDATE camp_slots SET start_time=?, end_time=?, max_capacity=? WHERE id=?")
        ->execute([$start, $end, $capacity, $id]);

    echo json_encode(['status' => 'success', 'message' => 'แก้ไขรอบเวลาสำเร็จ']);
    exit;
}

// ---- DELETE a slot --------------------------------------------------
if ($action === 'delete') {
    $id = (int)($_POST['slot_id'] ?? 0);
    if ($id <= 0) json_err('Invalid slot ID');

    $chk = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE slot_id = ? AND status != 'cancelled'");
    $chk->execute([$id]);
    if ((int) $chk->fetchColumn() > 0) {
        json_err('ไม่สามารถลบได้ มีผู้ลงทะเบียนในรอบนี้แล้ว');
    }

    $pdo->prepare("DELETE FROM camp_slots WHERE id = ?")->execute([$id]);
    echo json_encode(['status' => 'success', 'message' => 'ลบรอบเวลาสำเร็จ']);
    exit;
}

json_err('Unknown action');
