<?php
// database/seed_scan_test.php
// สร้าง Dummy Data สำหรับทดสอบการสแกนเช็คอินแคมเปญ
// รันผ่าน browser หรือ CLI: php database/seed_scan_test.php
require_once __DIR__ . '/../config/db_connect.php';

$pdo = db();
$today = date('Y-m-d'); // วันนี้ตามเวลาเซิร์ฟเวอร์

header('Content-Type: text/plain; charset=utf-8');

echo "=== Seed: ข้อมูลทดสอบสแกนเช็คอิน ===\n";
echo "วันที่ใช้ทดสอบ: $today\n\n";

// ── 1. สร้าง Campaign ────────────────────────────────────────────────────────
$pdo->exec("
    INSERT IGNORE INTO camp_list
        (id, title, type, description, total_capacity, available_until, status, is_auto_approve, created_at, updated_at)
    VALUES
        (9901, '[TEST] คลินิกสุขภาพประจำปี 2026', 'health', 'ข้อมูล Dummy สำหรับทดสอบระบบสแกนเช็คอิน', 100, '$today 23:59:59', 'active', 1, NOW(), NOW())
");
$campaignId = 9901;
echo "✓ Campaign ID $campaignId\n";

// ── 2. สร้าง Slot วันนี้ ─────────────────────────────────────────────────────
$pdo->exec("
    INSERT IGNORE INTO camp_slots
        (id, campaign_id, slot_date, start_time, end_time, max_capacity, created_at, updated_at)
    VALUES
        (9901, $campaignId, '$today', '09:00:00', '12:00:00', 50, NOW(), NOW()),
        (9902, $campaignId, '$today', '13:00:00', '16:00:00', 50, NOW(), NOW())
");
echo "✓ Slot 9901 (09:00–12:00) และ 9902 (13:00–16:00) วันที่ $today\n";

// ── 3. สร้าง Dummy Users ─────────────────────────────────────────────────────
$dummyUsers = [
    [9901, '6801001', 'นาย ทดสอบ เอก',    '0810000001', 'test01@test.rsu.ac.th'],
    [9902, '6801002', 'นาย ทดสอบ โท',     '0810000002', 'test02@test.rsu.ac.th'],
    [9903, '6801003', 'นางสาว ทดสอบ ตรี', '0810000003', 'test03@test.rsu.ac.th'],
    [9904, '6801004', 'นาย ทดสอบ จัตวา',  '0810000004', 'test04@test.rsu.ac.th'],
    [9905, '6801005', 'นางสาว ทดสอบ เบญจ','0810000005', 'test05@test.rsu.ac.th'],
];

foreach ($dummyUsers as [$uid, $spid, $name, $phone, $email]) {
    $pdo->prepare("
        INSERT IGNORE INTO sys_users
            (id, student_personnel_id, full_name, phone_number, email, status, created_at)
        VALUES
            (?, ?, ?, ?, ?, 'active', NOW())
    ")->execute([$uid, $spid, $name, $phone, $email]);
}
echo "✓ Users ID 9901–9905\n";

// ── 4. สร้าง Bookings (พร้อมสแกน) ───────────────────────────────────────────
// status='confirmed', attended_at=NULL → สแกนได้ทันที
$bookings = [
    [9901, 9901, $campaignId, 9901, 'confirmed'], // slot เช้า
    [9902, 9902, $campaignId, 9901, 'confirmed'],
    [9903, 9903, $campaignId, 9902, 'confirmed'], // slot บ่าย
    [9904, 9904, $campaignId, 9902, 'confirmed'],
    [9905, 9905, $campaignId, 9902, 'booked'],    // ยังไม่ approve (ทดสอบ warning)
];

foreach ($bookings as [$bid, $uid, $cid, $slotId, $status]) {
    $pdo->prepare("
        INSERT IGNORE INTO camp_bookings
            (id, student_id, campaign_id, slot_id, status, attended_at, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, NULL, NOW(), NOW())
    ")->execute([$bid, $uid, $cid, $slotId, $status]);
}
echo "✓ Bookings ID 9901–9905\n\n";

// ── สรุป ─────────────────────────────────────────────────────────────────────
echo "=== พร้อมทดสอบ ===\n";
echo "ไปที่: staff/scan.php\n\n";

echo "Booking ID ที่สแกนได้ (status=confirmed):\n";
echo "  - BOOKING-ID:9901  → นาย ทดสอบ เอก     (slot เช้า 09:00–12:00)\n";
echo "  - BOOKING-ID:9902  → นาย ทดสอบ โท      (slot เช้า 09:00–12:00)\n";
echo "  - BOOKING-ID:9903  → นางสาว ทดสอบ ตรี  (slot บ่าย 13:00–16:00)\n";
echo "  - BOOKING-ID:9904  → นาย ทดสอบ จัตวา   (slot บ่าย 13:00–16:00)\n\n";

echo "Booking ID ที่สแกนแล้วได้ WARNING:\n";
echo "  - BOOKING-ID:9905  → นางสาว ทดสอบ เบญจ (status=booked ยังไม่ approve)\n\n";

echo "ล้างข้อมูลทดสอบ: รัน database/seed_scan_test.php?cleanup=1\n";

// ── Cleanup mode ─────────────────────────────────────────────────────────────
if (isset($_GET['cleanup']) || (PHP_SAPI === 'cli' && in_array('--cleanup', $argv ?? []))) {
    $pdo->exec("DELETE FROM camp_bookings WHERE id BETWEEN 9901 AND 9905");
    $pdo->exec("DELETE FROM sys_users     WHERE id BETWEEN 9901 AND 9905");
    $pdo->exec("DELETE FROM camp_slots    WHERE id BETWEEN 9901 AND 9902");
    $pdo->exec("DELETE FROM camp_list     WHERE id = 9901");
    echo "\n✓ ลบข้อมูลทดสอบทั้งหมดแล้ว\n";
}
