<?php
/**
 * portal/scholarship_payroll_summary.php
 * แบบสรุปการปฏิบัติงานของนักศึกษา · รวมทุกคนในเดือนนั้น · ส่งการเงิน
 *
 * URL: ?month=YYYY-MM (default = เดือนปัจจุบัน)
 *      ?rate=N        (override default pay_rate_per_hour จาก settings)
 *
 * Logic:
 *  - เฉพาะ comp_type='paid' (ค่าตอบแทน · ไม่ใช่ชั่วโมงทุน)
 *  - เฉพาะ status='approved'
 *  - Aggregate per student: count distinct days · sum hours
 *  - Hours per day = MAX(clock_out) - MIN(clock_in) ของวันนั้น (ปัดลงเป็นชั่วโมงเต็ม)
 *  - แสดงช่วงเดือนการทำงาน (เดือนเดียวหรือคร่อม 2 เดือน)
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
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

$startDate = $month . '-01';
$endDate   = date('Y-m-t', strtotime($startDate));

// Pay rate
$rateOverride = isset($_GET['rate']) ? (float)$_GET['rate'] : null;
$payRate = $rateOverride;
if ($payRate === null) {
    try {
        $settings = function_exists('get_scholarship_settings') ? get_scholarship_settings($pdo) : [];
        $payRate = (float)($settings['pay_rate_per_hour'] ?? 30);
    } catch (Throwable) { $payRate = 30; }
}
if ($payRate <= 0) $payRate = 30;

// Settings (coordinator + director names)
$coordinator = '';
$director    = '';
try {
    $settings = function_exists('get_scholarship_settings') ? get_scholarship_settings($pdo) : [];
    $coordinator = (string)($settings['coordinator_name'] ?? '');
    $director    = (string)($settings['director_name']    ?? '');
} catch (Throwable) {}

// ดึง student-day pairs ของ paid-comp ภายในเดือน
$logStmt = $pdo->prepare("
    SELECT l.student_id, DATE(l.event_at) AS day,
           MIN(CASE WHEN l.action='clock_in'  THEN l.event_at END) AS clock_in_at,
           MAX(CASE WHEN l.action='clock_out' THEN l.event_at END) AS clock_out_at
    FROM sys_scholarship_clock_logs l
    WHERE DATE(l.event_at) BETWEEN :s AND :e
      AND l.status = 'approved'
      AND l.comp_type = 'paid'
    GROUP BY l.student_id, DATE(l.event_at)
    HAVING clock_in_at IS NOT NULL AND clock_out_at IS NOT NULL
    ORDER BY l.student_id, day
");
$logStmt->execute([':s' => $startDate, ':e' => $endDate]);
$dayRows = $logStmt->fetchAll(PDO::FETCH_ASSOC);

// Pull student metadata for the IDs in use
$studentIds = array_unique(array_column($dayRows, 'student_id'));
$studentInfo = [];
if ($studentIds) {
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $pdo->prepare("SELECT s.id, s.student_code, s.faculty, u.full_name, u.prefix
                           FROM sys_scholarship_students s
                           LEFT JOIN sys_users u ON u.id = s.user_id
                           WHERE s.id IN ($placeholders)");
    $stmt->execute($studentIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentInfo[(int)$row['id']] = $row;
    }
}

// Aggregate per student
$summary = []; // student_id => [name, days, total_hours, total_pay, months_set]
foreach ($dayRows as $r) {
    $sid = (int)$r['student_id'];
    $info = $studentInfo[$sid] ?? null;
    if (!$info) continue;
    $name = trim(($info['prefix'] ?? '') . ($info['prefix'] ? ' ' : '') . ($info['full_name'] ?? ''));

    // Hours per day — diff in seconds, floor to whole hour (ตามนโยบายเดิม)
    $secs = strtotime($r['clock_out_at']) - strtotime($r['clock_in_at']);
    if ($secs < 0) continue;
    $hoursWhole = floor($secs / 3600);
    if ($hoursWhole <= 0) continue;

    if (!isset($summary[$sid])) {
        $summary[$sid] = [
            'name' => $name,
            'days' => 0,
            'total_hours' => 0,
            'months' => [],
        ];
    }
    $summary[$sid]['days']++;
    $summary[$sid]['total_hours'] += $hoursWhole;
    $summary[$sid]['months'][(int)substr($r['day'], 5, 2)] = true;
}

// Sort by name
uasort($summary, fn($a, $b) => strcmp($a['name'], $b['name']));

// Compute totals
$grandHours = 0; $grandDays = 0;
foreach ($summary as &$s) {
    $s['amount'] = $s['total_hours'] * $payRate;
    $grandHours += $s['total_hours'];
    $grandDays  += $s['days'];
}
unset($s);
$grandPay = $grandHours * $payRate;

// Format month label for header
$thaiMonths = ['', 'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$thaiMonthsShort = ['', 'ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$monthIdx  = (int)substr($month, 5, 2);
$yearBE    = (int)substr($month, 0, 4) + 543;
$monthLabel = $thaiMonths[$monthIdx] . ' ' . $yearBE;

function formatPeriod(array $monthIdxs, int $monthIdx, int $yearBE) {
    global $thaiMonthsShort;
    $months = array_keys($monthIdxs);
    sort($months);
    if (count($months) === 1) {
        return $thaiMonthsShort[$months[0]] . substr((string)$yearBE, -2);
    }
    return $thaiMonthsShort[$months[0]] . ' - ' . $thaiMonthsShort[end($months)] . substr((string)$yearBE, -2);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>แบบสรุปการปฏิบัติงานของนักศึกษา · <?= htmlspecialchars($monthLabel) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
@page { size: A4 landscape; margin: 14mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body { font-family: 'Sarabun', sans-serif; background: #f1f5f9; color: #0f172a; font-size: 13px; }

.toolbar { position: sticky; top: 0; z-index: 50; background: #fff; border-bottom: 1px solid #e2e8f0; padding: 10px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
.tb-info b { color: #0f172a; }
.tb-info span { color: #64748b; margin-left: 8px; }
.tb-btns { display: flex; gap: 6px; }
.tb-btn { padding: 6px 12px; border-radius: 7px; border: 1.5px solid #e2e8f0; background: #fff; font-size: 12.5px; font-weight: 600; cursor: pointer; }
.tb-btn:hover { background: #f8fafc; }
.tb-btn--primary { background: #2e9e63; color: #fff; border-color: #2e9e63; }
.tb-btn--primary:hover { background: #268555; }

.page { background: #fff; width: 297mm; min-height: 210mm; margin: 12px auto; padding: 14mm 16mm; box-shadow: 0 8px 28px rgba(0,0,0,.08); position: relative; }

h1.form-title { font-size: 18px; font-weight: 700; text-align: center; margin: 0 0 4px; color: #0f172a; }
h2.form-sub   { font-size: 14px; font-weight: 500; text-align: center; margin: 0 0 22px; color: #0f172a; }

table.summary { width: 100%; border-collapse: collapse; }
table.summary th, table.summary td { border: 1px solid #475569; padding: 7px 10px; text-align: center; font-size: 13px; vertical-align: middle; }
table.summary th { background: #f1f5f9; font-weight: 700; }
table.summary td.text-left { text-align: left; }
table.summary .num { width: 60px; }
table.summary .name { width: 25%; }
table.summary .period { width: 130px; }
table.summary .days { width: 80px; }
table.summary .calc { width: 140px; }
table.summary .amount { width: 100px; font-variant-numeric: tabular-nums; }
.total-row td { background: #f8fafc; font-weight: 800; font-size: 13.5px; }
.total-label { text-align: right; }

.empty-row td { color: #cbd5e1; }
.no-data { text-align: center; padding: 40px; color: #64748b; font-size: 14px; }

.signs { margin-top: 36px; display: flex; justify-content: space-around; gap: 60px; }
.sign-block { display: flex; flex-direction: column; align-items: center; min-width: 280px; }
.sign-label { font-size: 13px; color: #0f172a; }
.sign-line { width: 220px; border-bottom: 1.5px solid #0f172a; height: 28px; }
.sign-text { font-size: 13px; color: #0f172a; padding-top: 4px; }
.sign-role { font-size: 12.5px; color: #64748b; }

@media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .page { box-shadow: none; width: auto; min-height: auto; margin: 0; padding: 0; }
}
</style>
</head>
<body>

<div class="toolbar">
    <div class="tb-info">
        <i class="fa-solid fa-file-invoice-dollar" style="color:#2e9e63"></i>
        <b>แบบสรุปการปฏิบัติงานของนักศึกษา</b>
        <span><?= htmlspecialchars($monthLabel) ?> · <?= count($summary) ?> คน · <?= number_format($grandHours, 0) ?> ชม. · รวม <?= number_format($grandPay, 0) ?> บาท</span>
    </div>
    <div class="tb-btns">
        <button class="tb-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> พิมพ์</button>
        <button class="tb-btn tb-btn--primary" onclick="window.history.back()"><i class="fa-solid fa-arrow-left"></i> กลับ</button>
    </div>
</div>

<div class="page">
    <h1 class="form-title">แบบสรุปการปฏิบัติงานของนักศึกษา</h1>
    <h2 class="form-sub">โครงการสนับสนุนให้นักศึกษาทำงานระหว่างเรียน สำนักงานสวัสดิการสุขภาพ มหาวิทยาลัยรังสิต ประจำเดือน <?= htmlspecialchars($monthLabel) ?></h2>

    <?php if (empty($summary)): ?>
        <div class="no-data">
            <i class="fa-solid fa-inbox" style="font-size:32px;display:block;margin-bottom:12px;color:#cbd5e1"></i>
            <b>ไม่มีข้อมูลในเดือน <?= htmlspecialchars($monthLabel) ?></b><br>
            <span style="font-size:12px">ยังไม่มีนักศึกษาทุนที่ทำงานแบบค่าตอบแทน (paid) และอนุมัติแล้วในเดือนนี้</span>
        </div>
    <?php else: ?>
        <table class="summary">
            <thead>
                <tr>
                    <th class="num">ลำดับ</th>
                    <th class="name">ชื่อนักศึกษา</th>
                    <th class="period">ระยะเวลาปฏิบัติงาน</th>
                    <th class="days">จำนวนวัน</th>
                    <th class="calc">จำนวนชั่วโมง×จำนวนเงิน</th>
                    <th class="amount">จำนวนเงิน</th>
                    <th>หมายเหตุ</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($summary as $s): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td class="text-left"><?= htmlspecialchars($s['name']) ?></td>
                    <td><?= htmlspecialchars(formatPeriod($s['months'], $monthIdx, $yearBE)) ?></td>
                    <td><?= (int)$s['days'] ?></td>
                    <td><?= (int)$s['total_hours'] ?>×<?= number_format($payRate, 0) ?></td>
                    <td><?= number_format($s['amount'], 0) ?></td>
                    <td>&nbsp;</td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3" class="total-label">รวมทั้งสิ้น</td>
                    <td><?= (int)$grandDays ?></td>
                    <td><?= (int)$grandHours ?>×<?= number_format($payRate, 0) ?></td>
                    <td><?= number_format($grandPay, 0) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="signs">
        <div class="sign-block">
            <div class="sign-line"></div>
            <div class="sign-text">( <?= htmlspecialchars($coordinator ?: '____________________') ?> )</div>
            <div class="sign-role">ผู้ประสานงาน</div>
        </div>
        <div class="sign-block">
            <div class="sign-line"></div>
            <div class="sign-text">( <?= htmlspecialchars($director ?: '____________________') ?> )</div>
            <div class="sign-role">ผู้อำนวยการสำนักงานสวัสดิการสุขภาพ</div>
        </div>
    </div>
</div>

</body>
</html>
