<?php
// asset/admin/manage_locations.php — จัดการจุดใช้งาน
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
asset_require_manage();

$pdo = db();
$err = $msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validate_csrf_or_die();
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $bld  = trim((string)($_POST['building'] ?? '')) ?: null;
            $flr  = trim((string)($_POST['floor'] ?? '')) ?: null;
            if ($name === '') throw new RuntimeException('กรุณากรอกชื่อจุดใช้งาน');
            $stmt = $pdo->prepare("INSERT INTO asset_locations (name, building, floor) VALUES (?, ?, ?)");
            $stmt->execute([$name, $bld, $flr]);
            $msg = 'เพิ่มจุดใช้งานสำเร็จ';
        } elseif ($action === 'update') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $bld  = trim((string)($_POST['building'] ?? '')) ?: null;
            $flr  = trim((string)($_POST['floor'] ?? '')) ?: null;
            $act  = isset($_POST['is_active']) ? 1 : 1;
            if ($id <= 0 || $name === '') throw new RuntimeException('ข้อมูลไม่ถูกต้อง');
            $stmt = $pdo->prepare("UPDATE asset_locations SET name=?, building=?, floor=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $bld, $flr, $act, $id]);
            $msg = 'แก้ไขสำเร็จ';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE location_id=?");
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() > 0) {
                throw new RuntimeException('ลบไม่ได้: ยังมีครุภัณฑ์อยู่ในจุดนี้');
            }
            $pdo->prepare("DELETE FROM asset_locations WHERE id=?")->execute([$id]);
            $msg = 'ลบสำเร็จ';
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE asset_locations SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            $msg = 'อัปเดตสถานะสำเร็จ';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where = ''; $params = [];
if ($search !== '') {
    $where  = 'WHERE l.name LIKE ? OR l.building LIKE ? OR l.floor LIKE ?';
    $like   = "%{$search}%";
    $params = [$like, $like, $like];
}

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_locations l {$where}");
$cntStmt->execute($params);
$total      = (int)$cntStmt->fetchColumn();
$totalPages = (int)max(1, ceil($total / $perPage));

$sql = "SELECT l.*, (SELECT COUNT(*) FROM assets a WHERE a.location_id = l.id) AS asset_count
        FROM asset_locations l {$where}
        ORDER BY l.is_active DESC, l.name ASC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'จุดใช้งาน';
$current_page = 'locations';
$extraQuery   = $search !== '' ? ['search' => $search] : [];
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4">
    <h2 class="asset-sec-title">จุดใช้งาน</h2>
</div>

