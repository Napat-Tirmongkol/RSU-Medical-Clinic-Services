<?php
// user/my_bookings.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();
check_user_profile((int)($_SESSION['evax_student_id'] ?? 0));
$studentId = (int)$_SESSION['evax_student_id'];

// ดึงข้อมูลการจอง
$bookings = [];
try {
  $pdo = db();
  $sql = "
    SELECT 
        a.id AS appointment_id, 
        a.status, 
        a.attended_at,
        t.slot_date, 
        t.start_time, 
        t.end_time,
        c.title AS campaign_title,
        c.description AS campaign_desc
    FROM camp_bookings a
    JOIN camp_slots t ON a.slot_id = t.id
    JOIN camp_list c ON a.campaign_id = c.id
    WHERE a.student_id = :student_id
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':student_id' => $studentId]);
  $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log("my_bookings error: " . $e->getMessage()); $upcomingBookings = []; $historyBookings = [];
}

// แยกหมวดหมู่
$upcomingBookings = [];
$historyBookings  = [];
$today = date('Y-m-d');

foreach ($bookings as $b) {
    $isAttended      = !empty($b['attended_at']);
    $isCancelled     = ($b['status'] === 'cancelled');
    $isCancelledByAdmin = ($b['status'] === 'cancelled_by_admin');
    $isPast          = ($b['slot_date'] < $today);

    if ($isAttended || $isCancelled || $isPast || $isCancelledByAdmin) {
        $historyBookings[] = $b;
    } else {
        $upcomingBookings[] = $b;
    }
}

usort($upcomingBookings, fn($a, $b) => strtotime($a['slot_date'].' '.$a['start_time']) <=> strtotime($b['slot_date'].' '.$b['start_time']));
usort($historyBookings,  fn($a, $b) => strtotime($b['slot_date'].' '.$b['start_time']) <=> strtotime($a['slot_date'].' '.$a['start_time']));

render_header('ประวัติการจอง - E-Campaign');

// Helper: Thai month
function thaiDate(string $dateStr): string {
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    [$y, $m, $d] = explode('-', $dateStr);
    return (int)$d . ' ' . $months[(int)$m] . ' ' . ((int)$y + 543);
}

