<?php
// asset/admin/stock_take.php — รายการรอบตรวจนับครุภัณฑ์ + สร้างรอบใหม่
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo       = db();
$canManage = asset_can_manage(); // admin/editor: สร้าง/ปิดรอบได้, employee: เข้าร่วมตรวจอย่างเดียว
$err = $msg = '';

// ── สร้างรอบใหม่ (admin/editor only) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        if (!$canManage) throw new RuntimeException('สิทธิ์ไม่เพียงพอ');
        validate_csrf_or_die();

        $name = trim((string)($_POST['name'] ?? ''));
        $year = (int)($_POST['year'] ?? date('Y'));
        $loc  = (int)($_POST['scope_location_id'] ?? 0) ?: null;
        $cat  = (int)($_POST['scope_category_id'] ?? 0) ?: null;
        $startDate = ($_POST['start_date'] ?? '') ?: null;
        $endDate   = ($_POST['end_date'] ?? '') ?: null;
        if ($name === '') throw new RuntimeException('กรุณากรอกชื่อรอบตรวจนับ');

        $pdo->beginTransaction();

        // 1. สร้างรอบ
        $stmt = $pdo->prepare(
            "INSERT INTO asset_stock_takes
                (name, year, start_date, end_date, scope_location_id, scope_category_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 'in_progress', ?)"
        );
        $stmt->execute([$name, $year, $startDate, $endDate, $loc, $cat, $_SESSION['user_id'] ?? null]);
        $takeId = (int)$pdo->lastInsertId();

        // 2. snapshot ครุภัณฑ์ที่เข้าข่าย → asset_stock_take_items
        $where = ["status NOT IN ('disposed', 'lost')"];
        $params = [];
        if ($loc) { $where[] = 'location_id = ?'; $params[] = $loc; }
        if ($cat) { $where[] = 'category_id = ?'; $params[] = $cat; }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sel = $pdo->prepare("SELECT id, location_id, status FROM assets {$whereSql}");
        $sel->execute($params);
        $items = $sel->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            throw new RuntimeException('ไม่พบครุภัณฑ์ที่ตรงตามเงื่อนไข — ไม่สามารถสร้างรอบได้');
        }

        $ins = $pdo->prepare(
            "INSERT INTO asset_stock_take_items
                (stock_take_id, asset_id, expected_location_id, expected_status, found_status)
             VALUES (?, ?, ?, ?, 'pending')"
        );
        foreach ($items as $a) {
            $ins->execute([$takeId, $a['id'], $a['location_id'], $a['status']]);
        }

        $pdo->commit();
        header("Location: stock_take_view.php?id={$takeId}&new=1");
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

// ── ปิดรอบ ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    try {
        if (!$canManage) throw new RuntimeException('สิทธิ์ไม่เพียงพอ');
        validate_csrf_or_die();
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE asset_stock_takes SET status='closed', closed_by=?, closed_at=NOW() WHERE id=?");
        $stmt->execute([$_SESSION['user_id'] ?? null, $id]);
        $msg = 'ปิดรอบตรวจนับสำเร็จ';
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

// ── List รอบทั้งหมด (pagination 20/หน้า) ─────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM asset_stock_takes")->fetchColumn();
    $totalPages = (int)max(1, ceil($total / $perPage));

    $sql = "SELECT t.*,
                   l.name AS scope_location_name, c.name AS scope_category_name,
                   (SELECT COUNT(*) FROM asset_stock_take_items i WHERE i.stock_take_id = t.id) AS total_items,
                   (SELECT COUNT(*) FROM asset_stock_take_items i WHERE i.stock_take_id = t.id AND i.found_status = 'found') AS found_items,
                   (SELECT COUNT(*) FROM asset_stock_take_items i WHERE i.stock_take_id = t.id AND i.found_status = 'not_found') AS missing_items
            FROM asset_stock_takes t
            LEFT JOIN asset_locations  l ON l.id = t.scope_location_id
            LEFT JOIN asset_categories c ON c.id = t.scope_category_id
            ORDER BY t.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = []; $total = 0; $totalPages = 1;
    $err = 'โหลดข้อมูลไม่สำเร็จ — กรุณารัน database/migrations/migrate_asset_stock_take.php ก่อน';
}

