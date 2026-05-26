<?php
/**
 * user/consent_form_preview.php
 *
 * Standalone visual preview of the vaccine consent form + tablet e-signature.
 * Self-contained — no DB writes, no auth, no session dependency.
 * Open in browser to demo the flow before production wiring.
 *
 * Two views available via ?view= param:
 *   ?view=patient (default)  — patient fills on own phone (portrait)
 *   ?view=tablet             — clinic tablet handover (landscape, larger signature)
 *
 * Production note: this file is a PREVIEW ONLY. The real form will use
 * server-side validation, audit logging, and DB persistence per the plan.
 */
declare(strict_types=1);
$view = ($_GET['view'] ?? 'patient') === 'tablet' ? 'tablet' : 'patient';

// Mock data — pretend this was pre-filled from booking + sys_users join
$mock = [
    'patient_name'    => 'ณภัทร ธีรมงคล',
    'national_id'     => '1-1037-12345-67-8',
    'date_of_birth'   => '15 มี.ค. 2546',
    'mobile'          => '081-234-5678',
    'student_id'      => '64012345',
    'faculty'         => 'แพทยศาสตร์ชั้นปีที่ 3',
    'address'         => '52/3 ซ.พหลโยธิน 87 ต.หลักหก อ.เมือง จ.ปทุมธานี 12000',
    'campaign_title'  => 'ฉีดวัคซีนไข้หวัดใหญ่ประจำปี 2569',
    'appointment_at'  => '26 พ.ค. 2569 · 10:30 - 11:00 น.',
];
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>แบบฟอร์มยินยอมรับวัคซีน · Preview · RSU Medical Clinic</title>
<link rel="stylesheet" href="../assets/css/rsufont.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/tailwind.min.css">
<style>
  *,*::before,*::after { box-sizing: border-box; }
  html,body { margin:0; padding:0; }
  body {
    font-family: 'Sarabun', system-ui, sans-serif;
    background:
      radial-gradient(circle at 12% 8%, rgba(46,158,99,.12) 0, transparent 420px),
      radial-gradient(circle at 88% 90%, rgba(6,182,212,.10) 0, transparent 380px),
      #f8faff;
    min-height: 100vh;
    color:#0f172a;
    -webkit-tap-highlight-color: transparent;
  }
  /* === Layout shell === */
  .shell {
    max-width: 720px;
    margin: 0 auto;
    padding: 20px 16px 140px;
  }
  body[data-view="tablet"] .shell {
    max-width: 1100px;
    padding: 24px 28px 160px;
  }
  /* === Top bar === */
  .topbar {
    display:flex; align-items:center; gap:12px;
    margin-bottom: 16px;
  }
  .topbar .logo {
    width:42px; height:42px; border-radius:14px;
    background: linear-gradient(135deg,#2e9e63,#4dc98a);
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:18px;
    box-shadow: 0 8px 20px rgba(46,158,99,.32);
  }
  .topbar h1 {
    margin:0; font-size:16px; font-weight:800; letter-spacing:-.01em;
    color:#0f172a; line-height:1.2;
  }
  .topbar .sub { font-size:11.5px; color:#64748b; font-weight:500; }
  .preview-pill {
    margin-left:auto;
    background: linear-gradient(135deg,#fef3c7,#fde68a);
    color:#92400e;
    font-size:10.5px; font-weight:800;
    padding:5px 10px; border-radius:999px;
    border:1px solid #fcd34d;
    text-transform: uppercase; letter-spacing:.08em;
  }
  /* === Progress stepper === */
  .stepper {
    display:flex; align-items:center; gap:6px;
    background:#fff;
    padding:12px 14px;
    border-radius:18px;
    border:1px solid #e2e8f0;
    margin-bottom:18px;
    box-shadow: 0 4px 14px rgba(15,23,42,.04);
  }
  .step-dot {
    flex:1; display:flex; align-items:center; gap:8px;
    color:#94a3b8; font-size:12px; font-weight:700;
    min-width: 0;
  }
  .step-dot .num {
    width:26px; height:26px; border-radius:50%;
    background:#f1f5f9; color:#94a3b8;
    display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:800; flex-shrink:0;
    border:2px solid #e2e8f0;
    transition: all .25s ease;
  }
  .step-dot.is-active { color:#0f172a; }
  .step-dot.is-active .num {
    background: linear-gradient(135deg,#2e9e63,#4dc98a);
    color:#fff; border-color:#2e9e63;
    box-shadow: 0 4px 12px rgba(46,158,99,.4);
    transform: scale(1.08);
  }
  .step-dot.is-done .num {
    background:#dcfce7; color:#15803d; border-color:#86efac;
  }
  .step-dot .lbl {
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .step-line { flex-shrink:0; width:14px; height:2px; background:#e2e8f0; border-radius:1px; }
  .step-line.is-done { background:#86efac; }

  /* === Card === */
  .card {
    background:#fff;
    border-radius: 28px;
    padding: 24px;
    border:1px solid #e2e8f0;
    box-shadow: 0 8px 28px rgba(15,23,42,.06);
    margin-bottom: 16px;
  }
  .card-title {
    display:flex; align-items:center; gap:10px;
    margin-bottom:18px;
  }
  .card-title .ic {
    width:38px; height:38px; border-radius:12px;
    background:#f0fdf4; color:#15803d;
    display:flex; align-items:center; justify-content:center;
    font-size:16px;
    border:1.5px solid #bbf7d0;
  }
  .card-title h2 {
    font-size:18px; font-weight:800; margin:0;
    letter-spacing:-.01em; color:#0f172a;
  }
  .card-title .desc { font-size:12.5px; color:#64748b; margin-top:2px; }

  /* === Info rows (read-only) === */
  .info-grid { display:grid; grid-template-columns: 1fr; gap:10px; }
  body[data-view="tablet"] .info-grid { grid-template-columns: 1fr 1fr; gap:14px; }
  .info-item {
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:14px;
    padding: 12px 14px;
  }
  .info-item .k {
    font-size:11px; font-weight:700; color:#64748b;
    text-transform: uppercase; letter-spacing:.05em;
    margin-bottom:2px;
  }
  .info-item .v {
    font-size:14.5px; font-weight:600; color:#0f172a; line-height:1.4;
  }
  .info-item.appt {
    background: linear-gradient(135deg,#f0fdf4,#dcfce7);
    border-color:#86efac;
  }
  .info-item.appt .k { color:#15803d; }

  /* === Editable inputs === */
  .field { margin-bottom: 14px; }
  .field label {
    display:block; font-size:12.5px; font-weight:700;
    color:#334155; margin-bottom:6px;
  }
  .field label .req { color:#dc2626; margin-left:2px; }
  .field input[type=text],
  .field input[type=tel],
  .field input[type=date],
  .field textarea,
  .field select {
    width:100%;
    padding: 12px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 14px;
    font-size: 14.5px;
    font-family: inherit;
    color:#0f172a;
    background:#fff;
    transition: all .15s ease;
  }
  .field input:focus, .field textarea:focus, .field select:focus {
    outline:none;
    border-color:#2e9e63;
    box-shadow: 0 0 0 4px rgba(46,158,99,.12);
  }
  .field-hint { font-size:11.5px; color:#94a3b8; margin-top:4px; }

  /* === Screening Q (Yes/No big tap targets) === */
  .q-list { display:flex; flex-direction:column; gap:12px; }
  .q-row {
    border:1.5px solid #e2e8f0;
    border-radius: 18px;
    padding: 14px 16px;
    background:#fff;
    transition: all .2s ease;
  }
  .q-row.is-yes {
    border-color:#fbbf24;
    background: linear-gradient(135deg,#fffbeb,#fef3c7);
  }
  .q-text {
    font-size:14.5px; font-weight:600; color:#0f172a;
    line-height:1.45;
    margin-bottom: 12px;
    display:flex; gap:10px;
  }
  .q-text .qnum {
    flex-shrink:0;
    width:26px; height:26px; border-radius:8px;
    background:#f1f5f9; color:#475569;
    display:flex; align-items:center; justify-content:center;
    font-size:12px; font-weight:800;
  }
  .is-yes .q-text .qnum { background:#fde68a; color:#92400e; }
  .q-opts { display:grid; grid-template-columns: 1fr 1fr; gap:8px; }
  .q-opt {
    display:flex; align-items:center; justify-content:center; gap:8px;
    padding: 12px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-size:14px; font-weight:700;
    color:#475569;
    background:#fff;
    cursor:pointer;
    user-select:none;
    transition: all .15s ease;
  }
  .q-opt:active { transform: scale(.96); }
  .q-opt input { display:none; }
  .q-opt.is-yes { border-color:#f59e0b; }
  .q-opt.is-no  { border-color:#10b981; }
  .q-opt input:checked + i { transform: scale(1.18); }
  .q-opt.is-yes input:checked ~ * { color:#92400e; }
  .q-opt.is-yes:has(input:checked) {
    background:#fef3c7; border-color:#f59e0b;
    box-shadow: 0 0 0 4px rgba(245,158,11,.15);
    color:#92400e;
  }
  .q-opt.is-no:has(input:checked) {
    background:#dcfce7; border-color:#10b981;
    box-shadow: 0 0 0 4px rgba(16,185,129,.15);
    color:#15803d;
  }
  .q-warning {
    margin-top:10px; padding:10px 12px;
    background:#fff; border-radius:10px;
    border-left: 3px solid #f59e0b;
    font-size:12px; color:#92400e; font-weight:600;
    display:none;
  }
  .q-row.is-yes .q-warning { display:block; }

  /* === Consent decision === */
  .decision-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  .decision-card {
    padding: 18px 16px;
    border-radius: 18px;
    border: 2px solid #e2e8f0;
    background:#fff;
    cursor:pointer;
    text-align:center;
    transition: all .2s ease;
    user-select:none;
  }
  .decision-card:active { transform: scale(.97); }
  .decision-card input { display:none; }
  .decision-card .dc-ic {
    width:56px; height:56px; border-radius:18px;
    margin: 0 auto 10px;
    display:flex; align-items:center; justify-content:center;
    font-size:24px;
  }
  .decision-card.consent .dc-ic { background:#dcfce7; color:#15803d; }
  .decision-card.decline .dc-ic { background:#fee2e2; color:#b91c1c; }
  .decision-card .dc-title { font-size:15px; font-weight:800; color:#0f172a; }
  .decision-card .dc-desc { font-size:11.5px; color:#64748b; margin-top:4px; line-height:1.4; }
  .decision-card:has(input:checked).consent {
    border-color:#10b981;
    background: linear-gradient(135deg,#f0fdf4,#dcfce7);
    box-shadow: 0 8px 24px rgba(16,185,129,.25);
  }
  .decision-card:has(input:checked).decline {
    border-color:#ef4444;
    background: linear-gradient(135deg,#fef2f2,#fee2e2);
    box-shadow: 0 8px 24px rgba(239,68,68,.25);
  }

  /* === Consent text === */
  .consent-text {
    background: linear-gradient(135deg,#f8fafc,#f1f5f9);
    border:1px solid #e2e8f0;
    border-radius:16px;
    padding: 16px 18px;
    font-size:13px;
    line-height:1.7;
    color:#334155;
    max-height: 220px;
    overflow-y: auto;
    margin-bottom: 14px;
  }
  .consent-text p { margin: 0 0 10px; }
  .consent-text strong { color:#0f172a; }

  /* === Signature pad === */
  .sig-wrap {
    background:#fff;
    border: 2px dashed #cbd5e1;
    border-radius: 18px;
    overflow:hidden;
    position:relative;
    transition: all .2s ease;
  }
  .sig-wrap.has-ink { border-style:solid; border-color:#2e9e63; }
  .sig-canvas {
    display:block;
    width:100%;
    height: 220px;
    touch-action: none;
    cursor: crosshair;
    background:
      linear-gradient(transparent calc(100% - 1px), #e2e8f0 calc(100% - 1px));
    background-size: 100% 32px;
  }
  body[data-view="tablet"] .sig-canvas { height: 320px; }
  .sig-placeholder {
    position:absolute; inset:0;
    display:flex; align-items:center; justify-content:center;
    flex-direction:column; gap:10px;
    color:#94a3b8; pointer-events:none;
    transition: opacity .2s ease;
  }
  .sig-placeholder.is-hidden { opacity:0; }
  .sig-placeholder i { font-size:36px; color:#cbd5e1; }
  .sig-placeholder .t { font-size:14px; font-weight:700; }
  .sig-placeholder .h { font-size:11.5px; color:#94a3b8; }
  .sig-line {
    position:absolute; left:20px; right:20px;
    bottom: 50px; height:1px;
    background: linear-gradient(to right, transparent, #cbd5e1 20%, #cbd5e1 80%, transparent);
    pointer-events:none;
  }
  .sig-x {
    position:absolute; left:24px; bottom: 38px;
    font-size:18px; color:#94a3b8; font-weight:800;
    pointer-events:none;
  }
  .sig-toolbar {
    display:flex; align-items:center; gap:8px;
    padding: 10px 14px;
    background:#f8fafc;
    border-top:1px solid #e2e8f0;
  }
  .sig-tool-btn {
    background:#fff;
    border:1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 8px 14px;
    font-size:12.5px; font-weight:700;
    color:#475569;
    display:inline-flex; align-items:center; gap:6px;
    cursor:pointer;
    transition: all .15s ease;
  }
  .sig-tool-btn:hover { border-color:#2e9e63; color:#15803d; }
  .sig-tool-btn:active { transform: scale(.96); }
  .sig-stats { margin-left:auto; font-size:11.5px; color:#94a3b8; font-weight:600; }
  .sig-stats .dot {
    display:inline-block; width:8px; height:8px;
    border-radius:50%; background:#cbd5e1;
    margin-right:6px; vertical-align: middle;
  }
  .has-ink .sig-stats .dot {
    background:#10b981;
    box-shadow: 0 0 0 3px rgba(16,185,129,.2);
  }

  /* === Witness section (tablet handover) === */
  .witness-card {
    background: linear-gradient(135deg,#eff6ff,#dbeafe);
    border:1.5px solid #93c5fd;
    border-radius:18px;
    padding:14px 16px;
    margin-bottom:14px;
    display:flex; gap:12px; align-items:center;
  }
  .witness-card .w-ic {
    width:42px; height:42px; border-radius:12px;
    background:#fff; color:#1d4ed8;
    display:flex; align-items:center; justify-content:center;
    font-size:18px;
    border:1.5px solid #93c5fd;
    flex-shrink:0;
  }
  .witness-card h3 { margin:0 0 2px; font-size:14px; font-weight:800; color:#1e3a8a; }
  .witness-card p { margin:0; font-size:12px; color:#1e40af; line-height:1.4; }

  /* === Bottom action bar === */
  .actionbar {
    position:fixed; left:0; right:0; bottom:0;
    background:rgba(255,255,255,.95);
    backdrop-filter: blur(12px);
    border-top:1px solid #e2e8f0;
    padding: 14px 16px;
    box-shadow: 0 -8px 24px rgba(15,23,42,.06);
    z-index: 50;
  }
  .actionbar-inner {
    max-width: 720px; margin: 0 auto;
    display:flex; gap:10px; align-items:center;
  }
  body[data-view="tablet"] .actionbar-inner { max-width: 1100px; }
  .btn {
    padding: 14px 22px;
    border-radius: 14px;
    font-size: 14.5px; font-weight: 800;
    border: none;
    cursor:pointer;
    display:inline-flex; align-items:center; justify-content:center; gap:8px;
    transition: all .15s ease;
    font-family: inherit;
  }
  .btn:active { transform: scale(.97); }
  .btn-primary {
    background: linear-gradient(135deg,#2e9e63,#16a34a);
    color:#fff;
    box-shadow: 0 8px 20px rgba(46,158,99,.35);
  }
  .btn-primary:hover { box-shadow: 0 12px 28px rgba(46,158,99,.45); }
  .btn-primary:disabled {
    background:#e2e8f0; color:#94a3b8; cursor:not-allowed;
    box-shadow:none;
  }
  .btn-ghost {
    background:#fff;
    color:#475569;
    border:1.5px solid #e2e8f0;
  }
  .btn-ghost:hover { border-color:#cbd5e1; }
  .btn-flex { flex:1; }

  /* === Status indicators === */
  .status-banner {
    border-radius: 18px;
    padding: 18px 18px;
    margin-bottom: 16px;
    display:flex; gap:14px; align-items:flex-start;
  }
  .status-banner .sb-ic {
    width:48px; height:48px; border-radius:14px;
    display:flex; align-items:center; justify-content:center;
    font-size:20px; flex-shrink:0;
  }
  .status-banner h3 { margin:0 0 2px; font-size:15px; font-weight:800; }
  .status-banner p { margin:0; font-size:12.5px; line-height:1.5; }
  .status-banner.flagged {
    background: linear-gradient(135deg,#fef3c7,#fde68a);
    border:1.5px solid #fbbf24;
    color:#78350f;
  }
  .status-banner.flagged .sb-ic { background:#fff; color:#d97706; }
  .status-banner.ok {
    background: linear-gradient(135deg,#dcfce7,#bbf7d0);
    border:1.5px solid #86efac;
    color:#14532d;
  }
  .status-banner.ok .sb-ic { background:#fff; color:#16a34a; }

  /* === View switcher === */
  .view-switch {
    position:fixed; top:14px; right:14px;
    background:#fff; border:1.5px solid #e2e8f0;
    border-radius:999px;
    padding: 4px;
    display:flex; gap:2px;
    box-shadow: 0 4px 12px rgba(15,23,42,.08);
    z-index: 60;
    font-size:11.5px;
  }
  .view-switch a {
    padding: 6px 12px;
    border-radius: 999px;
    color:#64748b; font-weight:700;
    text-decoration:none;
    transition: all .15s ease;
  }
  .view-switch a.is-active {
    background: linear-gradient(135deg,#2e9e63,#16a34a);
    color:#fff;
  }
  .view-switch a:hover:not(.is-active) { color:#2e9e63; }

  /* === Step transitions === */
  .step-pane { display:none; }
  .step-pane.is-active {
    display:block;
    animation: fadeUp .35s cubic-bezier(.16,1,.3,1);
  }
  @keyframes fadeUp {
    from { opacity:0; transform: translateY(8px); }
    to   { opacity:1; transform: translateY(0); }
  }

  /* === Decline reason area === */
  #declineReasonBox { display:none; margin-top:14px; }
  .decision-grid:has(.decision-card.decline input:checked) ~ #declineReasonBox { display:block; }

  /* === Summary === */
  .sum-row {
    display:flex; padding: 10px 0;
    border-bottom: 1px dashed #e2e8f0;
    gap: 12px;
  }
  .sum-row:last-child { border-bottom:none; }
  .sum-row .k { font-size:12.5px; color:#64748b; font-weight:600; flex:0 0 130px; }
  .sum-row .v { font-size:13.5px; color:#0f172a; font-weight:700; flex:1; text-align:right; }
  .sum-row .v.flag { color:#b45309; }
  .sum-row .v.ok { color:#15803d; }
  .sum-row .v.bad { color:#b91c1c; }

  /* === Tablet adjustments === */
  body[data-view="tablet"] {
    background:
      radial-gradient(circle at 5% 5%, rgba(46,158,99,.10) 0, transparent 500px),
      radial-gradient(circle at 95% 95%, rgba(59,130,246,.10) 0, transparent 500px),
      #f8faff;
  }
  body[data-view="tablet"] .card { padding: 28px 32px; border-radius: 32px; }
  body[data-view="tablet"] .card-title h2 { font-size: 22px; }
  body[data-view="tablet"] .decision-grid { gap:18px; }
  body[data-view="tablet"] .decision-card { padding: 24px 18px; }
  body[data-view="tablet"] .decision-card .dc-ic { width:72px; height:72px; font-size:32px; border-radius:22px; }
  body[data-view="tablet"] .decision-card .dc-title { font-size:18px; }
  body[data-view="tablet"] .stepper { padding: 16px 18px; }
  body[data-view="tablet"] .step-dot { font-size:13px; }
  body[data-view="tablet"] .step-dot .num { width:30px; height:30px; font-size:13px; }
  body[data-view="tablet"] .btn { padding: 16px 30px; font-size:15.5px; }
  body[data-view="tablet"] .q-opts { grid-template-columns: 1fr 1fr; }
  body[data-view="tablet"] .q-opt { padding: 16px 18px; font-size:15.5px; }

  /* === Mobile tweaks === */
  @media (max-width: 480px) {
    .step-dot .lbl { display:none; }
    .step-dot { flex: 0 0 auto; }
    .step-dot.is-active { flex: 1; }
    .step-dot.is-active .lbl { display:inline; }
  }

  /* === A11y: respect reduced motion === */
  @media (prefers-reduced-motion: reduce) {
    *,*::before,*::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
  }
</style>
</head>
<body data-view="<?= $view ?>">

<div class="view-switch" role="group" aria-label="สลับมุมมอง preview">
  <a href="?view=patient" class="<?= $view==='patient'?'is-active':'' ?>">มือถือคนไข้</a>
  <a href="?view=tablet"  class="<?= $view==='tablet' ?'is-active':'' ?>">แทบเล็ตคลินิก</a>
</div>

<div class="shell">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="logo"><i class="fa-solid fa-syringe"></i></div>
    <div>
      <h1>แบบฟอร์มยินยอมรับการฉีดวัคซีน</h1>
      <div class="sub">RSU Medical Clinic · <?= htmlspecialchars($mock['campaign_title']) ?></div>
    </div>
    <span class="preview-pill"><i class="fa-solid fa-flask"></i> Preview</span>
  </div>

  <!-- STEPPER -->
  <div class="stepper" id="stepper">
    <div class="step-dot is-active" data-step="1"><div class="num">1</div><div class="lbl">ข้อมูล</div></div>
    <div class="step-line"></div>
    <div class="step-dot" data-step="2"><div class="num">2</div><div class="lbl">คัดกรอง</div></div>
    <div class="step-line"></div>
    <div class="step-dot" data-step="3"><div class="num">3</div><div class="lbl">ยินยอม</div></div>
    <div class="step-line"></div>
    <div class="step-dot" data-step="4"><div class="num">4</div><div class="lbl">ลายเซ็น</div></div>
    <div class="step-line"></div>
    <div class="step-dot" data-step="5"><div class="num">5</div><div class="lbl">สรุป</div></div>
  </div>

  <!-- ============= STEP 1 : INFO ============= -->
  <div class="step-pane is-active" data-step-pane="1">
    <div class="card">
      <div class="card-title">
        <div class="ic"><i class="fa-solid fa-id-card"></i></div>
        <div>
          <h2>ข้อมูลผู้รับการฉีดวัคซีน</h2>
          <div class="desc">โปรดตรวจสอบความถูกต้อง ถ้าผิดพลาดกดแก้ไขที่ฟิลด์</div>
        </div>
      </div>

      <div class="info-item appt" style="margin-bottom:14px;">
        <div class="k"><i class="fa-solid fa-calendar-check"></i> นัดฉีดวัคซีน</div>
        <div class="v"><?= htmlspecialchars($mock['campaign_title']) ?><br><?= htmlspecialchars($mock['appointment_at']) ?></div>
      </div>

      <div class="info-grid">
        <div class="info-item">
          <div class="k">ชื่อ-นามสกุล</div>
          <div class="v"><?= htmlspecialchars($mock['patient_name']) ?></div>
        </div>
        <div class="info-item">
          <div class="k">เลขประจำตัวประชาชน</div>
          <div class="v"><?= htmlspecialchars($mock['national_id']) ?></div>
        </div>
        <div class="info-item">
          <div class="k">วันเกิด</div>
          <div class="v"><?= htmlspecialchars($mock['date_of_birth']) ?></div>
        </div>
        <div class="info-item">
          <div class="k">รหัสนักศึกษา/พนักงาน</div>
          <div class="v"><?= htmlspecialchars($mock['student_id']) ?> · <?= htmlspecialchars($mock['faculty']) ?></div>
        </div>
      </div>

      <div style="margin-top:14px;">
        <div class="field">
          <label>เบอร์โทรศัพท์ติดต่อ <span class="req">*</span></label>
          <input type="tel" value="<?= htmlspecialchars($mock['mobile']) ?>">
          <div class="field-hint">ใช้สำหรับติดต่อกรณีฉุกเฉินหลังฉีดวัคซีน</div>
        </div>
        <div class="field">
          <label>ที่อยู่ปัจจุบัน <span class="req">*</span></label>
          <textarea rows="2"><?= htmlspecialchars($mock['address']) ?></textarea>
        </div>
        <div class="field">
          <label>ประวัติโรคประจำตัว / ยาที่กำลังใช้</label>
          <textarea rows="2" placeholder="เช่น เบาหวาน, ความดันสูง, ยาละลายลิ่มเลือด — ถ้าไม่มีให้เว้นว่าง"></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- ============= STEP 2 : SCREENING ============= -->
  <div class="step-pane" data-step-pane="2">
    <div class="card">
      <div class="card-title">
        <div class="ic" style="background:#fef3c7;color:#b45309;border-color:#fcd34d;"><i class="fa-solid fa-clipboard-question"></i></div>
        <div>
          <h2>แบบคัดกรองก่อนรับวัคซีน</h2>
          <div class="desc">7 ข้อสั้นๆ · ถ้าข้อใดตอบ "ใช่" จะส่งให้แพทย์ประเมินก่อน</div>
        </div>
      </div>

      <div class="q-list">
        <?php
        $questions = [
          1 => ['ท่านเคยมีอาการแพ้ไข่ไก่ หรือผลิตภัณฑ์จากไข่อย่างรุนแรงหรือไม่', 'หากเคยแพ้ แพทย์ต้องประเมินก่อนเสมอ'],
          2 => ['ท่านเคยแพ้วัคซีนใด ๆ มาก่อน หรือเคยแพ้ยาขั้นรุนแรงหรือไม่', 'รวมถึงอาการบวม ผื่น หายใจไม่ออก'],
          3 => ['ขณะนี้ท่านมีไข้ ≥ 38°C หรือมีอาการเจ็บป่วยรุนแรงหรือไม่', 'ถ้ามีไข้สูง อาจต้องเลื่อนนัด'],
          4 => ['ท่านเพิ่งหายป่วยจากโรคติดเชื้อภายใน 7 วันที่ผ่านมาหรือไม่', 'รวมถึง COVID-19, ไข้หวัดใหญ่'],
          5 => ['ท่านเพิ่งออกจากโรงพยาบาลภายใน 14 วันที่ผ่านมาหรือไม่', ''],
          6 => ['ท่านมีโรคประจำตัวที่กำลังกำเริบหรือควบคุมไม่ได้หรือไม่ (เช่น โรคหัวใจ, เบาหวาน, ความดัน)', ''],
          7 => ['ท่าน (เพศหญิง) ตั้งครรภ์ หรือสงสัยว่าตั้งครรภ์ หรือกำลังให้นมบุตรหรือไม่', 'สำหรับวัคซีนบางชนิดต้องระวังเป็นพิเศษ'],
        ];
        foreach ($questions as $i => [$text, $note]):
        ?>
        <div class="q-row" data-q="<?= $i ?>">
          <div class="q-text">
            <span class="qnum"><?= $i ?></span>
            <span><?= htmlspecialchars($text) ?></span>
          </div>
          <div class="q-opts">
            <label class="q-opt is-no">
              <input type="radio" name="q<?= $i ?>" value="0" onchange="updateQ(<?= $i ?>,'no')">
              <i class="fa-solid fa-circle-check"></i>
              <span>ไม่ใช่</span>
            </label>
            <label class="q-opt is-yes">
              <input type="radio" name="q<?= $i ?>" value="1" onchange="updateQ(<?= $i ?>,'yes')">
              <i class="fa-solid fa-triangle-exclamation"></i>
              <span>ใช่</span>
            </label>
          </div>
          <?php if ($note): ?>
            <div class="q-warning"><i class="fa-solid fa-info-circle"></i> <?= htmlspecialchars($note) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ============= STEP 3 : CONSENT TEXT + DECISION ============= -->
  <div class="step-pane" data-step-pane="3">
    <div class="card">
      <div class="card-title">
        <div class="ic" style="background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;"><i class="fa-solid fa-file-signature"></i></div>
        <div>
          <h2>หนังสือยินยอมรับการฉีดวัคซีน</h2>
          <div class="desc">โปรดอ่านโดยละเอียดก่อนตัดสินใจ</div>
        </div>
      </div>

      <div class="consent-text">
        <p><strong>1. ข้าพเจ้าทราบและเข้าใจว่า</strong> วัคซีน <?= htmlspecialchars($mock['campaign_title']) ?> มีวัตถุประสงค์เพื่อสร้างภูมิคุ้มกันต่อโรค ลดความรุนแรงของอาการป่วย แต่ไม่อาจรับรองได้ว่าจะป้องกันการติดเชื้อได้ทั้งหมด</p>
        <p><strong>2. อาการข้างเคียงที่อาจเกิดขึ้น</strong> ได้แก่ ปวดบริเวณที่ฉีด, มีไข้ต่ำ, ปวดเมื่อยกล้ามเนื้อ, อ่อนเพลีย ซึ่งมักหายภายใน 1-3 วัน ในรายที่พบน้อยอาจเกิดอาการแพ้รุนแรงต้องได้รับการรักษาทันที</p>
        <p><strong>3. ข้าพเจ้าได้รับโอกาส</strong> สอบถามข้อสงสัยและได้รับคำตอบที่เข้าใจชัดเจนจากเจ้าหน้าที่ก่อนตัดสินใจ</p>
        <p><strong>4. ข้าพเจ้ายินยอม</strong> ให้ข้อมูลส่วนบุคคลและประวัติสุขภาพที่เกี่ยวข้องถูกบันทึกในระบบเวชระเบียนของคลินิก เพื่อใช้ในการดูแลรักษาและการรายงานตามกฎหมาย ภายใต้ พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562</p>
        <p><strong>5. หากเกิดอาการผิดปกติหลังฉีด</strong> ข้าพเจ้าจะติดต่อคลินิกทันทีที่หมายเลข 02-XXX-XXXX หรือเดินทางมาที่คลินิกในเวลาทำการ</p>
      </div>

      <div class="decision-grid">
        <label class="decision-card consent">
          <input type="radio" name="decision" value="consent" onchange="updateDecision()">
          <div class="dc-ic"><i class="fa-solid fa-check"></i></div>
          <div class="dc-title">ยินยอมรับวัคซีน</div>
          <div class="dc-desc">ข้าพเจ้าอ่านและเข้าใจครบถ้วน ยินยอมเข้ารับการฉีด</div>
        </label>
        <label class="decision-card decline">
          <input type="radio" name="decision" value="decline" onchange="updateDecision()">
          <div class="dc-ic"><i class="fa-solid fa-xmark"></i></div>
          <div class="dc-title">ไม่ยินยอม</div>
          <div class="dc-desc">ข้าพเจ้าขอปฏิเสธการฉีดวัคซีนในครั้งนี้</div>
        </label>
      </div>

      <div id="declineReasonBox">
        <div class="field">
          <label>เหตุผลที่ปฏิเสธ <span class="req">*</span></label>
          <textarea rows="2" placeholder="เช่น ต้องการปรึกษาแพทย์ส่วนตัวก่อน, ขอเลื่อนนัด"></textarea>
        </div>
      </div>
    </div>
  </div>

  <!-- ============= STEP 4 : SIGNATURE ============= -->
  <div class="step-pane" data-step-pane="4">
    <div class="card">
      <div class="card-title">
        <div class="ic" style="background:#fce7f3;color:#be185d;border-color:#f9a8d4;"><i class="fa-solid fa-signature"></i></div>
        <div>
          <h2>ลงลายมือชื่อยืนยัน</h2>
          <div class="desc">เซ็นในกรอบด้านล่างด้วยปลายนิ้วหรือปากกาทัชสกรีน</div>
        </div>
      </div>

      <?php if ($view === 'tablet'): ?>
      <div class="witness-card">
        <div class="w-ic"><i class="fa-solid fa-tablet-screen-button"></i></div>
        <div>
          <h3>โหมดแท็บเล็ตคลินิก</h3>
          <p>ส่งแท็บเล็ตให้คนไข้เซ็น เมื่อเซ็นเสร็จกด "บันทึกลายเซ็น" แล้วเจ้าหน้าที่เซ็นพยานต่อ</p>
        </div>
      </div>
      <?php endif; ?>

      <div class="sig-wrap" id="sigWrap">
        <canvas id="sigCanvas" class="sig-canvas" aria-label="พื้นที่สำหรับเซ็นชื่อ"></canvas>
        <div class="sig-x">✕</div>
        <div class="sig-line"></div>
        <div class="sig-placeholder" id="sigPlaceholder">
          <i class="fa-solid fa-pen-fancy"></i>
          <div class="t">เซ็นชื่อตรงนี้</div>
          <div class="h">ใช้ปลายนิ้วหรือปากกาทัชสกรีน</div>
        </div>
        <div class="sig-toolbar">
          <button type="button" class="sig-tool-btn" onclick="sigUndo()">
            <i class="fa-solid fa-rotate-left"></i> ย้อนเส้น
          </button>
          <button type="button" class="sig-tool-btn" onclick="sigClear()">
            <i class="fa-solid fa-eraser"></i> เริ่มใหม่
          </button>
          <div class="sig-stats" id="sigStats"><span class="dot"></span>ยังไม่มีลายเซ็น</div>
        </div>
      </div>

      <div class="field" style="margin-top:18px;">
        <label>ผู้เซ็นชื่อ</label>
        <input type="text" value="<?= htmlspecialchars($mock['patient_name']) ?> (ตนเอง)" readonly style="background:#f8fafc;">
        <div class="field-hint">ลายเซ็นนี้จะถูกเข้ารหัสและบันทึกพร้อม timestamp + IP เพื่อเป็นหลักฐาน</div>
      </div>

      <?php if ($view === 'tablet'): ?>
      <div style="margin-top:18px; padding-top:18px; border-top:1px dashed #e2e8f0;">
        <div class="card-title" style="margin-bottom:14px;">
          <div class="ic" style="background:#eff6ff;color:#1d4ed8;border-color:#93c5fd;"><i class="fa-solid fa-user-shield"></i></div>
          <div>
            <h2 style="font-size:16px;">ลายเซ็นเจ้าหน้าที่พยาน</h2>
            <div class="desc">เจ้าหน้าที่ลงชื่อรับรองว่าเป็นการเซ็นต่อหน้า</div>
          </div>
        </div>
        <div class="sig-wrap" id="witnessWrap">
          <canvas id="witnessCanvas" class="sig-canvas" style="height:180px;" aria-label="พื้นที่เซ็นพยาน"></canvas>
          <div class="sig-line"></div>
          <div class="sig-placeholder" id="witnessPlaceholder">
            <i class="fa-solid fa-pen"></i>
            <div class="t">เจ้าหน้าที่เซ็นที่นี่</div>
          </div>
          <div class="sig-toolbar">
            <button type="button" class="sig-tool-btn" onclick="sigClear('witness')">
              <i class="fa-solid fa-eraser"></i> เริ่มใหม่
            </button>
            <div class="sig-stats" id="witnessStats" style="margin-left:auto;"><span class="dot"></span>รอเจ้าหน้าที่เซ็น</div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ============= STEP 5 : SUMMARY ============= -->
  <div class="step-pane" data-step-pane="5">

    <div class="status-banner ok" id="statusOk">
      <div class="sb-ic"><i class="fa-solid fa-circle-check"></i></div>
      <div>
        <h3>เซ็นเอกสารเรียบร้อย</h3>
        <p>ระบบจะส่งคุณไปที่จุดบริการเพื่อให้เจ้าหน้าที่วัดสัญญาณชีพและฉีดวัคซีน</p>
      </div>
    </div>

    <div class="status-banner flagged" id="statusFlagged" style="display:none;">
      <div class="sb-ic"><i class="fa-solid fa-stethoscope"></i></div>
      <div>
        <h3>ส่งให้แพทย์ประเมินก่อน</h3>
        <p>มีข้อคัดกรองที่ตอบ "ใช่" — กรุณานั่งรอเจ้าหน้าที่เรียก คุณยังไม่สามารถรับวัคซีนได้จนกว่าแพทย์อนุมัติ</p>
      </div>
    </div>

    <div class="card">
      <div class="card-title">
        <div class="ic"><i class="fa-solid fa-list-check"></i></div>
        <div><h2>สรุปการลงทะเบียน</h2></div>
      </div>

      <div class="sum-row">
        <div class="k">ชื่อ-นามสกุล</div>
        <div class="v"><?= htmlspecialchars($mock['patient_name']) ?></div>
      </div>
      <div class="sum-row">
        <div class="k">วัคซีน</div>
        <div class="v"><?= htmlspecialchars($mock['campaign_title']) ?></div>
      </div>
      <div class="sum-row">
        <div class="k">นัดหมาย</div>
        <div class="v"><?= htmlspecialchars($mock['appointment_at']) ?></div>
      </div>
      <div class="sum-row">
        <div class="k">การคัดกรอง</div>
        <div class="v" id="sumScreening">ผ่านทั้ง 7 ข้อ</div>
      </div>
      <div class="sum-row">
        <div class="k">การตัดสินใจ</div>
        <div class="v ok" id="sumDecision">ยินยอมรับวัคซีน</div>
      </div>
      <div class="sum-row">
        <div class="k">ลายเซ็น</div>
        <div class="v ok" id="sumSignature">บันทึกแล้ว · SHA256: <span id="sumHash" style="font-family:monospace;font-size:11px;color:#64748b;">—</span></div>
      </div>
      <div class="sum-row">
        <div class="k">เวลาที่เซ็น</div>
        <div class="v" id="sumTime">—</div>
      </div>
    </div>

    <div class="card" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7); border-color:#86efac;">
      <div style="display:flex; gap:14px; align-items:center;">
        <div style="font-size:36px;"><i class="fa-solid fa-qrcode" style="color:#15803d;"></i></div>
        <div style="flex:1;">
          <div style="font-size:14px; font-weight:800; color:#14532d;">QR ติดตามสถานะ</div>
          <div style="font-size:12px; color:#15803d; margin-top:2px;">สแกนเพื่อดูสถานะการฉีดและประวัติวัคซีน</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============= BOTTOM ACTION BAR ============= -->
<div class="actionbar">
  <div class="actionbar-inner">
    <button class="btn btn-ghost" id="btnBack" onclick="goStep(-1)">
      <i class="fa-solid fa-arrow-left"></i> ย้อนกลับ
    </button>
    <button class="btn btn-primary btn-flex" id="btnNext" onclick="goStep(1)">
      ถัดไป <i class="fa-solid fa-arrow-right"></i>
    </button>
  </div>
</div>

<script>
/* ===== Step navigation ===== */
let currentStep = 1;
const totalSteps = 5;

function goStep(delta) {
  // Validation gate
  if (delta > 0 && !canAdvance(currentStep)) return;

  const next = Math.min(totalSteps, Math.max(1, currentStep + delta));
  if (next === currentStep) return;

  document.querySelector(`[data-step-pane="${currentStep}"]`).classList.remove('is-active');
  document.querySelector(`[data-step-pane="${next}"]`).classList.add('is-active');

  // Stepper
  document.querySelectorAll('.step-dot').forEach((dot, i) => {
    const s = i + 1;
    dot.classList.remove('is-active','is-done');
    if (s < next) dot.classList.add('is-done');
    else if (s === next) dot.classList.add('is-active');
  });
  document.querySelectorAll('.step-line').forEach((line, i) => {
    line.classList.toggle('is-done', (i + 1) < next);
  });

  currentStep = next;
  updateActionBar();
  if (next === 5) renderSummary();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateActionBar() {
  document.getElementById('btnBack').style.visibility = currentStep === 1 ? 'hidden' : 'visible';
  const btn = document.getElementById('btnNext');
  if (currentStep === totalSteps) {
    btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> ส่งและปิดหน้านี้';
    btn.onclick = () => {
      // In production this would POST to api_consent_submit.php
      alert('Preview only — production จะ POST ไป api_consent_submit.php');
    };
  } else if (currentStep === 4) {
    btn.innerHTML = 'ตรวจสอบและส่ง <i class="fa-solid fa-arrow-right"></i>';
    btn.onclick = () => goStep(1);
  } else {
    btn.innerHTML = 'ถัดไป <i class="fa-solid fa-arrow-right"></i>';
    btn.onclick = () => goStep(1);
  }
}

function canAdvance(step) {
  if (step === 2) {
    // require all 7 answered
    for (let i = 1; i <= 7; i++) {
      if (!document.querySelector(`input[name="q${i}"]:checked`)) {
        alert('กรุณาตอบให้ครบทั้ง 7 ข้อ');
        return false;
      }
    }
  }
  if (step === 3) {
    if (!document.querySelector('input[name="decision"]:checked')) {
      alert('กรุณาเลือกว่ายินยอมหรือไม่ยินยอม');
      return false;
    }
  }
  if (step === 4) {
    if (!hasSignature('patient')) {
      alert('กรุณาเซ็นลายมือชื่อ');
      return false;
    }
    <?php if ($view === 'tablet'): ?>
    if (!hasSignature('witness')) {
      alert('กรุณาให้เจ้าหน้าที่เซ็นพยาน');
      return false;
    }
    <?php endif; ?>
  }
  return true;
}

/* ===== Screening Q ===== */
function updateQ(i, val) {
  const row = document.querySelector(`[data-q="${i}"]`);
  row.classList.toggle('is-yes', val === 'yes');
}

/* ===== Decision ===== */
function updateDecision() {
  /* CSS :has() handles display, nothing to do */
}

/* ===== Signature pad (vanilla, touch + mouse) ===== */
const pads = {};

function initPad(canvasId, placeholderId, statsId, key) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const placeholder = document.getElementById(placeholderId);
  const stats = document.getElementById(statsId);
  const ctx = canvas.getContext('2d');

  const dpr = window.devicePixelRatio || 1;
  function resize() {
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0f172a';
    ctx.lineWidth = 2.2;
  }
  resize();
  window.addEventListener('resize', () => {
    const data = pads[key].strokes;
    resize();
    redraw(key);
  });

  pads[key] = {
    canvas, ctx, placeholder, stats,
    drawing: false,
    strokes: [],         // array of strokes; each stroke = array of points
    current: null,
  };

  const getPos = (e) => {
    const rect = canvas.getBoundingClientRect();
    const touch = e.touches?.[0] || e.changedTouches?.[0];
    const x = (touch ? touch.clientX : e.clientX) - rect.left;
    const y = (touch ? touch.clientY : e.clientY) - rect.top;
    return { x, y };
  };

  const start = (e) => {
    e.preventDefault();
    pads[key].drawing = true;
    const p = getPos(e);
    pads[key].current = [p];
    placeholder.classList.add('is-hidden');
    document.getElementById(canvas.parentElement.id || '').classList?.add('has-ink');
    canvas.parentElement.classList.add('has-ink');
  };
  const move = (e) => {
    if (!pads[key].drawing) return;
    e.preventDefault();
    const p = getPos(e);
    const c = pads[key].current;
    const prev = c[c.length - 1];
    ctx.beginPath();
    ctx.moveTo(prev.x, prev.y);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    c.push(p);
  };
  const end = (e) => {
    if (!pads[key].drawing) return;
    pads[key].drawing = false;
    if (pads[key].current.length > 1) {
      pads[key].strokes.push(pads[key].current);
    }
    pads[key].current = null;
    updateStats(key);
  };

  canvas.addEventListener('mousedown', start);
  canvas.addEventListener('mousemove', move);
  canvas.addEventListener('mouseup', end);
  canvas.addEventListener('mouseleave', end);
  canvas.addEventListener('touchstart', start, { passive: false });
  canvas.addEventListener('touchmove', move,  { passive: false });
  canvas.addEventListener('touchend',  end);
}

function redraw(key) {
  const p = pads[key];
  p.ctx.clearRect(0, 0, p.canvas.width, p.canvas.height);
  p.strokes.forEach(stroke => {
    if (stroke.length < 2) return;
    p.ctx.beginPath();
    p.ctx.moveTo(stroke[0].x, stroke[0].y);
    for (let i = 1; i < stroke.length; i++) {
      p.ctx.lineTo(stroke[i].x, stroke[i].y);
    }
    p.ctx.stroke();
  });
}

function updateStats(key) {
  const p = pads[key];
  const count = p.strokes.length;
  if (count > 0) {
    p.canvas.parentElement.classList.add('has-ink');
    p.placeholder.classList.add('is-hidden');
    p.stats.innerHTML = `<span class="dot"></span> เซ็นแล้ว · ${count} เส้น`;
  } else {
    p.canvas.parentElement.classList.remove('has-ink');
    p.placeholder.classList.remove('is-hidden');
    p.stats.innerHTML = `<span class="dot"></span>${key === 'witness' ? 'รอเจ้าหน้าที่เซ็น' : 'ยังไม่มีลายเซ็น'}`;
  }
}

function sigClear(key) {
  key = key || 'patient';
  const p = pads[key];
  if (!p) return;
  p.strokes = [];
  p.ctx.clearRect(0, 0, p.canvas.width, p.canvas.height);
  updateStats(key);
}

function sigUndo() {
  const p = pads.patient;
  if (!p || p.strokes.length === 0) return;
  p.strokes.pop();
  redraw('patient');
  updateStats('patient');
}

function hasSignature(key) {
  return pads[key] && pads[key].strokes.length > 0;
}

/* ===== Summary rendering ===== */
function renderSummary() {
  // Count flagged Qs
  const flagged = [];
  for (let i = 1; i <= 7; i++) {
    const v = document.querySelector(`input[name="q${i}"]:checked`)?.value;
    if (v === '1') flagged.push(i);
  }
  const decision = document.querySelector('input[name="decision"]:checked')?.value;
  const sumScreen = document.getElementById('sumScreening');
  const sumDec = document.getElementById('sumDecision');
  const banner = document.getElementById('statusOk');
  const flaggedBanner = document.getElementById('statusFlagged');

  if (flagged.length === 0) {
    sumScreen.textContent = 'ผ่านทั้ง 7 ข้อ';
    sumScreen.className = 'v ok';
  } else {
    sumScreen.innerHTML = `ตอบ "ใช่" ${flagged.length} ข้อ (Q${flagged.join(', Q')})`;
    sumScreen.className = 'v flag';
  }

  if (decision === 'consent') {
    sumDec.textContent = 'ยินยอมรับวัคซีน';
    sumDec.className = 'v ok';
  } else if (decision === 'decline') {
    sumDec.textContent = 'ปฏิเสธการรับวัคซีน';
    sumDec.className = 'v bad';
  }

  // Show appropriate banner
  if (decision === 'consent' && flagged.length === 0) {
    banner.style.display = 'flex';
    flaggedBanner.style.display = 'none';
  } else if (decision === 'consent' && flagged.length > 0) {
    banner.style.display = 'none';
    flaggedBanner.style.display = 'flex';
  } else {
    banner.style.display = 'none';
    flaggedBanner.style.display = 'none';
  }

  // Fake hash (real: SHA256 of canonical payload)
  const fakeHash = Array.from({length:16}, () =>
    Math.floor(Math.random()*16).toString(16)
  ).join('') + '…';
  document.getElementById('sumHash').textContent = fakeHash;
  document.getElementById('sumTime').textContent =
    new Date().toLocaleString('th-TH', { dateStyle:'long', timeStyle:'medium' });
}

/* ===== Init ===== */
window.addEventListener('DOMContentLoaded', () => {
  initPad('sigCanvas', 'sigPlaceholder', 'sigStats', 'patient');
  <?php if ($view === 'tablet'): ?>
  initPad('witnessCanvas', 'witnessPlaceholder', 'witnessStats', 'witness');
  <?php endif; ?>
  updateActionBar();
});
</script>
</body>
</html>