function renderBookingCard($b): void {
    $dateLabel  = thaiDate($b['slot_date']);
    $timeLabel  = substr($b['start_time'], 0, 5) . ' – ' . substr($b['end_time'], 0, 5);
    $dow        = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสฯ','ศุกร์','เสาร์'][date('w', strtotime($b['slot_date']))];
    $isAttended      = !empty($b['attended_at']);
    $isConfirmed        = ($b['status'] === 'confirmed');
    $isPending          = ($b['status'] === 'booked');      // รอ Admin อนุมัติ
    $isCancelled        = ($b['status'] === 'cancelled');
    $isCancelledByAdmin = ($b['status'] === 'cancelled_by_admin');

    $patientName   = htmlspecialchars($_SESSION['evax_full_name'] ?? 'ไม่ระบุชื่อ', ENT_QUOTES);
    $campaignTitle = htmlspecialchars($b['campaign_title'], ENT_QUOTES);
    $safeDate      = htmlspecialchars($dateLabel, ENT_QUOTES);
    $safeTime      = htmlspecialchars($timeLabel, ENT_QUOTES);
    $locationDesc  = trim(preg_replace('/\s+/', ' ', $b['campaign_desc'] ?? 'ไม่ระบุสถานที่'));
    $safeLocation  = htmlspecialchars($locationDesc, ENT_QUOTES);
    $isAttendedJs  = $isAttended ? 'true' : 'false';

    // Status config
    if ($isAttended) {
        $badgeBg    = 'bg-sky-100 text-sky-700 border-sky-200';
        $badgeIcon  = 'fa-check-double';
        $badgeText  = 'เช็คอินแล้ว';
        $accentGrad = 'from-sky-400 to-blue-500';
        $dotColor   = 'bg-sky-500';
    } elseif ($isPending) {
        $badgeBg    = 'bg-amber-100 text-amber-700 border-amber-200';
        $badgeIcon  = 'fa-hourglass-half';
        $badgeText  = 'รออนุมัติ';
        $accentGrad = 'from-amber-400 to-yellow-400';
        $dotColor   = 'bg-amber-400';
    } elseif ($isCancelledByAdmin) {
        $badgeBg    = 'bg-orange-100 text-orange-700 border-orange-200';
        $badgeIcon  = 'fa-rotate-right';
        $badgeText  = 'เลื่อนคิว';
        $accentGrad = 'from-orange-400 to-red-400';
        $dotColor   = 'bg-orange-500';
    } elseif ($isCancelled) {
        $badgeBg    = 'bg-red-100 text-red-600 border-red-200';
        $badgeIcon  = 'fa-xmark';
        $badgeText  = 'ยกเลิกแล้ว';
        $accentGrad = 'from-red-400 to-rose-500';
        $dotColor   = 'bg-red-500';
    } else { // confirmed
        $badgeBg    = 'bg-emerald-100 text-emerald-700 border-emerald-200';
        $badgeIcon  = 'fa-circle-check';
        $badgeText  = 'ยืนยันแล้ว';
        $accentGrad = 'from-emerald-400 to-teal-500';
        $dotColor   = 'bg-emerald-500';
    }
    ?>
    <div class="booking-card group bg-white rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all duration-300 overflow-hidden cursor-pointer active:scale-[0.98]"
         onclick="openModal('<?= $patientName ?>', '<?= $safeDate ?>', '<?= $safeTime ?>', '<?= $b['appointment_id'] ?>', '<?= $b['status'] ?>', '<?= $campaignTitle ?>', <?= $isAttendedJs ?>, '<?= $safeLocation ?>', '<?= $dow ?>')">

        <!-- Gradient Top Bar -->
        <div class="h-1 w-full bg-gradient-to-r <?= $accentGrad ?>"></div>

        <div class="p-4">
            <div class="flex items-start justify-between gap-3">
                <!-- Left: Info -->
                <div class="flex items-start gap-3 flex-1 min-w-0">
                    <!-- Icon Circle -->
                    <div class="shrink-0 w-11 h-11 rounded-xl bg-gradient-to-br <?= $accentGrad ?> flex items-center justify-center shadow-sm">
                        <i class="fa-solid fa-syringe text-white text-base"></i>
                    </div>

                    <div class="flex-1 min-w-0">
                        <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider truncate mb-0.5">
                            <?= htmlspecialchars($b['campaign_title']) ?>
                        </p>
                        <p class="font-bold text-gray-900 text-[15px] leading-tight">
                            <?= $dow ?>, <?= $dateLabel ?>
                        </p>
                        <p class="text-[13px] text-[#0052CC] font-semibold mt-0.5">
                            <i class="fa-regular fa-clock text-xs mr-1"></i><?= $timeLabel ?>
                        </p>
                    </div>
                </div>

                <!-- Right: Badge -->
                <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-bold border <?= $badgeBg ?>">
                    <i class="fa-solid <?= $badgeIcon ?> text-[9px]"></i>
                    <?= $badgeText ?>
                </span>
            </div>

            <?php if ($isConfirmed && !$isAttended && $b['slot_date'] >= date('Y-m-d')): ?>
            <div class="mt-3 pt-3 border-t border-gray-50" onclick="event.stopPropagation()">
                <form action="cancel_booking.php" method="POST" class="cancel-form m-0">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="appointment_id" value="<?= $b['appointment_id'] ?>">
                    <button type="submit"
                            class="w-full py-2 text-[12px] font-bold text-red-500 bg-red-50 hover:bg-red-100 rounded-xl transition-colors flex items-center justify-center gap-1.5 active:scale-[0.97]">
                        <i class="fa-regular fa-circle-xmark"></i> ยกเลิกคิวนี้
                    </button>
                </form>
            </div>
            <?php elseif ($isPending && $b['slot_date'] >= date('Y-m-d')): ?>
            <div class="mt-3 pt-3 border-t border-amber-50" onclick="event.stopPropagation()">
                <div class="flex items-center gap-2 bg-amber-50 border border-amber-100 rounded-xl px-3 py-2">
                    <i class="fa-solid fa-hourglass-half text-amber-500 text-xs animate-pulse"></i>
                    <p class="text-[11px] text-amber-700 font-medium">รอเจ้าหน้าที่ตรวจสอบและอนุมัติ ระบบจะแจ้งผ่าน LINE</p>
                </div>
                <form action="cancel_booking.php" method="POST" class="cancel-form m-0 mt-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="appointment_id" value="<?= $b['appointment_id'] ?>">
                    <button type="submit"
                            class="w-full py-1.5 text-[11px] font-bold text-gray-400 hover:text-red-500 transition-colors flex items-center justify-center gap-1">
                        <i class="fa-regular fa-circle-xmark"></i> ยกเลิกคำขอ
                    </button>
                </form>
            </div>
            <?php elseif ($isCancelledByAdmin): ?>
            <div class="mt-3 pt-3 border-t border-amber-50" onclick="event.stopPropagation()">
                <p class="text-[11px] text-amber-500 mb-2 text-center font-medium">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>ถูกยกเลิกโดยเจ้าหน้าที่ กรุณาจองรอบใหม่
                </p>
                <a href="booking_campaign.php"
                   class="block text-center w-full py-2 text-[12px] font-bold text-white bg-gradient-to-r from-amber-500 to-orange-500 rounded-xl shadow-sm transition-all active:scale-[0.97] hover:shadow-md">
                    <i class="fa-solid fa-calendar-plus mr-1"></i> จองรอบใหม่เลย
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>

<!-- ===== PAGE STRUCTURE ===== -->

<!-- Header Hero -->
<div class="bg-gradient-to-br from-[#0052CC] to-[#0070f3] pt-10 pb-16 px-5 relative overflow-hidden">
    <div class="absolute -right-10 -top-10 w-48 h-48 bg-white opacity-[0.05] rounded-full blur-3xl"></div>
    <div class="absolute -left-8 bottom-0 w-40 h-40 bg-cyan-300 opacity-[0.08] rounded-full blur-2xl"></div>
    <div class="relative z-10 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-[22px] font-bold text-white">การจองของฉัน</h1>
            <p class="text-blue-200 text-sm mt-1">คิวจองกิจกรรมและประวัติการเข้าร่วม</p>
        </div>
        <div class="flex items-center gap-2 mt-1">
            <a href="profile.php?redirect_back=my_bookings.php"
               class="shrink-0 flex items-center gap-1.5 bg-white/15 hover:bg-white/25 border border-white/20 rounded-xl px-3 py-2 text-white text-xs font-bold font-prompt transition-all">
                <i class="fa-solid fa-user-pen text-[11px]"></i>
                แก้ไขโปรไฟล์
            </a>
            <button onclick="openLogout()"
                class="shrink-0 w-9 h-9 flex items-center justify-center bg-white/15 hover:bg-red-500/80 border border-white/20 rounded-xl text-white transition-all"
                title="ออกจากระบบ">
                <i class="fa-solid fa-right-from-bracket text-sm"></i>
            </button>
        </div>
    </div>

    <!-- Floating stats -->
    <div class="relative z-10 mt-5 flex gap-3">
        <div class="flex-1 bg-white/10 backdrop-blur-sm border border-white/20 rounded-2xl p-3 text-center">
            <p class="text-2xl font-bold text-white"><?= count($upcomingBookings) ?></p>
            <p class="text-[11px] text-blue-200 font-medium mt-0.5">คิวที่รอ</p>
        </div>
        <div class="flex-1 bg-white/10 backdrop-blur-sm border border-white/20 rounded-2xl p-3 text-center">
            <p class="text-2xl font-bold text-white"><?= count($historyBookings) ?></p>
            <p class="text-[11px] text-blue-200 font-medium mt-0.5">ประวัติ</p>
        </div>
        <div class="flex-1 bg-white/10 backdrop-blur-sm border border-white/20 rounded-2xl p-3 text-center">
            <?php
            $attended = count(array_filter($historyBookings, fn($b) => !empty($b['attended_at'])));
            ?>
            <p class="text-2xl font-bold text-white"><?= $attended ?></p>
            <p class="text-[11px] text-blue-200 font-medium mt-0.5">เช็คอิน</p>
        </div>
    </div>
</div>

<!-- Main Content (pulls up over the hero) -->
<div class="relative -mt-6 pb-28 px-4">
    <!-- Tab Switcher -->
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-1.5 mb-5 flex">
        <button id="tab-upcoming"
                onclick="switchTab('upcoming')"
                class="flex-1 py-2.5 text-sm font-bold rounded-xl bg-[#0052CC] text-white shadow-md transition-all">
            <i class="fa-solid fa-calendar-clock mr-1.5 text-xs"></i>
            กำลังจะมาถึง
            <span class="ml-1 bg-white/25 text-white px-2 py-0.5 rounded-full text-[10px]"><?= count($upcomingBookings) ?></span>
        </button>
        <button id="tab-history"
                onclick="switchTab('history')"
                class="flex-1 py-2.5 text-sm font-bold rounded-xl text-gray-500 hover:text-gray-700 transition-all">
            <i class="fa-solid fa-clock-rotate-left mr-1.5 text-xs"></i>
            ประวัติ
            <span class="ml-1 bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-[10px]"><?= count($historyBookings) ?></span>
        </button>
    </div>

    <!-- Upcoming -->
    <div id="content-upcoming" class="space-y-3">
        <?php if (count($upcomingBookings) === 0): ?>
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mb-4">
                <i class="fa-regular fa-calendar-check text-3xl text-blue-300"></i>
            </div>
            <p class="font-bold text-gray-700 text-base">ไม่มีคิวที่รออยู่</p>
            <p class="text-sm text-gray-400 mt-1">กดปุ่มด้านล่างเพื่อจองคิวใหม่</p>
        </div>
        <?php else: ?>
            <?php foreach ($upcomingBookings as $b) renderBookingCard($b); ?>
        <?php endif; ?>
    </div>

    <!-- History -->
    <div id="content-history" class="space-y-3 hidden">
        <?php if (count($historyBookings) === 0): ?>
        <div class="flex flex-col items-center justify-center py-16 text-center">
            <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                <i class="fa-solid fa-clock-rotate-left text-3xl text-gray-300"></i>
            </div>
            <p class="font-bold text-gray-700 text-base">ยังไม่มีประวัติ</p>
            <p class="text-sm text-gray-400 mt-1">ประวัติการจองจะแสดงที่นี่</p>
        </div>
        <?php else: ?>
            <?php foreach ($historyBookings as $b) renderBookingCard($b); ?>
        <?php endif; ?>
    </div>
</div>

<!-- Floating Action Button -->
<div class="fixed bottom-0 left-0 right-0 max-w-md mx-auto p-4 z-20">
    <div class="bg-white/80 backdrop-blur-md rounded-2xl border border-gray-100 shadow-xl p-2">
        <a href="booking_campaign.php"
           class="flex w-full items-center justify-center gap-2 bg-gradient-to-r from-[#0052CC] to-[#0070f3] hover:from-[#0047b3] hover:to-[#005fd9] text-white font-bold py-3.5 rounded-xl transition-all shadow-lg shadow-blue-200 active:scale-[0.97]">
            <i class="fa-solid fa-plus"></i>
            <span class="font-prompt">จองกิจกรรมเพิ่ม</span>
        </a>
    </div>
</div>

<!-- ===== BOTTOM SHEET MODAL ===== -->
<div id="details-modal"
     class="fixed inset-0 z-[100] flex items-end justify-center opacity-0 pointer-events-none transition-opacity duration-300"
     onclick="closeModal()">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm"></div>

    <!-- Sheet -->
    <div class="relative bg-white w-full max-w-md rounded-t-3xl shadow-2xl transform translate-y-full transition-transform duration-300 max-h-[92vh] overflow-y-auto"
         onclick="event.stopPropagation()" id="modal-sheet">

        <!-- Drag Handle -->
        <div class="sticky top-0 z-10 bg-white pt-3 pb-2 px-6 flex items-center justify-between border-b border-gray-50">
            <div class="absolute left-1/2 top-2.5 -translate-x-1/2 w-10 h-1 bg-gray-200 rounded-full"></div>
            <h2 class="text-[17px] font-bold text-gray-900 font-prompt mt-2">รายละเอียดการจอง</h2>
            <button onclick="closeModal()"
                    class="mt-2 w-8 h-8 bg-gray-100 hover:bg-gray-200 rounded-full flex items-center justify-center text-gray-500 transition-colors">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="p-6 pt-4">
            <!-- Status Section -->
            <div id="modal-status-container" class="flex flex-col items-center text-center mb-6 py-5 rounded-2xl bg-gray-50">
                <div id="modal-status-icon-wrap" class="w-16 h-16 rounded-full flex items-center justify-center mb-3 bg-emerald-100">
                    <i id="modal-status-icon" class="fa-solid fa-check text-2xl text-emerald-500"></i>
                </div>
                <p id="modal-status-text" class="font-bold text-base text-emerald-600">ยืนยันการจองเรียบร้อย</p>
                <p id="modal-status-sub" class="text-xs text-gray-400 mt-1">กรุณาแสดง QR Code แก่เจ้าหน้าที่</p>
            </div>

            <!-- Details Grid -->
            <div class="bg-gray-50 rounded-2xl p-4 space-y-4 border border-gray-100 mb-5">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">
                        <i class="fa-solid fa-layer-group mr-1"></i>กิจกรรม
                    </p>
                    <p id="modal-campaign" class="font-bold text-[#0052CC] text-base font-prompt"></p>
                </div>
                <div class="h-px bg-gray-200"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">
                            <i class="fa-regular fa-calendar mr-1"></i>วันที่
                        </p>
                        <p id="modal-date" class="font-bold text-gray-900 text-sm font-prompt"></p>
                        <p id="modal-dow" class="text-xs text-gray-400"></p>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">
                            <i class="fa-regular fa-clock mr-1"></i>เวลา
                        </p>
                        <p id="modal-time" class="font-bold text-gray-900 text-sm font-prompt"></p>
                    </div>
                </div>
                <div class="h-px bg-gray-200"></div>
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">
                        <i class="fa-solid fa-location-dot mr-1"></i>สถานที่ / รายละเอียด
                    </p>
                    <p id="modal-location" class="text-sm text-gray-700 font-prompt font-medium leading-relaxed"></p>
                </div>
            </div>

            <!-- QR Code -->
            <div id="modal-qr-section" class="text-center">
                <div class="inline-block bg-white p-4 rounded-2xl border-2 border-gray-100 shadow-[0_4px_24px_rgba(0,82,204,0.08)] mb-3 relative">
                    <img id="modal-qrcode" src="" alt="QR Code" class="w-44 h-44 mx-auto" />
                    <div id="modal-qr-overlay"
                         class="absolute inset-0 bg-white/90 backdrop-blur-sm hidden items-center justify-center rounded-xl flex-col gap-2">
                        <div class="w-14 h-14 bg-sky-100 rounded-full flex items-center justify-center">
                            <i class="fa-solid fa-circle-check text-3xl text-sky-500"></i>
                        </div>
                        <p class="font-bold text-sm text-sky-600 bg-white px-4 py-1.5 rounded-full shadow-sm">ใช้งานแล้ว</p>
                    </div>
                </div>
                <p class="text-[10px] text-gray-400 font-mono">REF: <span id="modal-id" class="font-bold"></span></p>
            </div>
        </div>
    </div>
</div>

<!-- ===== SCRIPTS ===== -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Tab Switcher
function switchTab(tab) {
    const btnUp  = document.getElementById('tab-upcoming');
    const btnHis = document.getElementById('tab-history');
    const contUp  = document.getElementById('content-upcoming');
    const contHis = document.getElementById('content-history');
    const activeClass = 'flex-1 py-2.5 text-sm font-bold rounded-xl bg-[#0052CC] text-white shadow-md transition-all';
    const inactiveClass = 'flex-1 py-2.5 text-sm font-bold rounded-xl text-gray-500 hover:text-gray-700 transition-all';
    if (tab === 'upcoming') {
        btnUp.className  = activeClass;
        btnHis.className = inactiveClass;
        btnUp.innerHTML  = `<i class="fa-solid fa-calendar-clock mr-1.5 text-xs"></i>กำลังจะมาถึง <span class="ml-1 bg-white/25 text-white px-2 py-0.5 rounded-full text-[10px]"><?= count($upcomingBookings) ?></span>`;
        btnHis.innerHTML = `<i class="fa-solid fa-clock-rotate-left mr-1.5 text-xs"></i>ประวัติ <span class="ml-1 bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-[10px]"><?= count($historyBookings) ?></span>`;
        contUp.classList.remove('hidden');
        contHis.classList.add('hidden');
    } else {
        btnHis.className = activeClass;
        btnUp.className  = inactiveClass;
        btnHis.innerHTML = `<i class="fa-solid fa-clock-rotate-left mr-1.5 text-xs"></i>ประวัติ <span class="ml-1 bg-white/25 text-white px-2 py-0.5 rounded-full text-[10px]"><?= count($historyBookings) ?></span>`;
        btnUp.innerHTML  = `<i class="fa-solid fa-calendar-clock mr-1.5 text-xs"></i>กำลังจะมาถึง <span class="ml-1 bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full text-[10px]"><?= count($upcomingBookings) ?></span>`;
        contHis.classList.remove('hidden');
        contUp.classList.add('hidden');
    }
}

<?php if (isset($_GET['error']) && $_GET['error'] === 'already_booked'): ?>
Swal.fire({
    title: 'ไม่สามารถจองซ้ำได้',
    text: 'คุณมีคิวของกิจกรรมนี้อยู่แล้ว หากต้องการเปลี่ยนวัน กรุณายกเลิกคิวเดิมก่อน',
    icon: 'warning', confirmButtonColor: '#0052CC', confirmButtonText: 'ตกลง',
    customClass: { popup: 'font-prompt rounded-2xl', confirmButton: 'font-prompt rounded-xl px-5' }
});
window.history.replaceState(null, null, window.location.pathname);
<?php endif; ?>

// Cancel confirm
document.querySelectorAll('.cancel-form').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        Swal.fire({
            title: 'ต้องการยกเลิกคิว?',
            text: 'หากยกเลิกแล้ว คุณจะต้องทำการจองรอบเวลาใหม่',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#EF4444',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'ใช่, ยกเลิกคิว',
            cancelButtonText: 'ไม่ยกเลิก',
            reverseButtons: true,
            customClass: {
                popup: 'font-prompt rounded-3xl',
                confirmButton: 'font-prompt rounded-xl px-5',
                cancelButton: 'font-prompt rounded-xl px-5'
            }
        }).then(r => { if (r.isConfirmed) form.submit(); });
    });
});

