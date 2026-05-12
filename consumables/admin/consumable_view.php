<?php
// consumables/admin/consumable_view.php — รายละเอียด + ประวัติการเคลื่อนไหว
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ระบุ id ไม่ถูกต้อง'); }

$stmt = $pdo->prepare("SELECT c.*, cat.name AS category_name, l.name AS location_name
    FROM consumables c
    LEFT JOIN consumable_categories cat ON cat.id = c.category_id
    LEFT JOIN asset_locations       l   ON l.id   = c.location_id
    WHERE c.id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) { http_response_code(404); exit('ไม่พบรายการ'); }

// ประวัติ pagination 20/หน้า
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$total = (int)$pdo->query("SELECT COUNT(*) FROM consumable_transactions WHERE consumable_id = {$id}")->fetchColumn();
$totalPages = (int)max(1, ceil($total / $perPage));

$tStmt = $pdo->prepare("
    SELECT t.*, f.name_th AS faculty_name, f.type AS faculty_type, s.full_name AS created_by_name
    FROM consumable_transactions t
    LEFT JOIN sys_faculties f ON f.id = t.faculty_id
    LEFT JOIN sys_staff     s ON s.id = t.created_by
    WHERE t.consumable_id = ?
    ORDER BY t.id DESC
    LIMIT {$perPage} OFFSET {$offset}");
$tStmt->execute([$id]);
$txns = $tStmt->fetchAll(PDO::FETCH_ASSOC);

$canManage    = csm_can_manage();
$page_title   = 'รายละเอียด ' . ($item['name'] ?? '');
$current_page = 'consumables';
include __DIR__ . '/../includes/header.php';

$isLow = ((int)$item['min_stock'] > 0 && (int)$item['qty_on_hand'] <= (int)$item['min_stock']);
$isOut = ((int)$item['qty_on_hand'] <= 0);
?>

<div class="mb-4 flex items-center justify-between flex-wrap gap-3">
    <a href="admin/manage_consumables.php" class="text-sm text-slate-500 hover:text-[#2e9e63]">
        <i class="fas fa-arrow-left"></i> กลับรายการ
    </a>
    <div class="flex items-center gap-2 flex-wrap">
        <a href="admin/receive_form.php?consumable_id=<?= $id ?>" class="btn-asset btn-asset-secondary">
            <i class="fas fa-download"></i> รับเข้า
        </a>
        <a href="admin/issue_form.php?consumable_id=<?= $id ?>" class="btn-asset btn-asset-secondary">
            <i class="fas fa-hand-holding"></i> เบิกออก
        </a>
        <?php if ($canManage): ?>
            <a href="admin/consumable_form.php?id=<?= $id ?>" class="btn-asset btn-asset-primary">
                <i class="fas fa-edit"></i> แก้ไข
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <!-- Detail card -->
    <div class="lg:col-span-2 asset-card p-5">
        <div class="flex items-start gap-4">
            <?php if (!empty($item['image'])): ?>
                <img src="<?= htmlspecialchars($item['image']) ?>" class="w-24 h-24 rounded-xl object-cover border border-slate-200">
            <?php else: ?>
                <div class="w-24 h-24 rounded-xl bg-slate-100 flex items-center justify-center text-slate-400 text-3xl">
                    <i class="fas fa-box-open"></i>
                </div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <div class="font-mono text-xs text-[#2e9e63] font-bold"><?= htmlspecialchars($item['code']) ?></div>
                <h2 class="text-xl font-extrabold text-slate-800 mt-1"><?= htmlspecialchars($item['name']) ?></h2>
                <?php if (!empty($item['brand'])): ?>
                    <div class="text-sm text-slate-500"><?= htmlspecialchars($item['brand']) ?></div>
                <?php endif; ?>
                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    <?php if (!empty($item['category_name'])): ?>
                        <span class="badge-status bg-slate-100 text-slate-700"><i class="fa-solid fa-tag mr-1"></i><?= htmlspecialchars($item['category_name']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($item['location_name'])): ?>
                        <span class="badge-status bg-slate-100 text-slate-700"><i class="fa-solid fa-location-dot mr-1"></i><?= htmlspecialchars($item['location_name']) ?></span>
                    <?php endif; ?>
                    <span class="badge-status <?= $item['status']==='active' ? 'bg-[#f0faf4] text-[#2e7d52] border border-[#c7e8d5]' : 'bg-slate-100 text-slate-500' ?>">
                        <?= $item['status']==='active' ? 'ใช้งาน' : 'ปิด' ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if (!empty($item['note'])): ?>
            <div class="mt-4 p-3 rounded-lg bg-slate-50 text-sm text-slate-700 whitespace-pre-line"><?= htmlspecialchars($item['note']) ?></div>
        <?php endif; ?>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
            <div>
                <div class="text-[11px] text-slate-400 uppercase tracking-wider font-bold">บรรจุภัณฑ์</div>
                <div class="font-bold text-slate-700">
                    <?php if ((int)$item['pack_size'] > 1): ?>
                        1 <?= htmlspecialchars($item['unit_pack']) ?> = <?= (int)$item['pack_size'] ?> <?= htmlspecialchars($item['unit_piece']) ?>
                    <?php else: ?>
                        นับเป็น <?= htmlspecialchars($item['unit_piece']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <div class="text-[11px] text-slate-400 uppercase tracking-wider font-bold">จุดสั่งซื้อ</div>
                <div class="font-bold text-slate-700"><?= number_format((int)$item['min_stock']) ?> <?= htmlspecialchars($item['unit_piece']) ?></div>
            </div>
            <div>
                <div class="text-[11px] text-slate-400 uppercase tracking-wider font-bold">วันที่สร้าง</div>
                <div class="font-bold text-slate-700"><?= date('d/m/Y', strtotime($item['created_at'])) ?></div>
            </div>
        </div>
    </div>

    <!-- Stock card -->
    <div class="asset-card p-5 flex flex-col items-center justify-center text-center
                <?= $isOut ? 'border-rose-200 bg-rose-50/30' : ($isLow ? 'border-amber-200 bg-amber-50/30' : '') ?>">
        <div class="text-[11px] uppercase tracking-wider font-bold text-slate-500">ยอดคงเหลือ</div>
        <div class="my-2 font-extrabold tracking-tight <?= $isOut ? 'text-rose-600' : ($isLow ? 'text-amber-600' : 'text-emerald-700') ?>" style="font-size:3rem; line-height:1">
            <?= number_format((int)$item['qty_on_hand']) ?>
        </div>
        <div class="text-sm text-slate-500"><?= htmlspecialchars($item['unit_piece']) ?></div>
        <?php if ((int)$item['pack_size'] > 1 && (int)$item['qty_on_hand'] > 0): ?>
            <div class="text-[11px] text-slate-400 mt-1">
                ≈ <?= number_format(intdiv((int)$item['qty_on_hand'], (int)$item['pack_size'])) ?> <?= htmlspecialchars($item['unit_pack']) ?>
                <?php $rem = (int)$item['qty_on_hand'] % (int)$item['pack_size']; if ($rem > 0): ?>
                    + <?= $rem ?> <?= htmlspecialchars($item['unit_piece']) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($isLow): ?>
            <div class="mt-3 text-xs text-amber-700 font-bold">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= $isOut ? 'หมดสต็อก' : 'ใกล้หมด — ต่ำกว่าจุดสั่งซื้อ' ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Transactions history -->
<div class="asset-card p-2 sm:p-4">
    <div class="flex items-center justify-between mb-3 px-2 pt-2">
        <h2 class="asset-sec-title">ประวัติการเคลื่อนไหว</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="asset-table">
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th>ประเภท</th>
                    <th class="text-center">จำนวน</th>
                    <th class="text-center">คงเหลือ</th>
                    <th>หน่วยงาน</th>
                    <th>ผู้รับ</th>
                    <th>วัตถุประสงค์</th>
                    <th>โดย</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($txns)): ?>
                    <tr><td colspan="8" class="text-center text-slate-400 py-10 text-sm">ยังไม่มีประวัติการเคลื่อนไหว</td></tr>
                <?php else: foreach ($txns as $t):
                    $tl = csm_txn_label($t['txn_type']);
                    $facType = ($t['faculty_type'] ?? '') === 'department' ? 'หน่วยงาน' : 'คณะ';
                ?>
                    <tr>
                        <td data-label="วันที่" class="text-sm text-slate-600 whitespace-nowrap">
                            <?= date('d/m/Y', strtotime($t['txn_date'])) ?>
                            <div class="text-[10px] text-slate-400"><?= date('H:i', strtotime($t['created_at'])) ?></div>
                        </td>
                        <td data-label="ประเภท">
                            <span class="badge-status <?= $tl['class'] ?>"><?= $tl['label'] ?></span>
                        </td>
                        <td data-label="จำนวน" class="text-center">
                            <div class="font-extrabold <?= $t['qty_change'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= ($t['qty_change'] >= 0 ? '+' : '') . number_format((int)$t['qty_change']) ?>
                            </div>
                            <div class="text-[10px] text-slate-400">
                                (<?= (int)$t['qty_input'] ?> <?= $t['unit_input'] === 'pack' ? htmlspecialchars($item['unit_pack']) : htmlspecialchars($item['unit_piece']) ?>)
                            </div>
                        </td>
                        <td data-label="คงเหลือ" class="text-center font-bold text-slate-700"><?= number_format((int)$t['balance_after']) ?></td>
                        <td data-label="หน่วยงาน" class="text-sm">
                            <?php if (!empty($t['faculty_name'])): ?>
                                <div class="font-bold text-slate-700"><?= htmlspecialchars($t['faculty_name']) ?></div>
                                <div class="text-[10px] text-slate-400"><?= $facType ?></div>
                            <?php else: ?>
                                <span class="text-slate-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="ผู้รับ" class="text-sm text-slate-600"><?= htmlspecialchars($t['requester_name'] ?? '-') ?: '-' ?></td>
                        <td data-label="วัตถุประสงค์" class="text-xs text-slate-500"><?= htmlspecialchars($t['purpose'] ?? '') ?></td>
                        <td data-label="โดย" class="text-xs text-slate-500"><?= htmlspecialchars($t['created_by_name'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?= csm_pagination_html($page, $totalPages, $total, ['id' => $id]) ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
