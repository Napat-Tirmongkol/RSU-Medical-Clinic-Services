<?php
/**
 * admin/bookings.php (v4.0)
 * Booking Command Center — server-side search, date range, campaign filter, export, pagination
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

// Ensure is_walk_in column exists (idempotent — same logic in ajax_add_walkin.php
// and user/ajax_walkin_submit.php; needed here because bookings.php SELECTs the
// column directly without going through those entry points)
try { $pdo->exec("ALTER TABLE camp_bookings ADD COLUMN IF NOT EXISTS is_walk_in TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException) {}
try { $pdo->exec("ALTER TABLE camp_bookings ADD INDEX idx_walk_in (is_walk_in)"); } catch (PDOException) {}

// ── Date range (default: 3 months back → 3 months forward) ───────────────────
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-3 months'));
$dateTo   = $_GET['date_to']   ?? date('Y-m-d', strtotime('+3 months'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d', strtotime('-3 months'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d', strtotime('+3 months'));

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
            SUM(b.status IN ('cancelled','cancelled_by_admin','expired')) AS total_cancelled
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

// Active campaigns + their open slots (for walk-in modal)
try {
    $walkinCampaigns = $pdo->query("
        SELECT id, title FROM camp_list
        WHERE status IN ('active','closed','full')
        ORDER BY (status='active') DESC, title ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {
    $walkinCampaigns = [];
}

// ── Initial data (first page, no search term) ────────────────────────────────
$dataWhere  = "s.slot_date BETWEEN :start AND :end AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin','expired')";
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
            COALESCE(b.is_walk_in, 0) AS is_walk_in,
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
                <h5 class="text-[10px] text-amber-600 font-black tracking-wider">รออนุมัติ</h5>
                <p id="kpiPending" class="text-2xl font-black text-gray-900">' . number_format($total_pending) . '</p>
            </div>
        </div>
        <div class="bg-white border border-gray-100 p-4 px-6 rounded-[24px] shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center text-xl shadow-inner"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <h5 class="text-[10px] text-emerald-600 font-black tracking-wider">รอเข้าร่วม</h5>
                <p id="kpiConfirmed" class="text-2xl font-black text-gray-900">' . number_format($total_confirmed) . '</p>
            </div>
        </div>
        <div class="bg-white border border-gray-100 p-4 px-6 rounded-[24px] shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 bg-teal-50 text-teal-500 rounded-full flex items-center justify-center text-xl shadow-inner"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <h5 class="text-[10px] text-teal-600 font-black tracking-wider">เข้าร่วมแล้ว</h5>
                <p id="kpiCompleted" class="text-2xl font-black text-gray-900">' . number_format($total_completed) . '</p>
            </div>
        </div>';
    renderPageHeader("ศูนย์จัดการคิวการจอง", "ดู · อนุมัติ · เช็คอินผู้เข้าร่วมทุกแคมเปญในที่เดียว", $header_actions);
    ?>

    <!-- FILTER BAR: Date range + Campaign + Export -->
    <section class="bg-white border border-gray-100 p-5 rounded-[32px] shadow-sm flex flex-wrap items-end gap-4">
        <div class="flex items-end gap-2">
            <div>
                <label class="block text-[10px] font-black tracking-wider text-gray-400 mb-1.5 pl-1">จากวันที่</label>
                <input type="date" id="filterDateFrom" value="<?= htmlspecialchars($dateFrom) ?>"
                    class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-sm font-medium outline-none focus:ring-4 focus:ring-blue-50 focus:bg-white transition-all">
            </div>
            <span class="pb-2.5 text-gray-400 font-bold">—</span>
            <div>
                <label class="block text-[10px] font-black tracking-wider text-gray-400 mb-1.5 pl-1">ถึงวันที่</label>
                <input type="date" id="filterDateTo" value="<?= htmlspecialchars($dateTo) ?>"
                    class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-2xl text-sm font-medium outline-none focus:ring-4 focus:ring-blue-50 focus:bg-white transition-all">
            </div>
        </div>

        <div>
            <label class="block text-[10px] font-black tracking-wider text-gray-400 mb-1.5 pl-1">แคมเปญ</label>
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

        <button onclick="openWalkinModal()"
            class="px-5 py-2.5 bg-amber-500 text-white rounded-2xl text-xs font-black tracking-wider hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-sm shadow-amber-200 whitespace-nowrap self-end">
            <i class="fa-solid fa-person-walking"></i> เพิ่ม Walk-in
        </button>

        <button onclick="exportCsv()"
            class="px-5 py-2.5 bg-emerald-600 text-white rounded-2xl text-xs font-black tracking-wider hover:brightness-110 active:scale-95 transition-all flex items-center gap-2 shadow-sm shadow-emerald-200 whitespace-nowrap self-end">
            <i class="fa-solid fa-file-csv"></i> ดาวน์โหลด CSV
        </button>
    </section>

    <!-- TAB + SEARCH BAR -->
    <section class="bg-white border border-gray-100 p-5 rounded-[32px] shadow-sm flex flex-col lg:flex-row justify-between items-center gap-6">
        <div class="flex items-center gap-1.5 p-1.5 bg-gray-50 rounded-2xl overflow-x-auto no-scrollbar max-w-full">
            <button onclick="setStatusTab('all', this)"
                class="status-tab tab-active px-5 py-2.5 rounded-xl text-xs font-black tracking-wider transition-all">ทั้งหมด</button>
            <button onclick="setStatusTab('booked', this)"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black tracking-wider text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">
                รออนุมัติ <span id="badge-booked" class="px-2 py-0.5 bg-amber-100 text-amber-600 rounded-lg text-[10px]"><?= $total_pending ?></span>
            </button>
            <button onclick="setStatusTab('confirmed', this)"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black tracking-wider text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">
                รอเข้าร่วม <span id="badge-confirmed" class="px-2 py-0.5 bg-emerald-100 text-emerald-600 rounded-lg text-[10px]"><?= $total_confirmed ?></span>
            </button>
            <button onclick="setStatusTab('completed', this)"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black tracking-wider text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">
                เข้าร่วมแล้ว <span id="badge-completed" class="px-2 py-0.5 bg-teal-100 text-teal-600 rounded-lg text-[10px]"><?= $total_completed ?></span>
            </button>
            <button onclick="setStatusTab('cancelled', this)"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black tracking-wider text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">
                ยกเลิก <span id="badge-cancelled" class="px-2 py-0.5 bg-red-100 text-red-600 rounded-lg text-[10px]"><?= $total_cancelled ?></span>
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
                    <tr class="bg-gray-50 text-gray-400 text-[10px] font-black tracking-wider border-b border-gray-100">
                        <td class="px-3 py-3 w-10 text-center">
                            <input type="checkbox" onchange="toggleAllRows(this)" class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        </td>
                        <td class="px-3 py-3">วัน-เวลา</td>
                        <td class="px-3 py-3">ผู้ใช้/นักศึกษา</td>
                        <td class="px-3 py-3">ข้อมูลแคมเปญ</td>
                        <td class="px-3 py-3 text-center">สถานะ</td>
                        <td class="px-3 py-3 text-center">จัดการ</td>
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
        <h3 class="text-2xl font-black text-gray-900 tracking-tight">ข้อมูลการจอง</h3>
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
        <span class="text-[10px] font-black tracking-wider text-blue-600 opacity-60">การจัดการ</span>
        <span class="text-sm font-black text-gray-900"><span id="selectedCount">0</span> รายการที่เลือก</span>
    </div>
    <div class="flex items-center gap-3">
        <button onclick="bulkApprove()"
            class="bg-blue-600 text-white px-6 py-3 rounded-2xl text-xs font-black tracking-wider shadow-xl shadow-blue-200 hover:brightness-110 transition-all active:scale-95">อนุมัติทั้งหมด</button>
        <button onclick="bulkCheckin()"
            style="background-color:#0d9488;color:#fff;box-shadow:0 20px 25px -5px rgba(13,148,136,.3)"
            class="px-6 py-3 rounded-2xl text-xs font-black tracking-wider hover:brightness-110 transition-all active:scale-95">
            <i class="fa-solid fa-user-check mr-1"></i>เช็คอินทั้งหมด</button>
        <button onclick="bulkCancel()"
            class="bg-white border border-gray-100 text-red-500 px-6 py-3 rounded-2xl text-xs font-black tracking-wider hover:bg-red-50 transition-all active:scale-95">ยกเลิก</button>
    </div>
</div>

<!-- ════════════════════ WALK-IN MODAL ════════════════════ -->
<div id="walkinModal" class="hidden fixed inset-0 items-center justify-center" style="background:rgba(15,23,42,.55);backdrop-filter:blur(6px);z-index:9000">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-xl mx-4 overflow-hidden" style="max-height:92vh;display:flex;flex-direction:column">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
            <div class="w-10 h-10 bg-amber-50 rounded-xl flex items-center justify-center text-amber-600">
                <i class="fa-solid fa-person-walking"></i>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-black text-gray-800">เพิ่ม Walk-in</h3>
                <p class="text-[11px] text-gray-400">บันทึกผู้ที่ไม่ได้จองล่วงหน้าแต่มาเข้าร่วมกิจกรรม</p>
            </div>
            <button type="button" onclick="closeWalkinModal()" class="w-9 h-9 rounded-full hover:bg-gray-100 text-gray-400"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="p-6 space-y-4 overflow-y-auto" style="min-height:0">
            <!-- Step 1: campaign + slot -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] font-black tracking-wider text-gray-400 block mb-1.5">แคมเปญ *</label>
                    <select id="walkin_campaign" onchange="walkinLoadSlots()" class="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-amber-400 focus:bg-white">
                        <option value="">— เลือก —</option>
                        <?php foreach ($walkinCampaigns as $wc): ?>
                            <option value="<?= (int)$wc['id'] ?>"><?= htmlspecialchars($wc['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] font-black tracking-wider text-gray-400 block mb-1.5">รอบเวลา *</label>
                    <select id="walkin_slot" disabled class="w-full px-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-amber-400 focus:bg-white">
                        <option value="">— เลือกแคมเปญก่อน —</option>
                    </select>
                </div>
            </div>

            <!-- Step 2: user (search or create) -->
            <div>
                <label class="text-[10px] font-black tracking-wider text-gray-400 block mb-1.5">ผู้เข้าร่วม *</label>
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    <input type="text" id="walkin_search" placeholder="ค้นหารหัส / ชื่อ / เบอร์ — หรือพิมพ์เพื่อสร้างใหม่"
                        oninput="walkinSearch(this.value)"
                        class="w-full pl-9 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-bold outline-none focus:border-amber-400 focus:bg-white">
                </div>
                <div id="walkin_results" class="mt-1 bg-white border border-gray-100 rounded-xl shadow-sm max-h-48 overflow-y-auto hidden"></div>

                <!-- Selected user pill -->
                <div id="walkin_selected" class="mt-2 hidden p-3 bg-amber-50 border border-amber-200 rounded-xl flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center"><i class="fa-solid fa-user"></i></div>
                    <div class="flex-1 min-w-0">
                        <p id="walkin_sel_name" class="font-black text-gray-800 text-sm truncate"></p>
                        <p id="walkin_sel_meta" class="text-[11px] text-gray-500"></p>
                    </div>
                    <button type="button" onclick="walkinClearUser()" class="text-gray-400 hover:text-rose-500"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <!-- Inline create form -->
                <div id="walkin_create" class="mt-3 hidden p-3 bg-blue-50 border border-blue-200 rounded-xl space-y-2">
                    <p class="text-[11px] font-bold text-blue-700">ไม่พบในระบบ — สร้าง user ใหม่</p>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" id="walkin_new_sid" placeholder="รหัสนักศึกษา/บุคลากร *" class="px-2.5 py-2 bg-white border border-blue-100 rounded-lg text-xs font-bold outline-none focus:border-blue-400">
                        <input type="text" id="walkin_new_name" placeholder="ชื่อ-สกุล *" class="px-2.5 py-2 bg-white border border-blue-100 rounded-lg text-xs font-bold outline-none focus:border-blue-400">
                    </div>
                    <input type="tel" id="walkin_new_phone" placeholder="เบอร์โทร (ไม่บังคับ)" class="w-full px-2.5 py-2 bg-white border border-blue-100 rounded-lg text-xs font-bold outline-none focus:border-blue-400">
                </div>
            </div>
        </div>

        <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2">
            <button type="button" onclick="closeWalkinModal()" class="px-4 py-2 bg-white border border-gray-200 text-gray-600 rounded-xl text-sm font-bold hover:bg-gray-50">ยกเลิก</button>
            <button type="button" id="walkin_submit" onclick="submitWalkin(false)" disabled class="px-5 py-2 bg-amber-500 text-white rounded-xl text-sm font-black hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-2">
                <i class="fa-solid fa-check"></i> บันทึก Walk-in
            </button>
        </div>
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
        if (!data.success) {
            document.getElementById('bookingTbody').innerHTML =
                '<tr><td colspan="6" class="p-12 text-center text-red-400 text-sm"><i class="fa-solid fa-triangle-exclamation mr-2"></i>' +
                (data.message || 'เกิดข้อผิดพลาดในการดึงข้อมูล') + '</td></tr>';
            return;
        }
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
            <span class="px-3 py-1 bg-blue-100 text-blue-700 text-[10px] font-black rounded-lg tracking-wider">ข้อมูลผู้ใช้</span>
            <h4 class="text-4xl font-[900] text-gray-900 tracking-tight leading-tight">${data.full_name}</h4>
            <p class="text-gray-400 font-bold tracking-wider text-sm underline decoration-blue-500 decoration-2">รหัส: ${data.student_personnel_id}</p>
        </div>
        <div class="grid grid-cols-2 gap-8">
            <div>
                <label class="text-[10px] font-black tracking-wider text-gray-400 block mb-2">เบอร์โทรศัพท์</label>
                <p class="text-xl font-black text-gray-800">${data.phone_number}</p>
            </div>
            <div>
                <label class="text-[10px] font-black tracking-wider text-gray-400 block mb-2">รหัสแคมเปญ</label>
                <p class="text-xl font-black text-primary">#${data.campaign_id}</p>
            </div>
        </div>
        <div class="space-y-6">
            <div class="p-8 bg-gray-50 rounded-[32px] border border-gray-100">
                <label class="text-[10px] font-black tracking-wider text-gray-400 block mb-4">ตารางที่จอง</label>
                <div class="flex items-center gap-5">
                    <div class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center text-2xl text-primary shadow-sm"><i class="fa-regular fa-calendar-check"></i></div>
                    <div>
                        <p class="text-lg font-black text-gray-900 leading-none">${data.slot_date}</p>
                        <p class="text-xs font-bold text-blue-600 mt-2">${data.start_time} - ${data.end_time}</p>
                    </div>
                </div>
            </div>
            <div class="p-8 bg-gray-50 rounded-[32px] border border-gray-100">
                <label class="text-[10px] font-black tracking-wider text-gray-400 block mb-4">แคมเปญที่เลือก</label>
                <p class="text-base font-bold text-gray-800 leading-relaxed">${data.campaign_title}</p>
            </div>
        </div>`;

    document.getElementById('drawerFooter').innerHTML =
        data.status === 'booked' ? `
            <button onclick="approveOne(${data.booking_id})" class="flex-1 bg-blue-600 text-white py-5 rounded-2xl font-black tracking-wider text-xs shadow-xl shadow-blue-200">อนุมัติการจอง</button>
            <button onclick="rejectOne(${data.booking_id})" class="flex-1 bg-white text-red-500 py-5 rounded-2xl font-black tracking-wider text-xs border border-gray-200">ปฏิเสธ</button>`
        : data.status === 'confirmed' ? `
            <button onclick="checkinOne(${data.booking_id})" class="flex-1 bg-[#0052CC] text-white py-5 rounded-2xl font-black tracking-wider text-xs shadow-xl shadow-blue-200"><i class="fa-solid fa-user-check mr-2"></i>รับเข้าร่วมงาน</button>
            <button onclick="rescheduleOne(${data.booking_id})" class="flex-1 bg-orange-500 text-white py-5 rounded-2xl font-black tracking-wider text-xs shadow-xl shadow-orange-200">เลื่อนคิว</button>`
        : data.status === 'completed' ? `
            <button onclick="cancelAttendanceOne(${data.booking_id})" class="flex-1 bg-rose-500 text-white py-5 rounded-2xl font-black tracking-wider text-xs shadow-xl shadow-rose-200"><i class="fa-solid fa-rotate-left mr-2"></i>ยกเลิกการเข้าร่วม</button>
            <button onclick="closeDrawer()" class="flex-none px-6 bg-white border border-gray-200 text-gray-500 py-5 rounded-2xl font-black tracking-wider text-xs">ปิด</button>`
        : `<button onclick="closeDrawer()" class="w-full bg-gray-900 text-white py-5 rounded-2xl font-black tracking-wider text-xs">ปิดหน้าโปรไฟล์</button>`;

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
    Swal.fire({ title:'ยืนยันการอนุมัติ?', text:'ระบบจะเปลี่ยนสถานะเป็น "รอเข้าร่วม"', icon:'question',
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
    }).then(r => { if (r.isConfirmed) { closeDrawer(); performApiCall('ajax/ajax_checkin_booking.php', id, 'เช็คอินสำเร็จ!', 'success', 'completed'); }});
}

function rescheduleOne(id) {
    Swal.fire({ title:'ยืนยันการเลื่อนคิว?', icon:'warning', showCancelButton:true,
        confirmButtonColor:'#f97316', confirmButtonText:'ยืนยันแจ้งเลื่อน', cancelButtonText:'ยกเลิก',
        customClass:{title:'font-prompt',confirmButton:'font-prompt',cancelButton:'font-prompt'}
    }).then(r => r.isConfirmed && performApiCall('ajax/ajax_force_cancel.php', id, 'แจ้งเลื่อนคิวสำเร็จ!', 'success', 'cancelled_by_admin'));
}

function cancelAttendanceOne(id) {
    Swal.fire({
        title: 'ยกเลิกการเข้าร่วม?',
        text: 'ผู้ใช้จะกลับไปอยู่ในคิวเดิมที่เคยจอง',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        confirmButtonText: 'ยืนยันยกเลิก',
        cancelButtonText: 'ไม่ยกเลิก',
        customClass: { title:'font-prompt', confirmButton:'font-prompt', cancelButton:'font-prompt' }
    }).then(r => {
        if (!r.isConfirmed) return;
        closeDrawer();
        fetch('ajax/ajax_cancel_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `booking_id=${id}&csrf_token=${CSRF}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                updateRowStatus(id, data.new_status);
                doSearch(true);
                Swal.fire({ title:'ยกเลิกสำเร็จ', text:'ผู้ใช้กลับไปอยู่ในคิวเดิมแล้ว', icon:'success', timer:2000, showConfirmButton:false,
                    customClass:{title:'font-prompt',popup:'font-prompt rounded-2xl'} });
            } else {
                Swal.fire('เกิดข้อผิดพลาด', data.message || 'ไม่สามารถยกเลิกได้', 'error');
            }
        });
    });
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
    Swal.fire({ title:`เช็คอินทั้งหมด ${ids.length} รายการ?`, text:'เฉพาะรายการที่มีสถานะ "รอเข้าร่วม" เท่านั้น', icon:'question',
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
            confirmed: '<span class="px-4 py-1.5 bg-emerald-50 text-emerald-600 text-[10px] font-black rounded-full border border-emerald-100 tracking-wider">รอเข้าร่วม</span>',
            completed: '<span class="px-4 py-1.5 bg-teal-50 text-teal-600 text-[10px] font-black rounded-full border border-teal-100 tracking-wider">เข้าร่วมแล้ว</span>',
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

// ════════════════════ WALK-IN MODAL HANDLERS ════════════════════
let walkinSearchTimer = null;
let walkinSelectedUser = null;
let walkinTeleported   = false;

function walkinTeleport() {
    const m = document.getElementById('walkinModal');
    if (m && !walkinTeleported) { document.body.appendChild(m); walkinTeleported = true; }
    return m;
}
function openWalkinModal() {
    const m = walkinTeleport();
    // Reset state
    walkinSelectedUser = null;
    document.getElementById('walkin_campaign').value = '';
    document.getElementById('walkin_slot').innerHTML = '<option value="">— เลือกแคมเปญก่อน —</option>';
    document.getElementById('walkin_slot').disabled = true;
    document.getElementById('walkin_search').value = '';
    document.getElementById('walkin_results').classList.add('hidden');
    document.getElementById('walkin_selected').classList.add('hidden');
    document.getElementById('walkin_create').classList.add('hidden');
    document.getElementById('walkin_new_sid').value = '';
    document.getElementById('walkin_new_name').value = '';
    document.getElementById('walkin_new_phone').value = '';
    document.getElementById('walkin_submit').disabled = true;
    m.classList.remove('hidden'); m.classList.add('flex');
}
function closeWalkinModal() {
    const m = document.getElementById('walkinModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
async function walkinLoadSlots() {
    const cid = document.getElementById('walkin_campaign').value;
    const sel = document.getElementById('walkin_slot');
    sel.disabled = true; sel.innerHTML = '<option>กำลังโหลด...</option>';
    if (!cid) { sel.innerHTML = '<option value="">— เลือกแคมเปญก่อน —</option>'; return; }
    const fd = new FormData();
    fd.append('mode','slots'); fd.append('campaign_id', cid); fd.append('csrf_token', CSRF);
    const r = await fetch('ajax/ajax_add_walkin.php', {method:'POST', body:fd}).then(r=>r.json());
    sel.innerHTML = '<option value="">— เลือกรอบเวลา —</option>';
    let todayPicked = false;
    const today = new Date().toISOString().slice(0,10);
    (r.results || []).forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        const isFull = s.used >= s.max_capacity;
        opt.textContent = `${s.slot_date} ${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)} (${s.used}/${s.max_capacity}${isFull ? ' เต็ม' : ''})`;
        // Auto-select first slot of today
        if (!todayPicked && s.slot_date === today) { opt.selected = true; todayPicked = true; }
        sel.appendChild(opt);
    });
    sel.disabled = false;
    walkinUpdateSubmitState();
}
function walkinSearch(q) {
    clearTimeout(walkinSearchTimer);
    const box = document.getElementById('walkin_results');
    const createBox = document.getElementById('walkin_create');
    if (!q || q.length < 2) {
        box.classList.add('hidden');
        createBox.classList.add('hidden');
        return;
    }
    walkinSearchTimer = setTimeout(async () => {
        const fd = new FormData();
        fd.append('mode','search'); fd.append('q', q); fd.append('csrf_token', CSRF);
        const r = await fetch('ajax/ajax_add_walkin.php', {method:'POST', body:fd}).then(r=>r.json());
        const results = r.results || [];
        if (!results.length) {
            box.classList.add('hidden');
            // Pre-fill create form with the query if it looks like an ID
            document.getElementById('walkin_new_sid').value  = /^[0-9A-Za-z\-]{4,}$/.test(q) ? q : '';
            document.getElementById('walkin_new_name').value = /^[0-9A-Za-z\-]{4,}$/.test(q) ? '' : q;
            createBox.classList.remove('hidden');
            walkinUpdateSubmitState();
            return;
        }
        createBox.classList.add('hidden');
        box.innerHTML = results.map(u =>
            `<div class="px-3 py-2 hover:bg-amber-50 cursor-pointer border-b border-gray-50 last:border-0"
                  onclick='walkinPickUser(${JSON.stringify(u).replace(/'/g,"&apos;")})'>
                <div class="font-bold text-sm text-gray-800">${escapeHtml(u.full_name || '—')}</div>
                <div class="text-[11px] text-gray-500">${escapeHtml(u.student_personnel_id || '—')} · ${escapeHtml(u.phone_number || '—')}</div>
            </div>`
        ).join('');
        box.classList.remove('hidden');
    }, 300);
}
function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function walkinPickUser(u) {
    walkinSelectedUser = u;
    document.getElementById('walkin_results').classList.add('hidden');
    document.getElementById('walkin_create').classList.add('hidden');
    document.getElementById('walkin_search').value = '';
    document.getElementById('walkin_sel_name').textContent = u.full_name || '—';
    document.getElementById('walkin_sel_meta').textContent = `${u.student_personnel_id || '—'} · ${u.phone_number || '—'}`;
    document.getElementById('walkin_selected').classList.remove('hidden');
    walkinUpdateSubmitState();
}
function walkinClearUser() {
    walkinSelectedUser = null;
    document.getElementById('walkin_selected').classList.add('hidden');
    document.getElementById('walkin_search').value = '';
    walkinUpdateSubmitState();
}
function walkinUpdateSubmitState() {
    const cid = document.getElementById('walkin_campaign').value;
    const sid = document.getElementById('walkin_slot').value;
    const hasPicked = !!walkinSelectedUser;
    const hasCreate = !document.getElementById('walkin_create').classList.contains('hidden')
                      && document.getElementById('walkin_new_sid').value.trim()
                      && document.getElementById('walkin_new_name').value.trim();
    document.getElementById('walkin_submit').disabled = !(cid && sid && (hasPicked || hasCreate));
}
// Wire create-form inputs to refresh submit state
['walkin_new_sid','walkin_new_name','walkin_campaign','walkin_slot'].forEach(id =>
    document.addEventListener('input', e => { if (e.target.id === id) walkinUpdateSubmitState(); })
);

async function submitWalkin(forceOver) {
    const fd = new FormData();
    fd.append('mode','create');
    fd.append('campaign_id', document.getElementById('walkin_campaign').value);
    fd.append('slot_id', document.getElementById('walkin_slot').value);
    fd.append('csrf_token', CSRF);
    if (forceOver) fd.append('force_over_capacity', '1');
    if (walkinSelectedUser) {
        fd.append('user_id', walkinSelectedUser.id);
    } else {
        fd.append('user_id', '0');
        fd.append('student_personnel_id', document.getElementById('walkin_new_sid').value.trim());
        fd.append('full_name',             document.getElementById('walkin_new_name').value.trim());
        fd.append('phone_number',          document.getElementById('walkin_new_phone').value.trim());
    }
    const r = await fetch('ajax/ajax_add_walkin.php', {method:'POST', body:fd}).then(r=>r.json());
    if (r.status === 'over_capacity') {
        const c = await Swal.fire({
            icon:'warning',
            title:'Slot เต็มแล้ว',
            text: r.message,
            showCancelButton:true,
            confirmButtonText:'เพิ่มอยู่ดี',
            cancelButtonText:'ยกเลิก',
            confirmButtonColor:'#f59e0b',
        });
        if (c.isConfirmed) return submitWalkin(true);
        return;
    }
    if (r.status !== 'success') {
        Swal.fire({icon:'error', title:'ไม่สามารถเพิ่มได้', text: r.message || 'unknown'});
        return;
    }
    closeWalkinModal();
    Swal.fire({icon:'success', title:'เพิ่ม Walk-in เรียบร้อย', timer:1500, showConfirmButton:false});
    setTimeout(() => location.reload(), 700);
}
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.getElementById('walkinModal')?.classList.add('hidden');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
