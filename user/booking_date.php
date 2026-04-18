<?php
// user/booking_date.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();
check_user_profile((int)($_SESSION['evax_student_id'] ?? 0));

$studentId  = (int)$_SESSION['evax_student_id'];
$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;

if ($campaignId <= 0) {
    header('Location: booking_campaign.php', true, 303);
    exit;
}

try {
    $pdo = db();

    $stmtCamp = $pdo->prepare("SELECT id FROM camp_list WHERE id = :cid AND status = 'active' AND (available_until IS NULL OR available_until >= CURDATE())");
    $stmtCamp->execute([':cid' => $campaignId]);
    if (!$stmtCamp->fetch()) {
        header('Location: booking_campaign.php');
        exit;
    }

    $checkSql  = "SELECT COUNT(*) FROM camp_bookings WHERE student_id = :sid AND campaign_id = :cid AND status IN ('confirmed', 'booked')";
    $stmtCheck = $pdo->prepare($checkSql);
    $stmtCheck->execute([':sid' => $studentId, ':cid' => $campaignId]);

    if ((int)$stmtCheck->fetchColumn() > 0) {
        header('Location: my_bookings.php?error=already_booked', true, 303);
        exit;
    }
} catch (PDOException $e) {
    error_log("Check Booking Error: " . $e->getMessage());
}

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selectedDate = isset($_GET['day']) ? $_GET['day'] : null;

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$dailyStats = [];
$dbError    = null;
try {
    $startDate = "$year-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . "-01";
    $endDate   = date('Y-m-d', strtotime("$startDate +1 month"));

    $sqlTotal = "
        SELECT DAY(ts.slot_date) AS day_num, COALESCE(SUM(ts.max_capacity), 0) AS total_capacity
        FROM camp_slots ts
        WHERE ts.slot_date >= :startDate AND ts.slot_date < :endDate AND ts.campaign_id = :cid
        GROUP BY DAY(ts.slot_date)
    ";
    $stmt   = $pdo->prepare($sqlTotal);
    $stmt->execute([':startDate' => $startDate, ':endDate' => $endDate, ':cid' => $campaignId]);
    $totals = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $sqlBooked = "
        SELECT DAY(ts.slot_date) AS day_num, COUNT(*) AS booked_count
        FROM camp_bookings ap
        INNER JOIN camp_slots ts ON ts.id = ap.slot_id
        WHERE ts.slot_date >= :startDate AND ts.slot_date < :endDate
          AND ap.campaign_id = :cid AND ap.status IN ('confirmed', 'booked')
        GROUP BY DAY(ts.slot_date)
    ";
    $stmt2  = $pdo->prepare($sqlBooked);
    $stmt2->execute([':startDate' => $startDate, ':endDate' => $endDate, ':cid' => $campaignId]);
    $bookeds = $stmt2->fetchAll(PDO::FETCH_KEY_PAIR);

    for ($d = 1; $d <= 31; $d++) {
        $dailyStats[$d] = ['total' => $totals[$d] ?? 0, 'booked' => $bookeds[$d] ?? 0];
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

function density_for_day($y, $m, $d, $stats, $dbErr): string {
    if ($dbErr) return 'none';
    $t = $stats[$d]['total'] ?? 0;
    $b = $stats[$d]['booked'] ?? 0;
    if ($t == 0) return 'none';
    if ($b >= $t) return 'high';
    return ($b / $t >= 0.8) ? 'medium' : 'low';
}

render_header(__('date.page_title'));
?>

<div class="p-5 pb-56 -mt-6 relative z-10 flex flex-col h-full bg-[#f4f7fa]">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(__('date.heading')) ?></h2>
        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars(__('date.desc')) ?></p>
    </div>

    <div class="bg-white rounded-3xl p-5 shadow-sm border border-gray-100">
        <div class="flex items-center justify-between mb-6">
            <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>&campaign_id=<?= $campaignId ?>"
               class="w-10 h-10 flex items-center justify-center rounded-xl bg-gray-50 text-gray-600 hover:bg-[#0052CC] hover:text-white transition-colors">
                <i class="fa-solid fa-chevron-left"></i>
            </a>
            <h3 class="text-lg font-bold text-gray-900 font-prompt">
                <?= ($GLOBALS['_tr']['bookings.months_short'] ?? [])[(int)$month] ?? '' ?>
                <?= ($GLOBALS['_tr']['bookings.date_buddhist'] ?? false) ? $year + 543 : $year ?>
            </h3>
            <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>&campaign_id=<?= $campaignId ?>"
               class="w-10 h-10 flex items-center justify-center rounded-xl bg-gray-50 text-gray-600 hover:bg-[#0052CC] hover:text-white transition-colors">
                <i class="fa-solid fa-chevron-right"></i>
            </a>
        </div>

        <div class="grid grid-cols-7 gap-y-4 text-center">
            <?php foreach (($GLOBALS['_tr']['bookings.dow'] ?? []) as $d): ?>
                <div class="text-xs font-bold text-gray-400 uppercase"><?= $d ?></div>
            <?php endforeach; ?>

            <?php
            $firstDayOfMonth = mktime(0,0,0,$month,1,$year);
            $daysInMonth     = (int)date('t', $firstDayOfMonth);
            $dayOfWeek       = (int)date('w', $firstDayOfMonth);

            for ($i = 0; $i < $dayOfWeek; $i++) echo "<div></div>";

            for ($d = 1; $d <= $daysInMonth; $d++):
                $dateStr  = sprintf("%04d-%02d-%02d", $year, $month, $d);
                $isPast   = strtotime($dateStr) < strtotime(date('Y-m-d'));
                $density  = density_for_day($year, $month, $d, $dailyStats, $dbError);
                $btnClass = 'text-gray-700 hover:bg-gray-50';
                $disabled = '';
                $dotColor = '';

                if ($density === 'none' || $isPast) {
                    $btnClass = 'text-gray-300 cursor-not-allowed';
                    $disabled = 'disabled';
                } else {
                    $dotColor = match($density) {
                        'high'   => 'bg-red-500',
                        'medium' => 'bg-yellow-400',
                        default  => 'bg-green-500',
                    };
                }

                if ($selectedDate == $d && !$disabled) {
                    $btnClass = 'bg-[#0052CC] text-white shadow-md shadow-blue-200';
                }
            ?>
                <div class="flex justify-center">
                    <?php if ($disabled): ?>
                        <button type="button" disabled
                            class="relative w-10 h-10 rounded-full flex items-center justify-center text-sm font-semibold <?= $btnClass ?>"><?= $d ?></button>
                    <?php else: ?>
                        <button type="button" data-day="<?= $d ?>" data-density="<?= $density ?>"
                            onclick="selectDay(<?= $d ?>, '<?= $density ?>')"
                            class="relative w-10 h-10 rounded-full flex items-center justify-center text-sm font-semibold transition-all <?= $btnClass ?>">
                            <?= $d ?>
                            <span class="absolute bottom-1 w-1.5 h-1.5 rounded-full <?= $dotColor ?>"></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>

        <div class="flex items-center justify-between text-xs font-semibold text-gray-600 mt-6 pt-4 border-t border-gray-100">
            <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-green-500"></span> <?= htmlspecialchars(__('date.legend_free')) ?></div>
            <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-yellow-400"></span> <?= htmlspecialchars(__('date.legend_almost')) ?></div>
            <div class="flex items-center gap-2"><span class="w-3 h-3 rounded-full bg-red-500"></span> <?= htmlspecialchars(__('date.legend_full')) ?></div>
        </div>
    </div>

    <!-- Action Bar -->
    <div class="mt-8 flex gap-3 w-full">
        <a href="booking_campaign.php"
           class="px-6 py-4 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-bold rounded-xl transition-colors text-center shadow-sm active:scale-[0.98]">
            <?= htmlspecialchars(__('date.back')) ?>
        </a>
        <button id="btn-next-disabled" type="button" disabled
            class="flex-1 bg-gray-200 text-gray-400 font-bold py-4 rounded-xl cursor-not-allowed <?= ($selectedDate !== null && density_for_day($year, $month, (int)$selectedDate, $dailyStats, $dbError) !== 'high' && strtotime(sprintf("%04d-%02d-%02d", $year, $month, $selectedDate)) >= strtotime(date('Y-m-d'))) ? 'hidden' : '' ?>">
            <?= htmlspecialchars(__('date.next')) ?>
        </button>
        <a id="btn-next"
            href="booking_time.php?year=<?= $year ?>&month=<?= $month ?>&day=<?= $selectedDate ?>&campaign_id=<?= $campaignId ?>"
            class="flex-1 bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition-all shadow-sm text-center active:scale-[0.98] <?= ($selectedDate === null || density_for_day($year, $month, (int)$selectedDate, $dailyStats, $dbError) === 'high' || strtotime(sprintf("%04d-%02d-%02d", $year, $month, $selectedDate)) < strtotime(date('Y-m-d'))) ? 'hidden' : '' ?>">
            <?= htmlspecialchars(__('date.next')) ?>
        </a>
    </div>
</div>

<script>
function selectDay(day, density) {
    const url = new URL(window.location.href);
    url.searchParams.set('day', day);
    history.replaceState(null, '', url.toString());

    document.querySelectorAll('[data-day]').forEach(btn => {
        btn.classList.remove('bg-[#0052CC]', 'text-white', 'shadow-md', 'shadow-blue-200');
        btn.classList.add('text-gray-700', 'hover:bg-gray-50');
    });
    const target = document.querySelector('[data-day="' + day + '"]');
    if (target) {
        target.classList.remove('text-gray-700', 'hover:bg-gray-50');
        target.classList.add('bg-[#0052CC]', 'text-white', 'shadow-md', 'shadow-blue-200');
    }

    const btnNext     = document.getElementById('btn-next');
    const btnDisabled = document.getElementById('btn-next-disabled');
    if (density === 'high') {
        btnNext.classList.add('hidden');
        btnDisabled.classList.remove('hidden');
    } else {
        const p = url.searchParams;
        btnNext.href = 'booking_time.php?year=' + p.get('year') + '&month=' + p.get('month') + '&day=' + day + '&campaign_id=' + p.get('campaign_id');
        btnNext.classList.remove('hidden');
        btnDisabled.classList.add('hidden');
    }
}
</script>

<?php render_footer(); ?>
