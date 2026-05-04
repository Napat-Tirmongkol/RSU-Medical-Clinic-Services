<?php
// consumables/admin/stock_take.php — รายการรอบตรวจนับ + สร้างรอบใหม่
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$canManage = csm_can_manage();
$flash = ''; $flashType = '';

// ── สร้างรอบใหม่ ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csm_require_manage();
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash = 'CSRF token ไม่ถูกต้อง'; $flashType = 'error';
    } else {
        try {
            $name      = trim((string)($_POST['name'] ?? ''));
            $year      = (int)($_POST['year'] ?? date('Y')) ?: null;
            $startDate = $_POST['start_date'] ?? null ?: null;
            $endDate   = $_POST['end_date']   ?? null ?: null;
            $scopeLoc  = (int)($_POST['scope_location_id'] ?? 0) ?: null;
            $scopeCat  = (int)($_POST['scope_category_id'] ?? 0) ?: null;
            $note      = trim((string)($_POST['note'] ?? ''));
            if ($name === '') throw new RuntimeException('กรุณากรอกชื่อรอบตรวจ');

            $pdo->beginTransaction();

            $ins = $pdo->prepare("INSERT INTO consumable_stock_takes
                (name, year, start_date, end_date, scope_location_id, scope_category_id, status, note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 'in_progress', ?, ?)");
            $ins->execute([$name, $year, $startDate, $endDate, $scopeLoc, $scopeCat, $note ?: null, $_SESSION['user_id'] ?? null]);
            $takeId = (int)$pdo->lastInsertId();

            // Snapshot active consumables ตาม scope
            $where = ["c.status = 'active'"];
            $params = [];
            if ($scopeLoc) { $where[] = 'c.location_id = ?'; $params[] = $scopeLoc; }
            if ($scopeCat) { $where[] = 'c.category_id = ?'; $params[] = $scopeCat; }
            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $sel = $pdo->prepare("SELECT id, qty_on_hand FROM consumables c {$whereSql}");
            $sel->execute($params);
            $items = $sel->fetchAll(PDO::FETCH_ASSOC);

            $insItem = $pdo->prepare("INSERT INTO consumable_stock_take_items
                (stock_take_id, consumable_id, expected_qty) VALUES (?, ?, ?)");
            foreach ($items as $it) {
                $insItem->execute([$takeId, (int)$it['id'], (int)$it['qty_on_hand']]);
            }

            $pdo->commit();
            header('Location: admin/stock_take_view.php?id=' . $takeId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash = $e->getMessage(); $flashType = 'error';
        }
    }
}

// ── List rounds ──────────────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM consumable_stock_takes")->fetchColumn();
    $totalPages = (int)max(1, ceil($total / $perPage));

    $rounds = $pdo->query("
        SELECT t.*,
            (SELECT COUNT(*) FROM consumable_stock_take_items WHERE stock_take_id=t.id) AS total_items,
            (SELECT COUNT(*) FROM consumable_stock_take_items WHERE stock_take_id=t.id AND check_status<>'pending') AS counted_items,
            l.name AS scope_location, c.name AS scope_category, s.full_name AS created_by_name
        FROM consumable_stock_takes t
        LEFT JOIN asset_locations         l ON l.id = t.scope_location_id
        LEFT JOIN consumable_categories   c ON c.id = t.scope_category_id
        LEFT JOIN sys_staff               s ON s.id = t.created_by
        ORDER BY t.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ")->fetchAll(PDO::FETCH_ASSOC);

    $locations  = $pdo->query("SELECT id, name FROM asset_locations WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $categories = $pdo->query("SELECT id, name FROM consumable_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rounds = []; $total = 0; $totalPages = 1; $locations = []; $categories = [];
    $error = 'โหลดข้อมูลไม่สำเร็จ — กรุณารัน database/migrations/migrate_consumable_stock_take.php ก่อน';
}

$page_title   = 'ตรวจนับวัสดุ';
$current_page = 'stock_take';
include __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($error)): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-xl <?= $flashType === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-700' ?> border text-sm"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
    <div>
        <h2 class="asset-sec-title">ตรวจนับวัสดุสิ้นเปลือง</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">เปิดรอบตรวจนับ → กรอกจำนวนนับจริง → ปิดรอบเพื่อปรับยอดอัตโนมัติ</p>
    </div>
    <?php if ($canManage): ?>
        <button type="button" onclick="document.getElementById('newRoundModal').style.display='flex'" class="btn-asset btn-asset-primary">
            <i class="fas fa-plus"></i> เปิดรอบใหม่
        </button>
    <?php endif; ?>
</div>

<div class="asset-card p-2 sm:p-4">
    <div class="overflow-x-auto">
        <table class="asset-table">
            <thead><tr>
                <th>ชื่อรอบ</th><th class="text-center">ปี</th><th>ขอบเขต</th>
                <th>ช่วงวันที่</th><th class="text-center">ความคืบหน้า</th>
                <th class="text-center">สถานะ</th><th>โดย</th><th class="text-center">จัดการ</th>
            </tr></thead>
            <tbody>
                <?php if (empty($rounds)): ?>
                    <tr><td colspan="8" class="text-center text-slate-400 py-12 text-sm">
                        <i class="fas fa-clipboard-list text-3xl text-slate-300 mb-2 block"></i>
                        ยังไม่มีรอบตรวจนับ
                    </td></tr>
                <?php else: foreach ($rounds as $r):
                    $cnt = (int)$r['counted_items']; $tot = max(1, (int)$r['total_items']);
                    $pct = (int)round($cnt / $tot * 100);
                    $statusLabel = match ($r['status']) {
                        'in_progress' => ['กำลังดำเนินการ', 'bg-amber-50 text-amber-700 border border-amber-200'],
                        'closed'      => ['ปิดแล้ว', 'bg-slate-100 text-slate-600 border border-slate-200'],
                        default       => ['ร่าง', 'bg-sky-50 text-sky-700 border border-sky-200'],
                    };
                ?>
                    <tr>
                        <td class="font-bold text-slate-800"><?= htmlspecialchars($r['name']) ?></td>
                        <td class="text-center text-slate-600"><?= htmlspecialchars((string)($r['year'] ?? '-')) ?></td>
                        <td class="text-xs text-slate-600">
                            <?php if ($r['scope_location'] || $r['scope_category']): ?>
                                <?php if ($r['scope_location']): ?><div><i class="fa-solid fa-location-dot text-slate-400"></i> <?= htmlspecialchars($r['scope_location']) ?></div><?php endif; ?>
                                <?php if ($r['scope_category']): ?><div><i class="fa-solid fa-tag text-slate-400"></i> <?= htmlspecialchars($r['scope_category']) ?></div><?php endif; ?>
                            <?php else: ?>
                                <span class="text-slate-400">ทั้งหมด</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs text-slate-600">
                            <?= $r['start_date'] ? date('d/m/y', strtotime($r['start_date'])) : '-' ?>
                            →
                            <?= $r['end_date']   ? date('d/m/y', strtotime($r['end_date'])) : '-' ?>
                        </td>
                        <td class="text-center">
                            <div class="text-xs font-bold"><?= $cnt ?>/<?= (int)$r['total_items'] ?></div>
                            <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden mt-1 w-24 mx-auto">
                                <div class="h-full bg-emerald-400" style="width: <?= $pct ?>%"></div>
                            </div>
                        </td>
                        <td class="text-center"><span class="badge-status <?= $statusLabel[1] ?>"><?= $statusLabel[0] ?></span></td>
                        <td class="text-xs text-slate-500"><?= htmlspecialchars($r['created_by_name'] ?? '-') ?></td>
                        <td class="text-center">
                            <a href="admin/stock_take_view.php?id=<?= (int)$r['id'] ?>" class="btn-asset btn-asset-primary"><i class="fas fa-eye"></i> เปิด</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?= csm_pagination_html($page, $totalPages, $total) ?>
</div>

<!-- New round modal -->
<?php if ($canManage): ?>
<div id="newRoundModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:200;align-items:center;justify-content:center;padding:16px">
    <form method="post" class="bg-white rounded-2xl p-6 max-w-lg w-full shadow-2xl space-y-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="create">
        <h3 class="font-extrabold text-lg text-slate-800">เปิดรอบตรวจนับใหม่</h3>
        <p class="text-xs text-slate-500">ระบบจะ snapshot ยอดคงเหลือทุกวัสดุที่ active ไว้ในรอบนี้</p>
        <div>
            <label class="asset-label">ชื่อรอบ <span class="text-rose-500">*</span></label>
            <input type="text" name="name" required class="asset-input" placeholder="ตรวจนับวัสดุประจำปี <?= date('Y') + 543 ?>">
        </div>
        <div class="grid grid-cols-3 gap-2">
            <div>
                <label class="asset-label">ปี (พ.ศ.)</label>
                <input type="number" name="year" value="<?= date('Y') + 543 ?>" class="asset-input">
            </div>
            <div>
                <label class="asset-label">เริ่ม</label>
                <input type="date" name="start_date" class="asset-input">
            </div>
            <div>
                <label class="asset-label">ถึง</label>
                <input type="date" name="end_date" class="asset-input">
            </div>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="asset-label">เฉพาะจุดจัดเก็บ</label>
                <select name="scope_location_id" class="asset-input">
                    <option value="0">— ทั้งหมด —</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="asset-label">เฉพาะหมวด</label>
                <select name="scope_category_id" class="asset-input">
                    <option value="0">— ทั้งหมด —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div>
            <label class="asset-label">หมายเหตุ</label>
            <textarea name="note" rows="2" class="asset-input"></textarea>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" onclick="document.getElementById('newRoundModal').style.display='none'" class="btn-asset btn-asset-ghost">ยกเลิก</button>
            <button type="submit" class="btn-asset btn-asset-primary"><i class="fas fa-play"></i> เปิดรอบ</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
