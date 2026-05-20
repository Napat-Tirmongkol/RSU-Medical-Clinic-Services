<?php
// portal/_partials/vaccinations.php — Vaccination Records dashboard (Phase 1)
// Loaded by portal/index.php — portal_CSRF + SweetAlert2 available
$vxIsSuper = (($_SESSION['admin_role'] ?? '') === 'superadmin');
// Pre-compute how many bookings could be backfilled — show only when > 0
// so a healthy install doesn't see a useless button. Counts attended
// vaccine bookings that don't yet have a record, mirroring the AJAX
// backfill action's SQL exactly.
$vxBackfillCount = 0;
if ($vxIsSuper) {
    try {
        $_p = db();
        $vxBackfillCount = (int)$_p->query("
            SELECT COUNT(*)
            FROM camp_bookings b
            JOIN camp_list c ON b.campaign_id = c.id
            LEFT JOIN user_vaccination_records v ON v.campaign_booking_id = b.id
            WHERE c.type = 'vaccine'
              AND (b.status = 'completed' OR b.attended_at IS NOT NULL)
              AND v.id IS NULL
        ")->fetchColumn();
    } catch (Throwable $e) { /* swallowed — UI just hides the button */ }
}
?>
<style>
.vx-page { padding: 4px 4px 80px; }
.vx-h1 { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; }
.vx-sub { font-size: 12px; color: #64748b; margin-bottom: 16px; }

.vx-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px; }
.vx-kpi { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; transition: transform 0.18s, box-shadow 0.18s; }
.vx-kpi:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -10px rgba(15,23,42,0.18); }
.vx-kpi .ic { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.vx-kpi .num { font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1.1; }
.vx-kpi .lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.vx-kpi .sub { font-size: 11px; color: #475569; font-weight: 700; margin-top: 1px; }
.vx-kpi[data-tone="month"]   { background: #ecfdf5; }
.vx-kpi[data-tone="month"] .ic   { background: #d1fae5; color: #047857; }
.vx-kpi[data-tone="year"]    { background: #eff6ff; }
.vx-kpi[data-tone="year"] .ic    { background: #dbeafe; color: #1e40af; }
.vx-kpi[data-tone="top"]     { background: #fef3c7; }
.vx-kpi[data-tone="top"] .ic     { background: #fde68a; color: #b45309; }
.vx-kpi[data-tone="upcoming"]{ background: #f3e8ff; }
.vx-kpi[data-tone="upcoming"] .ic{ background: #e9d5ff; color: #7c3aed; }
.vx-kpi[data-tone="overdue"] { background: #fef2f2; }
.vx-kpi[data-tone="overdue"] .ic { background: #fee2e2; color: #b91c1c; }

.vx-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; margin-bottom: 16px; }
.vx-card-title { font-size: 13px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.vx-card-title i { color: #0d9488; }

.vx-trend-bar { display: flex; align-items: flex-end; gap: 4px; height: 80px; }
.vx-trend-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.vx-trend-col .bar { width: 100%; max-width: 40px; background: linear-gradient(180deg, #0d9488, #0f766e); border-radius: 4px 4px 0 0; min-height: 2px; transition: filter 0.15s; }
.vx-trend-col:hover .bar { filter: brightness(1.15); }
.vx-trend-col .lbl { font-size: 9px; font-weight: 700; color: #64748b; }
.vx-trend-col .val { font-size: 10px; font-weight: 800; color: #0f172a; }

.vx-breakdown-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 12px; }
.vx-breakdown-row .name { flex: 1; font-weight: 700; color: #0f172a; min-width: 0; word-break: break-word; }
.vx-breakdown-row .num { font-weight: 900; color: #0d9488; }
.vx-breakdown-row .bar { height: 6px; border-radius: 99px; background: linear-gradient(90deg, #0d9488, #14b8a6); }

.vx-filter-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; margin-bottom: 12px; }
.vx-filter-bar label { font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 3px; }
.vx-filter-bar input, .vx-filter-bar select { font-size: 13px; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
.vx-filter-bar input[type="search"] { min-width: 220px; }
.vx-filter-bar .btn-x { padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 800; cursor: pointer; border: 1px solid transparent; transition: filter 0.15s; }
.vx-filter-bar .btn-x:hover { filter: brightness(1.05); }
.vx-filter-bar .btn-x.primary { background: #0d9488; color: #fff; }
.vx-filter-bar .btn-x.ghost { background: #fff; border-color: #cbd5e1; color: #475569; }

.vx-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; }
.vx-table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1000px; }
.vx-table th { background: #f8fafc; padding: 9px 10px; text-align: left; font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
.vx-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.vx-table tbody tr:hover { background: #fafbfc; cursor: pointer; }
.vx-table .mono { font-family: ui-monospace, Menlo, monospace; font-size: 11px; color: #475569; }
.vx-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 9999px; font-size: 11px; font-weight: 800; }
.vx-pill-ok    { background: #dcfce7; color: #15803d; }
.vx-pill-warn  { background: #fef3c7; color: #b45309; }
.vx-pill-err   { background: #fee2e2; color: #b91c1c; }
.vx-pill-muted { background: #e2e8f0; color: #475569; }

.vx-pager { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; font-size: 12px; color: #475569; flex-wrap: wrap; gap: 8px; }
.vx-pager .pg-btns { display: flex; gap: 4px; }
.vx-pager .pg-btn { min-width: 32px; height: 32px; padding: 0 8px; border-radius: 6px; border: 1px solid #cbd5e1; background: #fff; font-weight: 800; font-size: 12px; cursor: pointer; transition: background 0.15s; }
.vx-pager .pg-btn:hover:not(:disabled) { background: #f1f5f9; }
.vx-pager .pg-btn.active { background: #0d9488; border-color: #0d9488; color: #fff; }
.vx-pager .pg-btn:disabled { opacity: 0.4; cursor: not-allowed; }

/* Detail / edit modal — portal-escape pattern (teleport to body) */
#vx-edit-modal { display: none; z-index: 9000 !important; background: rgba(15,23,42,0.55) !important; backdrop-filter: blur(6px); position: fixed; inset: 0; align-items: center; justify-content: center; padding: 20px; }
#vx-edit-modal.is-open { display: flex; }
#vx-edit-box { background: #fff; border-radius: 18px; width: 100%; max-width: 760px; max-height: 92vh; overflow-y: auto; padding: 0; }
#vx-edit-box .vx-box-head { padding: 18px 22px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
#vx-edit-box .vx-box-head h3 { font-size: 17px; font-weight: 900; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
#vx-edit-box .vx-box-body { padding: 20px 22px; }
#vx-edit-box .vx-box-foot { padding: 14px 22px; border-top: 1px solid #f1f5f9; display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
#vx-edit-box .vx-field { margin-bottom: 12px; }
#vx-edit-box .vx-field label { display: block; font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
#vx-edit-box .vx-field input, #vx-edit-box .vx-field select, #vx-edit-box .vx-field textarea { width: 100%; font-size: 13px; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; font-family: inherit; }
#vx-edit-box .vx-field textarea { min-height: 70px; resize: vertical; }
#vx-edit-box .vx-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
#vx-edit-box .vx-sec-title { font-size: 11px; font-weight: 900; color: #0d9488; text-transform: uppercase; letter-spacing: .08em; margin: 16px 0 8px; padding-top: 14px; border-top: 1px dashed #cbd5e1; }
#vx-edit-box .vx-sec-title:first-of-type { margin-top: 0; padding-top: 0; border-top: 0; }
#vx-edit-box .vx-audit-row { font-size: 11px; padding: 8px 10px; background: #f8fafc; border-radius: 6px; margin-bottom: 4px; }
#vx-edit-box .vx-audit-row .when { font-weight: 800; color: #475569; }
#vx-edit-box .vx-audit-row .who  { color: #0d9488; font-weight: 700; }
#vx-edit-box .vx-audit-row .diff { font-family: ui-monospace, Menlo, monospace; color: #1e40af; }

.vx-swal-z { z-index: 9999 !important; }

body[data-theme='dark'] .vx-kpi,
body[data-theme='dark'] .vx-card,
body[data-theme='dark'] .vx-table-wrap,
body[data-theme='dark'] #vx-edit-box { background: #1e293b; border-color: #334155; color: #e2e8f0; }
body[data-theme='dark'] .vx-kpi .num,
body[data-theme='dark'] .vx-card-title,
body[data-theme='dark'] #vx-edit-box .vx-box-head h3 { color: #f1f5f9; }
body[data-theme='dark'] .vx-table th { background: #0f172a; color: #cbd5e1; border-color: #334155; }
body[data-theme='dark'] .vx-table td { border-color: #334155; }
body[data-theme='dark'] .vx-table tbody tr:hover { background: #0f172a; }
body[data-theme='dark'] .vx-filter-bar input,
body[data-theme='dark'] .vx-filter-bar select,
body[data-theme='dark'] #vx-edit-box input,
body[data-theme='dark'] #vx-edit-box select,
body[data-theme='dark'] #vx-edit-box textarea { background: #0f172a; border-color: #334155; color: #e2e8f0; }
</style>

<div class="vx-page">
    <h1 class="vx-h1"><i class="fa-solid fa-syringe" style="color:#0d9488"></i> บันทึกการฉีดวัคซีน</h1>
    <p class="vx-sub">ดูและแก้ไขบันทึกการฉีดวัคซีนทั้งหมด · KPI รายเดือน · เพิ่ม lot/manufacturer/dose/certificate · audit log per record</p>

    <?php if ($vxIsSuper && $vxBackfillCount > 0): ?>
    <div style="background:#fef3c7;border:1.5px solid #fde68a;color:#92400e;padding:12px 16px;border-radius:12px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="font-size:13px;font-weight:700;flex:1;min-width:240px">
            <i class="fa-solid fa-database" style="color:#b45309"></i>
            พบ <b style="color:#92400e;font-size:15px"><?= number_format($vxBackfillCount) ?></b> booking ที่ผู้ใช้มาฉีดแล้ว แต่ขาด record หรือ status ยังค้าง <code style="background:rgba(120,53,15,0.1);padding:1px 5px;border-radius:3px;font-size:11px">confirmed</code>
            <div style="font-size:11px;font-weight:600;color:#9a3412;margin-top:4px">การ backfill จะทำ 2 อย่างใน transaction เดียว: (1) สร้าง vaccination records ที่ขาด · (2) flip booking status เป็น completed</div>
        </div>
        <button type="button" class="btn-x primary" id="vx-backfill-btn"
                style="background:#b45309;color:#fff;padding:8px 16px;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;border:0">
            <i class="fa-solid fa-arrows-rotate"></i> Backfill ทั้งหมด
        </button>
    </div>
    <?php endif; ?>

    <!-- KPI tiles -->
    <div class="vx-kpis">
        <div class="vx-kpi" data-tone="month"><div class="ic"><i class="fa-solid fa-calendar-day"></i></div><div><div class="num" id="vx-k-month">–</div><div class="lbl">เดือนนี้ (dose)</div></div></div>
        <div class="vx-kpi" data-tone="year"><div class="ic"><i class="fa-solid fa-calendar"></i></div><div><div class="num" id="vx-k-year">–</div><div class="lbl">ปีนี้ (dose)</div></div></div>
        <div class="vx-kpi" data-tone="top"><div class="ic"><i class="fa-solid fa-medal"></i></div><div><div class="num" id="vx-k-top-num">–</div><div class="sub" id="vx-k-top-name">—</div><div class="lbl">วัคซีนยอดนิยม (เดือนนี้)</div></div></div>
        <div class="vx-kpi" data-tone="upcoming"><div class="ic"><i class="fa-solid fa-clock"></i></div><div><div class="num" id="vx-k-upcoming">–</div><div class="lbl">ครบกำหนดใน 30 วัน</div></div></div>
        <div class="vx-kpi" data-tone="overdue"><div class="ic"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="num" id="vx-k-overdue">–</div><div class="lbl">เลยกำหนด</div></div></div>
    </div>

    <!-- Trend + breakdown -->
    <div class="vx-card">
        <div class="vx-card-title"><i class="fa-solid fa-chart-column"></i> Trend 12 เดือน · Top วัคซีน (ปีนี้)</div>
        <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:18px;align-items:stretch">
            <div>
                <div class="vx-trend-bar" id="vx-trend"></div>
            </div>
            <div id="vx-breakdown" style="font-size:12px"></div>
        </div>
    </div>

    <!-- Filter + table -->
    <div class="vx-card">
        <div class="vx-filter-bar">
            <div>
                <label>ค้นหา (ชื่อ / วัคซีน / lot / cert)</label>
                <input type="search" id="vx-q" placeholder="พิมพ์เพื่อค้นหา…">
            </div>
            <div>
                <label>วัคซีน</label>
                <select id="vx-type"><option value="">— ทั้งหมด —</option></select>
            </div>
            <div>
                <label>ตั้งแต่</label>
                <input type="date" id="vx-from">
            </div>
            <div>
                <label>ถึง</label>
                <input type="date" id="vx-to">
            </div>
            <div>
                <label>สถานะ</label>
                <select id="vx-status">
                    <option value="">— ทั้งหมด —</option>
                    <option value="completed">ฉีดสำเร็จ</option>
                    <option value="cancelled">ยกเลิก</option>
                </select>
            </div>
            <button type="button" class="btn-x primary" onclick="vxLoadList(1)"><i class="fa-solid fa-magnifying-glass"></i> ค้นหา</button>
            <button type="button" class="btn-x ghost"   onclick="vxReset()"><i class="fa-solid fa-rotate-left"></i> ล้าง</button>
        </div>

        <div class="vx-table-wrap">
            <table class="vx-table">
                <thead><tr>
                    <th>วันที่ฉีด</th>
                    <th>ผู้รับ</th>
                    <th>วัคซีน</th>
                    <th>Dose</th>
                    <th>Lot</th>
                    <th>Manufacturer</th>
                    <th>ครบกำหนดต่อไป</th>
                    <th>สถานะ</th>
                </tr></thead>
                <tbody id="vx-tbody">
                    <tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8">กำลังโหลด…</td></tr>
                </tbody>
            </table>
            <div class="vx-pager" id="vx-pager"></div>
        </div>
    </div>
</div>

<!-- Edit modal (teleported to body on first open) -->
<div id="vx-edit-modal" onclick="if(event.target===this) vxCloseEdit()">
    <div id="vx-edit-box">
        <div class="vx-box-head">
            <h3><i class="fa-solid fa-syringe" style="color:#0d9488"></i> <span id="vx-edit-title">แก้ไขบันทึกวัคซีน</span></h3>
            <button type="button" class="btn-x ghost" onclick="vxCloseEdit()" style="padding:6px 10px"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="vx-box-body" id="vx-edit-body"></div>
        <div class="vx-box-foot">
            <button type="button" class="btn-x ghost" onclick="vxCloseEdit()">ปิด</button>
            <button type="button" class="btn-x ghost" onclick="vxOpenBulkApply()" id="vx-bulk-btn" style="display:none;color:#0d9488;border-color:#0d9488"><i class="fa-solid fa-arrows-to-circle"></i> ใช้กับทุก record ในแคมเปญ</button>
            <button type="button" class="btn-x primary" onclick="vxSubmitEdit()" id="vx-edit-save"><i class="fa-solid fa-floppy-disk"></i> บันทึก + ระบุเหตุผล</button>
        </div>
    </div>
</div>

<script>
(function() {
    const AJAX = 'ajax_vaccinations.php';
    let vxCurrent = { page: 1, perPage: 20, totalPages: 1 };
    let vxTypes = [];          // loaded once via action=types
    let vxCurrentRow = null;   // record being edited

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function fmtDate(s) {
        if (!s) return '<span style="color:#cbd5e1">—</span>';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) return esc(s);
        return d.toLocaleDateString('th-TH', { day:'2-digit', month:'short', year:'numeric' });
    }
    function fmtDateTime(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d.getTime())) return s;
        return d.toLocaleString('th-TH', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
    }

    async function vxLoadStats() {
        try {
            const res = await fetch(AJAX + '?action=stats', { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);
            document.getElementById('vx-k-month').textContent = json.kpi.this_month.toLocaleString('th-TH');
            document.getElementById('vx-k-year').textContent  = json.kpi.this_year.toLocaleString('th-TH');
            if (json.kpi.top_month) {
                document.getElementById('vx-k-top-num').textContent  = (+json.kpi.top_month.n).toLocaleString('th-TH');
                document.getElementById('vx-k-top-name').textContent = json.kpi.top_month.vaccine_name;
            } else {
                document.getElementById('vx-k-top-num').textContent = '0';
                document.getElementById('vx-k-top-name').textContent = '—';
            }
            document.getElementById('vx-k-upcoming').textContent = json.kpi.upcoming_30d.toLocaleString('th-TH');
            document.getElementById('vx-k-overdue').textContent  = json.kpi.overdue.toLocaleString('th-TH');

            // Trend bar — last 12 months. Pad missing months with 0 so the bar layout
            // stays a fixed-width grid instead of skewing toward dense months.
            const trendEl = document.getElementById('vx-trend');
            const ymCounts = {};
            (json.trend || []).forEach(r => { ymCounts[r.ym] = +r.n; });
            const months = [];
            const now = new Date();
            for (let i = 11; i >= 0; i--) {
                const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
                const ym = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
                months.push({ ym, label: d.toLocaleString('th-TH', { month: 'short' }), n: ymCounts[ym] || 0 });
            }
            const max = Math.max(1, ...months.map(m => m.n));
            trendEl.innerHTML = months.map(m => `
                <div class="vx-trend-col" title="${m.ym}: ${m.n} dose">
                    <div class="val">${m.n || ''}</div>
                    <div class="bar" style="height:${Math.max(2, (m.n / max) * 70)}px"></div>
                    <div class="lbl">${esc(m.label)}</div>
                </div>`).join('');

            // Breakdown bars
            const bdEl = document.getElementById('vx-breakdown');
            const bdMax = Math.max(1, ...(json.by_vaccine || []).map(r => +r.n));
            bdEl.innerHTML = (json.by_vaccine || []).map(r => `
                <div class="vx-breakdown-row">
                    <span class="name">${esc(r.vaccine_name)}</span>
                    <span class="num">${(+r.n).toLocaleString('th-TH')}</span>
                </div>
                <div class="bar" style="width:${((+r.n) / bdMax) * 100}%; margin-bottom: 6px"></div>
            `).join('') || '<div style="color:#94a3b8;font-size:11px">ยังไม่มีข้อมูลปีนี้</div>';
        } catch (err) {
            console.error('[vacc] stats', err);
        }
    }

    async function vxLoadTypes() {
        try {
            const res = await fetch(AJAX + '?action=types', { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);
            vxTypes = json.types || [];
            const sel = document.getElementById('vx-type');
            sel.innerHTML = '<option value="">— ทั้งหมด —</option>'
                + vxTypes.map(t => `<option value="${t.id}">${esc(t.name_th)} (${esc(t.code)})</option>`).join('');
        } catch (err) {
            console.error('[vacc] types', err);
        }
    }

    async function vxLoadList(page) {
        page = page || 1;
        vxCurrent.page = page;
        const params = new URLSearchParams({
            action: 'list',
            page,
            per_page: vxCurrent.perPage,
            q: document.getElementById('vx-q').value.trim(),
            type_id: document.getElementById('vx-type').value,
            from: document.getElementById('vx-from').value,
            to: document.getElementById('vx-to').value,
            status: document.getElementById('vx-status').value,
        });

        const tbody = document.getElementById('vx-tbody');
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด…</td></tr>';

        try {
            const res = await fetch(AJAX + '?' + params, { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);

            vxCurrent.totalPages = json.page_count;
            if (!json.rows.length) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#94a3b8">ไม่พบบันทึก</td></tr>';
            } else {
                tbody.innerHTML = json.rows.map(r => {
                    const statusPill = r.status === 'completed'
                        ? '<span class="vx-pill vx-pill-ok"><i class="fa-solid fa-check"></i> สำเร็จ</span>'
                        : r.status === 'cancelled'
                            ? '<span class="vx-pill vx-pill-muted">ยกเลิก</span>'
                            : '<span class="vx-pill vx-pill-err">ผิดพลาด</span>';
                    return `<tr onclick="vxOpenEdit(${r.id})">
                        <td>${fmtDate(r.vaccinated_at)}</td>
                        <td><b>${esc(r.full_name || '—')}</b><br><span class="mono" style="font-size:10px">${esc(r.citizen_id || '')}</span></td>
                        <td><b>${esc(r.vaccine_name || '—')}</b></td>
                        <td class="mono">${r.dose_number ? '#' + r.dose_number : '—'}</td>
                        <td class="mono">${esc(r.lot_number || '—')}</td>
                        <td>${esc(r.manufacturer || '—')}</td>
                        <td>${r.next_due_date ? fmtDate(r.next_due_date) : '<span style="color:#cbd5e1">—</span>'}</td>
                        <td>${statusPill}</td>
                    </tr>`;
                }).join('');
            }

            // Pagination
            const pager = document.getElementById('vx-pager');
            const p = json.page, pc = json.page_count;
            const win = 2;
            const start = Math.max(1, p - win), end = Math.min(pc, p + win);
            let btns = '';
            btns += `<button class="pg-btn" ${p===1?'disabled':''} onclick="vxLoadList(1)">«</button>`;
            btns += `<button class="pg-btn" ${p===1?'disabled':''} onclick="vxLoadList(${p-1})">‹</button>`;
            for (let i = start; i <= end; i++) {
                btns += `<button class="pg-btn ${i===p?'active':''}" onclick="vxLoadList(${i})">${i}</button>`;
            }
            btns += `<button class="pg-btn" ${p===pc?'disabled':''} onclick="vxLoadList(${p+1})">›</button>`;
            btns += `<button class="pg-btn" ${p===pc?'disabled':''} onclick="vxLoadList(${pc})">»</button>`;
            pager.innerHTML = `
                <div>หน้า ${p} / ${pc} · รวม ${json.total.toLocaleString('th-TH')} รายการ</div>
                <div class="pg-btns">${btns}</div>`;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:30px;color:#b91c1c">ERROR: ${esc(err.message)}</td></tr>`;
        }
    }

    function vxReset() {
        document.getElementById('vx-q').value = '';
        document.getElementById('vx-type').value = '';
        document.getElementById('vx-from').value = '';
        document.getElementById('vx-to').value = '';
        document.getElementById('vx-status').value = '';
        vxLoadList(1);
    }

    function vxTeleportModal() {
        const m = document.getElementById('vx-edit-modal');
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
        return m;
    }

    async function vxOpenEdit(id) {
        const m = vxTeleportModal();
        const body = document.getElementById('vx-edit-body');
        body.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด…</div>';
        document.getElementById('vx-edit-title').textContent = 'แก้ไขบันทึกวัคซีน';
        m.classList.add('is-open');

        try {
            const res = await fetch(AJAX + '?action=detail&id=' + encodeURIComponent(id), { credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);
            vxCurrentRow = json.row;
            // Bulk-apply only makes sense when the record is linked to a
            // campaign booking — otherwise there's no "campaign" to apply across
            const bulkBtn = document.getElementById('vx-bulk-btn');
            if (bulkBtn) bulkBtn.style.display = json.row.campaign_booking_id ? '' : 'none';
            document.getElementById('vx-edit-title').textContent = `แก้ไขบันทึก · ${json.row.full_name || '#'+id}`;

            const r = json.row;
            const typeOpts = '<option value="">— ไม่ระบุ —</option>' + vxTypes.map(t =>
                `<option value="${t.id}" ${r.vaccine_type_id == t.id ? 'selected' : ''}>${esc(t.name_th)} (${esc(t.code)})</option>`).join('');

            const auditHtml = (json.audit && json.audit.length)
                ? json.audit.map(a => {
                    const diffKeys = a.changes ? Object.keys(a.changes) : [];
                    return `<div class="vx-audit-row">
                        <span class="when">${fmtDateTime(a.created_at)}</span> ·
                        <span class="who">${esc(a.performed_by_name || '—')}</span>
                        ${a.action === 'update' ? `<span class="diff">[${diffKeys.join(', ')}]</span>` : ''}
                        ${a.reason ? `<div style="margin-top:3px;color:#475569">${esc(a.reason)}</div>` : ''}
                    </div>`;
                }).join('')
                : '<div style="font-size:11px;color:#94a3b8">— ยังไม่มีประวัติแก้ไข —</div>';

            body.innerHTML = `
                <div class="vx-sec-title">ผู้รับวัคซีน</div>
                <div class="vx-grid-2">
                    <div class="vx-field"><label>ชื่อ-สกุล</label><input type="text" value="${esc(r.full_name || '—')}" disabled style="background:#f8fafc"></div>
                    <div class="vx-field"><label>เลขบัตร</label><input type="text" value="${esc(r.citizen_id || '—')}" disabled style="background:#f8fafc"></div>
                </div>

                <div class="vx-sec-title">ข้อมูลวัคซีน</div>
                <div class="vx-grid-2">
                    <div class="vx-field">
                        <label>ประเภทวัคซีน (catalog)</label>
                        <select id="vx-f-type">${typeOpts}</select>
                    </div>
                    <div class="vx-field"><label>ชื่อวัคซีน (free text)</label><input type="text" id="vx-f-name" value="${esc(r.vaccine_name || '')}" maxlength="200"></div>
                    <div class="vx-field"><label>Dose #</label><input type="number" id="vx-f-dose" value="${r.dose_number ?? ''}" min="1" max="20"></div>
                    <div class="vx-field"><label>Lot number</label><input type="text" id="vx-f-lot" value="${esc(r.lot_number || '')}" maxlength="100"></div>
                    <div class="vx-field"><label>Manufacturer</label><input type="text" id="vx-f-mfr" value="${esc(r.manufacturer || '')}" maxlength="150"></div>
                    <div class="vx-field"><label>Injection site</label><input type="text" id="vx-f-site" value="${esc(r.injection_site || '')}" maxlength="100" placeholder="เช่น ต้นแขนซ้าย"></div>
                    <div class="vx-field"><label>Provider (เจ้าหน้าที่)</label><input type="text" id="vx-f-prov" value="${esc(r.provider_name || '')}" maxlength="255"></div>
                    <div class="vx-field"><label>Location</label><input type="text" id="vx-f-loc" value="${esc(r.location || '')}" maxlength="255"></div>
                    <div class="vx-field"><label>ครบกำหนดต่อไป (next due)</label><input type="date" id="vx-f-due" value="${r.next_due_date || ''}"></div>
                    <div class="vx-field"><label>Certificate No.</label><input type="text" id="vx-f-cert" value="${esc(r.certificate_no || '')}" maxlength="100"></div>
                </div>
                <div class="vx-field"><label>หมายเหตุ</label><textarea id="vx-f-notes" maxlength="2000">${esc(r.notes || '')}</textarea></div>

                <div class="vx-sec-title">สถานะและเหตุผลการแก้ไข</div>
                <div class="vx-grid-2">
                    <div class="vx-field">
                        <label>สถานะ</label>
                        <select id="vx-f-status">
                            <option value="completed" ${r.status === 'completed' ? 'selected' : ''}>ฉีดสำเร็จ</option>
                            <option value="cancelled" ${r.status === 'cancelled' ? 'selected' : ''}>ยกเลิก</option>
                            <?php if ($vxIsSuper): ?>
                            <option value="entered_in_error" ${r.status === 'entered_in_error' ? 'selected' : ''}>บันทึกผิดพลาด (superadmin only)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="vx-field"><label>เหตุผลการแก้ไข <span style="color:#dc2626">*</span></label><input type="text" id="vx-f-reason" placeholder="เช่น เพิ่ม lot จากใบ certificate" minlength="5" maxlength="500"></div>
                </div>

                <div class="vx-sec-title">ประวัติแก้ไข (audit)</div>
                ${auditHtml}
            `;
        } catch (err) {
            body.innerHTML = `<div style="text-align:center;padding:40px;color:#b91c1c">ERROR: ${esc(err.message)}</div>`;
        }
    }

    function vxCloseEdit() {
        document.getElementById('vx-edit-modal').classList.remove('is-open');
        vxCurrentRow = null;
    }

    async function vxSubmitEdit() {
        if (!vxCurrentRow) return;
        const reason = (document.getElementById('vx-f-reason').value || '').trim();
        if (reason.length < 5) {
            Swal.fire({ icon:'warning', title:'กรุณาระบุเหตุผล', text:'อย่างน้อย 5 ตัวอักษร — จะถูกเก็บใน audit log',
                customClass: { container: 'vx-swal-z' } });
            return;
        }
        const btn = document.getElementById('vx-edit-save');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังบันทึก…';

        try {
            const fd = new FormData();
            fd.append('csrf_token', (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '');
            fd.append('id', vxCurrentRow.id);
            fd.append('reason', reason);
            // Explicit field → element id map (better than string transforms;
            // every editable backend field has exactly one input).
            // Send everything the form has so a deliberate clear (e.g. removing
            // a stale lot number) is captured server-side.
            const mapping = {
                vaccine_type_id: 'vx-f-type',
                vaccine_name:    'vx-f-name',
                dose_number:     'vx-f-dose',
                lot_number:      'vx-f-lot',
                manufacturer:    'vx-f-mfr',
                injection_site:  'vx-f-site',
                provider_name:   'vx-f-prov',
                location:        'vx-f-loc',
                next_due_date:   'vx-f-due',
                certificate_no:  'vx-f-cert',
                notes:           'vx-f-notes',
                status:          'vx-f-status',
            };
            for (const [field, elId] of Object.entries(mapping)) {
                const el = document.getElementById(elId);
                if (el) fd.append(field, el.value);
            }

            const res = await fetch(AJAX + '?action=update', { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);

            Swal.fire({ icon:'success', title:'บันทึกเรียบร้อย',
                text: Object.keys(json.changeset || {}).length + ' ฟิลด์ถูกอัพเดต',
                timer: 1800, showConfirmButton: false,
                customClass: { container: 'vx-swal-z' }
            });
            vxCloseEdit();
            vxLoadStats();
            vxLoadList(vxCurrent.page);
        } catch (err) {
            Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text: err.message,
                customClass: { container: 'vx-swal-z' } });
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> บันทึก + ระบุเหตุผล';
        }
    }

    // Debounced search-as-you-type
    let vxQTimer;
    document.getElementById('vx-q').addEventListener('input', () => {
        clearTimeout(vxQTimer);
        vxQTimer = setTimeout(() => vxLoadList(1), 350);
    });
    ['vx-type','vx-from','vx-to','vx-status'].forEach(id => {
        document.getElementById(id).addEventListener('change', () => vxLoadList(1));
    });

    // Bulk Apply — take the field values currently shown in the form and
    // push them to every other record in the same campaign. Modal asks the
    // operator to check which fields to apply (defaults to lot + manufacturer
    // which are the most common batch-level fields) plus a reason.
    async function vxOpenBulkApply() {
        if (!vxCurrentRow || !vxCurrentRow.campaign_booking_id) {
            Swal.fire({ icon:'info', title:'ไม่สามารถ Apply ได้', text:'record นี้ไม่ได้ผูกกับแคมเปญ', customClass:{ container:'vx-swal-z' } });
            return;
        }
        // Read what's currently in the form (may differ from server-side row
        // if the operator just typed something but hasn't saved yet)
        const cur = {
            vaccine_type_id: document.getElementById('vx-f-type').value,
            vaccine_name:    document.getElementById('vx-f-name').value,
            dose_number:     document.getElementById('vx-f-dose').value,
            lot_number:      document.getElementById('vx-f-lot').value,
            manufacturer:    document.getElementById('vx-f-mfr').value,
            injection_site:  document.getElementById('vx-f-site').value,
            provider_name:   document.getElementById('vx-f-prov').value,
            location:        document.getElementById('vx-f-loc').value,
            next_due_date:   document.getElementById('vx-f-due').value,
            certificate_no:  document.getElementById('vx-f-cert').value,
        };
        const labels = {
            vaccine_type_id: 'ประเภทวัคซีน (catalog)',
            vaccine_name: 'ชื่อวัคซีน',
            dose_number: 'Dose #',
            lot_number: 'Lot',
            manufacturer: 'Manufacturer',
            injection_site: 'Injection site',
            provider_name: 'Provider',
            location: 'Location',
            next_due_date: 'Next due date',
            certificate_no: 'Certificate No.',
        };
        // Default-on: lot + manufacturer (the typical batch-level fields)
        const defaultOn = new Set(['lot_number','manufacturer']);
        const checkboxes = Object.keys(labels).map(f => {
            const v = cur[f];
            const display = v ? esc(String(v)) : '<i style="color:#94a3b8">— ว่าง —</i>';
            const checked = defaultOn.has(f) && v ? 'checked' : '';
            return `<label style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px;cursor:pointer;border-bottom:1px solid #f1f5f9">
                <input type="checkbox" class="vx-ba-check" value="${f}" ${checked} style="width:16px;height:16px">
                <span style="flex:1"><b>${esc(labels[f])}</b>: <span style="color:#475569">${display}</span></span>
            </label>`;
        }).join('');

        const { isConfirmed, value } = await Swal.fire({
            title: 'ใช้ค่ากับทุก record ในแคมเปญ',
            html: `<div style="text-align:left;font-size:13px">
                       <div style="background:#fef3c7;border:1px solid #fde68a;padding:8px 12px;border-radius:8px;margin-bottom:12px;color:#92400e;font-size:11px;font-weight:700">
                           <i class="fa-solid fa-triangle-exclamation"></i> เลือกเฉพาะฟิลด์ที่ต้องการ overwrite ทุก target record
                       </div>
                       <div style="max-height:300px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:4px 12px;margin-bottom:12px">
                           ${checkboxes}
                       </div>
                       <label style="display:block;font-weight:800;font-size:11px;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">
                           เหตุผล <span style="color:#dc2626">*</span>
                       </label>
                       <input type="text" id="vx-ba-reason" class="swal2-input" style="width:100%;margin:0" placeholder="เช่น apply lot จาก batch รับเข้าวันที่ ...">
                   </div>`,
            showCancelButton: true,
            confirmButtonText: 'Apply ทั้งหมด',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#0d9488',
            reverseButtons: true,
            focusConfirm: false,
            customClass: { container: 'vx-swal-z' },
            preConfirm: () => {
                const checked = Array.from(document.querySelectorAll('.vx-ba-check:checked')).map(c => c.value);
                const reason = (document.getElementById('vx-ba-reason').value || '').trim();
                if (!checked.length) { Swal.showValidationMessage('เลือกอย่างน้อย 1 ฟิลด์'); return false; }
                if (reason.length < 5) { Swal.showValidationMessage('เหตุผลต้องอย่างน้อย 5 ตัวอักษร'); return false; }
                return { fields: checked, reason };
            },
        });
        if (!isConfirmed || !value) return;

        try {
            const fd = new FormData();
            fd.append('csrf_token', (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '');
            fd.append('source_id', vxCurrentRow.id);
            fd.append('reason', value.reason);
            for (const f of value.fields) fd.append('apply_' + f, '1');

            const res = await fetch(AJAX + '?action=bulk_apply', { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) throw new Error(json.message);

            await Swal.fire({
                icon: 'success',
                title: 'Apply สำเร็จ',
                html: `อัพเดต <b>${json.updated}</b> records (จาก ${json.targets} targets)`,
                customClass: { container: 'vx-swal-z' },
            });
            vxCloseEdit();
            vxLoadStats();
            vxLoadList(vxCurrent.page);
        } catch (err) {
            Swal.fire({ icon:'error', title:'Apply ล้มเหลว', text: err.message, customClass:{ container:'vx-swal-z' } });
        }
    }

    // Expose for inline handlers
    window.vxLoadList = vxLoadList;
    window.vxReset = vxReset;
    window.vxOpenEdit = vxOpenEdit;
    window.vxCloseEdit = vxCloseEdit;
    window.vxSubmitEdit = vxSubmitEdit;
    window.vxOpenBulkApply = vxOpenBulkApply;

    // Backfill button — only present in DOM for superadmin viewers
    const backfillBtn = document.getElementById('vx-backfill-btn');
    if (backfillBtn) {
        backfillBtn.addEventListener('click', async () => {
            const { isConfirmed } = await Swal.fire({
                icon: 'question',
                title: 'รัน Backfill?',
                html: `ทำ 2 ขั้นตอนใน transaction เดียว:
                       <ol style="text-align:left;font-size:13px;margin:8px 0 0;padding-left:24px">
                         <li>สร้าง <code>user_vaccination_records</code> ที่ขาดสำหรับ booking attended</li>
                         <li>Flip <code>camp_bookings.status</code> จาก <code>confirmed</code> → <code>completed</code> (เฉพาะ vaccine + มี attended_at)</li>
                       </ol>
                       <div style="margin-top:8px;font-size:11px;color:#64748b">ปลอดภัยที่จะคลิกซ้ำ · ทั้งคู่ idempotent · audit log ไป Activity Logs</div>`,
                showCancelButton: true,
                confirmButtonText: 'รัน Backfill',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#b45309',
                reverseButtons: true,
                customClass: { container: 'vx-swal-z' },
            });
            if (!isConfirmed) return;

            backfillBtn.disabled = true;
            const originalHtml = backfillBtn.innerHTML;
            backfillBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังรัน…';
            try {
                const fd = new FormData();
                fd.append('csrf_token', (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '');
                const res = await fetch(AJAX + '?action=backfill', { method: 'POST', body: fd, credentials: 'same-origin' });
                const json = await res.json();
                if (!json.ok) throw new Error(json.message);

                await Swal.fire({
                    icon: 'success',
                    title: 'Backfill สำเร็จ',
                    html: `<div style="text-align:left;font-size:13px">
                              <div>📋 Vaccination records: <b>+${json.inserted}</b> (จาก ${json.candidates} candidates)</div>
                              <div>🔄 Booking status flip: <b>${json.flipped}</b> rows · confirmed → completed</div>
                           </div>`,
                    confirmButtonText: 'ปิด',
                    confirmButtonColor: '#0d9488',
                    customClass: { container: 'vx-swal-z' },
                });
                // Reload everything: KPI numbers + trend + table all change
                location.reload();
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Backfill ล้มเหลว', text: err.message, customClass: { container: 'vx-swal-z' } });
                backfillBtn.disabled = false;
                backfillBtn.innerHTML = originalHtml;
            }
        });
    }

    // Boot
    (async () => {
        await vxLoadTypes();
        vxLoadStats();
        vxLoadList(1);
    })();
})();
</script>
