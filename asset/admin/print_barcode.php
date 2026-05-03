<?php
// asset/admin/print_barcode.php — พิมพ์บาร์โค้ดป้ายครุภัณฑ์
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
asset_require_manage();

$pdo = db();

// รับ id เดียว หรือหลาย id (?ids=1,2,3) หรือ filter
$ids        = [];
$singleId   = (int)($_GET['id'] ?? 0);
$bulkIds    = (string)($_GET['ids'] ?? '');
$filterLoc  = (int)($_GET['location_id'] ?? 0);
$filterCat  = (int)($_GET['category_id'] ?? 0);

if ($singleId > 0) {
    $ids = [$singleId];
} elseif ($bulkIds !== '') {
    $ids = array_filter(array_map('intval', explode(',', $bulkIds)));
}

$assets    = [];
$showCount = 0;
if (!empty($ids)) {
    $ph     = implode(',', array_fill(0, count($ids), '?'));
    $stmt   = $pdo->prepare("SELECT a.id, a.asset_code, a.name, a.brand, a.serial_number, a.rsu_asset_code, l.name AS location_name
                             FROM assets a LEFT JOIN asset_locations l ON l.id = a.location_id
                             WHERE a.id IN ({$ph}) ORDER BY a.id");
    $stmt->execute($ids);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($filterLoc > 0 || $filterCat > 0) {
    $where = []; $params = [];
    if ($filterLoc > 0) { $where[] = 'a.location_id = ?'; $params[] = $filterLoc; }
    if ($filterCat > 0) { $where[] = 'a.category_id = ?'; $params[] = $filterCat; }
    $sql = "SELECT a.id, a.asset_code, a.name, a.brand, a.serial_number, a.rsu_asset_code, l.name AS location_name
            FROM assets a LEFT JOIN asset_locations l ON l.id = a.location_id
            WHERE " . implode(' AND ', $where) . " ORDER BY a.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$showCount = count($assets);

// เลือกตัวเลือกสำหรับ filter
$locations  = $pdo->query("SELECT id, name FROM asset_locations  WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'พิมพ์บาร์โค้ด';
$current_page = 'assets';
$autoPrint    = !empty($_GET['print']);

// Print mode = ไม่ include header/footer (เปิดแบบ standalone)
if ($autoPrint && !empty($assets)):
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>พิมพ์บาร์โค้ด · <?= $showCount ?> รายการ</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        body { font-family: 'Prompt', sans-serif; margin: 0; padding: 8mm; background: #fff; color: #000; }
        .barcode-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4mm; }
        .barcode-cell {
            border: 1px dashed #999;
            border-radius: 6px;
            padding: 6px 10px;
            text-align: center;
            page-break-inside: avoid;
            display: flex; flex-direction: column; align-items: center;
        }
        .barcode-cell .name { font-size: 11pt; font-weight: 700; margin-bottom: 1mm; line-height: 1.1; max-height: 2.4em; overflow: hidden; }
        .barcode-cell .meta { font-size: 8pt; color: #555; margin-bottom: 2mm; }
        .barcode-cell svg { width: 100%; max-width: 75mm; height: 18mm; }
        .barcode-cell .code { font-size: 9pt; font-weight: 600; margin-top: 1mm; }
        .toolbar { position: fixed; top: 10px; right: 10px; z-index: 99; display: flex; gap: 6px; }
        .toolbar button { padding: 8px 14px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-print  { background: #2e9e63; color: #fff; }
        .btn-back   { background: #e2e8f0; color: #334155; }
        @media print {
            .toolbar { display: none; }
            body { padding: 4mm; }
            .barcode-grid { gap: 2mm; }
            .barcode-cell { border: 1px dashed #aaa; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn-back" onclick="history.back()">← กลับ</button>
        <button class="btn-print" onclick="window.print()">พิมพ์</button>
    </div>
    <div class="barcode-grid">
        <?php foreach ($assets as $i => $a): ?>
            <div class="barcode-cell">
                <div class="name"><?= htmlspecialchars($a['name']) ?></div>
                <div class="meta">
                    <?= htmlspecialchars(trim(($a['brand'] ?? '') . ($a['location_name'] ? ' · ' . $a['location_name'] : ''), ' ·')) ?: '&nbsp;' ?>
                </div>
                <svg id="bc<?= $i ?>"></svg>
                <div class="code"><?= htmlspecialchars($a['asset_code']) ?></div>
                <?php if (!empty($a['rsu_asset_code'])): ?>
                    <div style="font-size: 7.5pt; color: #777;">มรส: <?= htmlspecialchars($a['rsu_asset_code']) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <script>
        const codes = <?= json_encode(array_map(fn($a) => ['idx' => array_search($a, $assets), 'code' => $a['asset_code']], $assets)) ?>;
        document.querySelectorAll('svg[id^="bc"]').forEach((svg, i) => {
            const code = svg.id.replace('bc', '');
            const item = <?= json_encode(array_values(array_map(fn($a) => $a['asset_code'], $assets))) ?>[parseInt(code)];
            JsBarcode(svg, item, { format: 'CODE128', height: 50, displayValue: false, margin: 2 });
        });
        window.addEventListener('load', () => setTimeout(() => window.print(), 600));
    </script>
</body>
</html>
<?php
exit;
endif;

include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4">
    <div>
        <h2 class="asset-sec-title">พิมพ์บาร์โค้ดป้ายครุภัณฑ์</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">เลือกครุภัณฑ์ตาม ID หรือกรองตามจุด/หมวด แล้วพิมพ์ป้ายติดเครื่อง</p>
    </div>
    <a href="manage_assets.php" class="btn-asset btn-asset-ghost"><i class="fas fa-arrow-left"></i> กลับ</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="asset-card p-5">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-list text-[#2e9e63]"></i> เลือกตาม ID</h3>
        <form method="get" class="space-y-3">
            <div>
                <label class="asset-label">รหัส ID (คั่นด้วย comma เช่น 1,2,3)</label>
                <input type="text" name="ids" class="asset-input" placeholder="เช่น 1,5,12">
            </div>
            <button type="submit" class="btn-asset btn-asset-secondary">
                <i class="fas fa-eye"></i> แสดงรายการ
            </button>
        </form>
    </div>

    <div class="asset-card p-5">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-filter text-[#2e9e63]"></i> เลือกตาม Filter</h3>
        <form method="get" class="space-y-3">
            <div>
                <label class="asset-label">จุดใช้งาน</label>
                <select name="location_id" class="asset-input">
                    <option value="0">— ทั้งหมด —</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= $filterLoc === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="asset-label">หมวดหมู่</label>
                <select name="category_id" class="asset-input">
                    <option value="0">— ทั้งหมด —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-asset btn-asset-secondary">
                <i class="fas fa-eye"></i> แสดงรายการ
            </button>
        </form>
    </div>
</div>

<?php if (!empty($assets)): ?>
    <div class="asset-card p-5 mt-4">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
            <h3 class="font-bold text-slate-700">
                <i class="fas fa-barcode text-[#2e9e63]"></i> พบ <?= number_format($showCount) ?> รายการ
            </h3>
            <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI']) . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') ?>print=1" target="_blank"
               class="btn-asset btn-asset-primary">
                <i class="fas fa-print"></i> เปิดหน้าพิมพ์ (<?= $showCount ?> ป้าย)
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="asset-table">
                <thead><tr>
                    <th>#</th><th>รหัส</th><th>ชื่อ</th><th>ยี่ห้อ</th><th>จุดใช้งาน</th>
                </tr></thead>
                <tbody>
                <?php foreach ($assets as $i => $a): ?>
                    <tr>
                        <td data-label="#"><?= $i + 1 ?></td>
                        <td data-label="รหัส" class="font-mono text-xs text-[#2e9e63] font-bold"><?= htmlspecialchars($a['asset_code']) ?></td>
                        <td data-label="ชื่อ"><strong><?= htmlspecialchars($a['name']) ?></strong></td>
                        <td data-label="ยี่ห้อ"><?= htmlspecialchars($a['brand'] ?? '-') ?></td>
                        <td data-label="จุดใช้งาน"><?= htmlspecialchars($a['location_name'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
