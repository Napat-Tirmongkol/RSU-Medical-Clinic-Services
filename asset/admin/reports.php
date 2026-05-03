<?php
// asset/admin/reports.php — รายงานครุภัณฑ์ + Export Excel
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
asset_require_manage();

$pdo = db();

$filterLoc  = (int)($_GET['location_id'] ?? 0);
$filterCat  = (int)($_GET['category_id'] ?? 0);
$filterStat = (string)($_GET['status'] ?? '');
$dateFrom   = (string)($_GET['date_from'] ?? '');
$dateTo     = (string)($_GET['date_to'] ?? '');

$where  = []; $params = [];
if ($filterLoc > 0)  { $where[] = 'a.location_id = ?'; $params[] = $filterLoc; }
if ($filterCat > 0)  { $where[] = 'a.category_id = ?'; $params[] = $filterCat; }
if ($filterStat !== '' && array_key_exists($filterStat, asset_status_options())) {
    $where[] = 'a.status = ?'; $params[] = $filterStat;
}
if ($dateFrom !== '') { $where[] = 'a.purchase_date >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'a.purchase_date <= ?'; $params[] = $dateTo; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Export Excel ─────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $sql = "SELECT a.asset_code, a.rsu_asset_code, a.serial_number, a.name, a.brand, a.quantity,
                   a.purchase_date, a.warranty_text, a.warranty_until, a.vendor,
                   c.name AS category_name, l.name AS location_name, a.status, a.note, a.imported_at
            FROM assets a
            LEFT JOIN asset_categories c ON c.id = a.category_id
            LEFT JOIN asset_locations  l ON l.id = a.location_id
            {$whereSql}
            ORDER BY a.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusLabels = asset_status_options();

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Assets');

    $headers = ['รหัสครุภัณฑ์','S/N มรส','S/N','ชื่อ','ยี่ห้อ','จำนวน','วันที่ซื้อ','การรับประกัน','สิ้นสุดประกัน','บริษัท','หมวดหมู่','จุดใช้งาน','สถานะ','หมายเหตุ','นำเข้าเมื่อ'];
    $col = 'A';
    foreach ($headers as $h) { $sheet->setCellValue($col . '1', $h); $col++; }
    $sheet->getStyle('A1:O1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2E9E63']],
        'alignment' => ['horizontal' => 'center'],
    ]);
    $r = 2;
    foreach ($rows as $row) {
        $sheet->setCellValue("A{$r}", $row['asset_code']);
        $sheet->setCellValue("B{$r}", $row['rsu_asset_code']);
        $sheet->setCellValue("C{$r}", $row['serial_number']);
        $sheet->setCellValue("D{$r}", $row['name']);
        $sheet->setCellValue("E{$r}", $row['brand']);
        $sheet->setCellValue("F{$r}", (int)$row['quantity']);
        $sheet->setCellValue("G{$r}", $row['purchase_date']);
        $sheet->setCellValue("H{$r}", $row['warranty_text']);
        $sheet->setCellValue("I{$r}", $row['warranty_until']);
        $sheet->setCellValue("J{$r}", $row['vendor']);
        $sheet->setCellValue("K{$r}", $row['category_name']);
        $sheet->setCellValue("L{$r}", $row['location_name']);
        $sheet->setCellValue("M{$r}", $statusLabels[$row['status']] ?? $row['status']);
        $sheet->setCellValue("N{$r}", $row['note']);
        $sheet->setCellValue("O{$r}", $row['imported_at']);
        $r++;
    }
    foreach (range('A', 'O') as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
    $sheet->freezePane('A2');

    $filename = 'assets_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// ── สรุปสำหรับหน้าเว็บ ───────────────────────────────────────────────────
$summary = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(quantity), 0) AS total_qty,
        SUM(CASE WHEN status='in_use'   THEN 1 ELSE 0 END) AS cnt_in_use,
        SUM(CASE WHEN status='repair'   THEN 1 ELSE 0 END) AS cnt_repair,
        SUM(CASE WHEN status='reserve'  THEN 1 ELSE 0 END) AS cnt_reserve,
        SUM(CASE WHEN status='disposed' THEN 1 ELSE 0 END) AS cnt_disposed,
        SUM(CASE WHEN status='lost'     THEN 1 ELSE 0 END) AS cnt_lost
    FROM assets a {$whereSql}
");
$summary->execute($params);
$sum = $summary->fetch(PDO::FETCH_ASSOC) ?: [];

