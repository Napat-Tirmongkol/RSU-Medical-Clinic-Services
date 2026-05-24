<?php
// admin/campaign_overview.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// Ensure walkin_enabled column exists (idempotent — same logic in campaigns.php)
try { $pdo->exec("ALTER TABLE camp_list ADD COLUMN IF NOT EXISTS walkin_enabled TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException) {}

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
        $used = (int)$campaign['used_capacity'];  // booked + confirmed (active pipeline)
        $stats = compact('cap', 'used');

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

        // คำนวณ remaining/pct โดยรวม completed ด้วย (โควต้าที่ "ถูกใช้ไปจริง")
        $stats['awaiting']  = $statusBreakdown['confirmed'] ?? 0;
        $stats['attended']  = $statusBreakdown['completed'] ?? 0;
        $stats['occupied']  = $used + $stats['attended'];  // booked + confirmed + completed
        $stats['remaining'] = max(0, $cap - $stats['occupied']);
        $stats['pct']       = $cap > 0 ? round($stats['occupied'] / $cap * 100) : 0;

        // Trend รายวัน — 30 วันล่าสุดที่มีการจอง (ไม่ใช่ 30 calendar days)
        // ที่เปลี่ยน: filter "created_at >= NOW() - 30 day" ทำให้แคมเปญที่จองนานแล้วได้กราฟว่าง
        $stmt3 = $pdo->prepare("
            SELECT day, cnt FROM (
                SELECT DATE(created_at) AS day, COUNT(*) AS cnt
                FROM camp_bookings
                WHERE campaign_id = :id
                GROUP BY DATE(created_at)
                ORDER BY day DESC
                LIMIT 30
            ) t ORDER BY day ASC
        ");
        $stmt3->execute([':id' => $campaignId]);
        $dailyTrend = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        // การใช้งานรายรอบ (slot utilization) — นับทุกการจองที่ "ครองโควต้า" จริง
        // (booked + confirmed + completed + expired) ไม่นับยกเลิกที่คืนโควต้าแล้ว
        $stmt4 = $pdo->prepare("
            SELECT s.id, s.slot_date, s.start_time, s.end_time, s.max_capacity,
                COUNT(CASE WHEN b.status NOT IN ('cancelled','cancelled_by_admin') THEN 1 END) AS booked_cnt
            FROM camp_slots s
            LEFT JOIN camp_bookings b ON b.slot_id = s.id
            WHERE s.campaign_id = :id
            GROUP BY s.id
            ORDER BY s.slot_date ASC, s.start_time ASC
            LIMIT 50
        ");
        $stmt4->execute([':id' => $campaignId]);
        $slotUtil = $stmt4->fetchAll(PDO::FETCH_ASSOC);

        // รายชื่อผู้จอง — 25/หน้า (CLAUDE.md: pagination required)
        $bookingsPerPage = 25;
        $bookingsPage    = max(1, (int)($_GET['bp'] ?? 1));
        $bookingsTotal   = (int)$pdo->query("SELECT COUNT(*) FROM camp_bookings WHERE campaign_id = " . (int)$campaignId)->fetchColumn();
        $bookingsPages   = max(1, (int)ceil($bookingsTotal / $bookingsPerPage));
        if ($bookingsPage > $bookingsPages) $bookingsPage = $bookingsPages;
        $bookingsOffset  = ($bookingsPage - 1) * $bookingsPerPage;

        $stmt5 = $pdo->prepare("
            SELECT b.id, b.status, b.created_at,
                u.full_name, u.student_personnel_id, u.phone_number,
                s.slot_date, s.start_time, s.end_time
            FROM camp_bookings b
            JOIN sys_users u ON b.student_id = u.id
            JOIN camp_slots s ON b.slot_id = s.id
            WHERE b.campaign_id = :id
            ORDER BY b.created_at DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt5->bindValue(':id',  $campaignId,      PDO::PARAM_INT);
        $stmt5->bindValue(':lim', $bookingsPerPage, PDO::PARAM_INT);
        $stmt5->bindValue(':off', $bookingsOffset,  PDO::PARAM_INT);
        $stmt5->execute();
        $recentBookings = $stmt5->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('co_pager_url')) {
    function co_pager_url(string $param, int $page): string {
        $q = $_GET; $q[$param] = $page; return '?' . http_build_query($q);
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
<div class="card p-4 sm:p-5 mb-6 fade-up">
    <div style="display:flex; flex-direction:column; gap:10px;">
        <label class="text-sm font-bold text-gray-600">เลือกแคมเปญ :</label>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <form method="get" style="flex:1; min-width:0;">
                <select name="id" onchange="this.form.submit()"
                    class="border border-gray-200 rounded-xl px-4 py-2.5 text-sm font-medium outline-none focus:ring-2 focus:ring-blue-400 bg-white"
                    style="width:100%; min-width:0;">
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
            <a href="campaigns.php" class="text-sm text-blue-600 hover:underline whitespace-nowrap flex-shrink-0">
                <i class="fa-solid fa-pen-to-square mr-1"></i>แก้ไขแคมเปญ
            </a>
            <button type="button"
                    onclick="showWalkinQrModalCo(<?= (int)$campaignId ?>, <?= (int)($campaign['walkin_enabled'] ?? 0) ?>)"
                    class="text-sm text-amber-700 hover:underline whitespace-nowrap flex-shrink-0 inline-flex items-center gap-1 cursor-pointer bg-transparent border-0 p-0">
                <i class="fa-solid fa-person-walking"></i>QR Walk-in
                <?php if ((int)($campaign['walkin_enabled'] ?? 0) === 1): ?>
                <span class="inline-block w-1.5 h-1.5 bg-amber-500 rounded-full ml-1" title="เปิดอยู่"></span>
                <?php endif; ?>
            </button>
            <a href="campaign_report.php?id=<?= (int)$campaignId ?>" target="_blank" class="text-sm text-emerald-600 hover:underline whitespace-nowrap flex-shrink-0">
                <i class="fa-solid fa-print mr-1"></i>พิมพ์รายงาน / PDF
            </a>
            <?php endif; ?>
        </div>
    </div>
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
    'booked'             => '#f59e0b',
    'confirmed'          => '#14b8a6',
    'completed'          => '#2e9e63',
    'cancelled'          => '#ef4444',
    'cancelled_by_admin' => '#9ca3af',
    'expired'            => '#64748b',
];
$statusLabelsMap = [
    'booked'             => 'รออนุมัติ',
    'confirmed'          => 'รอเข้าร่วม',
    'completed'          => 'เข้าร่วมแล้ว',
    'cancelled'          => 'ยกเลิก (ผู้ใช้)',
    'cancelled_by_admin' => 'ยกเลิก (Admin)',
    'expired'            => 'ไม่มาตามนัด',
];
// แสดงเรียงตาม lifecycle (รออนุมัติ → รอเข้าร่วม → เข้าร่วม → ยกเลิก/expired)
$statusOrder = ['booked','confirmed','completed','expired','cancelled','cancelled_by_admin'];
$donutLabels = [];
$donutData   = [];
$donutColors = [];
// เรียงตาม lifecycle ก่อน → ที่เหลือต่อท้าย
$orderedStatuses = array_merge(
    array_intersect($statusOrder, array_keys($statusBreakdown)),
    array_diff(array_keys($statusBreakdown), $statusOrder)
);
foreach ($orderedStatuses as $st) {
    $cnt = $statusBreakdown[$st];
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
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
    <?php
    $cards = [
        ['label' => 'โควต้าทั้งหมด', 'value' => number_format($stats['cap']),      'icon' => 'fa-users',           'color' => 'text-blue-600',   'bg' => 'bg-blue-50'],
        ['label' => 'เข้าร่วมแล้ว',  'value' => number_format($stats['attended']), 'icon' => 'fa-circle-check',    'color' => 'text-green-600',  'bg' => 'bg-green-50'],
        ['label' => 'รอเข้าร่วม',     'value' => number_format($stats['awaiting']), 'icon' => 'fa-user-clock',      'color' => 'text-teal-600',   'bg' => 'bg-teal-50'],
        ['label' => 'คงเหลือ',        'value' => number_format($stats['remaining']),'icon' => 'fa-circle-dot',      'color' => 'text-amber-600',  'bg' => 'bg-amber-50'],
        ['label' => 'เต็ม',           'value' => $stats['pct'] . '%',              'icon' => 'fa-chart-pie',       'color' => 'text-purple-600', 'bg' => 'bg-purple-50'],
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
        <?php if ($card['label'] === 'เต็ม'): ?>
        <div class="mt-3 h-2 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full bg-purple-400 rounded-full transition-all" style="width:<?= min(100,$stats['pct']) ?>%"></div>
        </div>
        <p class="mt-1.5 text-[10px] text-gray-400">จากผู้ครองโควต้า <?= number_format($stats['occupied']) ?> คน</p>
        <?php elseif ($card['label'] === 'คงเหลือ'): ?>
        <p class="mt-2 text-[11px] text-gray-400">
            <?= number_format($stats['cap']) ?> − <?= number_format($stats['attended']) ?> − <?= number_format($stats['awaiting']) ?>
        </p>
        <?php elseif ($card['label'] === 'รอเข้าร่วม' && $stats['awaiting'] > 0): ?>
        <p class="mt-2 text-[11px] text-gray-400">
            <i class="fa-regular fa-calendar mr-1"></i>รอวันงาน · ยังไม่มาเช็คอิน
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">

    <!-- Donut: สถานะ -->
    <div class="card p-5 fade-up" style="animation-delay:.1s">
        <div class="mb-4">
            <h3 class="font-bold text-gray-700 flex items-center gap-2">
                <i class="fa-solid fa-chart-donut text-blue-500"></i> สัดส่วนสถานะการจอง
            </h3>
            <p class="text-[11px] text-gray-400 mt-1">
                ประวัติทั้งหมดของแคมเปญ (รวมเข้าร่วมแล้ว · ยกเลิก · ไม่มาตามนัด) — ไม่เท่ากับ "จองแล้ว" ใน KPI ด้านบนที่นับเฉพาะคิวที่ยังอยู่ในระบบ
            </p>
        </div>
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
            <i class="fa-solid fa-chart-line text-purple-500"></i> การจองรายวัน (30 วันล่าสุดที่มีข้อมูล)
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
    <div class="mb-4">
        <h3 class="font-bold text-gray-700 flex items-center gap-2">
            <i class="fa-solid fa-chart-column text-teal-500"></i> การใช้งานรายรอบเวลา
        </h3>
        <p class="text-[11px] text-gray-400 mt-1">
            แท่งเขียว = คนที่ครองโควต้าจริง (รออนุมัติ + รอเข้าร่วม + เข้าร่วมแล้ว + ไม่มาตามนัด) · แท่งเทาอ่อน = โควต้ารวมของรอบ
        </p>
    </div>
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
        <h3 class="font-bold text-gray-700 flex items-center gap-2 flex-wrap">
            <i class="fa-solid fa-list text-gray-400"></i>
            รายชื่อผู้จอง
            <span class="ml-1 text-xs font-semibold bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full"><?= number_format($bookingsTotal) ?></span>
            <?php if ($bookingsPages > 1): ?>
            <span class="text-[11px] font-normal text-gray-400">หน้า <?= $bookingsPage ?>/<?= $bookingsPages ?></span>
            <?php endif; ?>
        </h3>
        <div class="flex gap-2">
            <input id="bookingSearch" type="text" placeholder="ค้นหาในหน้านี้..."
                title="ค้นหาเฉพาะหน้าปัจจุบัน · ต้องการค้นหาทุกหน้า ให้ใช้หน้า ผู้เข้าร่วม"
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

    <?php if ($bookingsPages > 1): ?>
    <!-- Pagination — 25/page · window ±2 per CLAUDE.md -->
    <div class="mt-4 pt-3 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-3">
        <p class="text-[11px] text-gray-400">
            แสดง <?= number_format($bookingsOffset + 1) ?>–<?= number_format(min($bookingsOffset + $bookingsPerPage, $bookingsTotal)) ?>
            จากทั้งหมด <?= number_format($bookingsTotal) ?> รายการ
        </p>
        <div class="flex items-center gap-1">
            <?php
            $win = 2;
            $showFirst = $bookingsPage > $win + 1;
            $showLast  = $bookingsPage < $bookingsPages - $win;
            ?>
            <a href="<?= co_pager_url('bp', 1) ?>" class="px-2 py-1 text-xs rounded-lg <?= $bookingsPage===1 ? 'pointer-events-none text-gray-300' : 'text-gray-500 hover:bg-gray-100' ?>" title="หน้าแรก">«</a>
            <a href="<?= co_pager_url('bp', max(1, $bookingsPage - 1)) ?>" class="px-2 py-1 text-xs rounded-lg <?= $bookingsPage===1 ? 'pointer-events-none text-gray-300' : 'text-gray-500 hover:bg-gray-100' ?>" title="ก่อนหน้า">‹</a>
            <?php if ($showFirst): ?>
                <a href="<?= co_pager_url('bp', 1) ?>" class="px-2.5 py-1 text-xs rounded-lg text-gray-500 hover:bg-gray-100">1</a>
                <span class="text-gray-300 text-xs">…</span>
            <?php endif; ?>
            <?php for ($p = max(1, $bookingsPage - $win); $p <= min($bookingsPages, $bookingsPage + $win); $p++): ?>
                <a href="<?= co_pager_url('bp', $p) ?>"
                   class="px-2.5 py-1 text-xs rounded-lg font-semibold <?= $p === $bookingsPage ? 'text-white' : 'text-gray-500 hover:bg-gray-100' ?>"
                   style="<?= $p === $bookingsPage ? 'background:#0052CC' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($showLast): ?>
                <span class="text-gray-300 text-xs">…</span>
                <a href="<?= co_pager_url('bp', $bookingsPages) ?>" class="px-2.5 py-1 text-xs rounded-lg text-gray-500 hover:bg-gray-100"><?= $bookingsPages ?></a>
            <?php endif; ?>
            <a href="<?= co_pager_url('bp', min($bookingsPages, $bookingsPage + 1)) ?>" class="px-2 py-1 text-xs rounded-lg <?= $bookingsPage===$bookingsPages ? 'pointer-events-none text-gray-300' : 'text-gray-500 hover:bg-gray-100' ?>" title="ถัดไป">›</a>
            <a href="<?= co_pager_url('bp', $bookingsPages) ?>" class="px-2 py-1 text-xs rounded-lg <?= $bookingsPage===$bookingsPages ? 'pointer-events-none text-gray-300' : 'text-gray-500 hover:bg-gray-100' ?>" title="หน้าสุดท้าย">»</a>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = "'Sarabun', sans-serif";
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

<!-- ══ Walk-in QR Modal (mirrored from campaigns.php for reuse) ══════════════ -->
<div id="walkinQrOverlayCo"
     class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center p-4"
     style="display:none;z-index:9000">
  <div class="bg-white rounded-3xl shadow-2xl w-full max-w-sm overflow-hidden">

    <div class="flex items-center justify-between px-5 py-4"
         style="background:linear-gradient(135deg,#d97706,#f59e0b)">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 bg-white/20 rounded-xl flex items-center justify-center">
          <i class="fa-solid fa-person-walking text-white"></i>
        </div>
        <div>
          <p class="text-white font-black text-sm">QR Walk-in</p>
          <p class="text-white/80 text-[11px]" id="walkinQrTitleCo">—</p>
        </div>
      </div>
      <button onclick="closeWalkinQrModalCo()"
              class="w-8 h-8 bg-white/20 hover:bg-white/30 rounded-xl flex items-center justify-center transition-all">
        <i class="fa-solid fa-times text-white text-sm"></i>
      </button>
    </div>

    <div class="flex flex-col items-center px-6 pt-6 pb-4">
      <div class="w-52 h-52 bg-gray-50 rounded-2xl border-2 border-dashed border-amber-200 flex items-center justify-center overflow-hidden mb-4" id="walkinQrImgWrapCo">
        <i class="fa-solid fa-spinner fa-spin text-3xl text-gray-300"></i>
      </div>

      <button id="walkinToggleBtnCo" onclick="toggleWalkinQrCo()"
              class="w-full py-2.5 rounded-xl font-black text-sm mb-3 transition-all flex items-center justify-center gap-2">
        <i class="fa-solid fa-toggle-on"></i> <span>Walk-in เปิดอยู่</span>
      </button>

      <p class="text-[11px] text-gray-500 text-center mb-3 leading-relaxed">
        ผู้ป่วยสแกน → Login LINE → ยืนยัน<br>
        <span class="text-amber-600 font-bold">ระบบเช็คอินทันที</span> ไม่ต้องจองล่วงหน้า
      </p>

      <div class="w-full flex gap-2 mb-3">
        <input id="walkinCopyInputCo" type="text" readonly
               class="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-500 font-mono overflow-hidden"
               placeholder="กำลังโหลด URL...">
        <button onclick="copyWalkinUrlCo()"
                class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-xl transition-all" title="คัดลอก">
          <i class="fa-solid fa-copy text-gray-500 text-sm" id="walkinCopyIconCo"></i>
        </button>
      </div>

      <div class="w-full grid grid-cols-2 gap-2 mb-2">
        <button onclick="downloadWalkinQrCo()"
                class="py-2.5 bg-gray-50 hover:bg-gray-100 rounded-xl text-xs font-bold text-gray-600 transition-all flex items-center justify-center gap-1.5 border border-gray-200">
          <i class="fa-solid fa-download"></i> PNG
        </button>
        <button onclick="openWalkinPosterCo()"
                class="py-2.5 rounded-xl text-xs font-bold text-white transition-all flex items-center justify-center gap-1.5"
                style="background:linear-gradient(135deg,#d97706,#f59e0b)">
          <i class="fa-solid fa-print"></i> โปสเตอร์ A4
        </button>
      </div>
    </div>

  </div>
</div>

<script>
// _Co suffix on identifiers to avoid clashing if campaigns.php JS is also loaded
const CSRF_WALKIN_QR_CO = '<?= get_csrf_token() ?>';
let _walkinCurrentIdCo = 0;
let _walkinEnabledCo   = 0;

function showWalkinQrModalCo(campaignId, enabled) {
    _walkinCurrentIdCo = campaignId;
    _walkinEnabledCo   = enabled;

    const wrap = document.getElementById('walkinQrImgWrapCo');
    wrap.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-3xl text-gray-300"></i>';
    document.getElementById('walkinCopyInputCo').value = 'กำลังโหลด...';
    document.getElementById('walkinQrTitleCo').textContent = <?= json_encode($campaign['title'] ?? '') ?> || `Campaign #${campaignId}`;

    const img = new Image();
    img.src = `../user/api_walkin_qr.php?campaign=${campaignId}&t=${Date.now()}`;
    img.className = 'w-full h-full object-contain p-2';
    img.onload  = () => { wrap.innerHTML = ''; wrap.appendChild(img); };
    img.onerror = () => { wrap.innerHTML = '<p class="text-xs text-red-400">โหลด QR ไม่ได้</p>'; };

    fetch(`ajax/ajax_get_walkin_url.php?campaign=${campaignId}`)
        .then(r => r.json())
        .then(d => { document.getElementById('walkinCopyInputCo').value = d.url || ''; })
        .catch(() => { document.getElementById('walkinCopyInputCo').value = ''; });

    setWalkinToggleUICo(_walkinEnabledCo);

    const overlay = document.getElementById('walkinQrOverlayCo');
    if (overlay.parentElement !== document.body) document.body.appendChild(overlay);
    overlay.style.display = 'flex';
}

function closeWalkinQrModalCo() {
    document.getElementById('walkinQrOverlayCo').style.display = 'none';
}

document.getElementById('walkinQrOverlayCo').addEventListener('click', function(e) {
    if (e.target === this) closeWalkinQrModalCo();
});

function setWalkinToggleUICo(enabled) {
    const btn  = document.getElementById('walkinToggleBtnCo');
    const icon = btn.querySelector('i');
    const txt  = btn.querySelector('span');
    if (enabled) {
        btn.style.cssText = 'background:#fef3c7;color:#b45309;border:1.5px solid #fcd34d';
        icon.className = 'fa-solid fa-toggle-on';
        txt.textContent  = 'Walk-in เปิดอยู่ — กดเพื่อปิด';
    } else {
        btn.style.cssText = 'background:#f3f4f6;color:#6b7280;border:1.5px solid #e5e7eb';
        icon.className = 'fa-solid fa-toggle-off';
        txt.textContent  = 'Walk-in ปิดอยู่ — กดเพื่อเปิด';
    }
}

function toggleWalkinQrCo() {
    const btn = document.getElementById('walkinToggleBtnCo');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('campaign_id', _walkinCurrentIdCo);
    fd.append('csrf_token',  CSRF_WALKIN_QR_CO);
    fetch('ajax/ajax_toggle_walkin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                _walkinEnabledCo = d.walkin_enabled;
                setWalkinToggleUICo(_walkinEnabledCo);
            }
        })
        .catch(() => {})
        .finally(() => { btn.disabled = false; });
}

function copyWalkinUrlCo() {
    const input = document.getElementById('walkinCopyInputCo');
    if (!input.value) return;
    navigator.clipboard.writeText(input.value).catch(() => {
        input.select();
        document.execCommand('copy');
    });
    const icon = document.getElementById('walkinCopyIconCo');
    icon.className = 'fa-solid fa-check text-amber-600 text-sm';
    setTimeout(() => { icon.className = 'fa-solid fa-copy text-gray-500 text-sm'; }, 1500);
}

function downloadWalkinQrCo() {
    if (!_walkinCurrentIdCo) return;
    const a = document.createElement('a');
    a.href = `../user/api_walkin_qr.php?campaign=${_walkinCurrentIdCo}&size=14`;
    a.download = `walkin-qr-${_walkinCurrentIdCo}.png`;
    document.body.appendChild(a);
    a.click();
    a.remove();
}

function openWalkinPosterCo() {
    if (!_walkinCurrentIdCo) return;
    window.open(`walkin_poster.php?cid=${_walkinCurrentIdCo}`, '_blank');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
