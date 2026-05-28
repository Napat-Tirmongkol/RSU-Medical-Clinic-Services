<?php
/**
 * portal/scholarship_timesheet.php
 * Print-ready timesheet สำหรับนักศึกษาทุน · 2 ฟอร์ม per print:
 *   - แบบฟอร์ม 1: ลงเวลา (clock_logs aggregated per day)
 *   - แบบฟอร์ม 2: รายละเอียดการปฏิบัติงาน (task_description per day)
 *
 * URL: ?student_id=N&month=YYYY-MM
 *   - month default = เดือนปัจจุบัน
 *   - rate default = settings.pay_rate_per_hour (override ?rate=)
 */
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/scholarship_helper.php';

$_role = $_SESSION['admin_role'] ?? '';
if ($_role !== 'superadmin' && empty($_SESSION['access_scholarship']) && $_role !== 'admin') {
    http_response_code(403);
    exit('Access Denied — ต้องมีสิทธิ์ scholarship');
}

$pdo = db();
$studentId = (int)($_GET['student_id'] ?? 0);
$month     = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

if (!$studentId) {
    http_response_code(400);
    exit('Missing student_id');
}

// Pull student + user info
$stmt = $pdo->prepare("SELECT s.id, s.user_id, s.student_code, s.faculty, s.scholarship_type,
                              s.semester, u.full_name, u.prefix
                       FROM sys_scholarship_students s
                       LEFT JOIN sys_users u ON u.id = s.user_id
                       WHERE s.id = :id LIMIT 1");
$stmt->execute([':id' => $studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    http_response_code(404);
    exit('Student not found');
}

// Month bounds
$startDate = $month . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));

