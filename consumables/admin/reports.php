<?php
// consumables/admin/reports.php — รายงาน + Export Excel หลาย sheet
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
csm_require_manage();

$pdo = db();

$dateFrom    = (string)($_GET['date_from']    ?? date('Y-m-01'));
$dateTo      = (string)($_GET['date_to']      ?? date('Y-m-d'));
$filterCat   = (int)   ($_GET['category_id']  ?? 0);
$filterFac   = (int)   ($_GET['faculty_id']   ?? 0);
$filterType  = (string)($_GET['txn_type']     ?? 'issue'); // default = เบิกออกเป็นหลัก

$where  = []; $params = [];
if ($dateFrom !== '') { $where[] = 't.txn_date >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 't.txn_date <= ?'; $params[] = $dateTo; }
if ($filterCat > 0)   { $where[] = 'c.category_id = ?'; $params[] = $filterCat; }
if ($filterFac > 0)   { $where[] = 't.faculty_id = ?'; $params[] = $filterFac; }
if ($filterType !== '' && array_key_exists($filterType, csm_txn_options())) {
    $where[] = 't.txn_type = ?'; $params[] = $filterType;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Export Excel ─────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../../vendor/autoload.php';

    // 1) Transaction list
    $listSql = "SELECT t.txn_date, t.txn_type, c.code AS item_code, c.name AS item_name, c.brand,
                       t.qty_input, t.unit_input, c.unit_pack, c.unit_piece, t.qty_change, t.balance_after,
                       f.name_th AS faculty_name, f.type AS faculty_type,
                       t.requester_name, t.purpose, t.reference, t.note,
                       s.full_name AS created_by_name, t.created_at
                FROM consumable_transactions t
                LEFT JOIN consumables   c ON c.id = t.consumable_id
                LEFT JOIN sys_faculties f ON f.id = t.faculty_id
                LEFT JOIN sys_staff     s ON s.id = t.created_by
                {$whereSql}
                ORDER BY t.txn_date ASC, t.id ASC";
    $stmt = $pdo->prepare($listSql); $stmt->execute($params);
    $listRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2) By faculty
    $facSql = "SELECT COALESCE(f.name_th, '— ไม่ระบุ —') AS name, f.type AS type,
                      COUNT(*) AS txn_cnt,
                      SUM(CASE WHEN t.qty_change < 0 THEN -t.qty_change ELSE 0 END) AS qty_out,
                      SUM(CASE WHEN t.qty_change > 0 THEN t.qty_change ELSE 0 END) AS qty_in
               FROM consumable_transactions t
               LEFT JOIN consumables   c ON c.id = t.consumable_id
               LEFT JOIN sys_faculties f ON f.id = t.faculty_id
               {$whereSql}
               GROUP BY t.faculty_id ORDER BY qty_out DESC";
    $stmt = $pdo->prepare($facSql); $stmt->execute($params);
    $facRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3) By item
    $itmSql = "SELECT c.code, c.name, c.brand, c.unit_piece,
                      COUNT(*) AS txn_cnt,
                      SUM(CASE WHEN t.qty_change < 0 THEN -t.qty_change ELSE 0 END) AS qty_out,
                      SUM(CASE WHEN t.qty_change > 0 THEN t.qty_change ELSE 0 END) AS qty_in
               FROM consumable_transactions t
               LEFT JOIN consumables c ON c.id = t.consumable_id
               {$whereSql}
               GROUP BY t.consumable_id ORDER BY qty_out DESC";
    $stmt = $pdo->prepare($itmSql); $stmt->execute($params);
    $itmRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4) Current stock
    $stockRows = $pdo->query("
        SELECT c.code, c.name, c.brand, c.qty_on_hand, c.min_stock, c.unit_piece, c.unit_pack, c.pack_size,
               cat.name AS category, l.name AS location
        FROM consumables c
        LEFT JOIN consumable_categories cat ON cat.id = c.category_id
        LEFT JOIN asset_locations       l   ON l.id   = c.location_id
        WHERE c.status='active'
        ORDER BY c.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $headStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2E9E63']],
        'alignment' => ['horizontal' => 'center'],
    ];

    // Sheet 1: Transactions
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('ประวัติการเคลื่อนไหว');
    $headers = ['วันที่','ประเภท','รหัส','ชื่อวัสดุ','ยี่ห้อ','จำนวน(กรอก)','หน่วย','หน่วยย่อย','เปลี่ยน(ชิ้น)','คงเหลือ','หน่วยงาน','ประเภทหน่วยงาน','ผู้รับ','วัตถุประสงค์','อ้างอิง','หมายเหตุ','ผู้บันทึก','บันทึกเมื่อ'];
    $col = 'A';
    foreach ($headers as $h) { $sheet->setCellValue($col.'1', $h); $col++; }
    $sheet->getStyle('A1:R1')->applyFromArray($headStyle);
    $r = 2;
    $txnLabels = csm_txn_options();
    foreach ($listRows as $row) {
        $unitLabel = $row['unit_input'] === 'pack' ? ($row['unit_pack'] ?: 'บรรจุภัณฑ์') : ($row['unit_piece'] ?: 'ชิ้น');
        $facType   = ($row['faculty_type'] ?? '') === 'department' ? 'หน่วยงาน' : (($row['faculty_type'] ?? '') === 'faculty' ? 'คณะ' : '');
        $sheet->setCellValueExplicit("A{$r}", $row['txn_date'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue("B{$r}", $txnLabels[$row['txn_type']] ?? $row['txn_type']);
        $sheet->setCellValue("C{$r}", $row['item_code']);
        $sheet->setCellValue("D{$r}", $row['item_name']);
        $sheet->setCellValue("E{$r}", $row['brand']);
        $sheet->setCellValue("F{$r}", (int)$row['qty_input']);
        $sheet->setCellValue("G{$r}", $unitLabel);
        $sheet->setCellValue("H{$r}", $row['unit_piece']);
        $sheet->setCellValue("I{$r}", (int)$row['qty_change']);
        $sheet->setCellValue("J{$r}", (int)$row['balance_after']);
        $sheet->setCellValue("K{$r}", $row['faculty_name']);
        $sheet->setCellValue("L{$r}", $facType);
        $sheet->setCellValue("M{$r}", $row['requester_name']);
        $sheet->setCellValue("N{$r}", $row['purpose']);
        $sheet->setCellValue("O{$r}", $row['reference']);
        $sheet->setCellValue("P{$r}", $row['note']);
        $sheet->setCellValue("Q{$r}", $row['created_by_name']);
        $sheet->setCellValue("R{$r}", $row['created_at']);
        $r++;
    }
    foreach (range('A', 'R') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
    $sheet->freezePane('A2');

    // Sheet 2: By faculty
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('สรุปตามหน่วยงาน');
    $h = ['หน่วยงาน/คณะ','ประเภท','จำนวนรายการ','รับเข้า(ชิ้น)','เบิกออก(ชิ้น)'];
    $col = 'A'; foreach ($h as $hh) { $sheet2->setCellValue($col.'1', $hh); $col++; }
    $sheet2->getStyle('A1:E1')->applyFromArray($headStyle);
    $r = 2;
    foreach ($facRows as $row) {
        $facType = ($row['type'] ?? '') === 'department' ? 'หน่วยงาน' : (($row['type'] ?? '') === 'faculty' ? 'คณะ' : '-');
        $sheet2->setCellValue("A{$r}", $row['name']);
        $sheet2->setCellValue("B{$r}", $facType);
        $sheet2->setCellValue("C{$r}", (int)$row['txn_cnt']);
        $sheet2->setCellValue("D{$r}", (int)$row['qty_in']);
        $sheet2->setCellValue("E{$r}", (int)$row['qty_out']);
        $r++;
    }
    foreach (range('A', 'E') as $c) $sheet2->getColumnDimension($c)->setAutoSize(true);
    $sheet2->freezePane('A2');

    // Sheet 3: By item
    $sheet3 = $spreadsheet->createSheet();
    $sheet3->setTitle('สรุปตามวัสดุ');
    $h = ['รหัส','ชื่อ','ยี่ห้อ','หน่วย','จำนวนรายการ','รับเข้า','เบิกออก'];
    $col = 'A'; foreach ($h as $hh) { $sheet3->setCellValue($col.'1', $hh); $col++; }
    $sheet3->getStyle('A1:G1')->applyFromArray($headStyle);
    $r = 2;
    foreach ($itmRows as $row) {
        $sheet3->setCellValue("A{$r}", $row['code']);
        $sheet3->setCellValue("B{$r}", $row['name']);
        $sheet3->setCellValue("C{$r}", $row['brand']);
        $sheet3->setCellValue("D{$r}", $row['unit_piece']);
        $sheet3->setCellValue("E{$r}", (int)$row['txn_cnt']);
        $sheet3->setCellValue("F{$r}", (int)$row['qty_in']);
        $sheet3->setCellValue("G{$r}", (int)$row['qty_out']);
        $r++;
    }
    foreach (range('A', 'G') as $c) $sheet3->getColumnDimension($c)->setAutoSize(true);
    $sheet3->freezePane('A2');

    // Sheet 4: Stock current
    $sheet4 = $spreadsheet->createSheet();
    $sheet4->setTitle('สต็อกปัจจุบัน');
    $h = ['รหัส','ชื่อ','ยี่ห้อ','หมวดหมู่','จุดจัดเก็บ','คงเหลือ(ชิ้น)','หน่วยย่อย','บรรจุภัณฑ์','จำนวน/บรรจุภัณฑ์','จุดสั่งซื้อ','สถานะ'];
    $col = 'A'; foreach ($h as $hh) { $sheet4->setCellValue($col.'1', $hh); $col++; }
    $sheet4->getStyle('A1:K1')->applyFromArray($headStyle);
    $r = 2;
    foreach ($stockRows as $row) {
        $isLow = ((int)$row['min_stock'] > 0 && (int)$row['qty_on_hand'] <= (int)$row['min_stock']);
        $sheet4->setCellValue("A{$r}", $row['code']);
        $sheet4->setCellValue("B{$r}", $row['name']);
        $sheet4->setCellValue("C{$r}", $row['brand']);
        $sheet4->setCellValue("D{$r}", $row['category']);
        $sheet4->setCellValue("E{$r}", $row['location']);
        $sheet4->setCellValue("F{$r}", (int)$row['qty_on_hand']);
        $sheet4->setCellValue("G{$r}", $row['unit_piece']);
        $sheet4->setCellValue("H{$r}", $row['unit_pack']);
        $sheet4->setCellValue("I{$r}", (int)$row['pack_size']);
        $sheet4->setCellValue("J{$r}", (int)$row['min_stock']);
        $sheet4->setCellValue("K{$r}", $isLow ? 'ใกล้หมด' : 'ปกติ');
        $r++;
    }
    foreach (range('A', 'K') as $c) $sheet4->getColumnDimension($c)->setAutoSize(true);
    $sheet4->freezePane('A2');

    $spreadsheet->setActiveSheetIndex(0);
    $filename = 'consumables_report_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// ── Web summary ──────────────────────────────────────────────────────────
$summarySql = "SELECT
        COUNT(*) AS total_txn,
        SUM(CASE WHEN t.qty_change > 0 THEN t.qty_change ELSE 0 END) AS qty_in,
        SUM(CASE WHEN t.qty_change < 0 THEN -t.qty_change ELSE 0 END) AS qty_out,
        COUNT(DISTINCT t.consumable_id) AS unique_items,
        COUNT(DISTINCT t.faculty_id) AS unique_faculties
    FROM consumable_transactions t
    LEFT JOIN consumables c ON c.id = t.consumable_id
    {$whereSql}";
$stmt = $pdo->prepare($summarySql); $stmt->execute($params);
$sum = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

// By faculty (top 10)
$facSql = "SELECT COALESCE(f.name_th, '— ไม่ระบุ —') AS name, f.type AS type,
                  COUNT(*) AS txn_cnt,
                  SUM(CASE WHEN t.qty_change < 0 THEN -t.qty_change ELSE 0 END) AS qty_out,
                  SUM(CASE WHEN t.qty_change > 0 THEN t.qty_change ELSE 0 END) AS qty_in
           FROM consumable_transactions t
           LEFT JOIN consumables   c ON c.id = t.consumable_id
           LEFT JOIN sys_faculties f ON f.id = t.faculty_id
           {$whereSql}
           GROUP BY t.faculty_id ORDER BY qty_out DESC LIMIT 10";
$stmt = $pdo->prepare($facSql); $stmt->execute($params);
$rowsByFac = $stmt->fetchAll(PDO::FETCH_ASSOC);

// By item (top 10)
$itmSql = "SELECT c.code, c.name, c.unit_piece,
                  COUNT(*) AS txn_cnt,
                  SUM(CASE WHEN t.qty_change < 0 THEN -t.qty_change ELSE 0 END) AS qty_out,
                  SUM(CASE WHEN t.qty_change > 0 THEN t.qty_change ELSE 0 END) AS qty_in
           FROM consumable_transactions t
           LEFT JOIN consumables c ON c.id = t.consumable_id
           {$whereSql}
           GROUP BY t.consumable_id ORDER BY qty_out DESC LIMIT 10";
$stmt = $pdo->prepare($itmSql); $stmt->execute($params);
$rowsByItm = $stmt->fetchAll(PDO::FETCH_ASSOC);

// By month
$monSql = "SELECT DATE_FORMAT(t.txn_date, '%Y-%m') AS ym,
                  SUM(CASE WHEN t.qty_change > 0 THEN t.qty_change ELSE 0 END) AS qty_in,
                  SUM(CASE WHEN t.qty_change < 0 THEN -t.qty_change ELSE 0 END) AS qty_out
           FROM consumable_transactions t
           LEFT JOIN consumables c ON c.id = t.consumable_id
           {$whereSql}
           GROUP BY ym ORDER BY ym ASC";
$stmt = $pdo->prepare($monSql); $stmt->execute($params);
$rowsByMon = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT id, name FROM consumable_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$faculties  = csm_faculty_list($pdo);

$page_title   = 'รายงานวัสดุสิ้นเปลือง';
$current_page = 'reports';
include __DIR__ . '/../includes/header.php';

// max ยอดเบิก สำหรับวาด bar
$maxFacOut = max(1, ...array_map(fn($r) => (int)$r['qty_out'], $rowsByFac ?: [['qty_out' => 1]]));
$maxItmOut = max(1, ...array_map(fn($r) => (int)$r['qty_out'], $rowsByItm ?: [['qty_out' => 1]]));
$maxMon    = max(1, ...array_map(fn($r) => max((int)$r['qty_in'], (int)$r['qty_out']), $rowsByMon ?: [['qty_in' => 1, 'qty_out' => 1]]));
?>

<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
    <div>
        <h2 class="asset-sec-title">รายงานวัสดุสิ้นเปลือง</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">สรุปการรับเข้า-เบิกออกตามหน่วยงาน · วัสดุ · เดือน + Export Excel</p>
    </div>
    <a href="<?= '?' . http_build_query(array_merge($_GET, ['export' => 'xlsx'])) ?>" class="btn-asset btn-asset-primary">
        <i class="fas fa-file-excel"></i> Export Excel
    </a>
</div>

<form method="get" class="asset-card p-4 mb-4">
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
        <div class="md:col-span-2">
            <label class="asset-label">ตั้งแต่</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="asset-input">
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">ถึง</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="asset-input">
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">ประเภท</label>
            <select name="txn_type" class="asset-input">
                <option value="">— ทั้งหมด —</option>
                <?php foreach (csm_txn_options() as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-3">
            <label class="asset-label">หน่วยงาน/คณะ</label>
            <select name="faculty_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php if (!empty($faculties['department'])): ?>
                    <optgroup label="หน่วยงาน">
                        <?php foreach ($faculties['department'] as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= $filterFac === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['name_th']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if (!empty($faculties['faculty'])): ?>
                    <optgroup label="คณะ">
                        <?php foreach ($faculties['faculty'] as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= $filterFac === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['name_th']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">หมวดหมู่</label>
            <select name="category_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-1 flex items-end">
            <button type="submit" class="btn-asset btn-asset-primary w-full justify-center">
                <i class="fas fa-filter"></i>
            </button>
        </div>
    </div>
</form>

<!-- KPI -->
<div class="asset-kpi-strip mb-4">
    <?php
    $kpis = [
        ['label' => 'รายการ',        'value' => (int)($sum['total_txn']        ?? 0), 'icon' => 'fa-list',                'bg' => '#f0faf4', 'color' => '#2e9e63'],
        ['label' => 'รวมรับเข้า',    'value' => (int)($sum['qty_in']           ?? 0), 'icon' => 'fa-arrow-down-to-line',  'bg' => '#f0fdf4', 'color' => '#16a34a'],
        ['label' => 'รวมเบิกออก',    'value' => (int)($sum['qty_out']          ?? 0), 'icon' => 'fa-hand-holding',        'bg' => '#fef2f2', 'color' => '#dc2626'],
        ['label' => 'วัสดุที่เคลื่อน','value' => (int)($sum['unique_items']     ?? 0), 'icon' => 'fa-box-open',            'bg' => '#eff6ff', 'color' => '#2563eb'],
        ['label' => 'หน่วยงาน',      'value' => (int)($sum['unique_faculties'] ?? 0), 'icon' => 'fa-building',            'bg' => '#fffbeb', 'color' => '#d97706'],
    ];
    foreach ($kpis as $c): ?>
        <div class="asset-kpi-stat">
            <div class="asset-kpi-icon" style="background: <?= $c['bg'] ?>; color: <?= $c['color'] ?>;">
                <i class="fa-solid <?= $c['icon'] ?>"></i>
            </div>
            <div class="min-w-0">
                <div class="asset-kpi-num"><?= number_format($c['value']) ?></div>
                <div class="asset-kpi-label"><?= htmlspecialchars($c['label']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- By faculty -->
    <div class="asset-card p-5">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-building text-[#2e9e63]"></i> Top หน่วยงาน/คณะ ที่เบิกมากสุด</h3>
        <?php if (empty($rowsByFac)): ?>
            <p class="text-center text-slate-400 py-6 text-sm">ไม่มีข้อมูล</p>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($rowsByFac as $r):
                    $pct = (int)round(((int)$r['qty_out'] / $maxFacOut) * 100);
                    $facType = ($r['type'] ?? '') === 'department' ? 'หน่วยงาน' : (($r['type'] ?? '') === 'faculty' ? 'คณะ' : '-');
                ?>
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <div class="font-bold text-slate-700 truncate"><?= htmlspecialchars($r['name']) ?>
                                <span class="text-[10px] text-slate-400 font-normal">· <?= $facType ?></span>
                            </div>
                            <div class="font-bold text-rose-600 shrink-0 ml-2">
                                <?= number_format((int)$r['qty_out']) ?>
                                <span class="text-[10px] text-slate-400 font-normal">(<?= (int)$r['txn_cnt'] ?> ครั้ง)</span>
                            </div>
                        </div>
                        <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-rose-400 to-rose-500" style="width: <?= $pct ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- By item -->
    <div class="asset-card p-5">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-box-open text-[#2e9e63]"></i> Top วัสดุที่เบิกมากสุด</h3>
        <?php if (empty($rowsByItm)): ?>
            <p class="text-center text-slate-400 py-6 text-sm">ไม่มีข้อมูล</p>
        <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($rowsByItm as $r):
                    $pct = (int)round(((int)$r['qty_out'] / $maxItmOut) * 100);
                ?>
                    <div>
                        <div class="flex items-center justify-between text-xs mb-1">
                            <div class="font-bold text-slate-700 truncate">
                                <?= htmlspecialchars($r['name']) ?>
                                <span class="text-[10px] text-slate-400 font-mono font-normal">· <?= htmlspecialchars($r['code']) ?></span>
                            </div>
                            <div class="font-bold text-rose-600 shrink-0 ml-2">
                                <?= number_format((int)$r['qty_out']) ?>
                                <span class="text-[10px] text-slate-400 font-normal"><?= htmlspecialchars($r['unit_piece']) ?></span>
                            </div>
                        </div>
                        <div class="h-2 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-emerald-400 to-emerald-500" style="width: <?= $pct ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Monthly trend -->
<div class="asset-card p-5 mt-4">
    <h3 class="font-bold text-slate-700 mb-4"><i class="fas fa-chart-column text-[#2e9e63]"></i> แนวโน้มรายเดือน (รับเข้า vs เบิกออก)</h3>
    <?php if (empty($rowsByMon)): ?>
        <p class="text-center text-slate-400 py-6 text-sm">ไม่มีข้อมูล</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <div class="flex items-end gap-3 h-48 min-w-fit pb-2">
                <?php foreach ($rowsByMon as $r):
                    $hIn  = max(2, (int)round(((int)$r['qty_in']  / $maxMon) * 100));
                    $hOut = max(2, (int)round(((int)$r['qty_out'] / $maxMon) * 100));
                    $monLabel = date('M y', strtotime($r['ym'] . '-01'));
                ?>
                    <div class="flex flex-col items-center gap-1 min-w-[56px]">
                        <div class="flex items-end gap-1 h-40">
                            <div class="w-5 bg-emerald-400 rounded-t hover:bg-emerald-500 transition" style="height: <?= $hIn ?>%"
                                 title="รับเข้า <?= number_format((int)$r['qty_in']) ?>"></div>
                            <div class="w-5 bg-rose-400 rounded-t hover:bg-rose-500 transition" style="height: <?= $hOut ?>%"
                                 title="เบิกออก <?= number_format((int)$r['qty_out']) ?>"></div>
                        </div>
                        <div class="text-[10px] text-slate-500 font-bold whitespace-nowrap"><?= $monLabel ?></div>
                        <div class="text-[10px] text-emerald-600">+<?= number_format((int)$r['qty_in']) ?></div>
                        <div class="text-[10px] text-rose-600">-<?= number_format((int)$r['qty_out']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="flex items-center gap-4 mt-3 text-xs text-slate-500">
            <span class="flex items-center gap-1"><span class="w-3 h-3 bg-emerald-400 rounded"></span> รับเข้า</span>
            <span class="flex items-center gap-1"><span class="w-3 h-3 bg-rose-400 rounded"></span> เบิกออก</span>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
