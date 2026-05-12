<?php
// consumables/admin/manage_consumables.php — รายการ/ค้นหา/กรอง pagination 20/หน้า
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

$search     = trim((string)($_GET['search']      ?? ''));
$filterCat  = (int)   ($_GET['category_id']      ?? 0);
$filterLoc  = (int)   ($_GET['location_id']      ?? 0);
$filterStat = (string)($_GET['status']           ?? '');
$lowOnly    = !empty($_GET['low_only']);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(c.name LIKE ? OR c.brand LIKE ? OR c.code LIKE ? OR c.note LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
}
if ($filterCat > 0) {
    $where[]  = 'c.category_id = ?';
    $params[] = $filterCat;
}
if ($filterLoc > 0) {
    $where[]  = 'c.location_id = ?';
    $params[] = $filterLoc;
}
if ($filterStat !== '' && array_key_exists($filterStat, csm_status_options())) {
    $where[]  = 'c.status = ?';
    $params[] = $filterStat;
}
if ($lowOnly) {
    $where[] = 'c.min_stock > 0 AND c.qty_on_hand <= c.min_stock';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM consumables c {$whereSql}");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();
    $totalPages = (int)max(1, ceil($total / $perPage));

    $sql = "SELECT c.id, c.code, c.name, c.brand, c.unit_pack, c.unit_piece, c.pack_size,
                   c.qty_on_hand, c.min_stock, c.image, c.status,
                   cat.name AS category_name, l.name AS location_name
            FROM consumables c
            LEFT JOIN consumable_categories cat ON cat.id = c.category_id
            LEFT JOIN asset_locations       l   ON l.id   = c.location_id
            {$whereSql}
            ORDER BY c.id DESC
            LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = $pdo->query("SELECT id, name FROM consumable_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $locations  = $pdo->query("SELECT id, name FROM asset_locations WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = []; $total = 0; $totalPages = 1; $categories = []; $locations = [];
    $error = 'โหลดข้อมูลไม่สำเร็จ — กรุณารัน migration ก่อน (database/migrations/migrate_consumable_module.php). รายละเอียด: ' . $e->getMessage();
}

$canManage    = csm_can_manage();
$page_title   = 'รายการวัสดุสิ้นเปลือง';
$current_page = 'consumables';
$extraQuery   = array_filter([
    'search'      => $search,
    'category_id' => $filterCat ?: null,
    'location_id' => $filterLoc ?: null,
    'status'      => $filterStat,
    'low_only'    => $lowOnly ? 1 : null,
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
        <h2 class="asset-sec-title">รายการวัสดุสิ้นเปลือง</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">บันทึกสต็อก รับเข้า เบิกออก ตามหน่วยงาน</p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <a href="admin/receive_form.php" class="btn-asset btn-asset-secondary">
            <i class="fas fa-download"></i> รับเข้า
        </a>
        <a href="admin/issue_form.php" class="btn-asset btn-asset-secondary">
            <i class="fas fa-hand-holding"></i> เบิกออก
        </a>
        <?php if ($canManage): ?>
            <a href="admin/import_consumables.php" class="btn-asset btn-asset-secondary">
                <i class="fas fa-file-import"></i> Import
            </a>
            <a href="admin/consumable_form.php" class="btn-asset btn-asset-primary">
                <i class="fas fa-plus"></i> เพิ่มวัสดุ
            </a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="asset-card p-4 mb-4">
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
        <div class="md:col-span-5">
            <label class="asset-label">ค้นหา</label>
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="ชื่อ / ยี่ห้อ / รหัส"
                       class="asset-input pl-9">
            </div>
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
            <label class="asset-label">จุดจัดเก็บ</label>
            <select name="location_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $filterLoc === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">สถานะ</label>
            <select name="status" class="asset-input">
                <option value="">— ทั้งหมด —</option>
                <?php foreach (csm_status_options() as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $filterStat === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-1 flex items-end">
            <button type="submit" class="btn-asset btn-asset-primary w-full justify-center">
                <i class="fas fa-filter"></i>
            </button>
        </div>
    </div>
    <div class="mt-3 flex items-center gap-3 text-xs">
        <label class="inline-flex items-center gap-2 text-slate-600 cursor-pointer">
            <input type="checkbox" name="low_only" value="1" <?= $lowOnly ? 'checked' : '' ?>
                   onchange="this.form.submit()" class="rounded border-slate-300">
            <span>เฉพาะที่ใกล้หมด</span>
        </label>
        <?php if ($search || $filterStat || $filterLoc || $filterCat || $lowOnly): ?>
            <a href="admin/manage_consumables.php" class="text-slate-500 hover:text-[#2e9e63]">
                <i class="fas fa-times-circle"></i> ล้างตัวกรอง
            </a>
        <?php endif; ?>
    </div>
</form>

<div class="asset-card p-2 sm:p-4">
    <div class="overflow-x-auto">
        <table class="asset-table">
            <thead>
                <tr>
                    <th style="width:50px"></th>
                    <th>รหัส</th>
                    <th>ชื่อ / ยี่ห้อ</th>
                    <th>หมวดหมู่</th>
                    <th>จุดจัดเก็บ</th>
                    <th class="text-center">บรรจุภัณฑ์</th>
                    <th class="text-center">คงเหลือ</th>
                    <th class="text-center" style="width:200px">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-slate-400 py-12 text-sm">
                        <i class="fas fa-folder-open text-3xl text-slate-300 mb-2 block"></i>
                        ไม่พบรายการตรงกับเงื่อนไข
                    </td></tr>
                <?php else: foreach ($rows as $r):
                    $isLow = ((int)$r['min_stock'] > 0 && (int)$r['qty_on_hand'] <= (int)$r['min_stock']);
                    $isOut = ((int)$r['qty_on_hand'] <= 0);
                    $qtyClass = $isOut ? 'text-rose-600' : ($isLow ? 'text-amber-600' : 'text-emerald-700');
                ?>
                    <tr>
                        <td data-label="รูป">
                            <?php if (!empty($r['image'])): ?>
                                <img src="<?= htmlspecialchars($r['image']) ?>" alt="" class="asset-thumb">
                            <?php else: ?>
                                <div class="asset-thumb flex items-center justify-center text-slate-300"><i class="fas fa-box-open"></i></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="รหัส">
                            <div class="font-mono text-xs text-[#2e9e63] font-bold"><?= htmlspecialchars($r['code']) ?></div>
                        </td>
                        <td data-label="ชื่อ">
                            <div class="font-bold text-slate-800"><?= htmlspecialchars($r['name']) ?></div>
                            <?php if (!empty($r['brand'])): ?>
                                <div class="text-xs text-slate-500"><?= htmlspecialchars($r['brand']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="หมวดหมู่" class="text-sm text-slate-600">
                            <?= htmlspecialchars($r['category_name'] ?? '-') ?>
                        </td>
                        <td data-label="จุดจัดเก็บ" class="text-sm text-slate-600">
                            <?= htmlspecialchars($r['location_name'] ?? '-') ?>
                        </td>
                        <td data-label="บรรจุภัณฑ์" class="text-center text-xs text-slate-600">
                            <?php if ((int)$r['pack_size'] > 1): ?>
                                1 <?= htmlspecialchars($r['unit_pack']) ?> = <?= (int)$r['pack_size'] ?> <?= htmlspecialchars($r['unit_piece']) ?>
                            <?php else: ?>
                                <?= htmlspecialchars($r['unit_piece']) ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="คงเหลือ" class="text-center">
                            <div class="font-extrabold <?= $qtyClass ?>"><?= number_format((int)$r['qty_on_hand']) ?>
                                <span class="text-xs font-normal text-slate-500"><?= htmlspecialchars($r['unit_piece']) ?></span>
                            </div>
                            <?php if ($isLow): ?>
                                <div class="text-[10px] text-amber-600 mt-0.5"><i class="fa-solid fa-triangle-exclamation"></i> ใกล้หมด (ต่ำกว่า <?= (int)$r['min_stock'] ?>)</div>
                            <?php endif; ?>
                        </td>
                        <td data-label="จัดการ">
                            <div class="flex items-center gap-1 justify-center flex-wrap">
                                <a href="admin/consumable_view.php?id=<?= (int)$r['id'] ?>" class="btn-asset btn-asset-ghost" title="ดู">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="admin/issue_form.php?consumable_id=<?= (int)$r['id'] ?>" class="btn-asset btn-asset-secondary" title="เบิก">
                                    <i class="fas fa-hand-holding"></i>
                                </a>
                                <a href="admin/receive_form.php?consumable_id=<?= (int)$r['id'] ?>" class="btn-asset btn-asset-secondary" title="รับเข้า">
                                    <i class="fas fa-download"></i>
                                </a>
                                <?php if ($canManage): ?>
                                    <a href="admin/consumable_form.php?id=<?= (int)$r['id'] ?>" class="btn-asset btn-asset-secondary" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="btn-asset btn-asset-danger" title="ลบ"
                                            onclick="csmConfirmDelete('ajax/delete_consumable.php?id=<?= (int)$r['id'] ?>', '<?= htmlspecialchars(addslashes($r['name'])) ?>')">
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

    <?= csm_pagination_html($page, $totalPages, $total, $extraQuery) ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