// Modal
const modal      = document.getElementById('details-modal');
const modalSheet = document.getElementById('modal-sheet');

function openModal(name, date, time, appId, status, campaign, isAttended, location, dow) {
    document.getElementById('modal-date').innerText     = date;
    document.getElementById('modal-time').innerText     = time;
    document.getElementById('modal-campaign').innerText = campaign;
    document.getElementById('modal-location').innerText = location;
    document.getElementById('modal-id').innerText       = appId;
    document.getElementById('modal-dow').innerText      = 'วัน' + dow;

    const qrImg     = document.getElementById('modal-qrcode');
    const qrOverlay = document.getElementById('modal-qr-overlay');
    const iconWrap  = document.getElementById('modal-status-icon-wrap');
    const icon      = document.getElementById('modal-status-icon');
    const text      = document.getElementById('modal-status-text');
    const sub       = document.getElementById('modal-status-sub');
    const statusBg  = document.getElementById('modal-status-container');

    qrImg.src = `api_qrcode.php?id=${appId}`;

    if (isAttended) {
        iconWrap.className = 'w-16 h-16 rounded-full flex items-center justify-center mb-3 bg-sky-100';
        icon.className     = 'fa-solid fa-check-double text-2xl text-sky-500';
        text.innerText     = 'เข้าร่วมกิจกรรมแล้ว';
        text.className     = 'font-bold text-base text-sky-600';
        sub.innerText      = 'คิวนี้ถูกเช็คอินเรียบร้อยแล้ว';
        statusBg.className = 'flex flex-col items-center text-center mb-6 py-5 rounded-2xl bg-sky-50';
        qrOverlay.classList.remove('hidden'); qrOverlay.classList.add('flex');
    } else if (status === 'booked') {
        // รอการอนุมัติจาก Admin
        iconWrap.className = 'w-16 h-16 rounded-full flex items-center justify-center mb-3 bg-amber-100';
        icon.className     = 'fa-solid fa-hourglass-half text-2xl text-amber-500';
        text.innerText     = 'รอการอนุมัติจากเจ้าหน้าที่';
        text.className     = 'font-bold text-base text-amber-600';
        sub.innerText      = 'ระบบจะแจ้งผลการอนุมัติผ่าน LINE';
        statusBg.className = 'flex flex-col items-center text-center mb-6 py-5 rounded-2xl bg-amber-50';
        qrOverlay.classList.remove('hidden'); qrOverlay.classList.add('flex');
        // ซ่อน QR section เพราะยังไม่อนุมัติ
        document.getElementById('modal-qr-section').classList.add('hidden');
    } else if (status === 'confirmed') {
        document.getElementById('modal-qr-section').classList.remove('hidden');
        iconWrap.className = 'w-16 h-16 rounded-full flex items-center justify-center mb-3 bg-emerald-100';
        icon.className     = 'fa-solid fa-check text-2xl text-emerald-500';
        text.innerText     = 'ยืนยันการจองเรียบร้อย';
        text.className     = 'font-bold text-base text-emerald-600';
        sub.innerText      = 'กรุณาแสดง QR Code แก่เจ้าหน้าที่';
        statusBg.className = 'flex flex-col items-center text-center mb-6 py-5 rounded-2xl bg-emerald-50';
        qrOverlay.classList.add('hidden'); qrOverlay.classList.remove('flex');
    } else if (status === 'cancelled_by_admin') {
        document.getElementById('modal-qr-section').classList.remove('hidden');
        iconWrap.className = 'w-16 h-16 rounded-full flex items-center justify-center mb-3 bg-orange-100';
        icon.className     = 'fa-solid fa-triangle-exclamation text-2xl text-orange-500';
        text.innerText     = 'เจ้าหน้าที่ยกเลิกเพื่อเลื่อนวันคิว';
        text.className     = 'font-bold text-base text-orange-600';
        sub.innerText      = 'กรุณาทำการจองรอบเวลาใหม่';
        statusBg.className = 'flex flex-col items-center text-center mb-6 py-5 rounded-2xl bg-orange-50';
        qrOverlay.classList.remove('hidden'); qrOverlay.classList.add('flex');
    } else {
        document.getElementById('modal-qr-section').classList.remove('hidden');
        iconWrap.className = 'w-16 h-16 rounded-full flex items-center justify-center mb-3 bg-red-100';
        icon.className     = 'fa-solid fa-xmark text-2xl text-red-500';
        text.innerText     = 'ยกเลิกการจองแล้ว';
        text.className     = 'font-bold text-base text-red-500';
        sub.innerText      = 'คิวนี้ถูกยกเลิกแล้ว ไม่สามารถใช้งานได้';
        statusBg.className = 'flex flex-col items-center text-center mb-6 py-5 rounded-2xl bg-red-50';
        qrOverlay.classList.add('hidden'); qrOverlay.classList.remove('flex');
    }

    modal.classList.remove('opacity-0', 'pointer-events-none');
    requestAnimationFrame(() => modalSheet.classList.remove('translate-y-full'));
}

