<?php
// consumables/admin/consumable_form.php — เพิ่ม/แก้ไขวัสดุสิ้นเปลือง
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
csm_require_manage();

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];
$item   = [
    'id' => 0, 'code' => '', 'name' => '', 'brand' => '',
    'category_id' => 0, 'location_id' => 0,
    'unit_pack' => 'กล่อง', 'unit_piece' => 'ชิ้น', 'pack_size' => 1,
    'qty_on_hand' => 0, 'min_stock' => 0,
    'image' => null, 'note' => '', 'status' => 'active',
];

// ── Load existing for edit ────────────────────────────────────────────────
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM consumables WHERE id = ?");
    $stmt->execute([$id]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        exit('ไม่พบรายการ');
    }
    $item = array_merge($item, $found);
}

// ── POST handler ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token ไม่ถูกต้อง';
    }

    $name        = trim((string)($_POST['name'] ?? ''));
    $brand       = trim((string)($_POST['brand'] ?? ''));
    $categoryId  = (int)($_POST['category_id'] ?? 0) ?: null;
    $locationId  = (int)($_POST['location_id'] ?? 0) ?: null;
    $unitPack    = trim((string)($_POST['unit_pack']  ?? ''));
    $unitPiece   = trim((string)($_POST['unit_piece'] ?? 'ชิ้น'));
    $packSize    = max(1, (int)($_POST['pack_size'] ?? 1));
    $minStock    = max(0, (int)($_POST['min_stock'] ?? 0));
    $note        = trim((string)($_POST['note'] ?? ''));
    $status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

    // ยอดเริ่มต้น (เฉพาะตอนสร้างใหม่)
    $initialQty       = max(0, (int)($_POST['initial_qty']       ?? 0));
    $initialUnitInput = ($_POST['initial_unit'] ?? 'piece') === 'pack' ? 'pack' : 'piece';

    if ($name === '')   $errors[] = 'กรุณากรอกชื่อวัสดุ';
    if ($unitPiece === '') $errors[] = 'กรุณากรอกหน่วยย่อย';
    if ($packSize < 1)  $errors[] = 'จำนวนต่อบรรจุภัณฑ์ต้องอย่างน้อย 1';

    // ── Image upload ──
    $imagePath = $item['image'];
    try {
        $imagePath = csm_handle_image_upload('image', $item['image']);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            if ($isEdit) {
                $stmt = $pdo->prepare("UPDATE consumables SET
                    name=?, brand=?, category_id=?, location_id=?,
                    unit_pack=?, unit_piece=?, pack_size=?, min_stock=?,
                    image=?, note=?, status=?, updated_by=?
                    WHERE id=?");
                $stmt->execute([
                    $name, $brand, $categoryId, $locationId,
                    $unitPack ?: null, $unitPiece, $packSize, $minStock,
                    $imagePath, $note, $status, $_SESSION['user_id'] ?? null,
                    $id,
                ]);
            } else {
                $code = csm_generate_code($pdo);
                $stmt = $pdo->prepare("INSERT INTO consumables
                    (code, name, brand, category_id, location_id,
                     unit_pack, unit_piece, pack_size, qty_on_hand, min_stock,
                     image, note, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $code, $name, $brand, $categoryId, $locationId,
                    $unitPack ?: null, $unitPiece, $packSize, $minStock,
                    $imagePath, $note, $status, $_SESSION['user_id'] ?? null,
                ]);
                $id = (int)$pdo->lastInsertId();

                // ถ้ามียอดเริ่มต้น ให้บันทึกเป็น receive transaction
                if ($initialQty > 0) {
                    $qtyPieces = $initialUnitInput === 'pack' ? $initialQty * $packSize : $initialQty;
                    csm_log_txn(
                        $pdo, $id, 'receive',
                        +$qtyPieces, $initialUnitInput, $initialQty,
                        null, null, 'ยอดยกมา (สร้างรายการ)',
                        null, null, date('Y-m-d')
                    );
                }
            }

            $pdo->commit();
            header('Location: manage_consumables.php?saved=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }

    // คงค่าที่กรอกไว้
    $item = array_merge($item, [
        'name' => $name, 'brand' => $brand,
        'category_id' => $categoryId, 'location_id' => $locationId,
        'unit_pack' => $unitPack, 'unit_piece' => $unitPiece, 'pack_size' => $packSize,
        'min_stock' => $minStock, 'note' => $note, 'status' => $status, 'image' => $imagePath,
    ]);
}

$categories = $pdo->query("SELECT id, name FROM consumable_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$locations  = $pdo->query("SELECT id, name FROM asset_locations WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title   = $isEdit ? 'แก้ไขวัสดุสิ้นเปลือง' : 'เพิ่มวัสดุสิ้นเปลือง';
$current_page = 'consumables';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <a href="admin/manage_consumables.php" class="text-sm text-slate-500 hover:text-[#2e9e63]">
        <i class="fas fa-arrow-left"></i> กลับรายการ
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        <ul class="list-disc list-inside">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="asset-card p-5 max-w-4xl mx-auto">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <h2 class="asset-sec-title mb-5"><?= $isEdit ? 'แก้ไข: ' . htmlspecialchars($item['code']) : 'เพิ่มวัสดุสิ้นเปลือง' ?></h2>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <label class="asset-label">ชื่อวัสดุ <span class="text-rose-500">*</span></label>
            <input type="text" name="name" required value="<?= htmlspecialchars($item['name']) ?>"
                   placeholder="เช่น ถุงยาง XL, หน้ากากอนามัย, เข็มฉีดยา"
                   class="asset-input">
        </div>

        <div>
            <label class="asset-label">ยี่ห้อ</label>
            <input type="text" name="brand" value="<?= htmlspecialchars($item['brand'] ?? '') ?>" class="asset-input">
        </div>

        <div>
            <label class="asset-label">หมวดหมู่</label>
            <select name="category_id" class="asset-input">
                <option value="0">— ไม่ระบุ —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)$item['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="asset-label">จุดจัดเก็บ</label>
            <select name="location_id" class="asset-input">
                <option value="0">— ไม่ระบุ —</option>
                <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= (int)$item['location_id'] === (int)$l['id'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="asset-label">สถานะ</label>
            <select name="status" class="asset-input">
                <option value="active"   <?= $item['status'] === 'active'   ? 'selected' : '' ?>>ใช้งาน</option>
                <option value="inactive" <?= $item['status'] === 'inactive' ? 'selected' : '' ?>>ปิด</option>
            </select>
        </div>
    </div>

    <!-- ── หน่วย & บรรจุภัณฑ์ ── -->
    <div class="mt-5 p-4 rounded-xl bg-[#f0faf4]/50 border border-[#c7e8d5]">
        <div class="text-sm font-bold text-[#2e7d52] mb-3"><i class="fa-solid fa-box-open mr-1"></i> หน่วยและบรรจุภัณฑ์</div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="asset-label">หน่วยบรรจุภัณฑ์ใหญ่</label>
                <input type="text" name="unit_pack" value="<?= htmlspecialchars($item['unit_pack'] ?? 'กล่อง') ?>"
                       placeholder="เช่น กล่อง, แพ็ค, ขวด" class="asset-input">
                <p class="text-[11px] text-slate-500 mt-1">เว้นว่างได้ถ้าไม่มีบรรจุภัณฑ์</p>
            </div>
            <div>
                <label class="asset-label">หน่วยย่อย <span class="text-rose-500">*</span></label>
                <input type="text" name="unit_piece" required value="<?= htmlspecialchars($item['unit_piece'] ?? 'ชิ้น') ?>"
                       placeholder="เช่น ชิ้น, เม็ด, ml" class="asset-input">
            </div>
            <div>
                <label class="asset-label">จำนวนต่อหน่วยใหญ่</label>
                <input type="number" name="pack_size" min="1" value="<?= (int)($item['pack_size'] ?? 1) ?>" class="asset-input">
                <p class="text-[11px] text-slate-500 mt-1">เช่น 1 กล่อง = 40 ชิ้น → ใส่ 40</p>
            </div>
        </div>
    </div>

    <!-- ── Stock controls ── -->
    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="asset-label">จุดสั่งซื้อ (min stock)</label>
            <input type="number" name="min_stock" min="0" value="<?= (int)($item['min_stock'] ?? 0) ?>" class="asset-input">
            <p class="text-[11px] text-slate-500 mt-1">แจ้งเตือนเมื่อยอดคงเหลือ ≤ ค่านี้ (ใส่ 0 = ปิด)</p>
        </div>
        <?php if (!$isEdit): ?>
            <div>
                <label class="asset-label">ยอดยกมา (เริ่มต้น)</label>
                <div class="flex gap-2">
                    <input type="number" name="initial_qty" min="0" value="0" class="asset-input flex-1">
                    <select name="initial_unit" class="asset-input" style="width:auto">
                        <option value="piece">ชิ้น</option>
                        <option value="pack">บรรจุภัณฑ์</option>
                    </select>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">บันทึกเป็น "รับเข้า" อัตโนมัติ</p>
            </div>
        <?php else: ?>
            <div>
                <label class="asset-label">ยอดคงเหลือปัจจุบัน</label>
                <div class="asset-input bg-slate-50 cursor-not-allowed">
                    <strong class="text-emerald-700"><?= number_format((int)$item['qty_on_hand']) ?></strong> <?= htmlspecialchars($item['unit_piece']) ?>
                </div>
                <p class="text-[11px] text-slate-500 mt-1">ปรับยอดผ่านหน้า "รับเข้า" / "เบิกออก"</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Image & Note ── -->
    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="asset-label">รูปภาพ</label>
            <?php if (!empty($item['image'])): ?>
                <img src="<?= htmlspecialchars($item['image']) ?>" class="mb-2 rounded-lg max-h-32 border border-slate-200">
            <?php endif; ?>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" class="asset-input">
        </div>
        <div>
            <label class="asset-label">หมายเหตุ</label>
            <textarea name="note" rows="4" class="asset-input"><?= htmlspecialchars($item['note'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="mt-6 flex items-center justify-end gap-2">
        <a href="admin/manage_consumables.php" class="btn-asset btn-asset-ghost">ยกเลิก</a>
        <button type="submit" class="btn-asset btn-asset-primary">
            <i class="fas fa-save"></i> บันทึก
        </button>
    </div>
</form>

<?php include __DIR__ . '/../includes/footer.php'; ?>