// Aggregate clock_logs per day → daily rows
// แต่ละวัน: เริ่มเร็วสุด clock_in, สิ้นช้าสุด clock_out, รวม task_description
$rowsStmt = $pdo->prepare("
    SELECT DATE(event_at) AS day,
           MIN(CASE WHEN action='clock_in'  THEN event_at END) AS clock_in_at,
           MAX(CASE WHEN action='clock_out' THEN event_at END) AS clock_out_at,
           GROUP_CONCAT(DISTINCT task_description SEPARATOR ' · ') AS tasks
    FROM sys_scholarship_clock_logs
    WHERE student_id = :sid
      AND DATE(event_at) BETWEEN :s AND :e
      AND status = 'approved'
    GROUP BY DATE(event_at)
    ORDER BY day ASC
");
$rowsStmt->execute([':sid' => $studentId, ':s' => $startDate, ':e' => $endDate]);
$daily = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

// Pay rate
$rateOverride = isset($_GET['rate']) ? (float)$_GET['rate'] : null;
$payRate = $rateOverride;
if ($payRate === null) {
    try {
        $settings = function_exists('get_scholarship_settings') ? get_scholarship_settings($pdo) : [];
        $payRate = (float)($settings['pay_rate_per_hour'] ?? 30);
    } catch (Throwable) { $payRate = 30; }
}
if ($payRate <= 0) $payRate = 30;  // default per เอกสารต้นฉบับ

// Coordinator name (จาก settings หรือ session)
$coordinator = '';
try {
    $settings = function_exists('get_scholarship_settings') ? get_scholarship_settings($pdo) : [];
    $coordinator = (string)($settings['coordinator_name'] ?? '');
} catch (Throwable) {}

// Format helpers
$thaiMonths = ['', 'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$monthLabel = $thaiMonths[(int)substr($month, 5, 2)] . ' ' . ((int)substr($month, 0, 4) + 543);

function fmtDateThai($ymd) {
    $t = strtotime($ymd);
    return date('j/m/', $t) . (date('y', $t) + 43);  // 1/04/69 format
}
function fmtTime($dt) {
    if (!$dt) return '–';
    return date('H:i', strtotime($dt));
}

// Compute totals
$rows = [];
$totalHours = 0.0;
foreach ($daily as $d) {
    $hrs = 0.0;
    if ($d['clock_in_at'] && $d['clock_out_at']) {
        $hrs = (strtotime($d['clock_out_at']) - strtotime($d['clock_in_at'])) / 3600.0;
        if ($hrs < 0) $hrs = 0;
    }
    $rows[] = [
        'date'  => $d['day'],
        'in'    => $d['clock_in_at'],
        'out'   => $d['clock_out_at'],
        'hours' => $hrs,
        'tasks' => trim((string)($d['tasks'] ?? '')),
    ];
    $totalHours += $hrs;
}
$totalPay = $totalHours * $payRate;

$fullName = trim(($student['prefix'] ?? '') . ($student['prefix'] ? ' ' : '') . ($student['full_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>ใบลงเวลานักศึกษาทุน · <?= htmlspecialchars($fullName) ?> · <?= htmlspecialchars($monthLabel) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
@page { size: A4; margin: 15mm 14mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; color: #0f172a; font-size: 13px; }

.toolbar { position: sticky; top: 0; z-index: 50; background: #fff; border-bottom: 1px solid #e2e8f0; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.tb-info { font-size: 13px; }
.tb-info b { color: #0f172a; }
.tb-info span { color: #64748b; margin-left: 8px; }
.tb-btns { display: flex; gap: 6px; }
.tb-btn { padding: 6px 12px; border-radius: 7px; border: 1.5px solid #e2e8f0; background: #fff; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.tb-btn:hover { background: #f8fafc; }
.tb-btn--primary { background: #2e9e63; color: #fff; border-color: #2e9e63; }
.tb-btn--primary:hover { background: #268555; }

.page { background: #fff; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 16mm 14mm; box-shadow: 0 8px 28px rgba(0,0,0,.08); page-break-after: always; position: relative; }
.page:last-child { page-break-after: auto; }

.form-num { position: absolute; top: 14mm; right: 14mm; font-size: 12px; font-weight: 600; color: #64748b; }
h1.form-title { font-size: 17px; font-weight: 700; text-align: center; margin: 0 0 4px; color: #0f172a; }
h2.form-sub   { font-size: 15px; font-weight: 600; text-align: center; margin: 0 0 18px; color: #0f172a; }

.info { margin: 0 0 14px; }
.info-row { display: flex; gap: 8px; font-size: 13.5px; margin: 4px 0; }
.info-label { color: #475569; min-width: 160px; }
.info-value { color: #0f172a; font-weight: 600; border-bottom: 1px dotted #94a3b8; flex: 1; padding-bottom: 1px; }
.info-value.short { flex: 0 1 auto; min-width: 80px; padding: 0 8px; }

table.ts { width: 100%; border-collapse: collapse; margin-top: 8px; }
table.ts th, table.ts td { border: 1px solid #475569; padding: 6px 8px; text-align: center; font-size: 12.5px; vertical-align: middle; }
table.ts th { background: #f1f5f9; font-weight: 700; }
table.ts td.text-left { text-align: left; }
table.ts .num { width: 40px; }
table.ts .date { width: 90px; }
table.ts .time { width: 120px; }
table.ts .hours { width: 70px; }
table.ts .sign { width: 100px; }
.total-row td { background: #f8fafc; font-weight: 700; }

.footer { margin-top: 28px; display: flex; flex-direction: column; align-items: flex-end; }
.footer .sign-block { width: 340px; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.footer .sign-line { width: 100%; border-bottom: 1.5px solid #0f172a; height: 28px; }
.footer .sign-text { font-size: 13px; color: #0f172a; padding-top: 4px; text-align: center; }
.footer .sign-role { font-size: 12px; color: #64748b; }

.task-block { border: 1px solid #475569; padding: 10px 12px; margin: 8px 0; min-height: 60px; }
.task-block .task-date { font-weight: 700; font-size: 13px; color: #0f172a; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-bottom: 6px; }
.task-block .task-body { font-size: 13px; color: #0f172a; min-height: 30px; line-height: 1.6; }
.task-block.empty .task-body { color: #cbd5e1; font-style: italic; }

@media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .page { box-shadow: none; width: auto; min-height: auto; margin: 0; padding: 0; }
}
</style>
</head>
<body>

<!-- TOOLBAR (no-print) -->
<div class="toolbar">
    <div class="tb-info">
        <i class="fa-solid fa-file-invoice" style="color:#2e9e63"></i>
        <b>ใบลงเวลานักศึกษาทุน</b>
        <span><?= htmlspecialchars($fullName) ?> · <?= htmlspecialchars($monthLabel) ?> · <?= count($rows) ?> วัน · <?= number_format($totalHours, 1) ?> ชม.</span>
    </div>
    <div class="tb-btns">
        <button class="tb-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> พิมพ์</button>
        <button class="tb-btn tb-btn--primary" onclick="window.history.back()"><i class="fa-solid fa-arrow-left"></i> กลับ</button>
    </div>
</div>

<!-- ══════════════ PAGE 1 — แบบฟอร์มที่ 1 (ลงเวลา) ══════════════ -->
<div class="page">
    <div class="form-num">แบบฟอร์มที่ 1</div>
    <h1 class="form-title">แบบลงเวลาสำหรับนักศึกษาผู้ปฏิบัติงาน</h1>
    <h2 class="form-sub">โครงการสนับสนุนให้นักศึกษาทำงานระหว่างเรียน</h2>

    <div class="info">
        <div class="info-row"><span class="info-label">หน่วยงานที่ปฏิบัติงาน</span><span class="info-value">คลินิกเวชกรรม</span></div>
        <div class="info-row"><span class="info-label">ชื่อผู้ปฏิบัติงาน</span><span class="info-value"><?= htmlspecialchars($fullName) ?></span><span class="info-label" style="min-width:60px">รหัส</span><span class="info-value short"><?= htmlspecialchars($student['student_code'] ?? '') ?></span></div>
        <div class="info-row"><span class="info-label">วิทยาลัย / คณะ / สถาบัน / หน่วยงาน</span><span class="info-value"><?= htmlspecialchars($student['faculty'] ?? '') ?></span><span class="info-label" style="min-width:60px">ชั้นปีที่</span><span class="info-value short">&nbsp;</span></div>
    </div>

    <table class="ts">
        <thead>
            <tr>
                <th class="num">ลำดับที่</th>
                <th class="date">วัน/เดือน/ปี</th>
                <th class="time">เวลา (เริ่มต้น - สิ้นสุด)</th>
                <th class="hours">จำนวนชั่วโมง</th>
                <th class="sign">ลายเซ็น</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $maxRows = 20;
            for ($i = 0; $i < $maxRows; $i++):
                $r = $rows[$i] ?? null;
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $r ? htmlspecialchars(fmtDateThai($r['date'])) : '&nbsp;' ?></td>
                <td><?= $r ? htmlspecialchars(fmtTime($r['in']) . ' - ' . fmtTime($r['out'])) : '&nbsp;' ?></td>
                <td><?= $r ? number_format($r['hours'], 1) : '&nbsp;' ?></td>
                <td>&nbsp;</td>
            </tr>
            <?php endfor; ?>
            <tr class="total-row">
                <td colspan="3" style="text-align:right">รวมเวลาทำงาน</td>
                <td><?= number_format($totalHours, 1) ?> ชม.</td>
                <td><?= number_format($totalHours, 1) ?> × <?= number_format($payRate, 0) ?> = <b><?= number_format($totalPay, 0) ?> บาท</b></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <div class="sign-block">
            <div class="sign-line"></div>
            <div class="sign-text">( <?= htmlspecialchars($coordinator ?: '________________________________') ?> )</div>
            <div class="sign-role">ผู้ประสานงาน</div>
        </div>
    </div>
</div>

<!-- ══════════════ PAGE 2 — แบบฟอร์มที่ 2 (รายละเอียดการปฏิบัติงาน) ══════════════ -->
<div class="page">
    <div class="form-num">แบบฟอร์มที่ 2</div>
    <h1 class="form-title">แบบลงรายละเอียดการปฏิบัติงานสำหรับนักศึกษา</h1>
    <h2 class="form-sub">โครงการสนับสนุนให้นักศึกษาทำงานระหว่างเรียน</h2>

    <div class="info">
        <div class="info-row"><span class="info-label">หน่วยงานที่ปฏิบัติงาน</span><span class="info-value">คลินิกเวชกรรม</span></div>
        <div class="info-row"><span class="info-label">ชื่อผู้ปฏิบัติงาน</span><span class="info-value"><?= htmlspecialchars($fullName) ?></span><span class="info-label" style="min-width:60px">รหัส</span><span class="info-value short"><?= htmlspecialchars($student['student_code'] ?? '') ?></span></div>
        <div class="info-row"><span class="info-label">วิทยาลัย / คณะ / สถาบัน / หน่วยงาน</span><span class="info-value"><?= htmlspecialchars($student['faculty'] ?? '') ?></span><span class="info-label" style="min-width:60px">ชั้นปีที่</span><span class="info-value short">&nbsp;</span></div>
    </div>

    <?php
    $maxBlocks = max(8, count($rows));
    for ($i = 0; $i < $maxBlocks; $i++):
        $r = $rows[$i] ?? null;
        $hasTask = $r && !empty($r['tasks']);
    ?>
        <div class="task-block <?= !$hasTask ? 'empty' : '' ?>">
            <div class="task-date">
                วันที่ <?= $r ? htmlspecialchars(fmtDateThai($r['date'])) : '_____________________' ?>
                · การปฏิบัติงานในวันนี้
            </div>
            <div class="task-body"><?= $hasTask ? htmlspecialchars($r['tasks']) : '&nbsp;' ?></div>
        </div>
    <?php endfor; ?>

    <p style="font-size:11.5px;color:#94a3b8;margin-top:18px">
        หมายเหตุ : นักศึกษาต้องเขียนการปฏิบัติงานแต่ละวันให้ชัดเจน
    </p>
</div>

</body>
</html>
