<?php
// asset/admin/manage_categories.php — จัดการหมวดหมู่ครุภัณฑ์
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
            $code = trim((string)($_POST['code'] ?? '')) ?: null;
            $desc = trim((string)($_POST['description'] ?? '')) ?: null;
            if ($name === '') throw new RuntimeException('กรุณากรอกชื่อหมวดหมู่');
            $stmt = $pdo->prepare("INSERT INTO asset_categories (code, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$code, $name, $desc]);
            $msg = 'เพิ่มหมวดหมู่สำเร็จ';
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $code = trim((string)($_POST['code'] ?? '')) ?: null;
            $desc = trim((string)($_POST['description'] ?? '')) ?: null;
            if ($id <= 0 || $name === '') throw new RuntimeException('ข้อมูลไม่ถูกต้อง');
            $stmt = $pdo->prepare("UPDATE asset_categories SET code=?, name=?, description=? WHERE id=?");
            $stmt->execute([$code, $name, $desc, $id]);
            $msg = 'แก้ไขสำเร็จ';
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE category_id=?");
            $cnt->execute([$id]);
            if ((int)$cnt->fetchColumn() > 0) {
                throw new RuntimeException('ลบไม่ได้: ยังมีครุภัณฑ์ใช้หมวดนี้อยู่');
            }
            $pdo->prepare("DELETE FROM asset_categories WHERE id=?")->execute([$id]);
            $msg = 'ลบสำเร็จ';
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
    $where = 'WHERE c.name LIKE ? OR c.code LIKE ? OR c.description LIKE ?';
    $like  = "%{$search}%";
    $params = [$like, $like, $like];
}

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_categories c {$where}");
$cntStmt->execute($params);
$total = (int)$cntStmt->fetchColumn();
$totalPages = (int)max(1, ceil($total / $perPage));

$sql = "SELECT c.*, (SELECT COUNT(*) FROM assets a WHERE a.category_id = c.id) AS asset_count
        FROM asset_categories c {$where}
        ORDER BY c.name ASC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'หมวดหมู่ครุภัณฑ์';
$current_page = 'categories';
$extraQuery   = $search !== '' ? ['search' => $search] : [];
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4">
    <h2 class="asset-sec-title">หมวดหมู่ครุภัณฑ์</h2>
</div>

<?php if ($msg): ?><div class="mb-3 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="mb-3 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="asset-card p-5 lg:col-span-1">
        <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-plus-circle text-[#2e9e63]"></i> เพิ่มหมวดหมู่ใหม่</h3>
        <form method="post" class="space-y-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div>
                <label class="asset-label">รหัส (เช่น IT)</label>
                <input type="text" name="code" maxlength="20" class="asset-input">
            </div>
            <div>
                <label class="asset-label">ชื่อ <span class="text-rose-500">*</span></label>
                <input type="text" name="name" required class="asset-input">
            </div>
            <div>
                <label class="asset-label">คำอธิบาย</label>
                <input type="text" name="description" class="asset-input">
            </div>
            <button type="submit" class="btn-asset btn-asset-primary w-full justify-center">
                <i class="fas fa-save"></i> บันทึก
            </button>
        </form>
    </div>

    <div class="asset-card p-4 lg:col-span-2">
        <form method="get" class="mb-3">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาหมวดหมู่..." class="asset-input pl-9">
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="asset-table">
                <thead><tr>
                    <th>รหัส</th><th>ชื่อ</th><th>คำอธิบาย</th><th class="text-center">จำนวนครุภัณฑ์</th><th class="text-center" style="width:140px">จัดการ</th>
                </tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="5" class="text-center text-slate-400 py-8">ไม่พบหมวดหมู่</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td data-label="รหัส" class="font-mono text-xs text-[#2e9e63]"><?= htmlspecialchars($r['code'] ?? '-') ?></td>
                        <td data-label="ชื่อ"><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                        <td data-label="คำอธิบาย" class="text-sm text-slate-500"><?= htmlspecialchars($r['description'] ?? '-') ?></td>
                        <td data-label="จำนวน" class="text-center font-bold"><?= (int)$r['asset_count'] ?></td>
                        <td data-label="จัดการ">
                            <div class="flex items-center justify-center gap-1">
                                <button type="button" class="btn-asset btn-asset-secondary"
                                        onclick='editCategory(<?= json_encode($r, JSON_UNESCAPED_UNICODE) ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="post" onsubmit="return confirm('ลบหมวดหมู่นี้?')" class="inline">
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
function editCategory(c) {
    Swal.fire({
        title: 'แก้ไขหมวดหมู่',
        html: `
            <input id="ec_code" placeholder="รหัส" class="swal2-input" value="${c.code || ''}">
            <input id="ec_name" placeholder="ชื่อ *" class="swal2-input" value="${c.name || ''}">
            <input id="ec_desc" placeholder="คำอธิบาย" class="swal2-input" value="${c.description || ''}">
        `,
        showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#4f46e5',
        preConfirm: () => {
            const name = document.getElementById('ec_name').value.trim();
            if (!name) { Swal.showValidationMessage('กรุณากรอกชื่อ'); return false; }
            return {
                code: document.getElementById('ec_code').value.trim(),
                name: name,
                description: document.getElementById('ec_desc').value.trim(),
            };
        }
    }).then((res) => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', window.ASSET_CSRF);
        fd.append('action', 'update');
        fd.append('id', c.id);
        fd.append('code', res.value.code);
        fd.append('name', res.value.name);
        fd.append('description', res.value.description);
        fetch('manage_categories.php', { method: 'POST', body: fd })
            .then(() => window.location.reload());
    });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