$locations  = $pdo->query("SELECT id, name FROM asset_locations  WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'ตรวจนับครุภัณฑ์';
$current_page = 'stock_take';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4 flex-wrap gap-2">
    <div>
        <h2 class="asset-sec-title">ตรวจนับครุภัณฑ์</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">รอบตรวจนับประจำปี + ผลการตรวจ</p>
    </div>
</div>

<?php if ($msg): ?><div class="mb-3 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="mb-3 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <?php if ($canManage): ?>
    <div class="asset-card p-5 lg:col-span-1">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-plus-circle text-[#2e9e63]"></i> สร้างรอบตรวจนับใหม่</h3>
        <form method="post" class="space-y-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div>
                <label class="asset-label">ชื่อรอบ <span class="text-rose-500">*</span></label>
                <input type="text" name="name" required class="asset-input" placeholder="ตรวจนับประจำปี <?= (date('Y') + 543) ?>">
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="asset-label">ปี (ค.ศ.)</label>
                    <input type="number" name="year" value="<?= date('Y') ?>" class="asset-input">
                </div>
                <div>
                    <label class="asset-label">เริ่ม</label>
                    <input type="date" name="start_date" class="asset-input">
                </div>
            </div>
            <div>
                <label class="asset-label">สิ้นสุด</label>
                <input type="date" name="end_date" class="asset-input">
            </div>
            <div>
                <label class="asset-label">เฉพาะจุดใช้งาน</label>
                <select name="scope_location_id" class="asset-input">
                    <option value="0">— ทุกจุด —</option>
                    <?php foreach ($locations as $l): ?>
                        <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="asset-label">เฉพาะหมวดหมู่</label>
                <select name="scope_category_id" class="asset-input">
                    <option value="0">— ทุกหมวด —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-asset btn-asset-primary w-full justify-center">
                <i class="fas fa-play"></i> เริ่มรอบตรวจนับ
            </button>
            <p class="text-[11px] text-slate-500">ระบบจะ snapshot รายการครุภัณฑ์ที่เข้าข่ายอัตโนมัติ (ยกเว้นที่จำหน่าย/สูญหายแล้ว)</p>
        </form>
    </div>
    <?php endif; ?>

    <div class="asset-card p-4 <?= $canManage ? 'lg:col-span-2' : 'lg:col-span-3' ?>">
        <div class="overflow-x-auto">
            <table class="asset-table">
                <thead><tr>
                    <th>ชื่อรอบ</th><th>ขอบเขต</th>
                    <th class="text-center">ความคืบหน้า</th>
                    <th class="text-center">สถานะ</th>
                    <th>วันที่</th>
                    <th class="text-center" style="width:140px">จัดการ</th>
                </tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="text-center text-slate-400 py-12 text-sm">
                        <i class="fas fa-clipboard-check text-3xl text-slate-300 mb-2 block"></i>
                        ยังไม่มีรอบตรวจนับ
                    </td></tr>
                <?php else: foreach ($rows as $r):
                    $total = (int)$r['total_items'];
                    $found = (int)$r['found_items'];
                    $miss  = (int)$r['missing_items'];
                    $pct   = $total > 0 ? round(($found + $miss) / $total * 100) : 0;
                    $statusBadge = match ($r['status']) {
                        'draft'       => ['ร่าง',     'bg-slate-100 text-slate-600 border border-slate-200'],
                        'in_progress' => ['กำลังตรวจ','bg-amber-50 text-amber-700 border border-amber-200'],
                        'closed'      => ['ปิดรอบ',   'bg-[#f0faf4] text-[#2e7d52] border border-[#c7e8d5]'],
                        default       => [$r['status'], 'bg-slate-100 text-slate-600'],
                    };
                ?>
                    <tr>
                        <td data-label="ชื่อรอบ">
                            <strong><?= htmlspecialchars($r['name']) ?></strong>
                            <?php if ($r['year']): ?><div class="text-[11px] text-slate-500">ปี <?= (int)$r['year'] ?></div><?php endif; ?>
                        </td>
                        <td data-label="ขอบเขต" class="text-xs text-slate-600">
                            <?php
                            $scope = [];
                            if ($r['scope_location_name']) $scope[] = '📍 ' . $r['scope_location_name'];
                            if ($r['scope_category_name']) $scope[] = '🏷 ' . $r['scope_category_name'];
                            echo $scope ? htmlspecialchars(implode(' · ', $scope)) : '<span class="text-slate-400">ทั้งหมด</span>';
                            ?>
                        </td>
                        <td data-label="ความคืบหน้า" class="text-center">
                            <div class="text-sm font-bold"><?= $pct ?>%</div>
                            <div class="text-[10px] text-slate-500">เจอ <?= $found ?> / <?= $total ?>
                                <?php if ($miss): ?> · <span class="text-rose-600">หาย <?= $miss ?></span><?php endif; ?>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-1.5 mt-1 overflow-hidden">
                                <div class="h-full bg-gradient-to-r from-[#2e9e63] to-[#3bba7a]" style="width: <?= $pct ?>%"></div>
                            </div>
                        </td>
                        <td data-label="สถานะ" class="text-center">
                            <span class="badge-status <?= $statusBadge[1] ?>"><?= $statusBadge[0] ?></span>
                        </td>
                        <td data-label="วันที่" class="text-xs text-slate-500">
                            <?= !empty($r['start_date']) ? date('d/m/Y', strtotime($r['start_date'])) : '-' ?>
                            <?= !empty($r['end_date']) ? ' - ' . date('d/m/Y', strtotime($r['end_date'])) : '' ?>
                        </td>
                        <td data-label="จัดการ">
                            <div class="flex items-center justify-center gap-1 flex-wrap">
                                <a href="admin/stock_take_view.php?id=<?= (int)$r['id'] ?>" class="btn-asset btn-asset-secondary" title="เปิด">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($canManage && $r['status'] === 'in_progress'): ?>
                                    <form method="post" class="inline"
                                          onsubmit="return assetCloseRoundConfirm(event, this, '<?= htmlspecialchars(addslashes($r['name']), ENT_QUOTES) ?>', <?= (int)$r['total_items'] - (int)$r['found_items'] - (int)$r['missing_items'] ?>)">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="close">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn-asset btn-asset-ghost" title="ปิดรอบ"><i class="fas fa-lock"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?= asset_pagination_html($page, $totalPages ?? 1, $total ?? 0, []) ?>
    </div>
</div>

<script>
window.assetCloseRoundConfirm = function (e, form, name, pendingCount) {
    e.preventDefault();
    const html = pendingCount > 0
        ? `<div class="text-sm text-slate-600 leading-relaxed">รอบ <strong>"${name}"</strong> ยังมี <strong class="text-amber-600">${pendingCount} รายการที่ยังไม่ตรวจ</strong><br>การปิดรอบจะทำให้แก้ไขผลตรวจไม่ได้อีก</div>`
        : `<div class="text-sm text-slate-600">รอบ <strong>"${name}"</strong> จะถูกปิด<br>หลังปิดแล้วจะแก้ไขผลตรวจไม่ได้อีก</div>`;
    Swal.fire({
        title: 'ปิดรอบตรวจนับ?',
        html: html,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ปิดรอบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#b45309',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
        focusCancel: true,
    }).then((res) => { if (res.isConfirmed) form.submit(); });
    return false;
};
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
