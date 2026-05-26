<?php
// portal/gold_card_stats_template.php — Template Excel สำหรับ Gold Card Stats
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$can = $isSuper || !empty($_SESSION['access_insurance']);
if (!$can) { http_response_code(403); exit('Access Denied'); }

$ss = new Spreadsheet();
$sh = $ss->getActiveSheet();
$sh->setTitle('Template');

// Title
$sh->setCellValue('A1', 'สถิติบัตรทอง — Template Import');
$sh->mergeCells('A1:C1');
$sh->getStyle('A1')->applyFromArray([
    'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D97706']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$sh->getRowDimension(1)->setRowHeight(26);

// Instructions
$sh->setCellValue('A2', 'คำแนะนำ: 3 คอลัมน์ — ปี (พ.ศ.) · เดือน (ตัวเลข 1-12 หรือชื่อไทย เช่น มกราคม) · จำนวน · 1 แถวต่อเดือน · ถ้าเดือนซ้ำจะถูกอัปเดตทับ');
$sh->mergeCells('A2:C2');
$sh->getStyle('A2')->getFont()->setItalic(true);

// Column headers
$sh->setCellValue('A4', 'ปี (พ.ศ.)');
$sh->setCellValue('B4', 'เดือน');
$sh->setCellValue('C4', 'จำนวน (คน)');
$sh->getStyle('A4:C4')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B45309']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Example rows — last 3 months from current
$now = new DateTime();
$thaiMonths = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
$examples = [8500, 8520, 8550];
for ($i = 0; $i < 3; $i++) {
    $r = 5 + $i;
    $d = (clone $now)->modify("-" . (2 - $i) . " months");
    $sh->setCellValue('A' . $r, ((int)$d->format('Y')) + 543);
    $sh->setCellValue('B' . $r, $thaiMonths[(int)$d->format('n')]);
    $sh->setCellValue('C' . $r, $examples[$i]);
}

// Auto-size
foreach (['A','B','C'] as $c) $sh->getColumnDimension($c)->setAutoSize(true);
// Right-align จำนวน
$sh->getStyle('C5:C1000')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="gold_card_stats_template.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($ss);
$writer->save('php://output');
exit;
