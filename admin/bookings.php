<?php
// admin/bookings.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ==========================================
// การคำนวณเดือนและปีสำหรับปฏิทิน
// ==========================================
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$thaiMonths = [1=>'มกราคม', 2=>'กุมภาพันธ์', 3=>'มีนาคม', 4=>'เมษายน', 5=>'พฤษภาคม', 6=>'มิถุนายน', 7=>'กรกฎาคม', 8=>'สิงหาคม', 9=>'กันยายน', 10=>'ตุลาคม', 11=>'พฤศจิกายน', 12=>'ธันวาคม'];
$monthName = $thaiMonths[$month];
$buddhistYear = $year + 543;

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate)); 

$daysInMonth = (int)date('t', strtotime($startDate));
$startDayOfWeek = (int)date('w', strtotime($startDate)); 

// ==========================================
// ดึงข้อมูลการจองเฉพาะเดือนที่เลือกเพื่อโหลดครั้งแรก
// ==========================================
$allBookings = [];
$bookingsByDay = [];

try {
    $sql = "
        SELECT 
            a.id AS appointment_id, 
            a.status, 
            a.created_at,
            a.campaign_id,
            s.full_name, 
            s.student_personnel_id, 
            s.phone_number,
            t.slot_date, 
            t.start_time, 
            t.end_time,
            c.title AS campaign_title
        FROM camp_bookings a
        JOIN sys_users s ON a.student_id = s.id
        JOIN camp_slots t ON a.slot_id = t.id
        JOIN camp_list c ON a.campaign_id = c.id
        WHERE (
            (t.slot_date >= :start AND t.slot_date <= :end)
            OR a.status = 'booked'
          )
          AND a.status IN ('booked', 'confirmed') 
        ORDER BY 
            CASE WHEN a.status = 'booked' THEN 0 ELSE 1 END,
            t.slot_date ASC, 
            t.start_time ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $allBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allBookings as $b) {
        $day = (int)date('d', strtotime($b['slot_date']));
        $bookingsByDay[$day][] = $b;
    }
// ==========================================
// ดึงรายชื่อแคมเปญสำหรับ Filter Dropdown
// ==========================================
$campaignsList = $pdo->query("
    SELECT DISTINCT c.id, c.title
    FROM camp_list c
    INNER JOIN camp_bookings a ON a.campaign_id = c.id
    INNER JOIN camp_slots t ON t.id = a.slot_id
    WHERE t.slot_date >= '$startDate' AND t.slot_date <= '$endDate'
      AND a.status IN ('booked','confirmed','cancelled','cancelled_by_admin')
    ORDER BY c.title
")->fetchAll();

} catch (PDOException $e) {
    die("Error fetching bookings: " . $e->getMessage());
}

