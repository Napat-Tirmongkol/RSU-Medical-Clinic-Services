<?php
// portal/nurse_productivity_export.php — Excel export (PhpSpreadsheet)
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$can = ($adminRole === 'superadmin') || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
if (!$can) { http_response_code(403); exit('Access Denied'); }

$pdo = db();
$deptId = (int)($_GET['dept_id'] ?? 0);
$from   = $_GET['from'] ?? null;
$to     = $_GET['to'] ?? null;
if (!$deptId) { http_response_code(400); exit('dept_id required'); }

// Load settings + rows
$settings = $pdo->prepare("SELECT * FROM sys_nurse_productivity_settings WHERE dept_id = ?");
$settings->execute([$deptId]);
$s = $settings->fetch(PDO::FETCH_ASSOC) ?: [
    'hospital_name' => '', 'level' => 'F2', 'beds' => null, 'province' => '',
    'hpv' => 0.24, 'shift_hours' => 7, 'threshold_low' => 80, 'threshold_high' => 110,
    'period_label' => '',
];
$deptName = (string)$pdo->query("SELECT name FROM sys_departments WHERE id = " . (int)$deptId)->fetchColumn();

$sql = "SELECT * FROM sys_nurse_productivity_daily WHERE dept_id = :did";
$bind = ['did' => $deptId];
if ($from) { $sql .= " AND entry_date >= :from"; $bind['from'] = $from; }
if ($to)   { $sql .= " AND entry_date <= :to";   $bind['to']   = $to; }
$sql .= " ORDER BY entry_date ASC";
$st = $pdo->prepare($sql);
$st->execute($bind);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Productivity');

// Header block
$sh->setCellValue('A1', 'ระบบคำนวณ Productivity พยาบาล OPD');
$sh->mergeCells('A1:M1');
$sh->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E9E63']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sh->getRowDimension(1)->setRowHeight(28);

$sh->setCellValue('A2', 'หน่วยงาน: ' . $deptName . ' · ' . ($s['hospital_name'] ?? '') . ' · ระดับ ' . ($s['level'] ?? ''));
$sh->mergeCells('A2:M2');
$sh->setCellValue('A3', 'ช่วงข้อมูล: ' . ($from ?: '—') . ' ถึง ' . ($to ?: '—') . ' · พิมพ์เมื่อ: ' . date('d/m/Y H:i'));
$sh->mergeCells('A3:M3');
$sh->getStyle('A2:A3')->getFont()->setSize(11);

// Column headers
$headers = ['#', 'วันที่', 'วัน', 'ผู้ป่วย', 'RN', 'หัวหน้า', 'ชม./เวร', 'ชม.ต้องการ', 'ชม.ที่มี', 'Prod %', 'สถานะ', 'แหล่งข้อมูล', 'หมายเหตุ'];
$col = 'A'; $row = 5;
foreach ($headers as $h) { $sh->setCellValue($col . $row, $h); $col++; }
$sh->getStyle('A5:M5')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F7A4C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
]);

$hpv = (float)$s['hpv'];
$low = (int)$s['threshold_low'];
$high = (int)$s['threshold_high'];
$THAI_DAYS_FULL = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];

$r = 6; $i = 1;
$totVisits = 0; $totNeeded = 0.0; $totAvail = 0.0; $prodSum = 0.0;
$opt = 0; $und = 0; $ovr = 0;
foreach ($rows as $d) {
    $ts = strtotime($d['entry_date']);
    $dowName = $THAI_DAYS_FULL[(int)date('w', $ts)];
    $needed = $d['patients'] * $hpv;
    $available = ($d['rn_count'] + $d['head_count']) * $d['shift_hours'];
    $prod = $available > 0 ? ($needed / $available) * 100 : 0.0;
    $status = 'Optimal';
    if ($prod < $low)        { $status = 'Over staff';  $ovr++; }
    elseif ($prod > $high)   { $status = 'Under staff'; $und++; }
    else                     {                          $opt++; }
    $src = [];
    if ($d['rn_source']   === 'schedule') $src[] = 'RN←ตารางเวร';
    if ($d['head_source'] === 'schedule') $src[] = 'หัวหน้า←ตารางเวร';
    $sh->setCellValue('A' . $r, $i)
       ->setCellValue('B' . $r, $d['entry_date'])
       ->setCellValue('C' . $r, $dowName)
       ->setCellValue('D' . $r, (int)$d['patients'])
       ->setCellValue('E' . $r, (int)$d['rn_count'])
       ->setCellValue('F' . $r, (int)$d['head_count'])
       ->setCellValue('G' . $r, (float)$d['shift_hours'])
       ->setCellValue('H' . $r, round($needed, 2))
       ->setCellValue('I' . $r, round($available, 2))
       ->setCellValue('J' . $r, round($prod, 1))
       ->setCellValue('K' . $r, $status)
       ->setCellValue('L' . $r, implode('; ', $src))
       ->setCellValue('M' . $r, $d['note']);
    $totVisits += (int)$d['patients'];
    $totNeeded += $needed;
    $totAvail  += $available;
    $prodSum   += $prod;
    $r++; $i++;
}

// Totals row
$avgProd = $rows ? $prodSum / count($rows) : 0;
$sh->setCellValue('A' . $r, 'รวม')
   ->setCellValue('D' . $r, $totVisits)
   ->setCellValue('H' . $r, round($totNeeded, 1))
   ->setCellValue('I' . $r, round($totAvail, 1))
   ->setCellValue('J' . $r, round($avgProd, 1));
$sh->getStyle('A' . $r . ':M' . $r)->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
]);

// Summary
$r += 2;
$sh->setCellValue('A' . $r, 'สรุป:');
$sh->getStyle('A' . $r)->getFont()->setBold(true);
$r++;
$sh->setCellValue('A' . $r, "Optimal: $opt วัน · Under staff: $und วัน · Over staff: $ovr วัน · เกณฑ์ $low–$high%");

// Column widths
foreach (range('A','M') as $c) $sh->getColumnDimension($c)->setAutoSize(true);

// Output
$filename = 'nurse_productivity_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $deptName) . '_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
