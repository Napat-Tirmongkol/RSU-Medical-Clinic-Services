<?php
// admin/index.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// โหลดข้อมูลครั้งแรกตอนเปิดหน้าเว็บ
$stmt = $pdo->query("
    SELECT
        COUNT(*) as total_campaigns,
        (SELECT COUNT(*) FROM camp_bookings WHERE status = 'booked') as pending_count,
        (SELECT COUNT(*) FROM camp_bookings WHERE status = 'confirmed') as confirmed_count,
        (SELECT COUNT(*) FROM camp_bookings WHERE DATE(created_at) = CURDATE()) as bookings_today,
        (SELECT COUNT(*) FROM sys_users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_users_7d
    FROM camp_list WHERE status = 'active'
");
$stats = $stmt->fetch();

$popular_stmt = $pdo->query("
    SELECT c.title, COUNT(a.id) as booking_count
    FROM camp_list c
    LEFT JOIN camp_bookings a ON c.id = a.campaign_id AND a.status IN ('booked', 'confirmed')
    GROUP BY c.id
    ORDER BY booking_count DESC
    LIMIT 5
");
$popular_campaigns = $popular_stmt->fetchAll();

// Appointment Density Heatmap — ความหนาแน่นของวัน/เวลาที่ถูกจองมาใช้บริการมากที่สุด
$heatmap_rows = $pdo->query("
    SELECT DAYOFWEEK(s.slot_date) AS dow,
           HOUR(s.start_time)      AS hr,
           COUNT(*)              AS cnt
    FROM camp_bookings b
    JOIN camp_slots s ON b.slot_id = s.id
    WHERE b.status IN ('booked', 'confirmed')
    GROUP BY dow, hr
")->fetchAll(PDO::FETCH_ASSOC);

$heatmap  = [];
$hmap_max = 1;
foreach ($heatmap_rows as $r) {
    if ($r['hr'] === null) continue; // ป้องกันข้อมูลเวลาผิดพลาด
    $heatmap[(int)$r['dow']][(int)$r['hr']] = (int)$r['cnt'];
    if ((int)$r['cnt'] > $hmap_max) $hmap_max = (int)$r['cnt'];
}

function hmapColor(int $cnt, int $max): string {
    if ($cnt === 0) return '#f8fafc'; // Empty (Slate 50)
    $ratio = $cnt / $max;
    if ($ratio < 0.15) return '#dcfce7'; // Green 100
    if ($ratio < 0.35) return '#86efac'; // Green 300
    if ($ratio < 0.60) return '#4ade80'; // Green 400
    if ($ratio < 0.80) return '#22c55e'; // Green 500
    return '#16a34a'; // Green 600
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* CSS Animations เพิ่มความแพง */
@keyframes slideUpFade {
    0% { opacity: 0; transform: translateY(20px); }
    100% { opacity: 1; transform: translateY(0); }
}
.animate-slide-up { animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
.delay-100 { animation-delay: 0.1s; }
.delay-200 { animation-delay: 0.2s; }
.delay-300 { animation-delay: 0.3s; }

/* Custom Scrollbar for list */
.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
</style>

<!-- HEADER SECTION -->
<?php 
$header_title = 'ภาพรวมระบบ (Dashboard)
    <div class="relative flex h-4 w-4" title="ระบบกำลังอัปเดตแบบ Real-time">
      <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
      <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500 top-0.5 left-0.5"></span>
    </div>';
renderPageHeader($header_title, "สถิติการลงทะเบียน อัปเดตข้อมูลแบบ Real-time รอบทุกซอกทุกมุม"); 
?>

<!-- STATS GRID — 2 cols on mobile, 4 on desktop -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5 mb-6 sm:mb-10">

    <!-- STAT CARD 1: Active Campaigns -->
    <div class="group bg-white p-3 sm:p-5 rounded-[18px] sm:rounded-[22px] shadow-sm border border-gray-100 relative overflow-hidden transition-all duration-300 hover:shadow-xl hover:shadow-green-500/10 hover:-translate-y-1 animate-slide-up">
        <div class="absolute right-0 top-0 w-1.5 h-full bg-gradient-to-b from-[#2e9e63] to-[#0B6623]"></div>
        <div class="flex justify-between items-start">
            <div class="min-w-0">
                <p class="text-gray-500 text-[10px] sm:text-xs font-semibold mb-1 uppercase tracking-wide leading-tight">แคมเปญที่เปิด</p>
                <h3 id="stat-total" class="text-2xl sm:text-3xl lg:text-4xl font-black text-gray-800 transition-all duration-300"><?= number_format((float)$stats['total_campaigns']) ?></h3>
            </div>
            <div class="w-9 h-9 sm:w-12 sm:h-12 bg-[#e8f8f0] text-[#2e9e63] rounded-xl sm:rounded-2xl flex items-center justify-center shadow-inner flex-shrink-0">
                <i class="fa-solid fa-bullhorn text-base sm:text-xl"></i>
            </div>
        </div>
        <div class="mt-2 sm:mt-3 flex items-center gap-1.5 text-[10px] sm:text-xs text-[#2e9e63] font-semibold bg-[#e8f8f0] w-max px-2.5 py-1 rounded-full">
            <i class="fa-solid fa-circle-check"></i> Active
        </div>
    </div>

    <!-- STAT CARD 2: Bookings Today -->
    <div class="group bg-white p-3 sm:p-5 rounded-[18px] sm:rounded-[22px] shadow-sm border border-gray-100 relative overflow-hidden transition-all duration-300 hover:shadow-emerald-500/10 hover:-translate-y-1 animate-slide-up delay-100">
        <div class="absolute right-0 top-0 w-1.5 h-full bg-gradient-to-b from-emerald-400 to-[#2e9e63]"></div>
        <div class="flex justify-between items-start">
            <div class="min-w-0">
                <p class="text-gray-500 text-[10px] sm:text-xs font-semibold mb-1 uppercase tracking-wide leading-tight">จองวันนี้</p>
                <h3 id="stat-bookings-today" class="text-2xl sm:text-3xl lg:text-4xl font-black text-gray-800 transition-all duration-300"><?= number_format((float)$stats['bookings_today']) ?></h3>
            </div>
            <div class="w-9 h-9 sm:w-12 sm:h-12 bg-emerald-50 text-emerald-600 rounded-xl sm:rounded-2xl flex items-center justify-center shadow-inner flex-shrink-0">
                <i class="fa-solid fa-calendar-day text-base sm:text-xl"></i>
            </div>
        </div>
        <div class="mt-2 sm:mt-3 flex items-center gap-1.5 text-[10px] sm:text-xs text-emerald-600 font-semibold bg-emerald-50 w-max px-2.5 py-1 rounded-full">
            <i class="fa-solid fa-clock"></i> วันนี้
        </div>
    </div>

    <!-- STAT CARD 3: New Users (7d) -->
    <div class="group bg-white p-3 sm:p-5 rounded-[18px] sm:rounded-[22px] shadow-sm border border-gray-100 relative overflow-hidden transition-all duration-300 hover:shadow-teal-500/10 hover:-translate-y-1 animate-slide-up delay-200">
        <div class="absolute right-0 top-0 w-1.5 h-full bg-gradient-to-b from-teal-400 to-teal-600"></div>
        <div class="flex justify-between items-start">
            <div class="min-w-0">
                <p class="text-gray-500 text-[10px] sm:text-xs font-semibold mb-1 uppercase tracking-wide leading-tight">User ใหม่ 7 วัน</p>
                <h3 id="stat-new-users" class="text-2xl sm:text-3xl lg:text-4xl font-black text-gray-800 transition-all duration-300"><?= number_format((float)$stats['new_users_7d']) ?></h3>
            </div>
            <div class="w-9 h-9 sm:w-12 sm:h-12 bg-teal-50 text-teal-600 rounded-xl sm:rounded-2xl flex items-center justify-center shadow-inner flex-shrink-0">
                <i class="fa-solid fa-user-plus text-base sm:text-xl"></i>
            </div>
        </div>
        <div class="mt-2 sm:mt-3 flex items-center gap-1.5 text-[10px] sm:text-xs text-teal-600 font-semibold bg-teal-50 w-max px-2.5 py-1 rounded-full">
            <i class="fa-solid fa-arrow-trend-up"></i> 7 วันล่าสุด
        </div>
    </div>

    <!-- STAT CARD 4: Pending Approval -->
    <div class="group bg-white p-3 sm:p-5 rounded-[18px] sm:rounded-[22px] shadow-sm border border-gray-100 relative overflow-hidden transition-all duration-300 hover:shadow-xl hover:shadow-orange-500/10 hover:-translate-y-1 animate-slide-up delay-300">
        <div class="absolute right-0 top-0 w-1.5 h-full bg-gradient-to-b from-orange-400 to-amber-500"></div>
        <div class="flex justify-between items-start">
            <div class="min-w-0">
                <p class="text-gray-500 text-[10px] sm:text-xs font-semibold mb-1 uppercase tracking-wide leading-tight">รออนุมัติ</p>
                <h3 id="stat-pending" class="text-2xl sm:text-3xl lg:text-4xl font-black text-gray-800 transition-all duration-300"><?= number_format((float)$stats['pending_count']) ?></h3>
            </div>
            <div class="w-9 h-9 sm:w-12 sm:h-12 bg-orange-50 text-orange-500 rounded-xl sm:rounded-2xl flex items-center justify-center shadow-inner flex-shrink-0">
                <i class="fa-solid fa-clock-rotate-left text-base sm:text-xl"></i>
            </div>
        </div>
        <div class="mt-2 sm:mt-3 flex items-center gap-1.5 text-[10px] sm:text-xs text-orange-500 font-semibold bg-orange-50 w-max px-2.5 py-1 rounded-full">
            <i class="fa-solid fa-circle-exclamation animate-pulse"></i> ด่วน
        </div>
    </div>

</div>

<!-- BOTTOM GRID -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 sm:gap-8 animate-slide-up delay-200">

    <!-- POPULAR camp_list -->
    <div class="bg-white rounded-[20px] sm:rounded-[24px] shadow-sm border border-gray-100 flex flex-col">
        <div class="p-4 sm:p-6 border-b border-gray-50 flex justify-between items-center">
            <div>
                <h3 class="font-bold text-gray-900 text-base sm:text-lg">รายการแคมเปญยอดฮิต</h3>
                <p class="text-xs text-gray-500 mt-1">5 อันดับที่มีผู้สนใจจองมากที่สุด</p>
            </div>
            <div class="w-10 h-10 bg-orange-50 rounded-full flex items-center justify-center flex-shrink-0">
                <i class="fa-solid fa-fire text-orange-500 text-lg animate-pulse"></i>
            </div>
        </div>

        <div class="p-3 sm:p-4 flex-1 custom-scrollbar" style="max-height: 320px; overflow-y: auto;">
            <div id="popular-camp_list-container" class="space-y-2 sm:space-y-3">
                <?php if(empty($popular_campaigns)): ?>
                    <div class="text-center py-10">
                        <i class="fa-solid fa-inbox text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-500 text-sm">ยังไม่มีข้อมูลการจอง</p>
                    </div>
                <?php else: ?>
                    <?php foreach($popular_campaigns as $index => $pc):
                        $rankColors = ['bg-orange-100 text-orange-600', 'bg-gray-100 text-gray-600', 'bg-amber-100 text-amber-600'];
                        $rankClass = $index < 3 ? $rankColors[$index] : 'bg-[#e8f8f0] text-[#2e9e63]';
                    ?>
                    <div class="group flex justify-between items-center p-2.5 sm:p-3 rounded-2xl hover:bg-gray-50 transition-colors border border-transparent hover:border-gray-100 gap-3">
                        <div class="flex items-center gap-3 sm:gap-4 min-w-0">
                            <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full flex items-center justify-center font-bold text-xs sm:text-sm flex-shrink-0 <?= $rankClass ?>">
                                <?= $index + 1 ?>
                            </div>
                            <span class="text-gray-800 font-semibold group-hover:text-[#2e9e63] transition-colors text-sm truncate"><?= htmlspecialchars($pc['title']) ?></span>
                        </div>
                        <span class="bg-white border border-gray-200 shadow-sm px-3 sm:px-4 py-1 sm:py-1.5 rounded-full text-xs font-bold text-gray-700 whitespace-nowrap flex-shrink-0">
                            <?= number_format($pc['booking_count']) ?> คน
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 lg:gap-6 flex-1">

        <a href="campaigns.php" class="relative overflow-hidden bg-gradient-to-br from-[#0B6623] to-[#1a8c35] text-white p-5 sm:p-8 rounded-[20px] sm:rounded-[24px] flex flex-col justify-between hover:shadow-xl hover:shadow-green-900/20 hover:-translate-y-1 transition-all group">
            <div class="absolute right-0 top-0 w-32 h-32 bg-white/10 rounded-bl-full transition-transform duration-500 group-hover:scale-110"></div>
            <div class="w-11 h-11 sm:w-14 sm:h-14 bg-white/20 backdrop-blur-md rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-6 shadow-inner">
                <i class="fa-solid fa-bullhorn text-lg sm:text-2xl"></i>
            </div>
            <div>
                <span class="block font-black text-lg sm:text-xl mb-1">สร้างแคมเปญใหม่</span>
                <span class="text-green-100 text-xs sm:text-sm">เริ่มต้นเปิดโครงการใหม่ให้คนเข้ามาจองสิทธิ์</span>
            </div>
            <i class="fa-solid fa-arrow-right absolute bottom-5 right-5 sm:bottom-8 sm:right-8 text-xl opacity-0 -translate-x-4 transition-all duration-300 group-hover:opacity-100 group-hover:translate-x-0"></i>
        </a>

        <a href="bookings.php" class="relative overflow-hidden bg-white border border-gray-100 p-5 sm:p-8 rounded-[20px] sm:rounded-[24px] flex flex-col justify-between hover:border-[#c7e8d5] hover:shadow-xl hover:shadow-green-500/5 hover:-translate-y-1 transition-all group">
            <div class="w-11 h-11 sm:w-14 sm:h-14 bg-[#e8f8f0] text-[#2e9e63] rounded-xl sm:rounded-2xl flex items-center justify-center mb-4 sm:mb-6">
                <i class="fa-solid fa-users text-lg sm:text-2xl"></i>
            </div>
            <div>
                <span class="block font-black text-gray-900 text-lg sm:text-xl mb-1 group-hover:text-[#2e9e63] transition-colors">จัดการผู้จอง</span>
                <span class="text-gray-500 text-xs sm:text-sm">ตรวจสอบ อนุมัติ และจัดการรายชื่อทั้งหมด</span>
            </div>
            <i class="fa-solid fa-arrow-right absolute bottom-5 right-5 sm:bottom-8 sm:right-8 text-xl text-[#2e9e63] opacity-0 -translate-x-4 transition-all duration-300 group-hover:opacity-100 group-hover:translate-x-0"></i>
        </a>

        <!-- Extra Action Card (Optional) -->
        <a href="reports.php" class="sm:col-span-2 relative overflow-hidden bg-gray-50 border border-gray-100 p-4 sm:p-6 rounded-[20px] sm:rounded-[24px] flex items-center justify-between hover:bg-gray-100 hover:-translate-y-1 transition-all group gap-3">
            <div class="flex items-center gap-3 sm:gap-4 min-w-0">
                <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gray-200 text-gray-600 rounded-xl flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-chart-line text-lg sm:text-xl"></i>
                </div>
                <div class="min-w-0">
                    <span class="block font-bold text-gray-800 text-sm sm:text-base">ดูรายงานสรุป (Reports)</span>
                    <span class="text-gray-500 text-xs">สรุปข้อมูลเป็น Excel / สถิติเชิงลึก</span>
                </div>
            </div>
            <i class="fa-solid fa-chevron-right text-gray-400 group-hover:text-gray-600 transition-colors flex-shrink-0"></i>
        </a>

    </div>
</div>

<!-- PEAK TIMES HEATMAP -->
<div class="mt-5 sm:mt-8 bg-white rounded-[20px] sm:rounded-[24px] shadow-sm border border-gray-100 animate-slide-up" style="animation-delay:0.4s; opacity:0;">
    <div class="p-4 sm:p-6 border-b border-gray-50 flex justify-between items-center">
        <div>
            <h3 class="font-bold text-gray-900 text-base sm:text-lg">Appointment Density</h3>
            <p class="text-xs text-gray-500 mt-1">วิเคราะห์ความหนาแน่นของ "ช่วงเวลาที่คนจอง" เข้ามาใช้บริการมากที่สุดในแต่ละวัน</p>
        </div>
        <div class="w-10 h-10 bg-red-50 rounded-full flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-fire-flame-curved text-red-400 text-lg"></i>
        </div>
    </div>
    <div class="p-4 sm:p-6">
        <div class="overflow-x-auto">
            <div style="min-width:540px;">

                <!-- HOUR LABELS -->
                <div class="flex items-end mb-1.5">
                    <div style="width:38px; flex-shrink:0;"></div>
                    <div class="flex flex-1 gap-[3px]">
                        <?php
                        $hourLabels = [0=>'12am',3=>'3am',6=>'6am',9=>'9am',12=>'12pm',15=>'3pm',18=>'6pm',21=>'9pm'];
                        for ($h = 0; $h < 24; $h++): ?>
                        <div style="flex:1; text-align:center;" class="text-[9px] sm:text-[10px] text-gray-400 font-medium leading-tight">
                            <?= isset($hourLabels[$h]) ? $hourLabels[$h] : '' ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>

                <!-- GRID ROWS -->
                <?php
                $hmapDays = [
                    ['label'=>'Mon','dow'=>2],
                    ['label'=>'Tue','dow'=>3],
                    ['label'=>'Wed','dow'=>4],
                    ['label'=>'Thu','dow'=>5],
                    ['label'=>'Fri','dow'=>6],
                    ['label'=>'Sat','dow'=>7],
                    ['label'=>'Sun','dow'=>1],
                ];
                foreach ($hmapDays as $day): ?>
                <div class="flex items-center mb-[3px]">
                    <div style="width:38px; flex-shrink:0;" class="text-[10px] sm:text-[11px] text-gray-500 font-semibold text-right pr-2.5">
                        <?= $day['label'] ?>
                    </div>
                    <div class="flex flex-1 gap-[3px]">
                        <?php for ($h = 0; $h < 24; $h++):
                            $cnt = $heatmap[$day['dow']][$h] ?? 0;
                            $col = hmapColor($cnt, $hmap_max);
                            $timeLabel = date('H:i', mktime($h, 0, 0));
                            $tip = $cnt > 0
                                ? "ช่วงเวลา {$timeLabel} น. | มีการจอง {$cnt} คิว"
                                : "ช่วงเวลา {$timeLabel} น. | ยังไม่มีคนจอง";
                        ?>
                        <div style="flex:1; aspect-ratio:1/1; background:<?= $col ?>; border-radius:3px; min-width:0; cursor:default;"
                             title="<?= htmlspecialchars($tip) ?>"
                             class="transition-opacity duration-150 hover:opacity-60">
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- LEGEND -->
                <div class="flex items-center gap-1.5 mt-4">
                    <div style="width:38px; flex-shrink:0;"></div>
                    <span class="text-[10px] text-gray-400 font-medium mr-0.5">Less</span>
                    <?php foreach (['#f8fafc','#dcfce7','#86efac','#4ade80','#22c55e','#16a34a'] as $lc): ?>
                    <div style="width:13px; height:13px; background:<?= $lc ?>; border-radius:3px; flex-shrink:0;"></div>
                    <?php endforeach; ?>
                    <span class="text-[10px] text-gray-400 font-medium ml-0.5">More</span>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Animate numbers counting up on load
    function animateValue(obj, start, end, duration) {
        if (!obj) return;
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            obj.innerHTML = Math.floor(progress * (end - start) + start).toLocaleString();
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        };
        window.requestAnimationFrame(step);
    }
    
    const totalEl         = document.getElementById('stat-total');
    const bookingsTodayEl = document.getElementById('stat-bookings-today');
    const newUsersEl      = document.getElementById('stat-new-users');
    const pendingEl       = document.getElementById('stat-pending');

    if (totalEl)         animateValue(totalEl,         0, <?= (int)$stats['total_campaigns'] ?>, 1000);
    if (bookingsTodayEl) animateValue(bookingsTodayEl, 0, <?= (int)$stats['bookings_today'] ?>,  1000);
    if (newUsersEl)      animateValue(newUsersEl,      0, <?= (int)$stats['new_users_7d'] ?>,     1000);
    if (pendingEl)       animateValue(pendingEl,       0, <?= (int)$stats['pending_count'] ?>,    1000);


    function updateDashboardRealtime() {
        fetch('ajax/ajax_dashboard.php')
            .then(response => response.json())
            .then(data => {
                if(data.status === 'success') {
                    // Flash effect on update if the value changed
                    const updateWithFlash = (id, newVal) => {
                        const el = document.getElementById(id);
                        if (!el) return;
                        const currentVal = el.innerText.replace(/,/g, '');
                        if (currentVal != newVal) {
                            el.style.transform = 'scale(1.1)';
                            el.style.color = '#2e9e63';
                            setTimeout(() => {
                                el.innerText = parseInt(newVal).toLocaleString();
                                el.style.transform = 'scale(1)';
                                el.style.color = '';
                            }, 200);
                        }
                    };

                    updateWithFlash('stat-total',          data.stats.total);
                    updateWithFlash('stat-bookings-today', data.stats.bookings_today);
                    updateWithFlash('stat-new-users',      data.stats.new_users_7d);
                    updateWithFlash('stat-pending',        data.stats.pending);
                    
                    const container = document.getElementById('popular-camp_list-container');
                    if (container && data.popular_html) {
                        // Very simple check to prevent unnecessary DOM redraws
                        if(container.innerHTML.trim() !== data.popular_html.trim()){
                            container.innerHTML = data.popular_html;
                        }
                    }
                }
            })
            .catch(error => console.error('Error fetching dashboard data:', error));
    }

    // Refresh every 3 seconds
    setInterval(updateDashboardRealtime, 3000);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
