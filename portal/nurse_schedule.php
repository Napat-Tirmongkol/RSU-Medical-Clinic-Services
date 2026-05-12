<?php
// portal/nurse_schedule.php — ระบบจัดตารางเวรพยาบาล
// Phase 2: ข้อมูลเก็บที่ server (sys_nurse_schedule_global + sys_nurse_schedule_monthly)
//   ผ่าน portal/ajax_nurse_schedule.php · multi-user แชร์ข้อมูลร่วมกัน
// localStorage เป็น offline cache ช่วยตอน server ไม่ตอบ
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
// auth.php จะ redirect ถ้าไม่ได้ login (admin/staff session ผ่านแล้ว)
$NS_CSRF_TOKEN = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ตารางเวรพยาบาล · RSU Medical Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700;800&family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
<script src="https://unpkg.com/docx@8.5.0/build/index.umd.js"></script>
<style>
  body { font-family: 'Noto Sans Thai', 'IBM Plex Sans Thai', sans-serif; background: #f1f5f9; }

  /* ===== HEADER GRADIENT (NCDs-style) ===== */
  .ncds-header {
    background: linear-gradient(135deg, #10b981 0%, #2e9e63 35%, #34d399 100%);
    box-shadow: 0 4px 20px rgba(46, 158, 99, 0.25);
  }
  .ncds-subheader {
    background: linear-gradient(135deg, #059669 0%, #0d8a52 50%, #2563eb 100%);
  }

  /* ===== STAT CARDS (compact half-width) ===== */
  .stat-card {
    border-radius: 14px;
    padding: 10px 12px;
    color: white;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    transition: transform 0.18s ease, box-shadow 0.18s ease;
  }
  .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
  .stat-card::before {
    content: ''; position: absolute; right: -15px; bottom: -15px;
    width: 70px; height: 70px; border-radius: 50%;
    background: rgba(255,255,255,0.12);
  }
  .stat-card::after {
    content: ''; position: absolute; right: 12px; top: -18px;
    width: 40px; height: 40px; border-radius: 50%;
    background: rgba(255,255,255,0.08);
  }
  .stat-icon-box {
    width: 32px; height: 32px; border-radius: 9px;
    background: rgba(255,255,255,0.22);
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(8px);
  }
  .stat-icon-box i { width: 16px; height: 16px; }
  .stat-card .stat-value { font-size: 1.4rem; font-weight: 700; line-height: 1.1; margin-top: 6px; }
  .stat-card .stat-label { font-size: 0.75rem; font-weight: 500; opacity: 0.95; }
  .stat-card .stat-sub { font-size: 0.65rem; opacity: 0.8; margin-top: 1px; }
  .card-teal   { background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%); }
  .card-blue   { background: linear-gradient(135deg, #34d399 0%, #2563eb 100%); }
  .card-amber  { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
  .card-purple { background: linear-gradient(135deg, #a855f7 0%, #9333ea 100%); }
  .card-orange { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
  .card-red    { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
  .card-cyan   { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
  .card-pink   { background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); }
  .card-emerald { background: linear-gradient(135deg, #059669 0%, #047857 100%); }

  /* ===== BADGES ===== */
  .badge-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 999px;
    background: rgba(255,255,255,0.22); color: white;
    font-size: 13px; font-weight: 500; backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,0.15);
  }
  .badge-status-ok { background: rgba(16, 185, 129, 0.95); }

  /* ===== BUTTONS ===== */
  .btn-ghost {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 999px;
    background: rgba(255,255,255,0.18); color: white;
    border: 1px solid rgba(255,255,255,0.25);
    font-size: 13px; font-weight: 500;
    transition: all 0.15s;
  }
  .btn-ghost:hover { background: rgba(255,255,255,0.28); transform: translateY(-1px); }
  .btn-solid {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 10px; font-weight: 500; font-size: 14px;
    transition: all 0.15s;
  }
  .btn-primary { background: #2e9e63; color: white; }
  .btn-primary:hover { background: #0d8a52; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(46,158,99,0.4); }
  .btn-success { background: #10b981; color: white; }
  .btn-success:hover { background: #059669; }
  .btn-danger { background: #ef4444; color: white; }
  .btn-danger:hover { background: #dc2626; }
  .btn-warning { background: #f59e0b; color: white; }
  .btn-warning:hover { background: #d97706; }
  .btn-info { background: #0ea5e9; color: white; }
  .btn-info:hover { background: #0284c7; }

  /* ===== FILTER DROPDOWN ===== */
  .filter-card {
    background: white; border: 1px solid #e2e8f0; border-radius: 14px;
    padding: 10px 14px; transition: all 0.15s;
    min-width: 0;   /* allow shrink inside grid */
  }
  .filter-card:focus-within { border-color: #2e9e63; box-shadow: 0 0 0 3px rgba(46,158,99,0.15); }
  .filter-label {
    font-size: 11px; color: #64748b; font-weight: 500;
    display: flex; align-items: center; gap: 4px;
    flex-wrap: wrap;    /* label wrap ได้บนจอแคบ */
  }
  /* Stepper inputs ใน filter-card — shrink เท่ากันทุกตัว */
  .filter-card input[type="number"] {
    min-width: 0;
    width: 100%;
  }
  .filter-select {
    width: 100%; border: none; outline: none; background: transparent;
    font-size: 14px; font-weight: 500; color: #1e293b; margin-top: 2px;
  }

  /* ===== TAB NAV ===== */
  .tab-nav {
    display: flex; gap: 4px; padding: 4px; background: white;
    border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    border: 1px solid #e2e8f0; flex-wrap: wrap;
  }
  .tab-btn {
    padding: 10px 18px; border-radius: 10px; font-weight: 500; font-size: 14px;
    color: #64748b; display: flex; align-items: center; gap: 6px;
    transition: all 0.15s; cursor: pointer; white-space: nowrap;
  }
  .tab-btn:hover { background: #f1f5f9; color: #2e9e63; }
  .tab-btn.active {
    background: linear-gradient(135deg, #10b981 0%, #2e9e63 100%);
    color: white; box-shadow: 0 4px 12px rgba(46,158,99,0.3);
  }
  .tab-btn .count-bubble {
    padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600;
    background: #f1f5f9; color: #64748b;
  }
  .tab-btn.active .count-bubble { background: rgba(255,255,255,0.3); color: white; }

  /* ===== SCHEDULE TABLE ===== */
  .schedule-wrapper {
    background: white; border-radius: 14px; padding: 18px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;
    overflow: auto; max-height: calc(100vh - 320px);
  }
  .schedule-table {
    border-collapse: separate; border-spacing: 0;
    font-size: 13px; min-width: 100%;
  }
  .schedule-table thead { position: sticky; top: 0; z-index: 5; }
  .schedule-table thead th {
    background: linear-gradient(135deg, #059669 0%, #0d8a52 100%);
    color: white; padding: 8px 6px; font-weight: 500;
    border-right: 1px solid rgba(255,255,255,0.15);
  }
  .schedule-table thead .weekend-header {
    background: linear-gradient(135deg, #ec4899 0%, #db2777 100%) !important;
  }
  .schedule-table tbody td {
    border-bottom: 1px solid #e2e8f0;
    border-right: 1px solid #f1f5f9;
    text-align: center; padding: 0; vertical-align: middle;
  }
  .schedule-table tbody tr:hover { background: rgba(46,158,99,0.04); }
  .day-cell {
    width: 38px; height: 32px; cursor: pointer;
    font-weight: 600; letter-spacing: 0.3px; font-size: 13px;
    transition: all 0.1s;
  }
  .day-cell:hover { box-shadow: inset 0 0 0 2px #2e9e63; }
  .day-cell.weekend-cell { background: rgba(252, 231, 243, 0.4); }
  .day-cell.today { box-shadow: inset 0 0 0 2px #f59e0b; }

  /* Sticky columns */
  .sticky-col { position: sticky; left: 0; background: white; z-index: 3; border-right: 2px solid #e2e8f0; }
  .sticky-col-2 { position: sticky; left: 36px; background: white; z-index: 3; }
  .sticky-col-3 { position: sticky; background: white; z-index: 3; border-right: 2px solid #e2e8f0; }
  tr:hover .sticky-col, tr:hover .sticky-col-2, tr:hover .sticky-col-3 { background: #f0f9ff; }

  /* Position badges */
  .pos-badge {
    display: inline-block; padding: 3px 10px; border-radius: 8px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
  }
  .pos-head    { background: linear-gradient(135deg, #10b981, #059669); color: white; }
  .pos-deputy  { background: linear-gradient(135deg, #2e9e63, #0d8a52); color: white; }
  .pos-shift   { background: linear-gradient(135deg, #34d399, #2563eb); color: white; }
  .pos-rn      { background: linear-gradient(135deg, #14b8a6, #0d9488); color: white; }
  .pos-tech    { background: linear-gradient(135deg, #a855f7, #9333ea); color: white; }
  .pos-aide    { background: linear-gradient(135deg, #f97316, #ea580c); color: white; }
  .pos-staff   { background: linear-gradient(135deg, #64748b, #475569); color: white; }

  /* Shift palette */
  .shift-palette {
    display: flex; gap: 8px; flex-wrap: wrap; padding: 12px;
    background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;
  }
  .shift-btn {
    padding: 8px 14px; border-radius: 10px; font-weight: 600; font-size: 14px;
    cursor: pointer; transition: all 0.15s; border: 2px solid transparent;
    min-width: 48px; text-align: center;
  }
  .shift-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 14px rgba(0,0,0,0.12); }
  .shift-btn.selected { border-color: #2e9e63; box-shadow: 0 0 0 3px rgba(46,158,99,0.25); transform: translateY(-1px); }

  /* Stat cells in schedule */
  .stat-col { background: #f8fafc; font-weight: 600; color: #0d8a52; }

  /* Summary card */
  .summary-card {
    background: white; border-radius: 14px; padding: 16px;
    border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    transition: all 0.15s;
  }
  .summary-card:hover { box-shadow: 0 8px 20px rgba(46,158,99,0.12); transform: translateY(-2px); }
  .progress-bar {
    height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-top: 6px;
  }
  .progress-fill {
    height: 100%; background: linear-gradient(90deg, #10b981, #2e9e63);
    transition: width 0.4s ease;
  }

  /* OT table */
  .ot-table {
    width: 100%; border-collapse: collapse; font-size: 13px;
    background: white; border-radius: 12px; overflow: hidden;
  }
  .ot-table thead th {
    background: linear-gradient(135deg, #1e40af, #1e3a8a); color: white;
    padding: 10px 8px; font-weight: 500; text-align: center;
  }
  .ot-table tbody td {
    padding: 8px; border-bottom: 1px solid #e2e8f0; text-align: center;
  }
  .ot-table tbody tr:nth-child(even) { background: #f0f9ff; }
  .ot-table tbody tr:hover { background: #e0f2fe; }
  .ot-table tfoot td {
    background: #fef3c7; font-weight: 700; padding: 10px 8px;
    border-top: 2px solid #f59e0b;
  }

  /* Custom scrollbar */
  ::-webkit-scrollbar { width: 10px; height: 10px; }
  ::-webkit-scrollbar-track { background: #f1f5f9; }
  ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 5px; }
  ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

  /* SweetAlert */
  .swal2-popup { font-family: 'Noto Sans Thai', sans-serif !important; border-radius: 16px !important; }
  .swal2-title { font-weight: 600 !important; color: #0c4a6e !important; }

  /* Animations */
  @keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .fade-in-up { animation: fadeInUp 0.4s ease-out; }

  /* Help cards */
  .help-card {
    background: white; border-radius: 14px; padding: 20px;
    border-left: 4px solid #2e9e63; box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 14px;
  }
  .help-card.warn { border-left-color: #f59e0b; background: #fffbeb; }
  .help-card.danger { border-left-color: #ef4444; background: #fef2f2; }
  .help-card.success { border-left-color: #10b981; background: #ecfdf5; }

  /* Print */
  @media print {
    .no-print { display: none !important; }
    .schedule-wrapper { max-height: none; overflow: visible; }
    body { background: white; }
  }

  /* Loading overlay */
  .loader-backdrop {
    position: fixed; inset: 0; background: rgba(46, 158, 99, 0.08);
    backdrop-filter: blur(2px); z-index: 9999;
    display: flex; align-items: center; justify-content: center;
  }
  .loader-spinner {
    width: 50px; height: 50px; border: 4px solid #dbeafe;
    border-top-color: #2e9e63; border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>

<!-- ============== HEADER ============== -->
<header class="ncds-header text-white sticky top-0 z-40 no-print">
  <div class="max-w-[1600px] mx-auto px-6 py-4">
    <div class="flex items-center justify-between gap-4 flex-wrap">
      <!-- Left: Title only (logo + subtitle removed per request) -->
      <div class="flex items-center gap-3">
        <div>
          <h1 class="text-xl md:text-2xl font-bold tracking-tight">ตารางเวรพยาบาล</h1>
          <p class="text-xs md:text-sm text-emerald-50/90">RSU Medical Clinic</p>
        </div>
      </div>

      <!-- Right: Action buttons -->
      <div class="flex items-center gap-2 flex-wrap">
        <span class="badge-pill badge-status-ok">
          <span class="w-2 h-2 rounded-full bg-white"></span>พร้อมใช้งาน
        </span>
        <button onclick="openHolidayManager()" class="btn-ghost" title="จัดการวันหยุดราชการ">
          <i data-lucide="calendar-heart" class="w-4 h-4"></i>วันหยุด <span id="hdrHolidayCount" class="bg-white/30 px-1.5 rounded-full text-[10px]">0</span>
        </button>
        <button onclick="openAddNurse()" class="btn-ghost">
          <i data-lucide="user-plus" class="w-4 h-4"></i>เพิ่มพยาบาล
        </button>
        <button onclick="exportSchedule('xlsx')" class="btn-ghost">
          <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>Excel
        </button>
        <button onclick="exportSchedule('pdf')" class="btn-ghost">
          <i data-lucide="file-text" class="w-4 h-4"></i>PDF
        </button>
        <button onclick="window.print()" class="btn-ghost">
          <i data-lucide="printer" class="w-4 h-4"></i>พิมพ์
        </button>
      </div>
    </div>
  </div>

  <!-- Sub-header gradient with filters/staffing -->
  <div class="ncds-subheader border-t border-white/10">
    <div class="max-w-[1600px] mx-auto px-6 py-3">
      <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-2 items-end">
        <!-- Month -->
        <div class="filter-card">
          <div class="filter-label"><i data-lucide="calendar" class="w-3 h-3"></i> เดือน</div>
          <select id="monthSelect" class="filter-select" onchange="onMonthChange()"></select>
        </div>
        <!-- Year -->
        <div class="filter-card">
          <div class="filter-label"><i data-lucide="calendar-days" class="w-3 h-3"></i> ปี (พ.ศ.)</div>
          <select id="yearSelect" class="filter-select" onchange="onMonthChange()"></select>
        </div>
        <!-- Weekday staffing -->
        <div class="filter-card" style="min-width:0">
          <div class="filter-label"><i data-lucide="sun" class="w-3 h-3"></i> วันธรรมดา ช/บ/ด</div>
          <div class="flex gap-1 mt-1 w-full">
            <input type="number" id="reqWdCh" min="0" max="20" value="3" class="flex-1 min-w-0 text-center text-sm font-semibold text-blue-700 border rounded px-1 py-0.5">
            <input type="number" id="reqWdBa" min="0" max="20" value="2" class="flex-1 min-w-0 text-center text-sm font-semibold text-blue-700 border rounded px-1 py-0.5">
            <input type="number" id="reqWdDu" min="0" max="20" value="2" class="flex-1 min-w-0 text-center text-sm font-semibold text-blue-700 border rounded px-1 py-0.5">
          </div>
        </div>
        <!-- Weekend staffing -->
        <div class="filter-card" style="min-width:0">
          <div class="filter-label"><i data-lucide="moon" class="w-3 h-3"></i> วันหยุด ช/บ/ด</div>
          <div class="flex gap-1 mt-1 w-full">
            <input type="number" id="reqWeCh" min="0" max="20" value="2" class="flex-1 min-w-0 text-center text-sm font-semibold text-blue-700 border rounded px-1 py-0.5">
            <input type="number" id="reqWeBa" min="0" max="20" value="2" class="flex-1 min-w-0 text-center text-sm font-semibold text-blue-700 border rounded px-1 py-0.5">
            <input type="number" id="reqWeDu" min="0" max="20" value="2" class="flex-1 min-w-0 text-center text-sm font-semibold text-blue-700 border rounded px-1 py-0.5">
          </div>
        </div>
        <!-- Action buttons (4 columns wide on lg) -->
        <div class="lg:col-span-4 flex gap-2 justify-end items-stretch flex-wrap">
          <button onclick="runAutoSchedule()" class="btn-solid bg-white text-cyan-700 hover:bg-cyan-50 font-semibold shadow-md">
            <i data-lucide="wand-2" class="w-4 h-4"></i> จัดเวรอัตโนมัติ
          </button>
          <button onclick="saveAll()" class="btn-solid bg-emerald-500 hover:bg-emerald-600 text-white shadow-md">
            <i data-lucide="save" class="w-4 h-4"></i> บันทึก
          </button>
          <button onclick="clearAll()" class="btn-solid bg-red-500/90 hover:bg-red-600 text-white">
            <i data-lucide="eraser" class="w-4 h-4"></i> ล้าง
          </button>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- ============== TABS ============== -->
<div class="max-w-[1600px] mx-auto px-6 mt-4 no-print">
  <nav class="tab-nav">
    <div class="tab-btn active" data-tab="dashboard" onclick="switchTab('dashboard')">
      <i data-lucide="layout-dashboard" class="w-4 h-4"></i> ภาพรวม
    </div>
    <div class="tab-btn" data-tab="schedule" onclick="switchTab('schedule')">
      <i data-lucide="calendar-check-2" class="w-4 h-4"></i> ตารางเวร <span class="count-bubble" id="tabCountDays">31</span>
    </div>
    <div class="tab-btn" data-tab="leaves" onclick="switchTab('leaves')">
      <i data-lucide="palmtree" class="w-4 h-4"></i> วันลา <span class="count-bubble" id="tabCountLeaves">0</span>
    </div>
    <div class="tab-btn" data-tab="summary" onclick="switchTab('summary')">
      <i data-lucide="bar-chart-3" class="w-4 h-4"></i> สรุป
    </div>
    <div class="tab-btn" data-tab="ot" onclick="switchTab('ot')">
      <i data-lucide="wallet" class="w-4 h-4"></i> เงินเดือน <span class="count-bubble" id="tabCountOT">0</span>
    </div>
    <div class="tab-btn" data-tab="nurses" onclick="switchTab('nurses')">
      <i data-lucide="users" class="w-4 h-4"></i> พยาบาล <span class="count-bubble" id="tabCountNurses">0</span>
    </div>
    <div class="tab-btn" data-tab="help" onclick="switchTab('help')">
      <i data-lucide="help-circle" class="w-4 h-4"></i> คู่มือ
    </div>
  </nav>
</div>

<!-- ============== MAIN CONTENT ============== -->
<main class="max-w-[1600px] mx-auto px-6 py-4 pb-20">

  <!-- =========== TAB: DASHBOARD =========== -->
  <section id="tab-dashboard" class="tab-panel fade-in-up">

    <!-- 6 NCDs-style compact stat cards (half-width) -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 mb-5">
      <div class="stat-card card-teal">
        <div class="flex items-start justify-between relative z-10">
          <div class="stat-icon-box"><i data-lucide="calendar-days"></i></div>
        </div>
        <div class="relative z-10">
          <div class="stat-value" id="dashDays">31</div>
          <div class="stat-label">วันในเดือน</div>
          <div class="stat-sub" id="dashMonthLabel">ตุลาคม 2569</div>
        </div>
      </div>

      <div class="stat-card card-blue">
        <div class="flex items-start justify-between relative z-10">
          <div class="stat-icon-box"><i data-lucide="users"></i></div>
        </div>
        <div class="relative z-10">
          <div class="stat-value" id="dashNurses">0</div>
          <div class="stat-label">พยาบาลทั้งหมด</div>
          <div class="stat-sub" id="dashNursesSub">หอผู้ป่วย</div>
        </div>
      </div>

      <div class="stat-card card-amber">
        <div class="flex items-start justify-between relative z-10">
          <div class="stat-icon-box"><i data-lucide="check-circle-2"></i></div>
        </div>
        <div class="relative z-10">
          <div class="stat-value" id="dashShifts">0</div>
          <div class="stat-label">เวรที่จัดแล้ว</div>
          <div class="stat-sub" id="dashShiftPct">0%</div>
        </div>
      </div>

      <div class="stat-card card-purple">
        <div class="flex items-start justify-between relative z-10">
          <div class="stat-icon-box"><i data-lucide="palmtree"></i></div>
        </div>
        <div class="relative z-10">
          <div class="stat-value" id="dashLeaves">0</div>
          <div class="stat-label">วันลา</div>
          <div class="stat-sub">V + T รวม</div>
        </div>
      </div>

      <div class="stat-card card-orange">
        <div class="flex items-start justify-between relative z-10">
          <div class="stat-icon-box"><i data-lucide="alert-triangle"></i></div>
        </div>
        <div class="relative z-10">
          <div class="stat-value" id="dashWarnings">0</div>
          <div class="stat-label">คำเตือน</div>
          <div class="stat-sub">จาก algorithm</div>
        </div>
      </div>

      <div class="stat-card card-red">
        <div class="flex items-start justify-between relative z-10">
          <div class="stat-icon-box"><i data-lucide="banknote"></i></div>
        </div>
        <div class="relative z-10">
          <div class="stat-value" id="dashOTAmount">0</div>
          <div class="stat-label">เงินเดือนรวม (บาท)</div>
          <div class="stat-sub" id="dashOTCount">0 ชม.</div>
        </div>
      </div>
    </div>

    <!-- Distribution charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <!-- Shift type distribution -->
      <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-semibold text-slate-700 flex items-center gap-2">
            <i data-lucide="pie-chart" class="w-4 h-4 text-cyan-600"></i> การกระจายประเภทเวร
          </h3>
          <span class="text-xs text-slate-500">รวมทุกพยาบาล</span>
        </div>
        <div id="shiftDistribution" class="space-y-2"></div>
      </div>

      <!-- Workload per nurse -->
      <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-semibold text-slate-700 flex items-center gap-2">
            <i data-lucide="bar-chart-2" class="w-4 h-4 text-cyan-600"></i> ภาระงาน Top 10
          </h3>
          <span class="text-xs text-slate-500">เรียงจากมากไปน้อย</span>
        </div>
        <div id="workloadChart" class="space-y-1.5 max-h-80 overflow-y-auto"></div>
      </div>
    </div>

    <!-- Warning panel -->
    <div id="warningPanel" class="mt-4 hidden">
      <div class="bg-amber-50 border-l-4 border-amber-400 rounded-r-xl p-4">
        <div class="flex items-center gap-2 mb-2">
          <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600"></i>
          <h4 class="font-semibold text-amber-900">คำเตือนจาก Algorithm</h4>
        </div>
        <ul id="warningList" class="text-sm text-amber-800 space-y-1 ml-7 list-disc"></ul>
      </div>
    </div>
  </section>

  <!-- =========== TAB: SCHEDULE =========== -->
  <section id="tab-schedule" class="tab-panel hidden fade-in-up">
    <!-- Shift palette -->
    <div class="mb-3 flex items-center gap-3 flex-wrap">
      <span class="text-sm font-medium text-slate-700">เลือกเวรเพื่อกรอก:</span>
      <div class="shift-palette" id="shiftPalette"></div>
      <button onclick="state.selectedShift = null; renderShiftPalette();"
              class="btn-solid bg-slate-200 text-slate-700 hover:bg-slate-300 text-xs ml-auto">
        <i data-lucide="mouse-pointer" class="w-3.5 h-3.5"></i> ยกเลิกเลือก
      </button>
    </div>

    <!-- Schedule table -->
    <div class="schedule-wrapper">
      <table class="schedule-table" id="scheduleTable"></table>
    </div>

    <!-- Legend -->
    <div class="mt-4 bg-white rounded-xl p-3 border border-slate-200 flex items-center gap-2 flex-wrap text-xs">
      <span class="font-medium text-slate-600 mr-2">คำอธิบาย:</span>
      <span class="px-2 py-1 rounded font-bold" style="background:#fef9c3;color:#854d0e">ช</span> เช้า
      <span class="px-2 py-1 rounded font-bold" style="background:#bae6fd;color:#075985">บ</span> บ่าย
      <span class="px-2 py-1 rounded font-bold" style="background:#a5f3fc;color:#155e75">ด</span> ดึก
      <span class="px-2 py-1 rounded font-bold" style="background:#fcd34d;color:#78350f">ชบ</span> โย้หน้า
      <span class="px-2 py-1 rounded font-bold" style="background:#5eead4;color:#134e4a">ดบ</span> โย้หลัง
      <span class="px-2 py-1 rounded font-bold" style="background:#c4b5fd;color:#4c1d95">DN</span> Day+Night
      <span class="px-2 py-1 rounded font-bold" style="background:#e2e8f0;color:#475569">O</span> OFF
      <span class="px-2 py-1 rounded font-bold" style="background:#bbf7d0;color:#14532d">V</span> ลาพักร้อน
      <span class="px-2 py-1 rounded font-bold" style="background:#fbcfe8;color:#831843">T</span> ลาประชุม
    </div>
  </section>

  <!-- =========== TAB: LEAVES =========== -->
  <section id="tab-leaves" class="tab-panel hidden fade-in-up">
    <div class="mb-3 flex items-center gap-3 flex-wrap">
      <span class="text-sm font-medium text-slate-700">เลือกประเภทวันลา:</span>
      <div class="shift-palette" id="leavePalette"></div>
      <div class="ml-auto bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 text-xs text-amber-800">
        <i data-lucide="info" class="w-3.5 h-3.5 inline"></i>
        วันลาจะ "ล็อค" ในตารางเวรอัตโนมัติ
      </div>
    </div>
    <div class="schedule-wrapper">
      <table class="schedule-table" id="leavesTable"></table>
    </div>
  </section>

  <!-- =========== TAB: SUMMARY =========== -->
  <section id="tab-summary" class="tab-panel hidden fade-in-up">
    <div id="summaryGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"></div>
  </section>

  <!-- =========== TAB: PAYROLL (เงินเดือน/ค่าตอบแทน) =========== -->
  <section id="tab-ot" class="tab-panel hidden fade-in-up">
    <!-- Payroll info card -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200 mb-4">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h3 class="font-semibold text-slate-700 flex items-center gap-2">
          <i data-lucide="wallet" class="w-4 h-4 text-cyan-600"></i> เงินเดือน/ค่าตอบแทน
          <span class="text-xs font-normal text-slate-500">(ชั่วโมงปฏิบัติงาน × อัตรา/ชม.)</span>
        </h3>
        <div class="flex gap-2">
          <button onclick="openTimesheetSettings()" class="btn-solid btn-info text-sm" title="ตั้งค่าอัตราต่อชั่วโมง default + ภาษี">
            <i data-lucide="settings" class="w-3.5 h-3.5"></i> ตั้งค่า default
          </button>
          <button onclick="refreshNurseRates(true)" class="btn-solid btn-warning text-sm" title="ดึงอัตรา/ชม. รายคนจาก server ใหม่">
            <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> รีเฟรชอัตรา
          </button>
        </div>
      </div>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200">
          <div class="text-xs text-slate-500">อัตรา default (บาท/ชม.)</div>
          <div class="font-bold text-cyan-700 text-lg" id="payrollDefaultRate">—</div>
        </div>
        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200">
          <div class="text-xs text-slate-500">อัตราภาษี ณ ที่จ่าย</div>
          <div class="font-bold text-rose-700 text-lg"><span id="payrollTaxRate">—</span>%</div>
        </div>
        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200">
          <div class="text-xs text-slate-500">ผู้ลงนาม</div>
          <div class="font-semibold text-slate-700 truncate" id="payrollSigner">—</div>
        </div>
        <div class="p-3 rounded-xl bg-amber-50 border border-amber-200">
          <div class="text-xs text-amber-700">💡 อัตรารายบุคคล</div>
          <div class="text-xs text-amber-900">ไปที่แท็บ "พยาบาล" → "ข้อมูลใบลงเวลา"</div>
        </div>
      </div>
    </div>

    <!-- Summary cards -->
    <div id="otSummaryCards" class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4"></div>

    <!-- Send to Cash Book -->
    <?php
    $_ns_role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? '';
    $_ns_hasFinance = in_array($_ns_role, ['admin', 'superadmin'], true) || !empty($_SESSION['access_finance']);
    ?>
    <?php if ($_ns_hasFinance): ?>
    <div class="mb-4 p-3 rounded-xl border border-emerald-200 bg-emerald-50 flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-2 text-sm text-emerald-800">
            <i data-lucide="link" class="w-4 h-4 text-emerald-600"></i>
            <span>ส่งเงินเดือนรวมของเดือนนี้เข้าระบบการเงิน (Cash Book) — รายจ่ายหมวด "เงินเดือน/ค่าจ้าง"</span>
        </div>
        <button onclick="sendPayrollToFinance()" class="btn-solid bg-emerald-600 text-white hover:bg-emerald-700 text-sm">
            <i data-lucide="banknote" class="w-3.5 h-3.5"></i> ส่งเงินเดือนทั้งเดือนเข้า Cash Book
        </button>
    </div>
    <?php endif; ?>

    <!-- Payroll Table -->
    <div class="bg-white rounded-2xl p-3 border border-slate-200 overflow-x-auto">
      <div class="flex items-center justify-between mb-3 px-2 flex-wrap gap-2">
        <h3 class="font-semibold text-slate-700 flex items-center gap-2">
          <i data-lucide="table" class="w-4 h-4 text-cyan-600"></i> รายงานเงินเดือน/ค่าตอบแทน
        </h3>
        <div class="flex gap-2">
          <button onclick="exportOT('xlsx')" class="btn-solid btn-success text-xs">
            <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5"></i> Excel
          </button>
        </div>
      </div>
      <table class="ot-table" id="otTable"></table>
    </div>
  </section>

  <!-- =========== TAB: NURSES =========== -->
  <section id="tab-nurses" class="tab-panel hidden fade-in-up">
    <div class="bg-white rounded-2xl p-5 border border-slate-200">
      <div class="flex items-center justify-between mb-4">
        <h3 class="font-semibold text-slate-700 flex items-center gap-2">
          <i data-lucide="users" class="w-4 h-4 text-cyan-600"></i> ทะเบียนพยาบาล
        </h3>
        <div class="flex items-center gap-2">
          <button onclick="openImportNurses()" class="btn-solid btn-success text-sm" title="นำเข้าจาก Identity + ผังองค์กร">
            <i data-lucide="download" class="w-3.5 h-3.5"></i> นำเข้ารายชื่อ
          </button>
          <button onclick="openTimesheetSettings()" class="btn-solid btn-info text-sm" title="ตั้งค่าใบลงเวลา (ชื่อคลินิก/ผู้ลงนาม/ภาษี)">
            <i data-lucide="settings" class="w-3.5 h-3.5"></i> ตั้งค่าใบลงเวลา
          </button>
          <button onclick="openManagePositions()" class="btn-solid btn-warning text-sm" title="เพิ่ม/แก้ไขตำแหน่ง">
            <i data-lucide="badge-plus" class="w-3.5 h-3.5"></i> จัดการตำแหน่ง
          </button>
          <button onclick="openAddNurse()" class="btn-solid btn-primary text-sm">
            <i data-lucide="user-plus" class="w-3.5 h-3.5"></i> เพิ่มพยาบาล
          </button>
        </div>
      </div>
      <div id="nursesList" class="space-y-2"></div>
    </div>
  </section>

  <!-- =========== TAB: HELP =========== -->
  <section id="tab-help" class="tab-panel hidden fade-in-up">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <div class="help-card warn">
        <h4 class="font-semibold text-amber-900 mb-2 flex items-center gap-2">
          <i data-lucide="calendar-heart" class="w-4 h-4"></i> วันหยุดราชการ (ใหม่ v2.7.1)
        </h4>
        <p class="text-sm text-amber-800">ระบบรู้จัก <b>วันหยุดราชการไทย</b> อัตโนมัติทั้ง:</p>
        <ul class="text-xs text-amber-700 mt-1 ml-5 list-disc space-y-0.5">
          <li><b>วันหยุดตามวันที่ตายตัว</b> เช่น 1 ม.ค., 6 เม.ย., 13-15 เม.ย., 1 พ.ค., 4 พ.ค., 28 ก.ค., 12 ส.ค., 13 ต.ค., 23 ต.ค., 5 ธ.ค., 10 ธ.ค., 31 ธ.ค.</li>
          <li><b>วันหยุดทางพระพุทธศาสนา</b> (มาฆบูชา, วิสาขบูชา, อาสาฬหบูชา, เข้าพรรษา) — มีในระบบครบสำหรับปี 2569-2575</li>
          <li><b>วันหยุดพิเศษ ครม.</b> (ถ้ามี เช่น 2 ม.ค. 2569)</li>
        </ul>
        <p class="text-xs text-amber-800 mt-2">📌 คลิกปุ่ม <b>"วันหยุด"</b> บน header เพื่อดู/เพิ่ม/ลบวันหยุดแต่ละเดือน</p>
        <p class="text-xs text-amber-800 mt-1">⚠️ ในวันหยุด หัวหน้า/รองหัวหน้า = <b>O</b> และระบบใช้ <b>อัตรากำลังวันหยุด</b></p>
      </div>

      <div class="help-card">
        <h4 class="font-semibold text-cyan-800 mb-2 flex items-center gap-2">
          <i data-lucide="rocket" class="w-4 h-4"></i> เริ่มต้นใช้งาน
        </h4>
        <ol class="text-sm text-slate-700 space-y-1 ml-5 list-decimal">
          <li>แท็บ <b>พยาบาล</b> → เพิ่มรายชื่อให้ครบ (ระบบ seed 12 คนตัวอย่าง)</li>
          <li>แท็บ <b>วันลา</b> → กรอก V/T ที่ทราบล่วงหน้า</li>
          <li>ปรับ <b>อัตรากำลัง</b> ที่ sub-header (ช/บ/ด วันธรรมดา/วันหยุด)</li>
          <li>กดปุ่ม <b>จัดเวรอัตโนมัติ</b> — Algorithm 7 ขั้นจะรัน</li>
          <li>แก้รายช่อง: เลือกเวรจาก palette → คลิกที่ตาราง</li>
          <li>กด <b>บันทึก</b> → ข้อมูลถูกเก็บใน LocalStorage</li>
        </ol>
      </div>

      <div class="help-card warn">
        <h4 class="font-semibold text-amber-900 mb-2 flex items-center gap-2">
          <i data-lucide="alert-octagon" class="w-4 h-4"></i> กฎห้าม (Hard Rule)
        </h4>
        <p class="text-sm text-amber-800 mb-2">ห้ามขึ้น <b>เวรบ่ายวันนี้ → เวรดึกวันถัดไป</b> (คนเดียวกัน)</p>
        <p class="text-xs text-amber-700">ครอบคลุม pattern ทั้งหมด:<br>
        บ→ด, บ→ดบ, บ→DN, ชบ→ด, ชบ→ดบ, ชบ→DN, ดบ→ด, ดบ→ดบ, ดบ→DN</p>
        <p class="text-xs text-amber-700 mt-2">✅ <b>อนุญาต:</b> ด → ทุกอย่าง วันถัดไป, ดบ/ชบ/DN ในวันเดียวกัน</p>
      </div>

      <div class="help-card">
        <div class="flex items-center justify-between mb-2">
          <h4 class="font-semibold text-cyan-800 flex items-center gap-2">
            <i data-lucide="layers" class="w-4 h-4"></i> ประเภทเวร 9 แบบ
          </h4>
          <button onclick="openEditShiftTypes()" class="btn-solid btn-primary text-xs" title="แก้ชื่อ + สี">
            <i data-lucide="palette" class="w-3 h-3"></i> ปรับแต่ง
          </button>
        </div>
        <div id="shiftTypesLegend" class="grid grid-cols-3 gap-2 text-xs"></div>
        <p class="text-xs text-slate-500 mt-3">⚠️ ใช้ <b>"ดบ"</b> เท่านั้น (ไม่ใช่ "บด") · รหัส (label) แก้ไม่ได้ · แก้ได้เฉพาะชื่อ+สี</p>
      </div>

      <div class="help-card success">
        <h4 class="font-semibold text-emerald-900 mb-2 flex items-center gap-2">
          <i data-lucide="cog" class="w-4 h-4"></i> Algorithm 7 ขั้น
        </h4>
        <ol class="text-xs text-emerald-800 space-y-0.5 ml-5 list-decimal">
          <li>Apply วันลา V/T (ล็อค)</li>
          <li>Heads ขึ้น ช วันทำการ + O เสาร์อาทิตย์</li>
          <li>Main pass — ลำดับ ด → ช → บ (filter บ→ด)</li>
          <li>Distribute Special Shifts (ชบ/ดบ ทุกคน 1-3 ครั้ง)</li>
          <li>Auto-fix บ→ด ที่หลุด (swap/replace)</li>
          <li>tryFillSpecialShifts กฎ ก/ข/ค/ง + aggressiveFill 3 รอบ</li>
          <li>Fill OFF + Validation + Report warnings</li>
        </ol>
      </div>

      <div class="help-card">
        <h4 class="font-semibold text-cyan-800 mb-2 flex items-center gap-2">
          <i data-lucide="database" class="w-4 h-4"></i> ที่เก็บข้อมูล
        </h4>
        <p class="text-sm text-slate-700">ระบบใช้ <b>LocalStorage</b> ของเบราว์เซอร์เก็บข้อมูล:</p>
        <ul class="text-xs text-slate-600 mt-2 ml-5 list-disc space-y-0.5">
          <li>ข้อมูลถูกเก็บในเบราว์เซอร์เครื่องนี้เท่านั้น</li>
          <li>ล้าง cache เบราว์เซอร์ = ข้อมูลหาย</li>
          <li>แนะนำ Export Excel เก็บไว้สม่ำเสมอ</li>
          <li>เปิดในเครื่องอื่น = เริ่มข้อมูลใหม่</li>
        </ul>
      </div>
    </div>
  </section>

</main>


<!-- ============== JAVASCRIPT ============== -->
<script>
// ========= CONSTANTS =========
const DEFAULT_SHIFT_TYPES = {
  'ช':  { label: 'ช',  name: 'เช้า',       bg: '#fef9c3', fg: '#854d0e', order: 1, startTime: '08:00', endTime: '16:00', hours: 8 },
  'บ':  { label: 'บ',  name: 'บ่าย',       bg: '#bae6fd', fg: '#075985', order: 2, startTime: '16:00', endTime: '20:00', hours: 4 },
  'ด':  { label: 'ด',  name: 'ดึก',        bg: '#a5f3fc', fg: '#155e75', order: 3, startTime: '00:00', endTime: '08:00', hours: 8 },
  'ชบ': { label: 'ชบ', name: 'โย้หน้า',     bg: '#fcd34d', fg: '#78350f', order: 4, startTime: '08:00', endTime: '20:00', hours: 12 },
  'ดบ': { label: 'ดบ', name: 'โย้หลัง',     bg: '#5eead4', fg: '#134e4a', order: 5, startTime: '16:00', endTime: '08:00', hours: 16 },
  'DN': { label: 'DN', name: 'Day+Night',  bg: '#c4b5fd', fg: '#4c1d95', order: 6, startTime: '08:00', endTime: '08:00', hours: 24 },
  'O':  { label: 'O',  name: 'OFF',         bg: '#e2e8f0', fg: '#475569', order: 7, hours: 0 },
  'V':  { label: 'V',  name: 'ลาพักร้อน',   bg: '#bbf7d0', fg: '#14532d', order: 8, hours: 0 },
  'T':  { label: 'T',  name: 'ลาประชุม',    bg: '#fbcfe8', fg: '#831843', order: 9, hours: 0 }
};
// SHIFT_TYPES = defaults merged with state.shiftTypes (user overrides)
let SHIFT_TYPES = structuredClone(DEFAULT_SHIFT_TYPES);
function applyShiftTypeOverrides() {
  SHIFT_TYPES = structuredClone(DEFAULT_SHIFT_TYPES);
  for (const k in SHIFT_TYPES) SHIFT_TYPES[k].enabled = true; // default: ทุกตัวเปิดใช้
  const ov = state.shiftTypes || {};
  for (const k in ov) {
    if (SHIFT_TYPES[k] && ov[k]) {
      // override only name/bg/fg/enabled — never label (code) or order
      if (ov[k].name)      SHIFT_TYPES[k].name      = ov[k].name;
      if (ov[k].bg)        SHIFT_TYPES[k].bg        = ov[k].bg;
      if (ov[k].fg)        SHIFT_TYPES[k].fg        = ov[k].fg;
      if (ov[k].startTime) SHIFT_TYPES[k].startTime = ov[k].startTime;
      if (ov[k].endTime)   SHIFT_TYPES[k].endTime   = ov[k].endTime;
      if (typeof ov[k].hours === 'number') SHIFT_TYPES[k].hours = ov[k].hours;
      if (ov[k].enabled === false) SHIFT_TYPES[k].enabled = false;
    }
  }
}
function isShiftEnabled(code) { return SHIFT_TYPES[code]?.enabled !== false; }

const POSITIONS = {
  'หัวหน้าหอผู้ป่วย':    { icon: '⭐', cls: 'pos-head',   weekdayMorningOnly: true,  maxOne: true,  system: true },
  'รองหัวหน้าหอผู้ป่วย': { icon: '🌟', cls: 'pos-deputy', weekdayMorningOnly: true,  maxOne: false, system: true },
  'พยาบาลหัวหน้าเวร':    { icon: '💎', cls: 'pos-shift',  weekdayMorningOnly: false, maxOne: false, system: true },
  'พยาบาลวิชาชีพ':       { icon: '👤', cls: 'pos-rn',     weekdayMorningOnly: false, maxOne: false, system: true },
  'พยาบาลเทคนิค':        { icon: '🔧', cls: 'pos-tech',   weekdayMorningOnly: false, maxOne: false, system: true },
  'ผู้ช่วยพยาบาล':        { icon: '🤝', cls: 'pos-aide',   weekdayMorningOnly: false, maxOne: false, system: true },
  'เจ้าหน้าที่':            { icon: '🧑‍💼', cls: 'pos-staff',  weekdayMorningOnly: false, maxOne: false, system: true }
};

// ── Custom positions: inject CSS rules for user-defined badges ──
function ensureCustomPosStyles() {
  let el = document.getElementById('custom-pos-styles');
  if (!el) { el = document.createElement('style'); el.id = 'custom-pos-styles'; document.head.appendChild(el); }
  let css = '';
  for (const [, def] of Object.entries(state.customPositions || {})) {
    if (def.cls && def.color) css += `.${def.cls}{background:${def.color};color:#fff;}\n`;
  }
  el.textContent = css;
}

// Generate safe CSS class name from position label (Thai → ASCII fallback)
function makeCustomPosCls(name) {
  const hash = Array.from(name).reduce((h, c) => ((h << 5) - h + c.charCodeAt(0)) | 0, 0);
  return 'pos-custom-' + Math.abs(hash).toString(36);
}

const THAI_MONTHS = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
                     'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
const THAI_DAYS_SHORT = ['อา','จ','อ','พ','พฤ','ศ','ส'];

const DEFAULT_NURSES = [
  { id:'N001', name:'นางสาวสมหญิง พยาบาลดี',    position:'หัวหน้าหอผู้ป่วย',    order:1, active:true },
  { id:'N002', name:'นางสาวมาลี ใจดีงาม',       position:'รองหัวหน้าหอผู้ป่วย', order:2, active:true },
  { id:'N003', name:'นางสาวจันทร์ พริ้งเพรา',   position:'พยาบาลหัวหน้าเวร',    order:3, active:true },
  { id:'N004', name:'นางสาวอัญชลี สดใส',        position:'พยาบาลหัวหน้าเวร',    order:4, active:true },
  { id:'N005', name:'นางสาวปิยะดา ทองดี',       position:'พยาบาลวิชาชีพ',       order:5, active:true },
  { id:'N006', name:'นางสาวศิริพร แสงสว่าง',    position:'พยาบาลวิชาชีพ',       order:6, active:true },
  { id:'N007', name:'นางสาวพรทิพย์ ขยันยิ่ง',    position:'พยาบาลวิชาชีพ',       order:7, active:true },
  { id:'N008', name:'นางสาวรัตนา สุขใจ',        position:'พยาบาลวิชาชีพ',       order:8, active:true },
  { id:'N009', name:'นางสาวกาญจนา รักงาน',     position:'พยาบาลวิชาชีพ',       order:9, active:true },
  { id:'N010', name:'นางสาวนภาพร ตั้งใจ',       position:'พยาบาลเทคนิค',       order:10, active:true },
  { id:'N011', name:'นางสาวสุดา ขยันมาก',       position:'พยาบาลเทคนิค',       order:11, active:true },
  { id:'N012', name:'นางสาววันดี ช่วยเหลือ',     position:'ผู้ช่วยพยาบาล',       order:12, active:true }
];

const DEFAULT_OT = { threshold:18, rates:{'ช':600,'บ':600,'ด':720,'ชบ':1200,'ดบ':1320,'DN':1320} };
const DEFAULT_REQ = { weekday:{ch:3,ba:2,du:2}, weekend:{ch:2,ba:2,du:2} };
const STORAGE_KEY = 'smnc_nurse_schedule_v271';

// ===== THAI PUBLIC HOLIDAYS (วันหยุดราชการ) =====
// Fixed-date holidays — recur on the same M-D every year
const FIXED_HOLIDAYS = {
  '1-1':   'วันขึ้นปีใหม่',
  '4-6':   'วันจักรี',
  '4-13':  'วันสงกรานต์',
  '4-14':  'วันสงกรานต์ (วันครอบครัว)',
  '4-15':  'วันสงกรานต์',
  '5-1':   'วันแรงงานแห่งชาติ',
  '5-4':   'วันฉัตรมงคล',
  '6-3':   'วันเฉลิมพระชนมพรรษาพระราชินี',
  '7-28':  'วันเฉลิมพระชนมพรรษา ร.10',
  '8-12':  'วันแม่แห่งชาติ',
  '10-13': 'วันคล้ายวันสวรรคต ร.9',
  '10-23': 'วันปิยมหาราช',
  '12-5':  'วันพ่อแห่งชาติ',
  '12-10': 'วันรัฐธรรมนูญ',
  '12-31': 'วันสิ้นปี'
};

// Variable-date holidays — Buddhist/lunar calendar, change each year
// Key = BE year, value = {'M-D': name}
const VARIABLE_HOLIDAYS = {
  2569: {  // 2026
    '1-2':   'วันหยุดพิเศษตามมติ ครม.',
    '3-3':   'วันมาฆบูชา',
    '5-13':  'วันพืชมงคล',
    '5-31':  'วันวิสาขบูชา',
    '6-1':   'หยุดชดเชยวันวิสาขบูชา',
    '7-29':  'วันอาสาฬหบูชา',
    '7-30':  'วันเข้าพรรษา'
  },
  2570: {  // 2027
    '2-21':  'วันมาฆบูชา',
    '5-20':  'วันวิสาขบูชา',
    '7-18':  'วันอาสาฬหบูชา',
    '7-19':  'วันเข้าพรรษา'
  },
  2571: {  // 2028 (best-effort, please verify)
    '3-11':  'วันมาฆบูชา',
    '5-8':   'วันวิสาขบูชา',
    '7-6':   'วันอาสาฬหบูชา',
    '7-7':   'วันเข้าพรรษา'
  },
  2572: {  // 2029 (best-effort, please verify)
    '2-28':  'วันมาฆบูชา',
    '5-27':  'วันวิสาขบูชา',
    '7-25':  'วันอาสาฬหบูชา',
    '7-26':  'วันเข้าพรรษา'
  },
  2573: {  // 2030 (best-effort, please verify)
    '2-17':  'วันมาฆบูชา',
    '5-16':  'วันวิสาขบูชา',
    '7-14':  'วันอาสาฬหบูชา',
    '7-15':  'วันเข้าพรรษา'
  },
  2574: {  // 2031 (best-effort, please verify)
    '3-8':   'วันมาฆบูชา',
    '5-6':   'วันวิสาขบูชา',
    '7-4':   'วันอาสาฬหบูชา',
    '7-5':   'วันเข้าพรรษา'
  },
  2575: {  // 2032 (best-effort, please verify)
    '2-26':  'วันมาฆบูชา',
    '5-24':  'วันวิสาขบูชา',
    '7-22':  'วันอาสาฬหบูชา',
    '7-23':  'วันเข้าพรรษา'
  }
};

// ========= STATE =========
const state = {
  year: 2569,  // พ.ศ.
  month: new Date().getMonth() + 1,
  nurses: [],
  schedule: {},      // key: 'nurseId-day' → shift code
  leaves: {},        // key: 'nurseId-day' → 'V' | 'T'
  requirements: structuredClone(DEFAULT_REQ),
  otSettings: structuredClone(DEFAULT_OT),
  customHolidays: {},   // key: 'yBE-M-D' → name (user-added overrides/additions)
  removedHolidays: {},  // key: 'yBE-M-D' → true (built-in holidays user removed)
  clinicHolidays: {},   // key: 'yBE-M-D' → note (จาก sys_clinic_hours — read-only)
  shiftTypes: {},       // key: 'ช'|'บ'|... → {name?, bg?, fg?} overrides ของ DEFAULT_SHIFT_TYPES
  customPositions: {},  // key: position label → {icon, cls, color, weekdayMorningOnly, maxOne}
  selectedShift: null,
  selectedLeave: 'V',
  otReport: [],
  timesheetSettings: { default_hourly_rate: 120, tax_rate: 3, signer_name: '', signer_title: '', clinic_name: '' },
  warnings: [],
  dirty: false
};

// ========= HELPERS =========
const k = (nid, d) => `${nid}-${d}`;
const getShift = (nid, d) => state.schedule[k(nid,d)] || '';
const setShift = (nid, d, s) => { if(s){ state.schedule[k(nid,d)] = s; } else { delete state.schedule[k(nid,d)]; } state.dirty = true; };
const getLeave = (nid, d) => state.leaves[k(nid,d)] || '';
const setLeave = (nid, d, l) => { if(l){ state.leaves[k(nid,d)] = l; } else { delete state.leaves[k(nid,d)]; } state.dirty = true; };
const isWorking = s => s && ['ช','บ','ด','ชบ','ดบ','DN'].includes(s);
const isLeave = s => s === 'V' || s === 'T';
const includesAfternoon = s => s === 'บ' || s === 'ชบ' || s === 'ดบ';
const includesNight = s => s === 'ด' || s === 'ดบ' || s === 'DN';
const daysInMonth = (yBE, m) => new Date(yBE - 543, m, 0).getDate();
const isWeekend = (yBE, m, d) => { const dow = new Date(yBE-543, m-1, d).getDay(); return dow === 0 || dow === 6; };
const dayOfWeek = (yBE, m, d) => new Date(yBE-543, m-1, d).getDay();
const dayLabel = (yBE, m, d) => THAI_DAYS_SHORT[dayOfWeek(yBE, m, d)];
const todayBE = () => { const t = new Date(); return { y: t.getFullYear()+543, m: t.getMonth()+1, d: t.getDate() }; };

// Public-holiday lookup — returns holiday name or null
function isHoliday(yBE, m, d) {
  const key = `${m}-${d}`;
  const customKey = `${yBE}-${m}-${d}`;
  // User removed this holiday → not a holiday
  if (state.removedHolidays && state.removedHolidays[customKey]) return null;
  // Clinic calendar (sys_clinic_hours) — read-only จากปฏิทินคลินิก, มีน้ำหนักสูง
  if (state.clinicHolidays && state.clinicHolidays[customKey]) return state.clinicHolidays[customKey];
  // User added custom
  if (state.customHolidays && state.customHolidays[customKey]) return state.customHolidays[customKey];
  // Variable holidays for this specific year
  if (VARIABLE_HOLIDAYS[yBE] && VARIABLE_HOLIDAYS[yBE][key]) return VARIABLE_HOLIDAYS[yBE][key];
  // Fixed holidays (recurring)
  if (FIXED_HOLIDAYS[key]) return FIXED_HOLIDAYS[key];
  return null;
}
// Check if a day is a clinic-defined closure (different from public holidays)
function isClinicHoliday(yBE, m, d) {
  return !!(state.clinicHolidays && state.clinicHolidays[`${yBE}-${m}-${d}`]);
}
// "Rest day" = treated like weekend for staffing/heads rule
const isRestDay = (yBE, m, d) => isWeekend(yBE, m, d) || isHoliday(yBE, m, d) !== null;

// Get list of all holidays in a month (for UI display)
function getMonthHolidays(yBE, m) {
  const days = daysInMonth(yBE, m);
  const list = [];
  for (let d=1; d<=days; d++) {
    const name = isHoliday(yBE, m, d);
    if (name) list.push({ day: d, name, isWeekend: isWeekend(yBE, m, d) });
  }
  return list;
}

// ========= STORAGE =========
// ========= SERVER SYNC (Phase 2) =========
const NS_AJAX = 'ajax_nurse_schedule.php';
const NS_CSRF = <?= json_encode($NS_CSRF_TOKEN, JSON_UNESCAPED_SLASHES) ?>;
let _saveTimer = null;
let _isSaving = false;

function _showSaveStatus(text, cls) {
  let el = document.getElementById('ns-save-status');
  if (!el) {
    el = document.createElement('div');
    el.id = 'ns-save-status';
    el.style.cssText = 'position:fixed;bottom:14px;right:14px;z-index:9999;'
      + 'padding:7px 14px;border-radius:999px;font-size:12px;font-weight:700;'
      + 'box-shadow:0 4px 12px rgba(0,0,0,0.12);transition:opacity .3s,transform .3s;'
      + 'opacity:0;transform:translateY(8px);';
    document.body.appendChild(el);
  }
  el.textContent = text;
  el.style.background = cls === 'err' ? '#fee2e2' : (cls === 'ok' ? '#dcfce7' : '#dbeafe');
  el.style.color      = cls === 'err' ? '#991b1b' : (cls === 'ok' ? '#166534' : '#1e40af');
  el.style.opacity = '1'; el.style.transform = 'translateY(0)';
  clearTimeout(el._hideTimer);
  if (cls === 'ok') {
    el._hideTimer = setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(8px)'; }, 1800);
  }
}

async function serverLoad(year, month) {
  try {
    const r = await fetch(`${NS_AJAX}?action=load&year=${year}&month=${month}`,
      { credentials: 'same-origin' });
    const j = await r.json();
    if (!j.ok) { console.warn('serverLoad failed', j.error); return null; }
    return j.data || null;
  } catch (e) {
    console.warn('serverLoad error', e);
    return null;
  }
}

async function serverSave() {
  if (_isSaving) return;
  _isSaving = true;
  _showSaveStatus('กำลังบันทึก…', '');
  try {
    const payload = {
      nurses: state.nurses,
      schedule: state.schedule,
      leaves: state.leaves,
      requirements: state.requirements,
      otSettings: state.otSettings,
      customHolidays: state.customHolidays || {},
      removedHolidays: state.removedHolidays || {},
      shiftTypes: state.shiftTypes || {},
      customPositions: state.customPositions || {},
    };
    const fd = new FormData();
    fd.append('csrf_token', NS_CSRF);
    fd.append('action', 'save');
    fd.append('year', String(state.year));
    fd.append('month', String(state.month));
    fd.append('payload', JSON.stringify(payload));
    const r = await fetch(NS_AJAX, { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await r.json();
    if (j.ok) _showSaveStatus('บันทึกแล้ว ✓', 'ok');
    else _showSaveStatus('บันทึกล้มเหลว: ' + (j.error || ''), 'err');
  } catch (e) {
    _showSaveStatus('ออฟไลน์ · เก็บใน browser', 'err');
  } finally {
    _isSaving = false;
  }
}

function persistAll() {
  // เก็บใน localStorage เป็น cache ก่อน (เร็ว instant)
  const data = {
    year: state.year, month: state.month,
    nurses: state.nurses,
    schedule: state.schedule, leaves: state.leaves,
    requirements: state.requirements, otSettings: state.otSettings,
    customHolidays: state.customHolidays || {},
    removedHolidays: state.removedHolidays || {},
    shiftTypes: state.shiftTypes || {},
    customPositions: state.customPositions || {},
    savedAt: new Date().toISOString()
  };
  localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  state.dirty = false;

  // Debounced save to server (800ms after last edit)
  clearTimeout(_saveTimer);
  _saveTimer = setTimeout(serverSave, 800);
}

async function loadFromStorage() {
  // ลอง server ก่อน (ของจริง shared ทุกคน)
  const serverData = await serverLoad(state.year, state.month);
  if (serverData) {
    state.nurses          = (Array.isArray(serverData.nurses) && serverData.nurses.length)
                             ? serverData.nurses : structuredClone(DEFAULT_NURSES);
    state.schedule        = serverData.schedule || {};
    state.leaves          = serverData.leaves || {};
    state.requirements    = serverData.requirements || structuredClone(DEFAULT_REQ);
    state.otSettings      = serverData.otSettings || structuredClone(DEFAULT_OT);
    state.customHolidays  = serverData.customHolidays || {};
    state.removedHolidays = serverData.removedHolidays || {};
    state.clinicHolidays  = serverData.clinicHolidays || {};
    state.shiftTypes      = serverData.shiftTypes || {};
    state.customPositions = serverData.customPositions || {};
    for (const [name, def] of Object.entries(state.customPositions)) POSITIONS[name] = def;
    ensureCustomPosStyles();
    applyShiftTypeOverrides();
    // Migration: "บด" → "ดบ"
    for (const key in state.schedule) {
      if (state.schedule[key] === 'บด') state.schedule[key] = 'ดบ';
    }
    enforceHeadsWeekdayOnly();
    // อัปเดต localStorage cache
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      year: state.year, month: state.month,
      nurses: state.nurses, schedule: state.schedule, leaves: state.leaves,
      requirements: state.requirements, otSettings: state.otSettings,
      shiftTypes: state.shiftTypes, customPositions: state.customPositions,
      customHolidays: state.customHolidays, removedHolidays: state.removedHolidays,
      savedAt: new Date().toISOString(),
    }));
    return true;
  }

  // Fallback: localStorage (offline หรือ server ไม่ตอบ)
  const raw = localStorage.getItem(STORAGE_KEY);
  if (!raw) {
    state.nurses = structuredClone(DEFAULT_NURSES);
    return false;
  }
  try {
    const data = JSON.parse(raw);
    state.nurses = data.nurses || structuredClone(DEFAULT_NURSES);
    state.schedule = data.schedule || {};
    state.leaves = data.leaves || {};
    state.requirements = data.requirements || structuredClone(DEFAULT_REQ);
    state.otSettings = data.otSettings || structuredClone(DEFAULT_OT);
    state.customHolidays = data.customHolidays || {};
    state.removedHolidays = data.removedHolidays || {};
    state.shiftTypes = data.shiftTypes || {};
    state.customPositions = data.customPositions || {};
    for (const [name, def] of Object.entries(state.customPositions)) POSITIONS[name] = def;
    ensureCustomPosStyles();
    applyShiftTypeOverrides();
    for (const key in state.schedule) {
      if (state.schedule[key] === 'บด') state.schedule[key] = 'ดบ';
    }
    enforceHeadsWeekdayOnly();
    return true;
  } catch(e) { console.error('Load error', e); return false; }
}

// ========= SWAL HELPERS =========
const showLoading = (txt='กำลังประมวลผล...') => Swal.fire({
  title: txt,
  html: '<div class="loader-spinner mx-auto my-4"></div>',
  showConfirmButton: false,
  allowOutsideClick: false,
  background: '#f0f9ff'
});
const showSuccess = (txt) => Swal.fire({ icon:'success', title:txt, timer:1800, showConfirmButton:false, toast:true, position:'top' });
const showError = (txt) => Swal.fire({ icon:'error', title:'ผิดพลาด', text:txt });
const showWarn = (txt) => Swal.fire({ icon:'warning', title:'แจ้งเตือน', text:txt, toast:true, position:'top', timer:2500, showConfirmButton:false });
const showInfo = (txt) => Swal.fire({ icon:'info', title:'แจ้งเตือน', text:txt });
const confirmAct = (title, text) => Swal.fire({ icon:'question', title, text, showCancelButton:true, confirmButtonText:'ยืนยัน', cancelButtonText:'ยกเลิก', confirmButtonColor:'#2e9e63' });

// ========= INIT =========
function initSelectors() {
  const mSel = document.getElementById('monthSelect');
  THAI_MONTHS.forEach((mn, i) => {
    const o = document.createElement('option');
    o.value = i+1; o.textContent = mn;
    if (i+1 === state.month) o.selected = true;
    mSel.appendChild(o);
  });
  const ySel = document.getElementById('yearSelect');
  for (let y = 2569; y <= 2575; y++) {
    const o = document.createElement('option');
    o.value = y; o.textContent = y;
    if (y === state.year) o.selected = true;
    ySel.appendChild(o);
  }
}

function loadReqToUI() {
  document.getElementById('reqWdCh').value = state.requirements.weekday.ch;
  document.getElementById('reqWdBa').value = state.requirements.weekday.ba;
  document.getElementById('reqWdDu').value = state.requirements.weekday.du;
  document.getElementById('reqWeCh').value = state.requirements.weekend.ch;
  document.getElementById('reqWeBa').value = state.requirements.weekend.ba;
  document.getElementById('reqWeDu').value = state.requirements.weekend.du;
}

// (loadOTToUI ถูกแทนที่ — อัตราต่อชั่วโมงย้ายไปอยู่ใน sys_nurse_timesheet_settings + รายคน)
function loadOTToUI() { /* no-op */ }

function readReqFromUI() {
  state.requirements = {
    weekday: {
      ch: +document.getElementById('reqWdCh').value || 3,
      ba: +document.getElementById('reqWdBa').value || 2,
      du: +document.getElementById('reqWdDu').value || 2
    },
    weekend: {
      ch: +document.getElementById('reqWeCh').value || 2,
      ba: +document.getElementById('reqWeBa').value || 2,
      du: +document.getElementById('reqWeDu').value || 2
    }
  };
}

// (readOTFromUI removed — OT rate settings ย้ายไปเป็น hourly rate รายคน + default rate ใน timesheet settings)
function readOTFromUI() { /* no-op: kept for backward callsite compatibility */ }

// ========= TABS =========
function switchTab(name) {
  document.querySelectorAll('.tab-panel').forEach(el => el.classList.add('hidden'));
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-'+name).classList.remove('hidden');
  document.querySelector(`.tab-btn[data-tab="${name}"]`).classList.add('active');

  if (name === 'schedule') renderSchedule();
  else if (name === 'leaves') renderLeaves();
  else if (name === 'summary') renderSummary();
  else if (name === 'ot') { readOTFromUI(); renderOT(); }
  else if (name === 'nurses') renderNursesList();
  else if (name === 'dashboard') renderDashboard();

  lucide.createIcons();
}

// ========= PALETTES =========
// แสดง legend "ประเภทเวร 9 แบบ" จาก SHIFT_TYPES (รวม override ของผู้ใช้)
function renderShiftTypesLegend() {
  const wrap = document.getElementById('shiftTypesLegend');
  if (!wrap) return;
  const order = ['ช','บ','ด','ชบ','ดบ','DN','O','V','T'];
  wrap.innerHTML = order.map(code => {
    const t = SHIFT_TYPES[code];
    if (!t) return '';
    const isDefault = !state.shiftTypes?.[code];
    const disabled = t.enabled === false;
    const title = disabled ? 'ปิดใช้งานแล้ว' : (isDefault ? 'ค่ามาตรฐาน' : 'ปรับแต่งแล้ว');
    return `<div title="${title}" style="${disabled?'opacity:0.4;text-decoration:line-through':''}">
      <span style="display:inline-block;min-width:26px;padding:1px 6px;border-radius:6px;background:${t.bg};color:${t.fg};font-weight:800;text-align:center;margin-right:4px">${t.label}</span>${t.name}${disabled?' <span class="text-rose-500 text-[10px]">(ปิด)</span>':''}
    </div>`;
  }).join('');
}

window.openEditShiftTypes = function() {
  const order = ['ช','บ','ด','ชบ','ดบ','DN','O','V','T'];
  const rows = order.map(code => {
    const t = SHIFT_TYPES[code];
    const enabled = t.enabled !== false;
    return `<tr>
      <td style="padding:6px 8px;text-align:center;font-weight:900;background:${t.bg};color:${t.fg};border-radius:6px;min-width:36px">${t.label}</td>
      <td style="padding:4px 6px"><input class="st-name swal2-input" data-code="${code}" value="${t.name.replace(/"/g,'&quot;')}" style="margin:0;font-size:13px;height:32px;padding:4px 8px;width:100%"></td>
      <td style="padding:4px 6px;text-align:center"><input class="st-bg" data-code="${code}" type="color" value="${t.bg}" style="width:36px;height:32px;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer"></td>
      <td style="padding:4px 6px;text-align:center"><input class="st-fg" data-code="${code}" type="color" value="${t.fg}" style="width:36px;height:32px;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer"></td>
      <td style="padding:4px 6px;text-align:center"><label style="display:inline-flex;align-items:center;cursor:pointer"><input class="st-enabled" data-code="${code}" type="checkbox" ${enabled?'checked':''} style="width:18px;height:18px;cursor:pointer"></label></td>
    </tr>`;
  }).join('');
  Swal.fire({
    title: 'ปรับแต่งประเภทเวร',
    html: `<p class="text-xs text-slate-500 text-left mb-3">รหัส (label) แก้ไม่ได้ — ตั้งชื่อ/สี/เปิด-ปิดใช้งานได้ · ปิดแล้วจะไม่ขึ้นใน palette + ตัวจัดเวรอัตโนมัติจะข้าม · กด "คืนค่ามาตรฐาน" เพื่อ reset ทั้งหมด</p>
      <table style="width:100%;font-size:13px">
        <thead><tr style="font-size:11px;color:#64748b">
          <th style="padding:4px 6px">รหัส</th><th style="padding:4px 6px;text-align:left">ชื่อ</th><th style="padding:4px 6px">พื้น</th><th style="padding:4px 6px">อักษร</th><th style="padding:4px 6px">ใช้งาน</th>
        </tr></thead>
        <tbody>${rows}</tbody>
      </table>`,
    showCancelButton: true,
    showDenyButton: true,
    confirmButtonText: 'บันทึก',
    denyButtonText: 'คืนค่ามาตรฐาน',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#2e9e63',
    denyButtonColor: '#64748b',
    width: 560,
    preConfirm: () => {
      const overrides = {};
      document.querySelectorAll('.st-name').forEach(inp => {
        const code = inp.dataset.code;
        const name = inp.value.trim();
        const bg = document.querySelector(`.st-bg[data-code="${code}"]`).value;
        const fg = document.querySelector(`.st-fg[data-code="${code}"]`).value;
        const enabledEl = document.querySelector(`.st-enabled[data-code="${code}"]`);
        const enabled = enabledEl ? enabledEl.checked : true;
        const def = DEFAULT_SHIFT_TYPES[code];
        // เก็บเฉพาะที่เปลี่ยนจาก default
        const diff = {};
        if (name && name !== def.name) diff.name = name;
        if (bg && bg.toLowerCase() !== def.bg.toLowerCase()) diff.bg = bg;
        if (fg && fg.toLowerCase() !== def.fg.toLowerCase()) diff.fg = fg;
        if (!enabled) diff.enabled = false; // เก็บแค่กรณีปิด (default = true)
        if (Object.keys(diff).length) overrides[code] = diff;
      });
      return overrides;
    }
  }).then(r => {
    if (r.isDenied) {
      // คืนค่ามาตรฐานทั้งหมด
      state.shiftTypes = {};
      applyShiftTypeOverrides();
      state.dirty = true;
      persistAll();
      renderShiftTypesLegend();
      renderShiftPalette();
      renderLeavePalette();
      renderSchedule();
      renderLeaves();
      Swal.fire({ icon: 'success', title: 'คืนค่ามาตรฐานแล้ว', timer: 1200, showConfirmButton: false });
      return;
    }
    if (!r.isConfirmed) return;
    state.shiftTypes = r.value || {};
    applyShiftTypeOverrides();
    state.dirty = true;
    persistAll();
    renderShiftTypesLegend();
    renderShiftPalette();
    renderLeavePalette();
    renderSchedule();
    renderLeaves();
    Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false });
  });
};

function renderShiftPalette() {
  const wrap = document.getElementById('shiftPalette');
  wrap.innerHTML = '';
  ['ช','บ','ด','ชบ','ดบ','DN','O'].forEach(s => {
    const def = SHIFT_TYPES[s];
    if (!def || def.enabled === false) return; // ปิดใช้ → ไม่แสดงใน palette
    const btn = document.createElement('div');
    btn.className = 'shift-btn' + (state.selectedShift === s ? ' selected' : '');
    btn.style.background = def.bg; btn.style.color = def.fg;
    btn.title = def.name;
    btn.innerHTML = `${def.label} <span class="text-[10px] opacity-70 ml-1">${def.name}</span>`;
    btn.onclick = () => { state.selectedShift = (state.selectedShift === s) ? null : s; renderShiftPalette(); };
    wrap.appendChild(btn);
  });
  // eraser
  const er = document.createElement('div');
  er.className = 'shift-btn' + (state.selectedShift === '' ? ' selected' : '');
  er.style.background = '#fee2e2'; er.style.color = '#991b1b';
  er.innerHTML = `🧽 <span class="text-[10px] opacity-70 ml-1">ลบ</span>`;
  er.onclick = () => { state.selectedShift = (state.selectedShift === '') ? null : ''; renderShiftPalette(); };
  wrap.appendChild(er);
}

function renderLeavePalette() {
  const wrap = document.getElementById('leavePalette');
  wrap.innerHTML = '';
  ['V','T'].forEach(l => {
    const def = SHIFT_TYPES[l];
    const btn = document.createElement('div');
    btn.className = 'shift-btn' + (state.selectedLeave === l ? ' selected' : '');
    btn.style.background = def.bg; btn.style.color = def.fg;
    btn.innerHTML = `${def.label} <span class="text-[10px] opacity-70 ml-1">${def.name}</span>`;
    btn.onclick = () => { state.selectedLeave = l; renderLeavePalette(); };
    wrap.appendChild(btn);
  });
  const er = document.createElement('div');
  er.className = 'shift-btn' + (state.selectedLeave === '' ? ' selected' : '');
  er.style.background = '#fee2e2'; er.style.color = '#991b1b';
  er.innerHTML = `🧽 ลบวันลา`;
  er.onclick = () => { state.selectedLeave = ''; renderLeavePalette(); };
  wrap.appendChild(er);
}


// ========= SCHEDULE TABLE =========
function renderSchedule() {
  const tbl = document.getElementById('scheduleTable');
  const days = daysInMonth(state.year, state.month);
  const tdy = todayBE();
  let html = '<thead>';
  // Row 1: day numbers
  html += `<tr>
    <th rowspan="2" class="sticky-col" style="min-width:36px">ที่</th>
    <th rowspan="2" class="sticky-col-2" style="min-width:150px;left:36px">ชื่อ-นามสกุล</th>
    <th rowspan="2" class="sticky-col-3" style="min-width:110px;left:186px">ตำแหน่ง</th>`;
  for (let d=1; d<=days; d++) {
    const we = isRestDay(state.year, state.month, d);
    const holidayName = isHoliday(state.year, state.month, d);
    const clinicSource = isClinicHoliday(state.year, state.month, d);
    const today = tdy.y===state.year && tdy.m===state.month && tdy.d===d;
    const tipText = holidayName ? (clinicSource ? '🏥 ' + holidayName + ' (จากปฏิทินคลินิก)' : holidayName) : '';
    const tip = tipText ? ` title="${tipText.replace(/"/g,'&quot;')}"` : '';
    const indicator = holidayName
      ? (clinicSource
          ? '<sup style="font-size:9px;color:#fca5a5" aria-label="คลินิกปิด">🏥</sup>'
          : '<sup style="font-size:8px;color:#fde047">★</sup>')
      : '';
    html += `<th class="${we?'weekend-header':''}" style="min-width:38px;${today?'background:linear-gradient(135deg,#f59e0b,#d97706)!important;':''}"${tip}>${d}${indicator}</th>`;
  }
  ['ช','บ','ด','ชบ','ดบ','DN','O','V','T','รวม'].forEach(s => {
    const isRolledCol = (s === 'ช' || s === 'บ' || s === 'ด');
    const lbl = isRolledCol ? `${s}<br><span style="font-size:9px;font-weight:300;opacity:0.85">(รวม)</span>` : s;
    html += `<th rowspan="2" class="stat-col" style="min-width:36px;background:#0c4a6e">${lbl}</th>`;
  });
  html += '</tr><tr>';
  for (let d=1; d<=days; d++) {
    const we = isRestDay(state.year, state.month, d);
    const holidayName = isHoliday(state.year, state.month, d);
    const tip = holidayName ? ` title="${holidayName.replace(/"/g,'&quot;')}"` : '';
    html += `<th class="${we?'weekend-header':''}" style="font-size:10px;font-weight:400;opacity:0.85"${tip}>${dayLabel(state.year, state.month, d)}</th>`;
  }
  html += '</tr></thead><tbody>';

  state.nurses.filter(n=>n.active!==false).sort((a,b)=>(a.order||999)-(b.order||999)).forEach((n,idx) => {
    const posCls = POSITIONS[n.position]?.cls || 'pos-rn';
    html += `<tr data-nurse="${n.id}">
      <td class="sticky-col text-center text-slate-500">${idx+1}</td>
      <td class="sticky-col-2 text-left px-2 font-medium text-slate-700" style="left:36px">${n.name}</td>
      <td class="sticky-col-3 px-2" style="left:186px"><span class="pos-badge ${posCls}">${n.position}</span></td>`;
    for (let d=1; d<=days; d++) {
      const we = isRestDay(state.year, state.month, d);
      const shift = getShift(n.id, d);
      const lv = getLeave(n.id, d);
      let bg = '', fg = '#475569', txt = '';
      if (lv) { bg = SHIFT_TYPES[lv].bg; fg = SHIFT_TYPES[lv].fg; txt = lv; }
      else if (shift) { bg = SHIFT_TYPES[shift].bg; fg = SHIFT_TYPES[shift].fg; txt = shift; }
      html += `<td class="day-cell ${we?'weekend-cell':''}" style="background:${bg};color:${fg}" onclick="onCellClick('${n.id}',${d})" data-day="${d}">${txt}</td>`;
    }
    // stats — ช/บ/ด columns show EFFECTIVE counts (include ชบ/ดบ/DN), others show raw
    const st = computeNurseStats(n.id);
    const statValues = [
      st.chTotal,        // ช = ช + ชบ + DN
      st.baTotal,        // บ = บ + ชบ + ดบ
      st.duTotal,        // ด = ด + ดบ + DN
      st['ชบ']||0,
      st['ดบ']||0,
      st['DN']||0,
      st['O']||0,
      st['V']||0,
      st['T']||0
    ];
    statValues.forEach(v => {
      html += `<td class="stat-col">${v||''}</td>`;
    });
    html += `<td class="stat-col font-bold text-cyan-700">${st.total}</td>`;
    html += '</tr>';
  });
  html += '</tbody>';
  tbl.innerHTML = html;
}

function computeNurseStats(nid) {
  const days = daysInMonth(state.year, state.month);
  const st = { 'ช':0,'บ':0,'ด':0,'ชบ':0,'ดบ':0,'DN':0,'O':0,'V':0,'T':0, total:0,
               chTotal:0, baTotal:0, duTotal:0 };  // effective counts (include special shifts)
  for (let d=1; d<=days; d++) {
    const s = getShift(nid, d);
    const l = getLeave(nid, d);
    if (l) { st[l] = (st[l]||0)+1; continue; }
    if (s) {
      st[s] = (st[s]||0)+1;
      if (isWorking(s)) st.total++;
      // Auto-compute effective ช/บ/ด from compound shifts:
      // ชบ = morning + afternoon → count as ช +1 AND บ +1
      // ดบ = night + afternoon → count as ด +1 AND บ +1
      // DN = morning + night → count as ช +1 AND ด +1
      if (s === 'ช' || s === 'ชบ' || s === 'DN') st.chTotal++;
      if (s === 'บ' || s === 'ชบ' || s === 'ดบ') st.baTotal++;
      if (s === 'ด' || s === 'ดบ' || s === 'DN') st.duTotal++;
    }
  }
  return st;
}

// Update tab badge counts and dashboard headline counters (debounced caller for cheap re-render)
function updateBadgeCounts() {
  const days = daysInMonth(state.year, state.month);
  document.getElementById('tabCountDays').textContent = days;
  let leaveCount = 0;
  const active = state.nurses.filter(n=>n.active!==false);
  active.forEach(n => {
    for (let d=1; d<=days; d++) if (getLeave(n.id, d)) leaveCount++;
  });
  document.getElementById('tabCountLeaves').textContent = leaveCount;
  document.getElementById('tabCountNurses').textContent = active.length;
}

function onCellClick(nid, d) {
  if (state.selectedShift === null) {
    showWarn('เลือกประเภทเวรจาก palette ก่อน');
    return;
  }
  // locked by leave?
  if (getLeave(nid, d)) {
    showWarn(`วันที่ ${d} เป็นวันลา — ลบจากแท็บ "วันลา" ก่อน`);
    return;
  }
  const nurse = state.nurses.find(n => n.id === nid);
  if (!nurse) return;
  const pos = POSITIONS[nurse.position];
  const cur = getShift(nid, d);
  const s = state.selectedShift;

  // eraser
  if (s === '') { setShift(nid, d, null); refreshRow(nid); return; }

  // same shift = erase
  if (cur === s) { setShift(nid, d, null); refreshRow(nid); return; }

  // Head restriction
  if (pos.weekdayMorningOnly) {
    const we = isRestDay(state.year, state.month, d);
    if (s !== 'O' && s !== 'ช') {
      if (we) { showWarn(`${nurse.position} วันหยุด (เสาร์-อาทิตย์/วันหยุดราชการ) ต้อง O เท่านั้น`); return; }
      else { showWarn(`${nurse.position} วันทำการต้อง ช หรือ O เท่านั้น`); return; }
    }
    if (s === 'ช' && we) { showWarn(`${nurse.position} ขึ้น ช เฉพาะวันทำการ (ไม่ใช่วันหยุด)`); return; }
  }

  // maxOne check (head)
  if (pos.maxOne && isWorking(s)) {
    const others = state.nurses.filter(o => o.id !== nid && o.active!==false && POSITIONS[o.position]?.maxOne);
    for (const o of others) {
      if (isWorking(getShift(o.id, d))) {
        showWarn(`${nurse.position} มีได้ 1 คนต่อวัน (${o.name} ขึ้นอยู่)`); return;
      }
    }
  }

  // Validation: afternoon → night
  if (includesAfternoon(s)) {
    if (d+1 <= daysInMonth(state.year, state.month)) {
      const nxt = getShift(nid, d+1);
      if (includesNight(nxt)) {
        showWarn(`ห้าม ${s} → ${nxt} (วันถัดไป)`); return;
      }
    }
  }
  if (includesNight(s)) {
    if (d-1 >= 1) {
      const prev = getShift(nid, d-1);
      if (includesAfternoon(prev)) {
        showWarn(`ห้าม ${prev} (เมื่อวาน) → ${s}`); return;
      }
    }
  }

  setShift(nid, d, s);
  refreshRow(nid);
}

function refreshRow(nid) {
  const days = daysInMonth(state.year, state.month);
  const row = document.querySelector(`#scheduleTable tr[data-nurse="${nid}"]`);
  if (!row) return;
  for (let d=1; d<=days; d++) {
    const cell = row.querySelector(`td[data-day="${d}"]`);
    if (!cell) continue;
    const shift = getShift(nid, d);
    const lv = getLeave(nid, d);
    let bg='', fg='#475569', txt='';
    if (lv) { bg=SHIFT_TYPES[lv].bg; fg=SHIFT_TYPES[lv].fg; txt=lv; }
    else if (shift) { bg=SHIFT_TYPES[shift].bg; fg=SHIFT_TYPES[shift].fg; txt=shift; }
    cell.style.background = bg; cell.style.color = fg; cell.textContent = txt;
  }
  const st = computeNurseStats(nid);
  const cells = row.querySelectorAll('.stat-col');
  // Auto-update stats: ช/บ/ด columns reflect effective totals including ชบ/ดบ/DN
  const statValues = [
    st.chTotal, st.baTotal, st.duTotal,
    st['ชบ']||0, st['ดบ']||0, st['DN']||0,
    st['O']||0, st['V']||0, st['T']||0
  ];
  statValues.forEach((v, i) => { if (cells[i]) cells[i].textContent = v||''; });
  if (cells[9]) cells[9].textContent = st.total;
  state.dirty = true;
  // Update dashboard badge counts in real-time
  updateBadgeCounts();
  // Live-recompute OT (background) — so when user switches to OT tab it's already fresh
  if (typeof renderOT === 'function') {
    try { renderOT(); } catch(e) { /* OT tab DOM might not exist yet */ }
  }
}

// ========= LEAVES TABLE =========
function renderLeaves() {
  const tbl = document.getElementById('leavesTable');
  const days = daysInMonth(state.year, state.month);
  const tdy = todayBE();
  let html = '<thead><tr>';
  html += `<th class="sticky-col" style="min-width:36px">ที่</th>
    <th class="sticky-col-2" style="min-width:150px;left:36px">ชื่อ-นามสกุล</th>
    <th class="sticky-col-3" style="min-width:110px;left:186px">ตำแหน่ง</th>`;
  for (let d=1; d<=days; d++) {
    const we = isRestDay(state.year, state.month, d);
    const holidayName = isHoliday(state.year, state.month, d);
    const today = tdy.y===state.year && tdy.m===state.month && tdy.d===d;
    const tip = holidayName ? ` title="${holidayName.replace(/"/g,'&quot;')}"` : '';
    const indicator = holidayName ? '<sup style="font-size:8px;color:#fde047">★</sup>' : '';
    html += `<th class="${we?'weekend-header':''}" style="min-width:38px;${today?'background:linear-gradient(135deg,#f59e0b,#d97706)!important;':''}"${tip}>${d}${indicator}<br><span style="font-size:10px;opacity:0.85">${dayLabel(state.year, state.month, d)}</span></th>`;
  }
  html += '<th class="stat-col" style="background:#0c4a6e">V</th><th class="stat-col" style="background:#0c4a6e">T</th></tr></thead><tbody>';

  state.nurses.filter(n=>n.active!==false).sort((a,b)=>(a.order||999)-(b.order||999)).forEach((n,idx) => {
    const posCls = POSITIONS[n.position]?.cls || 'pos-rn';
    html += `<tr data-leave-nurse="${n.id}">
      <td class="sticky-col text-center text-slate-500">${idx+1}</td>
      <td class="sticky-col-2 text-left px-2 font-medium text-slate-700" style="left:36px">${n.name}</td>
      <td class="sticky-col-3 px-2" style="left:186px"><span class="pos-badge ${posCls}">${n.position}</span></td>`;
    let v=0, t=0;
    for (let d=1; d<=days; d++) {
      const we = isRestDay(state.year, state.month, d);
      const lv = getLeave(n.id, d);
      let bg='', fg='#475569', txt='';
      if (lv) { bg=SHIFT_TYPES[lv].bg; fg=SHIFT_TYPES[lv].fg; txt=lv; if(lv==='V') v++; else if(lv==='T') t++; }
      html += `<td class="day-cell ${we?'weekend-cell':''}" style="background:${bg};color:${fg}" onclick="onLeaveClick('${n.id}',${d})" data-lday="${d}">${txt}</td>`;
    }
    html += `<td class="stat-col font-bold text-emerald-700">${v||''}</td><td class="stat-col font-bold text-pink-700">${t||''}</td>`;
    html += '</tr>';
  });
  html += '</tbody>';
  tbl.innerHTML = html;
}

function onLeaveClick(nid, d) {
  const cur = getLeave(nid, d);
  const newL = state.selectedLeave;
  if (cur === newL) { setLeave(nid, d, null); }
  else if (newL === '') { setLeave(nid, d, null); }
  else {
    setLeave(nid, d, newL);
    // remove any schedule on this day for this nurse
    setShift(nid, d, null);
  }
  // refresh row in leaves table
  renderLeaves();
}


// ========= ALGORITHM (7 steps) =========
function runAutoSchedule() {
  readReqFromUI();
  if (state.nurses.filter(n=>n.active!==false).length === 0) {
    showError('ยังไม่มีพยาบาลในระบบ — กรุณาเพิ่มพยาบาลก่อน'); return;
  }
  confirmAct('จัดเวรอัตโนมัติ?', 'ระบบจะล้างตารางเวรเดิม (วันลายังคงอยู่)').then(r => {
    if (!r.isConfirmed) return;
    showLoading('กำลังจัดเวรอัตโนมัติ...');
    setTimeout(() => {
      autoScheduleCore();
      Swal.close();
      renderSchedule();
      renderDashboard();
      // Auto-refresh OT tab (in case user is on it or visits it next)
      readOTFromUI();
      renderOT();
      const wc = state.warnings.length;
      if (wc === 0) showSuccess('จัดเวรเสร็จสมบูรณ์ ✓');
      else showInfo(`จัดเวรเสร็จ แต่มี ${wc} คำเตือน — ดูที่แท็บภาพรวม`);
    }, 100);
  });
}

// ----- STRICT ENFORCEMENT FUNCTIONS -----
// Hard rule: หัวหน้า/รองหัวหน้า must be ช on working days and O on rest days (weekend + holidays).
// V/T leaves are preserved.
function enforceHeadsWeekdayOnly() {
  const days = daysInMonth(state.year, state.month);
  let fixed = 0;
  const violations = [];
  state.nurses.forEach(n => {
    if (!POSITIONS[n.position]?.weekdayMorningOnly) return;
    for (let d=1; d<=days; d++) {
      if (getLeave(n.id, d)) continue;  // preserve V/T leaves
      const rest = isRestDay(state.year, state.month, d);
      const cur = getShift(n.id, d);
      if (rest) {
        // Weekend/Holiday → only O allowed
        if (cur !== 'O') {
          violations.push(`${n.name} วันที่ ${d}(วันหยุด) เคยเป็น "${cur}" → แก้เป็น "O"`);
          setShift(n.id, d, 'O');
          fixed++;
        }
      } else {
        // Working day → only ช allowed
        if (cur !== 'ช') {
          violations.push(`${n.name} วันที่ ${d}(วันทำการ) เคยเป็น "${cur}" → แก้เป็น "ช"`);
          setShift(n.id, d, 'ช');
          fixed++;
        }
      }
    }
  });
  if (fixed > 0) console.log(`[enforceHeadsWeekdayOnly] Fixed ${fixed} cells`, violations);
  return fixed;
}

// Backup: ensure every active nurse has SOMETHING in every cell (shift or leave)
function fillAllEmpty() {
  const days = daysInMonth(state.year, state.month);
  let filled = 0;
  state.nurses.filter(n=>n.active!==false).forEach(n => {
    for (let d=1; d<=days; d++) {
      const hasShift = state.schedule[k(n.id, d)];
      const hasLeave = state.leaves[k(n.id, d)];
      if (!hasShift && !hasLeave) {
        state.schedule[k(n.id, d)] = 'O';
        filled++;
      }
    }
  });
  if (filled > 0) console.log(`[fillAllEmpty] Filled ${filled} empty cells with O`);
  state.dirty = true;
  return filled;
}

function autoScheduleCore() {
  const days = daysInMonth(state.year, state.month);
  const nurses = state.nurses.filter(n=>n.active!==false).sort((a,b)=>(a.order||999)-(b.order||999));
  state.warnings = [];

  // ----- Clear schedule (keep leaves) -----
  for (const key in state.schedule) delete state.schedule[key];

  // เตือนถ้าเวรหลักถูกปิดใช้งาน (ยังจัดได้แต่ผลจะไม่สมบูรณ์)
  ['ช','บ','ด'].forEach(c => { if (!isShiftEnabled(c)) state.warnings.push(`ปิดเวร "${SHIFT_TYPES[c]?.name||c}" → ตัวจัดอัตโนมัติจะข้าม`); });

  // ----- Step 1: Apply leaves are already in state.leaves -----

  // ----- Step 2: Heads / Deputies -----
  const headRestShift = isShiftEnabled('O') ? 'O' : '';
  const headWorkShift = isShiftEnabled('ช') ? 'ช' : '';
  if (headWorkShift) {
    nurses.forEach(n => {
      const p = POSITIONS[n.position];
      if (!p || !p.weekdayMorningOnly) return;
      for (let d=1; d<=days; d++) {
        if (getLeave(n.id, d)) continue;
        const code = isRestDay(state.year, state.month, d) ? headRestShift : headWorkShift;
        if (code) setShift(n.id, d, code);
      }
    });
  }

  // ----- Step 3: Main pass ด → ช → บ (ข้ามตัวที่ถูกปิด) -----
  const passOrder = ['ด','ช','บ'].filter(isShiftEnabled);
  for (const targetShift of passOrder) {
    for (let d=1; d<=days; d++) {
      const we = isRestDay(state.year, state.month, d);
      const req = we ? state.requirements.weekend : state.requirements.weekday;
      let needed;
      if (targetShift === 'ช') needed = req.ch - countShiftOnDay(d, 'ช');
      else if (targetShift === 'บ') needed = req.ba - countShiftOnDay(d, 'บ');
      else needed = req.du - countShiftOnDay(d, 'ด');
      if (needed <= 0) continue;

      // candidates
      let cands = nurses.filter(n => {
        if (getShift(n.id, d)) return false;
        if (getLeave(n.id, d)) return false;
        const p = POSITIONS[n.position];
        if (p && p.weekdayMorningOnly) return false;
        return canPlace(n.id, d, targetShift);
      });
      // additional filter for 'ด': prev day no afternoon
      if (targetShift === 'ด') {
        cands = cands.filter(n => {
          if (d-1 < 1) return true;
          return !includesAfternoon(getShift(n.id, d-1));
        });
      }
      // additional filter for 'บ': next day no night
      if (targetShift === 'บ') {
        cands = cands.filter(n => {
          if (d+1 > days) return true;
          return !includesNight(getShift(n.id, d+1));
        });
      }
      // score
      cands.forEach(n => { n._score = scoreNurse(n, d, targetShift); });
      cands.sort((a,b) => a._score - b._score);
      for (let i=0; i<needed && i<cands.length; i++) {
        setShift(cands[i].id, d, targetShift);
      }
    }
  }

  // ----- Step 3.5: distributeSpecialShifts -----
  // GOAL: Each nurse gets ≥1 "ชบ" AND ≥1 "ดบ" if possible (ข้ามตัวที่ถูกปิด)
  const chbaOn = isShiftEnabled('ชบ');
  const dubaOn = isShiftEnabled('ดบ');
  nurses.forEach(n => {
    if (!chbaOn && !dubaOn) return;
    if (POSITIONS[n.position]?.weekdayMorningOnly) return;
    let chbaCount = 0, dubaCount = 0;
    for (let d=1; d<=days; d++) {
      const s = getShift(n.id, d);
      if (s === 'ชบ') chbaCount++;
      if (s === 'ดบ') dubaCount++;
    }
    let needChba = chbaOn ? Math.max(0, 1 - chbaCount) : 0;
    let needDuba = dubaOn ? Math.max(0, 1 - dubaCount) : 0;

    // --- Phase A: upgrade ช → ชบ (each nurse should get at least 1 ชบ) ---
    // Constraints: next day must NOT include night (no บ→ด rule)
    for (let d=1; d<=days && needChba>0; d++) {
      if (getShift(n.id, d) !== 'ช') continue;
      if (d+1 <= days && includesNight(getShift(n.id, d+1))) continue;
      setShift(n.id, d, 'ชบ');
      needChba--;
    }

    // --- Phase B: upgrade บ → ดบ (each nurse should get at least 1 ดบ) ---
    // Constraints: prev day must NOT include afternoon, next day must NOT include night
    for (let d=1; d<=days && needDuba>0; d++) {
      if (getShift(n.id, d) !== 'บ') continue;
      if (d-1 >= 1 && includesAfternoon(getShift(n.id, d-1))) continue;
      if (d+1 <= days && includesNight(getShift(n.id, d+1))) continue;
      setShift(n.id, d, 'ดบ');
      needDuba--;
    }

    // --- Phase C: if still missing ชบ, try fallback upgrade บ → ชบ on a day where this nurse has no other ช ---
    // (Only when we can place ช-portion safely)
    if (needChba > 0) {
      for (let d=1; d<=days && needChba>0; d++) {
        if (getShift(n.id, d) !== 'บ') continue;
        // can't if prev includes afternoon (would break บ→ด upcoming check)
        if (d+1 <= days && includesNight(getShift(n.id, d+1))) continue;
        // We'd be converting an afternoon-only into morning+afternoon → check prev day doesn't include afternoon (no rule violation)
        if (d-1 >= 1 && includesAfternoon(getShift(n.id, d-1))) continue;
        setShift(n.id, d, 'ชบ');
        needChba--;
      }
    }
  });

  // ----- Step 3.7: Auto-fix afternoon→night -----
  autoFixAfternoonNight(nurses, days);

  // ----- Step 4: tryFillSpecialShifts -----
  // ----- Step 4.5: aggressiveFill 3 passes -----
  for (let pass=0; pass<3; pass++) {
    let added = 0;
    for (let d=1; d<=days; d++) {
      const we = isRestDay(state.year, state.month, d);
      const req = we ? state.requirements.weekend : state.requirements.weekday;
      // each shift type
      ['ช','บ','ด'].forEach(ts => {
        const r = ts==='ช'? req.ch : ts==='บ'? req.ba : req.du;
        const have = countShiftOnDay(d, ts);
        if (have >= r) return;
        let need = r - have;
        // candidates: free nurses
        let cands = nurses.filter(n => {
          if (getShift(n.id, d)) return false;
          if (getLeave(n.id, d)) return false;
          if (POSITIONS[n.position]?.weekdayMorningOnly) return false;
          return canPlace(n.id, d, ts);
        });
        if (ts === 'ด') cands = cands.filter(n => d-1<1 || !includesAfternoon(getShift(n.id, d-1)));
        if (ts === 'บ') cands = cands.filter(n => d+1>days || !includesNight(getShift(n.id, d+1)));
        cands.forEach(n => n._score = scoreNurse(n, d, ts));
        cands.sort((a,b) => a._score - b._score);
        for (let i=0; i<need && i<cands.length; i++) {
          setShift(cands[i].id, d, ts);
          added++;
        }
      });
    }
    if (added === 0) break;
  }

  // ----- Step 5: Fill remaining with O -----
  nurses.forEach(n => {
    for (let d=1; d<=days; d++) {
      if (getShift(n.id, d) || getLeave(n.id, d)) continue;
      setShift(n.id, d, 'O');
    }
  });

  // ----- Step 6: Validation -----
  nurses.forEach(n => {
    for (let d=1; d<=days; d++) {
      const cur = getShift(n.id, d);
      if (!includesAfternoon(cur)) continue;
      if (d+1 > days) continue;
      const nxt = getShift(n.id, d+1);
      if (includesNight(nxt)) {
        state.warnings.push(`⚠ ${n.name} : ${cur}(วันที่ ${d}) → ${nxt}(วันที่ ${d+1}) ผิดกฎ`);
      }
    }
  });

  // ----- Step 7: Coverage warnings -----
  for (let d=1; d<=days; d++) {
    const we = isRestDay(state.year, state.month, d);
    const req = we ? state.requirements.weekend : state.requirements.weekday;
    const ch = countShiftOnDay(d, 'ช');
    const ba = countShiftOnDay(d, 'บ');
    const du = countShiftOnDay(d, 'ด');
    if (ch < req.ch) state.warnings.push(`📅 วันที่ ${d}: เวรเช้าขาด ${req.ch - ch} คน`);
    if (ba < req.ba) state.warnings.push(`📅 วันที่ ${d}: เวรบ่ายขาด ${req.ba - ba} คน`);
    if (du < req.du) state.warnings.push(`📅 วันที่ ${d}: เวรดึกขาด ${req.du - du} คน`);
  }

  // ----- FINAL CLEANUP (defensive) -----
  // STEP 8: Re-enforce heads weekday-only rule (in case any earlier step violated it)
  enforceHeadsWeekdayOnly();
  // STEP 9: Backup fill — guarantee every cell has SOMETHING (catches edge cases like day 31)
  fillAllEmpty();
}

function countShiftOnDay(d, target) {
  let c = 0;
  state.nurses.filter(n=>n.active!==false).forEach(n => {
    const s = getShift(n.id, d);
    if (target === 'ช' && (s==='ช' || s==='ชบ' || s==='DN')) c++;
    else if (target === 'บ' && (s==='บ' || s==='ชบ' || s==='ดบ')) c++;
    else if (target === 'ด' && (s==='ด' || s==='ดบ' || s==='DN')) c++;
  });
  return c;
}

function canPlace(nid, d, s) {
  if (getShift(nid, d) || getLeave(nid, d)) return false;
  const nurse = state.nurses.find(n => n.id === nid);
  const p = POSITIONS[nurse?.position];
  if (p?.maxOne && isWorking(s)) {
    const others = state.nurses.filter(o => o.id !== nid && o.active!==false && POSITIONS[o.position]?.maxOne);
    for (const o of others) if (isWorking(getShift(o.id, d))) return false;
  }
  if (includesAfternoon(s)) {
    if (d+1 <= daysInMonth(state.year, state.month)) {
      const nxt = getShift(nid, d+1);
      if (includesNight(nxt)) return false;
    }
  }
  if (includesNight(s)) {
    if (d-1 >= 1) {
      const prev = getShift(nid, d-1);
      if (includesAfternoon(prev)) return false;
    }
  }
  return true;
}

function scoreNurse(n, d, targetShift) {
  const days = daysInMonth(state.year, state.month);
  let load = 0, sameCount = 0, penalty = 0;
  let consecutive = 0;
  for (let dd=1; dd<=days; dd++) {
    const s = getShift(n.id, dd);
    if (isWorking(s)) load++;
    if (s === targetShift) sameCount++;
  }
  // penalty: ด→ด pattern
  if (targetShift === 'ด') {
    if (d-1 >= 1 && getShift(n.id, d-1) === 'ด') penalty += 80;
    // 3xด in a row
    if (d-2 >= 1 && getShift(n.id, d-1)==='ด' && getShift(n.id, d-2)==='ด') penalty += 250;
  }
  // consecutive working
  let prevWork = 0;
  for (let dd=d-1; dd>=Math.max(1,d-6); dd--) {
    if (isWorking(getShift(n.id, dd))) prevWork++; else break;
  }
  if (prevWork >= 4) penalty += 300;
  else if (prevWork >= 2) penalty += 30;
  return load*50 + sameCount*20 + penalty + Math.random()*5;
}

function autoFixAfternoonNight(nurses, days) {
  let fixed = 0;
  for (const n of nurses) {
    for (let d=1; d<days; d++) {
      const cur = getShift(n.id, d);
      const nxt = getShift(n.id, d+1);
      if (!includesAfternoon(cur) || !includesNight(nxt)) continue;
      // try swap with another nurse on d+1
      let swapped = false;
      for (const m of nurses) {
        if (m.id === n.id) continue;
        if (POSITIONS[m.position]?.weekdayMorningOnly) continue;
        const ms = getShift(m.id, d+1);
        if (ms !== 'O') continue;
        if (getLeave(m.id, d+1)) continue;
        if (d >= 1 && includesAfternoon(getShift(m.id, d))) continue; // m can't take night
        // swap
        setShift(m.id, d+1, nxt);
        setShift(n.id, d+1, 'O');
        swapped = true; fixed++;
        break;
      }
      if (swapped) continue;
      // fallback: replace n's d+1 with O
      setShift(n.id, d+1, 'O');
      state.warnings.push(`🔄 ${n.name}: ${cur}(${d})→${nxt}(${d+1}) แก้เป็น O แทน`);
      fixed++;
    }
  }
}


// ========= DASHBOARD =========
function renderDashboard() {
  const days = daysInMonth(state.year, state.month);
  document.getElementById('dashDays').textContent = days;
  document.getElementById('dashMonthLabel').textContent = `${THAI_MONTHS[state.month-1]} ${state.year}`;
  document.getElementById('tabCountDays').textContent = days;

  const activeNurses = state.nurses.filter(n=>n.active!==false);
  document.getElementById('dashNurses').textContent = activeNurses.length;
  const heads = activeNurses.filter(n=>POSITIONS[n.position]?.weekdayMorningOnly).length;
  document.getElementById('dashNursesSub').textContent = `รวม ${heads} คนเป็นหัวหน้า`;
  document.getElementById('tabCountNurses').textContent = activeNurses.length;

  let totalShifts = 0, leaveCount = 0;
  const distribution = {'ช':0,'บ':0,'ด':0,'ชบ':0,'ดบ':0,'DN':0,'O':0,'V':0,'T':0};
  activeNurses.forEach(n => {
    for (let d=1; d<=days; d++) {
      const s = getShift(n.id, d);
      const l = getLeave(n.id, d);
      if (l) { distribution[l]++; leaveCount++; continue; }
      if (s) { distribution[s]++; if (isWorking(s)) totalShifts++; }
    }
  });
  document.getElementById('dashShifts').textContent = totalShifts;
  const totalNeeded = activeNurses.length * days;
  const pct = totalNeeded > 0 ? Math.round(((totalShifts + leaveCount + (distribution['O']||0)) / totalNeeded) * 100) : 0;
  document.getElementById('dashShiftPct').textContent = `${pct}% เติมแล้ว`;
  document.getElementById('dashLeaves').textContent = leaveCount;
  document.getElementById('tabCountLeaves').textContent = leaveCount;
  document.getElementById('dashWarnings').textContent = state.warnings.length;

  // Payroll
  const ot = computeOT();
  document.getElementById('dashOTAmount').textContent = ot.totalAmount.toLocaleString();
  document.getElementById('dashOTCount').textContent = `${ot.totalHours.toLocaleString()} ชม. · ${ot.nursesPaid} คน`;
  document.getElementById('tabCountOT').textContent = ot.nursesPaid;

  // Distribution bars
  const distEl = document.getElementById('shiftDistribution');
  const colors = { 'ช':'#fef9c3', 'บ':'#bae6fd', 'ด':'#a5f3fc', 'ชบ':'#fcd34d', 'ดบ':'#5eead4', 'DN':'#c4b5fd', 'O':'#e2e8f0', 'V':'#bbf7d0', 'T':'#fbcfe8' };
  const stroke = { 'ช':'#854d0e', 'บ':'#075985', 'ด':'#155e75', 'ชบ':'#78350f', 'ดบ':'#134e4a', 'DN':'#4c1d95', 'O':'#475569', 'V':'#14532d', 'T':'#831843' };
  const maxV = Math.max(1, ...Object.values(distribution));
  distEl.innerHTML = Object.entries(distribution).map(([s,c]) => {
    const pct = Math.round((c/maxV)*100);
    return `<div class="flex items-center gap-2">
      <span class="px-2 py-0.5 rounded font-bold text-xs w-10 text-center" style="background:${colors[s]};color:${stroke[s]}">${s}</span>
      <div class="flex-1 h-5 bg-slate-100 rounded overflow-hidden">
        <div class="h-full flex items-center justify-end pr-2 text-xs font-semibold" style="width:${pct}%;background:${colors[s]};color:${stroke[s]}">${c||''}</div>
      </div>
      <span class="text-xs text-slate-500 w-12 text-right">${c} เวร</span>
    </div>`;
  }).join('');

  // Workload top 10
  const wl = activeNurses.map(n => {
    const st = computeNurseStats(n.id);
    return { name: n.name, position: n.position, total: st.total };
  }).sort((a,b)=>b.total-a.total).slice(0,10);
  const maxW = Math.max(1, ...wl.map(w=>w.total));
  document.getElementById('workloadChart').innerHTML = wl.map((w,i) => {
    const pct = Math.round((w.total/maxW)*100);
    const cls = POSITIONS[w.position]?.cls || 'pos-rn';
    return `<div class="flex items-center gap-2 text-xs">
      <span class="w-5 text-slate-400 text-right">${i+1}.</span>
      <span class="flex-1 truncate text-slate-700">${w.name}</span>
      <div class="w-32 h-4 bg-slate-100 rounded overflow-hidden">
        <div class="h-full bg-gradient-to-r from-cyan-400 to-blue-500" style="width:${pct}%"></div>
      </div>
      <span class="w-10 text-right font-semibold text-cyan-700">${w.total}</span>
    </div>`;
  }).join('') || '<div class="text-sm text-slate-400 text-center py-6">ยังไม่มีข้อมูล</div>';

  // Warning panel
  const wp = document.getElementById('warningPanel');
  if (state.warnings.length > 0) {
    wp.classList.remove('hidden');
    document.getElementById('warningList').innerHTML = state.warnings.slice(0, 30).map(w => `<li>${w}</li>`).join('') + 
      (state.warnings.length > 30 ? `<li class="text-amber-600">... และอีก ${state.warnings.length-30} คำเตือน</li>` : '');
  } else {
    wp.classList.add('hidden');
  }
}

// ========= SUMMARY =========
function renderSummary() {
  const grid = document.getElementById('summaryGrid');
  const days = daysInMonth(state.year, state.month);
  const nurses = state.nurses.filter(n=>n.active!==false).sort((a,b)=>(a.order||999)-(b.order||999));
  grid.innerHTML = nurses.map(n => {
    const st = computeNurseStats(n.id);
    const cls = POSITIONS[n.position]?.cls || 'pos-rn';
    const pct = Math.min(100, Math.round((st.total / 22) * 100));
    return `<div class="summary-card">
      <div class="flex items-start justify-between mb-2">
        <div>
          <div class="font-semibold text-slate-800">${n.name}</div>
          <span class="pos-badge ${cls} mt-1 inline-block">${n.position}</span>
        </div>
        <div class="text-right">
          <div class="text-2xl font-bold text-cyan-700">${st.total}</div>
          <div class="text-xs text-slate-500">/${days} วัน</div>
        </div>
      </div>
      <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
      <div class="grid grid-cols-3 gap-1 text-xs mt-3">
        <div class="bg-yellow-50 p-1.5 rounded text-center" title="ช + ชบ + DN">
          <div class="font-bold text-yellow-800">${st.chTotal||0}</div>
          <div class="text-yellow-700">ช <span class="opacity-60">(รวม)</span></div>
        </div>
        <div class="bg-sky-50 p-1.5 rounded text-center" title="บ + ชบ + ดบ">
          <div class="font-bold text-sky-800">${st.baTotal||0}</div>
          <div class="text-sky-700">บ <span class="opacity-60">(รวม)</span></div>
        </div>
        <div class="bg-cyan-50 p-1.5 rounded text-center" title="ด + ดบ + DN">
          <div class="font-bold text-cyan-800">${st.duTotal||0}</div>
          <div class="text-cyan-700">ด <span class="opacity-60">(รวม)</span></div>
        </div>
        <div class="bg-amber-50 p-1.5 rounded text-center"><div class="font-bold text-amber-800">${st['ชบ']||0}</div><div class="text-amber-700">ชบ</div></div>
        <div class="bg-teal-50 p-1.5 rounded text-center"><div class="font-bold text-teal-800">${st['ดบ']||0}</div><div class="text-teal-700">ดบ</div></div>
        <div class="bg-purple-50 p-1.5 rounded text-center"><div class="font-bold text-purple-800">${st['DN']||0}</div><div class="text-purple-700">DN</div></div>
        <div class="bg-slate-50 p-1.5 rounded text-center"><div class="font-bold text-slate-700">${st['O']||0}</div><div class="text-slate-600">O</div></div>
        <div class="bg-emerald-50 p-1.5 rounded text-center"><div class="font-bold text-emerald-800">${st['V']||0}</div><div class="text-emerald-700">V</div></div>
        <div class="bg-pink-50 p-1.5 rounded text-center"><div class="font-bold text-pink-800">${st['T']||0}</div><div class="text-pink-700">T</div></div>
      </div>
    </div>`;
  }).join('');
}

// ========= PAYROLL (เงินเดือน/ค่าตอบแทน) =========
// คำนวณ: หาชั่วโมงปฏิบัติงานต่อเดือน (จาก shift × hours) × อัตรา/ชม. (รายคน หรือ default)
//        → ค่าตอบแทนรวม, ภาษี, คงเหลือ
function computeOT() {
  const days = daysInMonth(state.year, state.month);
  const nurses = state.nurses.filter(n=>n.active!==false);
  const defaultRate = state.timesheetSettings?.default_hourly_rate ?? 120;
  const taxPct      = state.timesheetSettings?.tax_rate ?? 3;
  const rows = [];
  let totalAmount = 0, totalHours = 0, nursesPaid = 0, totalTax = 0, totalNet = 0;
  nurses.forEach(n => {
    let hours = 0;
    for (let d=1; d<=days; d++) {
      if (getLeave(n.id, d)) continue;
      const s = getShift(n.id, d);
      if (!s) continue;
      const h = SHIFT_TYPES[s]?.hours;
      if (typeof h === 'number' && h > 0) hours += h;
    }
    const rate   = (typeof n.hourlyRate === 'number' && n.hourlyRate >= 0) ? n.hourlyRate : defaultRate;
    const gross  = Math.round(hours * rate * 100) / 100;
    const tax    = Math.round(gross * taxPct) / 100;
    const net    = Math.round((gross - tax) * 100) / 100;
    if (gross > 0) nursesPaid++;
    rows.push({
      id: n.id, name: n.name, position: n.position,
      staffId: n.staffId || null, orgMemberId: n.orgMemberId || null,
      hours, rate, gross, tax, net,
      hasCustomRate: typeof n.hourlyRate === 'number' && n.hourlyRate >= 0,
    });
    totalAmount += gross;
    totalHours  += hours;
    totalTax    += tax;
    totalNet    += net;
  });
  state.otReport = rows;
  // Backward compat fields ที่ dashboard/export ยังเรียกใช้
  return {
    rows,
    totalAmount: Math.round(totalAmount * 100) / 100,
    totalHours:  Math.round(totalHours  * 100) / 100,
    totalTax:    Math.round(totalTax    * 100) / 100,
    totalNet:    Math.round(totalNet    * 100) / 100,
    nursesPaid,
    // legacy aliases (เผื่อจุดอื่นยังอ้างถึง)
    totalUnits:  Math.round(totalHours * 100) / 100,
    nursesWithOT: nursesPaid,
  };
}

// ── ส่งเงินเดือนรวมของเดือนนี้เข้า Cash Book ──
async function sendPayrollToFinance() {
  const ot = computeOT();
  if (ot.totalAmount <= 0) {
    Swal.fire({ icon: 'info', title: 'ยังไม่มียอดเงินเดือน', text: 'เดือนนี้ยังไม่มีพยาบาลที่มีชั่วโมงปฏิบัติงาน — ไม่มียอดให้ส่ง' });
    return;
  }
  const yearBE = state.year, month = state.month;
  const monthName = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][month];
  const yearCE = yearBE - 543;
  const lastDay = new Date(yearCE, month, 0).getDate();
  const txnDate = `${yearCE}-${String(month).padStart(2,'0')}-${String(lastDay).padStart(2,'0')}`;
  const sourceId = `${yearBE}${String(month).padStart(2,'0')}`; // e.g., 256805
  const breakdownLines = ot.rows.filter(r => r.gross > 0)
    .map(r => `${r.name} (${r.position}): ${r.hours} ชม. × ${r.rate} = ${r.gross.toLocaleString()} ฿`).join('\n');

  const r = await Swal.fire({
    title: 'ส่งเงินเดือนเข้าระบบการเงิน',
    html: `<div class="text-left text-sm space-y-2">
      <div>เดือน: <b>${monthName} ${yearBE}</b></div>
      <div>จำนวนพยาบาลที่ได้ค่าตอบแทน: <b>${ot.nursesPaid} คน</b></div>
      <div>ชั่วโมงรวม: <b>${ot.totalHours.toLocaleString()} ชม.</b></div>
      <div>ค่าตอบแทนรวม (ก่อนหักภาษี): <b class="text-emerald-600">${ot.totalAmount.toLocaleString()} บาท</b></div>
      <hr class="my-2">
      <div class="text-xs text-slate-500">บันทึกเป็น "รายจ่าย" หมวด "เงินเดือน/ค่าจ้าง" วันที่ ${txnDate}<br>ถ้ามีรายการของเดือนนี้อยู่แล้วจะอัปเดต (ไม่สร้างซ้ำ)</div>
    </div>`,
    showCancelButton: true,
    confirmButtonText: 'ส่ง', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#059669',
  });
  if (!r.isConfirmed) return;

  const fd = new FormData();
  fd.append('csrf_token', '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES) ?>');
  fd.append('action', 'txn:upsert_from_source');
  fd.append('source_module', 'nurse_schedule');
  fd.append('source_id', sourceId);
  fd.append('kind', 'expense');
  fd.append('amount', String(ot.totalAmount));
  fd.append('txn_date', txnDate);
  fd.append('description', `เงินเดือนพยาบาลประจำเดือน ${monthName} ${yearBE} (${ot.nursesPaid} คน · ${ot.totalHours} ชม.)`);
  fd.append('category_name', 'เงินเดือน/ค่าจ้าง');
  fd.append('note', breakdownLines);
  try {
    const res = await fetch('ajax_finance.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await res.json();
    if (!j.ok) { Swal.fire({ icon: 'error', title: 'ส่งไม่สำเร็จ', text: j.message || '' }); return; }
    Swal.fire({ icon: 'success', title: j.mode === 'updated' ? 'อัปเดตในระบบการเงินแล้ว' : 'ส่งเข้าระบบการเงินแล้ว',
      html: `<div class="text-sm">บันทึก ${ot.totalAmount.toLocaleString()} บาท ใน Cash Book<br><a href="index.php?section=finance" class="text-emerald-600 underline">เปิดดู</a></div>`,
      confirmButtonColor: '#059669' });
  } catch (e) { Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(e) }); }
}
// alias เก่า — เผื่อมีจุดอื่นยังเรียกอยู่
const sendOtToFinance = sendPayrollToFinance;

function renderOT() {
  // refresh top-card text จาก timesheetSettings
  const ts = state.timesheetSettings || {};
  const defRate = Number(ts.default_hourly_rate ?? 120);
  const taxPct  = Number(ts.tax_rate ?? 3);
  const sn  = (ts.signer_name || '').trim();
  const st  = (ts.signer_title || '').trim();
  const sigEl = document.getElementById('payrollSigner');
  if (sigEl) sigEl.textContent = sn ? `${sn}${st ? ' · ' + st : ''}` : '— ยังไม่ตั้งค่า —';
  const drEl = document.getElementById('payrollDefaultRate'); if (drEl) drEl.textContent = defRate.toLocaleString();
  const trEl = document.getElementById('payrollTaxRate');     if (trEl) trEl.textContent = taxPct;

  const ot = computeOT();

  // summary cards
  document.getElementById('otSummaryCards').innerHTML = `
    <div class="stat-card card-cyan">
      <div class="flex items-start justify-between relative z-10"><div class="stat-icon-box"><i data-lucide="users"></i></div></div>
      <div class="relative z-10"><div class="stat-value">${ot.nursesPaid}</div><div class="stat-label">พยาบาลได้ค่าตอบแทน</div></div>
    </div>
    <div class="stat-card card-amber">
      <div class="flex items-start justify-between relative z-10"><div class="stat-icon-box"><i data-lucide="clock"></i></div></div>
      <div class="relative z-10"><div class="stat-value">${ot.totalHours.toLocaleString()}</div><div class="stat-label">ชั่วโมงรวม</div></div>
    </div>
    <div class="stat-card card-pink">
      <div class="flex items-start justify-between relative z-10"><div class="stat-icon-box"><i data-lucide="banknote"></i></div></div>
      <div class="relative z-10"><div class="stat-value">${ot.totalAmount.toLocaleString()}</div><div class="stat-label">บาท · ก่อนหักภาษี</div></div>
    </div>
    <div class="stat-card card-emerald">
      <div class="flex items-start justify-between relative z-10"><div class="stat-icon-box"><i data-lucide="hand-coins"></i></div></div>
      <div class="relative z-10"><div class="stat-value">${ot.totalNet.toLocaleString()}</div><div class="stat-label">บาท · คงเหลือ (หักภาษีแล้ว)</div></div>
    </div>`;

  // table
  const tbl = document.getElementById('otTable');
  let html = '<thead><tr>';
  ['ที่','ชื่อ-นามสกุล','ตำแหน่ง','ชั่วโมงรวม','อัตรา/ชม.','ค่าตอบแทน (บาท)','ภาษี','คงเหลือ','การกระทำ']
    .forEach(h => html += `<th>${h}</th>`);
  html += '</tr></thead><tbody>';
  ot.rows.forEach((r,i) => {
    const cls = POSITIONS[r.position]?.cls || 'pos-rn';
    const grossClr = r.gross>0 ? 'text-emerald-700 font-bold' : 'text-slate-300';
    const netClr   = r.net>0   ? 'text-emerald-800 font-bold' : 'text-slate-300';
    const rateTag  = r.hasCustomRate
      ? `<span class="text-[10px] text-cyan-700 bg-cyan-100 px-1 rounded ml-1">รายคน</span>`
      : `<span class="text-[10px] text-slate-500 bg-slate-100 px-1 rounded ml-1">default</span>`;
    const canTimesheet = r.staffId || r.orgMemberId;
    html += `<tr>
      <td>${i+1}</td>
      <td class="text-left">${r.name}</td>
      <td><span class="pos-badge ${cls}">${r.position}</span></td>
      <td class="font-semibold">${r.hours.toLocaleString()}</td>
      <td>${r.rate.toLocaleString()}${rateTag}</td>
      <td class="${grossClr}">${r.gross > 0 ? r.gross.toLocaleString() : '0'}</td>
      <td class="text-rose-700">${r.tax > 0 ? r.tax.toLocaleString() : '—'}</td>
      <td class="${netClr}">${r.net > 0 ? r.net.toLocaleString() : '0'}</td>
      <td>${canTimesheet
        ? `<button onclick="openTimesheet('${r.id}')" class="btn-solid btn-success text-xs" title="ดูใบลงเวลา"><i data-lucide=\"file-text\" class=\"w-3 h-3\"></i> ใบลงเวลา</button>`
        : `<span class="text-xs text-slate-400" title="ต้องผูกกับ Identity ก่อน">—</span>`}</td>
    </tr>`;
  });
  html += `</tbody><tfoot><tr>
    <td colspan="3" class="text-right">รวมทั้งหมด:</td>
    <td>${ot.totalHours.toLocaleString()}</td>
    <td></td>
    <td class="text-emerald-800">${ot.totalAmount.toLocaleString()}</td>
    <td class="text-rose-800">${ot.totalTax.toLocaleString()}</td>
    <td class="text-emerald-900">${ot.totalNet.toLocaleString()}</td>
    <td></td>
  </tr></tfoot>`;
  tbl.innerHTML = html;
  lucide.createIcons();
}

// legacy stubs — เผื่อ event handler หรือ caller อื่นยังเรียกอยู่
function saveOTSettings() { renderOT(); }
function liveRecalcOT()   { renderOT(); }

// ========= NURSES =========
function renderNursesList() {
  const list = document.getElementById('nursesList');
  const sorted = [...state.nurses].sort((a,b) => (a.order||999)-(b.order||999));
  const allPosNames = Object.keys(POSITIONS);
  list.innerHTML = sorted.map((n,i) => {
    const cls = POSITIONS[n.position]?.cls || 'pos-rn';
    const inactive = n.active === false;
    const posOpts = allPosNames.map(p => `<option value="${p}" ${p===n.position?'selected':''}>${POSITIONS[p].icon} ${p}</option>`).join('');
    return `<div class="flex items-center gap-3 p-3 rounded-xl border ${inactive?'bg-slate-50 opacity-60':'bg-white'} hover:border-cyan-400 transition">
      <div class="w-8 h-8 rounded-full bg-cyan-100 flex items-center justify-center text-cyan-700 font-semibold text-sm">${i+1}</div>
      <div class="flex-1">
        <div class="font-medium text-slate-800">${n.name} ${inactive?'<span class="text-xs text-slate-400">(ปิดใช้งาน)</span>':''}</div>
        <div class="flex items-center gap-2 mt-1">
          <span class="pos-badge ${cls}">${n.position}</span>
          <select onchange="changeNursePosition('${n.id}', this.value, this)"
                  class="text-xs px-2 py-1 rounded border border-slate-200 bg-white text-slate-600 hover:border-cyan-400 focus:border-cyan-500 focus:outline-none cursor-pointer"
                  title="เปลี่ยนตำแหน่ง">${posOpts}</select>
          <span class="text-xs text-slate-400">ID: ${n.id}</span>
        </div>
      </div>
      <button onclick="openTimesheet('${n.id}')" class="btn-solid btn-success text-xs" title="พิมพ์ใบลงเวลาประจำเดือน">
        <i data-lucide="file-text" class="w-3.5 h-3.5"></i> ใบลงเวลา
      </button>
      <button onclick="editTimesheetInfo('${n.id}')" class="btn-solid btn-info text-xs" title="แก้ไขเลขบัตรประชาชน/ตำแหน่งทางการ/อัตราต่อชั่วโมง">
        <i data-lucide="id-card" class="w-3.5 h-3.5"></i> ข้อมูลใบลงเวลา
      </button>
      <button onclick="toggleNurseActive('${n.id}')" class="btn-solid ${inactive?'btn-success':'btn-warning'} text-xs">
        <i data-lucide="${inactive?'check':'eye-off'}" class="w-3.5 h-3.5"></i> ${inactive?'เปิดใช้':'ปิดใช้'}
      </button>
      <button onclick="editNurse('${n.id}')" class="btn-solid btn-primary text-xs">
        <i data-lucide="edit-2" class="w-3.5 h-3.5"></i> แก้ไข
      </button>
      <button onclick="deleteNurse('${n.id}')" class="btn-solid btn-danger text-xs">
        <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> ลบ
      </button>
    </div>`;
  }).join('');
  lucide.createIcons();
}

// เปลี่ยนตำแหน่งจาก dropdown inline
function changeNursePosition(id, newPos, selectEl) {
  const n = state.nurses.find(x => x.id === id);
  if (!n || n.position === newPos) return;
  if (POSITIONS[newPos]?.maxOne) {
    const exists = state.nurses.find(o => o.id !== id && o.position === newPos && o.active !== false);
    if (exists) {
      Swal.fire({ icon: 'warning', title: `${newPos} มีอยู่แล้ว`, text: `${exists.name} เป็น ${newPos} อยู่แล้ว — ตำแหน่งนี้มีได้คนเดียวเท่านั้น` });
      if (selectEl) selectEl.value = n.position;
      return;
    }
  }
  n.position = newPos;
  state.dirty = true;
  persistAll();
  renderNursesList();
  renderDashboard?.();
  showSuccess(`เปลี่ยนตำแหน่ง ${n.name} → ${newPos}`);
}

// ── จัดการตำแหน่ง (เพิ่ม/ลบตำแหน่งที่ผู้ใช้สร้าง) ──
function openManagePositions() {
  const rows = Object.entries(POSITIONS).map(([name, def]) => {
    const usedBy = state.nurses.filter(n => n.position === name).length;
    const isSystem = !!def.system;
    const delBtn = isSystem
      ? `<span class="text-xs text-slate-400">ตำแหน่งระบบ</span>`
      : `<button onclick="deleteCustomPosition('${name.replace(/'/g, "\\'")}')" class="text-xs text-rose-600 hover:text-rose-700 font-semibold">ลบ</button>`;
    return `<tr class="border-b border-slate-100">
      <td class="py-2 px-2"><span class="pos-badge ${def.cls}">${def.icon} ${name}</span></td>
      <td class="py-2 px-2 text-xs text-slate-500">${def.maxOne ? 'มีคนเดียว' : '-'} ${def.weekdayMorningOnly ? '· จันทร์-ศุกร์ เช้าเท่านั้น' : ''}</td>
      <td class="py-2 px-2 text-xs text-slate-500 text-center">${usedBy}</td>
      <td class="py-2 px-2 text-right">${delBtn}</td>
    </tr>`;
  }).join('');

  Swal.fire({
    title: 'จัดการตำแหน่ง',
    width: 720,
    html: `
      <div class="text-left space-y-4">
        <div class="overflow-x-auto border border-slate-200 rounded-lg">
          <table class="w-full text-sm">
            <thead class="bg-slate-50">
              <tr>
                <th class="py-2 px-2 text-left font-semibold text-slate-600">ตำแหน่ง</th>
                <th class="py-2 px-2 text-left font-semibold text-slate-600">เงื่อนไข</th>
                <th class="py-2 px-2 text-center font-semibold text-slate-600">ใช้อยู่</th>
                <th class="py-2 px-2 text-right font-semibold text-slate-600">จัดการ</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        <div class="border-t pt-3">
          <div class="text-sm font-semibold text-slate-700 mb-2">เพิ่มตำแหน่งใหม่</div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="text-xs text-slate-500">ชื่อตำแหน่ง</label>
              <input id="npName" class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg" placeholder="เช่น พยาบาลพิเศษ">
            </div>
            <div>
              <label class="text-xs text-slate-500">ไอคอน (Emoji)</label>
              <input id="npIcon" class="w-full text-sm px-3 py-2 border border-slate-200 rounded-lg" placeholder="🎯" maxlength="2" value="🏷️">
            </div>
            <div>
              <label class="text-xs text-slate-500">สี Badge</label>
              <input id="npColor" type="color" value="#0891b2" class="w-full h-9 border border-slate-200 rounded-lg cursor-pointer">
            </div>
            <div class="flex flex-col gap-1 pt-4">
              <label class="text-xs flex items-center gap-2"><input id="npMaxOne" type="checkbox"> มีได้คนเดียว (เช่น หัวหน้า)</label>
              <label class="text-xs flex items-center gap-2"><input id="npWeekday" type="checkbox"> จันทร์-ศุกร์ เช้าเท่านั้น</label>
            </div>
          </div>
          <button onclick="addCustomPosition()" class="mt-3 btn-solid btn-success text-sm">
            <i data-lucide="plus" class="w-3.5 h-3.5"></i> เพิ่มตำแหน่ง
          </button>
        </div>
      </div>`,
    showConfirmButton: false,
    showCloseButton: true,
    didOpen: () => lucide.createIcons(),
  });
}

function addCustomPosition() {
  const name = document.getElementById('npName').value.trim();
  const icon = document.getElementById('npIcon').value.trim() || '🏷️';
  const color = document.getElementById('npColor').value || '#0891b2';
  const maxOne = document.getElementById('npMaxOne').checked;
  const weekdayMorningOnly = document.getElementById('npWeekday').checked;
  if (!name) { Swal.showValidationMessage?.('กรุณากรอกชื่อตำแหน่ง'); return; }
  if (POSITIONS[name]) { Swal.fire({ icon: 'warning', title: 'มีตำแหน่งนี้อยู่แล้ว' }); return; }
  const cls = makeCustomPosCls(name);
  const def = { icon, cls, color, weekdayMorningOnly, maxOne, system: false };
  POSITIONS[name] = def;
  state.customPositions = state.customPositions || {};
  state.customPositions[name] = def;
  state.dirty = true;
  ensureCustomPosStyles();
  persistAll();
  renderNursesList();
  Swal.close();
  showSuccess(`เพิ่มตำแหน่ง "${name}" แล้ว`);
}

function deleteCustomPosition(name) {
  if (POSITIONS[name]?.system) { Swal.fire({ icon: 'error', title: 'ลบตำแหน่งระบบไม่ได้' }); return; }
  const used = state.nurses.filter(n => n.position === name);
  if (used.length > 0) {
    Swal.fire({ icon: 'warning', title: 'มีพยาบาลใช้ตำแหน่งนี้อยู่', html: `${used.length} คน — ย้ายไปตำแหน่งอื่นก่อนแล้วค่อยลบ:<br><br>${used.map(u => u.name).join(', ')}` });
    return;
  }
  confirmAct(`ลบตำแหน่ง "${name}"?`, 'ลบแล้วเรียกคืนไม่ได้').then(r => {
    if (!r.isConfirmed) return;
    delete POSITIONS[name];
    delete state.customPositions[name];
    state.dirty = true;
    ensureCustomPosStyles();
    persistAll();
    renderNursesList();
    Swal.close();
    showSuccess(`ลบตำแหน่ง "${name}" แล้ว`);
  });
}

// แปลง job_title หรือ org_position_title → POSITIONS key
function mapJobTitleToPosition(jt, orgTitle) {
  const t = ((jt || '') + ' ' + (orgTitle || '')).trim();
  if (!t) return 'เจ้าหน้าที่';
  if (/หัวหน้าหอ/i.test(t)) return 'หัวหน้าหอผู้ป่วย';
  if (/รองหัวหน้า/i.test(t)) return 'รองหัวหน้าหอผู้ป่วย';
  if (/หัวหน้าเวร/i.test(t)) return 'พยาบาลหัวหน้าเวร';
  if (/ผู้ช่วยพยาบาล/i.test(t)) return 'ผู้ช่วยพยาบาล';
  if (/พยาบาลเทคนิค|เทคนิค/i.test(t)) return 'พยาบาลเทคนิค';
  if (/พยาบาลวิชาชีพ|วิชาชีพ|พยาบาล/i.test(t)) return 'พยาบาลวิชาชีพ';
  return 'เจ้าหน้าที่';
}

async function openImportNurses() {
  showLoading('กำลังโหลดทะเบียน…');
  let staff = [];
  try {
    const r = await fetch('ajax_nurse_register.php?action=list_staff_nurses', { credentials: 'same-origin' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'ผิดพลาด');
    staff = j.staff || [];
  } catch (e) {
    Swal.close();
    Swal.fire({ icon: 'error', title: 'โหลดไม่สำเร็จ', text: e.message });
    return;
  }
  Swal.close();

  if (!staff.length) {
    Swal.fire({
      icon: 'info', title: 'ไม่พบรายชื่อบุคลากร',
      html: 'ไม่พบในทั้ง 2 แหล่ง:<br>• <b>Identity</b> staff ที่อยู่ในผังองค์กร หรือมี Job Title "พยาบาล"<br>• <b>ผังองค์กร</b> สมาชิกที่ยังไม่ได้ผูกกับ Identity<br><br>เปิดหน้า "ข้อมูลคลินิก" → "ผังองค์กร" เพื่อเพิ่มสมาชิกก่อน',
    });
    return;
  }

  // เช็คว่าใครถูก import แล้ว (match by staffId หรือ orgMemberId)
  const importedStaffIds = new Set(state.nurses.filter(n => n.staffId).map(n => Number(n.staffId)));
  const importedOrgIds = new Set(state.nurses.filter(n => n.orgMemberId).map(n => Number(n.orgMemberId)));
  const importedNames = new Set(state.nurses.map(n => n.name)); // กันชื่อซ้ำ

  const rows = staff.map((s, idx) => {
    const isOrg = s.source === 'org';
    const sid = Number(s.staff_id || 0);
    const orgId = Number(s.org_member_id || 0);
    const already = (sid > 0 && importedStaffIds.has(sid))
                 || (orgId > 0 && importedOrgIds.has(orgId))
                 || importedNames.has(s.full_name);
    const title = (s.job_title || s.org_position_title || '').trim();
    const pos = mapJobTitleToPosition(s.job_title, s.org_position_title);
    const badge = isOrg
      ? '<span class="text-[9px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded ml-1">ผังองค์กร</span>'
      : '<span class="text-[9px] font-bold text-sky-700 bg-sky-100 px-1.5 py-0.5 rounded ml-1">Identity</span>';
    return `<label class="flex items-center gap-3 p-2 rounded-lg ${already ? 'bg-emerald-50' : 'bg-slate-50'} hover:bg-emerald-50 cursor-pointer mb-1">
      <input type="checkbox" class="imp-nurse-cb" data-idx="${idx}" data-source="${s.source || 'staff'}" data-staff-id="${sid}" data-org-id="${orgId}" data-name="${s.full_name.replace(/"/g, '&quot;')}" data-pos="${pos}" ${already ? 'checked disabled' : ''}>
      <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm text-slate-800">${s.full_name}${badge}${already ? '<span class=\"text-[10px] text-emerald-700 font-bold ml-1\">✓ นำเข้าแล้ว</span>' : ''}</div>
        <div class="text-xs text-slate-500">${title || '—'} <span class="opacity-60">→ ${pos}</span></div>
      </div>
    </label>`;
  }).join('');

  // count by source สำหรับแสดงสรุป
  const countStaff = staff.filter(s => s.source !== 'org').length;
  const countOrg = staff.filter(s => s.source === 'org').length;

  Swal.fire({
    title: 'นำเข้ารายชื่อบุคลากร',
    html: `<p class="text-xs text-slate-500 text-left mb-2">
        ดึงจาก 2 แหล่งรวมกัน: <b class="text-sky-700">Identity (${countStaff})</b> + <b class="text-amber-700">ผังองค์กร (${countOrg})</b><br>
        ระบบจะแมพ "ตำแหน่งในเวร" ให้อัตโนมัติจาก Job Title/Org Title (ผู้ที่ไม่ใช่พยาบาลจะถูกตั้งเป็น "เจ้าหน้าที่")
      </p>
      <div class="text-left max-h-[400px] overflow-y-auto pr-1">${rows}</div>`,
    showCancelButton: true,
    confirmButtonText: 'นำเข้า',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#2e9e63',
    width: 620,
    preConfirm: () => {
      const checks = Array.from(document.querySelectorAll('.imp-nurse-cb:checked:not(:disabled)'));
      if (!checks.length) {
        Swal.showValidationMessage('เลือกอย่างน้อย 1 คน');
        return false;
      }
      return checks.map(cb => ({
        source: cb.dataset.source,
        staffId: Number(cb.dataset.staffId) || null,
        orgMemberId: Number(cb.dataset.orgId) || null,
        name: cb.dataset.name,
        position: cb.dataset.pos,
      }));
    }
  }).then(async r => {
    if (!r.isConfirmed) return;
    let added = 0;
    r.value.forEach(item => {
      // ตรวจ maxOne — ข้ามถ้าตำแหน่งนั้นเต็มอยู่แล้ว
      let pos = item.position;
      if (POSITIONS[pos]?.maxOne) {
        const exists = state.nurses.find(n => n.position === pos && n.active !== false);
        if (exists) pos = 'พยาบาลวิชาชีพ'; // fallback
      }
      const newId = 'N' + String(Date.now() + added).slice(-6);
      const newNurse = {
        id: newId, name: item.name, position: pos,
        order: state.nurses.length + 1, active: true,
      };
      if (item.staffId) newNurse.staffId = item.staffId;
      if (item.orgMemberId) newNurse.orgMemberId = item.orgMemberId;
      state.nurses.push(newNurse);
      added++;
    });
    state.dirty = true;
    persistAll();
    renderNursesList();
    renderSchedule();
    // sync hourly_rate รายคนจาก server เพื่อให้ payroll ใช้งานได้ทันที
    await refreshNurseRates(false);
    Swal.fire({ icon: 'success', title: `นำเข้า ${added} คนแล้ว`, timer: 1500, showConfirmButton: false });
  });
}

function openAddNurse() {
  const posOptions = Object.keys(POSITIONS).map(p => `<option value="${p}">${POSITIONS[p].icon} ${p}</option>`).join('');
  Swal.fire({
    title: 'เพิ่มพยาบาลใหม่',
    html: `<div class="text-left space-y-3">
      <div><label class="text-sm font-medium">ชื่อ-นามสกุล</label>
        <input id="nName" class="swal2-input" placeholder="เช่น นางสาวสมหญิง พยาบาลดี" style="margin:4px 0"></div>
      <div><label class="text-sm font-medium">ตำแหน่ง</label>
        <select id="nPos" class="swal2-select" style="margin:4px 0">${posOptions}</select></div>
    </div>`,
    showCancelButton: true,
    confirmButtonText: 'เพิ่ม',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#2e9e63',
    preConfirm: () => {
      const name = document.getElementById('nName').value.trim();
      const pos = document.getElementById('nPos').value;
      if (!name) { Swal.showValidationMessage('กรุณากรอกชื่อ'); return false; }
      // maxOne check
      if (POSITIONS[pos]?.maxOne) {
        const exists = state.nurses.find(n => n.position === pos && n.active !== false);
        if (exists) { Swal.showValidationMessage(`${pos} มีอยู่แล้ว (${exists.name})`); return false; }
      }
      return { name, position: pos };
    }
  }).then(r => {
    if (!r.isConfirmed) return;
    const newId = 'N' + String(Date.now()).slice(-6);
    state.nurses.push({ id: newId, name: r.value.name, position: r.value.position, order: state.nurses.length+1, active: true });
    state.dirty = true;
    persistAll();
    renderNursesList();
    renderDashboard();
    showSuccess('เพิ่มพยาบาลแล้ว');
  });
}

function editNurse(id) {
  const n = state.nurses.find(x => x.id === id);
  if (!n) return;
  const posOptions = Object.keys(POSITIONS).map(p => `<option value="${p}" ${p===n.position?'selected':''}>${POSITIONS[p].icon} ${p}</option>`).join('');
  Swal.fire({
    title: 'แก้ไขพยาบาล',
    html: `<div class="text-left space-y-3">
      <div><label class="text-sm font-medium">ชื่อ-นามสกุล</label>
        <input id="eName" class="swal2-input" value="${n.name}" style="margin:4px 0"></div>
      <div><label class="text-sm font-medium">ตำแหน่ง</label>
        <select id="ePos" class="swal2-select" style="margin:4px 0">${posOptions}</select></div>
      <div><label class="text-sm font-medium">ลำดับ</label>
        <input id="eOrder" type="number" class="swal2-input" value="${n.order||1}" style="margin:4px 0"></div>
    </div>`,
    showCancelButton: true,
    confirmButtonText: 'บันทึก',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#2e9e63',
    preConfirm: () => {
      const name = document.getElementById('eName').value.trim();
      const pos = document.getElementById('ePos').value;
      const order = +document.getElementById('eOrder').value || 1;
      if (!name) { Swal.showValidationMessage('กรุณากรอกชื่อ'); return false; }
      if (pos !== n.position && POSITIONS[pos]?.maxOne) {
        const exists = state.nurses.find(o => o.id !== n.id && o.position === pos && o.active !== false);
        if (exists) { Swal.showValidationMessage(`${pos} มีอยู่แล้ว (${exists.name})`); return false; }
      }
      return { name, position: pos, order };
    }
  }).then(r => {
    if (!r.isConfirmed) return;
    Object.assign(n, r.value);
    state.dirty = true;
    persistAll();
    renderNursesList();
    showSuccess('แก้ไขแล้ว');
  });
}

function deleteNurse(id) {
  const n = state.nurses.find(x => x.id === id);
  if (!n) return;
  confirmAct(`ลบ ${n.name}?`, 'จะลบรายชื่อและตารางเวรของพยาบาลคนนี้').then(r => {
    if (!r.isConfirmed) return;
    state.nurses = state.nurses.filter(x => x.id !== id);
    // also delete schedule and leaves
    for (const k of Object.keys(state.schedule)) if (k.startsWith(id+'-')) delete state.schedule[k];
    for (const k of Object.keys(state.leaves)) if (k.startsWith(id+'-')) delete state.leaves[k];
    state.dirty = true;
    persistAll();
    renderNursesList();
    renderDashboard();
    showSuccess('ลบแล้ว');
  });
}

function toggleNurseActive(id) {
  const n = state.nurses.find(x => x.id === id);
  if (!n) return;
  n.active = n.active === false;
  state.dirty = true;
  persistAll();
  renderNursesList();
  renderDashboard();
}

// ========= TIMESHEET (ใบลงเวลาปฏิบัติงาน) =========

// เปิดหน้าพิมพ์ใบลงเวลาประจำเดือนของพยาบาลคนนี้
function openTimesheet(nurseId) {
  const n = state.nurses.find(x => x.id === nurseId);
  if (!n) { Swal.fire({ icon: 'error', title: 'ไม่พบพยาบาล' }); return; }
  if (!n.staffId && !n.orgMemberId) {
    Swal.fire({
      icon: 'warning',
      title: 'ต้องเชื่อมกับ Identity / ผังองค์กรก่อน',
      html: 'พยาบาลคนนี้ถูกเพิ่มแบบ manual (ไม่ได้นำเข้าจาก Identity)<br>' +
            '→ เพิ่มผ่าน "นำเข้ารายชื่อ" หรือกรอกข้อมูลในผังองค์กรก่อน เพื่อให้สามารถบันทึก<br>เลขบัตรประชาชน/ตำแหน่งทางการ/อัตราค่าจ้างได้'
    });
    return;
  }
  const params = new URLSearchParams({
    year: String(state.year), month: String(state.month),
  });
  if (n.staffId)      params.set('staff_id', String(n.staffId));
  if (n.orgMemberId)  params.set('org_member_id', String(n.orgMemberId));
  window.open('nurse_timesheet.php?' + params.toString(), '_blank');
}

// เปิด modal แก้ไขข้อมูลใบลงเวลาของพยาบาลคนนี้ (national_id, official_title, hourly_rate)
async function editTimesheetInfo(nurseId) {
  const n = state.nurses.find(x => x.id === nurseId);
  if (!n) { Swal.fire({ icon: 'error', title: 'ไม่พบพยาบาล' }); return; }
  if (!n.staffId && !n.orgMemberId) {
    Swal.fire({
      icon: 'warning',
      title: 'ต้องเชื่อมกับ Identity / ผังองค์กรก่อน',
      text: 'พยาบาลที่เพิ่มแบบ manual ไม่มีระเบียนที่จะเก็บข้อมูลใบลงเวลา — โปรดนำเข้าจาก Identity ก่อน'
    });
    return;
  }
  // โหลดข้อมูลปัจจุบัน
  showLoading('กำลังโหลด…');
  let info = {};
  try {
    const q = new URLSearchParams();
    if (n.staffId)     q.set('staff_id', String(n.staffId));
    if (n.orgMemberId) q.set('org_member_id', String(n.orgMemberId));
    const r = await fetch('ajax_nurse_register.php?action=get_nurse_info&' + q.toString(), { credentials: 'same-origin' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'โหลดไม่สำเร็จ');
    info = j.info || {};
  } catch (e) {
    Swal.close(); Swal.fire({ icon: 'error', title: 'โหลดไม่สำเร็จ', text: e.message }); return;
  }
  Swal.close();

  const titleSuggest = info.official_title || info.org_position_title || info.job_title || '';
  const r = await Swal.fire({
    title: `ข้อมูลใบลงเวลา: ${n.name}`,
    html: `<div class="text-left space-y-3" style="font-family:inherit">
      <div>
        <label class="text-sm font-medium text-slate-700">เลขบัตรประชาชน (13 หลัก)</label>
        <input id="tsNid" class="swal2-input" inputmode="numeric" maxlength="13" placeholder="1103702803723"
               value="${(info.national_id || '').replace(/"/g,'&quot;')}" style="margin:4px 0;width:100%">
      </div>
      <div>
        <label class="text-sm font-medium text-slate-700">ตำแหน่งทางการ (แสดงในใบลงเวลา)</label>
        <input id="tsTitle" class="swal2-input" placeholder="เช่น พยาบาลวิชาชีพ ประจำการ"
               value="${titleSuggest.replace(/"/g,'&quot;')}" style="margin:4px 0;width:100%">
        <div class="text-xs text-slate-400 mt-0.5">เว้นว่างได้ — จะใช้ Job Title / Org Title แทน</div>
      </div>
      <div>
        <label class="text-sm font-medium text-slate-700">อัตราค่าตอบแทน (บาท/ชั่วโมง)</label>
        <input id="tsRate" type="number" min="0" step="1" class="swal2-input" placeholder="120"
               value="${info.hourly_rate ?? ''}" style="margin:4px 0;width:100%">
        <div class="text-xs text-slate-400 mt-0.5">เว้นว่างได้ — จะใช้ค่า default จากตั้งค่าใบลงเวลา</div>
      </div>
    </div>`,
    showCancelButton: true,
    confirmButtonText: 'บันทึก',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#0ea5e9',
    width: 520,
    preConfirm: () => {
      const nid   = document.getElementById('tsNid').value.trim();
      const title = document.getElementById('tsTitle').value.trim();
      const rate  = document.getElementById('tsRate').value.trim();
      if (nid && !/^\d{13}$/.test(nid)) { Swal.showValidationMessage('เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก'); return false; }
      return { nid, title, rate };
    }
  });
  if (!r.isConfirmed) return;

  const fd = new FormData();
  fd.append('csrf_token', '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES) ?>');
  fd.append('action', 'save_nurse_info');
  if (n.staffId)     fd.append('staff_id', String(n.staffId));
  if (n.orgMemberId) fd.append('org_member_id', String(n.orgMemberId));
  fd.append('national_id', r.value.nid);
  fd.append('official_title', r.value.title);
  fd.append('hourly_rate', r.value.rate);

  try {
    const res = await fetch('ajax_nurse_register.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await res.json();
    if (!j.ok) { Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: j.error || '' }); return; }
    // อัปเดต hourlyRate local เพื่อให้ payroll tab สะท้อนทันที
    const rateNum = r.value.rate === '' ? null : Number(r.value.rate);
    n.hourlyRate = (rateNum === null || isNaN(rateNum)) ? null : rateNum;
    if (typeof renderOT === 'function')        { try { renderOT(); } catch (e) {} }
    if (typeof renderDashboard === 'function') { try { renderDashboard(); } catch (e) {} }
    Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false });
  } catch (e) {
    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(e) });
  }
}

// ตั้งค่าใบลงเวลา (ชื่อคลินิก/ผู้ลงนาม/อัตราภาษี/อัตราต่อชั่วโมงเริ่มต้น)
async function openTimesheetSettings() {
  showLoading('กำลังโหลด…');
  let s = {};
  try {
    const r = await fetch('ajax_nurse_register.php?action=get_timesheet_settings', { credentials: 'same-origin' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'โหลดไม่สำเร็จ');
    s = j.settings || {};
  } catch (e) {
    Swal.close(); Swal.fire({ icon: 'error', title: 'โหลดไม่สำเร็จ', text: e.message }); return;
  }
  Swal.close();

  const r = await Swal.fire({
    title: 'ตั้งค่าใบลงเวลาปฏิบัติงาน',
    html: `<div class="text-left space-y-3">
      <div>
        <label class="text-sm font-medium text-slate-700">ชื่อคลินิก / หน่วยงาน</label>
        <input id="tsClinic" class="swal2-input" value="${(s.clinic_name || 'คลินิกเวชกรรม มหาวิทยาลัยรังสิต').replace(/"/g,'&quot;')}" style="margin:4px 0;width:100%">
      </div>
      <div>
        <label class="text-sm font-medium text-slate-700">ผู้ลงนาม (ชื่อ-นามสกุล)</label>
        <input id="tsSignerN" class="swal2-input" placeholder="เช่น รศ.ดร.มนพร ชาติชำนิ" value="${(s.signer_name || '').replace(/"/g,'&quot;')}" style="margin:4px 0;width:100%">
      </div>
      <div>
        <label class="text-sm font-medium text-slate-700">ตำแหน่งผู้ลงนาม</label>
        <input id="tsSignerT" class="swal2-input" placeholder="เช่น ผู้อำนวยการสำนักงานสวัสดิการสุขภาพ" value="${(s.signer_title || '').replace(/"/g,'&quot;')}" style="margin:4px 0;width:100%">
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="text-sm font-medium text-slate-700">อัตราภาษี ณ ที่จ่าย (%)</label>
          <input id="tsTax" type="number" min="0" max="30" step="0.01" class="swal2-input" value="${s.tax_rate ?? 3}" style="margin:4px 0;width:100%">
        </div>
        <div>
          <label class="text-sm font-medium text-slate-700">อัตราต่อชั่วโมง (บาท)</label>
          <input id="tsDefRate" type="number" min="0" step="1" class="swal2-input" value="${s.default_hourly_rate ?? 120}" style="margin:4px 0;width:100%">
        </div>
      </div>
      <div class="text-xs text-slate-400">อัตราต่อชั่วโมง = ค่า default ที่ใช้กรณีพยาบาลไม่ได้ตั้งค่าเฉพาะของตน</div>
    </div>`,
    showCancelButton: true,
    confirmButtonText: 'บันทึก',
    cancelButtonText: 'ยกเลิก',
    confirmButtonColor: '#0ea5e9',
    width: 600,
    preConfirm: () => {
      return {
        clinic: document.getElementById('tsClinic').value.trim(),
        signerN: document.getElementById('tsSignerN').value.trim(),
        signerT: document.getElementById('tsSignerT').value.trim(),
        tax: document.getElementById('tsTax').value,
        defRate: document.getElementById('tsDefRate').value,
      };
    }
  });
  if (!r.isConfirmed) return;

  const fd = new FormData();
  fd.append('csrf_token', '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES) ?>');
  fd.append('action', 'save_timesheet_settings');
  fd.append('clinic_name', r.value.clinic);
  fd.append('signer_name', r.value.signerN);
  fd.append('signer_title', r.value.signerT);
  fd.append('tax_rate', r.value.tax);
  fd.append('default_hourly_rate', r.value.defRate);

  try {
    const res = await fetch('ajax_nurse_register.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await res.json();
    if (!j.ok) { Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: j.error || '' }); return; }
    // อัปเดต state.timesheetSettings ทันที
    state.timesheetSettings = {
      clinic_name:         r.value.clinic,
      signer_name:         r.value.signerN,
      signer_title:        r.value.signerT,
      tax_rate:            Number(r.value.tax),
      default_hourly_rate: Number(r.value.defRate),
    };
    if (typeof renderOT === 'function')        { try { renderOT(); } catch (e) {} }
    if (typeof renderDashboard === 'function') { try { renderDashboard(); } catch (e) {} }
    Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false });
  } catch (e) {
    Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(e) });
  }
}

// ========= HOLIDAY MANAGER =========
function updateHolidayBadge() {
  const list = getMonthHolidays(state.year, state.month).filter(h => !h.isWeekend);
  const el = document.getElementById('hdrHolidayCount');
  if (el) el.textContent = list.length;
}

function openHolidayManager() {
  const days = daysInMonth(state.year, state.month);
  const mName = THAI_MONTHS[state.month-1];

  // Build list of all days in month with holiday status
  const items = [];
  for (let d=1; d<=days; d++) {
    const name = isHoliday(state.year, state.month, d);
    const we = isWeekend(state.year, state.month, d);
    const dl = dayLabel(state.year, state.month, d);
    if (name || we) items.push({ day: d, name: name || '', isWeekend: we, dayLabel: dl });
  }

  const rows = items.map(it => {
    const customKey = `${state.year}-${state.month}-${it.day}`;
    const isCustom = !!state.customHolidays[customKey];
    const isRemoved = !!state.removedHolidays[customKey];
    let nameDisplay = it.name || (it.isWeekend ? '<span class="text-pink-600">วันเสาร์-อาทิตย์</span>' : '');
    if (isRemoved) nameDisplay = '<s class="text-slate-400">(ถูกลบ)</s>';
    const tag = isCustom ? '<span class="text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">custom</span>' :
                it.isWeekend && !it.name ? '<span class="text-xs bg-pink-100 text-pink-700 px-1.5 py-0.5 rounded">เสาร์-อาทิตย์</span>' :
                '<span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">วันหยุดราชการ</span>';
    const removeBtn = (it.name && !it.isWeekend) ?
      `<button onclick="toggleRemoveHoliday(${it.day})" class="text-xs ${isRemoved?'text-emerald-600':'text-red-600'} hover:underline">${isRemoved?'นำกลับมา':'ลบ'}</button>` : '';
    return `<tr class="border-b">
      <td class="py-1.5 px-2 text-center text-slate-600">${it.day}</td>
      <td class="py-1.5 px-2 text-center text-slate-500">${it.dayLabel}</td>
      <td class="py-1.5 px-2 text-left">${nameDisplay}</td>
      <td class="py-1.5 px-2 text-center">${tag}</td>
      <td class="py-1.5 px-2 text-center">${removeBtn}</td>
    </tr>`;
  }).join('');

  Swal.fire({
    title: `จัดการวันหยุด — ${mName} ${state.year}`,
    width: 720,
    html: `
      <div class="text-left">
        <div class="bg-blue-50 border-l-4 border-blue-400 p-3 mb-3 rounded-r text-sm text-blue-800">
          <i data-lucide="info" class="w-4 h-4 inline"></i>
          <b>วันหยุดราชการ</b> จะถูกใช้แทน <b>วันหยุด</b> ในอัลกอริทึมจัดเวร —
          หัวหน้า/รองหัวหน้าจะ <b>หยุด (O)</b> และใช้ <b>อัตรากำลังวันหยุด</b>
        </div>
        <div class="max-h-80 overflow-y-auto border rounded">
          <table class="w-full text-sm">
            <thead class="bg-slate-100 sticky top-0">
              <tr>
                <th class="py-2 px-2">วันที่</th>
                <th class="py-2 px-2">วัน</th>
                <th class="py-2 px-2 text-left">ชื่อวันหยุด</th>
                <th class="py-2 px-2">ประเภท</th>
                <th class="py-2 px-2">จัดการ</th>
              </tr>
            </thead>
            <tbody>${rows || '<tr><td colspan="5" class="py-4 text-slate-400">ไม่มีวันหยุดในเดือนนี้</td></tr>'}</tbody>
          </table>
        </div>
        <div class="mt-3 bg-slate-50 p-3 rounded">
          <div class="text-sm font-medium text-slate-700 mb-2">➕ เพิ่มวันหยุดใหม่ในเดือนนี้</div>
          <div class="flex gap-2">
            <select id="hAddDay" class="border rounded px-2 py-1 text-sm">
              ${Array.from({length:days},(_,i)=>`<option value="${i+1}">${i+1}</option>`).join('')}
            </select>
            <input id="hAddName" placeholder="ชื่อวันหยุด เช่น ลาประจำปี" class="flex-1 border rounded px-2 py-1 text-sm">
            <button onclick="addCustomHoliday()" class="btn-solid btn-primary text-sm">เพิ่ม</button>
          </div>
        </div>
      </div>
    `,
    showCancelButton: false,
    showConfirmButton: true,
    confirmButtonText: 'ปิด',
    confirmButtonColor: '#2e9e63',
    didOpen: () => { if (typeof lucide !== 'undefined') lucide.createIcons(); }
  });
}

function addCustomHoliday() {
  const d = +document.getElementById('hAddDay').value;
  const name = document.getElementById('hAddName').value.trim();
  if (!name) { Swal.showValidationMessage && Swal.showValidationMessage('กรุณากรอกชื่อวันหยุด'); return; }
  const key = `${state.year}-${state.month}-${d}`;
  state.customHolidays[key] = name;
  // If it was previously removed, un-remove
  delete state.removedHolidays[key];
  state.dirty = true;
  persistAll();
  Swal.close();
  setTimeout(() => openHolidayManager(), 100);
  refreshAllViews();
}

function toggleRemoveHoliday(d) {
  const key = `${state.year}-${state.month}-${d}`;
  if (state.removedHolidays[key]) {
    delete state.removedHolidays[key];
  } else {
    state.removedHolidays[key] = true;
    // Also delete from customHolidays in case user added it
    delete state.customHolidays[key];
  }
  state.dirty = true;
  persistAll();
  Swal.close();
  setTimeout(() => openHolidayManager(), 100);
  refreshAllViews();
}

function refreshAllViews() {
  enforceHeadsWeekdayOnly();  // re-enforce since holidays changed
  renderSchedule();
  renderLeaves();
  renderDashboard();
  renderOT();
  updateHolidayBadge();
}


// ========= EXPORT EXCEL =========
function exportSchedule(format) {
  if (format === 'xlsx') exportScheduleExcel();
  else if (format === 'pdf') exportSchedulePDF();
}

function exportScheduleExcel() {
  const days = daysInMonth(state.year, state.month);
  const nurses = state.nurses.filter(n=>n.active!==false).sort((a,b)=>(a.order||999)-(b.order||999));
  const aoa = [];

  // Header rows
  aoa.push(['ตารางการปฏิบัติงาน RSU Medical Clinic']);
  aoa.push([`ประจำเดือน ${THAI_MONTHS[state.month-1]} พ.ศ. ${state.year}`]);
  const req = state.requirements;
  aoa.push([`อัตรากำลัง: วันธรรมดา ช=${req.weekday.ch} บ=${req.weekday.ba} ด=${req.weekday.du} | วันหยุด ช=${req.weekend.ch} บ=${req.weekend.ba} ด=${req.weekend.du}`]);
  aoa.push([]);  // spacer

  // Column headers
  const head1 = ['ที่','ชื่อ-นามสกุล','ตำแหน่ง'];
  for (let d=1; d<=days; d++) head1.push(d);
  ['ช','บ','ด','ชบ','ดบ','DN','O','V','T','รวม'].forEach(s => head1.push(s));
  aoa.push(head1);
  const head2 = ['','',''];
  for (let d=1; d<=days; d++) head2.push(dayLabel(state.year, state.month, d));
  ['ช','บ','ด','ชบ','ดบ','DN','O','V','T','รวม'].forEach(()=>head2.push(''));
  aoa.push(head2);

  // Rows
  nurses.forEach((n,i) => {
    const row = [i+1, n.name, n.position];
    for (let d=1; d<=days; d++) {
      const s = getShift(n.id, d) || getLeave(n.id, d) || '';
      row.push(s);
    }
    const st = computeNurseStats(n.id);
    // ช/บ/ด show EFFECTIVE counts (include compound shifts), others show raw
    row.push(st.chTotal||'', st.baTotal||'', st.duTotal||'',
             st['ชบ']||'', st['ดบ']||'', st['DN']||'',
             st['O']||'', st['V']||'', st['T']||'');
    row.push(st.total);
    aoa.push(row);
  });

  // Signature rows
  aoa.push([]);
  aoa.push(['', '', '', '', 'ผู้จัดเวร: ........................', '', '', '', '', 'หัวหน้าหอผู้ป่วย: ........................', '', '', '', '', 'ผู้อำนวยการ: ........................']);

  const ws = XLSX.utils.aoa_to_sheet(aoa);

  // Merge title rows
  ws['!merges'] = ws['!merges'] || [];
  const totalCols = 3 + days + 10;
  ws['!merges'].push({ s:{r:0,c:0}, e:{r:0,c:totalCols-1} });
  ws['!merges'].push({ s:{r:1,c:0}, e:{r:1,c:totalCols-1} });
  ws['!merges'].push({ s:{r:2,c:0}, e:{r:2,c:totalCols-1} });

  // Column widths
  const cols = [{wch:5},{wch:28},{wch:18}];
  for (let d=1; d<=days; d++) cols.push({wch:4});
  for (let i=0; i<10; i++) cols.push({wch:5});
  ws['!cols'] = cols;
  ws['!freeze'] = { xSplit: 3, ySplit: 5 };

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'ตารางเวร');
  const fname = `ตารางเวร_${THAI_MONTHS[state.month-1]}_${state.year}.xlsx`;
  XLSX.writeFile(wb, fname);
  showSuccess('ดาวน์โหลด Excel แล้ว');
}

async function exportSchedulePDF() {
  showLoading('กำลังสร้าง PDF...');
  await new Promise(r => setTimeout(r, 80));
  try {
    const { jsPDF } = window.jspdf;
    // Capture the schedule table
    switchTab('schedule');
    await new Promise(r => setTimeout(r, 100));
    const wrap = document.querySelector('.schedule-wrapper');
    const original = { maxHeight: wrap.style.maxHeight, overflow: wrap.style.overflow };
    wrap.style.maxHeight = 'none'; wrap.style.overflow = 'visible';

    const canvas = await html2canvas(wrap, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
    wrap.style.maxHeight = original.maxHeight; wrap.style.overflow = original.overflow;

    const imgData = canvas.toDataURL('image/png');
    const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a3' });
    const pageW = pdf.internal.pageSize.getWidth();
    const pageH = pdf.internal.pageSize.getHeight();
    // Title
    pdf.setFontSize(14);
    pdf.text(`ตารางเวร เดือน ${THAI_MONTHS[state.month-1]} ${state.year}`, pageW/2, 10, { align: 'center' });
    // Image scaled
    const imgW = pageW - 20;
    const imgH = (canvas.height / canvas.width) * imgW;
    const finalH = Math.min(imgH, pageH - 25);
    pdf.addImage(imgData, 'PNG', 10, 15, imgW, finalH);
    pdf.save(`ตารางเวร_${THAI_MONTHS[state.month-1]}_${state.year}.pdf`);
    Swal.close();
    showSuccess('ดาวน์โหลด PDF แล้ว');
  } catch(e) {
    console.error(e);
    Swal.close();
    showError('สร้าง PDF ไม่สำเร็จ: ' + e.message);
  }
}

function exportOT(format) {
  if (format === 'xlsx') exportOTExcel();
  else exportOTPDF();
}

function exportOTExcel() {
  const ot = computeOT();
  const ts = state.timesheetSettings || {};
  const aoa = [];
  aoa.push([`รายงานเงินเดือน/ค่าตอบแทน — ${THAI_MONTHS[state.month-1]} ${state.year}`]);
  aoa.push([`อัตรา default: ${(ts.default_hourly_rate ?? 120)} บาท/ชม. | ภาษี ณ ที่จ่าย: ${(ts.tax_rate ?? 3)}%`]);
  aoa.push([]);
  aoa.push(['ที่','ชื่อ-นามสกุล','ตำแหน่ง','ชั่วโมงรวม','อัตรา/ชม.','ค่าตอบแทน (บาท)','ภาษี (บาท)','คงเหลือ (บาท)']);
  ot.rows.forEach((r,i) => {
    aoa.push([i+1, r.name, r.position, r.hours, r.rate, r.gross, r.tax, r.net]);
  });
  aoa.push(['','','รวม:', ot.totalHours, '', ot.totalAmount, ot.totalTax, ot.totalNet]);

  const ws = XLSX.utils.aoa_to_sheet(aoa);
  ws['!merges'] = [
    { s:{r:0,c:0}, e:{r:0,c:7} },
    { s:{r:1,c:0}, e:{r:1,c:7} }
  ];
  ws['!cols'] = [{wch:5},{wch:28},{wch:18},{wch:10},{wch:10},{wch:14},{wch:10},{wch:14}];

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Payroll');
  XLSX.writeFile(wb, `Payroll_${THAI_MONTHS[state.month-1]}_${state.year}.xlsx`);
  showSuccess('ดาวน์โหลดรายงานเงินเดือน Excel แล้ว');
}

async function exportOTPDF() {
  showLoading('กำลังสร้าง PDF...');
  await new Promise(r => setTimeout(r, 80));
  try {
    const { jsPDF } = window.jspdf;
    const el = document.getElementById('tab-ot');
    const canvas = await html2canvas(el, { scale: 2, backgroundColor: '#f1f5f9', useCORS: true });
    const imgData = canvas.toDataURL('image/png');
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const pageW = pdf.internal.pageSize.getWidth();
    const pageH = pdf.internal.pageSize.getHeight();
    const imgW = pageW - 16;
    const imgH = (canvas.height / canvas.width) * imgW;

    let heightLeft = imgH;
    let position = 8;
    pdf.addImage(imgData, 'PNG', 8, position, imgW, imgH);
    heightLeft -= (pageH - 16);
    while (heightLeft > 0) {
      pdf.addPage();
      position = -(imgH - heightLeft) + 8;
      pdf.addImage(imgData, 'PNG', 8, position, imgW, imgH);
      heightLeft -= (pageH - 16);
    }
    pdf.save(`OT_Report_${THAI_MONTHS[state.month-1]}_${state.year}.pdf`);
    Swal.close();
    showSuccess('ดาวน์โหลด OT PDF แล้ว');
  } catch(e) {
    console.error(e);
    Swal.close();
    showError('สร้าง PDF ไม่สำเร็จ: ' + e.message);
  }
}

// ========= GLOBAL ACTIONS =========
function saveAll() {
  readReqFromUI();
  readOTFromUI();
  persistAll();
  renderDashboard();
  renderOT();
  showSuccess('บันทึกข้อมูลทั้งหมดแล้ว');
}

function clearAll() {
  confirmAct('ล้างตารางเดือนนี้?', 'จะลบข้อมูลตารางเวรและวันลาของเดือนนี้ (รายชื่อพยาบาลยังอยู่)').then(r => {
    if (!r.isConfirmed) return;
    for (const key in state.schedule) delete state.schedule[key];
    for (const key in state.leaves) delete state.leaves[key];
    state.warnings = [];
    persistAll();
    renderSchedule();
    renderLeaves();
    renderDashboard();
    showSuccess('ล้างเรียบร้อย');
  });
}

async function onMonthChange() {
  state.month = +document.getElementById('monthSelect').value;
  state.year = +document.getElementById('yearSelect').value;
  // โหลดข้อมูล schedule + leaves ของเดือนใหม่จาก server
  await loadFromStorage();
  enforceHeadsWeekdayOnly();
  renderSchedule();
  renderLeaves();
  renderDashboard();
  renderOT();
  updateHolidayBadge();
}

// ดึง timesheet settings (ชื่อคลินิก/ผู้ลงนาม/อัตรา default/ภาษี)
async function loadTimesheetSettings() {
  try {
    const r = await fetch('ajax_nurse_register.php?action=get_timesheet_settings', { credentials: 'same-origin' });
    const j = await r.json();
    if (j.ok && j.settings) {
      state.timesheetSettings = {
        clinic_name: j.settings.clinic_name || '',
        signer_name: j.settings.signer_name || '',
        signer_title: j.settings.signer_title || '',
        tax_rate: Number(j.settings.tax_rate ?? 3),
        default_hourly_rate: Number(j.settings.default_hourly_rate ?? 120),
      };
    }
  } catch (e) { console.warn('loadTimesheetSettings failed', e); }
}

// ดึง hourly_rate รายคนจาก server แล้วใส่กลับใน state.nurses[].hourlyRate
async function refreshNurseRates(showToast) {
  const staffIds = state.nurses.filter(n => n.staffId).map(n => n.staffId);
  const orgIds   = state.nurses.filter(n => n.orgMemberId).map(n => n.orgMemberId);
  if (staffIds.length === 0 && orgIds.length === 0) {
    if (showToast) Swal.fire({ icon: 'info', title: 'ยังไม่มีพยาบาลที่ผูกกับ Identity / ผังองค์กร', timer: 2000, showConfirmButton: false });
    return;
  }
  const params = new URLSearchParams();
  staffIds.forEach(id => params.append('staff_ids[]', String(id)));
  orgIds.forEach(id   => params.append('org_member_ids[]', String(id)));
  try {
    const res = await fetch('ajax_nurse_register.php?action=list_nurse_rates&' + params.toString(), { credentials: 'same-origin' });
    const j = await res.json();
    if (!j.ok) throw new Error(j.error || 'load failed');
    const sMap = j.rates?.staff || {};
    const oMap = j.rates?.org   || {};
    state.nurses.forEach(n => {
      let v = null;
      if (n.staffId      && sMap[n.staffId]      !== undefined) v = sMap[n.staffId];
      else if (n.orgMemberId && oMap[n.orgMemberId] !== undefined) v = oMap[n.orgMemberId];
      n.hourlyRate = (v === null || v === undefined) ? null : Number(v);
    });
    if (typeof renderOT === 'function') { try { renderOT(); } catch (e) {} }
    if (typeof renderDashboard === 'function') { try { renderDashboard(); } catch (e) {} }
    if (showToast) Swal.fire({ icon: 'success', title: 'รีเฟรชอัตราแล้ว', timer: 1200, showConfirmButton: false });
  } catch (e) {
    console.warn('refreshNurseRates failed', e);
    if (showToast) Swal.fire({ icon: 'error', title: 'รีเฟรชไม่สำเร็จ', text: String(e) });
  }
}

// ========= STARTUP =========
window.addEventListener('DOMContentLoaded', async () => {
  initSelectors();             // ต้อง init ก่อนเพื่อให้ state.year/month มีค่า
  await loadFromStorage();     // โหลดข้อมูลจาก server (async)
  await loadTimesheetSettings();
  await refreshNurseRates(false);
  loadReqToUI();
  loadOTToUI();
  renderShiftPalette();
  renderLeavePalette();
  renderShiftTypesLegend();
  renderDashboard();
  updateHolidayBadge();
  lucide.createIcons();

  // Warn before leaving with unsaved changes
  window.addEventListener('beforeunload', e => {
    if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  // Auto-save every 60 seconds if dirty
  setInterval(() => { if (state.dirty) persistAll(); }, 60000);
});
</script>
</body>
</html>