// ==========================================
// ส่วนจัดการ Export Excel ประจำเดือน
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "evax_bookings_{$year}_{$month}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); 
    
    fputcsv($output, ['รหัสนักศึกษา/บุคลากร', 'ชื่อ-นามสกุล', 'เบอร์โทรศัพท์', 'กิจกรรม', 'วันที่จอง', 'เวลา', 'สถานะ']);
    
    foreach ($allBookings as $b) {
        $dateLabel = date('d/m/Y', strtotime($b['slot_date']));
        $timeLabel = substr($b['start_time'], 0, 5) . '-' . substr($b['end_time'], 0, 5);
        $statusText = '';
        switch ($b['status']) {
            case 'booked': $statusText = 'รออนุมัติ'; break;
            case 'confirmed': $statusText = 'อนุมัติแล้ว'; break;
            case 'cancelled': $statusText = 'ยกเลิกแล้ว'; break;
            default: $statusText = $b['status'];
        }
        fputcsv($output, [
            $b['student_personnel_id'], 
            $b['full_name'], 
            $b['phone_number'], 
            $b['campaign_title'],
            $dateLabel, 
            $timeLabel, 
            $statusText
        ]);
    }
    fclose($output);
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Animations & Glass Effects */
@keyframes slideUpFade {
    0% { opacity: 0; transform: translateY(15px); }
    100% { opacity: 1; transform: translateY(0); }
}
.animate-slide-up { animation: slideUpFade 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
.delay-100 { animation-delay: 0.1s; }

.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.glass-modal {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.calendar-grid {
    border-radius: 24px;
    box-shadow: 0 4px 25px rgba(0,0,0,0.03);
    overflow: hidden;
    background: white;
    border: 1px solid #f1f5f9;
}

.cal-day {
    transition: all 0.2s ease;
    border-right: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
}
.cal-day:hover {
    box-shadow: 0 0 15px rgba(0,0,0,0.05);
    z-index: 10;
}
</style>

<!-- HEADER SECTION -->
<div class="mb-8 flex flex-col md:flex-row md:justify-between md:items-end gap-4 animate-slide-up">
    <div>
        <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight flex items-center gap-3">
            จัดการคิวจองแบบปฏิทิน
            <div class="relative flex h-4 w-4" title="กำลังอัปเดตแบบ Real-time">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500 top-0.5 left-0.5"></span>
            </div>
        </h1>
        <p class="text-gray-500 text-sm mt-1 font-medium">คลิกที่วันที่บนปฏิทินเพื่อดูรายชื่อ เลื่อนคิว หรืออนุมัติคิวจองกิจกรรม</p>
    </div>
    
    <div class="flex flex-wrap gap-2 items-center justify-end">
        <!-- Campaign Filter Dropdown -->
        <div class="relative" id="bookingCampFilterContainer">
            <button type="button" onclick="toggleBookingCampFilter()" class="px-4 py-2 border border-gray-200 rounded-xl bg-white font-prompt text-sm shadow-sm hover:bg-gray-50 text-gray-700 flex items-center gap-2 w-56 transition-colors">
                <span id="bookingFilterLabel" class="truncate font-semibold text-[#0052CC]">แสดงทุกแคมเปญ</span>
                <i class="fa-solid fa-chevron-down text-[10px] text-gray-400 ml-auto"></i>
            </button>
            <div id="bookingCampDropdown" class="absolute z-50 mt-2 w-64 bg-white rounded-xl shadow-xl border border-gray-100 hidden flex-col top-full overflow-hidden right-0">
                <div class="p-2 border-b border-gray-100 bg-gray-50">
                    <div class="relative">
                        <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                        <input type="text" onkeyup="searchBookingCamps(this.value)" placeholder="ค้นหาแคมเปญ..." class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg outline-none focus:ring-2 focus:ring-[#0052CC] font-prompt">
                    </div>
                </div>
                <div class="max-h-60 overflow-y-auto p-2 space-y-0.5" id="bookingCampList">
                    <label class="flex items-center gap-3 px-2 py-2 hover:bg-blue-50/50 rounded-lg cursor-pointer group">
                        <input type="checkbox" id="selectAllBookingCamps" checked onchange="toggleAllBookingCamps(this)" class="w-4 h-4 text-[#0052CC] rounded border-gray-300">
                        <span class="text-sm font-bold text-gray-800">เลือกทั้งหมด</span>
                    </label>
                    <div class="h-px bg-gray-100 my-1"></div>
                    <?php foreach ($campaignsList as $cItem): ?>
                    <label class="booking-camp-label flex items-center gap-3 px-2 py-2 hover:bg-gray-50 rounded-lg cursor-pointer group" data-title="<?= htmlspecialchars(strtolower($cItem['title'])) ?>">
                        <input type="checkbox" value="<?= $cItem['id'] ?>" checked onchange="updateBookingFilter()" class="booking-camp-cb w-4 h-4 text-[#0052CC] rounded border-gray-300">
                        <span class="text-sm text-gray-600 line-clamp-1"><?= htmlspecialchars($cItem['title']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- View Toggle -->
        <div class="flex items-center bg-gray-100 p-1 rounded-xl">
            <button onclick="switchBookingView('calendar')" id="btnViewBookingCalendar" class="px-3 py-1.5 text-sm font-bold rounded-lg bg-white shadow-sm text-[#0052CC] transition-all" title="มุมมองปฏิทิน">
                <i class="fa-solid fa-calendar-alt"></i>
            </button>
            <button onclick="switchBookingView('table')" id="btnViewBookingTable" class="px-3 py-1.5 text-sm font-bold rounded-lg text-gray-500 hover:text-gray-700 hover:bg-white transition-all" title="มุมมองตาราง">
                <i class="fa-solid fa-list-ul"></i>
            </button>
        </div>

        <a href="?month=<?= $month ?>&year=<?= $year ?>&export=excel" class="bg-gradient-to-r from-emerald-500 to-green-600 hover:from-emerald-600 hover:to-green-700 text-white px-6 py-3 rounded-2xl font-bold transition-all shadow-lg hover:shadow-green-900/20 hover:-translate-y-1 flex items-center justify-center gap-2 w-full md:w-auto">
            <i class="fa-solid fa-file-excel text-lg"></i> โหลดข้อมูล Excel
        </a>
    </div>
</div>

<div id="calendarViewBookingContainer">
<div class="bg-white p-5 rounded-t-3xl border border-gray-100 border-b-0 flex justify-between items-center animate-slide-up delay-100 shadow-[0_-4px_10px_rgba(0,0,0,0.01)] relative z-20">
    <a href="?month=<?= $month-1 ?>&year=<?= $year ?>" class="px-5 py-2.5 bg-gray-50 border border-gray-200 hover:bg-gray-100 text-gray-700 rounded-xl font-bold transition-all flex items-center gap-2">
        <i class="fa-solid fa-chevron-left text-sm"></i> <span class="hidden sm:inline">เดือนก่อนหน้า</span>
    </a>
    <h2 class="text-2xl font-black text-[#0052CC] tracking-wide"><i class="fa-regular fa-calendar-days mr-2 text-blue-400"></i> <?= $monthName ?> <?= $buddhistYear ?></h2>
    <a href="?month=<?= $month+1 ?>&year=<?= $year ?>" class="px-5 py-2.5 bg-gray-50 border border-gray-200 hover:bg-gray-100 text-gray-700 rounded-xl font-bold transition-all flex items-center gap-2">
        <span class="hidden sm:inline">เดือนถัดไป</span> <i class="fa-solid fa-chevron-right text-sm"></i>
    </a>
</div>

<!-- CALENDAR GRID -->
<div class="calendar-grid rounded-t-none animate-slide-up delay-100 mb-10">
    <!-- Days of Week -->
    <div class="grid grid-cols-7 text-center bg-gray-50 border-b border-gray-100">
        <div class="py-4 text-xs font-black uppercase tracking-wider text-red-500">อาทิตย์</div>
        <div class="py-4 text-xs font-black uppercase tracking-wider text-gray-400">จันทร์</div>
        <div class="py-4 text-xs font-black uppercase tracking-wider text-gray-400">อังคาร</div>
        <div class="py-4 text-xs font-black uppercase tracking-wider text-gray-400">พุธ</div>
        <div class="py-4 text-xs font-black uppercase tracking-wider text-gray-400">พฤหัสบดี</div>
        <div class="py-4 text-xs font-black uppercase tracking-wider text-gray-400">ศุกร์</div>
        <div class="py-4 text-xs font-black uppercase tracking-wider text-blue-500">เสาร์</div>
    </div>
    
    <!-- Dates -->
    <div class="grid grid-cols-7 bg-white">
        <?php 
        $currentDay = 1;
        for ($i = 0; $i < 42; $i++) {
            if ($i < $startDayOfWeek || $currentDay > $daysInMonth) {
                echo '<div class="bg-gray-50/50 min-h-[140px] p-2 border-none"></div>';
            } else {
                $isToday = ($currentDay == (int)date('d') && $month == (int)date('m') && $year == (int)date('Y'));
                $hasBookings = isset($bookingsByDay[$currentDay]) && count($bookingsByDay[$currentDay]) > 0;
                
                $cursorClass = $hasBookings ? 'cursor-pointer hover:bg-blue-50/50' : 'opacity-80';
                $onClick = $hasBookings ? "onclick=\"openDayBookings({$currentDay}, '{$currentDay} {$monthName} {$buddhistYear}')\"" : '';
                
                echo "<div id='cal-box-{$currentDay}' class='cal-day min-h-[140px] p-3 transition-colors relative flex flex-col {$cursorClass}' {$onClick}>";
                
                // Day Number
                echo "<div class='flex justify-between items-start mb-2'>";
                echo "<span class='inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold " . ($isToday ? "bg-gradient-to-br from-[#0052CC] to-blue-600 text-white shadow-md shadow-blue-500/30" : "text-gray-700") . "'>{$currentDay}</span>";
                echo "</div>";

                // Booking Info Box
                echo "<div id='cal-info-{$currentDay}' class='mt-auto space-y-1.5 w-full'>";
                if ($hasBookings) {
                    $pending = 0; $confirmed = 0;
                    foreach ($bookingsByDay[$currentDay] as $b) {
                        if ($b['status'] == 'booked') $pending++;
                        elseif ($b['status'] == 'confirmed') $confirmed++;
                    }
                    
                    echo "<div class='text-[10px] bg-gray-100 border border-gray-200 text-gray-600 px-2 py-1 rounded-md font-extrabold text-center shadow-sm uppercase tracking-wider'>ทั้งหมด " . count($bookingsByDay[$currentDay]) . " คิว</div>";
                    if ($pending > 0) echo "<div class='text-[11px] bg-amber-50 border border-amber-200 text-amber-600 px-2 py-1 rounded-md font-bold text-center flex justify-between tracking-wide shadow-sm'><span>รออนุมัติ</span><span>{$pending}</span></div>";
                    if ($confirmed > 0) echo "<div class='text-[11px] bg-emerald-50 border border-emerald-200 text-emerald-600 px-2 py-1 rounded-md font-bold text-center flex justify-between tracking-wide shadow-sm'><span>อนุมัติแล้ว</span><span>{$confirmed}</span></div>";
                } else {
                    echo "<div class='mt-auto text-center text-xs text-gray-300 font-medium pb-2 flex flex-col items-center gap-1'><i class='fa-regular fa-face-frown text-gray-200 text-xl'></i> ว่าง</div>";
                }
                echo "</div>";
                
                echo "</div>"; 
                $currentDay++;
            }
            if ($currentDay > $daysInMonth && ($i + 1) % 7 == 0) break;
        }
        ?>
    </div>
</div>
</div> <!-- end calendarViewBookingContainer -->

<!-- TABLE VIEW -->
<div id="tableViewBookingContainer" class="hidden bg-white rounded-2xl shadow-sm border border-gray-100 p-6 animate-in fade-in duration-200 mb-10">
    <div class="overflow-x-auto">
        <table id="bookingsTable" class="w-full text-left border-collapse whitespace-nowrap">
            <thead>
                <tr class="bg-gray-50 text-gray-500 text-sm font-bold border-b border-gray-200">
                    <th class="p-3">วันที่</th>
                    <th class="p-3">เวลา</th>
                    <th class="p-3">กิจกรรม</th>
                    <th class="p-3">ชื่อผู้จอง</th>
                    <th class="p-3">รหัส</th>
                    <th class="p-3">เบอร์โทร</th>
                    <th class="p-3 text-center">สถานะ</th>
                    <th class="p-3 text-center">จัดการ</th>
                </tr>
            </thead>
            <tbody class="text-sm">
                <?php foreach ($allBookings as $b):
                    $dateObj = new DateTime($b['slot_date']);
                    $statusBadge = '';
                    $statusSort = 0;
                    switch ($b['status']) {
                        case 'booked': 
                            $statusBadge = '<span class="bg-amber-100 text-amber-700 px-3 py-1.5 rounded-full text-[11px] font-black border border-amber-200 animate-pulse">รอดำเนินการ (ใหม่)</span>'; 
                            $statusSort = 1; 
                            break;
                        case 'confirmed': 
                            $statusBadge = '<span class="bg-emerald-100 text-emerald-700 px-2 py-1 rounded-full text-[10px] font-bold">อนุมัติแล้ว</span>'; 
                            $statusSort = 2; 
                            break;
                        case 'cancelled': 
                            $statusBadge = '<span class="bg-red-100 text-red-700 px-2 py-1 rounded-full text-[10px] font-bold">ยกเลิก (user)</span>'; 
                            $statusSort = 3; 
                            break;
                        case 'cancelled_by_admin': 
                            $statusBadge = '<span class="bg-orange-100 text-orange-700 px-2 py-1 rounded-full text-[10px] font-bold">ระบบยกเลิก</span>'; 
                            $statusSort = 4; 
                            break;
                        default: 
                            $statusBadge = '<span class="bg-gray-100 text-gray-600 px-2 py-1 rounded-full text-[10px] font-bold">' . htmlspecialchars($b['status']) . '</span>';
                    }
                ?>
                <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors <?= $b['status'] === 'booked' ? 'bg-amber-50/30' : '' ?>" data-camp-id="<?= $b['campaign_id'] ?>">
                    <td class="p-3 font-semibold text-gray-800" data-sort="<?= $b['slot_date'] ?>"><?= $dateObj->format('d/m/Y') ?></td>
                    <td class="p-3 font-bold text-[#0052CC]"><?= substr($b['start_time'],0,5) ?> - <?= substr($b['end_time'],0,5) ?></td>
                    <td class="p-3 text-gray-600 font-medium"><?= htmlspecialchars($b['campaign_title']) ?></td>
                    <td class="p-3 font-bold text-gray-800"><?= htmlspecialchars($b['full_name']) ?></td>
                    <td class="p-3 text-gray-400 text-xs"><?= htmlspecialchars($b['student_personnel_id']) ?></td>
                    <td class="p-3 text-gray-600"><?= htmlspecialchars($b['phone_number']) ?></td>
                    <td class="p-3 text-center" data-sort="<?= $statusSort ?>"><?= $statusBadge ?></td>
                    <td class="p-3 text-center">
                        <?php if ($b['status'] === 'booked'): ?>
                            <button onclick="approveBooking(<?= $b['appointment_id'] ?>)" class="text-blue-600 bg-blue-50 hover:bg-blue-600 hover:text-white w-8 h-8 rounded-lg transition-colors mr-1" title="อนุมัติ">
                                <i class="fa-solid fa-check text-xs"></i>
                            </button>
                            <button onclick="forceCancelBooking(<?= $b['appointment_id'] ?>)" class="text-red-500 bg-red-50 hover:bg-red-500 hover:text-white w-8 h-8 rounded-lg transition-colors" title="ปฏิเสธ">
                                <i class="fa-solid fa-times text-xs"></i>
                            </button>
                        <?php elseif ($b['status'] === 'confirmed'): ?>
                            <button onclick="forceCancelBooking(<?= $b['appointment_id'] ?>)" class="text-orange-600 bg-orange-50 hover:bg-orange-500 hover:text-white px-3 h-8 rounded-lg transition-colors text-xs font-bold" title="แจ้งเลื่อน">
                                <i class="fa-solid fa-clock-rotate-left mr-1"></i>เลื่อน
                            </button>
                        <?php else: ?>
                            <span class="text-gray-300 text-xs">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div id="dayBookingsModal" class="fixed inset-0 z-50 bg-gray-900/60 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="glass-modal rounded-[24px] w-full max-w-6xl max-h-[90vh] flex flex-col overflow-hidden animate-slide-up border border-white/50">
        
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-white/50 flex-shrink-0">
            <div>
                <h3 class="text-2xl font-black text-[#0052CC] flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center text-lg shadow-inner"><i class="fa-solid fa-calendar-check"></i></div> 
                    คิวจองประจำวันที่ <span id="modal-date-title" class="text-gray-900 ml-2"></span>
                </h3>
            </div>
            <button onclick="closeDayBookings()" class="w-10 h-10 flex items-center justify-center bg-gray-100 text-gray-500 rounded-full hover:bg-gray-200 hover:text-gray-800 transition-colors shadow-sm focus:outline-none">
                <i class="fa-solid fa-times font-bold text-lg"></i>
            </button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 bg-gray-50/50 custom-scrollbar relative">
            <div class="bg-white rounded-[20px] shadow-sm border border-gray-100 overflow-x-auto relative">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead class="bg-gray-50/90 text-gray-600 font-bold border-b border-gray-100 uppercase tracking-wider text-[11px] sticky top-0 z-20 backdrop-blur-md">
                        <tr>
                            <th class="px-5 py-4"><i class="fa-regular fa-clock mr-1"></i> เวลา</th>
                            <th class="px-5 py-4"><i class="fa-solid fa-bookmark mr-1"></i> กิจกรรม</th>
                            <th class="px-5 py-4"><i class="fa-solid fa-user mr-1"></i> ชื่อผู้จอง (รหัส)</th>
                            <th class="px-5 py-4"><i class="fa-solid fa-phone mr-1"></i> ติดต่อ</th>
                            <th class="px-5 py-4 text-center"><i class="fa-solid fa-toggle-on mr-1"></i> สถานะ</th>
                            <th class="px-5 py-4 text-center sticky right-0 bg-gray-50/90 shadow-[-4px_0_10px_rgba(0,0,0,0.02)] border-l border-gray-100 backdrop-blur-md z-30"><i class="fa-solid fa-gear mr-1"></i> การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody id="dayBookingsTbody" class="divide-y divide-gray-50">
                        <!-- Content rendered by JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/simple-datatables@latest/dist/style.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/simple-datatables@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.dataTable-input,.dataTable-selector{border:1px solid #e5e7eb;border-radius:.5rem;padding:.35rem .5rem;font-size:.875rem;outline:none;font-family:inherit}
.dataTable-input:focus,.dataTable-selector:focus{border-color:#0052CC;box-shadow:0 0 0 2px rgba(0,82,204,.2)}
.dataTable-info,.dataTable-bottom{font-size:.875rem;color:#6B7280;margin-top:.5rem}
</style>
<script>
let bookingsTableInst = null;
let initialBookingsTbodyHTML = '';

document.addEventListener('DOMContentLoaded', function() {
    const tbl = document.getElementById('bookingsTable');
    if (tbl) {
        initialBookingsTbodyHTML = tbl.querySelector('tbody').innerHTML;
        bookingsTableInst = new simpleDatatables.DataTable('#bookingsTable', {
            searchable: true, fixedHeight: false, perPage: 20,
            labels: { placeholder: 'ค้นหา...', perPage: 'รายการต่อหน้า', noRows: 'ไม่พบข้อมูล', info: 'แสดง {start} ถึง {end} จาก {rows} รายการ' }
        });
    }
});

// =========================================
// View Toggle
// =========================================
function switchBookingView(view) {
    const cal = document.getElementById('calendarViewBookingContainer');
    const tbl = document.getElementById('tableViewBookingContainer');
    const btnCal = document.getElementById('btnViewBookingCalendar');
    const btnTbl = document.getElementById('btnViewBookingTable');
    const active = 'px-3 py-1.5 text-sm font-bold rounded-lg bg-white shadow-sm text-[#0052CC] transition-all';
    const inactive = 'px-3 py-1.5 text-sm font-bold rounded-lg text-gray-500 hover:text-gray-700 hover:bg-white transition-all';
    if (view === 'calendar') {
        cal.classList.remove('hidden'); tbl.classList.add('hidden');
        btnCal.className = active; btnTbl.className = inactive;
    } else {
        cal.classList.add('hidden'); tbl.classList.remove('hidden');
        btnCal.className = inactive; btnTbl.className = active;
    }
}

// =========================================
// Campaign Filter Dropdown for Bookings
// =========================================
function toggleBookingCampFilter() {
    document.getElementById('bookingCampDropdown').classList.toggle('hidden');
}
document.addEventListener('click', function(e) {
    const c = document.getElementById('bookingCampFilterContainer');
    if (c && !c.contains(e.target)) document.getElementById('bookingCampDropdown').classList.add('hidden');
});
function searchBookingCamps(val) {
    const term = val.toLowerCase();
    document.querySelectorAll('.booking-camp-label').forEach(el => {
        el.style.display = el.getAttribute('data-title').includes(term) ? '' : 'none';
    });
}
function toggleAllBookingCamps(master) {
    document.querySelectorAll('.booking-camp-cb').forEach(cb => cb.checked = master.checked);
    updateBookingFilter();
}
function updateBookingFilter() {
    const cbs = document.querySelectorAll('.booking-camp-cb');
    const masterCb = document.getElementById('selectAllBookingCamps');
    const checkedIds = Array.from(cbs).filter(cb => cb.checked).map(cb => cb.value);

    masterCb.checked = (checkedIds.length === cbs.length);
    const label = document.getElementById('bookingFilterLabel');
    if (checkedIds.length === cbs.length) label.textContent = 'แสดงทุกแคมเปญ';
    else if (checkedIds.length === 0) label.textContent = 'ไม่ได้เลือกแคมเปญ';
    else label.textContent = `เลือกไว้ (${checkedIds.length}/${cbs.length})`;

    // Filter calendar cells by re-computing shown count from monthBookings
    for (let day = 1; day <= 31; day++) {
        const cellBox = document.getElementById(`cal-box-${day}`);
        const cellInfo = document.getElementById(`cal-info-${day}`);
        if (!cellBox || !cellInfo) continue;
        const dayData = (monthBookings[day] || []).filter(b => checkedIds.includes(String(b.campaign_id)));
        if (dayData.length > 0) {
            let pending = 0, confirmed = 0;
            dayData.forEach(b => { if (b.status==='booked') pending++; else if (b.status==='confirmed') confirmed++; });
            let html = `<div class='text-[10px] bg-gray-100 border border-gray-200 text-gray-600 px-2 py-1 rounded-md font-extrabold text-center shadow-sm uppercase tracking-wider mb-1'>ทั้งหมด ${dayData.length} คิว</div>`;
            if (pending>0) html+=`<div class='text-[11px] bg-amber-50 border border-amber-200 text-amber-600 px-2 py-1 rounded-md font-bold text-center flex justify-between tracking-wide shadow-sm mb-1'><span>รออนุมัติ</span><span>${pending}</span></div>`;
            if (confirmed>0) html+=`<div class='text-[11px] bg-emerald-50 border border-emerald-200 text-emerald-600 px-2 py-1 rounded-md font-bold text-center flex justify-between tracking-wide shadow-sm'><span>อนุมัติแล้ว</span><span>${confirmed}</span></div>`;
            cellInfo.innerHTML = html;
            cellBox.className = 'cal-day min-h-[140px] p-3 transition-colors relative flex flex-col cursor-pointer hover:bg-blue-50/50';
            cellBox.setAttribute('onclick', `openDayBookings(${day},'${day} ${currentMonthName}')`);
        } else {
            cellInfo.innerHTML = "<div class='mt-auto text-center text-xs text-gray-300 font-medium pb-2 flex flex-col items-center gap-1'><i class='fa-regular fa-face-frown text-gray-200 text-xl'></i> ว่าง</div>";
            cellBox.className = 'cal-day min-h-[140px] p-3 transition-colors relative flex flex-col opacity-80 cursor-default';
            cellBox.removeAttribute('onclick');
        }
    }

    // Filter table view
    if (bookingsTableInst) {
        bookingsTableInst.destroy();
        const tbody = document.querySelector('#bookingsTable tbody');
        if (tbody) {
            tbody.innerHTML = initialBookingsTbodyHTML;
            tbody.querySelectorAll('tr').forEach(row => {
                if (!checkedIds.includes(row.getAttribute('data-camp-id'))) row.remove();
            });
            bookingsTableInst = new simpleDatatables.DataTable('#bookingsTable', {
                searchable: true, fixedHeight: false, perPage: 20,
                labels: { placeholder: 'ค้นหา...', perPage: 'รายการต่อหน้า', noRows: 'ไม่พบข้อมูล', info: 'แสดง {start} ถึง {end} จาก {rows} รายการ' }
            });
        }
    }
}

// 🌟 ตัวแปรเก็บข้อมูล และการทำ Real-time
let monthBookings = <?= json_encode($bookingsByDay, JSON_UNESCAPED_UNICODE) ?>;
let currentOpenDay = null;
const currentMonthName = '<?= $monthName ?> <?= $buddhistYear ?>';

function openDayBookings(day, dateTitle) {
    currentOpenDay = day;
    document.getElementById('modal-date-title').innerText = dateTitle;
    renderModalTable(day);
    document.getElementById('dayBookingsModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden'; // ป้องกันสกอร์บาร์ซ้อน
}

function closeDayBookings() {
    currentOpenDay = null;
    document.getElementById('dayBookingsModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

function renderModalTable(day) {
    const tbody = document.getElementById('dayBookingsTbody');
    tbody.innerHTML = '';
    
    const dayData = monthBookings[day] || [];
    
    if (dayData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-16 text-gray-500 font-medium">ไม่มีคิวให้จัดการ</td></tr>';
    } else {
        dayData.forEach(b => {
            const timeStr = b.start_time.substring(0,5) + ' - ' + b.end_time.substring(0,5);
            let statusBadge = '';
            let actionBtn = '-';
            
            if (b.status === 'booked') {
                statusBadge = '<span class="bg-amber-100 border border-amber-200 text-amber-700 px-3 py-1 rounded-full text-[11px] font-bold shadow-sm inline-flex items-center gap-1"><i class="fa-solid fa-circle-notch fa-spin"></i> รออนุมัติ</span>';
                actionBtn = `<div class="flex gap-2 justify-center">
                                <button onclick="approveBooking(${b.appointment_id})" class="bg-[#0052CC] hover:bg-blue-700 text-white px-4 py-2 rounded-xl text-[11px] font-bold transition-all shadow-sm hover:-translate-y-0.5"><i class="fa-solid fa-check"></i> ยืนยันคิว</button>
                                <button onclick="forceCancelBooking(${b.appointment_id})" class="bg-white border border-gray-200 text-red-500 hover:bg-red-50 px-4 py-2 rounded-xl text-[11px] font-bold transition-all shadow-sm"><i class="fa-solid fa-times"></i> ปฏิเสธ</button>
                             </div>`;
            } else if (b.status === 'confirmed') {
                statusBadge = '<span class="bg-emerald-100 border border-emerald-200 text-emerald-700 px-3 py-1 rounded-full text-[11px] font-bold shadow-sm inline-flex items-center gap-1"><i class="fa-solid fa-check-circle"></i> อนุมัติแล้ว</span>';
                actionBtn = `<div class="flex justify-center"><button onclick="forceCancelBooking(${b.appointment_id})" class="bg-orange-50 border border-orange-200 hover:bg-orange-500 hover:text-white text-orange-600 px-4 py-2 rounded-xl text-[11px] font-bold transition-all shadow-sm"><i class="fa-solid fa-clock-rotate-left"></i> แจ้งเลื่อน/ยกเลิก</button></div>`;
            } else if (b.status === 'cancelled') {
                statusBadge = '<span class="bg-red-50 border border-red-200 text-red-600 px-3 py-1 rounded-full text-[11px] font-bold shadow-sm inline-flex items-center gap-1"><i class="fa-solid fa-ban"></i> ยกเลิกโดยนักศึกษา</span>';
            } else if (b.status === 'cancelled_by_admin') {
                statusBadge = '<span class="bg-gray-100 border border-gray-300 text-gray-500 px-3 py-1 rounded-full text-[11px] font-bold shadow-sm inline-flex items-center gap-1"><i class="fa-solid fa-eject"></i> ระบบยกเลิกให้เลื่อนวัน</span>';
            }
            
            const tr = document.createElement('tr');
            tr.className = 'group transition-colors hover:bg-gray-50/80';
            tr.innerHTML = `
                <td class="px-5 py-4 font-black text-[#0052CC] text-base">${timeStr}</td>
                <td class="px-5 py-4 text-gray-700 font-bold">${b.campaign_title}</td>
                <td class="px-5 py-4">
                    <div class="font-extrabold text-gray-900 text-sm">${b.full_name}</div>
                    <div class="text-[11px] text-gray-400 font-bold bg-gray-100 px-2 py-0.5 rounded-md inline-block mt-1">${b.student_personnel_id}</div>
                </td>
                <td class="px-5 py-4 text-gray-600 font-semibold">${b.phone_number}</td>
                <td class="px-5 py-4 text-center">${statusBadge}</td>
                <td class="px-5 py-4 text-center sticky right-0 bg-white group-hover:bg-[#f8fafc] transition-colors border-l border-gray-50 shadow-[-4px_0_10px_rgba(0,0,0,0.02)] z-10">${actionBtn}</td>
            `;
            tbody.appendChild(tr);
        });
    }
}

// APIs Action JS remains exactly the same structure, just class upgrades on Swal.
function approveBooking(appointmentId) {
    Swal.fire({
        title: 'ยืนยันการอนุมัติคิวจอง',
        text: "ส่งแจ้งเตือนให้ผู้ใช้งานทราบว่าคำขอได้รับการยืนยัน?",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0052CC',
        cancelButtonColor: '#e2e8f0',
        confirmButtonText: '<i class="fa-solid fa-check-circle"></i> อนุมัติเลย',
        cancelButtonText: '<span class="text-gray-600">ปิดหน้าต่าง</span>',
        customClass: { title: 'font-prompt font-bold text-xl', popup: 'font-prompt rounded-3xl', confirmButton: 'rounded-xl', cancelButton: 'rounded-xl text-gray-700' }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'กำลังอนุมัติ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            fetch('ajax_approve_booking.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'appointment_id=' + appointmentId + '&csrf_token=<?= get_csrf_token() ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        title: 'ยอดเยี่ยม!',
                        text: 'ระบบได้อนุมัติคิวเรียบร้อยแล้ว',
                        icon: 'success',
                        confirmButtonColor: '#0052CC',
                        customClass: { title: 'font-prompt', popup: 'font-prompt rounded-3xl' },
                        timer: 1500
                    }).then(() => {
                        updateCalendarRealtime();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => Swal.fire('Error', 'ไม่สามารถเชื่อมต่อระบบได้ตรวจสอบอินเตอร์เน็ต', 'error'));
        }
    });
}

function forceCancelBooking(appointmentId) {
    Swal.fire({
        title: 'ปฏิเสธ/แจ้งให้เลื่อนวัน',
        html: "ระบบจะเปลี่ยนสถานะเป็น <b>'ยกเลิกเพื่อให้เลื่อนวัน'</b> <br>คืนโควต้าที่นั่งในระบบ และจะส่งข้อความ <b>LINE Notify</b><br>แจ้งผู้จองทันที",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f97316',
        cancelButtonColor: '#e2e8f0',
        confirmButtonText: '<i class="fa-solid fa-paper-plane"></i> ยืนยันการปฏิเสธให้เลื่อนวัน',
        cancelButtonText: '<span class="text-gray-600">ปิดหน้าต่าง</span>',
        customClass: { title: 'font-prompt font-bold text-xl', popup: 'font-prompt rounded-3xl', confirmButton: 'rounded-xl shadow-lg shadow-orange-500/30', cancelButton: 'rounded-xl text-gray-700' }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'กำลังดำเนินการ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            fetch('ajax_force_cancel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'appointment_id=' + appointmentId + '&csrf_token=<?= get_csrf_token() ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire({
                        title: 'สำเร็จแล้ว!',
                        text: 'ยกเลิกคิวและส่งแจ้งเตือนไปที่ไลน์ให้ผู้ใช้เลื่อนวันเรียบร้อย',
                        icon: 'success',
                        confirmButtonColor: '#0052CC',
                        customClass: { title: 'font-prompt', popup: 'font-prompt rounded-3xl' },
                        timer: 2000
                    }).then(() => {
                        updateCalendarRealtime();
                    });
                } else {
                    Swal.fire('ข้อผิดพลาด', data.message || 'ไม่สามารถทำการยกเลิกได้', 'error');
                }
            })
            .catch(error => Swal.fire('Error', 'ระบบขัดข้อง ปัญหาเครือข่าย', 'error'));
        }
    });
}

function updateCalendarRealtime() {
    fetch(`ajax_get_month_bookings.php?year=<?= $year ?>&month=<?= $month ?>`)
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            monthBookings = data.data; 
            
            for (let day = 1; day <= 31; day++) {
                const cellInfo = document.getElementById(`cal-info-${day}`);
                const cellBox = document.getElementById(`cal-box-${day}`);
                if (!cellInfo || !cellBox) continue;
                
                const dayData = monthBookings[day] || [];
                if (dayData.length > 0) {
                    let pending = 0, confirmed = 0;
                    dayData.forEach(b => {
                        if (b.status === 'booked') pending++;
                        else if (b.status === 'confirmed') confirmed++;
                    });
                    
                    let html = `<div class='text-[10px] bg-gray-100 border border-gray-200 text-gray-600 px-2 py-1 rounded-md font-extrabold text-center shadow-sm uppercase tracking-wider mb-1'>ทั้งหมด ${dayData.length} คิว</div>`;
                    if(pending > 0) html += `<div class='text-[11px] bg-amber-50 border border-amber-200 text-amber-600 px-2 py-1 rounded-md font-bold text-center flex justify-between tracking-wide shadow-sm mb-1'><span>รออนุมัติ</span><span>${pending}</span></div>`;
                    if(confirmed > 0) html += `<div class='text-[11px] bg-emerald-50 border border-emerald-200 text-emerald-600 px-2 py-1 rounded-md font-bold text-center flex justify-between tracking-wide shadow-sm'><span>อนุมัติแล้ว</span><span>${confirmed}</span></div>`;
                    
                    cellInfo.innerHTML = html;
                    cellBox.className = "cal-day min-h-[140px] p-3 transition-colors relative flex flex-col cursor-pointer hover:bg-blue-50/50";
                    cellBox.setAttribute('onclick', `openDayBookings(${day}, '${day} ${currentMonthName}')`);
                } else {
                    cellInfo.innerHTML = "<div class='mt-auto text-center text-xs text-gray-300 font-medium pb-2 flex flex-col items-center gap-1'><i class='fa-regular fa-face-frown text-gray-200 text-xl'></i> ว่าง</div>";
                    cellBox.className = "cal-day min-h-[140px] p-3 transition-colors relative flex flex-col opacity-80 cursor-default";
                    cellBox.removeAttribute('onclick');
                }
            }
            
            if (currentOpenDay !== null) {
                renderModalTable(currentOpenDay);
            }
        }
    })
    .catch(err => console.error('Real-time sync issue:', err));
}

setInterval(updateCalendarRealtime, 3000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
