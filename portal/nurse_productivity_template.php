<?php
// portal/nurse_productivity_template.php — empty Excel template for import
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$can = ($adminRole === 'superadmin') || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
if (!$can) { http_response_code(403); exit('Access Denied'); }

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Template');

$sh->setCellValue('A1', 'ระบบ Productivity พยาบาล — Template Import');
$sh->mergeCells('A1:F1');
$sh->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E9E63']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sh->getRowDimension(1)->setRowHeight(26);

$sh->setCellValue('A2', 'คำแนะนำ: กรอกข้อมูลตามคอลัมน์ — RN/หัวหน้า ปล่อยว่างได้ ระบบจะดึงจากตารางเวรอัตโนมัติ');
$sh->mergeCells('A2:F2');
$sh->getStyle('A2')->getFont()->setItalic(true);

$headers = ['วันที่ (YYYY-MM-DD)', 'ผู้ป่วย', 'RN (ว่างได้)', 'หัวหน้า (ว่างได้)', 'ชม./เวร', 'หมายเหตุ'];
$col = 'A';
foreach ($headers as $h) { $sh->setCellValue($col . '4', $h); $col++; }
$sh->getStyle('A4:F4')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F7A4C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Example rows
$today = new DateTime();
for ($i = 0; $i < 3; $i++) {
    $r = 5 + $i;
    $d = (clone $today)->modify("-$i days");
    $sh->setCellValue('A' . $r, $d->format('Y-m-d'))
       ->setCellValue('B' . $r, 100 + $i * 10)
       ->setCellValue('C' . $r, '') // empty → auto-fill
       ->setCellValue('D' . $r, '')
       ->setCellValue('E' . $r, 7)
       ->setCellValue('F' . $r, $i === 0 ? 'ตัวอย่างข้อมูล' : '');
}

foreach (range('A','F') as $c) $sh->getColumnDimension($c)->setAutoSize(true);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="nurse_productivity_template.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
