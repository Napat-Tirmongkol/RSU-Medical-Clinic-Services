<?php
// admin/campaign_overview.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// รับ campaign_id จาก GET
$campaignId = (int)($_GET['id'] ?? 0);

// ดึงรายการแคมเปญทั้งหมดสำหรับ dropdown
$allCampaigns = $pdo->query("SELECT id, title, status FROM camp_list ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$campaign = null;
$stats = [];
$statusBreakdown = [];
$dailyTrend = [];
$slotUtil = [];
$recentBookings = [];

if ($campaignId > 0) {
    // ข้อมูลแคมเปญ
    $stmt = $pdo->prepare("
        SELECT c.*,
            (SELECT COUNT(*) FROM camp_bookings b WHERE b.campaign_id = c.id AND b.status IN ('booked','confirmed')) AS used_capacity,
            (SELECT COUNT(*) FROM camp_bookings b WHERE b.campaign_id = c.id) AS total_bookings,
            (SELECT COUNT(*) FROM camp_slots s WHERE s.campaign_id = c.id) AS total_slots
        FROM camp_list c WHERE c.id = :id
    ");
    $stmt->execute([':id' => $campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($campaign) {
        $cap = (int)$campaign['total_capacity'];
        $used = (int)$campaign['used_capacity'];
        $remaining = max(0, $cap - $used);
        $pct = $cap > 0 ? round($used / $cap * 100) : 0;

        $stats = compact('cap', 'used', 'remaining', 'pct');

        // สถิติแยกตามสถานะ
        $stmt2 = $pdo->prepare("
            SELECT status, COUNT(*) AS cnt
            FROM camp_bookings WHERE campaign_id = :id
            GROUP BY status
        ");
        $stmt2->execute([':id' => $campaignId]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusBreakdown[$row['status']] = (int)$row['cnt'];
        }

        // Trend รายวัน (30 วันล่าสุด)
        $stmt3 = $pdo->prepare("
            SELECT DATE(created_at) AS day, COUNT(*) AS cnt
            FROM camp_bookings
            WHERE campaign_id = :id AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY day ASC
        ");
        $stmt3->execute([':id' => $campaignId]);
        $dailyTrend = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        // การใช้งานรายรอบ (slot utilization)
        $stmt4 = $pdo->prepare("
            SELECT s.id, s.slot_date, s.start_time, s.end_time, s.max_capacity,
                COUNT(CASE WHEN b.status IN ('booked','confirmed') THEN 1 END) AS booked_cnt
            FROM camp_slots s
            LEFT JOIN camp_bookings b ON b.slot_id = s.id
            WHERE s.campaign_id = :id
            GROUP BY s.id
            ORDER BY s.slot_date ASC, s.start_time ASC
            LIMIT 50
        ");
        $stmt4->execute([':id' => $campaignId]);
        $slotUtil = $stmt4->fetchAll(PDO::FETCH_ASSOC);

        // รายชื่อผู้จองล่าสุด
        $stmt5 = $pdo->prepare("
            SELECT b.id, b.status, b.created_at,
                u.full_name, u.student_personnel_id, u.phone_number,
                s.slot_date, s.start_time, s.end_time
            FROM camp_bookings b
            JOIN sys_users u ON b.student_id = u.id
            JOIN camp_slots s ON b.slot_id = s.id
            WHERE b.campaign_id = :id
            ORDER BY b.created_at DESC
            LIMIT 100
        ");
        $stmt5->execute([':id' => $campaignId]);
        $recentBookings = $stmt5->fetchAll(PDO::FETCH_ASSOC);
    }
}

function statusLabel(string $s): string {
    return match($s) {
        'booked'             => 'รอยืนยัน',
        'confirmed'          => 'ยืนยันแล้ว',
        'cancelled'          => 'ยกเลิกโดยผู้ใช้',
        'cancelled_by_admin' => 'ยกเลิกโดย Admin',
        default              => $s,
    };
}
function statusBadge(string $s): string {
    return match($s) {
        'booked'             => 'bg-yellow-100 text-yellow-700',
        'confirmed'          => 'bg-green-100 text-green-700',
        'cancelled'          => 'bg-red-100 text-red-600',
        'cancelled_by_admin' => 'bg-gray-100 text-gray-600',
        default              => 'bg-gray-100 text-gray-500',
    };
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
@keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
.fade-up { animation: fadeUp .35s ease both; }
.card { background:#fff; border-radius:1rem; box-shadow:0 1px 4px rgba(0,0,0,.07); }
</style>

<?php renderPageHeader(
    '<i class="fa-solid fa-chart-bar text-[#0052CC]"></i> ภาพรวมแคมเปญ',
    'Campaign Overview & Analytics'
); ?>

<!-- Campaign Selector -->
<div class="card p-5 mb-6 fade-up flex flex-col md:flex-row gap-4 items-center">
    <label class="text-sm font-bold text-gray-600 whitespace-nowrap">เลือกแคมเปญ :</label>
    <form method="get" class="flex gap-3 flex-1 flex-wrap">
        <select name="id" onchange="this.form.submit()"
            class="flex-1 min-w-[220px] border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-medium outline-none focus:ring-2 focus:ring-blue-400 bg-white">
            <option value="">— เลือกแคมเปญ —</option>
            <?php foreach ($allCampaigns as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $c['id'] == $campaignId ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['title']) ?>
                <?= $c['status'] !== 'active' ? ' ['.htmlspecialchars($c['status']).']' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($campaign): ?>
    <a href="campaigns.php" class="text-sm text-blue-600 hover:underline whitespace-nowrap">
        <i class="fa-solid fa-pen-to-square mr-1"></i>แก้ไขแคมเปญ
    </a>
    <?php endif; ?>
</div>

<?php if (!$campaign && $campaignId > 0): ?>
<div class="card p-10 text-center text-gray-400 fade-up">
    <i class="fa-solid fa-triangle-exclamation text-3xl mb-3 block"></i>
    ไม่พบแคมเปญนี้
</div>
<?php elseif (!$campaign): ?>
<div class="card p-14 text-center text-gray-300 fade-up">
    <i class="fa-solid fa-chart-pie text-6xl mb-4 block"></i>
    <p class="text-lg font-semibold text-gray-400">เลือกแคมเปญเพื่อดูภาพรวม</p>
</div>
<?php else: ?>

<?php
// ข้อมูลสำหรับ charts (encode เป็น JSON)
$statusColors = [
    'confirmed'          => '#22c55e',
    'booked'             => '#f59e0b',
    'cancelled'          => '#ef4444',
    'cancelled_by_admin' => '#9ca3af',
];
$statusLabelsMap = [
    'confirmed'          => 'ยืนยันแล้ว',
    'booked'             => 'รอยืนยัน',
    'cancelled'          => 'ยกเลิก (ผู้ใช้)',
    'cancelled_by_admin' => 'ยกเลิก (Admin)',
];
$donutLabels = [];
$donutData   = [];
$donutColors = [];
foreach ($statusBreakdown as $st => $cnt) {
    $donutLabels[] = $statusLabelsMap[$st] ?? $st;
    $donutData[]   = $cnt;
    $donutColors[] = $statusColors[$st] ?? '#6b7280';
}

$trendLabels = array_column($dailyTrend, 'day');
$trendData   = array_column($dailyTrend, 'cnt');

$slotLabels = [];
$slotBooked = [];
$slotMax    = [];
foreach ($slotUtil as $sl) {
    $slotLabels[] = date('d/m', strtotime($sl['slot_date'])) . ' ' . substr($sl['start_time'], 0, 5);
    $slotBooked[] = (int)$sl['booked_cnt'];
    $slotMax[]    = (int)$sl['max_capacity'];
}
?>

<!-- Campaign Header Info -->
<div class="card p-5 mb-6 fade-up flex flex-col md:flex-row md:items-center gap-4">
    <div class="flex-1">
        <h2 class="text-xl font-extrabold text-gray-900"><?= htmlspecialchars($campaign['title']) ?></h2>
        <?php if ($campaign['description']): ?>
        <p class="text-sm text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars($campaign['description']) ?></p>
        <?php endif; ?>
    </div>
    <div class="flex flex-wrap gap-2 text-xs font-semibold">
        <?php
        $statusCss = match($campaign['status']) {
            'active'   => 'bg-green-100 text-green-700',
            'inactive' => 'bg-gray-100 text-gray-500',
            'full'     => 'bg-red-100 text-red-600',
            default    => 'bg-gray-100 text-gray-500',
        };
        ?>
        <span class="px-3 py-1 rounded-full <?= $statusCss ?>">
            <?= htmlspecialchars($campaign['status']) ?>
        </span>
        <?php if ($campaign['available_until']): ?>
        <span class="px-3 py-1 rounded-full bg-blue-50 text-blue-600">
            <i class="fa-regular fa-calendar mr-1"></i>
            ถึง <?= htmlspecialchars($campaign['available_until']) ?>
        </span>
        <?php endif; ?>
        <?php if ($campaign['is_auto_approve']): ?>
        <span class="px-3 py-1 rounded-full bg-purple-50 text-purple-600">
            <i class="fa-solid fa-bolt mr-1"></i>Auto Approve
        </span>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $cards = [
        ['label' => 'โควต้าทั้งหมด', 'value' => number_format($stats['cap']),      'icon' => 'fa-users',          'color' => 'text-blue-600',   'bg' => 'bg-blue-50'],
        ['label' => 'จองแล้ว',        'value' => number_format($stats['used']),     'icon' => 'fa-clipboard-check','color' => 'text-green-600',  'bg' => 'bg-green-50'],
        ['label' => 'คงเหลือ',        'value' => number_format($stats['remaining']),'icon' => 'fa-circle-dot',    'color' => 'text-amber-600',  'bg' => 'bg-amber-50'],
        ['label' => 'เต็ม',           'value' => $stats['pct'] . '%',              'icon' => 'fa-chart-pie',      'color' => 'text-purple-600', 'bg' => 'bg-purple-50'],
    ];
    foreach ($cards as $i => $card):
    ?>
    <div class="card p-5 fade-up" style="animation-delay:<?= $i * .06 ?>s">
        <div class="flex items-center justify-between mb-3">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider"><?= $card['label'] ?></span>
            <div class="w-9 h-9 rounded-xl <?= $card['bg'] ?> flex items-center justify-center">
                <i class="fa-solid <?= $card['icon'] ?> <?= $card['color'] ?> text-sm"></i>
            </div>
        </div>
        <p class="text-3xl font-[950] text-gray-900"><?= $card['value'] ?></p>
        <?php if ($i === 3): ?>
        <div class="mt-3 h-2 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full bg-purple-400 rounded-full transition-all" style="width:<?= min(100,$stats['pct']) ?>%"></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">

    <!-- Donut: สถานะ -->
    <div class="card p-5 fade-up" style="animation-delay:.1s">
        <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
            <i class="fa-solid fa-chart-donut text-blue-500"></i> สัดส่วนสถานะการจอง
        </h3>
        <?php if (!empty($donutData)): ?>
        <div class="flex flex-col sm:flex-row items-center gap-6">
            <div class="relative w-44 h-44 shrink-0">
                <canvas id="donutChart"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-2xl font-[950] text-gray-800"><?= array_sum($donutData) ?></span>
                    <span class="text-[10px] font-bold text-gray-400 uppercase">ทั้งหมด</span>
                </div>
            </div>
            <div class="flex flex-col gap-2 text-sm">
                <?php foreach ($donutLabels as $li => $lbl): ?>
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full shrink-0" style="background:<?= $donutColors[$li] ?>"></span>
                    <span class="text-gray-600"><?= htmlspecialchars($lbl) ?></span>
                    <span class="ml-auto font-bold text-gray-800"><?= $donutData[$li] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="py-10 text-center text-gray-300 text-sm">ยังไม่มีข้อมูลการจอง</div>
        <?php endif; ?>
    </div>

    <!-- Line: Trend รายวัน -->
    <div class="card p-5 fade-up" style="animation-delay:.15s">
        <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
            <i class="fa-solid fa-chart-line text-purple-500"></i> การจองรายวัน (30 วันล่าสุด)
        </h3>
        <?php if (!empty($trendData)): ?>
        <canvas id="trendChart" height="160"></canvas>
        <?php else: ?>
        <div class="py-10 text-center text-gray-300 text-sm">ยังไม่มีข้อมูล</div>
        <?php endif; ?>
    </div>
</div>

<!-- Slot Utilization Bar Chart -->
<?php if (!empty($slotUtil)): ?>
<div class="card p-5 mb-6 fade-up" style="animation-delay:.2s">
    <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
        <i class="fa-solid fa-chart-column text-teal-500"></i> การใช้งานรายรอบเวลา
    </h3>
    <div class="overflow-x-auto">
        <div style="min-width:<?= max(400, count($slotUtil) * 54) ?>px; height:220px;">
            <canvas id="slotChart"></canvas>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bookings Table -->
<div class="card p-5 fade-up" style="animation-delay:.25s">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <h3 class="font-bold text-gray-700 flex items-center gap-2">
            <i class="fa-solid fa-list text-gray-400"></i>
            รายชื่อผู้จอง
            <span class="ml-1 text-xs font-semibold bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full"><?= count($recentBookings) ?></span>
        </h3>
        <div class="flex gap-2">
            <input id="bookingSearch" type="text" placeholder="ค้นหาชื่อ / รหัส..."
                class="border border-gray-200 rounded-xl px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-300 w-52">
            <a href="reports.php?campaign_id=<?= $campaignId ?>" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-xl transition-colors flex items-center gap-1.5">
                <i class="fa-solid fa-download text-xs"></i> Export
            </a>
        </div>
    </div>

    <?php if (empty($recentBookings)): ?>
    <div class="py-12 text-center text-gray-300 text-sm">ยังไม่มีผู้จอง</div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" id="bookingTable">
            <thead>
                <tr class="border-b border-gray-100 text-left">
                    <th class="pb-3 pr-4 font-bold text-gray-500 text-xs uppercase tracking-wide">#</th>
                    <th class="pb-3 pr-4 font-bold text-gray-500 text-xs uppercase tracking-wide">ชื่อ-สกุล</th>
                    <th class="pb-3 pr-4 font-bold text-gray-500 text-xs uppercase tracking-wide">รหัส</th>
                    <th class="pb-3 pr-4 font-bold text-gray-500 text-xs uppercase tracking-wide">เบอร์โทร</th>
                    <th class="pb-3 pr-4 font-bold text-gray-500 text-xs uppercase tracking-wide">วัน-เวลา</th>
                    <th class="pb-3 pr-4 font-bold text-gray-500 text-xs uppercase tracking-wide">สถานะ</th>
                    <th class="pb-3 font-bold text-gray-500 text-xs uppercase tracking-wide">วันที่จอง</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50" id="bookingTbody">
                <?php foreach ($recentBookings as $i => $bk): ?>
                <tr class="hover:bg-gray-50 transition-colors" data-search="<?= strtolower(htmlspecialchars($bk['full_name'].' '.$bk['student_personnel_id'])) ?>">
                    <td class="py-3 pr-4 text-gray-400 font-mono text-xs"><?= $i+1 ?></td>
                    <td class="py-3 pr-4 font-semibold text-gray-800"><?= htmlspecialchars($bk['full_name'] ?: '—') ?></td>
                    <td class="py-3 pr-4 text-gray-500 font-mono text-xs"><?= htmlspecialchars($bk['student_personnel_id'] ?: '—') ?></td>
                    <td class="py-3 pr-4 text-gray-500"><?= htmlspecialchars($bk['phone_number'] ?: '—') ?></td>
                    <td class="py-3 pr-4 text-gray-600">
                        <?php
                        $d = new DateTime($bk['slot_date']);
                        $thDays = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
                        echo $thDays[$d->format('w')] . ' ' . $d->format('d/m/y');
                        echo ' <span class="text-[#0052CC] font-bold">'.substr($bk['start_time'],0,5).'-'.substr($bk['end_time'],0,5).'</span>';
                        ?>
                    </td>
                    <td class="py-3 pr-4">
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold <?= statusBadge($bk['status']) ?>">
                            <?= statusLabel($bk['status']) ?>
                        </span>
                    </td>
                    <td class="py-3 text-gray-400 text-xs whitespace-nowrap"><?= (new DateTime($bk['created_at']))->format('d/m/y H:i') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Prompt', sans-serif";
Chart.defaults.color = '#6b7280';

<?php if (!empty($donutData)): ?>
new Chart(document.getElementById('donutChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($donutLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            data: <?= json_encode($donutData) ?>,
            backgroundColor: <?= json_encode($donutColors) ?>,
            borderWidth: 3,
            borderColor: '#fff',
            hoverOffset: 6,
        }]
    },
    options: {
        cutout: '68%',
        plugins: { legend: { display: false }, tooltip: { callbacks: {
            label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' คน'
        }}},
        animation: { animateScale: true }
    }
});
<?php endif; ?>

<?php if (!empty($trendData)): ?>
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($trendLabels) ?>,
        datasets: [{
            label: 'การจอง',
            data: <?= json_encode($trendData) ?>,
            borderColor: '#6366f1',
            backgroundColor: 'rgba(99,102,241,.12)',
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: '#6366f1',
            tension: 0.4,
            fill: true,
        }]
    },
    options: {
        scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 11 } } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { precision: 0 } }
        },
        plugins: { legend: { display: false } },
        interaction: { mode: 'index', intersect: false },
    }
});
<?php endif; ?>

<?php if (!empty($slotUtil)): ?>
new Chart(document.getElementById('slotChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($slotLabels, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
            {
                label: 'จองแล้ว',
                data: <?= json_encode($slotBooked) ?>,
                backgroundColor: 'rgba(34,197,94,.75)',
                borderRadius: 5,
                borderSkipped: false,
            },
            {
                label: 'โควต้ารวม',
                data: <?= json_encode($slotMax) ?>,
                backgroundColor: 'rgba(209,213,219,.5)',
                borderRadius: 5,
                borderSkipped: false,
            }
        ]
    },
    options: {
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 45 } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { precision: 0 } }
        },
        plugins: { legend: { position: 'top', labels: { font: { size: 12 }, usePointStyle: true } } },
        interaction: { mode: 'index', intersect: false },
        maintainAspectRatio: false,
    }
});
<?php endif; ?>

// Live search for booking table
document.getElementById('bookingSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#bookingTbody tr').forEach(tr => {
        tr.style.display = tr.dataset.search.includes(q) ? '' : 'none';
    });
});
</script>

<?php endif; // end if $campaign ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
