<?php
// user/booking_date.php — Premium Date Selection
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
if ($campaignId <= 0) {
    header('Location: booking_campaign.php');
    exit;
}

try {
    $pdo = db();
    // Get user details
    $stmtU = $pdo->prepare("SELECT id, student_personnel_id FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmtU->execute([':line_id' => $lineUserId]);
    $user = $stmtU->fetch();
    
    if (!$user) {
        header('Location: profile.php');
        exit;
    }
    
    $studentId = (int)$user['id'];
    
    // Check existing booking (Fix: Use student_id instead of student_personnel_id)
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE student_id = :sid AND campaign_id = :cid AND status IN ('confirmed', 'booked')");
    $stmtCheck->execute([':sid' => $studentId, ':cid' => $campaignId]);
    if ((int)$stmtCheck->fetchColumn() > 0) {
        header('Location: my_bookings.php?error=already_booked');
        exit;
    }
} catch (PDOException $e) {
    error_log("Check Booking Error: " . $e->getMessage());
}

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selectedDay = isset($_GET['day']) ? (int)$_GET['day'] : null;

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$dailyStats = [];
try {
    $startDate = "$year-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . "-01";
    $endDate   = date('Y-m-d', strtotime("$startDate +1 month"));

    $sqlTotal = "SELECT DAY(slot_date) AS day_num, SUM(max_capacity) AS total FROM camp_slots WHERE slot_date >= :s AND slot_date < :e AND campaign_id = :cid GROUP BY DAY(slot_date)";
    $stmt = $pdo->prepare($sqlTotal);
    $stmt->execute([':s' => $startDate, ':e' => $endDate, ':cid' => $campaignId]);
    $totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $sqlBooked = "SELECT DAY(ts.slot_date) AS day_num, COUNT(*) AS booked FROM camp_bookings ap JOIN camp_slots ts ON ts.id = ap.slot_id WHERE ts.slot_date >= :s AND ts.slot_date < :e AND ap.campaign_id = :cid AND ap.status IN ('confirmed', 'booked') GROUP BY DAY(ts.slot_date)";
    $stmt2 = $pdo->prepare($sqlBooked);
    $stmt2->execute([':s' => $startDate, ':e' => $endDate, ':cid' => $campaignId]);
    $bookeds = $stmt2->fetchAll(PDO::FETCH_KEY_PAIR);

    for ($d = 1; $d <= 31; $d++) {
        $dailyStats[$d] = ['total' => (int)($totals[$d] ?? 0), 'booked' => (int)($bookeds[$d] ?? 0)];
    }
} catch (PDOException $e) { error_log($e->getMessage()); }

function getDensity($d, $stats) {
    $t = $stats[$d]['total'] ?? 0;
    if ($t === 0) return 'none';
    $b = $stats[$d]['booked'] ?? 0;
    if ($b >= $t) return 'full';
    return ($b / $t >= 0.8) ? 'medium' : 'low';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>เลือกวันที่ - RSU Medical</title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; background-color: #F8FAFF; }
        .glass-header { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .active-day { background: #0052CC !important; color: white !important; box-shadow: 0 10px 20px rgba(0, 82, 204, 0.2); }
    </style>
</head>
<body class="pb-32">
    <div class="max-w-md mx-auto min-h-screen relative">
        
        <!-- ── Clean White Header ── -->
        <header class="glass-header sticky top-0 z-[60] px-6 py-5 flex items-center justify-between border-b border-slate-100">
            <button onclick="window.location.href='booking_campaign.php'" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition-all">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <h1 class="text-lg font-black text-slate-900 tracking-tight">เลือกวันที่รับบริการ</h1>
            <div class="w-11"></div>
        </header>

        <main class="px-6 pt-8">
            <div class="mb-8">
                <h2 class="text-2xl font-black text-slate-900 leading-tight mb-2">เลือกวันที่คุณสะดวก</h2>
                <p class="text-slate-400 text-sm font-bold">ตารางนัดหมายประจำเดือนนี้</p>
            </div>

            <!-- ── Calendar Card ── -->
            <div class="bg-white rounded-[2.5rem] p-6 border border-slate-100 shadow-sm mb-8">
                <div class="flex items-center justify-between mb-8">
                    <button onclick="changeMonth(<?= $prevYear ?>, <?= $prevMonth ?>)" class="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-50 text-slate-400 hover:bg-blue-600 hover:text-white transition-all">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <h3 class="text-lg font-black text-slate-900">
                        <?= ($GLOBALS['_tr']['bookings.months_short'] ?? [])[(int)$month] ?? date('F', mktime(0,0,0,$month,1)) ?>
                        <?= $year + 543 ?>
                    </h3>
                    <button onclick="changeMonth(<?= $nextYear ?>, <?= $nextMonth ?>)" class="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-50 text-slate-400 hover:bg-blue-600 hover:text-white transition-all">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>

                <div class="grid grid-cols-7 gap-y-4 text-center mb-6">
                    <?php foreach (['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'] as $d): ?>
                        <div class="text-[10px] font-black text-slate-300 uppercase tracking-widest"><?= $d ?></div>
                    <?php endforeach; ?>

                    <?php
                    $firstDay = mktime(0,0,0,$month,1,$year);
                    $daysInMonth = (int)date('t', $firstDay);
                    $startDow = (int)date('w', $firstDay);
                    for ($i = 0; $i < $startDow; $i++) echo "<div></div>";

                    for ($d = 1; $d <= $daysInMonth; $d++):
                        $dateStr = sprintf("%04d-%02d-%02d", $year, $month, $d);
                        $isPast = strtotime($dateStr) < strtotime(date('Y-m-d'));
                        $density = getDensity($d, $dailyStats);
                        
                        $isDisabled = ($density === 'none' || $isPast || $density === 'full');
                        $dotColor = match($density) {
                            'full'   => 'bg-red-400',
                            'medium' => 'bg-amber-400',
                            'low'    => 'bg-emerald-400',
                            default  => 'bg-transparent'
                        };
                    ?>
                        <div class="flex justify-center">
                            <button type="button" 
                                onclick="selectDay(<?= $d ?>, '<?= $density ?>')"
                                <?= $isDisabled ? 'disabled' : '' ?>
                                class="day-btn relative w-10 h-10 rounded-full flex items-center justify-center text-sm font-black transition-all <?= $isDisabled ? 'text-slate-200' : 'text-slate-700 hover:bg-slate-50' ?> <?= $selectedDay === $d ? 'active-day' : '' ?>">
                                <?= $d ?>
                                <?php if (!$isPast && $density !== 'none'): ?>
                                    <span class="absolute bottom-1 w-1 h-1 rounded-full <?= $dotColor ?>"></span>
                                <?php endif; ?>
                            </button>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="flex items-center justify-between pt-6 border-t border-slate-50">
                    <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-emerald-400"></span> <span class="text-[10px] font-black text-slate-400 uppercase tracking-tighter">ว่าง</span></div>
                    <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-400"></span> <span class="text-[10px] font-black text-slate-400 uppercase tracking-tighter">ใกล้เต็ม</span></div>
                    <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-red-400"></span> <span class="text-[10px] font-black text-slate-400 uppercase tracking-tighter">เต็มแล้ว</span></div>
                </div>
            </div>

            <!-- ── Action Bar ── -->
            <div class="flex gap-4">
                <button onclick="window.location.href='booking_campaign.php'" class="flex-1 h-14 bg-white border border-slate-100 text-slate-400 font-black rounded-2xl active:scale-95 transition-all">ย้อนกลับ</button>
                <button id="btn-next" onclick="goToTime()" disabled class="flex-[2] h-14 bg-slate-100 text-slate-300 font-black rounded-2xl active:scale-95 transition-all shadow-xl shadow-slate-100">ถัดไป</button>
            </div>
        </main>

        <!-- ── Premium Bottom Navigation ── -->
        <nav class="fixed bottom-0 left-0 right-0 z-[70] bg-white/90 backdrop-blur-2xl border-t border-slate-50 px-8 py-4 pb-10 flex justify-between items-center max-w-md mx-auto shadow-[0_-20px_40px_rgba(0,0,0,0.04)]">
            <button onclick="window.location.href='hub.php'" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-house-chimney text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Home</span>
            </button>
            <button onclick="window.location.href='my_bookings.php'" class="flex flex-col items-center gap-1.5 text-blue-600 transition-all scale-110">
                <i class="fa-solid fa-calendar-day text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Booking</span>
            </button>
            <div class="relative -mt-14">
                <button onclick="window.location.href='hub.php?action=campaigns'" class="w-16 h-16 bg-blue-600 rounded-[1.8rem] rotate-45 flex items-center justify-center text-white shadow-[0_15px_30px_rgba(0,82,204,0.4)] border-[6px] border-[#F8FAFF] active:scale-90 transition-all group">
                    <i class="fa-solid fa-plus text-2xl -rotate-45 group-hover:scale-125 transition-transform"></i>
                </button>
            </div>
            <button onclick="window.location.href='hub.php'" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-heart-pulse text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Health</span>
            </button>
            <button onclick="window.location.href='profile.php'" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-user-ninja text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Account</span>
            </button>
        </nav>
    </div>

    <script>
        let currentDay = <?= $selectedDay ?? 'null' ?>;
        
        function selectDay(day, density) {
            currentDay = day;
            document.querySelectorAll('.day-btn').forEach(btn => btn.classList.remove('active-day'));
            event.currentTarget.classList.add('active-day');
            
            const btnNext = document.getElementById('btn-next');
            btnNext.disabled = false;
            btnNext.classList.remove('bg-slate-100', 'text-slate-300', 'shadow-slate-100');
            btnNext.classList.add('bg-blue-600', 'text-white', 'shadow-blue-100');
        }

        function changeMonth(y, m) {
            window.location.href = `booking_date.php?year=${y}&month=${m}&campaign_id=<?= $campaignId ?>`;
        }

        function goToTime() {
            if (!currentDay) return;
            window.location.href = `booking_time.php?year=<?= $year ?>&month=<?= $month ?>&day=${currentDay}&campaign_id=<?= $campaignId ?>`;
        }
    </script>
</body>
</html>
