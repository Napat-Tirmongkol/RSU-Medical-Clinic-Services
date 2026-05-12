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

<div class="asset-anim d1 mb-6">
    <div class="asset-kpi-strip">
        <?php
        $cards = [
            ['label' => 'รายการทั้งหมด', 'value' => (int)($stats['total'] ?? 0),                                                       'icon' => 'fa-boxes-stacked',     'bg' => '#f0faf4', 'color' => '#2e9e63'],
            ['label' => 'จำนวนรวม',      'value' => (int)($stats['total_qty'] ?? 0),                                                   'icon' => 'fa-cubes',             'bg' => '#eff6ff', 'color' => '#2563eb'],
            ['label' => 'กำลังซ่อม',     'value' => (int)($stats['cnt_repair'] ?? 0),                                                  'icon' => 'fa-screwdriver-wrench','bg' => '#fffbeb', 'color' => '#d97706'],
            ['label' => 'จำหน่าย/สูญหาย','value' => (int)(($stats['cnt_disposed'] ?? 0) + ($stats['cnt_lost'] ?? 0)),                  'icon' => 'fa-circle-xmark',      'bg' => '#fef2f2', 'color' => '#dc2626'],
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

<div class="asset-card p-5 asset-anim d2">
    <div class="flex items-center justify-between mb-4">
        <h2 class="asset-sec-title">รายการล่าสุด</h2>
        <a href="admin/manage_assets.php" class="text-sm font-bold text-[#2e9e63] hover:text-[#258052]">ดูทั้งหมด <i class="fa-solid fa-arrow-right text-xs"></i></a>
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
                    <td data-label="รหัส" class="font-mono text-xs text-[#2e9e63] font-bold"><?= htmlspecialchars($r['asset_code']) ?></td>
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

<!-- ════════════ Guided Tour (Driver.js) ════════════ -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<script src="../assets/js/rsu-tour.js"></script>
<script>
(function () {
    const assetSteps = [
        { popover: { title: 'ยินดีต้อนรับสู่ระบบครุภัณฑ์', description: 'จัดการทะเบียนทรัพย์สินของหน่วยงาน — ทัวร์สั้นๆ ดูฟีเจอร์หลักกัน' } },
        { element: '[role="tablist"]', popover: { title: 'สลับโมดูล', description: 'แท็บบนสุดสลับไปหาวัสดุสิ้นเปลือง (ใช้ตาราง asset_locations ร่วมกัน)', side: 'bottom' } },
        { element: '.psb-item[href*="manage_assets"]', popover: { title: 'รายการครุภัณฑ์', description: 'ทะเบียนทรัพย์สินทั้งหมด — เพิ่ม/แก้/ค้นหา', side: 'right' } },
        { element: '.psb-item[href*="manage_locations"]', popover: { title: 'จุดใช้งาน', description: 'จัดการที่ตั้งของครุภัณฑ์ (ใช้ร่วมกับวัสดุสิ้นเปลือง)', side: 'right' } },
        { element: '.psb-item[href*="scan.php"]', popover: { title: 'สแกน QR', description: 'สแกนบาร์โค้ดบนตัวครุภัณฑ์เพื่อดูประวัติ/ย้ายจุดใช้งาน', side: 'right' } },
        { element: '.psb-item[href*="stock_take"]', popover: { title: 'ตรวจนับ', description: 'เปิดรอบตรวจนับประจำปี — สแกนเช็คมี/ไม่มี', side: 'right' } },
        { popover: { title: 'เริ่มใช้งานได้เลย', description: 'กดปุ่ม <i class="fa-solid fa-question"></i> มุมซ้ายล่างเมื่อต้องการดูทัวร์ซ้ำ' } },
    ];
    window.RsuTour && RsuTour.maybeAutoStart('asset', assetSteps);
    window._assetTourSteps = assetSteps;
})();
</script>
<button id="rsu-tour-fab" type="button" aria-label="ดู Tour อีกครั้ง" title="ดู Tour อีกครั้ง"
    onclick="window.RsuTour && RsuTour.start(window._assetTourSteps, 'asset')"
    style="position:fixed;bottom:20px;left:20px;width:44px;height:44px;border-radius:50%;border:none;background:#2e9e63;color:#fff;font-size:16px;cursor:pointer;box-shadow:0 4px 12px rgba(46,158,99,.35);z-index:90">
    <i class="fa-solid fa-question"></i>
</button>

<?php include __DIR__ . '/includes/footer.php'; ?>
