<?php
// consumables/admin/issue_form.php — เบิกออก (ผูกหน่วยงาน/คณะ)
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();
$preselect = (int)($_GET['consumable_id'] ?? 0);
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token ไม่ถูกต้อง';
    }

    $consumableId  = (int)($_POST['consumable_id'] ?? 0);
    $unitInput     = ($_POST['unit_input'] ?? 'piece') === 'pack' ? 'pack' : 'piece';
    $qtyInput      = (int)($_POST['qty_input'] ?? 0);
    $facultyId     = (int)($_POST['faculty_id'] ?? 0) ?: null;
    $requesterName = trim((string)($_POST['requester_name'] ?? ''));
    $purpose       = trim((string)($_POST['purpose'] ?? ''));
    $reference     = trim((string)($_POST['reference'] ?? ''));
    $note          = trim((string)($_POST['note'] ?? ''));
    $txnDate       = $_POST['txn_date'] ?? date('Y-m-d');

    if ($consumableId <= 0) $errors[] = 'กรุณาเลือกวัสดุที่จะเบิก';
    if ($qtyInput <= 0)     $errors[] = 'จำนวนต้องมากกว่า 0';

    $consumable = null;
    if ($consumableId > 0) {
        $st = $pdo->prepare("SELECT * FROM consumables WHERE id = ?");
        $st->execute([$consumableId]);
        $consumable = $st->fetch(PDO::FETCH_ASSOC);
        if (!$consumable) $errors[] = 'ไม่พบรายการวัสดุที่เลือก';
    }

    if (empty($errors) && $consumable) {
        // คำนวณจำนวนชิ้น
        $packSize  = max(1, (int)$consumable['pack_size']);
        $qtyPieces = $unitInput === 'pack' ? $qtyInput * $packSize : $qtyInput;

        try {
            $pdo->beginTransaction();
            csm_log_txn(
                $pdo, $consumableId, 'issue',
                -$qtyPieces, $unitInput, $qtyInput,
                $facultyId, $requesterName ?: null, $purpose ?: null, $reference ?: null, $note ?: null, $txnDate
            );
            $pdo->commit();
            $success = true;
            $preselect = $consumableId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// โหลดรายการวัสดุที่ active เท่านั้น
$consumables = $pdo->query("
    SELECT id, code, name, brand, qty_on_hand, unit_pack, unit_piece, pack_size
    FROM consumables WHERE status = 'active'
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$faculties = csm_faculty_list($pdo);

$page_title   = 'เบิกออก';
$current_page = 'issue';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <a href="admin/manage_consumables.php" class="text-sm text-slate-500 hover:text-[#2e9e63]">
        <i class="fas fa-arrow-left"></i> กลับรายการ
    </a>
</div>

<?php if ($success): ?>
    <div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
        <i class="fas fa-circle-check"></i> บันทึกการเบิกเรียบร้อย
        <a href="admin/consumable_view.php?id=<?= $preselect ?>" class="ml-2 font-bold underline">ดูรายละเอียด</a>
    </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        <ul class="list-disc list-inside">
            <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="asset-card p-5 max-w-3xl mx-auto">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

    <h2 class="asset-sec-title mb-5"><i class="fa-solid fa-hand-holding text-amber-600 mr-2"></i>บันทึกการเบิกออก</h2>

    <div class="space-y-4">
        <div>
            <label class="asset-label">วัสดุ <span class="text-rose-500">*</span></label>
            <select name="consumable_id" id="consumable_id" required class="asset-input" onchange="csmUpdateStockHint()">
                <option value="">— เลือกวัสดุ —</option>
                <?php foreach ($consumables as $c): ?>
                    <option value="<?= $c['id'] ?>"
                            data-stock="<?= (int)$c['qty_on_hand'] ?>"
                            data-pack="<?= (int)$c['pack_size'] ?>"
                            data-unit-pack="<?= htmlspecialchars($c['unit_pack'] ?? '') ?>"
                            data-unit-piece="<?= htmlspecialchars($c['unit_piece']) ?>"
                            <?= $preselect === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['code']) ?> · <?= htmlspecialchars($c['name']) ?>
                        <?= !empty($c['brand']) ? ' (' . htmlspecialchars($c['brand']) . ')' : '' ?>
                        — คงเหลือ <?= (int)$c['qty_on_hand'] ?> <?= htmlspecialchars($c['unit_piece']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div id="stock_hint" class="text-xs text-slate-500 mt-1"></div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="md:col-span-2">
                <label class="asset-label">จำนวน <span class="text-rose-500">*</span></label>
                <input type="number" name="qty_input" id="qty_input" min="1" required class="asset-input" placeholder="เช่น 1 หรือ 40">
            </div>
            <div>
                <label class="asset-label">หน่วย</label>
                <select name="unit_input" id="unit_input" class="asset-input" onchange="csmUpdateStockHint()">
                    <option value="piece">ชิ้น (ย่อย)</option>
                    <option value="pack">บรรจุภัณฑ์</option>
                </select>
            </div>
        </div>
        <div id="qty_calc_hint" class="text-xs text-emerald-700 -mt-2"></div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="asset-label">หน่วยงาน / คณะ <span class="text-rose-500">*</span></label>
                <select name="faculty_id" required class="asset-input">
                    <option value="">— เลือกหน่วยงาน —</option>
                    <?php if (!empty($faculties['department'])): ?>
                        <optgroup label="หน่วยงาน">
                            <?php foreach ($faculties['department'] as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name_th']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if (!empty($faculties['faculty'])): ?>
                        <optgroup label="คณะ">
                            <?php foreach ($faculties['faculty'] as $f): ?>
                                <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name_th']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label class="asset-label">วันที่เบิก</label>
                <input type="date" name="txn_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="asset-input">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
                <label class="asset-label">ผู้มารับ (ชื่อ)</label>
                <input type="text" name="requester_name" class="asset-input" placeholder="ชื่อบุคคลที่มารับของ">
            </div>
            <div>
                <label class="asset-label">เลขที่เอกสาร / อ้างอิง</label>
                <input type="text" name="reference" class="asset-input" placeholder="เช่น MEM-001/68">
            </div>
        </div>

        <div>
            <label class="asset-label">วัตถุประสงค์</label>
            <input type="text" name="purpose" class="asset-input" placeholder="เช่น ใช้กิจกรรม Health Day">
        </div>

        <div>
            <label class="asset-label">หมายเหตุ</label>
            <textarea name="note" rows="2" class="asset-input"></textarea>
        </div>
    </div>

    <div class="mt-6 flex items-center justify-end gap-2">
        <a href="admin/manage_consumables.php" class="btn-asset btn-asset-ghost">ยกเลิก</a>
        <button type="submit" class="btn-asset btn-asset-primary">
            <i class="fas fa-hand-holding"></i> บันทึกการเบิก
        </button>
    </div>
</form>

<script>
function csmUpdateStockHint() {
    const sel  = document.getElementById('consumable_id');
    const hint = document.getElementById('stock_hint');
    const calc = document.getElementById('qty_calc_hint');
    const opt  = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { hint.textContent = ''; calc.textContent = ''; return; }

    const stock     = parseInt(opt.dataset.stock || '0', 10);
    const pack      = parseInt(opt.dataset.pack  || '1', 10);
    const unitPack  = opt.dataset.unitPack  || 'กล่อง';
    const unitPiece = opt.dataset.unitPiece || 'ชิ้น';
    hint.innerHTML = '<i class="fa-solid fa-circle-info"></i> คงเหลือ <strong>' + stock + '</strong> ' + unitPiece +
                     (pack > 1 ? ' (1 ' + unitPack + ' = ' + pack + ' ' + unitPiece + ')' : '');

    csmRecalcQty();
}
function csmRecalcQty() {
    const sel = document.getElementById('consumable_id');
    const opt = sel.options[sel.selectedIndex];
    const calc = document.getElementById('qty_calc_hint');
    if (!opt || !opt.value) { calc.textContent = ''; return; }
    const qty   = parseInt(document.getElementById('qty_input').value || '0', 10);
    const unit  = document.getElementById('unit_input').value;
    const pack  = parseInt(opt.dataset.pack  || '1', 10);
    const unitPack  = opt.dataset.unitPack  || 'กล่อง';
    const unitPiece = opt.dataset.unitPiece || 'ชิ้น';
    if (qty > 0 && unit === 'pack' && pack > 1) {
        calc.textContent = '= ' + (qty * pack) + ' ' + unitPiece;
    } else { calc.textContent = ''; }
}
document.getElementById('qty_input').addEventListener('input', csmRecalcQty);
csmUpdateStockHint();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
