<?php
// asset/admin/stock_take_view.php — หน้าตรวจนับครุภัณฑ์รายรอบ
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: stock_take.php'); exit; }

$canManage = asset_can_manage();

$takeStmt = $pdo->prepare("
    SELECT t.*, l.name AS scope_location_name, c.name AS scope_category_name
    FROM asset_stock_takes t
    LEFT JOIN asset_locations  l ON l.id = t.scope_location_id
    LEFT JOIN asset_categories c ON c.id = t.scope_category_id
    WHERE t.id = ?
");
$takeStmt->execute([$id]);
$take = $takeStmt->fetch(PDO::FETCH_ASSOC);
if (!$take) { header('Location: stock_take.php'); exit; }

// ── Filter ────────────────────────────────────────────────────────────────
$filterStatus = (string)($_GET['fs'] ?? '');
$filterLoc    = (int)($_GET['loc'] ?? 0);
$search       = trim((string)($_GET['search'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ['i.stock_take_id = ?'];
$params = [$id];
if ($filterStatus !== '' && in_array($filterStatus, ['pending', 'found', 'not_found', 'wrong_location', 'damaged'], true)) {
    $where[] = 'i.found_status = ?'; $params[] = $filterStatus;
}
if ($filterLoc > 0) { $where[] = 'i.expected_location_id = ?'; $params[] = $filterLoc; }
if ($search !== '') {
    $where[] = '(a.name LIKE ? OR a.asset_code LIKE ? OR a.serial_number LIKE ? OR a.rsu_asset_code LIKE ?)';
    $like = "%{$search}%";
    array_push($params, $like, $like, $like, $like);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_stock_take_items i
                          JOIN assets a ON a.id = i.asset_id
                          {$whereSql}");
$cntStmt->execute($params);
$total      = (int)$cntStmt->fetchColumn();
$totalPages = (int)max(1, ceil($total / $perPage));

$sql = "SELECT i.*, a.asset_code, a.name AS asset_name, a.brand, a.serial_number, a.rsu_asset_code, a.image,
               el.name AS expected_location_name, fl.name AS found_location_name
        FROM asset_stock_take_items i
        JOIN assets a ON a.id = i.asset_id
        LEFT JOIN asset_locations el ON el.id = i.expected_location_id
        LEFT JOIN asset_locations fl ON fl.id = i.found_location_id
        {$whereSql}
        ORDER BY i.found_status='pending' DESC, a.name ASC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// summary counts
$sumStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN found_status='pending'        THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN found_status='found'          THEN 1 ELSE 0 END) AS found,
        SUM(CASE WHEN found_status='not_found'      THEN 1 ELSE 0 END) AS not_found,
        SUM(CASE WHEN found_status='wrong_location' THEN 1 ELSE 0 END) AS wrong_loc,
        SUM(CASE WHEN found_status='damaged'        THEN 1 ELSE 0 END) AS damaged
    FROM asset_stock_take_items WHERE stock_take_id = ?
");
$sumStmt->execute([$id]);
$sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$locations = $pdo->query("SELECT id, name FROM asset_locations WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$readonly = $take['status'] === 'closed';
$pct = ($sum['total'] ?? 0) > 0
    ? round((((int)$sum['found'] + (int)$sum['not_found'] + (int)$sum['wrong_loc'] + (int)$sum['damaged']) / (int)$sum['total']) * 100)
    : 0;

$page_title   = 'ตรวจนับ: ' . $take['name'];
$current_page = 'stock_take';
$extraQuery   = array_filter(['fs' => $filterStatus, 'loc' => $filterLoc ?: null, 'search' => $search ?: null], fn($v) => $v !== null && $v !== '');
$extraQuery['id'] = $id;
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4 flex-wrap gap-2">
    <div>
        <div class="text-xs text-slate-500 font-semibold">รอบตรวจนับ</div>
        <h2 class="asset-sec-title"><?= htmlspecialchars($take['name']) ?></h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">
            <?php if ($take['scope_location_name']): ?>📍 <?= htmlspecialchars($take['scope_location_name']) ?> · <?php endif; ?>
            <?php if ($take['scope_category_name']): ?>🏷 <?= htmlspecialchars($take['scope_category_name']) ?> · <?php endif; ?>
            <?php if (!$take['scope_location_name'] && !$take['scope_category_name']): ?>ครอบคลุมทั้งหมด<?php endif; ?>
        </p>
    </div>
    <a href="admin/stock_take.php" class="btn-asset btn-asset-ghost"><i class="fas fa-arrow-left"></i> กลับ</a>
</div>

<?php if ($readonly): ?>
    <div class="mb-4 p-3 rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-sm">
        <i class="fas fa-lock"></i> รอบนี้ปิดแล้ว — ดูผลได้อย่างเดียว
    </div>
<?php endif; ?>

<div class="asset-kpi-strip mb-4">
    <?php
    $kpis = [
        ['label' => 'รวม',         'value' => (int)($sum['total'] ?? 0),     'icon' => 'fa-list',          'bg' => '#f8fafc', 'color' => '#475569'],
        ['label' => 'รอตรวจ',      'value' => (int)($sum['pending'] ?? 0),   'icon' => 'fa-clock',         'bg' => '#f1f5f9', 'color' => '#64748b'],
        ['label' => 'เจอ',         'value' => (int)($sum['found'] ?? 0),     'icon' => 'fa-circle-check',  'bg' => '#f0faf4', 'color' => '#2e9e63'],
        ['label' => 'ไม่เจอ',      'value' => (int)($sum['not_found'] ?? 0), 'icon' => 'fa-circle-xmark',  'bg' => '#fef2f2', 'color' => '#dc2626'],
        ['label' => 'ผิดที่/ชำรุด','value' => (int)(($sum['wrong_loc'] ?? 0) + ($sum['damaged'] ?? 0)), 'icon' => 'fa-triangle-exclamation', 'bg' => '#fffbeb', 'color' => '#d97706'],
    ];
    foreach ($kpis as $c): ?>
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

<div class="asset-card p-4 mb-4">
    <div class="text-sm font-semibold text-slate-700 mb-2">ความคืบหน้า: <?= $pct ?>%</div>
    <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
        <div class="h-full bg-gradient-to-r from-[#2e9e63] to-[#3bba7a] transition-all" style="width: <?= $pct ?>%"></div>
    </div>
</div>

<form method="get" class="asset-card p-4 mb-4">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
        <div class="md:col-span-5">
            <label class="asset-label">ค้นหา</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ชื่อ / รหัส / S/N" class="asset-input">
        </div>
        <div class="md:col-span-3">
            <label class="asset-label">สถานะการตรวจ</label>
            <select name="fs" class="asset-input">
                <option value="">— ทั้งหมด —</option>
                <option value="pending"        <?= $filterStatus === 'pending' ? 'selected' : '' ?>>รอตรวจ</option>
                <option value="found"          <?= $filterStatus === 'found' ? 'selected' : '' ?>>เจอ</option>
                <option value="not_found"      <?= $filterStatus === 'not_found' ? 'selected' : '' ?>>ไม่เจอ</option>
                <option value="wrong_location" <?= $filterStatus === 'wrong_location' ? 'selected' : '' ?>>ผิดที่</option>
                <option value="damaged"        <?= $filterStatus === 'damaged' ? 'selected' : '' ?>>ชำรุด</option>
            </select>
        </div>
        <div class="md:col-span-3">
            <label class="asset-label">จุดที่คาดว่าอยู่</label>
            <select name="loc" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $filterLoc === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-1 flex items-end">
            <button type="submit" class="btn-asset btn-asset-primary w-full justify-center">
                <i class="fas fa-filter"></i>
            </button>
        </div>
    </div>
</form>

<div class="asset-card p-2 sm:p-4">
    <div class="overflow-x-auto">
        <table class="asset-table">
            <thead><tr>
                <th style="width:50px"></th>
                <th>รหัส / ชื่อ</th>
                <th>คาดว่าอยู่ที่</th>
                <th>สถานะการตรวจ</th>
                <th>หมายเหตุ</th>
                <?php if (!$readonly): ?><th class="text-center" style="width:240px">ผลการตรวจ</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="<?= $readonly ? 5 : 6 ?>" class="text-center text-slate-400 py-12 text-sm">
                    ไม่พบรายการตรงตามเงื่อนไข
                </td></tr>
            <?php else: foreach ($items as $it):
                $statusMap = [
                    'pending'        => ['รอตรวจ', 'bg-slate-100 text-slate-600 border border-slate-200'],
                    'found'          => ['เจอ',    'bg-[#f0faf4] text-[#2e7d52] border border-[#c7e8d5]'],
                    'not_found'      => ['ไม่เจอ', 'bg-rose-50 text-rose-700 border border-rose-200'],
                    'wrong_location' => ['ผิดที่', 'bg-amber-50 text-amber-700 border border-amber-200'],
                    'damaged'        => ['ชำรุด',  'bg-orange-50 text-orange-700 border border-orange-200'],
                ];
                $sb = $statusMap[$it['found_status']] ?? ['?', 'bg-slate-100 text-slate-600'];
            ?>
                <tr>
                    <td data-label="">
                        <?php if (!empty($it['image'])): ?>
                            <img src="<?= htmlspecialchars($it['image']) ?>" class="asset-thumb">
                        <?php else: ?>
                            <div class="asset-thumb flex items-center justify-center"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="รหัส / ชื่อ">
                        <div class="font-mono text-xs text-[#2e9e63] font-bold"><?= htmlspecialchars($it['asset_code']) ?></div>
                        <div class="font-bold"><?= htmlspecialchars($it['asset_name']) ?></div>
                        <?php if (!empty($it['serial_number'])): ?><div class="text-[11px] text-slate-500">S/N: <?= htmlspecialchars($it['serial_number']) ?></div><?php endif; ?>
                    </td>
                    <td data-label="คาดว่าอยู่ที่"><?= htmlspecialchars($it['expected_location_name'] ?? '-') ?></td>
                    <td data-label="สถานะ">
                        <span class="badge-status <?= $sb[1] ?>"><?= $sb[0] ?></span>
                        <?php if ($it['found_status'] === 'wrong_location' && $it['found_location_name']): ?>
                            <div class="text-[11px] text-slate-500 mt-1">เจอที่: <strong><?= htmlspecialchars($it['found_location_name']) ?></strong></div>
                        <?php endif; ?>
                        <?php if (!empty($it['checked_at'])): ?>
                            <div class="text-[10px] text-slate-400 mt-0.5"><?= date('d/m/Y H:i', strtotime($it['checked_at'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="หมายเหตุ" class="text-xs text-slate-600"><?= htmlspecialchars($it['note'] ?? '-') ?></td>
                    <?php if (!$readonly): ?>
                    <td data-label="ผลการตรวจ">
                        <div class="flex items-center justify-center gap-1 flex-wrap">
                            <button type="button" class="btn-asset btn-asset-secondary" title="เจอ"
                                    onclick="stCheck(<?= (int)$it['id'] ?>, 'found')">
                                <i class="fas fa-check text-emerald-600"></i>
                            </button>
                            <button type="button" class="btn-asset btn-asset-secondary" title="ไม่เจอ"
                                    onclick="stCheck(<?= (int)$it['id'] ?>, 'not_found')">
                                <i class="fas fa-xmark text-rose-600"></i>
                            </button>
                            <button type="button" class="btn-asset btn-asset-secondary" title="ผิดที่/ชำรุด/หมายเหตุ"
                                    onclick='stCheckAdvanced(<?= json_encode([
                                        "id" => (int)$it["id"],
                                        "name" => $it["asset_name"],
                                        "current" => $it["found_status"],
                                        "note" => $it["note"] ?? ""
                                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                <i class="fas fa-ellipsis"></i>
                            </button>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?= asset_pagination_html($page, $totalPages, $total, $extraQuery) ?>
</div>

<script>
window.ST_LOCATIONS = <?= json_encode($locations, JSON_UNESCAPED_UNICODE) ?>;

window.stCheck = function (itemId, status) {
    const fd = new FormData();
    fd.append('csrf_token', window.ASSET_CSRF);
    fd.append('item_id', itemId);
    fd.append('status', status);
    fetch('ajax/stock_take_check.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) window.location.reload();
            else Swal.fire('ผิดพลาด', data.message || 'อัปเดตไม่สำเร็จ', 'error');
        });
};

window.stCheckAdvanced = function (item) {
    const locOpts = window.ST_LOCATIONS.map(l => `<option value="${l.id}">${l.name}</option>`).join('');
    Swal.fire({
        title: 'บันทึกผลการตรวจ',
        html: `
            <div class="text-left text-sm font-semibold mb-2">${item.name}</div>
            <select id="stx_status" class="swal2-input" style="display:flex">
                <option value="found">เจอ</option>
                <option value="not_found">ไม่เจอ</option>
                <option value="wrong_location">ผิดที่ (เจอที่อื่น)</option>
                <option value="damaged">ชำรุด</option>
            </select>
            <select id="stx_loc" class="swal2-input" style="display:none">
                <option value="0">— เลือกที่อยู่จริง —</option>
                ${locOpts}
            </select>
            <textarea id="stx_note" class="swal2-textarea" placeholder="หมายเหตุ (ถ้ามี)">${item.note || ''}</textarea>
        `,
        showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#2e9e63',
        didOpen: () => {
            const sel = document.getElementById('stx_status');
            const loc = document.getElementById('stx_loc');
            sel.value = item.current === 'pending' ? 'found' : item.current;
            const upd = () => loc.style.display = sel.value === 'wrong_location' ? 'flex' : 'none';
            sel.addEventListener('change', upd); upd();
        },
        preConfirm: () => {
            const status = document.getElementById('stx_status').value;
            const loc    = document.getElementById('stx_loc').value;
            const note   = document.getElementById('stx_note').value;
            if (status === 'wrong_location' && (!loc || loc === '0')) {
                Swal.showValidationMessage('กรุณาเลือกจุดที่เจอจริง'); return false;
            }
            return { status, loc, note };
        }
    }).then((res) => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', window.ASSET_CSRF);
        fd.append('item_id', item.id);
        fd.append('status', res.value.status);
        fd.append('found_location_id', res.value.loc || '');
        fd.append('note', res.value.note);
        fetch('ajax/stock_take_check.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.ok) window.location.reload();
                else Swal.fire('ผิดพลาด', data.message || 'อัปเดตไม่สำเร็จ', 'error');
            });
    });
};
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
