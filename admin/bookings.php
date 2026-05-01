<?php
/**
 * admin/bookings.php (v4.0)
 * Booking Command Center — server-side search, date range, campaign filter, export, pagination
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// ── Date range (default: current month) ──────────────────────────────────────
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-t');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-t');

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;

// ── KPI (scoped to selected date range) ──────────────────────────────────────
try {
    $kpiWhere  = 's.slot_date BETWEEN :start AND :end';
    $kpiParams = [':start' => $dateFrom, ':end' => $dateTo];
    if ($campaignId > 0) {
        $kpiWhere .= ' AND b.campaign_id = :cid';
        $kpiParams[':cid'] = $campaignId;
    }
    $kpi = $pdo->prepare("
        SELECT
            SUM(b.status = 'booked')                               AS total_pending,
            SUM(b.status = 'confirmed')                            AS total_confirmed,
            SUM(b.status = 'completed')                            AS total_completed,
            SUM(b.status IN ('cancelled','cancelled_by_admin'))    AS total_cancelled
        FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE $kpiWhere
    ");
    $kpi->execute($kpiParams);
    $kpi = $kpi->fetch(PDO::FETCH_ASSOC);
    $total_pending   = (int)($kpi['total_pending']   ?? 0);
    $total_confirmed = (int)($kpi['total_confirmed'] ?? 0);
    $total_completed = (int)($kpi['total_completed'] ?? 0);
    $total_cancelled = (int)($kpi['total_cancelled'] ?? 0);
} catch (PDOException $e) {
    $total_pending = $total_confirmed = $total_completed = $total_cancelled = 0;
}

// ── Campaign list for dropdown ────────────────────────────────────────────────
try {
    $campaigns = $pdo->query("
        SELECT DISTINCT c.id, c.title
        FROM camp_list c
        JOIN camp_bookings b ON b.campaign_id = c.id
        ORDER BY c.title
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
}

// ── Initial data (first page, no search term) ────────────────────────────────
$dataWhere  = "s.slot_date BETWEEN :start AND :end AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin')";
$dataParams = [':start' => $dateFrom, ':end' => $dateTo];
if ($campaignId > 0) {
    $dataWhere .= ' AND b.campaign_id = :cid';
    $dataParams[':cid'] = $campaignId;
}

try {
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE $dataWhere
    ");
    $cntStmt->execute($dataParams);
    $totalBookings = (int)$cntStmt->fetchColumn();
    $totalPages    = max(1, (int)ceil($totalBookings / $perPage));

    $stmt = $pdo->prepare("
        SELECT
            b.id AS booking_id, b.status, b.created_at, b.campaign_id,
            u.full_name, u.student_personnel_id, u.phone_number,
            s.slot_date, s.start_time, s.end_time,
            c.title AS campaign_title
        FROM camp_bookings b
        JOIN sys_users u  ON b.student_id  = u.id
        JOIN camp_slots s ON b.slot_id     = s.id
        JOIN camp_list c  ON b.campaign_id = c.id
        WHERE $dataWhere
        ORDER BY CASE WHEN b.status = 'booked' THEN 0 ELSE 1 END, s.slot_date ASC, s.start_time ASC
        LIMIT :lim OFFSET :off
    ");
    foreach ($dataParams as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Data Error: ' . $e->getMessage());
}

require_once __DIR__ . '/includes/booking_row.php';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    @keyframes sideIn {
        from { transform: translateX(100%); opacity: 0; }
        to   { transform: translateX(0);    opacity: 1; }
    }
    @keyframes barSlideUp {
        from { transform: translate(-50%, 100%); opacity: 0; }
        to   { transform: translate(-50%, 0);    opacity: 1; }
    }
    .animate-sideIn { animation: sideIn 0.4s cubic-bezier(0.16,1,0.3,1) forwards; }
    .animate-bar    { animation: barSlideUp 0.4s cubic-bezier(0.16,1,0.3,1) forwards; }
    .drawer-overlay { background: rgba(15,23,42,0.4); backdrop-filter: blur(4px); transition: opacity 0.3s; }
    .tab-active     { border-color:#0052CC; color:#0052CC; background:#E7F0FF; padding:0.5rem 1.2rem; transform:scale(1.05); }
    .no-scrollbar::-webkit-scrollbar { display: none; }
</style>

<div class="max-w-[1600px] mx-auto space-y-8 pb-32">

    <!-- HEADER + KPI CARDS -->
    <?php
    $header_actions = '
        <div class="bg-white border border-gray-100 p-4 px-6 rounded-[24px] shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 bg-amber-50 text-amber-500 rounded-full flex items-center justify-center text-xl shadow-inner animate-pulse"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
                <h5 class="text-[10px] text-amber-600 font-black uppercase tracking-widest">Pending</h5>
                <p id="kpiPending" class="text-2xl font-black text-gray-900">' . number_format($total_pending) . '</p>
            </div>
        </div>
        <div class="bg-white border border-gray-100 p-4 px-6 rounded-[24px] shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center text-xl shadow-inner"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <h5 class="text-[10px] text-emerald-600 font-black uppercase tracking-widest">Confirmed</h5>
                <p id="kpiConfirmed" class="text-2xl font-black text-gray-900">' . number_format($total_confirmed) . '</p>
            </div>
        </div>
        <div class="bg-white border border-gray-100 p-4 px-6 rounded-[24px] shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 bg-teal-50 text-teal-500 rounded-full flex items-center justify-center text-xl shadow-inner"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <h5 class="text-[10px] text-teal-600 font-black uppercase tracking-widest">เข้าร่วมแล้ว</h5>
                <p id="kpiCompleted" class="text-2xl font-black text-gray-900">' . number_format($total_completed) . '</p>
            </div>
        </div>';
    renderPageHeader("Booking Management Center", "ศูนย์บริหารจัดการคิวการจองแบบองค์รวม", $header_actions);
    ?>

    <!-- FILTER BAR: Date range + Campaign + Export -->
    <section class="bg-white border border-gray-100 p-5 rounded-[32px] shadow-sm flex flex-wrap items-end gap-4">
        <div class="flex items-end gap-2">
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5 pl-1">จากวันที่</label>
                <input type="date" id="filterDateFrom" value="<?= htmlspecialchars($dateFrom) ?>"
                    class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-sm font-medium outline-none focus:ring-4 focus:ring-blue-50 focus:bg-white transition-all">
            </div>
            <span class="pb-2.5 text-gray-400 font-bold">—</span>
            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5 pl-1">ถึงวันที่</label>
                <input type="date" id="filterDateTo" value="<?= htmlspecialchars($dateTo) ?>"
                    class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-sm font-medium outline-none focus:ring-4 focus:ring-blue-50 focus:bg-white transition-all">
            </div>
        </div>

        <div>
            <label class="block text-[10px] font-black uppercase tracking-widest text-gray-400 mb-1.5 pl-1">กิจกรรม</label>
            <select id="filterCampaign"
                class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-sm font-medium outline-none focus:ring-4 focus:ring-blue-50 focus:bg-white transition-all min-w-[200px]">
                <option value="0">— ทุกกิจกรรม —</option>
                <?php foreach ($campaigns as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $campaignId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button onclick="exportCsv()"
            class="px-5 py-2.5 bg-emerald-600 text-white rounded-2xl text-xs font-black uppercase tracking-widest hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-sm shadow-emerald-200 whitespace-nowrap self-end">
            <i class="fa-solid fa-file-csv"></i> Export CSV
        </button>
    </section>

    <!-- TAB + SEARCH BAR -->
    <section class="bg-white border border-gray-100 p-5 rounded-[32px] shadow-sm flex flex-col lg:flex-row justify-between items-center gap-6">
        <div class="flex items-center gap-1.5 p-1.5 bg-gray-50 rounded-2xl overflow-x-auto no-scrollbar max-w-full">
            <button onclick="setStatusTab('all', this)"
                class="status-tab tab-active px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all">All</button>
            <button onclick="setStatusTab('booked', this)"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">
                Pending <span id="badge-booked" class="px-2 py-0.5 bg-amber-100 text-amber-600 rounded-lg text-[10px]"><?= $total_pending ?></span>
            </button>
            <button onclick="setStatusTab('confirmed', this)"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">
                Confirmed <span id="badge-confirmed" class="px-2 py-0.5 bg-emerald-100 text-emerald-600 rounded-lg text-[10px]"><?= $total_confirmed ?></span>
            </button>
            <button onclick="setStatusTab('completed', this)"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">
                เข้าร่วมแล้ว <span id="badge-completed" class="px-2 py-0.5 bg-teal-100 text-teal-600 rounded-lg text-[10px]"><?= $total_completed ?></span>
            </button>
            <button onclick="setStatusTab('cancelled', this)"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">
                Cancelled <span id="badge-cancelled" class="px-2 py-0.5 bg-red-100 text-red-600 rounded-lg text-[10px]"><?= $total_cancelled ?></span>
            </button>
        </div>

        <div class="flex-1 w-full lg:max-w-md relative">
            <i class="fa-solid fa-magnifying-glass absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
            <input type="text" id="globalSearch" placeholder="ค้นหาชื่อ, รหัส, หรือกิจกรรม (ค้นหาทั้ง DB)..."
                class="w-full pl-12 pr-6 py-4 bg-gray-50 border border-gray-100 rounded-2xl text-sm font-medium outline-none focus:ring-4 focus:ring-blue-50/50 focus:bg-white transition-all">
        </div>
    </section>

    <!-- DATA TABLE -->
    <div class="bg-white rounded-[40px] shadow-sm border border-gray-100 overflow-hidden relative">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="bg-gray-50 text-gray-400 text-[11px] font-black uppercase tracking-[0.15em] border-b border-gray-100">
                        <td class="p-6 w-12 text-center">
                            <input type="checkbox" onchange="toggleAllRows(this)" class="w-5 h-5 rounded-md border-gray-300 text-blue-600 focus:ring-blue-500">
                        </td>
                        <td class="p-6">Date & Time</td>
                        <td class="p-6">User/Student</td>
                        <td class="p-6">Campaign Info</td>
                        <td class="p-6 text-center">Status</td>
                        <td class="p-6 text-center">Quick Actions</td>
                    </tr>
                </thead>
                <tbody id="bookingTbody" class="divide-y divide-gray-50">
                    <?= render_booking_rows($bookings) ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PAGINATION -->
    <div id="paginationWrap"></div>

</div>

<!-- SIDE DRAWER -->
<div id="drawerOverlay" class="fixed inset-0 drawer-overlay hidden opacity-0" style="z-index:150" onclick="closeDrawer()"></div>
<aside id="sideDrawer" class="fixed top-0 right-0 h-screen w-full md:w-[480px] bg-white shadow-2xl translate-x-full hidden flex flex-col transition-all duration-300" style="z-index:200">
    <div class="p-8 border-b border-gray-100 flex justify-between items-center">
        <h3 class="text-2xl font-black text-gray-900 tracking-tight">Booking Info</h3>
        <button onclick="closeDrawer()" class="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center hover:bg-gray-100 transition-all">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
    <div id="drawerContent" class="flex-1 overflow-y-auto p-10 space-y-12 no-scrollbar"></div>
    <div id="drawerFooter" class="p-8 bg-gray-50 border-t border-gray-100 flex gap-3"></div>
</aside>

<!-- FLOATING ACTION BAR -->
<div id="actionBar" class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[50] glass-card px-8 py-4 rounded-[32px] shadow-2xl border-2 border-blue-600/10 hidden translate-y-full flex items-center gap-10">
    <div class="flex flex-col">
        <span class="text-[10px] font-black uppercase tracking-widest text-blue-600 opacity-60">Operations</span>
        <span class="text-sm font-black text-gray-900"><span id="selectedCount">0</span> รายการที่เลือก</span>
    </div>
    <div class="flex items-center gap-3">
        <button onclick="bulkApprove()"
            class="bg-blue-600 text-white px-6 py-3 rounded-2xl text-xs font-black uppercase tracking-widest shadow-xl shadow-blue-200 hover:brightness-110 transition-all active:scale-95">Approve All</button>
        <button onclick="bulkCheckin()"
            style="background-color:#0d9488;color:#fff;box-shadow:0 20px 25px -5px rgba(13,148,136,.3)"
            class="px-6 py-3 rounded-2xl text-xs font-black uppercase tracking-widest hover:brightness-110 transition-all active:scale-95">
            <i class="fa-solid fa-user-check mr-1"></i>Check-in All</button>
        <button onclick="bulkCancel()"
            class="bg-white border border-gray-100 text-red-500 px-6 py-3 rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-red-50 transition-all active:scale-95">Cancel</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const CSRF = '<?= get_csrf_token() ?>';

// ── Filter state ─────────────────────────────────────────────────────────────
const F = {
    date_from:   '<?= $dateFrom ?>',
    date_to:     '<?= $dateTo ?>',
    campaign_id: <?= $campaignId ?>,
    q:           '',
    status:      'all',
    page:        <?= $page ?>,
};

// ── Search (debounced) ────────────────────────────────────────────────────────
let searchTimer = null;
document.getElementById('globalSearch').addEventListener('input', e => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        F.q = e.target.value.trim();
        F.page = 1;
        doSearch();
    }, 400);
});

// ── Date range inputs ─────────────────────────────────────────────────────────
document.getElementById('filterDateFrom').addEventListener('change', e => {
    F.date_from = e.target.value;
    F.page = 1;
    doSearch(true);
});
document.getElementById('filterDateTo').addEventListener('change', e => {
    F.date_to = e.target.value;
    F.page = 1;
    doSearch(true);
});

// ── Campaign filter ───────────────────────────────────────────────────────────
document.getElementById('filterCampaign').addEventListener('change', e => {
    F.campaign_id = parseInt(e.target.value) || 0;
    F.page = 1;
    doSearch(true);
});

// ── Status tabs ───────────────────────────────────────────────────────────────
function setStatusTab(status, btn) {
    document.querySelectorAll('.status-tab').forEach(t => {
        t.classList.remove('tab-active');
        t.classList.add('text-gray-400');
    });
    btn.classList.add('tab-active');
    btn.classList.remove('text-gray-400');
    F.status = status;
    F.page   = 1;
    doSearch();
}

// ── Main search function ──────────────────────────────────────────────────────
function doSearch(updateKpi = false) {
    document.getElementById('bookingTbody').innerHTML =
        '<tr><td colspan="6" class="p-12 text-center text-gray-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด...</td></tr>';

    const body = new URLSearchParams({ ...F, csrf_token: CSRF });

    fetch('ajax/ajax_search_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        document.getElementById('bookingTbody').innerHTML =
            data.html || '<tr><td colspan="6" class="p-16 text-center text-gray-400 text-sm">ไม่พบรายการ</td></tr>';

        renderPagination(data.page, data.total_pages, data.total);

        if (data.kpi) {
            updateBadges(data.kpi);
            if (updateKpi) updateKpiCards(data.kpi);
        }

        // Deselect all after refresh
        document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
        updateActionBar();
    })
    .catch(() => {
        document.getElementById('bookingTbody').innerHTML =
            '<tr><td colspan="6" class="p-12 text-center text-red-400">เกิดข้อผิดพลาด กรุณาลองใหม่</td></tr>';
    });
}

// ── Pagination renderer ───────────────────────────────────────────────────────
function renderPagination(page, totalPages, total) {
    const wrap = document.getElementById('paginationWrap');
    if (totalPages <= 1 && total === 0) { wrap.innerHTML = ''; return; }

    const btn = (label, p, disabled, active) =>
        `<button onclick="goPage(${p})" ${disabled ? 'disabled' : ''} class="w-9 h-9 flex items-center justify-center rounded-xl text-xs font-bold transition-all ${active ? 'bg-blue-600 text-white shadow-sm' : disabled ? 'text-gray-300 cursor-not-allowed' : 'text-gray-600 hover:bg-gray-100'}">${label}</button>`;

    let pages = '';
    const start = Math.max(1, page - 2);
    const end   = Math.min(totalPages, page + 2);
    for (let p = start; p <= end; p++) pages += btn(p, p, false, p === page);

    wrap.innerHTML = `
        <div class="flex flex-col items-center gap-3 py-4">
            <p class="text-xs text-gray-400">หน้า ${page} / ${totalPages} · รวม ${total.toLocaleString()} รายการ</p>
            <div class="flex items-center gap-1">
                ${btn('«', 1,          page === 1,          false)}
                ${btn('‹', page - 1,  page === 1,          false)}
                ${pages}
                ${btn('›', page + 1,  page === totalPages, false)}
                ${btn('»', totalPages, page === totalPages, false)}
            </div>
        </div>`;
}

function goPage(p) {
    F.page = p;
    doSearch();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── KPI / badge updaters ──────────────────────────────────────────────────────
function updateBadges(kpi) {
    document.getElementById('badge-booked').textContent    = kpi.pending    ?? 0;
    document.getElementById('badge-confirmed').textContent = kpi.confirmed  ?? 0;
    document.getElementById('badge-completed').textContent = kpi.completed  ?? 0;
    document.getElementById('badge-cancelled').textContent = kpi.cancelled  ?? 0;
}
function updateKpiCards(kpi) {
    document.getElementById('kpiPending').textContent   = parseInt(kpi.pending   || 0).toLocaleString();
    document.getElementById('kpiConfirmed').textContent = parseInt(kpi.confirmed || 0).toLocaleString();
    document.getElementById('kpiCompleted').textContent = parseInt(kpi.completed || 0).toLocaleString();
}

// ── CSV export (POST form) ────────────────────────────────────────────────────
function exportCsv() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'ajax/ajax_export_bookings.php';
    form.style.display = 'none';
    const fields = { ...F, csrf_token: CSRF };
    for (const [k, v] of Object.entries(fields)) {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = k;
        inp.value = v;
        form.appendChild(inp);
    }
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// ── Drawer ────────────────────────────────────────────────────────────────────
function openDrawer(dataStr) {
    const data    = JSON.parse(dataStr);
    const drawer  = document.getElementById('sideDrawer');
    const overlay = document.getElementById('drawerOverlay');

    document.getElementById('drawerContent').innerHTML = `
        <div class="space-y-3">
            <span class="px-3 py-1 bg-blue-100 text-blue-700 text-[10px] font-black rounded-lg uppercase tracking-widest">Profile Detail</span>
            <h4 class="text-4xl font-[900] text-gray-900 tracking-tight leading-tight">${data.full_name}</h4>
            <p class="text-gray-400 font-bold uppercase tracking-widest text-sm underline decoration-blue-500 decoration-2">ID: ${data.student_personnel_id}</p>
        </div>
        <div class="grid grid-cols-2 gap-8">
            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-2">Primary Phone</label>
                <p class="text-xl font-black text-gray-800">${data.phone_number}</p>
            </div>
            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-2">Campaign Unit</label>
                <p class="text-xl font-black text-primary">#${data.campaign_id}</p>
            </div>
        </div>
        <div class="space-y-6">
            <div class="p-8 bg-gray-50 rounded-[32px] border border-gray-100">
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-4">Booked Schedule</label>
                <div class="flex items-center gap-5">
                    <div class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center text-2xl text-primary shadow-sm"><i class="fa-regular fa-calendar-check"></i></div>
                    <div>
                        <p class="text-lg font-black text-gray-900 leading-none">${data.slot_date}</p>
                        <p class="text-xs font-bold text-blue-600 mt-2">${data.start_time} - ${data.end_time}</p>
                    </div>
                </div>
            </div>
            <div class="p-8 bg-gray-50 rounded-[32px] border border-gray-100">
                <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-4">Selected Activity</label>
                <p class="text-base font-bold text-gray-800 leading-relaxed">${data.campaign_title}</p>
            </div>
        </div>`;

    document.getElementById('drawerFooter').innerHTML =
        data.status === 'booked' ? `
            <button onclick="approveOne(${data.booking_id})" class="flex-1 bg-blue-600 text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-xl shadow-blue-200">Approve Booking</button>
            <button onclick="rejectOne(${data.booking_id})" class="flex-1 bg-white text-red-500 py-5 rounded-2xl font-black uppercase tracking-widest text-xs border border-gray-200">Reject</button>`
        : data.status === 'confirmed' ? `
            <button onclick="checkinOne(${data.booking_id})" class="flex-1 bg-[#0052CC] text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-xl shadow-blue-200"><i class="fa-solid fa-user-check mr-2"></i>รับเข้าร่วมงาน</button>
            <button onclick="rescheduleOne(${data.booking_id})" class="flex-1 bg-orange-500 text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-xl shadow-orange-200">เลื่อนคิว</button>`
        : `<button onclick="closeDrawer()" class="w-full bg-gray-900 text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs">Close Profile</button>`;

    drawer.classList.remove('hidden');
    overlay.classList.remove('hidden');
    setTimeout(() => {
        drawer.classList.add('translate-x-0');
        drawer.classList.remove('translate-x-full');
        overlay.classList.add('opacity-100');
        overlay.classList.remove('opacity-0');
    }, 10);
}

function closeDrawer() {
    const drawer  = document.getElementById('sideDrawer');
    const overlay = document.getElementById('drawerOverlay');
    drawer.classList.add('translate-x-full');
    drawer.classList.remove('translate-x-0');
    overlay.classList.remove('opacity-100');
    overlay.classList.add('opacity-0');
    setTimeout(() => {
        drawer.classList.add('hidden');
        overlay.classList.add('hidden');
    }, 300);
}

// ── Bulk selection ────────────────────────────────────────────────────────────
function toggleAllRows(master) {
    document.querySelectorAll('.booking-row').forEach(tr => {
        if (tr.style.display !== 'none') {
            const cb = tr.querySelector('.row-checkbox');
            if (cb) cb.checked = master.checked;
        }
    });
    updateActionBar();
}

function updateActionBar() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    const bar     = document.getElementById('actionBar');
    if (checked.length > 0) {
        document.getElementById('selectedCount').innerText = checked.length;
        bar.classList.remove('hidden', 'translate-y-full');
        bar.classList.add('flex', 'animate-bar');
    } else {
        bar.classList.add('translate-y-full');
        setTimeout(() => { if (!document.querySelectorAll('.row-checkbox:checked').length) bar.classList.add('hidden'); }, 300);
    }
}

// ── API actions ───────────────────────────────────────────────────────────────
function approveOne(id) {
    Swal.fire({ title:'ยืนยันการอนุมัติ?', text:'ระบบจะเปลี่ยนสถานะเป็น Confirmed', icon:'question',
        showCancelButton:true, confirmButtonColor:'#0052CC', confirmButtonText:'ใช่, อนุมัติเลย', cancelButtonText:'ยกเลิก'
    }).then(r => r.isConfirmed && performApiCall('ajax/ajax_approve_booking.php', id, 'อนุมัติเรียบร้อย!', 'success', 'confirmed'));
}

function rejectOne(id) {
    Swal.fire({ title:'ปฏิเสธการจอง?', icon:'warning', showCancelButton:true,
        confirmButtonColor:'#EF4444', confirmButtonText:'ยืนยันปฏิเสธ', cancelButtonText:'ยกเลิก',
        customClass:{title:'font-prompt',confirmButton:'font-prompt',cancelButton:'font-prompt'}
    }).then(r => r.isConfirmed && performApiCall('ajax/ajax_force_cancel.php', id, 'ปฏิเสธสำเร็จ', 'success', 'cancelled_by_admin'));
}

function checkinOne(id) {
    Swal.fire({ title:'รับเข้าร่วมงาน?', text:'สถานะจะเปลี่ยนเป็น "เข้าร่วมแล้ว"', icon:'question',
        showCancelButton:true, confirmButtonColor:'#0d9488', confirmButtonText:'ยืนยัน', cancelButtonText:'ยกเลิก',
        customClass:{title:'font-prompt',confirmButton:'font-prompt',cancelButton:'font-prompt'}
    }).then(r => { if (r.isConfirmed) { closeDrawer(); performApiCall('ajax/ajax_checkin_booking.php', id, 'Check-in สำเร็จ!', 'success', 'completed'); }});
}

function rescheduleOne(id) {
    Swal.fire({ title:'ยืนยันการเลื่อนคิว?', icon:'warning', showCancelButton:true,
        confirmButtonColor:'#f97316', confirmButtonText:'ยืนยันแจ้งเลื่อน', cancelButtonText:'ยกเลิก',
        customClass:{title:'font-prompt',confirmButton:'font-prompt',cancelButton:'font-prompt'}
    }).then(r => r.isConfirmed && performApiCall('ajax/ajax_force_cancel.php', id, 'แจ้งเลื่อนคิวสำเร็จ!', 'success', 'cancelled_by_admin'));
}

function bulkApprove() {
    const ids = getSelectedIds();
    Swal.fire({ title:`อนุมัติทั้งหมด ${ids.length} รายการ?`, icon:'warning',
        showCancelButton:true, confirmButtonColor:'#0052CC', confirmButtonText:'ยืนยันอนุมัติทั้งหมด'
    }).then(result => {
        if (!result.isConfirmed) return;
        Swal.fire({ title:'กำลังดำเนินการ...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });
        Promise.all(ids.map(id => fetch('ajax/ajax_approve_booking.php', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'appointment_id='+id+'&csrf_token='+CSRF
        }).then(r => r.json())))
        .then(() => {
            Swal.fire({ title:'สำเร็จ!', text:`อนุมัติ ${ids.length} รายการแล้ว`, icon:'success', timer:1800, showConfirmButton:false });
            doSearch(true);
        });
    });
}

function bulkCheckin() {
    const ids = getSelectedIds();
    Swal.fire({ title:`Check-in ทั้งหมด ${ids.length} รายการ?`, text:'เฉพาะรายการที่มีสถานะ Confirmed เท่านั้น', icon:'question',
        showCancelButton:true, confirmButtonColor:'#0d9488', confirmButtonText:'ยืนยัน Check-in',
        customClass:{title:'font-prompt',confirmButton:'font-prompt',cancelButton:'font-prompt'}
    }).then(result => {
        if (!result.isConfirmed) return;
        Swal.fire({ title:'กำลังดำเนินการ...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });
        fetch('ajax/ajax_bulk_checkin.php', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'ids='+encodeURIComponent(JSON.stringify(ids))+'&csrf_token='+CSRF
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({ title:'Check-in สำเร็จ!', text:`รับเข้าร่วม ${data.affected} รายการ`, icon:'success', timer:1800, showConfirmButton:false });
                doSearch(true);
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        });
    });
}

function bulkCancel() {
    const ids = getSelectedIds();
    Swal.fire({ title:`ยกเลิกทั้งหมด ${ids.length} รายการ?`, icon:'error',
        showCancelButton:true, confirmButtonColor:'#EF4444', confirmButtonText:'ยืนยันยกเลิกทั้งหมด',
        customClass:{title:'font-prompt',confirmButton:'font-prompt',cancelButton:'font-prompt'}
    }).then(result => {
        if (!result.isConfirmed) return;
        Swal.fire({ title:'กำลังดำเนินการ...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });
        Promise.all(ids.map(id => fetch('ajax/ajax_force_cancel.php', {
            method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'appointment_id='+id+'&csrf_token='+CSRF
        }).then(r => r.json())))
        .then(() => {
            Swal.fire({ title:'ยกเลิกสำเร็จ!', icon:'success', timer:1800, showConfirmButton:false });
            doSearch(true);
        });
    });
}

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-checkbox:checked'))
        .map(cb => cb.closest('tr').dataset.id);
}

function performApiCall(url, id, successTitle, icon, newStatus) {
    Swal.fire({ title:'กำลังดำเนินการ...', allowOutsideClick:false, didOpen:() => Swal.showLoading() });
    fetch(url, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'appointment_id='+id+'&csrf_token='+CSRF })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            if (newStatus) updateRowStatus(id, newStatus);
            Swal.fire({ title:successTitle, icon, timer:1500, showConfirmButton:false,
                customClass:{title:'font-prompt',popup:'font-prompt rounded-2xl'} });
        } else {
            Swal.fire('Error', data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    });
}

function updateRowStatus(id, newStatus) {
    const tr = document.querySelector(`tr[data-id="${id}"]`);
    if (!tr) return;
    tr.dataset.status = newStatus;
    const statusTd  = tr.cells[4];
    const actionDiv = tr.querySelector('td:last-child div');
    if (statusTd) {
        const badges = {
            confirmed: '<span class="px-4 py-1.5 bg-emerald-50 text-emerald-600 text-[10px] font-black uppercase rounded-full border border-emerald-100 tracking-widest">Confirmed</span>',
            completed: '<span class="px-4 py-1.5 bg-teal-50 text-teal-600 text-[10px] font-black uppercase rounded-full border border-teal-100 tracking-widest">เข้าร่วมแล้ว</span>',
        };
        statusTd.innerHTML = badges[newStatus] || `<span class="px-4 py-1.5 bg-gray-50 text-gray-400 text-[10px] font-black uppercase rounded-full tracking-widest">${newStatus}</span>`;
    }
    if (actionDiv && newStatus === 'confirmed') {
        actionDiv.innerHTML = `
            <button onclick="checkinOne(${id})" class="px-3 py-1.5 bg-[#0052CC] text-white rounded-xl text-[11px] font-black flex items-center gap-1.5 hover:brightness-110 active:scale-95 transition-all shadow-md shadow-blue-200 whitespace-nowrap"><i class="fa-solid fa-user-check"></i> รับเข้าร่วม</button>
            <button onclick="rescheduleOne(${id})" class="w-9 h-9 bg-orange-50 text-orange-600 border border-orange-100 rounded-xl flex items-center justify-center hover:bg-orange-500 hover:text-white hover:scale-110 active:scale-95 transition-all shadow-sm" title="แจ้งเลื่อนคิว"><i class="fa-solid fa-clock-rotate-left"></i></button>`;
    } else if (actionDiv) {
        actionDiv.innerHTML = `<button onclick='openDrawer(this.closest("tr").dataset.details)' class="text-gray-400 hover:text-blue-600 text-lg transition-colors"><i class="fa-solid fa-circle-info"></i></button>`;
    }
}

// ── Init pagination on load ───────────────────────────────────────────────────
renderPagination(<?= $page ?>, <?= $totalPages ?>, <?= $totalBookings ?>);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
