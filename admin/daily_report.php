<?php
// admin/daily_report.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo   = db();
$today = date('Y-m-d');
$date  = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']))
         ? $_GET['date'] : $today;
$init_cid = (int)($_GET['campaign_id'] ?? 0);

// Campaign list for tabs
$campaigns = $pdo->query("
    SELECT DISTINCT cl.id, cl.title
    FROM camp_list cl
    JOIN camp_slots cs ON cs.campaign_id = cl.id
    ORDER BY cl.title ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── CSV Export ────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $cid  = (int)($_GET['campaign_id'] ?? 0);
    $type = in_array($_GET['type'] ?? '', ['all','on_schedule','early','no_show','cancelled'], true)
            ? $_GET['type'] : 'all';

    $type_labels = [
        'all'         => 'ทั้งหมด',
        'on_schedule' => 'มาตามนัด',
        'early'       => 'มาก่อนวันนัด',
        'no_show'     => 'ยังไม่มา',
        'cancelled'   => 'ยกเลิก',
    ];

    // Build WHERE
    $tw_conds = [
        'on_schedule' => ["s.slot_date = :tw1 AND DATE(b.attended_at) = :tw2 AND b.status NOT IN ('cancelled','cancelled_by_admin')", [':tw1'=>$date,':tw2'=>$date]],
        'early'       => ["DATE(b.attended_at) = :tw1 AND s.slot_date > :tw2", [':tw1'=>$date,':tw2'=>$date]],
        'no_show'     => ["s.slot_date = :tw1 AND b.attended_at IS NULL AND b.status NOT IN ('cancelled','cancelled_by_admin')", [':tw1'=>$date]],
        'cancelled'   => ["s.slot_date = :tw1 AND b.status IN ('cancelled','cancelled_by_admin')", [':tw1'=>$date]],
        'all'         => ["(s.slot_date = :tw1 OR (DATE(b.attended_at) = :tw2 AND s.slot_date > :tw3))", [':tw1'=>$date,':tw2'=>$date,':tw3'=>$date]],
    ];
    [$tw_sql, $tw_p] = $tw_conds[$type];
    $cc = $cid > 0 ? ' AND b.campaign_id = :cid' : '';
    $params = array_merge($tw_p, [':vt1'=>$date,':vt2'=>$date,':vt3'=>$date,':vt4'=>$date]);
    if ($cid > 0) $params[':cid'] = $cid;

    $sql = "
        SELECT
            u.student_personnel_id,
            u.full_name,
            u.phone_number,
            cl.title    AS campaign_title,
            s.slot_date,
            s.start_time,
            s.end_time,
            b.attended_at,
            b.status,
            CASE
                WHEN b.status IN ('cancelled','cancelled_by_admin')         THEN 'ยกเลิก'
                WHEN s.slot_date = :vt1 AND DATE(b.attended_at) = :vt2     THEN 'มาตามนัด'
                WHEN DATE(b.attended_at) = :vt3 AND s.slot_date > :vt4     THEN 'มาก่อนวันนัด'
                WHEN b.attended_at IS NULL                                  THEN 'ยังไม่มา'
                ELSE 'อื่นๆ'
            END AS visit_type_th
        FROM camp_bookings b
        JOIN sys_users u   ON b.student_id  = u.id
        JOIN camp_slots s  ON b.slot_id     = s.id
        JOIN camp_list  cl ON b.campaign_id = cl.id
        WHERE $tw_sql $cc
        ORDER BY
            CASE WHEN b.attended_at IS NOT NULL THEN 0 ELSE 1 END,
            b.attended_at DESC,
            s.slot_date ASC,
            s.start_time ASC
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'daily_report_' . $date . ($cid > 0 ? "_camp{$cid}" : '') . "_{$type}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['รหัสนักศึกษา/บุคลากร','ชื่อ-นามสกุล','เบอร์โทรศัพท์','แคมเปญ','วันนัด','เวลา','เวลาเช็คอิน','สถานะ']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['student_personnel_id'],
            $r['full_name'],
            $r['phone_number'],
            $r['campaign_title'],
            date('d/m/Y', strtotime($r['slot_date'])),
            substr($r['start_time'],0,5).' - '.substr($r['end_time'],0,5),
            $r['attended_at'] ? date('d/m/Y H:i', strtotime($r['attended_at'])) : '-',
            $r['visit_type_th'],
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.dr-card {
    background: #fff;
    border-radius: 20px;
    border: 1.5px solid #f1f5f9;
    box-shadow: 0 1px 3px rgba(0,0,0,.06), 0 4px 16px rgba(0,0,0,.04);
    position: relative; overflow: hidden;
    transition: box-shadow .2s, transform .2s;
}
.dr-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.1); transform: translateY(-2px); }
.dr-card-accent {
    position: absolute; right: 0; top: 0;
    width: 5px; height: 100%; border-radius: 0 20px 20px 0;
}
@keyframes drSlide {
    from { opacity:0; transform:translateY(14px); }
    to   { opacity:1; transform:translateY(0); }
}
.dr-animate { animation: drSlide .4s cubic-bezier(.16,1,.3,1) both; }
.dr-d1{animation-delay:.05s} .dr-d2{animation-delay:.1s}
.dr-d3{animation-delay:.15s} .dr-d4{animation-delay:.2s} .dr-d5{animation-delay:.25s}

