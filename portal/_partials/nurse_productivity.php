<?php
// portal/_partials/nurse_productivity.php
// Nurse OPD Productivity — multi-tenant per department, with nurse_schedule integration + monthly/yearly rollup
// Visual conventions match portal/_partials/finance.php (canonical Cash Book pattern)
?>
<style>
/* ── NURSE PRODUCTIVITY — module-scoped styles
   Mirrors the Cash Book (.fin-*) patterns:
   thin-border cards, compact KPI tiles with data-tone,
   flat chip toggles, gradient-dark sticky bulk bar.
   ──────────────────────────────────────────────── */

/* Cards */
.np-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; }

/* Highlight ที่ใช้ flash row ที่เพิ่งเพิ่มผ่านปุ่ม "เพิ่มแถว" */
@keyframes npRowFlash {
    0%   { background-color: #fef9c3; box-shadow: inset 3px 0 0 #f59e0b; }
    100% { background-color: transparent; box-shadow: inset 3px 0 0 transparent; }
}
.np-row-flash td { animation: npRowFlash 1.2s ease-out both; }
@media (prefers-reduced-motion: reduce) {
    .np-row-flash td { animation: none; background-color: #fef9c3; }
}

/* KPI tiles — compact horizontal */
.np-kpi { display:flex; align-items:center; gap:12px; padding:14px 16px; border-radius:12px; border:1px solid transparent; position:relative; }
.np-kpi .ic { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.np-kpi .num { font-size:20px; font-weight:900; color:#0f172a; line-height:1.1; }
.np-kpi .num .unit { font-size:12px; font-weight:700; color:#64748b; margin-left:2px; }
.np-kpi .lbl { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; }
.np-kpi .sub { font-size:10.5px; font-weight:600; color:#94a3b8; margin-top:2px; }

.np-kpi[data-tone="prod"]    { background:#eff6ff; }
.np-kpi[data-tone="prod"] .ic    { background:#dbeafe; color:#1e40af; }
.np-kpi[data-tone="visits"]  { background:#f0fdf4; }
.np-kpi[data-tone="visits"] .ic  { background:#dcfce7; color:#15803d; }
.np-kpi[data-tone="hours"]   { background:#fff7ed; }
.np-kpi[data-tone="hours"] .ic   { background:#ffedd5; color:#c2410c; }
.np-kpi[data-tone="optimal"] { background:#ecfdf5; }
.np-kpi[data-tone="optimal"] .ic { background:#d1fae5; color:#047857; }
.np-kpi[data-tone="under"]   { background:#fef2f2; }
.np-kpi[data-tone="under"] .ic   { background:#fee2e2; color:#b91c1c; }
.np-kpi[data-tone="over"]    { background:#fffbeb; }
.np-kpi[data-tone="over"] .ic    { background:#fef3c7; color:#b45309; }

/* Period-over-period delta badge (same convention as .fin-delta) */
.np-delta { display:inline-flex; align-items:center; gap:3px; padding:1px 7px; border-radius:99px; font-size:10px; font-weight:800; margin-left:4px; vertical-align:middle; }
.np-delta.up   { background:#dcfce7; color:#15803d; }
.np-delta.down { background:#fee2e2; color:#b91c1c; }
.np-delta.flat { background:#f1f5f9; color:#64748b; }
.np-delta.hidden { display:none; }

/* Quick-toggle chips (view mode + filters) — borrowed pattern from .fin-chip */
.np-chip { padding:5px 12px; border-radius:99px; border:1.5px solid #e2e8f0; background:#fff; color:#475569; font-size:12px; font-weight:700; cursor:pointer; transition:all .15s; white-space:nowrap; }
.np-chip:hover { border-color:#2e9e63; color:#2e9e63; background:#f0faf4; }
.np-chip.is-active { background:linear-gradient(135deg,#2e9e63,#3bba7a); color:#fff; border-color:transparent; box-shadow:0 4px 10px -3px rgba(46,158,99,.45); }

/* Filter bar (date inputs) — mirror of .fin-filter-bar */
.np-filter-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:end; }
.np-filter-bar label { font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:3px; }
.np-filter-bar input, .np-filter-bar select { font-size:13px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; font-family:inherit; }

/* Tabs strip — simpler than chips, ties active state to brand gradient */
.np-tabs { display:flex; gap:4px; padding:4px; background:#f1f5f9; border-radius:12px; width:fit-content; }
.np-tab { padding:8px 16px; border-radius:8px; font-size:12px; font-weight:800; color:#64748b; background:transparent; border:0; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:all .15s; }
.np-tab:hover { background:#fff; color:#0f172a; }
.np-tab.is-active { background:linear-gradient(135deg,#2e9e63,#3bba7a); color:#fff; box-shadow:0 4px 10px -3px rgba(46,158,99,.45); }
.np-tab-count { background:rgba(255,255,255,.25); padding:1px 7px; border-radius:99px; font-size:10px; font-weight:800; }
.np-tab:not(.is-active) .np-tab-count { background:#e2e8f0; color:#475569; }
.np-tab-content { display:none; }
.np-tab-content.is-active { display:block; }

/* Status banner (similar to .ds-card-soft with tone) */
.np-banner { display:flex; gap:12px; align-items:center; padding:12px 16px; border-radius:12px; border:1px solid; }
.np-banner.is-ok    { background:#f0fdf4; border-color:#bbf7d0; color:#15803d; }
.np-banner.is-under { background:#fef2f2; border-color:#fecaca; color:#b91c1c; }
.np-banner.is-over  { background:#fffbeb; border-color:#fde68a; color:#b45309; }
.np-banner .ic { width:36px; height:36px; border-radius:10px; background:rgba(255,255,255,.65); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.np-banner b   { display:block; font-size:13px; font-weight:900; }
.np-banner small { font-size:11.5px; opacity:.85; }

/* Data table — mirror of .fin-table */
.np-table { width:100%; border-collapse:collapse; font-size:13px; }
.np-table th { background:#f8fafc; padding:9px 10px; text-align:left; font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
.np-table th.num, .np-table td.num { text-align:right; }
.np-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.np-table tbody tr:hover { background:#fafbfc; }
.np-table tbody tr.is-selected { background:#f0faf4; }
.np-table input { width:100%; border:1px solid transparent; padding:5px 7px; border-radius:5px; font-size:13px; font-family:inherit; background:transparent; }
.np-table input:hover  { border-color:#cbd5e1; }
.np-table input:focus  { border-color:#2e9e63; background:#fff; outline:none; box-shadow:0 0 0 3px rgba(46,158,99,.12); }
.np-chk { width:34px; text-align:center; }
.np-chk input[type="checkbox"] { width:15px; height:15px; cursor:pointer; accent-color:#2e9e63; }

/* Pagination — exact mirror of .fin-pagi */
.np-pagi { display:flex; gap:4px; align-items:center; justify-content:center; padding:14px 0; }
.np-pagi button { padding:6px 10px; border:1px solid #cbd5e1; background:#fff; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; min-width:34px; }
.np-pagi button:hover:not(:disabled) { background:#f0faf4; border-color:#2e9e63; color:#2e9e63; }
.np-pagi button:disabled { opacity:0.4; cursor:not-allowed; }
.np-pagi button.is-active { background:#2e9e63; color:#fff; border-color:#2e9e63; }

/* Bulk action bar — exact mirror of .fin-bulk-bar */
.np-bulk-bar {
    position:sticky; bottom:12px; margin:14px auto 0; max-width:680px;
    background:linear-gradient(135deg,#0f172a 0%,#14532d 100%);
    color:#fff; border-radius:14px; padding:12px 16px;
    display:flex; align-items:center; gap:14px;
    box-shadow:0 20px 40px -10px rgba(15,23,42,.45), inset 0 1px 0 rgba(255,255,255,.10);
    z-index:10;
    transform:translateY(120%); opacity:0; pointer-events:none;
    transition:transform .25s cubic-bezier(.16,1,.3,1), opacity .25s;
}
.np-bulk-bar.is-visible { transform:translateY(0); opacity:1; pointer-events:auto; }
.np-bulk-bar .count { display:inline-flex; align-items:center; gap:8px; font-size:13px; font-weight:800; }
.np-bulk-bar .count b { font-size:18px; color:#fbbf24; }
.np-bulk-bar .spacer { flex:1; }
.np-bulk-bar button { padding:8px 14px; border-radius:10px; font-size:12px; font-weight:800; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:6px; transition:background .15s, transform .15s; }
.np-bulk-bar .btn-cancel { background:rgba(255,255,255,.10); color:#fff; border:1px solid rgba(255,255,255,.22); }
.np-bulk-bar .btn-cancel:hover { background:rgba(255,255,255,.18); }
.np-bulk-bar .btn-del { background:linear-gradient(135deg,#f43f5e,#dc2626); color:#fff; box-shadow:0 6px 14px -3px rgba(244,63,94,.5); }
.np-bulk-bar .btn-del:hover { transform:translateY(-1px); }

/* Threshold cards (settings panel) */
.np-thr { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; }
.np-thr label { font-size:11px; font-weight:700; color:#475569; }
.np-thr input { width:100%; margin-top:6px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:7px; font-size:14px; font-weight:700; background:#fff; }
.np-thr .hint { font-size:10.5px; color:#94a3b8; margin-top:4px; font-weight:600; }

/* Source badge (data came from schedule) */
.np-src { display:inline-flex; align-items:center; gap:3px; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:800; background:#dbeafe; color:#1e40af; margin-left:6px; }

/* Cross-dept comparison row */
.np-cross { display:flex; gap:10px; align-items:center; padding:8px 12px; border-radius:8px; background:#f8fafc; font-size:13px; }
.np-cross + .np-cross { margin-top:6px; }
.np-cross .name { flex:1; color:#0f172a; font-weight:700; min-width:0; }
.np-cross .name small { color:#94a3b8; font-weight:600; }
.np-cross .bar  { height:8px; flex:2; background:#e2e8f0; border-radius:99px; overflow:hidden; }
.np-cross .bar > i { display:block; height:100%; background:linear-gradient(90deg,#2e9e63,#14b8a6); transition:width .4s cubic-bezier(.16,1,.3,1); }
.np-cross .val  { min-width:56px; text-align:right; font-weight:900; color:#1f7a4c; font-variant-numeric:tabular-nums; }

/* ── DARK MODE ──────────────────────────────────────────
   Same approach as Cash Book: surface tints + tonal KPIs */
body[data-theme='dark'] .np-card { background:#1e293b !important; border-color:#334155 !important; box-shadow:0 4px 15px rgba(0,0,0,.25); }
body[data-theme='dark'] .np-kpi[data-tone="prod"]    { background:rgba(59,130,246,.15); border-color:rgba(59,130,246,.30); }
body[data-theme='dark'] .np-kpi[data-tone="prod"] .ic    { background:rgba(59,130,246,.25); color:#93c5fd; }
body[data-theme='dark'] .np-kpi[data-tone="visits"]  { background:rgba(46,158,99,.15); border-color:rgba(46,158,99,.30); }
body[data-theme='dark'] .np-kpi[data-tone="visits"] .ic  { background:rgba(46,158,99,.25); color:#6ee7b7; }
body[data-theme='dark'] .np-kpi[data-tone="hours"]   { background:rgba(194,65,12,.18); border-color:rgba(194,65,12,.30); }
body[data-theme='dark'] .np-kpi[data-tone="hours"] .ic   { background:rgba(194,65,12,.30); color:#fdba74; }
body[data-theme='dark'] .np-kpi[data-tone="optimal"] { background:rgba(4,120,87,.18); border-color:rgba(4,120,87,.30); }
body[data-theme='dark'] .np-kpi[data-tone="optimal"] .ic { background:rgba(4,120,87,.30); color:#6ee7b7; }
body[data-theme='dark'] .np-kpi[data-tone="under"]   { background:rgba(244,63,94,.15); border-color:rgba(244,63,94,.30); }
body[data-theme='dark'] .np-kpi[data-tone="under"] .ic   { background:rgba(244,63,94,.25); color:#fb7185; }
body[data-theme='dark'] .np-kpi[data-tone="over"]    { background:rgba(245,158,11,.15); border-color:rgba(245,158,11,.30); }
body[data-theme='dark'] .np-kpi[data-tone="over"] .ic    { background:rgba(245,158,11,.25); color:#fbbf24; }
body[data-theme='dark'] .np-kpi .num { color:#f8fafc; }
body[data-theme='dark'] .np-kpi .num .unit { color:#94a3b8; }
body[data-theme='dark'] .np-kpi .lbl { color:#94a3b8; }
body[data-theme='dark'] .np-kpi .sub { color:#64748b; }
body[data-theme='dark'] .np-delta.up   { background:rgba(46,158,99,.15); color:#6ee7b7; }
body[data-theme='dark'] .np-delta.down { background:rgba(244,63,94,.15); color:#fb7185; }
body[data-theme='dark'] .np-delta.flat { background:rgba(255,255,255,.05); color:#94a3b8; }
body[data-theme='dark'] .np-chip { background:#1e293b; color:#cbd5e1; border-color:#334155; }
body[data-theme='dark'] .np-chip:hover { background:rgba(46,158,99,.15); color:#6ee7b7; border-color:rgba(46,158,99,.30); }
body[data-theme='dark'] .np-tabs { background:#0f172a; }
body[data-theme='dark'] .np-tab:hover { background:#1e293b; }
body[data-theme='dark'] .np-filter-bar label { color:#cbd5e1; }
body[data-theme='dark'] .np-filter-bar input, body[data-theme='dark'] .np-filter-bar select { background:#0f172a; border-color:#334155; color:#f1f5f9; }
body[data-theme='dark'] .np-filter-bar input[type="date"]::-webkit-calendar-picker-indicator { filter:invert(.85); }
body[data-theme='dark'] .np-table th { background:#0f172a; color:#94a3b8; border-bottom-color:#334155; }
body[data-theme='dark'] .np-table td { border-bottom-color:#334155; color:#e2e8f0; }
body[data-theme='dark'] .np-table tbody tr:hover { background:#0f172a; }
body[data-theme='dark'] .np-table input { color:#e2e8f0; }
body[data-theme='dark'] .np-table input:hover { border-color:#475569; }
body[data-theme='dark'] .np-table input:focus { background:#0f172a; }
body[data-theme='dark'] .np-pagi button { background:#1e293b; border-color:#334155; color:#cbd5e1; }
body[data-theme='dark'] .np-pagi button:hover:not(:disabled) { background:rgba(46,158,99,.15); color:#6ee7b7; }
body[data-theme='dark'] .np-banner.is-ok    { background:rgba(46,158,99,.12); border-color:rgba(46,158,99,.30); color:#6ee7b7; }
body[data-theme='dark'] .np-banner.is-under { background:rgba(244,63,94,.12); border-color:rgba(244,63,94,.30); color:#fb7185; }
body[data-theme='dark'] .np-banner.is-over  { background:rgba(245,158,11,.12); border-color:rgba(245,158,11,.30); color:#fbbf24; }
body[data-theme='dark'] .np-banner .ic { background:rgba(0,0,0,.20); }
body[data-theme='dark'] .np-thr { background:#0f172a; border-color:#334155; }
body[data-theme='dark'] .np-thr label { color:#cbd5e1; }
body[data-theme='dark'] .np-thr input { background:#1e293b; border-color:#334155; color:#f1f5f9; }
body[data-theme='dark'] .np-thr .hint { color:#64748b; }
body[data-theme='dark'] .np-src { background:rgba(59,130,246,.20); color:#93c5fd; }
body[data-theme='dark'] .np-cross { background:#0f172a; }
body[data-theme='dark'] .np-cross .name { color:#e2e8f0; }
body[data-theme='dark'] .np-cross .name small { color:#64748b; }
body[data-theme='dark'] .np-cross .bar { background:#334155; }
body[data-theme='dark'] .np-cross .val { color:#6ee7b7; }
body[data-theme='dark'] .np-bulk-bar { box-shadow:0 20px 40px -10px rgba(0,0,0,.6), inset 0 1px 0 rgba(255,255,255,.10); }
</style>

<div class="space-y-4">
    <!-- Header — matches finance.php pattern (h2 + subtitle + actions right) -->
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-user-nurse text-amber-600"></i>
                Productivity พยาบาล OPD
            </h2>
            <p class="text-xs text-slate-500 mt-1">คำนวณภาระงานพยาบาลตามมาตรฐานสภาการพยาบาล · HPV × visits / available hours</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a id="np-template-link" class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm" href="nurse_productivity_template.php" target="_blank" title="ดาวน์โหลด Template Excel">
                <i class="fa-solid fa-file-arrow-down"></i> Template
            </a>
            <button class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm" onclick="document.getElementById('np-import-file').click()" title="นำเข้าจาก Excel">
                <i class="fa-solid fa-file-import"></i> Import
            </button>
            <input type="file" id="np-import-file" accept=".xlsx,.xls,.csv" style="display:none" onchange="npImportExcel(event)">
            <a id="np-print-link" class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm" href="#" target="_blank" title="พิมพ์รายงาน A4">
                <i class="fa-solid fa-print"></i> พิมพ์
            </a>
            <button class="btn-solid bg-amber-500 text-white hover:bg-amber-600 text-sm" onclick="npExportExcel()" title="ส่งออก Excel">
                <i class="fa-solid fa-file-export"></i> Export
            </button>
            <button class="btn-solid bg-brand-500 text-white hover:bg-brand-600 text-sm" onclick="npAddRow()">
                <i class="fa-solid fa-plus"></i> เพิ่มแถว
            </button>
        </div>
    </div>

    <!-- Filter bar: dept + date range + view mode toggle -->
    <div class="np-card">
        <div class="np-filter-bar">
            <div style="min-width:200px">
                <label>หน่วยงาน / แผนก</label>
                <select id="np-dept-select" onchange="npChangeDept()" style="width:100%">
                    <option value="">— เลือกหน่วยงาน —</option>
                </select>
            </div>
            <div><label>ตั้งแต่</label><input type="date" id="np-filter-from" onchange="npRefreshAll()"></div>
            <div><label>ถึง</label><input type="date" id="np-filter-to" onchange="npRefreshAll()"></div>
            <button class="btn-solid bg-slate-700 text-white hover:bg-slate-800 text-sm" onclick="npRefreshAll()">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
            <div style="flex:1"></div>
            <div>
                <label>มุมมอง</label>
                <div class="flex gap-1.5" id="np-view-toggle">
                    <button type="button" class="np-chip is-active" data-view="daily"  onclick="npSetView('daily')">รายวัน</button>
                    <button type="button" class="np-chip"            data-view="monthly" onclick="npSetView('monthly')">รายเดือน</button>
                    <button type="button" class="np-chip"            data-view="yearly"  onclick="npSetView('yearly')">รายปี</button>
                </div>
            </div>
        </div>
    </div>

    <!-- TABS -->
    <nav class="np-tabs">
        <button class="np-tab is-active" data-tab="dashboard" type="button" onclick="npSwitchTab('dashboard')">
            <i class="fa-solid fa-chart-pie"></i> ภาพรวม
        </button>
        <button class="np-tab" data-tab="entry" type="button" onclick="npSwitchTab('entry')">
            <i class="fa-solid fa-table-list"></i> กรอกข้อมูล <span class="np-tab-count" id="np-tab-count-entry">0</span>
        </button>
        <button class="np-tab" data-tab="settings" type="button" onclick="npSwitchTab('settings')">
            <i class="fa-solid fa-gear"></i> ตั้งค่า
        </button>
    </nav>

    <!-- ============ DASHBOARD ============ -->
    <section class="np-tab-content is-active" id="np-tab-dashboard">
        <!-- KPI tiles -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            <div class="np-kpi" data-tone="prod">
                <div class="ic"><i class="fa-solid fa-circle-check"></i></div>
                <div style="min-width:0;flex:1">
                    <div class="num" id="np-kpi-avg">—<span class="unit">%</span></div>
                    <div class="lbl">เฉลี่ย Prod <span class="np-delta" id="np-delta-avg"></span></div>
                    <div class="sub" id="np-kpi-avg-foot">รอข้อมูล</div>
                </div>
            </div>
            <div class="np-kpi" data-tone="visits">
                <div class="ic"><i class="fa-solid fa-user-group"></i></div>
                <div style="min-width:0;flex:1">
                    <div class="num" id="np-kpi-visits">—</div>
                    <div class="lbl">รวมผู้ป่วย <span class="np-delta" id="np-delta-visits"></span></div>
                    <div class="sub" id="np-kpi-visits-foot">visits</div>
                </div>
            </div>
            <div class="np-kpi" data-tone="hours">
                <div class="ic"><i class="fa-solid fa-clock"></i></div>
                <div style="min-width:0;flex:1">
                    <div class="num" id="np-kpi-hours">—</div>
                    <div class="lbl">ชม.พยาบาล <span class="np-delta" id="np-delta-hours"></span></div>
                    <div class="sub" id="np-kpi-hours-foot">รวมในช่วง</div>
                </div>
            </div>
            <div class="np-kpi" data-tone="optimal">
                <div class="ic"><i class="fa-solid fa-check"></i></div>
                <div style="min-width:0;flex:1">
                    <div class="num" id="np-kpi-optimal">—</div>
                    <div class="lbl">Optimal</div>
                    <div class="sub" id="np-kpi-optimal-foot">วันที่ในเกณฑ์</div>
                </div>
            </div>
            <div class="np-kpi" data-tone="under">
                <div class="ic"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div style="min-width:0;flex:1">
                    <div class="num" id="np-kpi-under">—</div>
                    <div class="lbl">Under staff</div>
                    <div class="sub">ภาระงานสูง</div>
                </div>
            </div>
            <div class="np-kpi" data-tone="over">
                <div class="ic"><i class="fa-solid fa-arrow-trend-down"></i></div>
                <div style="min-width:0;flex:1">
                    <div class="num" id="np-kpi-over">—</div>
                    <div class="lbl">Over staff</div>
                    <div class="sub">มีกำลังเหลือ</div>
                </div>
            </div>
        </div>

        <!-- Status banner -->
        <div class="np-banner is-ok mt-3" id="np-banner">
            <div class="ic"><i class="fa-solid fa-circle-info"></i></div>
            <div>
                <b id="np-banner-headline">รอข้อมูลในช่วงเวลาที่เลือก</b>
                <small id="np-banner-subline">เลือกหน่วยงานและช่วงวันที่เพื่อเริ่มต้น</small>
            </div>
        </div>

        <!-- Charts: trend (line, 2/3) + status (donut, 1/3) -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mt-3">
            <div class="np-card lg:col-span-2">
                <div class="flex items-center justify-between mb-2">
                    <div class="font-bold text-slate-700 text-sm">
                        <i class="fa-solid fa-chart-line text-emerald-600"></i> <span id="np-trend-title">แนวโน้ม Productivity รายวัน</span>
                    </div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">เทียบเกณฑ์</div>
                </div>
                <div style="position:relative;height:240px"><canvas id="npTrendChart"></canvas></div>
            </div>
            <div class="np-card">
                <div class="flex items-center justify-between mb-2">
                    <div class="font-bold text-slate-700 text-sm">
                        <i class="fa-solid fa-chart-pie text-amber-600"></i> สัดส่วนสถานะ
                    </div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider" id="np-status-meta">ตามวัน</div>
                </div>
                <div style="position:relative;height:200px"><canvas id="npStatusChart"></canvas></div>
            </div>
        </div>

        <!-- Secondary chart: DOW / monthly bar / yearly comparison -->
        <div class="np-card mt-3">
            <div class="flex items-center justify-between mb-2">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-chart-column text-indigo-600"></i> <span id="np-secondary-title">ผู้ป่วยเฉลี่ยตามวันในสัปดาห์</span>
                </div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider" id="np-secondary-meta">รวมทุกสัปดาห์</div>
            </div>
            <div style="position:relative;height:240px"><canvas id="npSecondaryChart"></canvas></div>
        </div>

        <!-- Cross-dept comparison -->
        <div class="np-card mt-3">
            <div class="flex items-center justify-between mb-2">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-scale-balanced text-fuchsia-600"></i> เปรียบเทียบทุกหน่วยงาน
                </div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Productivity เฉลี่ย</div>
            </div>
            <div id="np-cross-list">
                <div class="text-xs text-slate-400 text-center py-4">รอข้อมูล</div>
            </div>
        </div>
    </section>

    <!-- ============ DATA ENTRY ============ -->
    <section class="np-tab-content" id="np-tab-entry">
        <div class="np-card p-0 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between flex-wrap gap-2">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-pen-to-square text-emerald-600"></i> กรอกข้อมูลรายวัน
                </div>
                <div class="text-xs text-slate-500">RN / หัวหน้า เว้นว่างได้ — ระบบจะดึงจากตารางเวรอัตโนมัติ</div>
            </div>
            <div class="overflow-x-auto">
                <table class="np-table">
                    <thead>
                        <tr>
                            <th class="np-chk"><input type="checkbox" id="np-check-all" onchange="npToggleAll(this)"></th>
                            <th style="width:42px">#</th>
                            <th style="width:130px">วันที่</th>
                            <th style="width:48px">วัน</th>
                            <th class="num" style="width:80px">ผู้ป่วย</th>
                            <th class="num" style="width:90px">RN</th>
                            <th class="num" style="width:90px">หัวหน้า</th>
                            <th class="num" style="width:84px">ชม./เวร</th>
                            <th class="num" style="width:90px">ชม.ต้องการ</th>
                            <th class="num" style="width:90px">ชม.ที่มี</th>
                            <th class="num" style="width:80px">Prod %</th>
                            <th style="width:88px">สถานะ</th>
                            <th>หมายเหตุ</th>
                            <th class="np-chk"></th>
                        </tr>
                    </thead>
                    <tbody id="np-entry-body"></tbody>
                </table>
            </div>
            <div class="px-4 py-2 border-t border-slate-200 flex items-center justify-between flex-wrap gap-2">
                <span class="text-xs text-slate-500" id="np-page-info">— รายการ</span>
                <div class="np-pagi" id="np-page-btns"></div>
            </div>
        </div>

        <!-- Sticky bulk action bar -->
        <div class="np-bulk-bar" id="np-bulk-bar" role="region" aria-label="การจัดการรายการที่เลือก">
            <span class="count"><i class="fa-solid fa-square-check"></i> เลือก <b id="np-bulk-count">0</b> รายการ</span>
            <span class="spacer"></span>
            <button class="btn-cancel" onclick="npClearSelection()">ยกเลิก</button>
            <button class="btn-del" onclick="npBulkDelete()"><i class="fa-solid fa-trash"></i> ลบ</button>
        </div>
    </section>

    <!-- ============ SETTINGS ============ -->
    <section class="np-tab-content" id="np-tab-settings">
        <div class="np-card">
            <div class="flex items-center justify-between mb-3">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-hospital text-emerald-600"></i> ข้อมูลโรงพยาบาล
                </div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">แสดงบนหัวรายงาน + Export</div>
            </div>
            <div class="np-filter-bar" style="gap:12px">
                <div style="flex:2;min-width:280px">
                    <label>ชื่อโรงพยาบาล</label>
                    <input type="text" id="np-set-name" placeholder="เช่น โรงพยาบาลชุมชน..." style="width:100%">
                </div>
                <div style="min-width:120px"><label>ระดับ Service Plan</label>
                    <select id="np-set-level" style="width:100%">
                        <option>A</option><option>S</option><option>M1</option><option>M2</option>
                        <option>F1</option><option selected>F2</option><option>F3</option>
                    </select>
                </div>
                <div style="min-width:110px"><label>จำนวนเตียง</label><input type="number" id="np-set-beds" placeholder="30" style="width:100%"></div>
                <div style="flex:1;min-width:140px"><label>จังหวัด</label><input type="text" id="np-set-province" style="width:100%"></div>
                <div style="flex:1;min-width:160px"><label>ผู้อำนวยการ</label><input type="text" id="np-set-director" style="width:100%"></div>
                <div style="min-width:120px"><label>รหัส MOPH</label><input type="text" id="np-set-moph" maxlength="5" style="width:100%"></div>
                <div style="flex:1;min-width:140px"><label>ช่วงเดือน/ปี</label><input type="text" id="np-set-period" placeholder="เมษายน 2569" style="width:100%"></div>
            </div>
            <div class="text-right mt-3">
                <button class="btn-solid bg-brand-500 text-white hover:bg-brand-600 text-sm" onclick="npSaveSettings()">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึก
                </button>
            </div>
        </div>

        <div class="np-card mt-3">
            <div class="flex items-center justify-between mb-3">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-sliders text-amber-600"></i> เกณฑ์มาตรฐานการคำนวณ
                </div>
                <button class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-xs" onclick="npResetThresholds()">
                    <i class="fa-solid fa-rotate-left"></i> คืนค่าเริ่มต้น
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="np-thr">
                    <label>ชม.พยาบาล / Visit</label>
                    <input type="number" id="np-set-hpv" step="0.01" value="0.24">
                    <div class="hint">มาตรฐาน 0.24 ชม.</div>
                </div>
                <div class="np-thr">
                    <label>ชม.ทำงาน / เวร</label>
                    <input type="number" id="np-set-shift" step="0.5" value="7">
                    <div class="hint">มาตรฐาน 7 ชม.</div>
                </div>
                <div class="np-thr">
                    <label>เกณฑ์ล่าง %</label>
                    <input type="number" id="np-set-thr-low" step="1" value="80">
                    <div class="hint">Over staff &lt; ค่านี้</div>
                </div>
                <div class="np-thr">
                    <label>เกณฑ์บน %</label>
                    <input type="number" id="np-set-thr-high" step="1" value="110">
                    <div class="hint">Under staff &gt; ค่านี้</div>
                </div>
            </div>
            <p class="text-[11px] text-slate-500 mt-3 flex items-center gap-1">
                <i class="fa-solid fa-circle-info"></i>
                อ้างอิง: มาตรฐานการพยาบาลในโรงพยาบาล ฉบับปรับปรุงครั้งที่ 4 (พ.ศ. 2551) สภาการพยาบาล
            </p>
            <div class="text-right mt-3">
                <button class="btn-solid bg-brand-500 text-white hover:bg-brand-600 text-sm" onclick="npSaveSettings()">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึกเกณฑ์
                </button>
            </div>
        </div>

        <div class="np-card mt-3">
            <div class="flex items-center justify-between mb-3">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-database text-indigo-600"></i> จัดการข้อมูล
                </div>
            </div>
            <div class="flex items-center justify-between p-3 rounded-lg bg-slate-50 flex-wrap gap-2">
                <div>
                    <div class="font-bold text-sm text-slate-700">ส่งออกข้อมูลทั้งหมด (Excel)</div>
                    <div class="text-xs text-slate-500 mt-0.5">ดาวน์โหลด .xlsx ครอบคลุมข้อมูลในช่วงที่เลือก</div>
                </div>
                <button class="btn-solid bg-amber-500 text-white hover:bg-amber-600 text-sm" onclick="npExportExcel()">
                    <i class="fa-solid fa-download"></i> ดาวน์โหลด
                </button>
            </div>
            <div class="flex items-center justify-between p-3 rounded-lg mt-2 flex-wrap gap-2" style="background:#fef2f2;border:1px solid #fecaca">
                <div>
                    <div class="font-bold text-sm" style="color:#b91c1c">ล้างข้อมูลทั้งหมดของหน่วยงานนี้</div>
                    <div class="text-xs mt-0.5" style="color:#dc2626">ลบทุก record ของหน่วยงานปัจจุบัน — ทำไม่ได้หลังดำเนินการ</div>
                </div>
                <button class="btn-solid bg-rose-500 text-white hover:bg-rose-600 text-sm" onclick="npFactoryReset()">
                    <i class="fa-solid fa-triangle-exclamation"></i> ล้างข้อมูล
                </button>
            </div>
        </div>
    </section>
</div>

<script>
/* ========================================================================
   NURSE PRODUCTIVITY — frontend
   ======================================================================== */
(function(){
  const AJAX = 'ajax_nurse_productivity.php';
  const CSRF = '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES) ?>';
  const PAGE_SIZE = 20;

  const state = {
    deptId: 0,
    deptName: '',
    view: 'daily',
    settings: null,
    rows: [],
    selected: new Set(),
    page: 1,
    depts: [],
    charts: { trend:null, status:null, secondary:null },
  };
  window.__np = state;

  /* ---------- utils ---------- */
  const $ = (id) => document.getElementById(id);
  const fmt = (n, d=0) => (n == null || isNaN(n)) ? '—' : Number(n).toLocaleString('th-TH', { minimumFractionDigits:d, maximumFractionDigits:d });
  const fmt1 = (n) => fmt(n, 1);
  const toISODate = (d) => { const z=(x)=>String(x).padStart(2,'0'); return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`; };
  const THAI_DAYS = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
  const THAI_DAYS_FULL = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
  const MONTH_TH = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

  function chartTheme() {
    const dark = document.body.getAttribute('data-theme') === 'dark';
    return {
      tick:   dark ? '#cbd5e1' : '#64748b',
      grid:   dark ? 'rgba(241,245,249,.08)' : 'rgba(15,23,42,.06)',
      legend: dark ? '#e2e8f0' : '#334155',
      border: dark ? '#1e293b' : '#fff',
    };
  }

  async function api(action, params = {}, method = 'GET') {
    const opts = { method };
    let url = `${AJAX}?action=${encodeURIComponent(action)}`;
    if (method === 'GET') {
      const qs = new URLSearchParams(params).toString();
      if (qs) url += '&' + qs;
    } else {
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      Object.entries(params).forEach(([k,v]) => fd.append(k, v == null ? '' : v));
      opts.body = fd;
    }
    const res = await fetch(url, opts);
    const json = await res.json();
    if (!json.ok) throw new Error(json.message || 'เกิดข้อผิดพลาด');
    return json;
  }

  function toast(msg, icon='success') {
    if (typeof Swal === 'undefined') { alert(msg); return; }
    Swal.fire({ icon, title: msg, toast:true, position:'top-end', timer:2200, showConfirmButton:false });
  }
  function errAlert(e) {
    const msg = (e && e.message) ? e.message : 'เกิดข้อผิดพลาด';
    if (typeof Swal === 'undefined') { alert(msg); return; }
    Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text: msg });
  }

  /* ---------- init ---------- */
  async function init() {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    $('np-filter-from').value = toISODate(first);
    $('np-filter-to').value = toISODate(now);

    try {
      const r = await api('depts:list');
      state.depts = r.data || [];
      const sel = $('np-dept-select');
      sel.innerHTML = '<option value="">— เลือกหน่วยงาน —</option>' +
        state.depts.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');
      if (state.depts.length) {
        sel.value = state.depts[0].id;
        state.deptId = +state.depts[0].id;
        state.deptName = state.depts[0].name;
        await refreshAll();
      }
    } catch (e) { errAlert(e); }
  }

  window.npChangeDept = async function() {
    const id = +$('np-dept-select').value || 0;
    state.deptId = id;
    state.deptName = state.depts.find(d => +d.id === id)?.name || '';
    state.selected.clear();
    state.page = 1;
    if (!id) return;
    await refreshAll();
  };

  window.npSetView = function(v) {
    state.view = v;
    document.querySelectorAll('#np-view-toggle .np-chip').forEach(b => b.classList.toggle('is-active', b.dataset.view === v));
    refreshDashboard();
  };

  window.npRefreshAll = refreshAll;
  async function refreshAll() {
    if (!state.deptId) return;
    try {
      const r = await api('daily:list', { dept_id: state.deptId, from: $('np-filter-from').value, to: $('np-filter-to').value });
      state.rows = r.data || [];
      // UI sort newest-first (backend stays ASC for export/print/charts).
      // Tie-breaker by id DESC so a row added today appears above older same-day rows.
      state.rows.sort((a, b) => {
        if (a.entry_date !== b.entry_date) return a.entry_date < b.entry_date ? 1 : -1;
        return (+b.id || 0) - (+a.id || 0);
      });
      state.settings = r.settings;
      fillSettingsForm();
      $('np-tab-count-entry').textContent = state.rows.length;
      updateLinks();
      renderTable();
      await refreshDashboard();
    } catch (e) { errAlert(e); }
  }

  function updateLinks() {
    const q = new URLSearchParams({
      dept_id: state.deptId,
      from: $('np-filter-from').value || '',
      to:   $('np-filter-to').value || '',
    }).toString();
    $('np-print-link').href = `nurse_productivity_print.php?${q}`;
  }

  /* ---------- DASHBOARD ---------- */
  async function refreshDashboard() {
    if (state.view === 'daily')   return refreshDaily();
    if (state.view === 'monthly') return refreshMonthly();
    if (state.view === 'yearly')  return refreshYearly();
  }

  async function refreshDaily() {
    $('np-trend-title').textContent = 'แนวโน้ม Productivity รายวัน';
    $('np-secondary-title').textContent = 'ผู้ป่วยเฉลี่ยตามวันในสัปดาห์';
    $('np-secondary-meta').textContent = 'รวมทุกสัปดาห์';
    $('np-status-meta').textContent = 'ตามวัน';

    try {
      const r = await api('analytics:summary', {
        dept_id: state.deptId,
        from: $('np-filter-from').value,
        to:   $('np-filter-to').value,
      });
      const s = r.summary;
      if (!s.count) { clearDashboard(); return; }

      $('np-kpi-avg').innerHTML = fmt1(s.avgProd) + '<span class="unit">%</span>';
      $('np-kpi-visits').textContent = fmt(s.totalVisits);
      $('np-kpi-hours').textContent = fmt(s.totalNeeded);
      $('np-kpi-optimal').textContent = s.optimal;
      $('np-kpi-under').textContent = s.under;
      $('np-kpi-over').textContent = s.over;
      $('np-kpi-avg-foot').textContent = `${s.count} วัน`;
      $('np-kpi-visits-foot').textContent = `เฉลี่ย ${fmt(s.totalVisits / s.count)} คน/วัน`;
      $('np-kpi-hours-foot').textContent = `ต้องการ ${fmt1(s.totalNeeded / s.count)} ชม./วัน`;
      $('np-kpi-optimal-foot').textContent = `${Math.round(s.optimal / s.count * 100)}% ของทั้งหมด`;

      renderDelta('np-delta-avg',    r.delta?.avgProd);
      renderDelta('np-delta-visits', r.delta?.totalVisits);
      renderDelta('np-delta-hours',  r.delta?.totalNeeded);

      updateBanner(s);
      drawTrendChart(r.labels, r.prods);
      drawStatusChart(s.optimal, s.under, s.over);
      drawSecondaryChart_DOW(r.dowAvg);
      loadCrossDept();
    } catch (e) { errAlert(e); }
  }

  async function refreshMonthly() {
    $('np-trend-title').textContent = 'แนวโน้ม Productivity รายเดือน (12 เดือน)';
    $('np-secondary-title').textContent = 'ผู้ป่วยรวมรายเดือน';
    $('np-secondary-meta').textContent = 'visits/เดือน';
    $('np-status-meta').textContent = 'ตามเดือน';

    try {
      const r = await api('analytics:rollup_monthly', { dept_id: state.deptId });
      const months = r.months || [];
      if (!months.length) { clearDashboard(); return; }
      const totalVisits = months.reduce((a,m) => a + m.visits, 0);
      const totalNeeded = months.reduce((a,m) => a + m.needed, 0);
      const avgProd = months.reduce((a,m) => a + m.avgProd, 0) / months.length;
      const days   = months.reduce((a,m) => a + m.days, 0);

      $('np-kpi-avg').innerHTML = fmt1(avgProd) + '<span class="unit">%</span>';
      $('np-kpi-visits').textContent = fmt(totalVisits);
      $('np-kpi-hours').textContent = fmt(totalNeeded);
      $('np-kpi-optimal').textContent = months.length;
      $('np-kpi-under').textContent = '—';
      $('np-kpi-over').textContent = '—';
      $('np-kpi-avg-foot').textContent = `${months.length} เดือน · ${days} วันบันทึก`;
      $('np-kpi-visits-foot').textContent = `เฉลี่ย ${fmt(totalVisits / months.length)} คน/เดือน`;
      $('np-kpi-hours-foot').textContent = `รวม ${fmt(totalNeeded)} ชม.`;
      $('np-kpi-optimal-foot').textContent = `เดือนที่บันทึก`;
      ['np-delta-avg','np-delta-visits','np-delta-hours'].forEach(id => { const el=$(id); if(el){ el.classList.add('hidden'); el.textContent=''; } });

      updateBanner({ avgProd, count: months.length });

      const labels = months.map(m => {
        const [y, mm] = m.ym.split('-');
        return MONTH_TH[+mm-1] + ' ' + ((+y) + 543 - 2500);
      });
      drawTrendChart(labels, months.map(m => m.avgProd));
      drawStatusChart_avg(avgProd);
      drawMonthlyBarChart(labels, months.map(m => m.visits));
      loadCrossDept();
    } catch (e) { errAlert(e); }
  }

  async function refreshYearly() {
    $('np-trend-title').textContent = 'แนวโน้ม Productivity รายปี';
    $('np-secondary-title').textContent = 'ผู้ป่วยรวมรายปี';
    $('np-secondary-meta').textContent = 'visits/ปี';
    $('np-status-meta').textContent = 'ตามปี';

    try {
      const r = await api('analytics:rollup_yearly', { dept_id: state.deptId });
      const years = r.years || [];
      if (!years.length) { clearDashboard(); return; }
      const totalVisits = years.reduce((a,m) => a + m.visits, 0);
      const totalNeeded = years.reduce((a,m) => a + m.needed, 0);
      const avgProd = years.reduce((a,m) => a + m.avgProd, 0) / years.length;
      const days   = years.reduce((a,m) => a + m.days, 0);

      $('np-kpi-avg').innerHTML = fmt1(avgProd) + '<span class="unit">%</span>';
      $('np-kpi-visits').textContent = fmt(totalVisits);
      $('np-kpi-hours').textContent = fmt(totalNeeded);
      $('np-kpi-optimal').textContent = years.length;
      $('np-kpi-under').textContent = '—';
      $('np-kpi-over').textContent = '—';
      $('np-kpi-avg-foot').textContent = `${years.length} ปี · ${days} วันบันทึก`;
      $('np-kpi-visits-foot').textContent = `เฉลี่ย ${fmt(totalVisits / years.length)} คน/ปี`;
      $('np-kpi-hours-foot').textContent = `รวม ${fmt(totalNeeded)} ชม.`;
      $('np-kpi-optimal-foot').textContent = `ปีที่บันทึก`;
      ['np-delta-avg','np-delta-visits','np-delta-hours'].forEach(id => { const el=$(id); if(el){ el.classList.add('hidden'); el.textContent=''; } });

      updateBanner({ avgProd, count: years.length });

      const labels = years.map(y => 'ปี ' + y.yearBE);
      drawTrendChart(labels, years.map(y => y.avgProd));
      drawStatusChart_avg(avgProd);
      drawMonthlyBarChart(labels, years.map(y => y.visits));
      loadCrossDept();
    } catch (e) { errAlert(e); }
  }

  function clearDashboard() {
    ['np-kpi-avg','np-kpi-visits','np-kpi-hours','np-kpi-optimal','np-kpi-under','np-kpi-over'].forEach(id => $(id).textContent='—');
    $('np-kpi-avg').innerHTML = '—<span class="unit">%</span>';
    ['np-delta-avg','np-delta-visits','np-delta-hours'].forEach(id => { const el=$(id); if(el){ el.classList.add('hidden'); el.textContent=''; } });
    updateBanner(null);
    Object.values(state.charts).forEach(c => { if (c) c.destroy(); });
    state.charts = { trend:null, status:null, secondary:null };
    $('np-cross-list').innerHTML = '<div class="text-xs text-slate-400 text-center py-4">รอข้อมูล</div>';
  }

  function renderDelta(elId, pct) {
    const el = $(elId);
    if (!el) return;
    el.classList.remove('up','down','flat','hidden');
    if (pct == null || isNaN(pct)) { el.classList.add('hidden'); el.textContent=''; return; }
    if (Math.abs(pct) < 0.5)       { el.classList.add('flat'); el.textContent = '~0%'; }
    else if (pct > 0)              { el.classList.add('up');   el.textContent = '▲ ' + Math.round(pct) + '%'; }
    else                           { el.classList.add('down'); el.textContent = '▼ ' + Math.round(Math.abs(pct)) + '%'; }
  }

  function updateBanner(s) {
    const banner = $('np-banner');
    const headline = $('np-banner-headline');
    const subline  = $('np-banner-subline');
    banner.classList.remove('is-ok','is-under','is-over');
    if (!s || !s.avgProd) {
      banner.classList.add('is-ok');
      headline.textContent = 'รอข้อมูลในช่วงเวลาที่เลือก';
      subline.textContent  = 'เลือกหน่วยงานและช่วงวันที่เพื่อเริ่มต้น';
      return;
    }
    const low  = +(state.settings?.threshold_low)  || 80;
    const high = +(state.settings?.threshold_high) || 110;
    const avg = s.avgProd;
    if (avg < low) {
      banner.classList.add('is-over');
      headline.textContent = 'พบความเสี่ยง: มีกำลังพยาบาลเหลือเฉลี่ย';
      subline.textContent  = `เฉลี่ย ${fmt1(avg)}% ต่ำกว่าเกณฑ์ ${low}% — พิจารณาจัดสรรกำลังให้เหมาะกับภาระงาน`;
    } else if (avg > high) {
      banner.classList.add('is-under');
      headline.textContent = 'พบความเสี่ยง: ภาระงานเกินกำลัง';
      subline.textContent  = `เฉลี่ย ${fmt1(avg)}% สูงกว่าเกณฑ์ ${high}% — ควรเสริมอัตรากำลัง`;
    } else {
      banner.classList.add('is-ok');
      headline.textContent = 'ผลการดำเนินงานอยู่ในเกณฑ์ดี';
      subline.textContent  = `เฉลี่ย ${fmt1(avg)}% อยู่ระหว่าง ${low}–${high}% ตามมาตรฐาน`;
    }
  }

  /* ---------- CHARTS ---------- */
  function destroyChart(name) { if (state.charts[name]) { state.charts[name].destroy(); state.charts[name] = null; } }

  function drawTrendChart(labels, data) {
    destroyChart('trend');
    const ctx = $('npTrendChart').getContext('2d');
    const t = chartTheme();
    const low  = +(state.settings?.threshold_low)  || 80;
    const high = +(state.settings?.threshold_high) || 110;
    state.charts.trend = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Productivity %', data, borderColor:'#2e9e63', backgroundColor:'rgba(46,158,99,.12)', fill:true, tension:.35, pointRadius:3, pointHoverRadius:5 },
          { label: 'เกณฑ์บน', data: labels.map(()=>high), borderColor:'#ef4444', borderDash:[5,5], pointRadius:0, fill:false, tension:0 },
          { label: 'เกณฑ์ล่าง', data: labels.map(()=>low), borderColor:'#f59e0b', borderDash:[5,5], pointRadius:0, fill:false, tension:0 },
        ]
      },
      options: {
        responsive:true, maintainAspectRatio:false,
        scales: {
          x: { ticks:{ color:t.tick }, grid:{ color:t.grid } },
          y: { ticks:{ color:t.tick, callback:v=>v+'%' }, grid:{ color:t.grid }, beginAtZero:true }
        },
        plugins: { legend: { labels:{ color:t.legend, font:{size:11} } } }
      }
    });
  }

  function drawStatusChart(opt, und, ovr) {
    destroyChart('status');
    const ctx = $('npStatusChart').getContext('2d');
    const t = chartTheme();
    state.charts.status = new Chart(ctx, {
      type:'doughnut',
      data: { labels:['Optimal','Under staff','Over staff'], datasets: [{
        data:[opt, und, ovr],
        backgroundColor:['#2e9e63','#ef4444','#f59e0b'],
        borderColor: t.border, borderWidth:2
      }]},
      options: { responsive:true, maintainAspectRatio:false, plugins: { legend:{ position:'bottom', labels:{ color:t.legend, font:{size:11}, padding:10 } } } }
    });
  }

  function drawStatusChart_avg(avg) {
    destroyChart('status');
    const ctx = $('npStatusChart').getContext('2d');
    const t = chartTheme();
    const low  = +(state.settings?.threshold_low)  || 80;
    const high = +(state.settings?.threshold_high) || 110;
    const ratio = Math.min(100, avg);
    const color = avg < low ? '#f59e0b' : (avg > high ? '#ef4444' : '#2e9e63');
    state.charts.status = new Chart(ctx, {
      type:'doughnut',
      data: { labels:['Productivity','—'], datasets:[{
        data:[ratio, Math.max(0, 100-ratio)],
        backgroundColor:[color, t.grid.includes('rgba(241') ? '#334155' : '#e2e8f0'],
        borderColor:t.border, borderWidth:2
      }] },
      options: { responsive:true, maintainAspectRatio:false, cutout:'70%', plugins:{ legend:{ display:false } } }
    });
  }

  function drawSecondaryChart_DOW(dowAvg) {
    destroyChart('secondary');
    const ctx = $('npSecondaryChart').getContext('2d');
    const t = chartTheme();
    state.charts.secondary = new Chart(ctx, {
      type:'bar',
      data: { labels:THAI_DAYS_FULL, datasets:[{
        label:'ผู้ป่วยเฉลี่ย', data: dowAvg.map(n => Math.round(n)),
        backgroundColor:['#fde68a','#bfdbfe','#a7f3d0','#fcd34d','#86efac','#c4b5fd','#fbcfe8']
      }] },
      options: { responsive:true, maintainAspectRatio:false,
        scales: { x:{ ticks:{color:t.tick}, grid:{color:t.grid} }, y:{ ticks:{color:t.tick}, grid:{color:t.grid}, beginAtZero:true } },
        plugins: { legend:{ display:false } }
      }
    });
  }

  function drawMonthlyBarChart(labels, data) {
    destroyChart('secondary');
    const ctx = $('npSecondaryChart').getContext('2d');
    const t = chartTheme();
    state.charts.secondary = new Chart(ctx, {
      type:'bar',
      data: { labels, datasets:[{ label:'ผู้ป่วยรวม', data, backgroundColor:'#6366f1' }] },
      options: { responsive:true, maintainAspectRatio:false,
        scales: { x:{ ticks:{color:t.tick}, grid:{color:t.grid} }, y:{ ticks:{color:t.tick}, grid:{color:t.grid}, beginAtZero:true } },
        plugins: { legend:{ display:false } }
      }
    });
  }

  async function loadCrossDept() {
    try {
      const r = await api('analytics:cross_dept', { from: $('np-filter-from').value, to: $('np-filter-to').value });
      const list = $('np-cross-list');
      const depts = (r.depts || []).filter(d => d.visits > 0);
      if (!depts.length) { list.innerHTML = '<div class="text-xs text-slate-400 text-center py-4">ยังไม่มีหน่วยงานอื่นที่มีข้อมูลในช่วงนี้</div>'; return; }
      const max = Math.max(...depts.map(d => d.avgProd), 1);
      list.innerHTML = depts.map(d => `
        <div class="np-cross">
          <div class="name">${escapeHtml(d.deptName)} <small>· ${d.days} วัน · ${fmt(d.visits)} visits</small></div>
          <div class="bar"><i style="width:${Math.min(100,(d.avgProd/max)*100)}%"></i></div>
          <div class="val">${fmt1(d.avgProd)}%</div>
        </div>
      `).join('');
    } catch (e) { /* silent */ }
  }

  /* ---------- TABLE ---------- */
  function renderTable() {
    const tbody = $('np-entry-body');
    const total = state.rows.length;
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (state.page > totalPages) state.page = totalPages;
    const startIdx = (state.page - 1) * PAGE_SIZE;
    const slice = state.rows.slice(startIdx, startIdx + PAGE_SIZE);

    if (!slice.length) {
      tbody.innerHTML = `<tr><td colspan="14" class="text-center text-slate-400 py-10 text-sm">ยังไม่มีข้อมูล — กด "เพิ่มแถว" เพื่อเริ่ม</td></tr>`;
    } else {
      tbody.innerHTML = slice.map((r, i) => {
        const dow = THAI_DAYS[new Date(r.entry_date).getDay()];
        const isChecked = state.selected.has(r.id) ? 'checked' : '';
        const isSel     = state.selected.has(r.id) ? 'is-selected' : '';
        const statusCls = r.status === 'optimal' ? 'ds-pill-brand' : (r.status === 'under' ? 'ds-pill-rose' : 'ds-pill-amber');
        const statusLbl = r.status === 'optimal' ? 'Optimal' : (r.status === 'under' ? 'Under' : 'Over');
        const rnSrcBadge   = r.rn_source === 'schedule' ? '<span class="np-src"><i class="fa-regular fa-calendar"></i> เวร</span>' : '';
        const headSrcBadge = r.head_source === 'schedule' ? '<span class="np-src"><i class="fa-regular fa-calendar"></i> เวร</span>' : '';
        return `
          <tr data-id="${r.id}" class="${isSel}">
            <td class="np-chk"><input type="checkbox" ${isChecked} onchange="npToggleRow(${r.id}, this.checked)"></td>
            <td class="text-slate-400">${startIdx + i + 1}</td>
            <td><input type="date" value="${r.entry_date}" onchange="npRowEdit(${r.id}, 'entry_date', this.value)"></td>
            <td class="text-slate-500">${dow}</td>
            <td class="num"><input type="number" min="0" value="${r.patients}" onchange="npRowEdit(${r.id}, 'patients', this.value)"></td>
            <td class="num"><input type="number" min="0" value="${r.rn_count}" onchange="npRowEdit(${r.id}, 'rn_count', this.value)">${rnSrcBadge}</td>
            <td class="num"><input type="number" min="0" value="${r.head_count}" onchange="npRowEdit(${r.id}, 'head_count', this.value)">${headSrcBadge}</td>
            <td class="num"><input type="number" min="0.5" step="0.5" value="${r.shift_hours}" onchange="npRowEdit(${r.id}, 'shift_hours', this.value)"></td>
            <td class="num text-slate-600">${fmt1(r.needed)}</td>
            <td class="num text-slate-600">${fmt1(r.available)}</td>
            <td class="num font-black text-slate-800">${fmt1(r.prod)}%</td>
            <td><span class="${statusCls}">${statusLbl}</span></td>
            <td><input type="text" placeholder="—" value="${escapeAttr(r.note||'')}" onchange="npRowEdit(${r.id}, 'note', this.value)"></td>
            <td class="np-chk"><button type="button" class="btn-solid bg-rose-100 text-rose-700 hover:bg-rose-200 text-xs" style="padding:4px 8px" onclick="npDeleteRow(${r.id})" title="ลบ"><i class="fa-solid fa-trash"></i></button></td>
          </tr>
        `;
      }).join('');
    }

    $('np-page-info').textContent = `หน้า ${state.page} / ${totalPages} · รวม ${total} รายการ`;
    renderPaginationBtns(totalPages);
    updateBulkBar();
  }

  function renderPaginationBtns(totalPages) {
    const wrap = $('np-page-btns');
    const cur = state.page;
    const btns = [];
    btns.push(`<button onclick="npGoto(1)" ${cur===1?'disabled':''}>«</button>`);
    btns.push(`<button onclick="npGoto(${cur-1})" ${cur===1?'disabled':''}>‹</button>`);
    const from = Math.max(1, cur - 2), to = Math.min(totalPages, cur + 2);
    for (let p = from; p <= to; p++) btns.push(`<button class="${p===cur?'is-active':''}" onclick="npGoto(${p})">${p}</button>`);
    btns.push(`<button onclick="npGoto(${cur+1})" ${cur===totalPages?'disabled':''}>›</button>`);
    btns.push(`<button onclick="npGoto(${totalPages})" ${cur===totalPages?'disabled':''}>»</button>`);
    wrap.innerHTML = btns.join('');
  }
  window.npGoto = (p) => { state.page = p; renderTable(); };

  window.npToggleRow = (id, checked) => {
    if (checked) state.selected.add(id); else state.selected.delete(id);
    renderTable();
  };
  window.npToggleAll = (cb) => {
    if (cb.checked) document.querySelectorAll('#np-entry-body tr[data-id]').forEach(tr => state.selected.add(+tr.dataset.id));
    else state.selected.clear();
    renderTable();
  };
  window.npClearSelection = () => { state.selected.clear(); $('np-check-all').checked = false; renderTable(); };

  function updateBulkBar() {
    const bar = $('np-bulk-bar');
    $('np-bulk-count').textContent = state.selected.size;
    bar.classList.toggle('is-visible', state.selected.size > 0);
  }

  /* ---------- ROW CRUD ---------- */
  window.npAddRow = async function() {
    if (!state.deptId) { toast('กรุณาเลือกหน่วยงานก่อน', 'warning'); return; }
    const today = toISODate(new Date());
    try {
      const r = await api('daily:create', { dept_id: state.deptId, entry_date: today, patients: 0, rn_count: '', head_count: '', shift_hours: state.settings?.shift_hours || 7, note: '' }, 'POST');
      toast(r.rn_source === 'schedule' ? 'เพิ่มแถว — RN/หัวหน้าดึงจากตารางเวร' : 'เพิ่มแถวสำเร็จ');
      // Jump to page 1 so the new row (now sorted newest-first) is immediately visible
      state.page = 1;
      await refreshAll();
      // Auto-focus the first editable input of the newly-added row for fast data entry
      requestAnimationFrame(() => {
        const firstRow = document.querySelector('#np-entry-body tr[data-id]');
        if (!firstRow) return;
        firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstRow.classList.add('np-row-flash');
        setTimeout(() => firstRow.classList.remove('np-row-flash'), 1200);
        const firstInput = firstRow.querySelector('input[type="number"], input[type="text"], input[type="date"]');
        if (firstInput) firstInput.focus();
      });
    } catch (e) { errAlert(e); }
  };

  window.npRowEdit = async function(id, field, val) {
    const row = state.rows.find(r => +r.id === +id);
    if (!row) return;
    const payload = {
      id: id, dept_id: state.deptId,
      entry_date: field === 'entry_date' ? val : row.entry_date,
      patients:   field === 'patients'   ? val : row.patients,
      rn_count:   field === 'rn_count'   ? val : row.rn_count,
      head_count: field === 'head_count' ? val : row.head_count,
      shift_hours: field === 'shift_hours' ? val : row.shift_hours,
      note:       field === 'note'       ? val : row.note,
    };
    try {
      await api('daily:update', payload, 'POST');
      await refreshAll();
    } catch (e) { errAlert(e); }
  };

  window.npDeleteRow = async function(id) {
    const r = await Swal.fire({ icon:'warning', title:'ลบรายการนี้?', showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก', confirmButtonColor:'#ef4444' });
    if (!r.isConfirmed) return;
    try {
      await api('daily:delete', { dept_id: state.deptId, id }, 'POST');
      state.selected.delete(id);
      toast('ลบสำเร็จ');
      await refreshAll();
    } catch (e) { errAlert(e); }
  };

  window.npBulkDelete = async function() {
    if (!state.selected.size) return;
    const r = await Swal.fire({ icon:'warning', title:`ลบ ${state.selected.size} รายการ?`, showCancelButton:true, confirmButtonText:'ลบทั้งหมด', cancelButtonText:'ยกเลิก', confirmButtonColor:'#ef4444' });
    if (!r.isConfirmed) return;
    try {
      await api('daily:bulk_delete', { dept_id: state.deptId, ids: JSON.stringify([...state.selected]) }, 'POST');
      state.selected.clear();
      toast('ลบสำเร็จ');
      await refreshAll();
    } catch (e) { errAlert(e); }
  };

  /* ---------- SETTINGS form ---------- */
  function fillSettingsForm() {
    if (!state.settings) return;
    const s = state.settings;
    $('np-set-name').value = s.hospital_name || '';
    $('np-set-level').value = s.level || 'F2';
    $('np-set-beds').value = s.beds || '';
    $('np-set-province').value = s.province || '';
    $('np-set-director').value = s.director || '';
    $('np-set-moph').value = s.moph_code || '';
    $('np-set-period').value = s.period_label || '';
    $('np-set-hpv').value = s.hpv;
    $('np-set-shift').value = s.shift_hours;
    $('np-set-thr-low').value = s.threshold_low;
    $('np-set-thr-high').value = s.threshold_high;
  }
  window.npSaveSettings = async function() {
    if (!state.deptId) { toast('เลือกหน่วยงานก่อน', 'warning'); return; }
    try {
      await api('settings:save', {
        dept_id: state.deptId,
        hospital_name: $('np-set-name').value,
        level: $('np-set-level').value,
        beds: $('np-set-beds').value,
        province: $('np-set-province').value,
        director: $('np-set-director').value,
        moph_code: $('np-set-moph').value,
        period_label: $('np-set-period').value,
        hpv: $('np-set-hpv').value,
        shift_hours: $('np-set-shift').value,
        threshold_low: $('np-set-thr-low').value,
        threshold_high: $('np-set-thr-high').value,
      }, 'POST');
      toast('บันทึกการตั้งค่าสำเร็จ');
      await refreshAll();
    } catch (e) { errAlert(e); }
  };
  window.npResetThresholds = () => {
    $('np-set-hpv').value = 0.24;
    $('np-set-shift').value = 7;
    $('np-set-thr-low').value = 80;
    $('np-set-thr-high').value = 110;
    toast('คืนค่าเริ่มต้นแล้ว — กดบันทึกเพื่อยืนยัน', 'info');
  };

  window.npFactoryReset = async function() {
    const r = await Swal.fire({
      icon:'warning', title:'ล้างข้อมูลทั้งหมดของหน่วยงานนี้?',
      text: `จะลบทุกรายการของ "${state.deptName}" — ทำไม่ได้หลังดำเนินการ`,
      input:'text', inputPlaceholder:'พิมพ์ "ลบ" เพื่อยืนยัน',
      showCancelButton:true, confirmButtonText:'ล้างทั้งหมด', cancelButtonText:'ยกเลิก',
      confirmButtonColor:'#ef4444',
      inputValidator: (v) => v !== 'ลบ' ? 'กรุณาพิมพ์ "ลบ" เพื่อยืนยัน' : null
    });
    if (!r.isConfirmed) return;
    try {
      await api('daily:delete_all', { dept_id: state.deptId }, 'POST');
      toast('ล้างข้อมูลสำเร็จ');
      state.selected.clear();
      await refreshAll();
    } catch (e) { errAlert(e); }
  };

  /* ---------- TABS ---------- */
  window.npSwitchTab = function(name) {
    document.querySelectorAll('.np-tab').forEach(t => t.classList.toggle('is-active', t.dataset.tab === name));
    document.querySelectorAll('.np-tab-content').forEach(c => c.classList.toggle('is-active', c.id === 'np-tab-' + name));
  };

  /* ---------- EXCEL I/O ---------- */
  window.npExportExcel = function() {
    if (!state.deptId) { toast('เลือกหน่วยงานก่อน','warning'); return; }
    const q = new URLSearchParams({
      dept_id: state.deptId,
      from: $('np-filter-from').value || '',
      to:   $('np-filter-to').value || '',
    }).toString();
    window.location.href = `nurse_productivity_export.php?${q}`;
  };

  window.npImportExcel = async function(ev) {
    const file = ev.target.files[0];
    ev.target.value = '';
    if (!file || !state.deptId) return;
    const r = await Swal.fire({
      icon:'question', title:'นำเข้าข้อมูล',
      text: `จะเพิ่ม/อัปเดตรายการตามข้อมูลในไฟล์ "${file.name}" สำหรับหน่วยงาน "${state.deptName}"`,
      showCancelButton:true, confirmButtonText:'ดำเนินการ', cancelButtonText:'ยกเลิก'
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('dept_id', state.deptId);
    fd.append('file', file);
    try {
      const res = await fetch('nurse_productivity_import.php', { method:'POST', body: fd });
      const j = await res.json();
      if (!j.ok) throw new Error(j.message || 'นำเข้าไม่สำเร็จ');
      Swal.fire({ icon:'success', title:'นำเข้าสำเร็จ', text:`เพิ่ม ${j.inserted || 0} · อัปเดต ${j.updated || 0} · ข้าม ${j.skipped || 0} แถว` });
      await refreshAll();
    } catch (e) { errAlert(e); }
  };

  /* ---------- HELPERS ---------- */
  function escapeHtml(s) { return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function escapeAttr(s) { return escapeHtml(s); }

  // Watch dark-mode toggles → redraw charts
  new MutationObserver(muts => {
    for (const m of muts) {
      if (m.attributeName === 'data-theme') { refreshDashboard(); break; }
    }
  }).observe(document.body, { attributes:true, attributeFilter:['data-theme'] });

  document.addEventListener('DOMContentLoaded', init);
  if (document.readyState !== 'loading') setTimeout(init, 50);
})();
</script>
