<?php
// user/booking_time.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();
check_user_profile((int)($_SESSION['evax_student_id'] ?? 0));
$studentId = (int)$_SESSION['evax_student_id'];

$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
$day = (int)($_GET['day'] ?? 0);
$campaignId = (int)($_GET['campaign_id'] ?? 0);

if ($year == 0 || $month == 0 || $day == 0 || $campaignId == 0) {
    header('Location: booking_campaign.php');
    exit;
}

$selectedDateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
$displayDate = date('j F Y', strtotime($selectedDateStr));

$pdo = db();

// 1. ดึงข้อมูลแคมเปญ และเช็คสถานะ
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

// 2. ดึงสล็อตเวลาเริ่มต้น
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

render_header('เลือกรอบเวลา');
?>

<div class="p-5 pb-32 flex flex-col h-full animate-in fade-in slide-in-from-right-4 duration-500">
    <div class="flex-1">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center text-xl shrink-0">
                <i class="fa-solid fa-clock text-[#0052CC]"></i>
            </div>
            <div>
                <h2 class="text-xl font-bold text-gray-900 leading-tight">เลือกรอบเวลา</h2>
                <p class="text-sm text-[#0052CC] font-semibold mt-0.5"><?= $displayDate ?></p>
            </div>
        </div>

        <form action="submit_booking.php" method="POST" id="bookingForm">
            <input type="hidden" name="booking_date" value="<?= $selectedDateStr ?>">
            <input type="hidden" name="campaign_id" value="<?= $campaignId ?>">
            <?php csrf_field(); ?>
            
            <div class="mb-6 bg-white p-5 rounded-2xl shadow-sm border border-gray-100">
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">
                    <i class="fa-solid fa-thumbtack mr-1"></i> กิจกรรมที่เลือก
                </label>
                <div class="font-bold text-gray-900 text-lg">
                    <?= htmlspecialchars($campaign['title']) ?>
                </div>
            </div>

            <label class="block text-sm font-semibold text-gray-700 mb-3 ml-1">เลือกรอบเวลา (อัปเดตแบบ Real-time)</label>
            <div class="space-y-3">
                <?php if (count($timeSlots) === 0): ?>
                    <div class="bg-gray-50 p-8 rounded-3xl text-center border-2 border-dashed border-gray-200">
                        <div class="text-3xl text-gray-300 mb-2"><i class="fa-regular fa-clock"></i></div>
                        <p class="text-gray-500 font-medium">ไม่พบรอบเวลาที่เปิดรับในวันนี้</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($timeSlots as $slot): 
                        $timeStr = substr($slot['start_time'], 0, 5) . ' - ' . substr($slot['end_time'], 0, 5);
                        $remaining = $slot['max_capacity'] - $slot['booked_count'];
                        $isFull = $remaining <= 0;
                    ?>
                        <label id="slot-label-<?= $slot['id'] ?>" class="relative block bg-white border <?= $isFull ? 'border-red-200 opacity-60' : 'border-gray-200 cursor-pointer hover:border-[#0052CC] hover:bg-blue-50/50 hover:shadow-sm' ?> rounded-2xl p-4 transition-all duration-300">
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <input type="radio" id="slot-radio-<?= $slot['id'] ?>" name="slot_id" value="<?= $slot['id'] ?>" <?= $isFull ? 'disabled' : 'required' ?> class="w-5 h-5 text-[#0052CC] focus:ring-[#0052CC] border-gray-300 cursor-pointer disabled:cursor-not-allowed" data-time="<?= $timeStr ?>">
                                    <span class="font-bold text-gray-900 text-lg font-prompt"><?= $timeStr ?></span>
                                </div>
                                <div>
                                    <span id="slot-status-<?= $slot['id'] ?>" class="text-xs font-bold px-3 py-1.5 rounded-lg border transition-colors <?= $isFull ? 'text-red-500 bg-red-50 border-red-100' : 'text-green-600 bg-green-50 border-green-100' ?>">
                                        <?= $isFull ? 'เต็มแล้ว' : "ว่าง $remaining ที่" ?>
                                    </span>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="fixed bottom-0 left-0 right-0 max-w-md mx-auto p-4 bg-white border-t border-gray-100 z-20 flex gap-3 shadow-[0_-4px_20px_-10px_rgba(0,0,0,0.1)]">
                <a href="booking_date.php?year=<?= $year ?>&month=<?= $month ?>&campaign_id=<?= $campaignId ?>" class="px-6 py-4 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold rounded-xl transition-colors text-center shadow-sm active:scale-95">ย้อนกลับ</a>
                <button type="submit" class="flex-1 bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition-colors text-center shadow-sm active:scale-95">
                    ยืนยันรอบเวลา
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateStr = '<?= $selectedDateStr ?>';
    const campId = <?= $campaignId ?>;
    
    // 🌟 ระบบแจ้งเตือนยืนยันก่อนบันทึก (SweetAlert2)
    const bookingForm = document.getElementById('bookingForm');
    bookingForm.addEventListener('submit', function(e) {
        e.preventDefault(); // หยุดการส่งฟอร์มทันที
        
        // หาตัวเลือก Radio ที่ถูกติ๊ก เพื่อเอาข้อความเวลามาแสดงในแจ้งเตือน
        const selectedRadio = document.querySelector('input[name="slot_id"]:checked');
        let selectedTimeText = '';
        if (selectedRadio) {
            selectedTimeText = selectedRadio.getAttribute('data-time');
        }
        
        Swal.fire({
            title: 'ยืนยันรอบเวลา?',
            html: `คุณต้องการจอง <b><?= htmlspecialchars($campaign['title']) ?></b><br>รอบเวลา <span class="text-[#0052CC] font-bold">${selectedTimeText}</span> ใช่หรือไม่?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0052CC',
            cancelButtonColor: '#6B7280',
            confirmButtonText: 'ใช่, ยืนยันการจอง',
            cancelButtonText: 'ยกเลิก',
            reverseButtons: true, // สลับปุ่มกดยืนยันมาไว้ขวา
            customClass: {
                title: 'font-prompt text-xl',
                htmlContainer: 'font-prompt text-gray-600',
                popup: 'font-prompt rounded-3xl',
                confirmButton: 'font-prompt rounded-xl px-5 py-2.5 shadow-md',
                cancelButton: 'font-prompt rounded-xl px-5 py-2.5'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // ถ้าเด็กกดยืนยัน ค่อยสั่งให้ฟอร์มส่งข้อมูลจริงๆ
                bookingForm.submit();
            }
        });
    });

    // 🌟 ระบบดึงข้อมูล Real-time อัปเดตที่นั่ง
    function updateSlotsRealtime() {
        fetch(`api_get_slots.php?date=${dateStr}&campaign_id=${campId}`)
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    for (const [slotId, remaining] of Object.entries(data.data)) {
                        const label = document.getElementById(`slot-label-${slotId}`);
                        const radio = document.getElementById(`slot-radio-${slotId}`);
                        const statusBadge = document.getElementById(`slot-status-${slotId}`);
                        
                        if(!label || !radio || !statusBadge) continue;
                        
                        if(remaining <= 0) {
                            if (radio.checked) {
                                radio.checked = false; 
                                Swal.fire({
                                    title: 'คิวเต็มแล้ว!',
                                    text: 'ขออภัย รอบเวลาที่คุณเลือกเพิ่งถูกจองเต็มไปเมื่อสักครู่ กรุณาเลือกรอบอื่นครับ',
                                    icon: 'warning',
                                    confirmButtonColor: '#0052CC',
                                    confirmButtonText: 'ตกลง',
                                    customClass: { title: 'font-prompt', popup: 'font-prompt rounded-2xl', confirmButton: 'font-prompt rounded-xl' }
                                });
                            }
                            radio.disabled = true;
                            label.className = "relative block bg-white border border-red-200 opacity-60 rounded-2xl p-4 transition-all duration-300";
                            statusBadge.className = "text-xs font-bold text-red-500 bg-red-50 px-3 py-1.5 rounded-lg border border-red-100 transition-colors";
                            statusBadge.innerText = "เต็มแล้ว";
                        } else {
                            radio.disabled = false;
                            label.className = "relative block bg-white border border-gray-200 cursor-pointer hover:border-[#0052CC] hover:bg-blue-50/50 hover:shadow-sm rounded-2xl p-4 transition-all duration-300";
                            statusBadge.className = "text-xs font-bold text-green-600 bg-green-50 px-3 py-1.5 rounded-lg border border-green-100 transition-colors";
                            statusBadge.innerText = `ว่าง ${remaining} ที่`;
                        }
                    }
                }
            })
            .catch(error => console.error('Error fetching real-time slots:', error));
    }

    setInterval(updateSlotsRealtime, 3000);
});
</script>

<?php render_footer(); ?>
