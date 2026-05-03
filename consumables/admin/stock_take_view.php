<?php
// consumables/admin/stock_take_view.php — กรอกจำนวนนับ + ปิดรอบ (apply adjustments)
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('id ไม่ถูกต้อง'); }

$flash = ''; $flashType = '';

// ── ปิดรอบ + apply adjustments ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    csm_require_manage();
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash = 'CSRF token ไม่ถูกต้อง'; $flashType = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            $items = $pdo->prepare("
                SELECT i.id, i.consumable_id, i.expected_qty, i.actual_qty, c.qty_on_hand
                FROM consumable_stock_take_items i
                LEFT JOIN consumables c ON c.id = i.consumable_id
                WHERE i.stock_take_id = ? AND i.actual_qty IS NOT NULL AND i.check_status = 'counted'
            ");
            $items->execute([$id]);
            $applied = 0;
            foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $diff = (int)$it['actual_qty'] - (int)$it['qty_on_hand']; // ปรับเทียบ "qty ปัจจุบัน" จริง
                if ($diff !== 0) {
                    csm_log_txn(
                        $pdo, (int)$it['consumable_id'], 'adjust', $diff, 'piece', abs($diff),
                        null, null, 'ปรับยอดจากรอบตรวจนับ #' . $id, 'STK-' . $id, null, date('Y-m-d')
                    );
                    $applied++;
                }
                $pdo->prepare("UPDATE consumable_stock_take_items SET check_status='adjusted' WHERE id=?")
                    ->execute([(int)$it['id']]);
            }
            $pdo->prepare("UPDATE consumable_stock_takes SET status='closed', closed_by=?, closed_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'] ?? null, $id]);
            $pdo->commit();
            $flash = "ปิดรอบเรียบร้อย — ปรับยอด {$applied} รายการ"; $flashType = 'ok';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash = $e->getMessage(); $flashType = 'error';
        }
    }
}

