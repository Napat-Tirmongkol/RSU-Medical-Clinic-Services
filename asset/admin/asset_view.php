<?php
// asset/admin/asset_view.php — ดูรายละเอียดครุภัณฑ์ + ประวัติการเปลี่ยนแปลง
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: manage_assets.php'); exit; }

$stmt = $pdo->prepare("
    SELECT a.*, c.name AS category_name, l.name AS location_name
    FROM assets a
    LEFT JOIN asset_categories c ON c.id = a.category_id
    LEFT JOIN asset_locations  l ON l.id = a.location_id
    WHERE a.id = ?
");
$stmt->execute([$id]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$a) { header('Location: manage_assets.php'); exit; }

$mvStmt = $pdo->prepare("
    SELECT m.*, lf.name AS from_loc, lt.name AS to_loc
    FROM asset_movements m
    LEFT JOIN asset_locations lf ON lf.id = m.from_location_id
    LEFT JOIN asset_locations lt ON lt.id = m.to_location_id
    WHERE m.asset_id = ?
    ORDER BY m.id DESC LIMIT 50
");
$mvStmt->execute([$id]);
$movements = $mvStmt->fetchAll(PDO::FETCH_ASSOC);

$st = asset_status_label($a['status']);
$canManage    = asset_can_manage();
$page_title   = 'รายละเอียด: ' . $a['name'];
$current_page = 'assets';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4">
    <div>
        <div class="text-xs font-mono text-indigo-600 font-bold"><?= htmlspecialchars($a['asset_code']) ?></div>
        <h2 class="text-xl font-extrabold text-slate-800"><?= htmlspecialchars($a['name']) ?></h2>
    </div>
    <div class="flex items-center gap-2">
        <a href="manage_assets.php" class="btn-asset btn-asset-ghost"><i class="fas fa-arrow-left"></i> กลับ</a>
        <button type="button" class="btn-asset btn-asset-secondary" onclick="assetQuickStatus(<?= (int)$a['id'] ?>, '<?= htmlspecialchars($a['status']) ?>')">
            <i class="fas fa-arrow-right-arrow-left"></i> เปลี่ยนสถานะ
        </button>
        <?php if ($canManage): ?>
            <a href="asset_form.php?id=<?= (int)$a['id'] ?>" class="btn-asset btn-asset-primary">
                <i class="fas fa-edit"></i> แก้ไข
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="asset-card p-5 lg:col-span-1">
        <?php if (!empty($a['image'])): ?>
            <img src="../<?= htmlspecialchars($a['image']) ?>" alt="" class="w-full aspect-square object-cover rounded-xl border border-slate-200">
        <?php else: ?>
            <div class="w-full aspect-square rounded-xl bg-slate-100 flex items-center justify-center text-slate-300">
                <i class="fas fa-image text-5xl"></i>
            </div>
        <?php endif; ?>
        <div class="mt-3 flex items-center justify-between">
            <span class="badge-status <?= $st['class'] ?> text-sm py-1.5 px-3"><?= $st['label'] ?></span>
            <span class="text-sm text-slate-600">จำนวน: <strong><?= (int)$a['quantity'] ?></strong></span>
        </div>
    </div>

    <div class="asset-card p-5 lg:col-span-2">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-circle-info text-indigo-500"></i> ข้อมูลทั่วไป</h3>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <?php
            $pairs = [
                'ยี่ห้อ'                     => $a['brand'],
                'หมายเลขเครื่อง S/N'         => $a['serial_number'],
                'S/N ฝ่ายจัดซื้อพัสดุ มรส'   => $a['rsu_asset_code'],
                'บริษัทที่ซื้อ'              => $a['vendor'],
                'วันที่ซื้อ'                 => !empty($a['purchase_date']) ? date('d/m/Y', strtotime($a['purchase_date'])) : null,
                'การรับประกัน'               => $a['warranty_text'],
                'วันสิ้นสุดประกัน'           => !empty($a['warranty_until']) ? date('d/m/Y', strtotime($a['warranty_until'])) : null,
                'หมวดหมู่'                   => $a['category_name'],
                'จุดใช้งาน'                  => $a['location_name'],
                'นำเข้าระบบเมื่อ'            => !empty($a['imported_at']) ? date('d/m/Y H:i', strtotime($a['imported_at'])) : null,
            ];
            foreach ($pairs as $k => $v):
                if ($v === null || $v === '') continue; ?>
                <div>
                    <dt class="text-xs text-slate-500 font-semibold mb-0.5"><?= htmlspecialchars($k) ?></dt>
                    <dd class="text-slate-800"><?= htmlspecialchars((string)$v) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <?php if (!empty($a['note'])): ?>
            <div class="mt-4 p-3 rounded-xl bg-slate-50 border border-slate-200">
                <div class="text-xs text-slate-500 font-semibold mb-1">หมายเหตุ</div>
                <div class="text-sm text-slate-700 whitespace-pre-line"><?= htmlspecialchars($a['note']) ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="asset-card p-5 mt-4">
    <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-clock-rotate-left text-indigo-500"></i> ประวัติการเปลี่ยนแปลง</h3>
    <?php if (empty($movements)): ?>
        <p class="text-center text-slate-400 py-6 text-sm">ยังไม่มีประวัติ</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="asset-table">
                <thead><tr>
                    <th>วันที่</th><th>การกระทำ</th><th>สถานะ</th><th>จุดใช้งาน</th><th>เหตุผล</th>
                </tr></thead>
                <tbody>
                <?php foreach ($movements as $m):
                    $actLabel = ['create' => 'สร้างใหม่', 'move' => 'ย้าย', 'status_change' => 'เปลี่ยนสถานะ', 'update' => 'แก้ไข', 'delete' => 'ลบ'][$m['action']] ?? $m['action'];
                ?>
                    <tr>
                        <td data-label="วันที่" class="text-xs text-slate-500"><?= date('d/m/Y H:i', strtotime($m['moved_at'])) ?></td>
                        <td data-label="การกระทำ"><span class="badge-status bg-indigo-100 text-indigo-700 ring-1 ring-indigo-200"><?= htmlspecialchars($actLabel) ?></span></td>
                        <td data-label="สถานะ" class="text-sm">
                            <?= htmlspecialchars($m['from_status'] ?? '-') ?> → <strong><?= htmlspecialchars($m['to_status'] ?? '-') ?></strong>
                        </td>
                        <td data-label="จุดใช้งาน" class="text-sm">
                            <?= htmlspecialchars($m['from_loc'] ?? '-') ?> → <strong><?= htmlspecialchars($m['to_loc'] ?? '-') ?></strong>
                        </td>
                        <td data-label="เหตุผล" class="text-sm text-slate-600"><?= htmlspecialchars($m['reason'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
