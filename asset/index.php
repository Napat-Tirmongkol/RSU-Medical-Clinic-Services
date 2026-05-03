<?php
// asset/index.php — Dashboard ภาพรวมครุภัณฑ์
require_once __DIR__ . '/includes/check_session.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = db();

// ── สถิติรวม ───────────────────────────────────────────────────────────────
try {
    $stats = $pdo->query("
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(quantity), 0) AS total_qty,
            SUM(CASE WHEN status='in_use'   THEN 1 ELSE 0 END) AS cnt_in_use,
            SUM(CASE WHEN status='repair'   THEN 1 ELSE 0 END) AS cnt_repair,
            SUM(CASE WHEN status='disposed' THEN 1 ELSE 0 END) AS cnt_disposed,
            SUM(CASE WHEN status='lost'     THEN 1 ELSE 0 END) AS cnt_lost
        FROM assets
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $stats = ['total' => 0, 'total_qty' => 0];
    $error = 'ไม่สามารถโหลดข้อมูลได้: ตารางอาจยังไม่ได้สร้าง (รัน database/migrations/migrate_asset_module.php ก่อน)';
}

// ── 5 รายการล่าสุด ─────────────────────────────────────────────────────────
$recent = [];
try {
    $recent = $pdo->query("
        SELECT a.id, a.asset_code, a.name, a.status, a.created_at, l.name AS location_name
        FROM assets a
        LEFT JOIN asset_locations l ON l.id = a.location_id
        ORDER BY a.created_at DESC LIMIT 5
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

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $cards = [
        ['label' => 'รายการทั้งหมด', 'value' => (int)($stats['total'] ?? 0),       'icon' => 'fa-boxes-stacked', 'color' => 'from-indigo-500 to-purple-600'],
        ['label' => 'จำนวนรวม',     'value' => (int)($stats['total_qty'] ?? 0),   'icon' => 'fa-cubes',         'color' => 'from-sky-500 to-blue-600'],
        ['label' => 'กำลังซ่อม',    'value' => (int)($stats['cnt_repair'] ?? 0),  'icon' => 'fa-screwdriver-wrench','color' => 'from-amber-500 to-orange-600'],
        ['label' => 'จำหน่าย/สูญหาย','value' => (int)(($stats['cnt_disposed'] ?? 0) + ($stats['cnt_lost'] ?? 0)), 'icon' => 'fa-circle-xmark', 'color' => 'from-rose-500 to-red-600'],
    ];
    foreach ($cards as $c): ?>
        <div class="asset-card p-4">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br <?= $c['color'] ?> text-white flex items-center justify-center shadow">
                    <i class="fas <?= $c['icon'] ?>"></i>
                </div>
                <div>
                    <div class="text-xs text-slate-500 font-semibold"><?= htmlspecialchars($c['label']) ?></div>
                    <div class="text-2xl font-extrabold text-slate-800"><?= number_format($c['value']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="asset-card p-5">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-extrabold text-slate-800"><i class="fas fa-clock-rotate-left text-indigo-500"></i> รายการล่าสุด</h2>
        <a href="admin/manage_assets.php" class="text-sm font-bold text-indigo-600 hover:text-indigo-700">ดูทั้งหมด <i class="fas fa-arrow-right text-xs"></i></a>
    </div>
    <?php if (empty($recent)): ?>
        <p class="text-center text-slate-400 py-8 text-sm">ยังไม่มีข้อมูลครุภัณฑ์</p>
    <?php else: ?>
        <table class="asset-table">
            <thead><tr>
                <th>รหัส</th><th>ชื่อ</th><th>จุดใช้งาน</th><th>สถานะ</th><th>เพิ่มเมื่อ</th>
            </tr></thead>
            <tbody>
            <?php foreach ($recent as $r):
                $st = asset_status_label($r['status']); ?>
                <tr>
                    <td data-label="รหัส" class="font-mono text-xs text-slate-600"><?= htmlspecialchars($r['asset_code']) ?></td>
                    <td data-label="ชื่อ"><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                    <td data-label="จุดใช้งาน"><?= htmlspecialchars($r['location_name'] ?? '-') ?></td>
                    <td data-label="สถานะ"><span class="badge-status <?= $st['class'] ?>"><?= $st['label'] ?></span></td>
                    <td data-label="เพิ่มเมื่อ" class="text-xs text-slate-500"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