.type-tab {
    padding: 7px 16px; border-radius: 99px; font-size: 12px; font-weight: 700;
    cursor: pointer; border: 1.5px solid transparent; transition: all .18s; white-space: nowrap;
}
.type-tab.active { border-color: currentColor; }

.slot-bar-track { background:#f1f5f9; border-radius:99px; height:6px; overflow:hidden; flex:1; }
.slot-bar-fill  { height:100%; border-radius:99px; transition:width .6s cubic-bezier(.16,1,.3,1); }

.camp-tab {
    padding: 8px 18px; border-radius: 10px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1.5px solid #e5e7eb; background: #fff;
    color: #4b5563; transition: all .18s; white-space: nowrap;
}
.camp-tab.active {
    background: #e8f8f0; border-color: #2e9e63; color: #1a5c38;
}
.camp-tab:hover:not(.active) { background: #f9fafb; }

.refresh-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700; color: #64748b;
    background: #f8fafc; border: 1.5px solid #e2e8f0;
    border-radius: 99px; padding: 4px 12px;
}
</style>

<?php
$date_th = date('j', strtotime($date));
$months_th = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$date_display = $date_th . ' ' . $months_th[(int)date('n', strtotime($date))] . ' ' . (date('Y', strtotime($date)) + 543);
?>

<?php renderPageHeader(
    'รีพอตรายวัน',
    'ข้อมูลการรับบริการ · ' . $date_display
); ?>

<!-- ── Controls ────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-5 flex flex-wrap gap-3 items-center justify-between">
    <div class="flex flex-wrap gap-3 items-center">
        <!-- Date picker -->
        <div class="flex items-center gap-2">
            <label class="text-xs font-black uppercase tracking-wider text-gray-400">วันที่</label>
            <input type="date" id="datePicker" value="<?= htmlspecialchars($date) ?>"
                   max="<?= $today ?>"
                   class="px-3 py-2 rounded-xl border border-gray-200 text-sm font-semibold text-gray-700
                          focus:ring-2 focus:ring-[#2e9e63] focus:border-[#2e9e63] outline-none cursor-pointer">
        </div>

        <!-- Campaign tabs -->
        <?php if (count($campaigns) > 0): ?>
        <div class="flex flex-wrap gap-2 items-center">
            <button class="camp-tab <?= $init_cid === 0 ? 'active' : '' ?>"
                    onclick="setCampaign(0)">ทั้งหมด</button>
            <?php foreach ($campaigns as $c): ?>
            <button class="camp-tab <?= $init_cid === (int)$c['id'] ? 'active' : '' ?>"
                    onclick="setCampaign(<?= (int)$c['id'] ?>)">
                <?= htmlspecialchars($c['title']) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Auto-refresh + Export -->
    <div class="flex items-center gap-3">
        <div class="refresh-badge" id="refreshBadge">
            <i class="fa-solid fa-rotate fa-spin text-[#2e9e63]" id="refreshIcon"></i>
            <span id="refreshLabel">รีเฟรชใน <span id="countdown">60</span>s</span>
        </div>
        <button onclick="toggleAutoRefresh()"
                id="refreshToggle"
                class="text-xs font-bold px-3 py-1.5 rounded-lg border border-gray-200
                       text-gray-500 hover:bg-gray-50 transition-colors">
            หยุด
        </button>
        <button id="exportBtn" onclick="doExport()"
                class="flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white
                       text-xs font-bold px-4 py-2 rounded-xl transition-colors shadow-sm">
            <i class="fa-solid fa-file-csv"></i> Export CSV
        </button>
    </div>
</div>

<!-- ── KPI Cards ───────────────────────────────────────────────────────── -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5" id="kpiGrid">
    <?php
    $kpi_skeletons = [
        ['label'=>'มาตามนัด','color'=>'#2e9e63','bg'=>'#e8f8f0','accent'=>'linear-gradient(180deg,#2e9e63,#4ade80)','icon'=>'fa-user-check'],
        ['label'=>'มาก่อนวันนัด','color'=>'#d97706','bg'=>'#fffbeb','accent'=>'linear-gradient(180deg,#f59e0b,#fbbf24)','icon'=>'fa-person-walking-arrow-right'],
        ['label'=>'ยังไม่มา','color'=>'#dc2626','bg'=>'#fef2f2','accent'=>'linear-gradient(180deg,#ef4444,#f87171)','icon'=>'fa-user-xmark'],
        ['label'=>'ยกเลิก','color'=>'#6b7280','bg'=>'#f9fafb','accent'=>'linear-gradient(180deg,#9ca3af,#d1d5db)','icon'=>'fa-ban'],
        ['label'=>'อัตรา No-Show','color'=>'#7c3aed','bg'=>'#f5f3ff','accent'=>'linear-gradient(180deg,#8b5cf6,#a78bfa)','icon'=>'fa-chart-pie'],
    ];
    foreach ($kpi_skeletons as $i => $k): ?>
    <div class="dr-card p-4 sm:p-5 dr-animate dr-d<?= $i+1 ?>"
         style="border-color:<?= $k['bg'] ?>">
        <div class="dr-card-accent" style="background:<?= $k['accent'] ?>"></div>
        <p class="text-[10px] font-black uppercase tracking-wider mb-2"
           style="color:<?= $k['color'] ?>">
            <i class="fa-solid <?= $k['icon'] ?> mr-1"></i><?= $k['label'] ?>
        </p>
        <div class="text-3xl sm:text-4xl font-black text-gray-900 mb-2 kpi-val" data-idx="<?= $i ?>">
            <span class="animate-pulse text-gray-200">——</span>
        </div>
        <div class="text-[10px] kpi-delta" data-idx="<?= $i ?>"></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Slot Breakdown ──────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm mb-5">
    <div class="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
        <h3 class="font-black text-gray-800 text-sm">
            <i class="fa-solid fa-clock-rotate-left mr-2 text-[#2e9e63]"></i>
            รายละเอียดรายรอบเวลา
        </h3>
        <span class="text-[10px] font-bold text-gray-400" id="slotSubtitle">วันที่ <?= htmlspecialchars($date_display) ?></span>
    </div>
    <div id="slotTableWrap" class="overflow-x-auto">
        <div class="px-6 py-10 text-center text-gray-400 text-sm">
            <i class="fa-solid fa-circle-notch fa-spin text-2xl text-[#2e9e63] mb-3 block"></i>
            กำลังโหลด...
        </div>
    </div>
</div>

<!-- ── Participant List ────────────────────────────────────────────────── -->
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm">
    <!-- Header + Type Filter -->
    <div class="px-5 py-4 border-b border-gray-50">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <h3 class="font-black text-gray-800 text-sm">
                <i class="fa-solid fa-list-ul mr-2 text-[#2e9e63]"></i>
                รายชื่อผู้รับบริการ
            </h3>
            <span class="text-[10px] font-bold text-gray-400" id="listMeta">—</span>
        </div>
        <!-- Type filter tabs -->
        <div class="flex flex-wrap gap-2">
            <?php
            $types = [
                ['val'=>'all',         'label'=>'ทั้งหมด',       'color'=>'#374151', 'bg'=>'#f3f4f6'],
                ['val'=>'on_schedule', 'label'=>'มาตามนัด',      'color'=>'#2e9e63', 'bg'=>'#e8f8f0'],
                ['val'=>'early',       'label'=>'มาก่อนวันนัด',  'color'=>'#d97706', 'bg'=>'#fffbeb'],
                ['val'=>'no_show',     'label'=>'ยังไม่มา',      'color'=>'#dc2626', 'bg'=>'#fef2f2'],
                ['val'=>'cancelled',   'label'=>'ยกเลิก',        'color'=>'#6b7280', 'bg'=>'#f9fafb'],
            ];
            foreach ($types as $t): ?>
            <button class="type-tab" data-type="<?= $t['val'] ?>"
                    style="color:<?= $t['color'] ?>; background:<?= $t['bg'] ?>;"
                    onclick="setType('<?= $t['val'] ?>')">
                <?= $t['label'] ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Table -->
    <div id="listTableWrap" class="overflow-x-auto">
        <div class="px-6 py-10 text-center text-gray-400 text-sm">
            <i class="fa-solid fa-circle-notch fa-spin text-2xl text-[#2e9e63] mb-3 block"></i>
            กำลังโหลด...
        </div>
    </div>

    <!-- Pagination -->
    <div id="pagerWrap" class="px-5 py-4 border-t border-gray-50 hidden">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <span class="text-xs text-gray-500" id="pagerInfo"></span>
            <div class="flex items-center gap-1" id="pagerBtns"></div>
        </div>
    </div>
</div>

<script>
(function () {
    /* ── State ─────────────────────────────────────────────────────── */
    const S = {
        date:       <?= json_encode($date) ?>,
        campaignId: <?= (int)$init_cid ?>,
        type:       'all',
        page:       1,
        totalPages: 1,
        autoRefresh: true,
        countdown:  60,
        timerId:    null,
        countId:    null,
    };

    const AJAX = 'ajax/ajax_daily_report.php';

    /* ── KPI config ────────────────────────────────────────────────── */
    const KPI = [
        { key: 'on_schedule',   good: true  },
        { key: 'early_arrival', good: true  },
        { key: 'no_show',       good: false },
        { key: 'cancelled_count', good: false },
        { key: '_rate',         good: false }, // no-show rate %
    ];

    /* ── Helpers ───────────────────────────────────────────────────── */
    function qs(sel)  { return document.querySelector(sel); }
    function qsa(sel) { return document.querySelectorAll(sel); }
    function n(v)     { return Number(v).toLocaleString('th-TH'); }

    function apiUrl(action, extra = {}) {
        const p = new URLSearchParams({
            action,
            date: S.date,
            campaign_id: S.campaignId,
            ...extra,
        });
        return AJAX + '?' + p.toString();
    }

    /* ── Delta badge ───────────────────────────────────────────────── */
    function deltaBadge(curr, prev, good) {
        const diff = curr - prev;
        if (prev === 0 && curr === 0) return '<span class="text-gray-300">ไม่มีข้อมูลเมื่อวาน</span>';
        if (diff === 0) return '<span class="text-gray-400">= เท่าเมื่อวาน</span>';
        const up   = diff > 0;
        const pos  = good ? up : !up;   // positive = good
        const col  = pos ? 'text-emerald-600 bg-emerald-50' : 'text-red-500 bg-red-50';
        const icon = up ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down';
        const sign = up ? '+' : '';
        return `<span class="text-[10px] font-black px-2 py-0.5 rounded-full ${col}">
                    <i class="fa-solid ${icon} mr-0.5"></i>${sign}${diff} จากเมื่อวาน
                </span>`;
    }

    /* ── Fetch stats ───────────────────────────────────────────────── */
    function loadStats() {
        fetch(apiUrl('stats'))
            .then(r => r.json())
            .then(d => {
                if (d.status !== 'success') return;
                const t = d.today, y = d.yesterday;
                const vals = [t.on_schedule, t.early_arrival, t.no_show, t.cancelled_count, d.no_show_rate];
                const yvals = [y.on_schedule, y.early_arrival, y.no_show, y.cancelled_count, 0];

                qsa('.kpi-val').forEach((el, i) => {
                    if (i === 4) {
                        el.innerHTML = `<span style="color:#7c3aed">${d.no_show_rate}%</span>`;
                    } else {
                        el.textContent = n(vals[i]);
                    }
                });
                qsa('.kpi-delta').forEach((el, i) => {
                    if (i === 4) {
                        el.innerHTML = `<span class="text-[10px] text-gray-400">มาก่อนวันนัด ${d.early_rate}%</span>`;
                    } else {
                        el.innerHTML = deltaBadge(vals[i], yvals[i], KPI[i].good);
                    }
                });

                const refreshEl = qs('#refreshIcon');
                if (refreshEl) refreshEl.classList.remove('fa-spin');
                setTimeout(() => { if (refreshEl) refreshEl.classList.add('fa-spin'); }, 200);

                const ts = qs('#refreshLabel');
                if (ts) ts.innerHTML = `รีเฟรชล่าสุด ${d.last_refresh}`;
            })
            .catch(() => {});
    }

    /* ── Fetch slots ───────────────────────────────────────────────── */
    function loadSlots() {
        const wrap = qs('#slotTableWrap');
        wrap.innerHTML = `<div class="px-6 py-8 text-center text-gray-400 text-sm">
            <i class="fa-solid fa-circle-notch fa-spin text-xl text-[#2e9e63] mb-2 block"></i>
            กำลังโหลด...
        </div>`;

        fetch(apiUrl('slots'))
            .then(r => r.json())
            .then(d => {
                if (d.status !== 'success') { wrap.innerHTML = '<div class="px-6 py-8 text-center text-red-400 text-sm">โหลดข้อมูลไม่สำเร็จ</div>'; return; }
                renderSlots(d.slots, wrap);
            })
            .catch(() => { wrap.innerHTML = '<div class="px-6 py-8 text-center text-red-400 text-sm">เกิดข้อผิดพลาด</div>'; });
    }

    function renderSlots(slots, wrap) {
        if (!slots || slots.length === 0) {
            wrap.innerHTML = `<div class="px-6 py-10 text-center text-gray-400 text-sm">
                <i class="fa-regular fa-calendar-xmark text-3xl mb-3 block"></i>
                ไม่มีรอบเวลาในวันที่เลือก
            </div>`;
            return;
        }

        const showCampaign = (S.campaignId === 0);
        let html = `<table class="w-full text-sm text-left whitespace-nowrap">
            <thead class="bg-gray-50 text-gray-500 text-[11px] font-black uppercase tracking-wider border-b border-gray-100">
                <tr>
                    <th class="px-5 py-3">รอบเวลา</th>
                    ${showCampaign ? '<th class="px-5 py-3">แคมเปญ</th>' : ''}
                    <th class="px-5 py-3 text-center">ที่นั่งทั้งหมด</th>
                    <th class="px-5 py-3 text-center">จองแล้ว</th>
                    <th class="px-5 py-3 text-center">เช็คอินแล้ว</th>
                    <th class="px-5 py-3 text-center">รอ</th>
                    <th class="px-5 py-3 text-center">ยกเลิก</th>
                    <th class="px-5 py-3">อัตราการใช้</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">`;

        slots.forEach(s => {
            const cap       = parseInt(s.max_capacity) || 1;
            const active    = parseInt(s.total_booked) - parseInt(s.cancelled_count);
            const fillPct   = Math.min(100, Math.round(active / cap * 100));
            const attendPct = active > 0 ? Math.round(parseInt(s.attended) / active * 100) : 0;
            const barColor  = fillPct >= 90 ? '#ef4444' : fillPct >= 70 ? '#f59e0b' : '#2e9e63';
            const st = s.start_time.slice(0,5), et = s.end_time.slice(0,5);

            html += `<tr class="hover:bg-gray-50 transition-colors">
                <td class="px-5 py-3">
                    <span class="font-bold text-gray-800">${st}–${et}</span>
                </td>
                ${showCampaign ? `<td class="px-5 py-3 text-gray-700 font-medium max-w-[160px] truncate" title="${escHtml(s.campaign_title)}">${escHtml(s.campaign_title)}</td>` : ''}
                <td class="px-5 py-3 text-center font-bold text-gray-700">${n(s.max_capacity)}</td>
                <td class="px-5 py-3 text-center">
                    <span class="font-bold ${fillPct >= 90 ? 'text-red-600' : 'text-gray-800'}">${n(active)}</span>
                    <span class="text-gray-400 text-xs ml-1">(${fillPct}%)</span>
                </td>
                <td class="px-5 py-3 text-center font-bold text-emerald-600">${n(s.attended)}</td>
                <td class="px-5 py-3 text-center font-bold text-amber-600">${n(s.pending)}</td>
                <td class="px-5 py-3 text-center font-bold text-gray-400">${n(s.cancelled_count)}</td>
                <td class="px-5 py-3">
                    <div class="flex items-center gap-2 min-w-[100px]">
                        <div class="slot-bar-track">
                            <div class="slot-bar-fill" style="width:${fillPct}%;background:${barColor}"></div>
                        </div>
                        <span class="text-xs font-bold" style="color:${barColor};min-width:34px">${attendPct}%</span>
                    </div>
                </td>
            </tr>`;
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    /* ── Fetch list ────────────────────────────────────────────────── */
    function loadList(page = 1) {
        S.page = page;
        const wrap = qs('#listTableWrap');
        wrap.innerHTML = `<div class="px-6 py-10 text-center text-gray-400 text-sm">
            <i class="fa-solid fa-circle-notch fa-spin text-2xl text-[#2e9e63] mb-3 block"></i>
            กำลังโหลด...
        </div>`;
        qs('#pagerWrap').classList.add('hidden');

        fetch(apiUrl('list', { type: S.type, page }))
            .then(r => r.json())
            .then(d => {
                if (d.status !== 'success') { wrap.innerHTML = '<div class="px-6 py-10 text-center text-red-400 text-sm">โหลดข้อมูลไม่สำเร็จ</div>'; return; }
                S.totalPages = d.pages;
                qs('#listMeta').textContent = `หน้า ${d.page} / ${d.pages} · รวม ${n(d.total)} รายการ`;
                renderList(d, wrap);
                renderPager(d);
            })
            .catch(() => { wrap.innerHTML = '<div class="px-6 py-10 text-center text-red-400 text-sm">เกิดข้อผิดพลาด</div>'; });
    }

    function visitBadge(type) {
        const cfg = {
            on_schedule: { cls:'bg-emerald-50 text-emerald-700 border-emerald-100', icon:'fa-user-check',              label:'มาตามนัด' },
            early:       { cls:'bg-amber-50 text-amber-700 border-amber-100',        icon:'fa-person-walking-arrow-right', label:'มาก่อนวันนัด' },
            no_show:     { cls:'bg-red-50 text-red-600 border-red-100',               icon:'fa-user-xmark',              label:'ยังไม่มา' },
            cancelled:   { cls:'bg-gray-100 text-gray-500 border-gray-200',           icon:'fa-ban',                     label:'ยกเลิก' },
            other:       { cls:'bg-gray-100 text-gray-500 border-gray-200',           icon:'fa-circle-question',         label:'อื่นๆ' },
        };
        const c = cfg[type] || cfg.other;
        return `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold border ${c.cls}">
                    <i class="fa-solid ${c.icon}"></i>${c.label}
                </span>`;
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    function renderList(d, wrap) {
        if (!d.rows || d.rows.length === 0) {
            wrap.innerHTML = `<div class="px-6 py-12 text-center text-gray-400 text-sm">
                <i class="fa-solid fa-users-slash text-3xl mb-3 block"></i>
                ไม่มีรายการในวันที่เลือก
            </div>`;
            return;
        }

        const showCampaign = (S.campaignId === 0);
        let html = `<table class="w-full text-left text-sm whitespace-nowrap">
            <thead class="bg-gray-50 text-gray-500 text-[11px] font-black uppercase tracking-wider border-b border-gray-100">
                <tr>
                    <th class="px-5 py-3">ชื่อ-นามสกุล / รหัส</th>
                    <th class="px-5 py-3">เบอร์</th>
                    ${showCampaign ? '<th class="px-5 py-3">แคมเปญ</th>' : ''}
                    <th class="px-5 py-3">วันนัด</th>
                    <th class="px-5 py-3">รอบเวลา</th>
                    <th class="px-5 py-3">เวลาเช็คอิน</th>
                    <th class="px-5 py-3">สถานะ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">`;

        d.rows.forEach(r => {
            const slotDate  = r.slot_date ? r.slot_date.slice(0,10) : '';
            const dateParts = slotDate ? slotDate.split('-') : [];
            const dateDisp  = dateParts.length === 3
                ? `${dateParts[2]}/${dateParts[1]}/${(parseInt(dateParts[0])+543)}`
                : '—';
            const timeDisp  = r.start_time && r.end_time
                ? r.start_time.slice(0,5) + '–' + r.end_time.slice(0,5) : '—';
            const checkin   = r.attended_at
                ? (() => {
                    const dt = new Date(r.attended_at);
                    return dt.toLocaleTimeString('th-TH', {hour:'2-digit', minute:'2-digit'}) + ' น.';
                  })()
                : '<span class="text-gray-300">—</span>';
            const dim = r.visit_type === 'cancelled' ? 'opacity-50' : '';

            html += `<tr class="hover:bg-gray-50 transition-colors ${dim}">
                <td class="px-5 py-3">
                    <div class="font-bold text-gray-900">${escHtml(r.full_name)}</div>
                    <div class="text-xs text-[#2e9e63] font-semibold mt-0.5">${escHtml(r.student_personnel_id || '—')}</div>
                </td>
                <td class="px-5 py-3 text-gray-600">${escHtml(r.phone_number || '—')}</td>
                ${showCampaign ? `<td class="px-5 py-3 text-gray-700 font-medium max-w-[140px] truncate" title="${escHtml(r.campaign_title)}">${escHtml(r.campaign_title)}</td>` : ''}
                <td class="px-5 py-3 font-medium text-gray-700">${dateDisp}</td>
                <td class="px-5 py-3 text-gray-600">${timeDisp}</td>
                <td class="px-5 py-3 font-semibold text-emerald-700">${checkin}</td>
                <td class="px-5 py-3">${visitBadge(r.visit_type)}</td>
            </tr>`;
        });

        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    /* ── Pagination ────────────────────────────────────────────────── */
    function renderPager(d) {
        const wrap = qs('#pagerWrap');
        if (d.pages <= 1) { wrap.classList.add('hidden'); return; }
        wrap.classList.remove('hidden');

        qs('#pagerInfo').textContent = `หน้า ${d.page} / ${d.pages} · รวม ${n(d.total)} รายการ`;

        const p = d.page, last = d.pages, win = 2;
        let btns = '';
        const btnCls = (active) => active
            ? 'px-2.5 py-1 text-xs rounded-lg font-bold text-white'
            : 'px-2.5 py-1 text-xs rounded-lg text-gray-500 hover:bg-gray-100';
        const navCls = (disabled) => disabled
            ? 'px-2 py-1 text-xs rounded-lg text-gray-300 pointer-events-none'
            : 'px-2 py-1 text-xs rounded-lg text-gray-500 hover:bg-gray-100 cursor-pointer';

        btns += `<button class="${navCls(p===1)}" onclick="goPage(1)">«</button>`;
        btns += `<button class="${navCls(p===1)}" onclick="goPage(${p-1})">‹</button>`;

        if (p > win + 1) {
            btns += `<button class="${btnCls(false)}" onclick="goPage(1)">1</button><span class="text-gray-300 text-xs">…</span>`;
        }
        for (let i = Math.max(1, p-win); i <= Math.min(last, p+win); i++) {
            btns += `<button class="${btnCls(i===p)}" style="${i===p?'background:#2e9e63':''}" onclick="goPage(${i})">${i}</button>`;
        }
        if (p < last - win) {
            btns += `<span class="text-gray-300 text-xs">…</span><button class="${btnCls(false)}" onclick="goPage(${last})">${last}</button>`;
        }

        btns += `<button class="${navCls(p===last)}" onclick="goPage(${p+1})">›</button>`;
        btns += `<button class="${navCls(p===last)}" onclick="goPage(${last})">»</button>`;

        qs('#pagerBtns').innerHTML = btns;
    }

    window.goPage = (p) => {
        p = Math.max(1, Math.min(S.totalPages, p));
        loadList(p);
    };

    /* ── Auto-refresh ──────────────────────────────────────────────── */
    function startAutoRefresh() {
        stopAutoRefresh();
        S.countdown = 60;
        updateCountdown();

        S.countId = setInterval(() => {
            S.countdown--;
            updateCountdown();
            if (S.countdown <= 0) {
                S.countdown = 60;
                refreshAll();
            }
        }, 1000);
    }

    function stopAutoRefresh() {
        clearInterval(S.countId);
        S.countId = null;
    }

    function updateCountdown() {
        const el = qs('#countdown');
        if (el) el.textContent = S.countdown;
    }

    window.toggleAutoRefresh = function () {
        S.autoRefresh = !S.autoRefresh;
        const btn   = qs('#refreshToggle');
        const label = qs('#refreshLabel');
        const icon  = qs('#refreshIcon');

        if (S.autoRefresh) {
            startAutoRefresh();
            btn.textContent = 'หยุด';
            icon.classList.remove('text-gray-400');
            icon.classList.add('text-[#2e9e63]');
        } else {
            stopAutoRefresh();
            btn.textContent = 'รีเฟรช';
            if (label) label.textContent = 'หยุดรีเฟรช';
            icon.classList.remove('text-[#2e9e63]');
            icon.classList.add('text-gray-400');
        }
    };

    /* ── Load all ──────────────────────────────────────────────────── */
    function loadAll() {
        loadStats();
        loadSlots();
        loadList(1);
    }

    function refreshAll() {
        loadStats();
        loadSlots();
        loadList(S.page);
    }

    /* ── Event handlers ────────────────────────────────────────────── */
    qs('#datePicker').addEventListener('change', function () {
        S.date = this.value;
        // update URL without reload
        const u = new URL(location.href);
        u.searchParams.set('date', S.date);
        u.searchParams.set('campaign_id', S.campaignId);
        history.replaceState({}, '', u.toString());
        loadAll();
        if (S.autoRefresh) startAutoRefresh();
    });

    window.setCampaign = function (id) {
        S.campaignId = id;
        qsa('.camp-tab').forEach(el => {
            el.classList.toggle('active', parseInt(el.getAttribute('onclick').match(/\d+/)?.[0] ?? -1) === id);
        });
        // fix "ทั้งหมด" button (no digit in onclick)
        qsa('.camp-tab').forEach(el => {
            if (el.getAttribute('onclick') === 'setCampaign(0)') {
                el.classList.toggle('active', id === 0);
            }
        });
        const u = new URL(location.href);
        u.searchParams.set('campaign_id', id);
        history.replaceState({}, '', u.toString());
        loadAll();
        if (S.autoRefresh) startAutoRefresh();
    };

    window.setType = function (type) {
        S.type = type;
        qsa('.type-tab').forEach(el => {
            const isActive = el.dataset.type === type;
            el.classList.toggle('active', isActive);
        });
        loadList(1);
    };

    window.doExport = function () {
        const p = new URLSearchParams({
            export: 'csv',
            date: S.date,
            campaign_id: S.campaignId,
            type: S.type,
        });
        location.href = 'daily_report.php?' + p.toString();
    };

    /* ── Init ──────────────────────────────────────────────────────── */
    // Set initial active type tab
    qsa('.type-tab').forEach(el => {
        if (el.dataset.type === 'all') el.classList.add('active');
    });

    loadAll();
    startAutoRefresh();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
