<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';
session_start();

$studentId = $_SESSION['evax_student_id'] ?? 0;
$appId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($studentId <= 0 || $appId <= 0) {
    header('Location: my_bookings.php');
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT a.*, t.slot_date, t.start_time, t.end_time 
        FROM vac_appointments a 
        JOIN vac_time_slots t ON a.slot_id = t.id 
        WHERE a.id = :id AND a.student_id = :student_id
    ");
    $stmt->execute([':id' => $appId, ':student_id' => $studentId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        die("ไม่พบข้อมูลการจอง");
    }
} catch (PDOException $e) {
    error_log("booking_details error: " . $e->getMessage()); header("Location: my_bookings.php"); exit;
}

render_header('Booking Details');
?>

<div class="p-5 flex flex-col min-h-screen bg-[#f4f7fa]">
    <div class="mb-6">
        <a href="my_bookings.php" class="text-[#0052CC] font-bold flex items-center gap-2">
            ← กลับไปหน้าประวัติ
        </a>
    </div>

    <div class="bg-white rounded-3xl p-8 shadow-sm text-center border border-gray-100">
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
            <span class="text-4xl text-green-500">✓</span>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 mb-2">รายละเอียดการจอง</h2>
        <p class="text-gray-500 mb-8">สถานะ: <?= $booking['status'] === 'cancelled' ? 'ยกเลิกแล้ว' : 'ยืนยันการจองเรียบร้อย' ?></p>

        <div class="space-y-4 text-left bg-gray-50 p-6 rounded-2xl">
            <div>
                <p class="text-xs text-gray-400 uppercase tracking-wider font-bold">วันที่นัดหมาย</p>
                <p class="text-lg font-bold text-gray-900"><?= date('j F Y', strtotime($booking['slot_date'])) ?></p>
            </div>
            <div>
                <p class="text-xs text-gray-400 uppercase tracking-wider font-bold">เวลา</p>
                <p class="text-lg font-bold text-[#0052CC]"><?= substr($booking['start_time'], 0, 5) ?> - <?= substr($booking['end_time'], 0, 5) ?></p>
            </div>
        </div>
    </div>
</div>

<?php render_footer(); ?>