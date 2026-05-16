<?php
// portal/_partials/daily_summary.php
// Daily clinic summary — single-page read-only dashboard pulling from:
// nurse_productivity, finance, consumables, gold_card, insurance, asset, schedule, edms
// Visual conventions: mirror portal/_partials/finance.php (.fin-card pattern)
?>
<style>
.ds-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; }
.ds-kpi-row { display:flex; align-items:center; gap:12px; padding:14px 16px; border-radius:12px; border:1px solid transparent; position:relative; }
.ds-kpi-row .ic { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.ds-kpi-row .num { font-size:22px; font-weight:900; color:#0f172a; line-height:1.1; }
.ds-kpi-row .num .unit { font-size:13px; font-weight:700; color:#64748b; margin-left:2px; }
.ds-kpi-row .lbl { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-top:2px; }
.ds-kpi-row .sub { font-size:10.5px; font-weight:600; color:#94a3b8; margin-top:2px; }
.ds-kpi-row[data-tone="visits"]  { background:#f0fdf4; }
.ds-kpi-row[data-tone="visits"] .ic  { background:#dcfce7; color:#15803d; }
.ds-kpi-row[data-tone="prod"]    { background:#eff6ff; }
.ds-kpi-row[data-tone="prod"] .ic    { background:#dbeafe; color:#1e40af; }
.ds-kpi-row[data-tone="income"]  { background:#ecfdf5; }
.ds-kpi-row[data-tone="income"] .ic  { background:#d1fae5; color:#047857; }
.ds-kpi-row[data-tone="expense"] { background:#fef2f2; }
.ds-kpi-row[data-tone="expense"] .ic { background:#fee2e2; color:#b91c1c; }

.ds-delta { display:inline-flex; align-items:center; gap:3px; padding:1px 7px; border-radius:99px; font-size:10px; font-weight:800; margin-left:4px; vertical-align:middle; }
.ds-delta.up   { background:#dcfce7; color:#15803d; }
.ds-delta.down { background:#fee2e2; color:#b91c1c; }
.ds-delta.flat { background:#f1f5f9; color:#64748b; }
.ds-delta.hidden { display:none; }

.ds-mini { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; background:#f8fafc; border:1px solid #e2e8f0; }
.ds-mini .ic { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:14px; }
.ds-mini .num { font-size:16px; font-weight:900; color:#0f172a; line-height:1.1; }
.ds-mini .lbl { font-size:10.5px; font-weight:700; color:#64748b; }
.ds-mini[data-tone="in"]    .ic { background:#d1fae5; color:#047857; }
.ds-mini[data-tone="out"]   .ic { background:#fee2e2; color:#b91c1c; }
.ds-mini[data-tone="item"]  .ic { background:#dbeafe; color:#1e40af; }
.ds-mini[data-tone="alert"] .ic { background:#fef3c7; color:#b45309; }

.ds-list-row { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:8px; font-size:13px; }
.ds-list-row + .ds-list-row { margin-top:3px; }
.ds-list-row:hover { background:#fafbfc; }
.ds-list-row .name { flex:1; color:#0f172a; font-weight:700; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ds-list-row .meta { font-size:11px; color:#94a3b8; font-weight:600; }
.ds-list-row .val  { font-weight:900; color:#1f7a4c; font-variant-numeric:tabular-nums; }
.ds-list-row.is-warn { background:#fffbeb; border:1px solid #fde68a; }
.ds-list-row.is-warn .val { color:#b45309; }
.ds-list-row.is-warn .name { color:#92400e; }

.ds-status-pill { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:99px; font-size:10px; font-weight:800; }
.ds-status-pill.is-optimal { background:#d1fae5; color:#047857; }
.ds-status-pill.is-under   { background:#fee2e2; color:#b91c1c; }
.ds-status-pill.is-over    { background:#fef3c7; color:#b45309; }

.ds-prod-row { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; background:#f8fafc; font-size:13px; }
.ds-prod-row + .ds-prod-row { margin-top:6px; }
.ds-prod-row .name { flex:1.5; color:#0f172a; font-weight:800; }
.ds-prod-row .stat { display:flex; gap:14px; font-size:11.5px; color:#64748b; flex:2; }
.ds-prod-row .stat b { color:#0f172a; font-weight:900; }
.ds-prod-row .prod { min-width:74px; text-align:right; font-weight:900; color:#1e40af; font-variant-numeric:tabular-nums; }

.ds-cash-bar { height:14px; border-radius:99px; background:#f1f5f9; overflow:hidden; display:flex; }
.ds-cash-bar > i { display:block; height:100%; transition:width .4s cubic-bezier(.16,1,.3,1); }
.ds-cash-bar .seg-income  { background:linear-gradient(90deg,#10b981,#2e9e63); }
.ds-cash-bar .seg-expense { background:linear-gradient(90deg,#ef4444,#dc2626); }

.ds-cat-chip { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:99px; font-size:10.5px; font-weight:800; background:#f1f5f9; color:#475569; }
.ds-cat-chip.income { background:#d1fae5; color:#047857; }
.ds-cat-chip.expense { background:#fee2e2; color:#b91c1c; }

.ds-shift-badge { display:inline-block; padding:1px 7px; border-radius:6px; font-size:10.5px; font-weight:800; background:#dbeafe; color:#1e40af; min-width:22px; text-align:center; }
.ds-shift-badge.morning   { background:#fef3c7; color:#b45309; }
.ds-shift-badge.afternoon { background:#fce7f3; color:#9d174d; }
.ds-shift-badge.night     { background:#e0e7ff; color:#3730a3; }

.ds-empty { text-align:center; color:#94a3b8; font-size:12px; padding:24px 8px; font-style:italic; }

/* DARK MODE */
body[data-theme='dark'] .ds-card { background:#1e293b !important; border-color:#334155 !important; box-shadow:0 4px 15px rgba(0,0,0,.25); }
body[data-theme='dark'] .ds-kpi-row[data-tone="visits"]  { background:rgba(46,158,99,.15); border-color:rgba(46,158,99,.30); }
body[data-theme='dark'] .ds-kpi-row[data-tone="visits"] .ic  { background:rgba(46,158,99,.25); color:#6ee7b7; }
body[data-theme='dark'] .ds-kpi-row[data-tone="prod"]    { background:rgba(59,130,246,.15); border-color:rgba(59,130,246,.30); }
body[data-theme='dark'] .ds-kpi-row[data-tone="prod"] .ic    { background:rgba(59,130,246,.25); color:#93c5fd; }
body[data-theme='dark'] .ds-kpi-row[data-tone="income"]  { background:rgba(4,120,87,.18); border-color:rgba(4,120,87,.30); }
body[data-theme='dark'] .ds-kpi-row[data-tone="income"] .ic  { background:rgba(4,120,87,.30); color:#6ee7b7; }
body[data-theme='dark'] .ds-kpi-row[data-tone="expense"] { background:rgba(244,63,94,.15); border-color:rgba(244,63,94,.30); }
body[data-theme='dark'] .ds-kpi-row[data-tone="expense"] .ic { background:rgba(244,63,94,.25); color:#fb7185; }
body[data-theme='dark'] .ds-kpi-row .num { color:#f8fafc; }
body[data-theme='dark'] .ds-kpi-row .num .unit { color:#94a3b8; }
body[data-theme='dark'] .ds-kpi-row .lbl { color:#94a3b8; }
body[data-theme='dark'] .ds-kpi-row .sub { color:#64748b; }
body[data-theme='dark'] .ds-mini { background:#0f172a; border-color:#334155; }
body[data-theme='dark'] .ds-mini .num { color:#f1f5f9; }
body[data-theme='dark'] .ds-mini .lbl { color:#94a3b8; }
body[data-theme='dark'] .ds-list-row:hover { background:#0f172a; }
body[data-theme='dark'] .ds-list-row .name { color:#e2e8f0; }
body[data-theme='dark'] .ds-list-row .meta { color:#64748b; }
body[data-theme='dark'] .ds-list-row .val  { color:#6ee7b7; }
body[data-theme='dark'] .ds-list-row.is-warn { background:rgba(245,158,11,.10); border-color:rgba(245,158,11,.30); }
body[data-theme='dark'] .ds-list-row.is-warn .name { color:#fbbf24; }
body[data-theme='dark'] .ds-list-row.is-warn .val { color:#fbbf24; }
body[data-theme='dark'] .ds-prod-row { background:#0f172a; }
body[data-theme='dark'] .ds-prod-row .name { color:#f1f5f9; }
body[data-theme='dark'] .ds-prod-row .stat { color:#94a3b8; }
body[data-theme='dark'] .ds-prod-row .stat b { color:#e2e8f0; }
body[data-theme='dark'] .ds-prod-row .prod { color:#93c5fd; }
body[data-theme='dark'] .ds-cash-bar { background:#334155; }
body[data-theme='dark'] .ds-cat-chip { background:#334155; color:#cbd5e1; }
body[data-theme='dark'] .ds-cat-chip.income { background:rgba(4,120,87,.30); color:#6ee7b7; }
body[data-theme='dark'] .ds-cat-chip.expense { background:rgba(244,63,94,.30); color:#fb7185; }
body[data-theme='dark'] .ds-delta.up   { background:rgba(46,158,99,.15); color:#6ee7b7; }
body[data-theme='dark'] .ds-delta.down { background:rgba(244,63,94,.15); color:#fb7185; }
body[data-theme='dark'] .ds-delta.flat { background:rgba(255,255,255,.05); color:#94a3b8; }
body[data-theme='dark'] .ds-empty { color:#64748b; }
</style>

<div class="space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
            <h2 class="text-xl font-black text-slate-800 flex items-center gap-2">
                <i class="fa-solid fa-clipboard-check text-amber-600"></i>
                สรุปงานประจำวัน
            </h2>
            <p class="text-xs text-slate-500 mt-1" id="ds-subtitle">ภาพรวมการให้บริการ การเงิน วัสดุ และเหตุการณ์สำคัญในวันเดียว</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <input type="date" id="ds-date" class="text-sm font-bold text-slate-700 bg-white border border-slate-300 rounded-md px-3 py-1.5">
            <button class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm" onclick="dsShiftDate(-1)" title="ย้อนวัน">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <button class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm" onclick="dsGoToday()" title="วันนี้">
                วันนี้
            </button>
            <button class="btn-solid bg-slate-100 text-slate-700 hover:bg-slate-200 text-sm" onclick="dsShiftDate(1)" title="วันถัดไป">
                <i class="fa-solid fa-chevron-right"></i>
            </button>
            <button class="btn-solid bg-slate-700 text-white hover:bg-slate-800 text-sm" onclick="dsRefresh()">
                <i class="fa-solid fa-rotate"></i> รีเฟรช
            </button>
            <a id="ds-print-link" class="btn-solid bg-brand-500 text-white hover:bg-brand-600 text-sm" href="#" target="_blank">
                <i class="fa-solid fa-print"></i> พิมพ์
            </a>
        </div>
    </div>

    <!-- HERO KPIs (4 big) -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="ds-kpi-row" data-tone="visits">
            <div class="ic"><i class="fa-solid fa-user-group"></i></div>
            <div style="min-width:0;flex:1">
                <div class="num" id="ds-kpi-visits">—</div>
                <div class="lbl">ผู้ป่วยทั้งหมด <span class="ds-delta" id="ds-delta-visits"></span></div>
                <div class="sub" id="ds-kpi-visits-foot">รอข้อมูล</div>
            </div>
        </div>
        <div class="ds-kpi-row" data-tone="prod">
            <div class="ic"><i class="fa-solid fa-gauge-high"></i></div>
            <div style="min-width:0;flex:1">
                <div class="num" id="ds-kpi-prod">—<span class="unit">%</span></div>
                <div class="lbl">Productivity เฉลี่ย</div>
                <div class="sub" id="ds-kpi-prod-foot">รอข้อมูล</div>
            </div>
        </div>
        <div class="ds-kpi-row" data-tone="income">
            <div class="ic"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div style="min-width:0;flex:1">
                <div class="num" id="ds-kpi-income">฿—</div>
                <div class="lbl">รายรับ <span class="ds-delta" id="ds-delta-income"></span></div>
                <div class="sub" id="ds-kpi-income-foot">รอข้อมูล</div>
            </div>
        </div>
        <div class="ds-kpi-row" data-tone="expense">
            <div class="ic"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div style="min-width:0;flex:1">
                <div class="num" id="ds-kpi-expense">฿—</div>
                <div class="lbl">รายจ่าย <span class="ds-delta" id="ds-delta-expense"></span></div>
                <div class="sub" id="ds-kpi-expense-foot">รอข้อมูล</div>
            </div>
        </div>
    </div>

    <!-- Productivity per dept + Cash Book breakdown -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Productivity per dept -->
        <div class="ds-card">
            <div class="flex items-center justify-between mb-3">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-user-nurse text-blue-600"></i> Productivity รายหน่วยงาน
                </div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider" id="ds-prod-meta">— หน่วยงาน</div>
            </div>
            <div id="ds-prod-list"></div>
        </div>

        <!-- Cash Book breakdown -->
        <div class="ds-card">
            <div class="flex items-center justify-between mb-3">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-money-bill-trend-up text-emerald-600"></i> รายรับ-รายจ่าย วันนี้
                </div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                    สุทธิ: <span class="text-slate-700" id="ds-fin-net">฿0</span>
                </div>
            </div>
            <div class="ds-cash-bar mb-3" id="ds-cash-bar">
                <i class="seg-income"  id="ds-bar-income"  style="width:0"></i>
                <i class="seg-expense" id="ds-bar-expense" style="width:0"></i>
            </div>
            <div class="flex items-center justify-between text-[11px] font-bold text-slate-500 mb-3">
                <span><span class="inline-block w-2 h-2 rounded-full bg-emerald-500 mr-1"></span>รายรับ <span class="text-emerald-700" id="ds-bar-income-lbl">฿0</span></span>
                <span><span class="inline-block w-2 h-2 rounded-full bg-rose-500 mr-1"></span>รายจ่าย <span class="text-rose-700" id="ds-bar-expense-lbl">฿0</span></span>
                <span class="text-slate-400" id="ds-fin-count">0 รายการ</span>
            </div>
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-3 mb-2">หมวดสูงสุด</div>
            <div id="ds-fin-cats"></div>
        </div>
    </div>

    <!-- Stock movement + alerts -->
    <div class="ds-card">
        <div class="flex items-center justify-between mb-3">
            <div class="font-bold text-slate-700 text-sm">
                <i class="fa-solid fa-boxes-stacked text-rose-600"></i> สต็อกวัสดุสิ้นเปลือง
            </div>
            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider" id="ds-stock-meta">รับเข้า/เบิกออกวันนี้</div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
            <div class="ds-mini" data-tone="in"><div class="ic"><i class="fa-solid fa-arrow-down"></i></div><div><div class="num" id="ds-stock-in">—</div><div class="lbl">รับเข้า (ชิ้น)</div></div></div>
            <div class="ds-mini" data-tone="out"><div class="ic"><i class="fa-solid fa-arrow-up"></i></div><div><div class="num" id="ds-stock-out">—</div><div class="lbl">เบิกออก (ชิ้น)</div></div></div>
            <div class="ds-mini" data-tone="item"><div class="ic"><i class="fa-solid fa-box"></i></div><div><div class="num" id="ds-stock-items">—</div><div class="lbl">รายการที่เคลื่อนไหว</div></div></div>
            <div class="ds-mini" data-tone="alert"><div class="ic"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="num" id="ds-stock-alerts">—</div><div class="lbl">รายการต่ำกว่าจุดสั่งซื้อ</div></div></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">รายการที่เบิกออกมากที่สุด</div>
                <div id="ds-stock-top"></div>
            </div>
            <div>
                <div class="text-[11px] font-bold uppercase tracking-wider mb-2" style="color:#b45309">
                    <i class="fa-solid fa-triangle-exclamation"></i> ใกล้หมด — ต้องสั่งซื้อ
                </div>
                <div id="ds-stock-low"></div>
            </div>
        </div>
    </div>

    <!-- Other events: gold card / insurance / asset / docs / schedule -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="ds-card">
            <div class="flex items-center justify-between mb-3">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-bolt text-fuchsia-600"></i> เหตุการณ์สำคัญวันนี้
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <div class="ds-mini" data-tone="item">
                    <div class="ic" style="background:#e0e7ff;color:#3730a3"><i class="fa-solid fa-id-card-clip"></i></div>
                    <div><div class="num" id="ds-other-gold">—</div><div class="lbl">บัตรทอง</div></div>
                </div>
                <div class="ds-mini" data-tone="item">
                    <div class="ic" style="background:#dbeafe;color:#1e40af"><i class="fa-solid fa-shield-halved"></i></div>
                    <div><div class="num" id="ds-other-ins">—</div><div class="lbl">Batch ประกัน</div></div>
                </div>
                <div class="ds-mini" data-tone="item">
                    <div class="ic" style="background:#fef3c7;color:#b45309"><i class="fa-solid fa-warehouse"></i></div>
                    <div><div class="num" id="ds-other-asset">—</div><div class="lbl">ครุภัณฑ์</div></div>
                </div>
                <div class="ds-mini" data-tone="item">
                    <div class="ic" style="background:#fce7f3;color:#9d174d"><i class="fa-solid fa-folder-open"></i></div>
                    <div><div class="num" id="ds-other-docs">—</div><div class="lbl">เอกสาร</div></div>
                </div>
            </div>
        </div>
        <div class="ds-card">
            <div class="flex items-center justify-between mb-3">
                <div class="font-bold text-slate-700 text-sm">
                    <i class="fa-solid fa-user-clock text-indigo-600"></i> ตารางเวรพยาบาลวันนี้
                </div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider" id="ds-sched-meta">— คน</div>
            </div>
            <div id="ds-sched-list" class="max-h-64 overflow-y-auto"></div>
        </div>
    </div>
</div>

<script>
/* ========================================================================
   DAILY SUMMARY — frontend (read-only aggregator)
   ======================================================================== */
(function(){
  const AJAX = 'ajax_daily_summary.php';
  const CSRF = '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES) ?>';

  const state = { date: null };
  window.__ds = state;

  const $ = (id) => document.getElementById(id);
  const fmt   = (n, d=0) => (n == null || isNaN(n)) ? '—' : Number(n).toLocaleString('th-TH', { minimumFractionDigits:d, maximumFractionDigits:d });
  const fmt1  = (n) => fmt(n, 1);
  const baht  = (n) => '฿' + Number(n||0).toLocaleString('th-TH');
  const toISO = (d) => { const z=(x)=>String(x).padStart(2,'0'); return `${d.getFullYear()}-${z(d.getMonth()+1)}-${z(d.getDate())}`; };
  const escHtml = (s) => String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  async function fetchSummary(date) {
    const res = await fetch(`${AJAX}?action=summary:get&date=${encodeURIComponent(date)}`);
    const json = await res.json();
    if (!json.ok) throw new Error(json.message || 'โหลดข้อมูลไม่สำเร็จ');
    return json;
  }

  function setDelta(elId, pct, expenseMode=false) {
    const el = $(elId);
    if (!el) return;
    el.classList.remove('up','down','flat','hidden');
    if (pct == null || isNaN(pct)) { el.classList.add('hidden'); el.textContent=''; return; }
    if (Math.abs(pct) < 0.5)       { el.classList.add('flat'); el.textContent = '~0%'; }
    else if (pct > 0)              { el.classList.add(expenseMode ? 'down' : 'up');   el.textContent = '▲ ' + Math.round(pct) + '%'; }
    else                           { el.classList.add(expenseMode ? 'up' : 'down');   el.textContent = '▼ ' + Math.round(Math.abs(pct)) + '%'; }
  }

  function shiftBadgeClass(code) {
    if (code === 'ช') return 'morning';
    if (code === 'บ') return 'afternoon';
    if (code === 'ด') return 'night';
    return '';
  }
  function shiftLabel(code) {
    return ({'ช':'เช้า','บ':'บ่าย','ด':'ดึก'}[code]) || code;
  }

  function render(json) {
    const h = json.headline;
    $('ds-subtitle').textContent = `${h.dayName} ที่ ${h.date.split('-').reverse().join('/')} (พ.ศ. ${h.dateBE}) · ${h.isToday ? 'วันนี้' : (h.isWeekend ? 'วันหยุด' : 'วันทำการ')}`;

    // HERO KPIs
    const p = json.productivity, f = json.finance;
    $('ds-kpi-visits').textContent  = fmt(p.totalVisits);
    $('ds-kpi-prod').innerHTML      = (p.avgProd ? fmt1(p.avgProd) : '—') + '<span class="unit">%</span>';
    $('ds-kpi-income').textContent  = baht(f.income);
    $('ds-kpi-expense').textContent = baht(f.expense);

    $('ds-kpi-visits-foot').textContent  = p.deptCount ? `${p.deptCount} หน่วยงาน · เทียบเมื่อวาน ${fmt(p.prevVisits)}` : 'ยังไม่มีรายงาน';
    $('ds-kpi-prod-foot').textContent    = p.deptCount ? `เฉลี่ยจาก ${p.deptCount} หน่วยงาน` : 'รอกรอกข้อมูล';
    $('ds-kpi-income-foot').textContent  = `${f.txnCount} รายการ`;
    $('ds-kpi-expense-foot').textContent = `สุทธิ ${baht(f.net)}`;

    setDelta('ds-delta-visits',  p.visitsDelta);
    setDelta('ds-delta-income',  f.incomeDelta);
    setDelta('ds-delta-expense', f.expenseDelta, true); // expense ขึ้น = ลบ

    // Productivity per dept
    $('ds-prod-meta').textContent = `${p.deptCount} หน่วยงาน`;
    if (!p.list.length) {
        $('ds-prod-list').innerHTML = '<div class="ds-empty">ยังไม่มีหน่วยงานที่กรอกข้อมูลในวันนี้</div>';
    } else {
        $('ds-prod-list').innerHTML = p.list.map(d => `
            <div class="ds-prod-row">
                <div class="name">${escHtml(d.deptName)}</div>
                <div class="stat">
                    <span>ผู้ป่วย <b>${fmt(d.patients)}</b></span>
                    <span>RN <b>${d.rn}</b></span>
                    <span>หัวหน้า <b>${d.head}</b></span>
                </div>
                <div class="prod">${fmt1(d.prod)}% <span class="ds-status-pill is-${d.status}" style="margin-left:4px">${d.status === 'optimal' ? 'OK' : (d.status === 'under' ? 'Under' : 'Over')}</span></div>
            </div>
        `).join('');
    }

    // Cash book bar + categories
    const total = f.income + f.expense;
    if (total > 0) {
        $('ds-bar-income').style.width  = ((f.income  / total) * 100) + '%';
        $('ds-bar-expense').style.width = ((f.expense / total) * 100) + '%';
    } else {
        $('ds-bar-income').style.width  = '0%';
        $('ds-bar-expense').style.width = '0%';
    }
    $('ds-bar-income-lbl').textContent  = baht(f.income);
    $('ds-bar-expense-lbl').textContent = baht(f.expense);
    $('ds-fin-net').textContent = baht(f.net);
    $('ds-fin-count').textContent = `${f.txnCount} รายการ`;

    if (!f.topCategories.length) {
        $('ds-fin-cats').innerHTML = '<div class="ds-empty">ไม่มีรายการในวันนี้</div>';
    } else {
        $('ds-fin-cats').innerHTML = f.topCategories.map(c => `
            <div class="ds-list-row">
                <span class="ds-cat-chip ${c.kind}">${escHtml(c.name)}</span>
                <div class="meta" style="flex:1;text-align:right">${c.count} รายการ</div>
                <div class="val">${baht(c.total)}</div>
            </div>
        `).join('');
    }

    // Stock
    const s = json.stock;
    $('ds-stock-in').textContent     = fmt(s.qtyIn);
    $('ds-stock-out').textContent    = fmt(s.qtyOut);
    $('ds-stock-items').textContent  = fmt(s.itemsTouched);
    $('ds-stock-alerts').textContent = fmt(s.lowStock.length);

    if (!s.topIssued.length) {
        $('ds-stock-top').innerHTML = '<div class="ds-empty">ไม่มีการเบิกออกในวันนี้</div>';
    } else {
        $('ds-stock-top').innerHTML = s.topIssued.map(i => `
            <div class="ds-list-row">
                <div class="name">${escHtml(i.name)}</div>
                <div class="meta">คงเหลือ ${fmt(i.onHand)} ${escHtml(i.unit||'ชิ้น')}</div>
                <div class="val">−${fmt(i.qty)}</div>
            </div>
        `).join('');
    }

    if (!s.lowStock.length) {
        $('ds-stock-low').innerHTML = '<div class="ds-empty">ไม่มีรายการที่ต่ำกว่าจุดสั่งซื้อ</div>';
    } else {
        $('ds-stock-low').innerHTML = s.lowStock.map(i => `
            <div class="ds-list-row is-warn">
                <div class="name">${escHtml(i.name)}</div>
                <div class="meta">เกณฑ์ ${fmt(i.min)} ${escHtml(i.unit||'ชิ้น')}</div>
                <div class="val">${fmt(i.onHand)} ${escHtml(i.unit||'ชิ้น')}</div>
            </div>
        `).join('');
    }

    // Other
    const o = json.other;
    $('ds-other-gold').textContent  = fmt(o.goldCard);
    $('ds-other-ins').textContent   = fmt(o.insurance);
    $('ds-other-asset').textContent = fmt(o.assetEvents);
    $('ds-other-docs').textContent  = fmt(o.docs.in + o.docs.out);

    // Schedule
    $('ds-sched-meta').textContent = `${o.schedule.length} คน`;
    if (!o.schedule.length) {
        $('ds-sched-list').innerHTML = '<div class="ds-empty">ยังไม่มีตารางเวรในวันนี้</div>';
    } else {
        $('ds-sched-list').innerHTML = o.schedule.map(n => `
            <div class="ds-list-row">
                <div class="name">${escHtml(n.name)}</div>
                <div class="meta" style="flex:1.2">${escHtml(n.position)}</div>
                <span class="ds-shift-badge ${shiftBadgeClass(n.shift)}">${escHtml(shiftLabel(n.shift))}</span>
            </div>
        `).join('');
    }

    updatePrintLink();
  }

  function clearAll() {
    ['ds-kpi-visits','ds-kpi-income','ds-kpi-expense'].forEach(id => $(id).textContent = '—');
    $('ds-kpi-prod').innerHTML = '—<span class="unit">%</span>';
    ['ds-prod-list','ds-fin-cats','ds-stock-top','ds-stock-low','ds-sched-list'].forEach(id => $(id).innerHTML = '<div class="ds-empty">ไม่มีข้อมูล</div>');
  }

  function updatePrintLink() {
    $('ds-print-link').href = `daily_summary_print.php?date=${encodeURIComponent(state.date)}`;
  }

  window.dsRefresh = async function() {
    state.date = $('ds-date').value || toISO(new Date());
    try {
      const json = await fetchSummary(state.date);
      render(json);
    } catch (e) {
      Swal.fire({ icon:'error', title:'โหลดข้อมูลไม่สำเร็จ', text: e.message });
      clearAll();
    }
  };

  window.dsShiftDate = function(days) {
    const d = new Date(state.date + 'T00:00:00');
    d.setDate(d.getDate() + days);
    $('ds-date').value = toISO(d);
    dsRefresh();
  };
  window.dsGoToday = function() {
    $('ds-date').value = toISO(new Date());
    dsRefresh();
  };

  function init() {
    $('ds-date').value = toISO(new Date());
    state.date = $('ds-date').value;
    $('ds-date').addEventListener('change', dsRefresh);
    dsRefresh();
  }

  document.addEventListener('DOMContentLoaded', init);
  if (document.readyState !== 'loading') setTimeout(init, 50);
})();
</script>
