<?php
// portal/nurse_productivity_print.php — A4 print-friendly view
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$can = ($adminRole === 'superadmin') || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
if (!$can) { http_response_code(403); exit('Access Denied'); }

$pdo = db();
$deptId = (int)($_GET['dept_id'] ?? 0);
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
if (!$deptId) { http_response_code(400); exit('dept_id required'); }

$st = $pdo->prepare("SELECT * FROM sys_nurse_productivity_settings WHERE dept_id = ?");
$st->execute([$deptId]);
$s = $st->fetch(PDO::FETCH_ASSOC) ?: ['hpv' => 0.24, 'threshold_low' => 80, 'threshold_high' => 110, 'hospital_name' => '', 'level' => 'F2'];
$deptName = (string)$pdo->query("SELECT name FROM sys_departments WHERE id = " . (int)$deptId)->fetchColumn();

$sql = "SELECT * FROM sys_nurse_productivity_daily WHERE dept_id = :did";
$bind = ['did' => $deptId];
if ($from) { $sql .= " AND entry_date >= :from"; $bind['from'] = $from; }
if ($to)   { $sql .= " AND entry_date <= :to";   $bind['to'] = $to; }
$sql .= " ORDER BY entry_date ASC";
$stmt = $pdo->prepare($sql); $stmt->execute($bind);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hpv = (float)$s['hpv'];
$low = (int)$s['threshold_low']; $high = (int)$s['threshold_high'];
$THAI_DAYS = ['อา','จ','อ','พ','พฤ','ศ','ส'];
$totVisits = 0; $totNeeded = 0; $totAvail = 0; $prodSum = 0; $opt = 0; $und = 0; $ovr = 0;
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Productivity พยาบาล — <?= htmlspecialchars($deptName) ?></title>
<style>
  @page { size: A4; margin: 14mm; }
  body { font-family: 'Sarabun', 'TH SarabunPSK', sans-serif; font-size: 12px; color:#0f172a; margin:0; }
  h1 { font-size: 18px; margin: 0 0 4px; color: #1f7a4c; }
  .hdr { padding: 10px 0; border-bottom: 2px solid #2e9e63; margin-bottom: 12px; display:flex; justify-content:space-between; align-items:flex-end; }
  .hdr-sub { font-size: 11px; color: #475569; }
  .meta-row { font-size: 11px; color: #64748b; margin: 4px 0 12px; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 11px; }
  th { background: #f0fdf4; padding: 6px 8px; text-align: left; border-bottom: 1.5px solid #2e9e63; font-weight: 600; }
  td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
  td.num, th.num { text-align: right; }
  tr.totals td { background:#f1f5f9; font-weight: 700; border-top: 2px solid #2e9e63; }
  .pill { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 10px; font-weight: 600; }
  .pill-ok { background: #d1fae5; color: #047857; }
  .pill-und { background: #fee2e2; color: #b91c1c; }
  .pill-ovr { background: #fef3c7; color: #b45309; }
  .summary { display:grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 12px; }
  .sum-card { padding: 10px 12px; background: #f8fafc; border-radius: 8px; border-left: 3px solid #2e9e63; }
  .sum-card label { display:block; font-size: 10px; color: #64748b; }
  .sum-card strong { font-size: 16px; color: #1f7a4c; }
  .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #94a3b8; }
  .print-btn { position: fixed; top: 10px; right: 10px; padding: 8px 14px; background: #2e9e63; color: #fff; border: 0; border-radius: 8px; cursor: pointer; font-family: inherit; font-size: 12px; }
  @media print { .print-btn { display: none; } }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨️ พิมพ์</button>

<div class="hdr">
  <div>
    <h1>รายงาน Productivity พยาบาล OPD</h1>
    <div class="hdr-sub"><?= htmlspecialchars($s['hospital_name'] ?: 'โรงพยาบาล') ?> · ระดับ <?= htmlspecialchars($s['level']) ?> · หน่วยงาน <?= htmlspecialchars($deptName) ?></div>
  </div>
  <div class="hdr-sub" style="text-align:right">
    พิมพ์เมื่อ <?= date('d/m/Y H:i') ?>
  </div>
</div>

<div class="meta-row">
  ช่วงข้อมูล: <strong><?= htmlspecialchars($from ?: '—') ?></strong> ถึง <strong><?= htmlspecialchars($to ?: '—') ?></strong>
  · เกณฑ์มาตรฐาน: HPV <?= $hpv ?> ชม. · เกณฑ์ Productivity <?= $low ?>–<?= $high ?>%
</div>

<table>
  <thead>
    <tr>
      <th>#</th><th>วันที่</th><th>วัน</th>
      <th class="num">ผู้ป่วย</th><th class="num">RN</th><th class="num">หัวหน้า</th>
      <th class="num">ชม./เวร</th><th class="num">ชม.ต้องการ</th><th class="num">ชม.ที่มี</th>
      <th class="num">Prod %</th><th>สถานะ</th><th>หมายเหตุ</th>
    </tr>
  </thead>
  <tbody>
<?php $i = 1; foreach ($rows as $r):
    $ts = strtotime($r['entry_date']);
    $needed = $r['patients'] * $hpv;
    $available = ($r['rn_count'] + $r['head_count']) * $r['shift_hours'];
    $prod = $available > 0 ? ($needed / $available) * 100 : 0;
    if ($prod < $low) { $status = 'Over'; $pill = 'pill-ovr'; $ovr++; }
    elseif ($prod > $high) { $status = 'Under'; $pill = 'pill-und'; $und++; }
    else { $status = 'Optimal'; $pill = 'pill-ok'; $opt++; }
    $totVisits += $r['patients']; $totNeeded += $needed; $totAvail += $available; $prodSum += $prod;
?>
    <tr>
      <td><?= $i++ ?></td>
      <td><?= htmlspecialchars($r['entry_date']) ?></td>
      <td><?= $THAI_DAYS[(int)date('w', $ts)] ?></td>
      <td class="num"><?= (int)$r['patients'] ?></td>
      <td class="num"><?= (int)$r['rn_count'] ?><?= $r['rn_source']==='schedule'?' *':'' ?></td>
      <td class="num"><?= (int)$r['head_count'] ?><?= $r['head_source']==='schedule'?' *':'' ?></td>
      <td class="num"><?= (float)$r['shift_hours'] ?></td>
      <td class="num"><?= number_format($needed, 1) ?></td>
      <td class="num"><?= number_format($available, 1) ?></td>
      <td class="num"><strong><?= number_format($prod, 1) ?>%</strong></td>
      <td><span class="pill <?= $pill ?>"><?= $status ?></span></td>
      <td><?= htmlspecialchars($r['note'] ?? '') ?></td>
    </tr>
<?php endforeach;
$count = count($rows);
$avgProd = $count ? $prodSum / $count : 0;
?>
    <tr class="totals">
      <td colspan="3">รวม <?= $count ?> วัน</td>
      <td class="num"><?= number_format($totVisits) ?></td>
      <td colspan="3"></td>
      <td class="num"><?= number_format($totNeeded, 1) ?></td>
      <td class="num"><?= number_format($totAvail, 1) ?></td>
      <td class="num"><strong><?= number_format($avgProd, 1) ?>%</strong></td>
      <td colspan="2"></td>
    </tr>
  </tbody>
</table>

<div class="summary">
  <div class="sum-card"><label>Optimal</label><strong><?= $opt ?> วัน</strong></div>
  <div class="sum-card" style="border-left-color:#ef4444"><label>Under staff (ภาระสูง)</label><strong><?= $und ?> วัน</strong></div>
  <div class="sum-card" style="border-left-color:#f59e0b"><label>Over staff (กำลังเหลือ)</label><strong><?= $ovr ?> วัน</strong></div>
</div>

<div class="footer">
  * = ดึงค่าจากตารางเวร (sys_nurse_schedule_monthly)
  · เกณฑ์อ้างอิง: มาตรฐานการพยาบาลในโรงพยาบาล ฉบับปรับปรุงครั้งที่ 4 (พ.ศ. 2551) สภาการพยาบาล
</div>

</body></html>
