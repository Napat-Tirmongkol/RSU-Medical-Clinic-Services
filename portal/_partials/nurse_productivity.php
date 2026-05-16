<?php
// portal/_partials/nurse_productivity.php
// Nurse OPD Productivity — multi-tenant per department, with schedule integration + monthly/yearly rollup
?>
<style>
  /* ========================================
     NURSE PRODUCTIVITY — scoped styles
     ======================================== */
  #section-nurse_productivity { background:#f8fafc; }

  /* KPI tile palette (uses project's brand-green canon + accents) */
  .np-kpi {
    position: relative;
    background: #fff;
    border-radius: 16px;
    padding: 18px 18px 16px;
    box-shadow: 0 6px 20px rgba(15,26,46,.06), 0 2px 6px rgba(15,26,46,.04);
    overflow: hidden;
    transition: transform .2s cubic-bezier(.16,1,.3,1), box-shadow .2s;
  }
  .np-kpi:hover:not(.fx-tilt) { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(15,26,46,.10); }
  .np-kpi.fx-tilt:hover { --lift: -2px; }
  .np-kpi::before {
    content:''; position:absolute; left:0; top:0; bottom:0; width:5px;
    background: linear-gradient(180deg, var(--np-c1, #2e9e63), var(--np-c2, #1f7a4c));
  }
  .np-kpi .np-kpi-icon {
    width:40px; height:40px; border-radius:12px;
    background: linear-gradient(135deg, var(--np-c1, #2e9e63), var(--np-c2, #1f7a4c));
    color:#fff; display:flex; align-items:center; justify-content:center; margin-bottom:10px;
    box-shadow: 0 4px 12px rgba(15,26,46,.10);
  }
  .np-kpi .np-kpi-value { font-size:26px; font-weight:700; color:#0f172a; line-height:1.1; }
  .np-kpi .np-kpi-value .unit { font-size:14px; font-weight:500; color:#64748b; margin-left:2px; }
  .np-kpi .np-kpi-title { font-size:12px; color:#64748b; margin-top:4px; font-weight:500; }
  .np-kpi .np-kpi-sub   { font-size:11px; color:#94a3b8; margin-top:2px; }
  .np-kpi .np-delta {
    position:absolute; top:14px; right:14px;
    font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px;
    background: rgba(34,197,94,.12); color:#15803d;
  }
  .np-kpi .np-delta.down { background: rgba(239,68,68,.12); color:#b91c1c; }
  .np-kpi .np-delta.flat { background: rgba(100,116,139,.12); color:#475569; }

  .np-kpi.c-teal    { --np-c1:#14b8a6; --np-c2:#0d9488; }
  .np-kpi.c-blue    { --np-c1:#3b82f6; --np-c2:#1d4ed8; }
  .np-kpi.c-orange  { --np-c1:#f59e0b; --np-c2:#d97706; }
  .np-kpi.c-brand   { --np-c1:#2e9e63; --np-c2:#1f7a4c; }
  .np-kpi.c-coral   { --np-c1:#fb923c; --np-c2:#ea580c; }
  .np-kpi.c-rose    { --np-c1:#ef4444; --np-c2:#b91c1c; }

  /* Tabs */
  .np-tabs { display:flex; gap:4px; padding:4px; background:#fff; border-radius:12px; margin-bottom:16px; box-shadow:0 2px 8px rgba(15,26,46,.04); width:fit-content; }
  .np-tab {
    padding:10px 18px; border-radius:8px; font-size:13px; font-weight:500;
    color:#64748b; background:transparent; border:0; cursor:pointer;
    display:inline-flex; align-items:center; gap:6px; transition:all .15s;
  }
  .np-tab:hover { background:#f1f5f9; color:#0f172a; }
  .np-tab.active { background: linear-gradient(135deg, #2e9e63, #1f7a4c); color:#fff; box-shadow:0 4px 10px rgba(46,158,99,.30); }
  .np-tab .np-tab-count { background:rgba(255,255,255,.25); padding:2px 7px; border-radius:999px; font-size:11px; }
  .np-tab:not(.active) .np-tab-count { background:#e2e8f0; color:#475569; }

  .np-tab-content { display:none; }
  .np-tab-content.active { display:block; animation:np-fade-in .25s cubic-bezier(.16,1,.3,1); }
  @keyframes np-fade-in { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }

  /* View mode pills (รายวัน/รายเดือน/รายปี) */
  .np-view-toggle { display:inline-flex; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:3px; gap:2px; }
  .np-view-toggle button {
    border:0; background:transparent; padding:7px 14px; border-radius:9px;
    font-size:12px; font-weight:500; color:#64748b; cursor:pointer; transition:all .15s;
  }
  .np-view-toggle button:hover { color:#0f172a; }
  .np-view-toggle button.active { background: linear-gradient(135deg, #2e9e63, #1f7a4c); color:#fff; box-shadow:0 2px 6px rgba(46,158,99,.30); }

  /* Status banner */
  .np-banner {
    display:flex; gap:14px; align-items:center; padding:14px 18px; border-radius:14px;
    background: linear-gradient(135deg, rgba(34,197,94,.10), rgba(34,197,94,.04));
    border:1px solid rgba(34,197,94,.18);
    color:#15803d; margin:14px 0;
  }
  .np-banner.is-under { background: linear-gradient(135deg, rgba(239,68,68,.10), rgba(239,68,68,.04)); border-color:rgba(239,68,68,.18); color:#b91c1c; }
  .np-banner.is-over  { background: linear-gradient(135deg, rgba(245,158,11,.10), rgba(245,158,11,.04)); border-color:rgba(245,158,11,.18); color:#b45309; }
  .np-banner-icon { width:36px; height:36px; border-radius:10px; background:rgba(255,255,255,.65); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
  .np-banner-text strong { display:block; font-size:14px; }
  .np-banner-text small  { font-size:11.5px; opacity:.85; }

  /* Cards & sections */
  .np-card {
    background:#fff; border-radius:14px; padding:18px;
    box-shadow:0 4px 14px rgba(15,26,46,.04); border:1px solid #f1f5f9;
  }
  .np-card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:12px; flex-wrap:wrap; }
  .np-card-head h3 { font-size:15px; font-weight:600; color:#0f172a; display:flex; align-items:center; gap:8px; }
  .np-card-head .meta { font-size:12px; color:#64748b; }
  .np-chart-wrap { height: 280px; position:relative; }

  /* Dept selector + filters */
  .np-toolbar { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
  .np-field {
    background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px 12px; min-width:160px;
  }
  .np-field-label { font-size:11px; color:#64748b; margin-bottom:2px; font-weight:500; }
  .np-field select, .np-field input { width:100%; border:0; background:transparent; font-size:13px; color:#0f172a; font-family:inherit; outline:none; }
  .np-field-mini-row { display:flex; gap:6px; }
  .np-field-mini { width:50px; border:1px solid #e2e8f0; border-radius:6px; padding:4px 6px; font-size:12px; text-align:center; }

  /* Data table */
  .np-table-wrap { background:#fff; border-radius:14px; padding:14px; box-shadow:0 4px 14px rgba(15,26,46,.04); }
  .np-table-scroll { overflow-x:auto; }
  .np-data { width:100%; border-collapse: collapse; font-size:13px; }
  .np-data th { background:#f8fafc; padding:10px 12px; text-align:left; font-weight:600; font-size:12px; color:#475569; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
  .np-data th.num, .np-data td.num { text-align:right; }
  .np-data td { padding:8px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
  .np-data tr:hover td { background:#fafbfd; }
  .np-data input { width:100%; border:1px solid transparent; padding:5px 7px; border-radius:5px; font-size:13px; font-family:inherit; background:transparent; }
  .np-data input:hover  { border-color:#e2e8f0; }
  .np-data input:focus  { border-color:#2e9e63; background:#fff; outline:none; }

  .np-status-pill { display:inline-flex; align-items:center; gap:5px; padding:3px 9px; border-radius:999px; font-size:11px; font-weight:600; }
  .np-status-pill.is-optimal { background:#d1fae5; color:#047857; }
  .np-status-pill.is-under   { background:#fee2e2; color:#b91c1c; }
  .np-status-pill.is-over    { background:#fef3c7; color:#b45309; }
  .np-src-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 7px; border-radius:999px; font-size:10px; background:#dbeafe; color:#1e40af; margin-left:6px; }

  .np-pagination {
    display:flex; align-items:center; justify-content:space-between;
    padding:12px 14px; gap:12px; flex-wrap:wrap; border-top:1px solid #f1f5f9; margin-top:6px;
  }
  .np-page-info { font-size:12px; color:#64748b; }
  .np-page-btns { display:flex; gap:4px; }
  .np-page-btn {
    border:1px solid #e2e8f0; background:#fff; padding:5px 10px; border-radius:6px;
    font-size:12px; color:#475569; cursor:pointer; min-width:32px;
  }
  .np-page-btn:hover:not(:disabled) { background:#f1f5f9; color:#0f172a; }
  .np-page-btn.active { background:#2e9e63; color:#fff; border-color:#2e9e63; }
  .np-page-btn:disabled { opacity:.4; cursor:not-allowed; }

  /* Settings */
  .np-form-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; }
  @media (max-width: 768px) { .np-form-grid { grid-template-columns:1fr; } }
  .np-form-field label { font-size:12px; color:#64748b; margin-bottom:4px; display:block; font-weight:500; }
  .np-form-field input, .np-form-field select {
    width:100%; padding:8px 12px; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; background:#fff; font-family:inherit;
  }
  .np-form-field input:focus, .np-form-field select:focus { border-color:#2e9e63; outline:none; box-shadow:0 0 0 3px rgba(46,158,99,.12); }
  .np-threshold-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; margin:14px 0; }
  @media (max-width: 768px) { .np-threshold-grid { grid-template-columns:repeat(2, 1fr); } }
  .np-threshold-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; }
  .np-threshold-card label { font-size:12px; color:#475569; font-weight:500; }
  .np-threshold-card input { width:100%; margin-top:6px; padding:8px 10px; border:1px solid #e2e8f0; border-radius:8px; font-size:14px; font-weight:600; }
  .np-threshold-card .note { font-size:11px; color:#94a3b8; margin-top:4px; }

  .np-data-zone { display:flex; align-items:center; justify-content:space-between; padding:14px; background:#f8fafc; border-radius:10px; margin-top:10px; gap:14px; }
  .np-data-zone.danger { background: rgba(239,68,68,.04); border:1px solid rgba(239,68,68,.18); }
  .np-data-zone strong { display:block; font-size:13px; color:#0f172a; }
  .np-data-zone small  { font-size:11.5px; color:#64748b; display:block; margin-top:2px; }

  /* Bulk action bar */
  .np-bulk-bar {
    position:sticky; bottom:0; background:#fff; border-top:2px solid #2e9e63; padding:10px 14px;
    display:none; align-items:center; gap:12px; box-shadow: 0 -6px 20px rgba(15,26,46,.06);
    z-index: 5;
  }
  .np-bulk-bar.active { display:flex; }

  .np-cross-row { display:flex; gap:10px; align-items:center; padding:10px 14px; border-radius:10px; background:#f8fafc; margin-bottom:8px; font-size:13px; }
  .np-cross-row .np-cross-name { flex:1; color:#0f172a; font-weight:500; }
  .np-cross-row .np-cross-bar { height:8px; flex:2; background:#e2e8f0; border-radius:999px; overflow:hidden; }
  .np-cross-row .np-cross-bar-fill { height:100%; background:linear-gradient(90deg, #2e9e63, #14b8a6); transition: width .4s cubic-bezier(.16,1,.3,1); }
  .np-cross-row .np-cross-val { min-width:60px; text-align:right; font-weight:600; color:#1f7a4c; }

  /* DARK MODE */
  body[data-theme='dark'] #section-nurse_productivity { background: transparent; }
  body[data-theme='dark'] .np-card,
  body[data-theme='dark'] .np-kpi,
  body[data-theme='dark'] .np-table-wrap,
  body[data-theme='dark'] .np-tabs { background:#1e293b; border-color:#334155; color:#e2e8f0; }
  body[data-theme='dark'] .np-card-head h3 { color:#f1f5f9; }
  body[data-theme='dark'] .np-card-head .meta { color:#94a3b8; }
  body[data-theme='dark'] .np-kpi .np-kpi-value { color:#f1f5f9; }
  body[data-theme='dark'] .np-kpi .np-kpi-value .unit { color:#94a3b8; }
  body[data-theme='dark'] .np-tab:not(.active) { color:#94a3b8; }
  body[data-theme='dark'] .np-tab:hover:not(.active) { background:#334155; color:#f1f5f9; }
  body[data-theme='dark'] .np-data th { background:#0f172a; color:#cbd5e1; border-color:#334155; }
  body[data-theme='dark'] .np-data td { border-color:#334155; color:#e2e8f0; }
  body[data-theme='dark'] .np-data tr:hover td { background:rgba(46,158,99,.05); }
  body[data-theme='dark'] .np-data input { color:#e2e8f0; }
  body[data-theme='dark'] .np-data input:hover, body[data-theme='dark'] .np-data input:focus { background:#0f172a; }
  body[data-theme='dark'] .np-field, body[data-theme='dark'] .np-view-toggle,
  body[data-theme='dark'] .np-form-field input, body[data-theme='dark'] .np-form-field select,
  body[data-theme='dark'] .np-threshold-card input { background:#0f172a; border-color:#334155; color:#e2e8f0; }
  body[data-theme='dark'] .np-field select, body[data-theme='dark'] .np-field input { color:#e2e8f0; }
  body[data-theme='dark'] .np-threshold-card, body[data-theme='dark'] .np-data-zone { background:#0f172a; border-color:#334155; }
  body[data-theme='dark'] .np-threshold-card label { color:#cbd5e1; }
  body[data-theme='dark'] .np-data-zone strong { color:#f1f5f9; }
  body[data-theme='dark'] .np-page-btn { background:#0f172a; border-color:#334155; color:#cbd5e1; }
  body[data-theme='dark'] .np-page-btn:hover:not(:disabled) { background:#334155; }
  body[data-theme='dark'] .np-cross-row { background:#0f172a; }
  body[data-theme='dark'] .np-cross-row .np-cross-name { color:#e2e8f0; }
</style>

<div class="space-y-4">
  <!-- HEADER + dept selector + view toggle -->
  <div class="np-toolbar">
    <div class="np-field">
      <div class="np-field-label">🏥 หน่วยงาน / แผนก</div>
      <select id="np-dept-select" onchange="npChangeDept()">
        <option value="">— เลือกหน่วยงาน —</option>
      </select>
    </div>
    <div class="np-field">
      <div class="np-field-label">📅 ตั้งแต่</div>
      <input type="date" id="np-filter-from" onchange="npRefreshAll()">
    </div>
    <div class="np-field">
      <div class="np-field-label">📅 ถึง</div>
      <input type="date" id="np-filter-to" onchange="npRefreshAll()">
    </div>
    <div class="np-view-toggle" id="np-view-toggle">
      <button type="button" data-view="daily" class="active" onclick="npSetView('daily')">รายวัน</button>
      <button type="button" data-view="monthly" onclick="npSetView('monthly')">รายเดือน</button>
      <button type="button" data-view="yearly" onclick="npSetView('yearly')">รายปี</button>
    </div>
    <button type="button" class="ds-btn ds-btn-ghost" onclick="npRefreshAll()" style="margin-left:auto;">
      <i class="fa-solid fa-rotate"></i> รีเฟรช
    </button>
  </div>

  <!-- TABS -->
  <nav class="np-tabs">
    <button class="np-tab active" data-tab="dashboard" type="button" onclick="npSwitchTab('dashboard')">
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
  <section class="np-tab-content active" id="np-tab-dashboard">
    <!-- KPI cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
      <div class="np-kpi c-teal fx-tilt" data-tilt="5">
        <div class="np-delta" id="np-delta-avg" style="display:none">—</div>
        <div class="np-kpi-icon"><i class="fa-solid fa-circle-check"></i></div>
        <div class="np-kpi-value" id="np-kpi-avg">—<span class="unit">%</span></div>
        <div class="np-kpi-title">เฉลี่ย Productivity</div>
        <div class="np-kpi-sub" id="np-kpi-avg-foot">รอข้อมูล</div>
      </div>
      <div class="np-kpi c-blue fx-tilt" data-tilt="5">
        <div class="np-delta" id="np-delta-visits" style="display:none">—</div>
        <div class="np-kpi-icon"><i class="fa-solid fa-user-group"></i></div>
        <div class="np-kpi-value" id="np-kpi-visits">—</div>
        <div class="np-kpi-title">รวมผู้ป่วย</div>
        <div class="np-kpi-sub" id="np-kpi-visits-foot">visits ทั้งหมด</div>
      </div>
      <div class="np-kpi c-orange fx-tilt" data-tilt="5">
        <div class="np-delta" id="np-delta-hours" style="display:none">—</div>
        <div class="np-kpi-icon"><i class="fa-solid fa-clock"></i></div>
        <div class="np-kpi-value" id="np-kpi-hours">—</div>
        <div class="np-kpi-title">ชม.พยาบาลที่ใช้</div>
        <div class="np-kpi-sub" id="np-kpi-hours-foot">ชม. รวมในช่วงนี้</div>
      </div>
      <div class="np-kpi c-brand fx-tilt" data-tilt="5">
        <div class="np-kpi-icon"><i class="fa-solid fa-check"></i></div>
        <div class="np-kpi-value" id="np-kpi-optimal">—</div>
        <div class="np-kpi-title">Optimal</div>
        <div class="np-kpi-sub" id="np-kpi-optimal-foot">วันที่อยู่ในเกณฑ์</div>
      </div>
      <div class="np-kpi c-coral fx-tilt" data-tilt="5">
        <div class="np-kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="np-kpi-value" id="np-kpi-under">—</div>
        <div class="np-kpi-title">Under staff</div>
        <div class="np-kpi-sub">ภาระงานสูง</div>
      </div>
      <div class="np-kpi c-rose fx-tilt" data-tilt="5">
        <div class="np-kpi-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div class="np-kpi-value" id="np-kpi-over">—</div>
        <div class="np-kpi-title">Over staff</div>
        <div class="np-kpi-sub">มีกำลังเหลือ</div>
      </div>
    </div>

    <!-- Status banner -->
    <div class="np-banner" id="np-banner">
      <div class="np-banner-icon"><i class="fa-solid fa-circle-info"></i></div>
      <div class="np-banner-text">
        <strong id="np-banner-headline">รอข้อมูลในช่วงเวลาที่เลือก</strong>
        <small id="np-banner-subline">เลือกหน่วยงานและช่วงวันที่เพื่อเริ่มต้น</small>
      </div>
    </div>

    <!-- CHARTS row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
      <div class="np-card">
        <div class="np-card-head">
          <h3><i class="fa-solid fa-chart-line"></i> <span id="np-trend-title">แนวโน้ม Productivity รายวัน</span></h3>
          <span class="meta">เทียบกับเกณฑ์</span>
        </div>
        <div class="np-chart-wrap"><canvas id="npTrendChart"></canvas></div>
      </div>
      <div class="np-card">
        <div class="np-card-head">
          <h3><i class="fa-solid fa-chart-pie"></i> สัดส่วนสถานะ</h3>
          <span class="meta" id="np-status-meta">ตามวัน</span>
        </div>
        <div class="np-chart-wrap"><canvas id="npStatusChart"></canvas></div>
      </div>
    </div>

    <!-- Second row chart (DOW / monthly bar / yearly) -->
    <div class="np-card mt-4">
      <div class="np-card-head">
        <h3><i class="fa-solid fa-chart-column"></i> <span id="np-secondary-title">ผู้ป่วยเฉลี่ยตามวันในสัปดาห์</span></h3>
        <span class="meta" id="np-secondary-meta">รวมทุกสัปดาห์</span>
      </div>
      <div class="np-chart-wrap" style="height:280px;"><canvas id="npSecondaryChart"></canvas></div>
    </div>

    <!-- Cross-dept comparison -->
    <div class="np-card mt-4">
      <div class="np-card-head">
        <h3><i class="fa-solid fa-scale-balanced"></i> เปรียบเทียบทุกหน่วยงาน</h3>
        <span class="meta">Productivity เฉลี่ยในช่วงเดียวกัน</span>
      </div>
      <div id="np-cross-list">
        <div class="text-sm text-slate-500 py-4 text-center">รอข้อมูล</div>
      </div>
    </div>
  </section>

  <!-- ============ DATA ENTRY ============ -->
  <section class="np-tab-content" id="np-tab-entry">
    <div class="np-card">
      <div class="np-card-head">
        <h3><i class="fa-solid fa-pen-to-square"></i> กรอกข้อมูลรายวัน</h3>
        <span class="meta">Productivity คำนวณอัตโนมัติ · ช่อง RN/หัวหน้าว่าง จะดึงจากตารางเวร</span>
      </div>
      <div class="flex flex-wrap gap-2 justify-end">
        <button type="button" class="ds-btn ds-btn-ghost" onclick="npAddRow()">
          <i class="fa-solid fa-plus"></i> เพิ่มแถว
        </button>
        <button type="button" class="ds-btn ds-btn-ghost" onclick="document.getElementById('np-import-file').click()">
          <i class="fa-solid fa-file-import"></i> Import Excel
        </button>
        <input type="file" id="np-import-file" accept=".xlsx,.xls,.csv" style="display:none" onchange="npImportExcel(event)">
        <a class="ds-btn ds-btn-ghost" id="np-template-link" href="#" target="_blank">
          <i class="fa-solid fa-download"></i> Template
        </a>
        <button type="button" class="ds-btn ds-btn-primary" onclick="npExportExcel()">
          <i class="fa-solid fa-file-export"></i> Export Excel
        </button>
        <a class="ds-btn ds-btn-ghost" id="np-print-link" href="#" target="_blank">
          <i class="fa-solid fa-print"></i> พิมพ์
        </a>
      </div>
    </div>

    <div class="np-table-wrap mt-3">
      <div class="np-table-scroll">
        <table class="np-data">
          <thead>
            <tr>
              <th style="width:34px"><input type="checkbox" id="np-check-all" onchange="npToggleAll(this)"></th>
              <th style="width:42px">#</th>
              <th>วันที่</th>
              <th>วัน</th>
              <th class="num">ผู้ป่วย</th>
              <th class="num">RN</th>
              <th class="num">หัวหน้า</th>
              <th class="num">ชม./เวร</th>
              <th class="num">ชม.ต้องการ</th>
              <th class="num">ชม.ที่มี</th>
              <th class="num">Prod %</th>
              <th>สถานะ</th>
              <th>หมายเหตุ</th>
              <th style="width:42px"></th>
            </tr>
          </thead>
          <tbody id="np-entry-body"></tbody>
        </table>
      </div>
      <div class="np-pagination" id="np-pagination">
        <span class="np-page-info" id="np-page-info">— records</span>
        <div class="np-page-btns" id="np-page-btns"></div>
      </div>
    </div>

    <!-- Sticky bulk action bar -->
    <div class="np-bulk-bar" id="np-bulk-bar">
      <span class="font-medium text-sm text-slate-700">
        เลือก <span id="np-bulk-count">0</span> รายการ
      </span>
      <button type="button" class="ds-btn ds-btn-danger" onclick="npBulkDelete()">
        <i class="fa-solid fa-trash"></i> ลบรายการที่เลือก
      </button>
      <button type="button" class="ds-btn ds-btn-ghost" onclick="npClearSelection()">
        ยกเลิก
      </button>
    </div>
  </section>

  <!-- ============ SETTINGS ============ -->
  <section class="np-tab-content" id="np-tab-settings">
    <div class="np-card">
      <div class="np-card-head">
        <h3><i class="fa-solid fa-hospital"></i> ข้อมูลโรงพยาบาล</h3>
        <span class="meta">แสดงบนหัวรายงาน + ไฟล์ export</span>
      </div>
      <div class="np-form-grid">
        <div class="np-form-field" style="grid-column: span 2;">
          <label>ชื่อโรงพยาบาล</label>
          <input type="text" id="np-set-name" placeholder="เช่น โรงพยาบาลชุมชน...">
        </div>
        <div class="np-form-field">
          <label>ระดับ Service Plan</label>
          <select id="np-set-level">
            <option value="A">A</option><option value="S">S</option>
            <option value="M1">M1</option><option value="M2">M2</option>
            <option value="F1">F1</option><option value="F2" selected>F2</option><option value="F3">F3</option>
          </select>
        </div>
        <div class="np-form-field">
          <label>จำนวนเตียง</label>
          <input type="number" id="np-set-beds" placeholder="30">
        </div>
        <div class="np-form-field">
          <label>จังหวัด</label>
          <input type="text" id="np-set-province">
        </div>
        <div class="np-form-field">
          <label>ผู้อำนวยการ</label>
          <input type="text" id="np-set-director">
        </div>
        <div class="np-form-field">
          <label>รหัส MOPH (5 หลัก)</label>
          <input type="text" id="np-set-moph" maxlength="5">
        </div>
        <div class="np-form-field">
          <label>ช่วงเดือน/ปี</label>
          <input type="text" id="np-set-period" placeholder="เมษายน 2569">
        </div>
      </div>
      <div class="text-right mt-3">
        <button type="button" class="ds-btn ds-btn-primary" onclick="npSaveSettings()">
          <i class="fa-solid fa-floppy-disk"></i> บันทึก
        </button>
      </div>
    </div>

    <div class="np-card mt-4">
      <div class="np-card-head">
        <h3><i class="fa-solid fa-sliders"></i> เกณฑ์มาตรฐานการคำนวณ</h3>
        <button type="button" class="ds-btn ds-btn-ghost ds-btn-sm" onclick="npResetThresholds()">
          <i class="fa-solid fa-rotate-left"></i> คืนค่าเริ่มต้น
        </button>
      </div>
      <div class="np-threshold-grid">
        <div class="np-threshold-card">
          <label>ชม.พยาบาล / Visit</label>
          <input type="number" id="np-set-hpv" step="0.01" value="0.24">
          <div class="note">มาตรฐาน 0.24 ชม.</div>
        </div>
        <div class="np-threshold-card">
          <label>ชม.ทำงาน / เวร</label>
          <input type="number" id="np-set-shift" step="0.5" value="7">
          <div class="note">มาตรฐาน 7 ชม.</div>
        </div>
        <div class="np-threshold-card">
          <label>เกณฑ์ล่าง %</label>
          <input type="number" id="np-set-thr-low" step="1" value="80">
          <div class="note">Over staff &lt; ค่านี้</div>
        </div>
        <div class="np-threshold-card">
          <label>เกณฑ์บน %</label>
          <input type="number" id="np-set-thr-high" step="1" value="110">
          <div class="note">Under staff &gt; ค่านี้</div>
        </div>
      </div>
      <p class="text-xs text-slate-500 mt-2">
        <i class="fa-solid fa-circle-info"></i>
        อ้างอิง: มาตรฐานการพยาบาลในโรงพยาบาล ฉบับปรับปรุงครั้งที่ 4 (พ.ศ. 2551) สภาการพยาบาล
      </p>
      <div class="text-right mt-3">
        <button type="button" class="ds-btn ds-btn-primary" onclick="npSaveSettings()">
          <i class="fa-solid fa-floppy-disk"></i> บันทึกเกณฑ์
        </button>
      </div>
    </div>

    <div class="np-card mt-4">
      <div class="np-card-head">
        <h3><i class="fa-solid fa-database"></i> จัดการข้อมูล</h3>
      </div>
      <div class="np-data-zone">
        <div>
          <strong>ส่งออกข้อมูลทั้งหมด (Excel)</strong>
          <small>ดาวน์โหลด .xlsx ครอบคลุมข้อมูลในช่วงที่เลือก</small>
        </div>
        <button type="button" class="ds-btn ds-btn-ghost" onclick="npExportExcel()">
          <i class="fa-solid fa-download"></i> ดาวน์โหลด
        </button>
      </div>
      <div class="np-data-zone danger">
        <div>
          <strong style="color:#b91c1c;">ล้างข้อมูลทั้งหมดของหน่วยงานนี้</strong>
          <small>ลบทุก record ของหน่วยงานปัจจุบัน — ทำไม่ได้หลังดำเนินการ</small>
        </div>
        <button type="button" class="ds-btn ds-btn-danger" onclick="npFactoryReset()">
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
  const CSRF = (typeof portal_CSRF !== 'undefined') ? portal_CSRF : '';
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
  // expose for inline onclick handlers
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
    // default date range: this month
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth(), 1);
    $('np-filter-from').value = toISODate(first);
    $('np-filter-to').value = toISODate(now);

    // load depts
    try {
      const r = await api('depts:list');
      state.depts = r.data || [];
      const sel = $('np-dept-select');
      sel.innerHTML = '<option value="">— เลือกหน่วยงาน —</option>' +
        state.depts.map(d => `<option value="${d.id}">${d.name}</option>`).join('');
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
    document.querySelectorAll('#np-view-toggle button').forEach(b => b.classList.toggle('active', b.dataset.view === v));
    refreshDashboard();
  };

  window.npRefreshAll = refreshAll;
  async function refreshAll() {
    if (!state.deptId) return;
    try {
      const r = await api('daily:list', { dept_id: state.deptId, from: $('np-filter-from').value, to: $('np-filter-to').value });
      state.rows = r.data || [];
      state.settings = r.settings;
      fillSettingsForm();
      $('np-tab-count-entry').textContent = state.rows.length;
      updateExportLinks();
      renderTable();
      await refreshDashboard();
    } catch (e) { errAlert(e); }
  }

  function updateExportLinks() {
    const q = new URLSearchParams({
      dept_id: state.deptId,
      from: $('np-filter-from').value || '',
      to:   $('np-filter-to').value || '',
    }).toString();
    $('np-template-link').href = `nurse_productivity_template.php`;
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
      ['np-delta-avg','np-delta-visits','np-delta-hours'].forEach(id => $(id).style.display='none');

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
      ['np-delta-avg','np-delta-visits','np-delta-hours'].forEach(id => $(id).style.display='none');

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
    ['np-delta-avg','np-delta-visits','np-delta-hours'].forEach(id => $(id).style.display='none');
    updateBanner(null);
    Object.values(state.charts).forEach(c => { if (c) c.destroy(); });
    state.charts = { trend:null, status:null, secondary:null };
    $('np-cross-list').innerHTML = '<div class="text-sm text-slate-500 py-4 text-center">รอข้อมูล</div>';
  }

  function renderDelta(elId, pct) {
    const el = $(elId);
    if (pct == null || isNaN(pct)) { el.style.display='none'; return; }
    el.style.display='';
    el.classList.remove('down','flat');
    if (Math.abs(pct) < 0.5) { el.classList.add('flat'); el.textContent = '~0%'; }
    else if (pct > 0) el.textContent = '▲ ' + Math.round(pct) + '%';
    else { el.classList.add('down'); el.textContent = '▼ ' + Math.round(Math.abs(pct)) + '%'; }
  }

  function updateBanner(s) {
    const banner = $('np-banner');
    const headline = $('np-banner-headline');
    const subline  = $('np-banner-subline');
    banner.classList.remove('is-under', 'is-over');
    if (!s || !s.avgProd) {
      headline.textContent = 'รอข้อมูลในช่วงเวลาที่เลือก';
      subline.textContent  = 'เลือกหน่วยงานและช่วงวันที่เพื่อเริ่มต้น';
      return;
    }
    const low  = state.settings?.threshold_low  || 80;
    const high = state.settings?.threshold_high || 110;
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
    const low  = state.settings?.threshold_low  || 80;
    const high = state.settings?.threshold_high || 110;
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
      options: { responsive:true, maintainAspectRatio:false, plugins: { legend:{ position:'bottom', labels:{ color:t.legend, font:{size:11}, padding:12 } } } }
    });
  }

  function drawStatusChart_avg(avg) {
    destroyChart('status');
    const ctx = $('npStatusChart').getContext('2d');
    const t = chartTheme();
    const low  = state.settings?.threshold_low  || 80;
    const high = state.settings?.threshold_high || 110;
    const ratio = Math.min(100, avg);
    const color = avg < low ? '#f59e0b' : (avg > high ? '#ef4444' : '#2e9e63');
    state.charts.status = new Chart(ctx, {
      type:'doughnut',
      data: { labels:['Productivity','—'], datasets:[{
        data:[ratio, Math.max(0, 100-ratio)],
        backgroundColor:[color, '#e2e8f0'], borderColor:t.border, borderWidth:2
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
        backgroundColor: ['#fde68a','#bfdbfe','#a7f3d0','#fcd34d','#86efac','#c4b5fd','#fbcfe8']
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
      data: { labels, datasets:[{ label:'ผู้ป่วยรวม', data, backgroundColor:'#3b82f6' }] },
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
      if (!depts.length) { list.innerHTML = '<div class="text-sm text-slate-500 py-4 text-center">ยังไม่มีหน่วยงานอื่นที่มีข้อมูลในช่วงนี้</div>'; return; }
      const max = Math.max(...depts.map(d => d.avgProd), 1);
      list.innerHTML = depts.map(d => `
        <div class="np-cross-row">
          <div class="np-cross-name">${escapeHtml(d.deptName)} <small class="text-slate-400">· ${d.days} วัน · ${fmt(d.visits)} visits</small></div>
          <div class="np-cross-bar"><div class="np-cross-bar-fill" style="width:${Math.min(100,(d.avgProd/max)*100)}%"></div></div>
          <div class="np-cross-val">${fmt1(d.avgProd)}%</div>
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
      tbody.innerHTML = `<tr><td colspan="14" class="text-center text-slate-400 py-8">ยังไม่มีข้อมูล — กด "เพิ่มแถว" เพื่อเริ่ม</td></tr>`;
    } else {
      tbody.innerHTML = slice.map((r, i) => {
        const dow = THAI_DAYS[new Date(r.entry_date).getDay()];
        const isChecked = state.selected.has(r.id) ? 'checked' : '';
        const statusCls = `is-${r.status}`;
        const statusLbl = r.status === 'optimal' ? 'Optimal' : (r.status === 'under' ? 'Under' : 'Over');
        const rnSrcBadge   = r.rn_source === 'schedule' ? '<span class="np-src-badge"><i class="fa-regular fa-calendar"></i> ตารางเวร</span>' : '';
        const headSrcBadge = r.head_source === 'schedule' ? '<span class="np-src-badge"><i class="fa-regular fa-calendar"></i> ตารางเวร</span>' : '';
        return `
          <tr data-id="${r.id}">
            <td><input type="checkbox" ${isChecked} onchange="npToggleRow(${r.id}, this.checked)"></td>
            <td>${startIdx + i + 1}</td>
            <td><input type="date" value="${r.entry_date}" onchange="npRowEdit(${r.id}, 'entry_date', this.value)"></td>
            <td>${dow}</td>
            <td class="num"><input type="number" min="0" value="${r.patients}" onchange="npRowEdit(${r.id}, 'patients', this.value)"></td>
            <td class="num"><input type="number" min="0" value="${r.rn_count}" onchange="npRowEdit(${r.id}, 'rn_count', this.value)">${rnSrcBadge}</td>
            <td class="num"><input type="number" min="0" value="${r.head_count}" onchange="npRowEdit(${r.id}, 'head_count', this.value)">${headSrcBadge}</td>
            <td class="num"><input type="number" min="0.5" step="0.5" value="${r.shift_hours}" onchange="npRowEdit(${r.id}, 'shift_hours', this.value)"></td>
            <td class="num">${fmt1(r.needed)}</td>
            <td class="num">${fmt1(r.available)}</td>
            <td class="num"><strong>${fmt1(r.prod)}%</strong></td>
            <td><span class="np-status-pill ${statusCls}">${statusLbl}</span></td>
            <td><input type="text" placeholder="—" value="${escapeAttr(r.note||'')}" onchange="npRowEdit(${r.id}, 'note', this.value)"></td>
            <td><button type="button" class="ds-btn ds-btn-danger ds-btn-sm" onclick="npDeleteRow(${r.id})"><i class="fa-solid fa-trash"></i></button></td>
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
    btns.push(`<button class="np-page-btn" onclick="npGoto(1)" ${cur===1?'disabled':''}>«</button>`);
    btns.push(`<button class="np-page-btn" onclick="npGoto(${cur-1})" ${cur===1?'disabled':''}>‹</button>`);
    const from = Math.max(1, cur - 2), to = Math.min(totalPages, cur + 2);
    for (let p = from; p <= to; p++) btns.push(`<button class="np-page-btn ${p===cur?'active':''}" onclick="npGoto(${p})">${p}</button>`);
    btns.push(`<button class="np-page-btn" onclick="npGoto(${cur+1})" ${cur===totalPages?'disabled':''}>›</button>`);
    btns.push(`<button class="np-page-btn" onclick="npGoto(${totalPages})" ${cur===totalPages?'disabled':''}>»</button>`);
    wrap.innerHTML = btns.join('');
  }
  window.npGoto = (p) => { state.page = p; renderTable(); };

  window.npToggleRow = (id, checked) => {
    if (checked) state.selected.add(id); else state.selected.delete(id);
    updateBulkBar();
  };
  window.npToggleAll = (cb) => {
    document.querySelectorAll('#np-entry-body input[type="checkbox"]').forEach(c => { c.checked = cb.checked; });
    if (cb.checked) document.querySelectorAll('#np-entry-body tr[data-id]').forEach(tr => state.selected.add(+tr.dataset.id));
    else state.selected.clear();
    updateBulkBar();
  };
  window.npClearSelection = () => { state.selected.clear(); $('np-check-all').checked = false; renderTable(); };

  function updateBulkBar() {
    const bar = $('np-bulk-bar');
    $('np-bulk-count').textContent = state.selected.size;
    bar.classList.toggle('active', state.selected.size > 0);
  }

  /* ---------- ROW CRUD ---------- */
  window.npAddRow = async function() {
    if (!state.deptId) { toast('กรุณาเลือกหน่วยงานก่อน', 'warning'); return; }
    const today = toISODate(new Date());
    // Default empty rn/head → server will derive from schedule
    try {
      const r = await api('daily:create', { dept_id: state.deptId, entry_date: today, patients: 0, rn_count: '', head_count: '', shift_hours: state.settings?.shift_hours || 7, note: '' }, 'POST');
      toast(r.rn_source === 'schedule' ? 'เพิ่มแถว — RN/หัวหน้าดึงจากตารางเวร' : 'เพิ่มแถวสำเร็จ');
      await refreshAll();
    } catch (e) { errAlert(e); }
  };

  window.npRowEdit = async function(id, field, val) {
    const row = state.rows.find(r => +r.id === +id);
    if (!row) return;
    // Build full payload (server upserts)
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
      // refresh just this row's computed values quickly
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
    document.querySelectorAll('.np-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
    document.querySelectorAll('.np-tab-content').forEach(c => c.classList.toggle('active', c.id === 'np-tab-' + name));
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

  // Init
  document.addEventListener('DOMContentLoaded', init);
  // If section already shown when partial loaded (SPA-style)
  if (document.readyState !== 'loading') setTimeout(init, 50);
})();
</script>
