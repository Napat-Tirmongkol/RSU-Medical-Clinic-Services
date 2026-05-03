<?php
// consumables/admin/manage_categories.php — CRUD หมวดหมู่
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
csm_require_manage();

$pdo = db();
$flash = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $flash = 'CSRF token ไม่ถูกต้อง'; $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create') {
                $code = trim((string)($_POST['code'] ?? ''));
                $name = trim((string)($_POST['name'] ?? ''));
                $desc = trim((string)($_POST['description'] ?? ''));
                if ($name === '') throw new RuntimeException('กรุณากรอกชื่อหมวด');
                $stmt = $pdo->prepare("INSERT INTO consumable_categories (code, name, description) VALUES (?, ?, ?)");
                $stmt->execute([$code ?: null, $name, $desc ?: null]);
                $flash = 'เพิ่มหมวดหมู่เรียบร้อย'; $flashType = 'ok';
            } elseif ($action === 'update') {
                $id   = (int)($_POST['id'] ?? 0);
                $code = trim((string)($_POST['code'] ?? ''));
                $name = trim((string)($_POST['name'] ?? ''));
                $desc = trim((string)($_POST['description'] ?? ''));
                if ($id <= 0 || $name === '') throw new RuntimeException('ข้อมูลไม่ครบ');
                $stmt = $pdo->prepare("UPDATE consumable_categories SET code=?, name=?, description=? WHERE id=?");
                $stmt->execute([$code ?: null, $name, $desc ?: null, $id]);
                $flash = 'อัปเดตเรียบร้อย'; $flashType = 'ok';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('id ไม่ถูกต้อง');
                $usedCnt = (int)$pdo->query("SELECT COUNT(*) FROM consumables WHERE category_id = {$id}")->fetchColumn();
                if ($usedCnt > 0) throw new RuntimeException('ไม่สามารถลบได้ — มีวัสดุ ' . $usedCnt . ' รายการอ้างอิงหมวดนี้');
                $stmt = $pdo->prepare("DELETE FROM consumable_categories WHERE id = ?");
                $stmt->execute([$id]);
                $flash = 'ลบเรียบร้อย'; $flashType = 'ok';
            }
        } catch (Throwable $e) {
            $flash = $e->getMessage(); $flashType = 'error';
        }
    }
}

$categories = $pdo->query("
    SELECT c.*, (SELECT COUNT(*) FROM consumables WHERE category_id = c.id) AS item_count
    FROM consumable_categories c
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'หมวดหมู่วัสดุ';
$current_page = 'categories';
include __DIR__ . '/../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="mb-4 p-4 rounded-xl <?= $flashType === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-rose-50 border border-rose-200 text-rose-700' ?> text-sm">
        <i class="fas <?= $flashType === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Add form -->
    <div class="asset-card p-5">
        <h2 class="asset-sec-title mb-4">เพิ่มหมวดหมู่</h2>
        <form method="post" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="asset-label">รหัส (เลือกใส่)</label>
                <input type="text" name="code" maxlength="20" class="asset-input" placeholder="MED">
            </div>
            <div>
                <label class="asset-label">ชื่อ <span class="text-rose-500">*</span></label>
                <input type="text" name="name" required class="asset-input">
            </div>
            <div>
                <label class="asset-label">คำอธิบาย</label>
                <textarea name="description" rows="2" class="asset-input"></textarea>
            </div>
            <button type="submit" class="btn-asset btn-asset-primary w-full justify-center">
                <i class="fas fa-plus"></i> เพิ่มหมวดหมู่
            </button>
        </form>
    </div>

    <!-- List -->
    <div class="lg:col-span-2 asset-card p-2 sm:p-4">
        <div class="overflow-x-auto">
            <table class="asset-table">
                <thead><tr>
                    <th>รหัส</th><th>ชื่อ</th><th>คำอธิบาย</th>
                    <th class="text-center">รายการ</th>
                    <th class="text-center" style="width:140px">จัดการ</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr><td colspan="5" class="text-center text-slate-400 py-10 text-sm">ยังไม่มีหมวดหมู่</td></tr>
                    <?php else: foreach ($categories as $c): ?>
                        <tr>
                            <td data-label="รหัส" class="font-mono text-xs text-slate-500"><?= htmlspecialchars($c['code'] ?? '-') ?></td>
                            <td data-label="ชื่อ" class="font-bold text-slate-800"><?= htmlspecialchars($c['name']) ?></td>
                            <td data-label="คำอธิบาย" class="text-sm text-slate-600"><?= htmlspecialchars($c['description'] ?? '') ?></td>
                            <td data-label="รายการ" class="text-center font-bold"><?= (int)$c['item_count'] ?></td>
                            <td data-label="จัดการ">
                                <div class="flex items-center gap-1 justify-center">
                                    <button type="button" class="btn-asset btn-asset-secondary"
                                            onclick="csmEditCat(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['code'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($c['name'])) ?>', '<?= htmlspecialchars(addslashes($c['description'] ?? '')) ?>')">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="post" onsubmit="return confirm('ลบหมวดหมู่นี้?')" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit" class="btn-asset btn-asset-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit modal (lightweight) -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:200;align-items:center;justify-content:center;padding:16px">
    <form method="post" class="bg-white rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="edit_id">
        <h3 class="font-extrabold text-lg text-slate-800">แก้ไขหมวดหมู่</h3>
        <div>
            <label class="asset-label">รหัส</label>
            <input type="text" name="code" id="edit_code" class="asset-input">
        </div>
        <div>
            <label class="asset-label">ชื่อ</label>
            <input type="text" name="name" id="edit_name" required class="asset-input">
        </div>
        <div>
            <label class="asset-label">คำอธิบาย</label>
            <textarea name="description" id="edit_desc" rows="2" class="asset-input"></textarea>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" onclick="document.getElementById('editModal').style.display='none'" class="btn-asset btn-asset-ghost">ยกเลิก</button>
            <button type="submit" class="btn-asset btn-asset-primary">บันทึก</button>
        </div>
    </form>
</div>

<script>
function csmEditCat(id, code, name, desc) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_code').value = code;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_desc').value = desc;
    document.getElementById('editModal').style.display = 'flex';
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