function closeModal() {
    modalSheet.classList.add('translate-y-full');
    modal.classList.add('opacity-0', 'pointer-events-none');
    setTimeout(() => { document.getElementById('modal-qrcode').src = ''; }, 300);
}
</script>

<!-- Logout confirmation dialog — วางนอก <main> เพื่อให้ fixed positioning ทำงานถูกต้อง -->
<div id="logoutConfirm" class="hidden" style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);display:none;align-items:flex-end;justify-content:center;padding:16px"
    onclick="if(event.target===this){this.style.display='none'}">
    <div class="w-full max-w-md bg-white rounded-3xl p-6 shadow-2xl">
        <div class="flex items-center gap-3 mb-1">
            <div class="w-11 h-11 bg-red-100 rounded-2xl flex items-center justify-center shrink-0">
                <i class="fa-solid fa-right-from-bracket text-red-500 text-lg"></i>
            </div>
            <div>
                <p class="font-black text-gray-900 text-base">ออกจากระบบ?</p>
                <p class="text-xs text-gray-500 mt-0.5">คุณจะต้อง Login ผ่าน LINE ใหม่อีกครั้ง</p>
            </div>
        </div>
        <div class="flex gap-3 mt-5">
            <button onclick="document.getElementById('logoutConfirm').style.display='none'"
                class="flex-1 py-3 border-2 border-gray-200 rounded-2xl font-bold text-gray-600 text-sm">
                ยกเลิก
            </button>
            <a href="logout.php"
                class="flex-1 py-3 bg-red-500 text-white rounded-2xl font-bold text-center text-sm shadow-md shadow-red-200">
                ออกจากระบบ
            </a>
        </div>
    </div>
</div>

<script>
function openLogout() {
    var el = document.getElementById('logoutConfirm');
    el.style.display = 'flex';
}
</script>

<?php render_footer(); ?>
