<?php
// admin/campaign_report.php — Print/PDF-ready summary report per campaign.
// เปิดผ่าน sidebar "รายงาน > สรุปรายแคมเปญ" หรือลิงก์ตรง ?id=<campaign_id>
//
// ✦ Layout : A4 print-friendly, รองรับ window.print() และ html2pdf.js
// ✦ Data   : campaign info + KPI + status breakdown + daily trend
//            + slot-by-slot utilization + รายชื่อผู้เข้าร่วม + survey avg
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$pdo = db();

$campaignId  = (int)($_GET['id'] ?? $_GET['campaign_id'] ?? 0);
$printMode   = isset($_GET['print']) && $_GET['print'] === '1';

// dropdown ของแคมเปญทั้งหมด
try {
    $allCampaigns = $pdo->query("SELECT id, title, status FROM camp_list ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {
    $allCampaigns = [];
}

// ─────────────────────────────────────────────────────────────
// ดึงข้อมูล (เมื่อเลือกแคมเปญแล้วเท่านั้น)
// ─────────────────────────────────────────────────────────────
$campaign = null;
$kpi = [
    'capacity'         => 0,
    'used'             => 0,
    'remaining'        => 0,
    'fill_pct'         => 0,
    'total_bookings'   => 0,
    'attended'         => 0,
    'absent'           => 0,
    'upcoming'         => 0,
    'cancelled'        => 0,
    'cancelled_admin'  => 0,
    'attendance_pct'   => 0,
    'cancel_pct'       => 0,
    'total_slots'      => 0,
];
$statusBreakdown = [];
$dailyTrend      = [];
$slotUtil        = [];
$participants    = [];
$surveyStats     = ['count' => 0, 'avg_rating' => null];

if ($campaignId > 0) {
    try {
        // used_capacity here = all non-cancelled bookings (booked + confirmed + completed).
        // This intentionally differs from campaigns.php / time_slots.php / ajax helpers
        // which use ('booked','confirmed') for slot-pipeline checks. In a SUMMARY REPORT
        // the user expects "จองแล้ว" to count everyone who actually booked (including
        // those who already attended), not just the active pipeline.
        $stmt = $pdo->prepare("
            SELECT c.*,
                (SELECT COUNT(*) FROM camp_slots    s WHERE s.campaign_id = c.id) AS total_slots,
                (SELECT COUNT(*) FROM camp_bookings b WHERE b.campaign_id = c.id) AS total_bookings,
                (SELECT COUNT(*) FROM camp_bookings b WHERE b.campaign_id = c.id
                   AND b.status NOT IN ('cancelled','cancelled_by_admin')) AS used_capacity
            FROM camp_list c WHERE c.id = :id
        ");
        $stmt->execute([':id' => $campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException) {
        $campaign = null;
    }
}

if ($campaign) {
    $today = date('Y-m-d');
    $kpi['capacity']    = (int)$campaign['total_capacity'];
    $kpi['used']        = (int)$campaign['used_capacity'];
    $kpi['remaining']   = max(0, $kpi['capacity'] - $kpi['used']);
    $kpi['fill_pct']    = $kpi['capacity'] > 0 ? round($kpi['used'] / $kpi['capacity'] * 100, 1) : 0;
    $kpi['total_bookings'] = (int)$campaign['total_bookings'];
    $kpi['total_slots']    = (int)$campaign['total_slots'];

    // status breakdown
    try {
        $st = $pdo->prepare("SELECT status, COUNT(*) c FROM camp_bookings WHERE campaign_id = :id GROUP BY status");
        $st->execute([':id' => $campaignId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusBreakdown[$row['status']] = (int)$row['c'];
        }
    } catch (PDOException) {}

    $kpi['cancelled']       = $statusBreakdown['cancelled'] ?? 0;
    $kpi['cancelled_admin'] = $statusBreakdown['cancelled_by_admin'] ?? 0;

    // daily booking trend (created_at)
    try {
        $st = $pdo->prepare("
            SELECT DATE(created_at) d, COUNT(*) c
            FROM camp_bookings
            WHERE campaign_id = :id AND created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
            GROUP BY DATE(created_at)
            ORDER BY d ASC
        ");
        $st->execute([':id' => $campaignId]);
        $dailyTrend = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) {}

    // slot utilization
    try {
        $st = $pdo->prepare("
            SELECT s.id, s.slot_date, s.start_time, s.end_time, s.max_capacity,
                COUNT(CASE WHEN b.status IN ('booked','confirmed') THEN 1 END) AS booked,
                COUNT(CASE WHEN b.attended_at IS NOT NULL THEN 1 END) AS attended
            FROM camp_slots s
            LEFT JOIN camp_bookings b ON b.slot_id = s.id
            WHERE s.campaign_id = :id
            GROUP BY s.id
            ORDER BY s.slot_date ASC, s.start_time ASC
        ");
        $st->execute([':id' => $campaignId]);
        $slotUtil = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) {}

    // participants — full list for print
    try {
        $st = $pdo->prepare("
            SELECT b.id, b.status, b.attended_at, b.created_at,
                u.full_name, u.student_personnel_id, u.phone_number,
                s.slot_date, s.start_time, s.end_time
            FROM camp_bookings b
            JOIN sys_users u ON u.id = b.student_id
            JOIN camp_slots s ON s.id = b.slot_id
            WHERE b.campaign_id = :id
            ORDER BY s.slot_date ASC, s.start_time ASC, u.full_name ASC
        ");
        $st->execute([':id' => $campaignId]);
        $participants = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) {}

    // compute attended/absent/upcoming from participants
    foreach ($participants as $p) {
        if ($p['status'] === 'cancelled' || $p['status'] === 'cancelled_by_admin') continue;
        if (!empty($p['attended_at']))           $kpi['attended']++;
        elseif ($p['slot_date'] < $today)        $kpi['absent']++;
        else                                     $kpi['upcoming']++;
    }
    $totalNonCancel = $kpi['attended'] + $kpi['absent'] + $kpi['upcoming'];
    $pastTotal      = $kpi['attended'] + $kpi['absent']; // เฉพาะที่ผ่านวันงานแล้ว
    $kpi['attendance_pct'] = $pastTotal > 0 ? round($kpi['attended'] / $pastTotal * 100, 1) : 0;
    $kpi['cancel_pct']     = $kpi['total_bookings'] > 0
        ? round(($kpi['cancelled'] + $kpi['cancelled_admin']) / $kpi['total_bookings'] * 100, 1)
        : 0;

    // survey avg (post_checkin)
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) c, AVG(s.rating) avg_r
            FROM satisfaction_surveys s
            JOIN camp_bookings b ON b.id = s.booking_id
            WHERE b.campaign_id = :id AND s.survey_type = 'post_checkin'
        ");
        $st->execute([':id' => $campaignId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $surveyStats['count']      = (int)($r['c'] ?? 0);
        $surveyStats['avg_rating'] = $r['avg_r'] !== null ? round((float)$r['avg_r'], 2) : null;
    } catch (PDOException) {}
}

// ── Aggregate slot utilization by day (รายงานสรุปย่อยุบจากรายรอบ → รายวัน) ──
$dailySlotAgg = [];
foreach ($slotUtil as $sl) {
    $d = $sl['slot_date'];
    if (!isset($dailySlotAgg[$d])) {
        $dailySlotAgg[$d] = ['date' => $d, 'slots' => 0, 'capacity' => 0, 'booked' => 0, 'attended' => 0];
    }
    $dailySlotAgg[$d]['slots']++;
    $dailySlotAgg[$d]['capacity'] += (int)$sl['max_capacity'];
    $dailySlotAgg[$d]['booked']   += (int)$sl['booked'];
    $dailySlotAgg[$d]['attended'] += (int)$sl['attended'];
}
$dailySlotAgg = array_values($dailySlotAgg);

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────
function statusLabel(string $s): string {
    return match($s) {
        'booked'             => 'รอเจ้าหน้าที่ยืนยัน',
        'confirmed'          => 'ยืนยันการจอง',
        'cancelled'          => 'ผู้ใช้ยกเลิกเอง',
        'cancelled_by_admin' => 'เจ้าหน้าที่ยกเลิก',
        default              => $s,
    };
}
function campaignTypeLabel(?string $t): string {
    return match($t) {
        'vaccine'      => 'ฉีดวัคซีน',
        'training'     => 'อบรม/สัมมนา',
        'health_check' => 'ตรวจสุขภาพ',
        default        => 'กิจกรรมอื่นๆ',
    };
}
function campaignStatusLabel(?string $s): string {
    return match($s) {
        'active'      => 'เปิดรับสมัคร',
        'full'        => 'เต็มแล้ว',
        'closed'      => 'ปิดรับสมัคร',
        'inactive'    => 'หยุดชั่วคราว',
        'archived'    => 'เก็บถาวร',
        'draft'       => 'ฉบับร่าง',
        'coming_soon' => 'เร็วๆ นี้',
        'private'     => 'ลิงก์ส่วนตัว',
        default       => $s ?? '—',
    };
}
function thDate(?string $d): string {
    if (!$d) return '—';
    $ts = strtotime($d);
    if (!$ts) return '—';
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' ' . (date('Y', $ts) + 543);
}
function thDateTime(?string $d): string {
    if (!$d) return '—';
    $ts = strtotime($d);
    if (!$ts) return '—';
    return thDate($d) . ' ' . date('H:i', $ts) . ' น.';
}
function calcStatus(array $p, string $today): string {
    if ($p['status'] === 'cancelled' || $p['status'] === 'cancelled_by_admin') return 'cancelled';
    if (!empty($p['attended_at']))    return 'attended';
    if ($p['slot_date'] < $today)     return 'absent';
    return 'upcoming';
}

// ─────────────────────────────────────────────────────────────
// CSV Export (full participant list, full campaign metadata)
// ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $campaign) {
    $today = date('Y-m-d');
    $fname = sprintf('campaign_report_%d_%s.csv', $campaignId, date('Ymd'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    // Summary block
    fputcsv($out, ['สรุปรายงานแคมเปญ']);
    fputcsv($out, ['ชื่อแคมเปญ', $campaign['title']]);
    fputcsv($out, ['ประเภท', campaignTypeLabel($campaign['type'] ?? null)]);
    fputcsv($out, ['ช่วงเวลาเปิดจอง', thDate($campaign['available_from'] ?? null) . ' — ' . thDate($campaign['available_until'] ?? null)]);
    fputcsv($out, ['รับได้ทั้งหมด', $kpi['capacity']]);
    fputcsv($out, ['จองแล้ว', $kpi['used'] . ' (' . $kpi['fill_pct'] . '%)']);
    fputcsv($out, ['มาตามนัด', $kpi['attended']]);
    fputcsv($out, ['ไม่มาตามนัด', $kpi['absent']]);
    fputcsv($out, ['อัตราการมา', $kpi['attendance_pct'] . '%']);
    fputcsv($out, ['ผู้ใช้ยกเลิกเอง', $kpi['cancelled']]);
    fputcsv($out, ['เจ้าหน้าที่ยกเลิก', $kpi['cancelled_admin']]);
    fputcsv($out, ['คะแนนความพึงพอใจเฉลี่ย', $surveyStats['avg_rating'] !== null ? $surveyStats['avg_rating'] . ' / 5 (จากผู้ตอบ ' . $surveyStats['count'] . ' คน)' : 'ยังไม่มีผู้ตอบ']);
    fputcsv($out, []);

    fputcsv($out, ['รายชื่อผู้ลงทะเบียน']);
    fputcsv($out, ['ลำดับ','รหัส','ชื่อ-นามสกุล','เบอร์โทร','วันที่จัดงาน','รอบเวลา','สถานะ','เวลาเช็คอิน','วันที่จอง']);
    $i = 0;
    foreach ($participants as $p) {
        $i++;
        $cs   = calcStatus($p, $today);
        $stat = match($cs) {
            'attended'  => 'มาตามนัด',
            'absent'    => 'ไม่มา',
            'upcoming'  => 'รอวันงาน',
            'cancelled' => statusLabel($p['status']),
        };
        fputcsv($out, [
            $i,
            $p['student_personnel_id'] ?? '',
            $p['full_name'],
            $p['phone_number'] ?? '',
            thDate($p['slot_date']),
            substr($p['start_time'], 0, 5) . '–' . substr($p['end_time'], 0, 5),
            $stat,
            $p['attended_at'] ? thDateTime($p['attended_at']) : '—',
            thDateTime($p['created_at']),
        ]);
    }
    fclose($out);
    exit;
}

// header — เฉพาะตอนไม่ใช่ print mode (print mode = standalone document)
if (!$printMode) {
    require_once __DIR__ . '/includes/header.php';
}
?>
<?php if ($printMode): ?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>รายงานสรุปแคมเปญ — <?= $campaign ? htmlspecialchars($campaign['title']) : 'ไม่พบแคมเปญ' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<?php endif; ?>

<style>
/* ── Action toolbar (screen only) ───────────────────────────── */
.cr-toolbar {
    background:#fff; padding:14px 18px; border-radius:14px;
    box-shadow:0 1px 4px rgba(0,0,0,.06); border:1px solid #e2e8f0;
    display:flex; gap:10px; align-items:center; flex-wrap:wrap;
    margin-bottom:20px;
}
.cr-toolbar select { flex:1; min-width:240px; padding:10px 14px; border:1.5px solid #e2e8f0; border-radius:10px; background:#fff; font-size:14px; }
.cr-btn { display:inline-flex; align-items:center; gap:6px; padding:10px 16px; border-radius:10px; font-weight:700; font-size:13px; border:none; cursor:pointer; transition:all .2s; text-decoration:none; }
.cr-btn-print { background:linear-gradient(135deg, #2e9e63, #3bba7a); color:#fff; box-shadow:0 4px 12px rgba(46,158,99,.3); }
.cr-btn-print:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(46,158,99,.4); }
.cr-btn-pdf { background:#dc2626; color:#fff; box-shadow:0 4px 12px rgba(220,38,38,.25); }
.cr-btn-pdf:hover { transform:translateY(-1px); }
.cr-btn-csv { background:#0f7349; color:#fff; }
.cr-btn-csv:hover { background:#0c5e3b; }
.cr-btn-ghost { background:#f1f5f9; color:#475569; }
.cr-btn-ghost:hover { background:#e2e8f0; }

/* ── Document (A4) ───────────────────────────────────────────── */
.cr-doc {
    background:#fff;
    max-width: 820px;
    margin: 0 auto;
    padding: 36px 44px 44px 44px;
    border-radius: 6px;
    box-shadow: 0 8px 28px rgba(0,0,0,.08);
    color:#1f2937;
    font-family: 'Sarabun', 'TH Sarabun New', sans-serif;
    font-size: 13pt;
    line-height: 1.55;
}
.cr-doc-empty {
    background:#fff; max-width:820px; margin:0 auto; padding:56px 32px;
    border-radius:6px; box-shadow:0 8px 28px rgba(0,0,0,.08);
    text-align:center; color:#94a3b8;
}
.cr-doc-empty i { font-size:48px; color:#cbd5e1; margin-bottom:14px; display:block; }

/* Header band */
.cr-band {
    background: linear-gradient(135deg, #2e9e63 0%, #3bba7a 100%);
    color:#fff;
    padding: 22px 26px;
    border-radius: 12px;
    margin-bottom: 22px;
    position:relative;
    overflow:hidden;
}
.cr-band::before {
    content:''; position:absolute; right:-20px; top:-20px;
    width:160px; height:160px; border-radius:50%;
    background: rgba(255,255,255,.08);
}
.cr-band-eyebrow { font-size:11pt; font-weight:800; letter-spacing:.18em; text-transform:uppercase; opacity:.85; }
.cr-band h1 { margin:6px 0 4px; font-size:22pt; font-weight:800; line-height:1.25; letter-spacing:-.01em; }
.cr-band .cr-band-meta { font-size:11pt; opacity:.9; display:flex; gap:14px; flex-wrap:wrap; align-items:center; margin-top:6px; }
.cr-band .cr-pill {
    background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.25);
    padding:3px 10px; border-radius:999px; font-size:10pt; font-weight:700;
}

/* Section title */
.cr-h {
    display:flex; align-items:center; gap:10px;
    margin: 22px 0 12px 0; font-size:13pt; font-weight:800; color:#0f172a;
    border-left:4px solid #2e9e63; padding-left:10px;
}

/* KPI grid */
.cr-kpi {
    display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; margin-bottom:8px;
}
.cr-kpi-tile {
    border:1.5px solid #e5e7eb; border-radius:12px; padding:12px 14px; background:#fafafa;
    display:flex; flex-direction:column; gap:4px;
}
.cr-kpi-tile.is-brand   { background:linear-gradient(180deg,#ecfdf5,#fff); border-color:#bbf7d0; }
.cr-kpi-tile.is-info    { background:linear-gradient(180deg,#eff6ff,#fff); border-color:#bfdbfe; }
.cr-kpi-tile.is-danger  { background:linear-gradient(180deg,#fef2f2,#fff); border-color:#fecaca; }
.cr-kpi-tile.is-amber   { background:linear-gradient(180deg,#fffbeb,#fff); border-color:#fde68a; }
.cr-kpi-tile.is-violet  { background:linear-gradient(180deg,#faf5ff,#fff); border-color:#e9d5ff; }
.cr-kpi-tile.is-slate   { background:linear-gradient(180deg,#f8fafc,#fff); border-color:#cbd5e1; }
.cr-kpi-label { font-size:9.5pt; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; }
.cr-kpi-value { font-size:18pt; font-weight:800; color:#0f172a; line-height:1.1; }
.cr-kpi-sub   { font-size:9pt; color:#64748b; font-weight:600; }

/* Two-column row */
.cr-row2 { display:grid; grid-template-columns: 1.4fr 1fr; gap:14px; margin-bottom:8px; }

/* Status bars */
.cr-bar-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.cr-bar-label { width:135px; font-size:10.5pt; font-weight:700; color:#334155; }
.cr-bar-track { flex:1; height:18px; background:#f1f5f9; border-radius:8px; overflow:hidden; position:relative; }
.cr-bar-fill { height:100%; border-radius:8px; transition:width .4s; display:flex; align-items:center; justify-content:flex-end; padding-right:8px; color:#fff; font-size:9pt; font-weight:700; }
.cr-bar-count { width:80px; text-align:right; font-size:10.5pt; font-weight:700; color:#0f172a; }

/* Donut (inline SVG) */
.cr-donut { width:170px; height:170px; margin:0 auto; }

/* Tables */
.cr-table { width:100%; border-collapse:collapse; font-size:10.5pt; margin-bottom:10px; }
.cr-table th { background:#f1f5f9; padding:8px 10px; text-align:left; font-weight:700; color:#334155; border-bottom:2px solid #cbd5e1; }
.cr-table td { padding:7px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.cr-table tr:nth-child(even) td { background:#fafbfc; }
.cr-table tr:hover td { background:#f0fdf4; }
.cr-status-pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:9pt; font-weight:700; }
.cr-status-attended  { background:#dcfce7; color:#15803d; }
.cr-status-absent    { background:#fee2e2; color:#b91c1c; }
.cr-status-upcoming  { background:#fef3c7; color:#a16207; }
.cr-status-cancelled { background:#f1f5f9; color:#64748b; }

/* Sparkline-style trend bars */
.cr-trend { display:flex; align-items:flex-end; gap:3px; height:110px; padding:6px 0; border-bottom:1.5px solid #e5e7eb; }
.cr-trend-bar { flex:1; min-width:6px; background:linear-gradient(180deg,#3bba7a,#2e9e63); border-radius:3px 3px 0 0; position:relative; opacity:.88; }
.cr-trend-bar:hover { opacity:1; }
.cr-trend-bar[data-zero="1"] { background:#f1f5f9; }
.cr-trend-labels { display:flex; justify-content:space-between; font-size:8.5pt; color:#94a3b8; margin-top:4px; }

/* Footer */
.cr-footer {
    margin-top:28px; padding-top:14px; border-top:1.5px dashed #cbd5e1;
    display:flex; justify-content:space-between; font-size:9.5pt; color:#64748b;
    flex-wrap:wrap; gap:8px;
}

/* ── Print ───────────────────────────────────────────────────── */
@page { size: A4; margin: 14mm 12mm 16mm 12mm; }
@media print {
    body { background:#fff !important; }
    .cr-toolbar, .admin-sidebar, .admin-topbar, .header, header, aside, nav,
    .no-print, .navbar, .breadcrumb { display:none !important; }
    .cr-doc {
        box-shadow:none !important; max-width:none !important;
        margin:0 !important; padding:0 !important; border-radius:0 !important;
    }
    .cr-band { border-radius:0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .cr-kpi-tile, .cr-status-pill, .cr-bar-fill, .cr-trend-bar {
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .cr-h, .cr-kpi { page-break-inside: avoid; }
    .cr-table { page-break-inside: auto; }
    .cr-table tr { page-break-inside: avoid; page-break-after: auto; }
    thead { display: table-header-group; }
}

@media (max-width: 720px) {
    .cr-kpi { grid-template-columns: repeat(2, 1fr); }
    .cr-row2 { grid-template-columns: 1fr; }
    .cr-doc { padding: 22px 18px; }
    .cr-band h1 { font-size:18pt; }
}
</style>

<?php if ($printMode): ?>
</head>
<body style="background:#f1f5f9;margin:0;padding:14px;">
<?php endif; ?>

<?php if (!$printMode): ?>
<?php renderPageHeader(
    '<i class="fa-solid fa-file-invoice text-[#0052CC]"></i> สรุปรายงานแคมเปญ',
    'Per-Campaign Report (Print/PDF)'
); ?>
<?php endif; ?>

<!-- Toolbar (hidden in print) -->
<div class="cr-toolbar no-print">
    <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;flex:1;min-width:240px;">
        <label class="text-sm font-bold text-gray-600" style="white-space:nowrap;">เลือกแคมเปญ:</label>
        <select name="id" onchange="this.form.submit()">
            <option value="">— เลือกแคมเปญ —</option>
            <?php foreach ($allCampaigns as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $campaignId ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['title']) ?><?= $c['status'] !== 'active' ? ' [' . htmlspecialchars($c['status']) . ']' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($campaign): ?>
    <button type="button" onclick="window.print()" class="cr-btn cr-btn-print" title="พิมพ์ / Save as PDF (Browser)">
        <i class="fa-solid fa-print"></i> พิมพ์รายงาน
    </button>
    <button type="button" onclick="crSavePdf()" class="cr-btn cr-btn-pdf" title="ดาวน์โหลด PDF">
        <i class="fa-solid fa-file-pdf"></i> ดาวน์โหลด PDF
    </button>
    <a href="?id=<?= (int)$campaignId ?>&export=csv" class="cr-btn cr-btn-csv">
        <i class="fa-solid fa-file-csv"></i> Excel/CSV
    </a>
    <?php endif; ?>
</div>

<?php if (!$campaignId): ?>
<div class="cr-doc-empty">
    <i class="fa-solid fa-folder-open"></i>
    <p style="font-size:15pt;font-weight:700;color:#64748b;">เลือกแคมเปญด้านบนเพื่อสร้างรายงาน</p>
    <p style="font-size:11pt;margin-top:6px;">รายงานนี้ออกแบบสำหรับพิมพ์ลง A4 หรือ Save เป็น PDF</p>
</div>
<?php elseif (!$campaign): ?>
<div class="cr-doc-empty">
    <i class="fa-solid fa-triangle-exclamation" style="color:#f87171;"></i>
    <p style="font-size:15pt;font-weight:700;color:#dc2626;">ไม่พบแคมเปญที่เลือก</p>
</div>
<?php else: ?>

<?php
// Pre-compute donut paths
$donutTotal = array_sum($statusBreakdown);
$donutSegments = [];
if ($donutTotal > 0) {
    $colors = [
        'confirmed'          => '#22c55e',
        'booked'             => '#f59e0b',
        'cancelled'          => '#ef4444',
        'cancelled_by_admin' => '#94a3b8',
    ];
    $cumPct = 0;
    foreach ($statusBreakdown as $st => $cnt) {
        $pct = $cnt / $donutTotal;
        $donutSegments[] = [
            'status' => $st,
            'count'  => $cnt,
            'pct'    => $pct,
            'offset' => $cumPct,
            'color'  => $colors[$st] ?? '#cbd5e1',
        ];
        $cumPct += $pct;
    }
}

// Daily trend max for bar scaling
$trendMax = 0;
foreach ($dailyTrend as $d) { $trendMax = max($trendMax, (int)$d['c']); }
$trendMax = max($trendMax, 1);

$today = date('Y-m-d');
?>

<div id="crDoc" class="cr-doc">
    <!-- Header band -->
    <div class="cr-band">
        <div class="cr-band-eyebrow">RSU Medical Clinic · e-Campaign Report</div>
        <h1><?= htmlspecialchars($campaign['title']) ?></h1>
        <div class="cr-band-meta">
            <span class="cr-pill"><i class="fa-solid fa-tag"></i> <?= htmlspecialchars(campaignTypeLabel($campaign['type'] ?? null)) ?></span>
            <span class="cr-pill"><i class="fa-regular fa-calendar"></i> <?= thDate($campaign['available_from'] ?? null) ?> — <?= thDate($campaign['available_until'] ?? null) ?></span>
            <span class="cr-pill"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(campaignStatusLabel($campaign['status'] ?? null)) ?></span>
            <?php if (!empty($campaign['location'])): ?>
            <span class="cr-pill"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($campaign['location']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI tiles -->
    <div class="cr-h"><i class="fa-solid fa-chart-pie text-[#2e9e63]"></i> สรุปตัวเลขสำคัญ</div>
    <div class="cr-kpi">
        <div class="cr-kpi-tile is-brand">
            <div class="cr-kpi-label">รับได้ทั้งหมด</div>
            <div class="cr-kpi-value"><?= number_format($kpi['capacity']) ?></div>
            <div class="cr-kpi-sub"><?= number_format($kpi['total_slots']) ?> รอบ</div>
        </div>
        <div class="cr-kpi-tile is-info">
            <div class="cr-kpi-label">จองแล้ว</div>
            <div class="cr-kpi-value"><?= number_format($kpi['used']) ?></div>
            <div class="cr-kpi-sub">คิดเป็น <?= $kpi['fill_pct'] ?>% · เหลืออีก <?= number_format($kpi['remaining']) ?> ที่</div>
        </div>
        <div class="cr-kpi-tile is-brand">
            <div class="cr-kpi-label">มาตามนัด</div>
            <div class="cr-kpi-value"><?= number_format($kpi['attended']) ?></div>
            <div class="cr-kpi-sub">มาแล้ว <?= $kpi['attendance_pct'] ?>% ของที่จอง</div>
        </div>
        <div class="cr-kpi-tile is-danger">
            <div class="cr-kpi-label">ไม่มาตามนัด</div>
            <div class="cr-kpi-value"><?= number_format($kpi['absent']) ?></div>
            <div class="cr-kpi-sub">ผ่านวันงานแล้ว</div>
        </div>
        <div class="cr-kpi-tile is-amber">
            <div class="cr-kpi-label">รอวันงาน</div>
            <div class="cr-kpi-value"><?= number_format($kpi['upcoming']) ?></div>
            <div class="cr-kpi-sub">ยังไม่ถึงวันนัด</div>
        </div>
        <div class="cr-kpi-tile is-slate">
            <div class="cr-kpi-label">ยกเลิกแล้ว</div>
            <div class="cr-kpi-value"><?= number_format($kpi['cancelled'] + $kpi['cancelled_admin']) ?></div>
            <div class="cr-kpi-sub">ผู้ใช้ <?= number_format($kpi['cancelled']) ?> · เจ้าหน้าที่ <?= number_format($kpi['cancelled_admin']) ?></div>
        </div>
        <div class="cr-kpi-tile is-info">
            <div class="cr-kpi-label">ยอดจองทั้งหมด</div>
            <div class="cr-kpi-value"><?= number_format($kpi['total_bookings']) ?></div>
            <div class="cr-kpi-sub">นับรวมที่ยกเลิกด้วย</div>
        </div>
        <div class="cr-kpi-tile is-violet">
            <div class="cr-kpi-label">คะแนนความพึงพอใจ</div>
            <div class="cr-kpi-value">
                <?= $surveyStats['avg_rating'] !== null ? $surveyStats['avg_rating'] : '—' ?>
                <?php if ($surveyStats['avg_rating'] !== null): ?><span style="font-size:11pt;color:#64748b;font-weight:600;">/5</span><?php endif; ?>
            </div>
            <div class="cr-kpi-sub">
                <?= $surveyStats['count'] > 0 ? 'จากผู้ตอบ ' . number_format($surveyStats['count']) . ' คน' : 'ยังไม่มีผู้ตอบ' ?>
            </div>
        </div>
    </div>

    <!-- Status breakdown + Donut -->
    <div class="cr-h"><i class="fa-solid fa-layer-group text-[#2e9e63]"></i> แยกตามสถานะการจอง</div>
    <div class="cr-row2">
        <div>
            <?php if ($donutTotal === 0): ?>
                <p style="color:#94a3b8;font-style:italic;">ยังไม่มีข้อมูลการจอง</p>
            <?php else: foreach (['confirmed','booked','cancelled','cancelled_by_admin'] as $st):
                $cnt = $statusBreakdown[$st] ?? 0;
                if ($cnt === 0) continue;
                $pct = round($cnt / $donutTotal * 100, 1);
                $color = match($st) {
                    'confirmed' => '#22c55e',
                    'booked'    => '#f59e0b',
                    'cancelled' => '#ef4444',
                    default     => '#94a3b8',
                };
            ?>
            <div class="cr-bar-row">
                <div class="cr-bar-label"><?= statusLabel($st) ?></div>
                <div class="cr-bar-track">
                    <div class="cr-bar-fill" style="width:<?= max(8, $pct) ?>%; background:<?= $color ?>;">
                        <?= $pct ?>%
                    </div>
                </div>
                <div class="cr-bar-count"><?= number_format($cnt) ?> คน</div>
            </div>
            <?php endforeach; endif; ?>
        </div>
        <div style="text-align:center;">
            <?php if ($donutTotal > 0): ?>
            <svg class="cr-donut" viewBox="0 0 36 36">
                <?php
                $r = 15.9155;
                foreach ($donutSegments as $seg):
                    $strokeDash = ($seg['pct'] * 100) . ' ' . ((1 - $seg['pct']) * 100);
                    $rotation   = ($seg['offset'] * 360) - 90;
                ?>
                <circle cx="18" cy="18" r="<?= $r ?>" fill="transparent"
                        stroke="<?= $seg['color'] ?>" stroke-width="5"
                        stroke-dasharray="<?= $strokeDash ?>"
                        transform="rotate(<?= $rotation ?> 18 18)"></circle>
                <?php endforeach; ?>
                <text x="18" y="18" text-anchor="middle" dy="0.1em" font-size="6" font-weight="800" fill="#0f172a"><?= number_format($donutTotal) ?></text>
                <text x="18" y="23" text-anchor="middle" font-size="2.6" fill="#64748b">รายการ</text>
            </svg>
            <?php endif; ?>
        </div>
    </div>

    <!-- Daily trend -->
    <?php if (!empty($dailyTrend)): ?>
    <div class="cr-h"><i class="fa-solid fa-chart-line text-[#2e9e63]"></i> จำนวนการจองในแต่ละวัน (60 วันล่าสุด)</div>
    <div class="cr-trend">
        <?php foreach ($dailyTrend as $d):
            $h = round((int)$d['c'] / $trendMax * 100);
            $isZero = (int)$d['c'] === 0 ? 1 : 0;
        ?>
        <div class="cr-trend-bar" style="height:<?= max(2, $h) ?>%;" data-zero="<?= $isZero ?>"
             title="<?= thDate($d['d']) ?>: <?= (int)$d['c'] ?> ราย"></div>
        <?php endforeach; ?>
    </div>
    <div class="cr-trend-labels">
        <span><?= !empty($dailyTrend) ? thDate($dailyTrend[0]['d']) : '' ?></span>
        <span style="font-weight:700;color:#475569;">วันที่จองสูงสุด: <?= $trendMax ?> ราย</span>
        <span><?= !empty($dailyTrend) ? thDate(end($dailyTrend)['d']) : '' ?></span>
    </div>
    <?php endif; ?>

    <!-- Slot utilization (รายวัน — ยุบจากรายรอบ) -->
    <?php if (!empty($dailySlotAgg)): ?>
    <div class="cr-h"><i class="fa-regular fa-calendar-check text-[#2e9e63]"></i> สรุปยอดจองแต่ละวัน</div>
    <table class="cr-table">
        <thead>
            <tr>
                <th>วันที่จัดงาน</th>
                <th style="text-align:center;">รอบเวลา</th>
                <th style="text-align:center;">รับได้</th>
                <th style="text-align:center;">มีคนจอง</th>
                <th style="text-align:center;">มาตามนัด</th>
                <th>เต็มแค่ไหน</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dailySlotAgg as $d):
                $maxCap = (int)$d['capacity'];
                $booked = (int)$d['booked'];
                $att    = (int)$d['attended'];
                $pct    = $maxCap > 0 ? round($booked / $maxCap * 100) : 0;
                $color  = $pct >= 90 ? '#dc2626' : ($pct >= 60 ? '#f59e0b' : '#22c55e');
            ?>
            <tr>
                <td><?= thDate($d['date']) ?></td>
                <td style="text-align:center;"><?= number_format((int)$d['slots']) ?> รอบ</td>
                <td style="text-align:center;"><?= number_format($maxCap) ?></td>
                <td style="text-align:center;font-weight:700;"><?= number_format($booked) ?></td>
                <td style="text-align:center;color:#15803d;font-weight:700;"><?= number_format($att) ?></td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="flex:1;height:10px;background:#f1f5f9;border-radius:5px;overflow:hidden;">
                            <div style="width:<?= min(100, $pct) ?>%;height:100%;background:<?= $color ?>;"></div>
                        </div>
                        <span style="font-size:10pt;font-weight:700;color:<?= $color ?>;width:42px;text-align:right;"><?= $pct ?>%</span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p style="font-size:9.5pt;color:#94a3b8;margin-top:-4px;">
        <i class="fa-solid fa-circle-info"></i>
        รวม <?= count($slotUtil) ?> รอบ จาก <?= count($dailySlotAgg) ?> วัน · ดูแยกแต่ละรอบได้ในไฟล์ CSV
    </p>
    <?php endif; ?>

    <!-- Participants summary callout (ยุบตารางรายชื่อ → callout ดาวน์โหลด) -->
    <?php if (!empty($participants)): ?>
    <div class="cr-h"><i class="fa-solid fa-users text-[#2e9e63]"></i> รายชื่อผู้เข้าร่วม</div>
    <div style="background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1.5px solid #bbf7d0;border-radius:14px;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
        <div style="flex:1;min-width:240px;">
            <div style="font-size:11pt;font-weight:800;color:#15803d;letter-spacing:.04em;text-transform:uppercase;margin-bottom:4px;">
                <i class="fa-solid fa-database"></i> มีผู้ลงทะเบียนรวม <?= number_format(count($participants)) ?> ราย
            </div>
            <p style="font-size:10.5pt;color:#475569;margin:0;line-height:1.5;">
                รายชื่อทั้งหมดมีจำนวนมากเกินกว่าจะใส่ในรายงานสรุปนี้
                <strong>กดดาวน์โหลด CSV</strong> เพื่อดูชื่อ-นามสกุล เบอร์โทร รอบที่จอง สถานะ และเวลาเช็คอินของทุกคนแบบเปิดใน Excel ได้
            </p>
        </div>
        <a href="?id=<?= (int)$campaignId ?>&export=csv" class="no-print"
            style="display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:12px;background:#15803d;color:#fff;font-weight:800;font-size:13px;text-decoration:none;box-shadow:0 4px 14px rgba(21,128,61,.3);white-space:nowrap;">
            <i class="fa-solid fa-file-csv"></i> ดาวน์โหลด CSV
        </a>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="cr-footer">
        <div>
            <strong>ผู้ออกรายงาน:</strong>
            <?= htmlspecialchars($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'Admin') ?>
            (<?= htmlspecialchars($_SESSION['admin_role'] ?? $_SESSION['role'] ?? '—') ?>)
        </div>
        <div>
            ออกรายงานเมื่อ <?= thDateTime(date('Y-m-d H:i:s')) ?>
        </div>
    </div>
</div>

<script>
function crSavePdf() {
    const el = document.getElementById('crDoc');
    if (!el) return;
    const t  = <?= json_encode($campaign['title']) ?>;
    const fn = 'campaign_report_' + (t || '<?= $campaignId ?>').replace(/[^a-zA-Z0-9ก-๛_-]/g, '_') + '_<?= date('Ymd') ?>.pdf';
    html2pdf().set({
        margin:       [10, 8, 10, 8],
        filename:     fn,
        image:        { type: 'jpeg', quality: 0.96 },
        html2canvas:  { scale: 2, useCORS: true, scrollY: 0 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak:    { mode: ['css', 'legacy'] }
    }).from(el).save();
}
</script>

<?php endif; // campaign exists ?>

<?php if ($printMode): ?>
</body>
</html>
<?php else: ?>
<?php
// Inject html2pdf only on screen
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>
