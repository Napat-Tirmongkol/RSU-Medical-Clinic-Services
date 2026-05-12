<?php
// consumables/index.php — Dashboard ภาพรวมวัสดุสิ้นเปลือง
require_once __DIR__ . '/includes/check_session.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = db();

// ── สถิติรวม ───────────────────────────────────────────────────────────────
try {
    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total_items,
            COALESCE(SUM(qty_on_hand), 0) AS total_qty,
            SUM(CASE WHEN qty_on_hand <= min_stock AND min_stock > 0 THEN 1 ELSE 0 END) AS low_stock,
            SUM(CASE WHEN qty_on_hand <= 0 THEN 1 ELSE 0 END) AS out_of_stock
        FROM consumables WHERE status = 'active'
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    // รวมการเบิกใน 30 วันที่ผ่านมา
    $issuedQty = $pdo->query("
        SELECT COALESCE(SUM(ABS(qty_change)), 0)
        FROM consumable_transactions
        WHERE txn_type = 'issue' AND txn_date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
    ")->fetchColumn();
} catch (Throwable $e) {
    $stats = ['total_items' => 0, 'total_qty' => 0, 'low_stock' => 0, 'out_of_stock' => 0];
    $issuedQty = 0;
    $error = 'ไม่สามารถโหลดข้อมูลได้: รัน database/migrations/migrate_consumable_module.php ก่อน';
}

// ── รายการ low stock (top 5) ──────────────────────────────────────────────
$lowStockItems = [];
try {
    $lowStockItems = $pdo->query("
        SELECT c.id, c.code, c.name, c.qty_on_hand, c.min_stock, c.unit_piece, l.name AS location_name
        FROM consumables c
        LEFT JOIN asset_locations l ON l.id = c.location_id
        WHERE c.status='active' AND c.min_stock > 0 AND c.qty_on_hand <= c.min_stock
        ORDER BY (c.qty_on_hand / NULLIF(c.min_stock,0)) ASC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

// ── 5 transaction ล่าสุด ──────────────────────────────────────────────────
$recentTxn = [];
try {
    $recentTxn = $pdo->query("
        SELECT t.id, t.txn_type, t.qty_change, t.balance_after, t.txn_date, t.requester_name,
               c.name AS item_name, c.unit_piece,
               f.name_th AS faculty_name
        FROM consumable_transactions t
        LEFT JOIN consumables c ON c.id = t.consumable_id
        LEFT JOIN sys_faculties f ON f.id = t.faculty_id
        ORDER BY t.id DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

$page_title   = 'ภาพรวม';
$current_page = 'index';
include __DIR__ . '/includes/header.php';
?>

<?php if (!empty($error)): ?>
    <div class="mb-4 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-sm">
        <i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="asset-anim d1 mb-6">
    <div class="asset-kpi-strip">
        <?php
        $cards = [
            ['label' => 'รายการวัสดุ',         'value' => (int)($stats['total_items']  ?? 0), 'icon' => 'fa-box-open',          'bg' => '#f0faf4', 'color' => '#2e9e63'],
            ['label' => 'ยอดคงเหลือรวม (ชิ้น)','value' => (int)($stats['total_qty']    ?? 0), 'icon' => 'fa-cubes',             'bg' => '#eff6ff', 'color' => '#2563eb'],
            ['label' => 'ใกล้หมด',              'value' => (int)($stats['low_stock']    ?? 0), 'icon' => 'fa-triangle-exclamation','bg' => '#fffbeb', 'color' => '#d97706'],
            ['label' => 'เบิกใน 30 วัน',        'value' => (int)$issuedQty,                    'icon' => 'fa-hand-holding',      'bg' => '#fef2f2', 'color' => '#dc2626'],
        ];
        foreach ($cards as $c): ?>
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
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <!-- รายการใกล้หมด -->
    <div class="asset-card p-5 asset-anim d2">
        <div class="flex items-center justify-between mb-4">
            <h2 class="asset-sec-title">รายการใกล้หมด</h2>
            <a href="admin/manage_consumables.php" class="text-sm font-bold text-[#2e9e63] hover:text-[#258052]">ดูทั้งหมด <i class="fa-solid fa-arrow-right text-xs"></i></a>
        </div>
        <?php if (empty($lowStockItems)): ?>
            <p class="text-center text-slate-400 py-8 text-sm"><i class="fa-solid fa-circle-check text-2xl text-emerald-400 mb-2 block"></i>ไม่มีรายการใกล้หมด</p>
        <?php else: ?>
            <ul class="space-y-2">
                <?php foreach ($lowStockItems as $it): ?>
                    <li class="flex items-center justify-between p-3 rounded-lg border border-amber-100 bg-amber-50/40">
                        <div class="min-w-0">
                            <div class="font-bold text-slate-800 text-sm"><?= htmlspecialchars($it['name']) ?></div>
                            <div class="text-[11px] text-slate-500 font-mono"><?= htmlspecialchars($it['code']) ?> · <?= htmlspecialchars($it['location_name'] ?? '-') ?></div>
                        </div>
                        <div class="text-right shrink-0 ml-3">
                            <div class="text-amber-700 font-extrabold"><?= (int)$it['qty_on_hand'] ?>
                                <span class="text-xs font-normal text-slate-500">/ <?= (int)$it['min_stock'] ?> <?= htmlspecialchars($it['unit_piece']) ?></span>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- การเคลื่อนไหวล่าสุด -->
    <div class="asset-card p-5 asset-anim d3">
        <div class="flex items-center justify-between mb-4">
            <h2 class="asset-sec-title">การเคลื่อนไหวล่าสุด</h2>
            <a href="admin/transactions.php" class="text-sm font-bold text-[#2e9e63] hover:text-[#258052]">ทั้งหมด <i class="fa-solid fa-arrow-right text-xs"></i></a>
        </div>
        <?php if (empty($recentTxn)): ?>
            <p class="text-center text-slate-400 py-8 text-sm">ยังไม่มีการเคลื่อนไหว</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($recentTxn as $t):
                    $tl = csm_txn_label($t['txn_type']);
                    $sign = $t['qty_change'] > 0 ? '+' : '';
                ?>
                    <li class="py-2.5 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="badge-status <?= $tl['class'] ?>"><?= $tl['label'] ?></span>
                                <span class="font-bold text-slate-800 text-sm truncate"><?= htmlspecialchars($t['item_name'] ?? '-') ?></span>
                            </div>
                            <div class="text-[11px] text-slate-500 mt-0.5">
                                <?= date('d/m/Y', strtotime($t['txn_date'])) ?>
                                <?php if (!empty($t['faculty_name'])): ?> · <?= htmlspecialchars($t['faculty_name']) ?><?php endif; ?>
                                <?php if (!empty($t['requester_name'])): ?> · <?= htmlspecialchars($t['requester_name']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="font-extrabold <?= $t['qty_change'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= $sign ?><?= number_format((int)$t['qty_change']) ?>
                            </div>
                            <div class="text-[10px] text-slate-400">คงเหลือ <?= number_format((int)$t['balance_after']) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<!-- ════════════ Guided Tour (Driver.js) ════════════ -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<script src="../assets/js/rsu-tour.js"></script>
<script>
(function () {
    const csmSteps = [
        { popover: { title: 'ยินดีต้อนรับสู่ระบบวัสดุสิ้นเปลือง', description: 'จัดการสต็อกวัสดุ — รับเข้า เบิกออก พร้อมแจ้งเตือนใกล้หมด' } },
        { element: '[role="tablist"]', popover: { title: 'สลับโมดูล', description: 'แท็บบนสุดสลับไปหาครุภัณฑ์ได้เลย', side: 'bottom' } },
        { element: '.psb-item[href*="manage_consumables"]', popover: { title: 'รายการวัสดุ', description: 'ทะเบียนวัสดุทั้งหมด — เพิ่ม/แก้/ค้นหา', side: 'right' } },
        { element: '.psb-item[href*="manage_locations"]', popover: { title: 'จุดจัดเก็บ (ใหม่)', description: 'จัดการที่จัดเก็บวัสดุ — ใช้ร่วมกับโมดูลครุภัณฑ์', side: 'right' } },
        { element: '.psb-item[href*="receive_form"]', popover: { title: 'รับเข้า', description: 'บันทึกวัสดุรับเข้าคลัง', side: 'right' } },
        { element: '.psb-item[href*="issue_form"]', popover: { title: 'เบิกออก', description: 'บันทึกวัสดุเบิกใช้', side: 'right' } },
        { element: '.psb-item[href*="stock_take"]', popover: { title: 'ตรวจนับ', description: 'เปิดรอบตรวจนับ — เทียบยอดจริงกับ snapshot', side: 'right' } },
        { popover: { title: 'เริ่มใช้งานได้เลย', description: 'กดปุ่ม <i class="fa-solid fa-question"></i> มุมซ้ายล่างเมื่อต้องการดูทัวร์ซ้ำ' } },
    ];
    window.RsuTour && RsuTour.maybeAutoStart('consumables', csmSteps);
    window._csmTourSteps = csmSteps;
})();
</script>
<button id="rsu-tour-fab" type="button" aria-label="ดู Tour อีกครั้ง" title="ดู Tour อีกครั้ง"
    onclick="window.RsuTour && RsuTour.start(window._csmTourSteps, 'consumables')"
    style="position:fixed;bottom:20px;left:20px;width:44px;height:44px;border-radius:50%;border:none;background:#2e9e63;color:#fff;font-size:16px;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.35);z-index:90">
    <i class="fa-solid fa-question"></i>
</button>

<?php include __DIR__ . '/includes/footer.php'; ?>
