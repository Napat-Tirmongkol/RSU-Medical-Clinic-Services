<?php
// asset/admin/asset_form.php — เพิ่ม/แก้ไขครุภัณฑ์
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
asset_require_manage(); // admin/editor only

$pdo  = db();
$id   = (int)($_GET['id'] ?? 0);
$mode = $id > 0 ? 'edit' : 'create';
$err  = '';
$msg  = '';

// ── Defaults ──────────────────────────────────────────────────────────────
$asset = [
    'asset_code' => '', 'rsu_asset_code' => '', 'serial_number' => '',
    'name' => '', 'brand' => '', 'quantity' => 1,
    'purchase_date' => '', 'warranty_text' => '', 'warranty_until' => '',
    'vendor' => '', 'category_id' => 0, 'location_id' => 0,
    'status' => 'in_use', 'image' => '', 'note' => '',
];

if ($mode === 'edit') {
    $stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        header('Location: manage_assets.php');
        exit;
    }
    $asset = array_merge($asset, $row);
}

// ── Submit ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validate_csrf_or_die();

        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') throw new RuntimeException('กรุณากรอกชื่ออุปกรณ์');

        $data = [
            'rsu_asset_code' => trim((string)($_POST['rsu_asset_code'] ?? '')) ?: null,
            'serial_number'  => trim((string)($_POST['serial_number']  ?? '')) ?: null,
            'name'           => $name,
            'brand'          => trim((string)($_POST['brand'] ?? '')) ?: null,
            'quantity'       => max(1, (int)($_POST['quantity'] ?? 1)),
            'purchase_date'  => ($_POST['purchase_date'] ?? '') ?: null,
            'warranty_text'  => trim((string)($_POST['warranty_text'] ?? '')) ?: null,
            'warranty_until' => ($_POST['warranty_until'] ?? '') ?: null,
            'vendor'         => trim((string)($_POST['vendor'] ?? '')) ?: null,
            'category_id'    => (int)($_POST['category_id'] ?? 0) ?: null,
            'location_id'    => (int)($_POST['location_id'] ?? 0) ?: null,
            'custodian_id'   => (int)($_POST['custodian_id'] ?? 0) ?: null,
            'status'         => array_key_exists($_POST['status'] ?? '', asset_status_options())
                                    ? $_POST['status'] : 'in_use',
            'note'           => trim((string)($_POST['note'] ?? '')) ?: null,
        ];

        // รูปภาพ
        $newImage = asset_handle_image_upload('image', $asset['image'] ?? null);
        $data['image'] = $newImage;

        if ($mode === 'create') {
            $data['asset_code']  = asset_generate_code($pdo);
            $data['imported_at'] = date('Y-m-d H:i:s');
            $data['created_by']  = (int)($_SESSION['user_id'] ?? 0) ?: null;

            $cols = implode(',', array_keys($data));
            $ph   = implode(',', array_fill(0, count($data), '?'));
            $stmt = $pdo->prepare("INSERT INTO assets ({$cols}) VALUES ({$ph})");
            $stmt->execute(array_values($data));
            $newId = (int)$pdo->lastInsertId();
            asset_log_movement($pdo, $newId, 'create', null, $data['location_id'], null, $data['status'], 'สร้างใหม่');
            header('Location: manage_assets.php?ok=created');
            exit;
        }

        // edit
        $oldStatus = $asset['status'];
        $oldLoc    = (int)($asset['location_id'] ?? 0) ?: null;
        $data['updated_by'] = (int)($_SESSION['user_id'] ?? 0) ?: null;

        $set = implode(',', array_map(fn($k) => "$k = ?", array_keys($data)));
        $vals = array_values($data);
        $vals[] = $id;
        $stmt = $pdo->prepare("UPDATE assets SET {$set} WHERE id = ?");
        $stmt->execute($vals);

        if ($oldStatus !== $data['status'] || $oldLoc !== $data['location_id']) {
            asset_log_movement(
                $pdo, $id,
                ($oldLoc !== $data['location_id']) ? 'move' : 'status_change',
                $oldLoc, $data['location_id'],
                $oldStatus, $data['status'],
                'แก้ไขจากฟอร์ม'
            );
        }
        header('Location: manage_assets.php?ok=updated');
        exit;
    } catch (Throwable $e) {
        $err = $e->getMessage();
        // เก็บค่าจากฟอร์มไว้ให้ผู้ใช้แก้ต่อ
        $asset = array_merge($asset, $_POST);
    }
}