// ── โหลดข้อมูลรอบ ────────────────────────────────────────────────────────
$round = $pdo->prepare("SELECT t.*, l.name AS scope_location, c.name AS scope_category, s.full_name AS created_by_name
    FROM consumable_stock_takes t
    LEFT JOIN asset_locations         l ON l.id = t.scope_location_id
    LEFT JOIN consumable_categories   c ON c.id = t.scope_category_id
    LEFT JOIN sys_staff               s ON s.id = t.created_by
    WHERE t.id = ?");
$round->execute([$id]);
$round = $round->fetch(PDO::FETCH_ASSOC);
if (!$round) { http_response_code(404); exit('ไม่พบรอบตรวจนับ'); }

// ── โหลดรายการ + filter ──────────────────────────────────────────────────
$filterDiff = !empty($_GET['only_diff']);
$filterPending = !empty($_GET['only_pending']);

$items = $pdo->prepare("
    SELECT i.*, c.code, c.name, c.brand, c.unit_piece, c.qty_on_hand AS current_qty,
           l.name AS location_name, cat.name AS category_name
    FROM consumable_stock_take_items i
    LEFT JOIN consumables             c   ON c.id   = i.consumable_id
    LEFT JOIN asset_locations         l   ON l.id   = c.location_id
    LEFT JOIN consumable_categories   cat ON cat.id = c.category_id
    WHERE i.stock_take_id = ?
    ORDER BY (i.check_status = 'pending') DESC, c.name ASC
");
$items->execute([$id]);
$rows = $items->fetchAll(PDO::FETCH_ASSOC);

if ($filterDiff)    $rows = array_filter($rows, fn($r) => $r['actual_qty'] !== null && (int)$r['actual_qty'] !== (int)$r['expected_qty']);
if ($filterPending) $rows = array_filter($rows, fn($r) => $r['check_status'] === 'pending');

$totalItems   = (int)$pdo->query("SELECT COUNT(*) FROM consumable_stock_take_items WHERE stock_take_id = {$id}")->fetchColumn();
$countedItems = (int)$pdo->query("SELECT COUNT(*) FROM consumable_stock_take_items WHERE stock_take_id = {$id} AND check_status <> 'pending'")->fetchColumn();
$diffItems    = (int)$pdo->query("SELECT COUNT(*) FROM consumable_stock_take_items WHERE stock_take_id = {$id} AND actual_qty IS NOT NULL AND actual_qty <> expected_qty")->fetchColumn();
$pct = $totalItems > 0 ? (int)round($countedItems / $totalItems * 100) : 0;

$canManage = csm_can_manage();
$isClosed  = $round['status'] === 'closed';
$page_title   = 'รอบตรวจนับ: ' . $round['name'];
$current_page = 'stock_take';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4 flex items-center justify-between flex-wrap gap-3">
    <a href="admin/stock_take.php" class="text-sm text-slate-500 hover:text-[#2e9e63]">
        <i class="fas fa-arrow-left"></i> รายการรอบทั้งหมด
    </a>
    <?php if (!$isClosed && $canManage): ?>
        <form method="post" onsubmit="return confirm('ยืนยันปิดรอบ? ระบบจะปรับยอด stock อัตโนมัติตามจำนวนที่นับจริง')" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="close">
            <button type="submit" class="btn-asset btn-asset-primary">
                <i class="fas fa-flag-checkered"></i> ปิดรอบ + ปรับยอด
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-xl <?= $flashType === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-700' ?> border text-sm"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="asset-card p-5 mb-4">
    <div class="flex items-start justify-between flex-wrap gap-3">
        <div>
            <h2 class="text-xl font-extrabold text-slate-800"><?= htmlspecialchars($round['name']) ?></h2>
            <div class="text-xs text-slate-500 mt-1">
                <?= $round['year'] ? 'ปี ' . htmlspecialchars((string)$round['year']) . ' · ' : '' ?>
                สร้างโดย <?= htmlspecialchars($round['created_by_name'] ?? '-') ?> · <?= date('d/m/Y H:i', strtotime($round['created_at'])) ?>
            </div>
            <?php if (!empty($round['note'])): ?>
                <div class="text-xs text-slate-600 mt-2 p-2 bg-slate-50 rounded"><?= htmlspecialchars($round['note']) ?></div>
            <?php endif; ?>
        </div>
        <div class="text-right">
            <div class="text-xs text-slate-500 uppercase font-bold">ความคืบหน้า</div>
            <div class="text-2xl font-extrabold text-[#2e9e63]"><?= $pct ?>%</div>
            <div class="text-xs text-slate-500"><?= $countedItems ?>/<?= $totalItems ?> รายการ</div>
            <?php if ($diffItems > 0): ?>
                <div class="text-xs text-amber-600 font-bold mt-1"><i class="fa-solid fa-triangle-exclamation"></i> ต่าง <?= $diffItems ?> รายการ</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="flex items-center gap-3 mb-3 text-xs flex-wrap">
    <a href="admin/stock_take_view.php?id=<?= $id ?>" class="<?= !$filterDiff && !$filterPending ? 'font-bold text-[#2e9e63]' : 'text-slate-500 hover:text-[#2e9e63]' ?>">ทั้งหมด (<?= $totalItems ?>)</a>
    <span class="text-slate-300">·</span>
    <a href="admin/stock_take_view.php?id=<?= $id ?>&only_pending=1" class="<?= $filterPending ? 'font-bold text-amber-600' : 'text-slate-500 hover:text-amber-600' ?>">ยังไม่นับ (<?= $totalItems - $countedItems ?>)</a>
    <span class="text-slate-300">·</span>
    <a href="admin/stock_take_view.php?id=<?= $id ?>&only_diff=1" class="<?= $filterDiff ? 'font-bold text-rose-600' : 'text-slate-500 hover:text-rose-600' ?>">ต่างจากระบบ (<?= $diffItems ?>)</a>
</div>

<div class="asset-card p-2 sm:p-4">
    <div class="overflow-x-auto">
        <table class="asset-table">
            <thead><tr>
                <th>รหัส / ชื่อ</th>
                <th>จุดเก็บ / หมวด</th>
                <th class="text-center">ระบบบันทึก<br>(เปิดรอบ)</th>
                <th class="text-center">ระบบปัจจุบัน</th>
                <th class="text-center" style="width:140px">นับจริง</th>
                <th class="text-center">ต่าง</th>
                <th class="text-center" style="width:120px">สถานะ</th>
            </tr></thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-slate-400 py-10 text-sm">ไม่มีรายการตามเงื่อนไข</td></tr>
                <?php else: foreach ($rows as $r):
                    $actual = $r['actual_qty'];
                    $diff = $actual !== null ? (int)$actual - (int)$r['expected_qty'] : null;
                    $statusBadge = match ($r['check_status']) {
                        'counted'  => ['นับแล้ว', 'bg-sky-50 text-sky-700 border border-sky-200'],
                        'adjusted' => ['ปรับแล้ว', 'bg-emerald-50 text-emerald-700 border border-emerald-200'],
                        default    => ['ยังไม่นับ', 'bg-slate-100 text-slate-500 border border-slate-200'],
                    };
                ?>
                    <tr>
                        <td>
                            <div class="font-mono text-xs text-[#2e9e63] font-bold"><?= htmlspecialchars($r['code']) ?></div>
                            <div class="font-bold text-slate-800"><?= htmlspecialchars($r['name']) ?></div>
                            <?php if (!empty($r['brand'])): ?><div class="text-xs text-slate-500"><?= htmlspecialchars($r['brand']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-xs text-slate-600">
                            <?php if ($r['location_name']): ?><div><i class="fa-solid fa-location-dot text-slate-400"></i> <?= htmlspecialchars($r['location_name']) ?></div><?php endif; ?>
                            <?php if ($r['category_name']): ?><div><i class="fa-solid fa-tag text-slate-400"></i> <?= htmlspecialchars($r['category_name']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-center font-bold text-slate-700"><?= number_format((int)$r['expected_qty']) ?></td>
                        <td class="text-center font-bold text-slate-500"><?= number_format((int)$r['current_qty']) ?></td>
                        <td class="text-center">
                            <?php if ($isClosed): ?>
                                <span class="font-bold"><?= $actual !== null ? number_format((int)$actual) : '-' ?></span>
                            <?php else: ?>
                                <input type="number" min="0" value="<?= $actual !== null ? (int)$actual : '' ?>"
                                       class="asset-input text-center font-bold csm-count-input"
                                       data-item-id="<?= (int)$r['id'] ?>"
                                       placeholder="—">
                            <?php endif; ?>
                            <span class="text-[10px] text-slate-400"><?= htmlspecialchars($r['unit_piece']) ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($diff !== null): ?>
                                <span class="font-extrabold <?= $diff === 0 ? 'text-slate-500' : ($diff > 0 ? 'text-emerald-600' : 'text-rose-600') ?>">
                                    <?= $diff > 0 ? '+' : '' ?><?= number_format($diff) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-slate-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge-status <?= $statusBadge[1] ?>"><?= $statusBadge[0] ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!$isClosed): ?>
<script>
(function () {
    const ENDPOINT = 'ajax/stock_take_count.php';
    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';

    function save(input) {
        const itemId = input.dataset.itemId;
        const val = input.value === '' ? null : parseInt(input.value, 10);
        if (val !== null && val < 0) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('item_id', itemId);
        fd.append('actual_qty', val === null ? '' : String(val));
        input.style.outline = '2px solid #fde68a';
        fetch(ENDPOINT, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    input.style.outline = '2px solid #86efac';
                    setTimeout(() => { input.style.outline = ''; }, 600);
                } else {
                    input.style.outline = '2px solid #fda4af';
                    alert(d.error || 'บันทึกไม่สำเร็จ');
                }
            })
            .catch(() => { input.style.outline = '2px solid #fda4af'; });
    }

    document.querySelectorAll('.csm-count-input').forEach(i => {
        i.addEventListener('change', () => save(i));
        i.addEventListener('blur', () => save(i));
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