$byLocation = $pdo->prepare("
    SELECT COALESCE(l.name, '— ไม่ระบุ —') AS name, COUNT(*) AS cnt, COALESCE(SUM(a.quantity), 0) AS qty
    FROM assets a LEFT JOIN asset_locations l ON l.id = a.location_id
    {$whereSql}
    GROUP BY a.location_id ORDER BY cnt DESC
");
$byLocation->execute($params);
$rowsByLoc = $byLocation->fetchAll(PDO::FETCH_ASSOC);

$byCategory = $pdo->prepare("
    SELECT COALESCE(c.name, '— ไม่ระบุ —') AS name, COUNT(*) AS cnt, COALESCE(SUM(a.quantity), 0) AS qty
    FROM assets a LEFT JOIN asset_categories c ON c.id = a.category_id
    {$whereSql}
    GROUP BY a.category_id ORDER BY cnt DESC
");
$byCategory->execute($params);
$rowsByCat = $byCategory->fetchAll(PDO::FETCH_ASSOC);

$locations  = $pdo->query("SELECT id, name FROM asset_locations  WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'รายงานครุภัณฑ์';
$current_page = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4 flex-wrap gap-3">
    <div>
        <h2 class="asset-sec-title">รายงานครุภัณฑ์</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">สรุปจำนวนตามจุดใช้งาน หมวดหมู่ และสถานะ พร้อม Export Excel</p>
    </div>
    <a href="<?= '?' . http_build_query(array_merge($_GET, ['export' => 'xlsx'])) ?>" class="btn-asset btn-asset-primary">
        <i class="fas fa-file-excel"></i> Export Excel
    </a>
</div>

<form method="get" class="asset-card p-4 mb-4">
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
        <div class="md:col-span-3">
            <label class="asset-label">จุดใช้งาน</label>
            <select name="location_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $filterLoc === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-3">
            <label class="asset-label">หมวดหมู่</label>
            <select name="category_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">สถานะ</label>
            <select name="status" class="asset-input">
                <option value="">— ทั้งหมด —</option>
                <?php foreach (asset_status_options() as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $filterStat === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">ซื้อตั้งแต่</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="asset-input">
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">ถึง</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="asset-input">
        </div>
        <div class="md:col-span-12 flex items-center gap-2 pt-2">
            <button type="submit" class="btn-asset btn-asset-primary">
                <i class="fas fa-filter"></i> ค้นหา
            </button>
            <a href="admin/reports.php" class="btn-asset btn-asset-ghost">ล้าง</a>
        </div>
    </div>
</form>

<div class="asset-kpi-strip mb-4">
    <?php
    $kpis = [
        ['label' => 'รายการ',      'value' => (int)($sum['total'] ?? 0),        'icon' => 'fa-boxes-stacked',     'bg' => '#f0faf4', 'color' => '#2e9e63'],
        ['label' => 'จำนวนรวม',    'value' => (int)($sum['total_qty'] ?? 0),    'icon' => 'fa-cubes',             'bg' => '#eff6ff', 'color' => '#2563eb'],
        ['label' => 'ใช้งาน',      'value' => (int)($sum['cnt_in_use'] ?? 0),   'icon' => 'fa-circle-check',      'bg' => '#f0fdf4', 'color' => '#16a34a'],
        ['label' => 'ซ่อม',        'value' => (int)($sum['cnt_repair'] ?? 0),   'icon' => 'fa-screwdriver-wrench','bg' => '#fffbeb', 'color' => '#d97706'],
        ['label' => 'จำหน่าย/สูญหาย','value' => (int)(($sum['cnt_disposed'] ?? 0) + ($sum['cnt_lost'] ?? 0)), 'icon' => 'fa-circle-xmark', 'bg' => '#fef2f2', 'color' => '#dc2626'],
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
    <div class="asset-card p-5">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-location-dot text-[#2e9e63]"></i> สรุปตามจุดใช้งาน</h3>
        <?php if (empty($rowsByLoc)): ?>
            <p class="text-center text-slate-400 py-6 text-sm">ไม่มีข้อมูล</p>
        <?php else: ?>
            <table class="asset-table">
                <thead><tr><th>จุดใช้งาน</th><th class="text-center">รายการ</th><th class="text-center">จำนวนรวม</th></tr></thead>
                <tbody>
                <?php foreach ($rowsByLoc as $r): ?>
                    <tr>
                        <td data-label="จุดใช้งาน"><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                        <td data-label="รายการ" class="text-center font-bold"><?= number_format($r['cnt']) ?></td>
                        <td data-label="จำนวนรวม" class="text-center"><?= number_format($r['qty']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="asset-card p-5">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-tags text-[#2e9e63]"></i> สรุปตามหมวดหมู่</h3>
        <?php if (empty($rowsByCat)): ?>
            <p class="text-center text-slate-400 py-6 text-sm">ไม่มีข้อมูล</p>
        <?php else: ?>
            <table class="asset-table">
                <thead><tr><th>หมวดหมู่</th><th class="text-center">รายการ</th><th class="text-center">จำนวนรวม</th></tr></thead>
                <tbody>
                <?php foreach ($rowsByCat as $r): ?>
                    <tr>
                        <td data-label="หมวดหมู่"><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                        <td data-label="รายการ" class="text-center font-bold"><?= number_format($r['cnt']) ?></td>
                        <td data-label="จำนวนรวม" class="text-center"><?= number_format($r['qty']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
