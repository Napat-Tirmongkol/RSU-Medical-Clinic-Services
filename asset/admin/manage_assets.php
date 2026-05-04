<?php
// asset/admin/manage_assets.php — รายการ/ค้นหา/กรอง พร้อม pagination 20/หน้า
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

// ── Filter & Search params ────────────────────────────────────────────────
$search      = trim((string)($_GET['search']      ?? ''));
$filterStat  = (string)($_GET['status']           ?? '');
$filterLoc   = (int)   ($_GET['location_id']      ?? 0);
$filterCat   = (int)   ($_GET['category_id']      ?? 0);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20; // default ตาม CLAUDE.md
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(a.name LIKE ? OR a.brand LIKE ? OR a.serial_number LIKE ? OR a.rsu_asset_code LIKE ? OR a.asset_code LIKE ? OR a.vendor LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filterStat !== '' && array_key_exists($filterStat, asset_status_options())) {
    $where[]  = 'a.status = ?';
    $params[] = $filterStat;
}
if ($filterLoc > 0) {
    $where[]  = 'a.location_id = ?';
    $params[] = $filterLoc;
}
if ($filterCat > 0) {
    $where[]  = 'a.category_id = ?';
    $params[] = $filterCat;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Count + fetch ─────────────────────────────────────────────────────────
try {
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM assets a {$whereSql}");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    $totalPages = (int)max(1, ceil($total / $perPage));

    $sql = "SELECT a.id, a.asset_code, a.rsu_asset_code, a.serial_number, a.name, a.brand,
                   a.quantity, a.purchase_date, a.warranty_text, a.image, a.status,
                   c.name AS category_name, l.name AS location_name
            FROM assets a
            LEFT JOIN asset_categories c ON c.id = a.category_id
            LEFT JOIN asset_locations  l ON l.id = a.location_id
            {$whereSql}
            ORDER BY a.id DESC
            LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $locations  = $pdo->query("SELECT id, name FROM asset_locations  WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $categories = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = []; $total = 0; $totalPages = 1; $locations = []; $categories = [];
    $error = 'โหลดข้อมูลไม่สำเร็จ — กรุณารัน migration ก่อน (database/migrations/migrate_asset_module.php). รายละเอียด: ' . $e->getMessage();
}

$canManage    = asset_can_manage();
$page_title   = 'จัดการครุภัณฑ์';
$current_page = 'assets';
$extraQuery   = array_filter([
    'search'      => $search,
    'status'      => $filterStat,
    'location_id' => $filterLoc ?: null,
    'category_id' => $filterCat ?: null,
], fn($v) => $v !== null && $v !== '' && $v !== 0);

include __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($error)): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
    <div>
        <h2 class="asset-sec-title">รายการครุภัณฑ์</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">ทะเบียนครุภัณฑ์สำนักงานทั้งหมด</p>
    </div>
    <?php if ($canManage): ?>
        <div class="flex items-center gap-2">
            <a href="admin/import_assets.php" class="btn-asset btn-asset-secondary">
                <i class="fas fa-file-import"></i> Import Excel
            </a>
            <a href="admin/asset_form.php" class="btn-asset btn-asset-primary">
                <i class="fas fa-plus"></i> เพิ่มครุภัณฑ์
            </a>
        </div>
    <?php endif; ?>
</div>

<form method="get" class="asset-card p-4 mb-4">
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
        <div class="md:col-span-5">
            <label class="asset-label">ค้นหา</label>
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="ชื่อ / ยี่ห้อ / S/N / รหัส / บริษัท"
                       class="asset-input pl-9">
            </div>
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
            <label class="asset-label">หมวดหมู่</label>
            <select name="category_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterCat === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">จุดใช้งาน</label>
            <select name="location_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $filterLoc === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-1 flex items-end">
            <button type="submit" class="btn-asset btn-asset-primary w-full justify-center">
                <i class="fas fa-filter"></i> ค้นหา
            </button>
        </div>
    </div>
    <?php if ($search || $filterStat || $filterLoc || $filterCat): ?>
        <div class="mt-3">
            <a href="admin/manage_assets.php" class="text-xs text-slate-500 hover:text-[#2e9e63]">
                <i class="fas fa-times-circle"></i> ล้างตัวกรอง
            </a>
        </div>
    <?php endif; ?>
</form>

<div class="asset-card p-2 sm:p-4">
    <div class="overflow-x-auto">
        <table class="asset-table">
            <thead>
                <tr>
                    <th style="width:50px"></th>
                    <th>รหัส / S/N</th>
                    <th>ชื่อ / ยี่ห้อ</th>
                    <th class="text-center">จำนวน</th>
                    <th>จุดใช้งาน</th>
                    <th>สถานะ</th>
                    <th>วันที่ซื้อ</th>
                    <th class="text-center" style="width:160px">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-slate-400 py-12 text-sm">
                        <i class="fas fa-folder-open text-3xl text-slate-300 mb-2 block"></i>
                        ไม่พบรายการตรงกับเงื่อนไข
                    </td></tr>
                <?php else: foreach ($rows as $r):
                    $st = asset_status_label($r['status']); ?>
                    <tr>
                        <td data-label="รูป">
                            <?php if (!empty($r['image'])): ?>
                                <img src="<?= htmlspecialchars($r['image']) ?>" alt="" class="asset-thumb">
                            <?php else: ?>
                                <div class="asset-thumb flex items-center justify-center text-slate-300"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="รหัส / S/N">
                            <div class="font-mono text-xs text-[#2e9e63] font-bold"><?= htmlspecialchars($r['asset_code']) ?></div>
                            <?php if (!empty($r['rsu_asset_code'])): ?>
                                <div class="text-[11px] text-slate-500">มรส: <?= htmlspecialchars($r['rsu_asset_code']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($r['serial_number'])): ?>
                                <div class="text-[11px] text-slate-500">S/N: <?= htmlspecialchars($r['serial_number']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="ชื่อ">
                            <div class="font-bold text-slate-800"><?= htmlspecialchars($r['name']) ?></div>
                            <?php if (!empty($r['brand'])): ?>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars($r['brand']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="จำนวน" class="text-center font-bold"><?= (int)$r['quantity'] ?></td>
                        <td data-label="จุดใช้งาน">
                            <span class="text-sm"><?= htmlspecialchars($r['location_name'] ?? '-') ?></span>
                        </td>
                        <td data-label="สถานะ">
                            <span class="badge-status <?= $st['class'] ?>"><?= $st['label'] ?></span>
                        </td>
                        <td data-label="วันที่ซื้อ" class="text-xs text-slate-500">
                            <?= !empty($r['purchase_date']) ? date('d/m/Y', strtotime($r['purchase_date'])) : '-' ?>
                        </td>
                        <td data-label="จัดการ">
                            <div class="flex items-center gap-1 justify-center flex-wrap">
                                <a href="admin/asset_view.php?id=<?= (int)$r['id'] ?>" class="btn-asset btn-asset-ghost" title="ดูรายละเอียด">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button" class="btn-asset btn-asset-secondary" title="เปลี่ยนสถานะ"
                                        onclick="assetQuickStatus(<?= (int)$r['id'] ?>, '<?= htmlspecialchars($r['status']) ?>')">
                                    <i class="fas fa-arrow-right-arrow-left"></i>
                                </button>
                                <?php if ($canManage): ?>
                                    <a href="admin/asset_form.php?id=<?= (int)$r['id'] ?>" class="btn-asset btn-asset-secondary" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="btn-asset btn-asset-danger" title="ลบ"
                                            onclick="assetConfirmDelete('ajax/delete_asset.php?id=<?= (int)$r['id'] ?>', '<?= htmlspecialchars(addslashes($r['name'])) ?>')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?= asset_pagination_html($page, $totalPages, $total, $extraQuery) ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
