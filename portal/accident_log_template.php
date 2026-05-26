<?php
// portal/accident_log_template.php — empty Excel template for Accident Log import
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

// Title
$sh->setCellValue('A1', 'ระบบบันทึกอุบัติเหตุ — Template Import');
$sh->mergeCells('A1:B1');
$sh->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sh->getRowDimension(1)->setRowHeight(26);

// Instructions
$sh->setCellValue('A2', 'คำแนะนำ: กรอก 2 คอลัมน์ — วันที่ (YYYY-MM-DD) และจำนวนครั้ง · 1 แถวต่อวัน · ถ้ามีวันซ้ำจะถูกอัปเดตทับ');
$sh->mergeCells('A2:B2');
$sh->getStyle('A2')->getFont()->setItalic(true);

// Column headers
$sh->setCellValue('A4', 'วันที่ (YYYY-MM-DD)');
$sh->setCellValue('B4', 'จำนวน (ครั้ง)');
$sh->getStyle('A4:B4')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B91C1C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Example rows — last 3 days
$today = new DateTime();
$examples = [3, 7, 2];
for ($i = 0; $i < count($examples); $i++) {
    $r = 5 + $i;
    $d = (clone $today)->modify("-$i days");
    $sh->setCellValue('A' . $r, $d->format('Y-m-d'))
       ->setCellValue('B' . $r, $examples[$i]);
}

// Auto-size columns
foreach (['A','B'] as $c) $sh->getColumnDimension($c)->setAutoSize(true);
// Right-align จำนวน
$sh->getStyle('B5:B1000')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="accident_log_template.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