$categories = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations  = $pdo->query("SELECT id, name FROM asset_locations  WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$custodians = [];
try {
    $custodians = $pdo->query("SELECT id, full_name FROM sys_staff WHERE account_status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* table may not exist on test envs */ }

$page_title   = $mode === 'edit' ? 'แก้ไขครุภัณฑ์' : 'เพิ่มครุภัณฑ์';
$current_page = 'assets';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4">
    <div>
        <h2 class="text-xl font-extrabold text-slate-800">
            <i class="fas <?= $mode === 'edit' ? 'fa-pen-to-square' : 'fa-plus-circle' ?> text-[#2e9e63]"></i>
            <?= htmlspecialchars($page_title) ?>
        </h2>
        <?php if ($mode === 'edit'): ?>
            <p class="text-sm text-slate-500">รหัส: <span class="font-mono text-[#2e9e63] font-bold"><?= htmlspecialchars($asset['asset_code']) ?></span></p>
        <?php endif; ?>
    </div>
    <a href="admin/manage_assets.php" class="btn-asset btn-asset-ghost">
        <i class="fas fa-arrow-left"></i> กลับ
    </a>
</div>

<?php if ($err): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="asset-card p-5 space-y-5">
    <?php csrf_field(); ?>

    <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
        <div class="md:col-span-6">
            <label class="asset-label">ชื่ออุปกรณ์ <span class="text-rose-500">*</span></label>
            <input type="text" name="name" required value="<?= htmlspecialchars($asset['name'] ?? '') ?>" class="asset-input">
        </div>
        <div class="md:col-span-3">
            <label class="asset-label">ยี่ห้อ</label>
            <input type="text" name="brand" value="<?= htmlspecialchars($asset['brand'] ?? '') ?>" class="asset-input">
        </div>
        <div class="md:col-span-3">
            <label class="asset-label">จำนวน</label>
            <input type="number" min="1" inputmode="numeric" pattern="[0-9]*" name="quantity" value="<?= (int)($asset['quantity'] ?? 1) ?>" class="asset-input">
        </div>

        <div class="md:col-span-4">
            <label class="asset-label">หมายเลขเครื่อง S/N</label>
            <input type="text" name="serial_number" value="<?= htmlspecialchars($asset['serial_number'] ?? '') ?>" class="asset-input">
        </div>
        <div class="md:col-span-4">
            <label class="asset-label">S/N ฝ่ายจัดซื้อพัสดุ มรส</label>
            <input type="text" name="rsu_asset_code" value="<?= htmlspecialchars($asset['rsu_asset_code'] ?? '') ?>" class="asset-input">
        </div>
        <div class="md:col-span-4">
            <label class="asset-label">บริษัทที่ซื้อ</label>
            <input type="text" name="vendor" value="<?= htmlspecialchars($asset['vendor'] ?? '') ?>" class="asset-input">
        </div>

        <div class="md:col-span-4">
            <label class="asset-label">วันที่ซื้อ</label>
            <input type="date" name="purchase_date" value="<?= htmlspecialchars($asset['purchase_date'] ?? '') ?>" class="asset-input">
        </div>
        <div class="md:col-span-4">
            <label class="asset-label">การรับประกัน (ข้อความ)</label>
            <input type="text" name="warranty_text" placeholder="เช่น 1 ปี" value="<?= htmlspecialchars($asset['warranty_text'] ?? '') ?>" class="asset-input">
        </div>
        <div class="md:col-span-4">
            <label class="asset-label">วันสิ้นสุดประกัน</label>
            <input type="date" name="warranty_until" value="<?= htmlspecialchars($asset['warranty_until'] ?? '') ?>" class="asset-input">
        </div>

        <div class="md:col-span-4">
            <label class="asset-label">หมวดหมู่</label>
            <select name="category_id" class="asset-input">
                <option value="0">— ไม่ระบุ —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)($asset['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-4">
            <label class="asset-label">จุดใช้งาน</label>
            <select name="location_id" class="asset-input">
                <option value="0">— ไม่ระบุ —</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= (int)($asset['location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($l['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-4">
            <label class="asset-label">สถานะ</label>
            <select name="status" class="asset-input">
                <?php foreach (asset_status_options() as $k => $label): ?>
                    <option value="<?= $k ?>" <?= ($asset['status'] ?? '') === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="md:col-span-6">
            <label class="asset-label">ผู้รับผิดชอบ (Custodian)</label>
            <select name="custodian_id" class="asset-input">
                <option value="0">— ไม่ระบุ —</option>
                <?php foreach ($custodians as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= (int)($asset['custodian_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['full_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="md:col-span-12">
            <label class="asset-label">หมายเหตุ</label>
            <textarea name="note" rows="3" class="asset-input"><?= htmlspecialchars($asset['note'] ?? '') ?></textarea>
        </div>

        <div class="md:col-span-12">
            <label class="asset-label">รูปภาพสินค้า (jpg/png/webp ≤ 5MB)</label>
            <div class="flex items-center gap-4">
                <?php if (!empty($asset['image'])): ?>
                    <img src="<?= htmlspecialchars($asset['image']) ?>" alt="" class="w-24 h-24 object-cover rounded-xl border border-slate-200">
                <?php endif; ?>
                <input type="file" name="image" accept="image/*" capture="environment" class="asset-input flex-1">
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
        <a href="admin/manage_assets.php" class="btn-asset btn-asset-ghost">ยกเลิก</a>
        <button type="submit" class="btn-asset btn-asset-primary">
            <i class="fas fa-save"></i> บันทึก
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