<?php if ($msg): ?><div class="mb-3 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="mb-3 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="asset-card p-5 lg:col-span-1">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-plus-circle text-[#2e9e63]"></i> เพิ่มจุดใช้งานใหม่</h3>
        <form method="post" class="space-y-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div>
                <label class="asset-label">ชื่อจุดใช้งาน <span class="text-rose-500">*</span></label>
                <input type="text" name="name" required class="asset-input" placeholder="เช่น เคาน์เตอร์พยาบาล">
            </div>
            <div>
                <label class="asset-label">อาคาร</label>
                <input type="text" name="building" class="asset-input">
            </div>
            <div>
                <label class="asset-label">ชั้น</label>
                <input type="text" name="floor" class="asset-input">
            </div>
            <button class="btn-asset btn-asset-primary w-full justify-center"><i class="fas fa-save"></i> บันทึก</button>
        </form>
    </div>

    <div class="asset-card p-4 lg:col-span-2">
        <form method="get" class="mb-3">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาจุดใช้งาน..." class="asset-input pl-9">
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="asset-table">
                <thead><tr>
                    <th>ชื่อ</th><th>อาคาร / ชั้น</th><th class="text-center">จำนวนครุภัณฑ์</th><th class="text-center">สถานะ</th><th class="text-center" style="width:160px">จัดการ</th>
                </tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="5" class="text-center text-slate-400 py-8">ไม่พบจุดใช้งาน</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td data-label="ชื่อ"><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                        <td data-label="อาคาร / ชั้น" class="text-sm text-slate-500">
                            <?= htmlspecialchars(trim(($r['building'] ?? '') . ' ' . ($r['floor'] ?? ''))) ?: '-' ?>
                        </td>
                        <td data-label="จำนวน" class="text-center font-bold"><?= (int)$r['asset_count'] ?></td>
                        <td data-label="สถานะ" class="text-center">
                            <?php if ((int)$r['is_active'] === 1): ?>
                                <span class="badge-status bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200">ใช้งาน</span>
                            <?php else: ?>
                                <span class="badge-status bg-slate-200 text-slate-600">ปิดใช้งาน</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="จัดการ">
                            <div class="flex items-center justify-center gap-1">
                                <button type="button" class="btn-asset btn-asset-secondary"
                                        onclick='editLocation(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="post" class="inline"
                                      onsubmit="return assetLocToggleConfirm(event, this, <?= (int)$r['is_active'] ?>, '<?= htmlspecialchars(addslashes($r['name']), ENT_QUOTES) ?>')">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <?php if ((int)$r['is_active'] === 1): ?>
                                        <button class="btn-asset btn-asset-ghost" title="ซ่อนจุดนี้ออกจาก dropdown">
                                            <i class="fas fa-eye-slash"></i>
                                        </button>
                                    <?php else: ?>
                                        <button class="btn-asset btn-asset-secondary" title="เปิดใช้งานอีกครั้ง">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                                <form method="post" class="inline"
                                      onsubmit="return assetLocDeleteConfirm(event, this, '<?= htmlspecialchars(addslashes($r['name']), ENT_QUOTES) ?>', <?= (int)$r['asset_count'] ?>)">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn-asset btn-asset-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?= asset_pagination_html($page, $totalPages, $total, $extraQuery) ?>
    </div>
</div>

<script>
window.assetLocDeleteConfirm = function (e, form, name, assetCount) {
    e.preventDefault();
    if (assetCount > 0) {
        Swal.fire({
            title: 'ลบไม่ได้',
            html: `<div class="text-sm text-slate-600">จุด <strong>"${name}"</strong> ยังมีครุภัณฑ์อยู่ <strong class="text-rose-600">${assetCount} รายการ</strong><br>กรุณาย้ายครุภัณฑ์ออกก่อน หรือใช้ปุ่ม <i class="fas fa-eye-slash"></i> ซ่อนแทน</div>`,
            icon: 'info',
            confirmButtonText: 'เข้าใจแล้ว',
            confirmButtonColor: '#475569',
        });
        return false;
    }
    Swal.fire({
        title: 'ลบจุดใช้งานนี้?',
        html: `<div class="text-sm text-slate-600"><strong>"${name}"</strong> จะถูกลบถาวร<br>การกระทำนี้ย้อนกลับไม่ได้</div>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
        focusCancel: true,
    }).then((res) => { if (res.isConfirmed) form.submit(); });
    return false;
};

window.assetLocToggleConfirm = function (e, form, isActive, name) {
    // เปิดใช้งานอีกครั้ง — ไม่ต้อง confirm
    if (Number(isActive) === 0) return true;
    // กำลังจะปิดใช้งาน — ขอ confirm
    e.preventDefault();
    Swal.fire({
        title: 'ซ่อนจุดใช้งานนี้?',
        html: `<div class="text-sm text-slate-600 leading-relaxed">
                  จุด <strong class="text-slate-800">"${name}"</strong> จะหายจาก dropdown ตอนเลือกจุดในฟอร์มครุภัณฑ์<br>
                  <span class="text-emerald-700">ครุภัณฑ์เก่าที่อยู่ในจุดนี้ยังอยู่ครบ</span> และเปิดกลับได้ภายหลัง
               </div>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'ซ่อนจุดนี้',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#b45309',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true,
        focusCancel: true,
    }).then((res) => { if (res.isConfirmed) form.submit(); });
    return false;
};

function editLocation(l) {
    Swal.fire({
        title: 'แก้ไขจุดใช้งาน',
        html: `
            <input id="el_name" placeholder="ชื่อ *" class="swal2-input" value="${l.name || ''}">
            <input id="el_bld"  placeholder="อาคาร" class="swal2-input" value="${l.building || ''}">
            <input id="el_flr"  placeholder="ชั้น"   class="swal2-input" value="${l.floor || ''}">
        `,
        showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#4f46e5',
        preConfirm: () => {
            const name = document.getElementById('el_name').value.trim();
            if (!name) { Swal.showValidationMessage('กรุณากรอกชื่อ'); return false; }
            return {
                name: name,
                building: document.getElementById('el_bld').value.trim(),
                floor:    document.getElementById('el_flr').value.trim(),
            };
        }
    }).then((res) => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', window.ASSET_CSRF);
        fd.append('action', 'update');
        fd.append('id', l.id);
        fd.append('name', res.value.name);
        fd.append('building', res.value.building);
        fd.append('floor', res.value.floor);
        fetch('admin/manage_locations.php', { method: 'POST', body: fd })
            .then(() => window.location.reload());
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
