<?php
// user/booking_time.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();
check_user_profile((int)($_SESSION['evax_student_id'] ?? 0));
$studentId = (int)$_SESSION['evax_student_id'];

$year       = (int)($_GET['year']        ?? 0);
$month      = (int)($_GET['month']       ?? 0);
$day        = (int)($_GET['day']         ?? 0);
$campaignId = (int)($_GET['campaign_id'] ?? 0);

if ($year == 0 || $month == 0 || $day == 0 || $campaignId == 0) {
    header('Location: booking_campaign.php');
    exit;
}

$selectedDateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
$months      = $GLOBALS['_tr']['bookings.months_short'] ?? [];
$isBuddhist  = $GLOBALS['_tr']['bookings.date_buddhist'] ?? false;
$displayYear = $isBuddhist ? $year + 543 : $year;
$displayDate = (int)$day . ' ' . $months[(int)$month] . ' ' . $displayYear;

$pdo = db();

$campaign = null;
try {
    $stmtCamp = $pdo->prepare("SELECT id, title FROM camp_list WHERE id = :id AND status = 'active' AND (available_until IS NULL OR available_until >= CURDATE())");
    $stmtCamp->execute([':id' => $campaignId]);
    $campaign = $stmtCamp->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) {
        header('Location: booking_campaign.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("booking_time campaign fetch error: " . $e->getMessage());
    header('Location: booking_campaign.php');
    exit;
}

$timeSlots = [];
try {
    $sqlSlots = "
        SELECT
            t.id, t.start_time, t.end_time, t.max_capacity,
            (SELECT COUNT(*) FROM camp_bookings a WHERE a.slot_id = t.id AND a.status IN ('booked', 'confirmed')) as booked_count
        FROM camp_slots t
        WHERE t.slot_date = :date AND t.campaign_id = :cid
        ORDER BY t.start_time ASC
    ";
    $stmt = $pdo->prepare($sqlSlots);
    $stmt->execute([':date' => $selectedDateStr, ':cid' => $campaignId]);
    $timeSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("booking_time slots fetch error: " . $e->getMessage());
    $timeSlots = [];
}

render_header(__('time.page_title'));

// Pass translated strings to JavaScript
$jsT = [
    'swal_title'     => __('time.swal_title'),
    'swal_confirm'   => __('time.swal_confirm'),
    'swal_cancel'    => __('time.swal_cancel'),
    'swal_full_title'=> __('time.swal_full_title'),
    'swal_full_text' => __('time.swal_full_text'),
    'swal_ok'        => __('time.swal_ok'),
    'swal_html'      => __('time.swal_html'),
    'full_badge'     => __('time.full_badge'),
    'available'      => __('time.available'),  // sprintf pattern: "ว่าง %d ที่" / "%d seats left"
];
?>

<div class="p-5 pb-56 -mt-6 relative z-10 flex flex-col h-full animate-in fade-in slide-in-from-right-4 duration-500">
    <div class="flex-1">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-clock text-[#0052CC]"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-gray-900 leading-tight"><?= htmlspecialchars(__('time.heading')) ?></h2>
                <p class="text-sm text-[#0052CC] font-semibold mt-0.5"><?= $displayDate ?></p>
            </div>
        </div>

        <form action="submit_booking.php" method="POST" id="bookingForm">
            <input type="hidden" name="booking_date" value="<?= $selectedDateStr ?>">
            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
            <?php csrf_field(); ?>

            <div class="mb-6 bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">
                    <i class="fa-solid fa-thumbtack mr-1"></i> <?= htmlspecialchars(__('time.selected_activity')) ?>
                </label>
                <div class="font-bold text-gray-900 text-lg">
                    <?= htmlspecialchars($campaign['title']) ?>
                </div>
            </div>

            <label class="block text-sm font-semibold text-gray-700 mb-3 ml-1">
                <?= htmlspecialchars(__('time.slots_label')) ?>
            </label>

            <div class="space-y-3">
                <?php if (count($timeSlots) === 0): ?>
                    <div class="bg-gray-50 p-8 rounded-3xl text-center border-2 border-dashed border-gray-200">
                        <div class="text-3xl text-gray-300 mb-2"><i class="fa-regular fa-clock"></i></div>
                        <p class="text-gray-500 font-medium"><?= htmlspecialchars(__('time.no_slots')) ?></p>
                    </div>
                <?php else: ?>
                    <?php foreach ($timeSlots as $slot):
                        $timeStr   = substr($slot['start_time'], 0, 5) . ' - ' . substr($slot['end_time'], 0, 5);
                        $remaining = $slot['max_capacity'] - $slot['booked_count'];
                        $isFull    = $remaining <= 0;
                        $badgeText = $isFull ? __('time.full_badge') : sprintf(__('time.available'), $remaining);
                    ?>
                        <label id="slot-label-<?= $slot['id'] ?>"
                               class="relative block bg-white border <?= $isFull ? 'border-red-200 opacity-60' : 'border-gray-200 cursor-pointer hover:border-[#0052CC] hover:bg-blue-50/50 hover:shadow-sm' ?> rounded-2xl p-4 transition-all duration-300">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <input type="radio" id="slot-radio-<?= $slot['id'] ?>" name="slot_id"
                                           value="<?= $slot['id'] ?>" <?= $isFull ? 'disabled' : 'required' ?>
                                           class="w-5 h-5 text-[#0052CC] focus:ring-[#0052CC] border-gray-300 cursor-pointer disabled:cursor-not-allowed"
                                           data-time="<?= $timeStr ?>">
                                    <span class="font-bold text-gray-900 text-lg font-prompt"><?= $timeStr ?></span>
                                </div>
                                <span id="slot-status-<?= $slot['id'] ?>"
                                      class="text-xs font-bold px-3 py-1.5 rounded-lg border transition-colors <?= $isFull ? 'text-red-500 bg-red-50 border-red-100' : 'text-green-600 bg-green-50 border-green-100' ?>">
                                    <?= htmlspecialchars($badgeText) ?>
                                </span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Action Bar -->
            <div class="mt-8 pt-4 border-t border-gray-100 flex gap-3 w-full">
                <a href="booking_date.php?year=<?= $year ?>&month=<?= $month ?>&campaign_id=<?= $campaignId ?>"
                   class="px-6 py-4 bg-white border border-gray-200 hover:bg-gray-50 text-gray-700 font-bold rounded-xl transition-colors text-center shadow-sm active:scale-[0.98]">
                    <?= htmlspecialchars(__('time.back')) ?>
                </a>
                <button type="submit"
                        class="flex-1 bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition-all shadow-sm active:scale-[0.98] font-prompt">
                    <?= htmlspecialchars(__('time.confirm_btn')) ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const T            = <?= json_encode($jsT, JSON_UNESCAPED_UNICODE) ?>;
const campaignTitle = <?= json_encode($campaign['title'], JSON_UNESCAPED_UNICODE) ?>;

document.addEventListener('DOMContentLoaded', function () {
    const dateStr = '<?= $selectedDateStr ?>';
    const campId  = <?= $campaignId ?>;

    // Confirm dialog before submit
    document.getElementById('bookingForm').addEventListener('submit', function (e) {
        e.preventDefault();
        const radio = document.querySelector('input[name="slot_id"]:checked');
        const timeText = radio ? radio.getAttribute('data-time') : '';


        const confirmText = T.swal_html.replace('%s', campaignTitle).replace('%s', timeText);

        Swal.fire({
            title: T.swal_title,
            html: confirmText,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0052CC',
            cancelButtonColor: '#6B7280',
            confirmButtonText: T.swal_confirm,
            cancelButtonText: T.swal_cancel,
            reverseButtons: true,
            customClass: {
                title: 'font-prompt text-xl',
                htmlContainer: 'font-prompt text-gray-600',
                popup: 'font-prompt rounded-3xl',
                confirmButton: 'font-prompt rounded-xl px-5 py-2.5 shadow-md',
                cancelButton: 'font-prompt rounded-xl px-5 py-2.5'
            }
        }).then(r => { if (r.isConfirmed) document.getElementById('bookingForm').submit(); });
    });

    // Real-time slot availability
    function updateSlots() {
        fetch(`api_get_slots.php?date=${dateStr}&campaign_id=${campId}`)
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') return;
                for (const [slotId, remaining] of Object.entries(data.data)) {
                    const label  = document.getElementById(`slot-label-${slotId}`);
                    const radio  = document.getElementById(`slot-radio-${slotId}`);
                    const badge  = document.getElementById(`slot-status-${slotId}`);
                    if (!label || !radio || !badge) continue;

                    if (remaining <= 0) {
                        if (radio.checked) {
                            radio.checked = false;
                            Swal.fire({
                                title: T.swal_full_title,
                                text:  T.swal_full_text,
                                icon: 'warning',
                                confirmButtonColor: '#0052CC',
                                confirmButtonText: T.swal_ok,
                                customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl', confirmButton: 'font-prompt rounded-xl' }
                            });
                        }
                        radio.disabled = true;
                        label.className = 'relative block bg-white border border-red-200 opacity-60 rounded-2xl p-4 transition-all duration-300';
                        badge.className = 'text-xs font-bold text-red-500 bg-red-50 px-3 py-1.5 rounded-lg border border-red-100 transition-colors';
                        badge.innerText = T.full_badge;
                    } else {
                        radio.disabled  = false;
                        label.className = 'relative block bg-white border border-gray-200 cursor-pointer hover:border-[#0052CC] hover:bg-blue-50/50 hover:shadow-sm rounded-2xl p-4 transition-all duration-300';
                        badge.className = 'text-xs font-bold text-green-600 bg-green-50 px-3 py-1.5 rounded-lg border border-green-100 transition-colors';
                        badge.innerText = T.available.replace('%d', remaining);
                    }
                }
            })
            .catch(() => {});
    }
    setInterval(updateSlots, 3000);
});
</script>

<?php render_footer(); ?>
