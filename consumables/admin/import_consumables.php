<?php
// consumables/admin/import_consumables.php — Import วัสดุสิ้นเปลืองจาก Excel/CSV
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
csm_require_manage();

require_once __DIR__ . '/../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$pdo = db();

/** Mapping ชื่อคอลัมน์ → field — รองรับ alias หลายแบบ */
$columnAliases = [
    'name'        => ['ชื่อวัสดุ', 'ชื่อ', 'name'],
    'brand'       => ['ยี่ห้อ', 'brand'],
    'category'    => ['หมวดหมู่', 'category'],
    'location'    => ['จุดจัดเก็บ', 'จุดเก็บ', 'location'],
    'unit_pack'   => ['หน่วยใหญ่', 'หน่วยบรรจุภัณฑ์', 'unit_pack'],
    'unit_piece'  => ['หน่วยย่อย', 'หน่วยนับ', 'unit_piece', 'หน่วย'],
    'pack_size'   => ['จำนวนต่อหน่วยใหญ่', 'จำนวนต่อกล่อง', 'pack_size'],
    'min_stock'   => ['จุดสั่งซื้อ', 'min_stock'],
    'initial_qty' => ['ยอดเริ่มต้น', 'ยอดยกมา', 'initial_qty', 'qty'],
    'note'        => ['หมายเหตุ', 'note'],
];

$normalize = fn(string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)) ?? '', 'UTF-8');

$resolveField = function (string $header) use ($columnAliases, $normalize): ?string {
    $h = $normalize($header);
    foreach ($columnAliases as $field => $aliases) {
        foreach ($aliases as $alias) {
            if ($h === $normalize($alias)) return $field;
        }
    }
    return null;
};

$step    = $_POST['step'] ?? 'upload';
$message = '';
$error   = '';
$preview = null;

// ── Download template ────────────────────────────────────────────────────
if (($_GET['template'] ?? '') === '1') {
    $sp = new Spreadsheet();
    $sh = $sp->getActiveSheet();
    $sh->setTitle('วัสดุสิ้นเปลือง');
    $headers = ['ชื่อวัสดุ','ยี่ห้อ','หมวดหมู่','จุดจัดเก็บ','หน่วยใหญ่','หน่วยย่อย','จำนวนต่อหน่วยใหญ่','จุดสั่งซื้อ','ยอดเริ่มต้น','หมายเหตุ'];
    foreach ($headers as $i => $h) $sh->setCellValueByColumnAndRow($i + 1, 1, $h);
    $sh->getStyle('A1:J1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2E9E63']],
        'alignment' => ['horizontal' => 'center'],
    ]);
    $sample = [
        ['ถุงยาง XL', 'Durex', 'ถุงยาง / Contraception', 'ห้องยา', 'กล่อง', 'ชิ้น', 40, 50, 200, 'ตัวอย่าง'],
        ['หน้ากากอนามัย', '3M', 'เวชภัณฑ์ทางการแพทย์', 'ห้องยา', 'แพ็ค', 'ชิ้น', 50, 100, 500, ''],
    ];
    foreach ($sample as $i => $row) {
        foreach ($row as $j => $v) $sh->setCellValueByColumnAndRow($j + 1, $i + 2, $v);
    }
    foreach (range('A', 'J') as $c) $sh->getColumnDimension($c)->setAutoSize(true);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="consumables_template.xlsx"');
    (new XlsxWriter($sp))->save('php://output');
    exit;
}

// ── STEP 1: Upload + Preview ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'upload') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) throw new RuntimeException('CSRF token ไม่ถูกต้อง');
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('กรุณาเลือกไฟล์');
        }
        if ($_FILES['file']['size'] > 10 * 1024 * 1024) throw new RuntimeException('ไฟล์ใหญ่เกิน 10 MB');

        $tmp = $_FILES['file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls','csv'], true)) throw new RuntimeException('รองรับ .xlsx / .xls / .csv');

        $reader = IOFactory::createReaderForFile($tmp);
        $reader->setReadDataOnly(true);
        $rows = $reader->load($tmp)->getActiveSheet()->toArray(null, true, true, false);
        if (count($rows) < 2) throw new RuntimeException('ไฟล์ว่างเปล่าหรือมีแต่หัวตาราง');

        $headers = array_shift($rows);
        $map = []; $unmatched = [];
        foreach ($headers as $i => $h) {
            $f = $resolveField((string)($h ?? ''));
            if ($f) $map[$i] = $f;
            elseif (!empty($h)) $unmatched[] = (string)$h;
        }
        if (empty($map['name'] ?? null) && !in_array('name', $map, true)) {
            throw new RuntimeException('ไม่พบคอลัมน์ "ชื่อวัสดุ" — กรุณาตรวจสอบหัวตาราง');
        }

        // โหลด categories + locations เพื่อ resolve ชื่อ → id
        $catMap = [];
        foreach ($pdo->query("SELECT id, name FROM consumable_categories")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $catMap[$normalize($c['name'])] = (int)$c['id'];
        }
        $locMap = [];
        foreach ($pdo->query("SELECT id, name FROM asset_locations")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $locMap[$normalize($l['name'])] = (int)$l['id'];
        }

        $items = [];
        foreach ($rows as $rowIdx => $row) {
            $rec = ['name'=>'', 'brand'=>'', 'category'=>'', 'location'=>'', 'unit_pack'=>'', 'unit_piece'=>'',
                    'pack_size'=>1, 'min_stock'=>0, 'initial_qty'=>0, 'note'=>''];
            foreach ($map as $col => $field) {
                $rec[$field] = $row[$col] ?? '';
            }
            // skip blank rows
            if (trim((string)$rec['name']) === '') continue;

            $rec['name']        = trim((string)$rec['name']);
            $rec['brand']       = trim((string)$rec['brand']);
            $rec['unit_pack']   = trim((string)$rec['unit_pack']);
            $rec['unit_piece']  = trim((string)$rec['unit_piece']) ?: 'ชิ้น';
            $rec['pack_size']   = max(1, (int)$rec['pack_size']);
            $rec['min_stock']   = max(0, (int)$rec['min_stock']);
            $rec['initial_qty'] = max(0, (int)$rec['initial_qty']);
            $rec['note']        = trim((string)$rec['note']);

            $rec['category_id'] = !empty($rec['category']) ? ($catMap[$normalize($rec['category'])] ?? null) : null;
            $rec['location_id'] = !empty($rec['location']) ? ($locMap[$normalize($rec['location'])] ?? null) : null;

            $rec['_warnings'] = [];
            if (!empty($rec['category']) && !$rec['category_id']) $rec['_warnings'][] = 'หมวด "' . $rec['category'] . '" ไม่มีในระบบ — จะข้ามฟิลด์';
            if (!empty($rec['location']) && !$rec['location_id']) $rec['_warnings'][] = 'จุดจัดเก็บ "' . $rec['location'] . '" ไม่มีในระบบ';

            $items[] = $rec;
        }

        if (empty($items)) throw new RuntimeException('ไม่มีรายการที่ import ได้ (กรุณาตรวจคอลัมน์ "ชื่อวัสดุ")');

        // เก็บ preview ไว้ใน session ชั่วคราว
        $_SESSION['csm_import_preview'] = $items;
        $preview = $items;
        $message = 'พรีวิวสำเร็จ ' . count($items) . ' แถว — กดยืนยันเพื่อ import';
        if ($unmatched) $message .= ' (คอลัมน์ที่ไม่รู้จัก: ' . htmlspecialchars(implode(', ', $unmatched)) . ')';
        $step = 'preview';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── STEP 2: Confirm + Insert ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'confirm') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) throw new RuntimeException('CSRF token ไม่ถูกต้อง');
        $items = $_SESSION['csm_import_preview'] ?? [];
        if (empty($items)) throw new RuntimeException('Session หมดอายุ — กรุณาอัปโหลดไฟล์ใหม่');

        $inserted = 0; $skipped = 0;
        $pdo->beginTransaction();
        $insStmt = $pdo->prepare("INSERT INTO consumables
            (code, name, brand, category_id, location_id, unit_pack, unit_piece, pack_size, qty_on_hand, min_stock, note, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, 'active', ?)");

        foreach ($items as $it) {
            $code = csm_generate_code($pdo);
            $insStmt->execute([
                $code, $it['name'], $it['brand'] ?: null, $it['category_id'], $it['location_id'],
                $it['unit_pack'] ?: null, $it['unit_piece'], $it['pack_size'], $it['min_stock'],
                $it['note'] ?: null, $_SESSION['user_id'] ?? null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            if ((int)$it['initial_qty'] > 0) {
                csm_log_txn($pdo, $newId, 'receive', +(int)$it['initial_qty'], 'piece', (int)$it['initial_qty'],
                    null, null, 'ยอดยกมา (Excel import)', null, null, date('Y-m-d'));
            }
            $inserted++;
        }
        $pdo->commit();
        unset($_SESSION['csm_import_preview']);
        $message = "Import เรียบร้อย — เพิ่ม {$inserted} รายการ";
        $step = 'done';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Import ไม่สำเร็จ: ' . $e->getMessage();
    }
}

$page_title   = 'นำเข้าวัสดุจาก Excel';
$current_page = 'consumables';
include __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
    <a href="admin/manage_consumables.php" class="text-sm text-slate-500 hover:text-[#2e9e63]">
        <i class="fas fa-arrow-left"></i> กลับรายการ
    </a>
</div>

<?php if ($error): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
        <i class="fas fa-circle-check"></i> <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($step === 'done'): ?>
    <div class="asset-card p-6 max-w-2xl mx-auto text-center">
        <i class="fas fa-circle-check text-5xl text-emerald-500 mb-3"></i>
        <h2 class="font-extrabold text-lg text-slate-800 mb-2">นำเข้าเรียบร้อย</h2>
        <div class="flex items-center gap-2 justify-center mt-4">
            <a href="admin/manage_consumables.php" class="btn-asset btn-asset-primary"><i class="fas fa-list"></i> ดูรายการ</a>
            <a href="admin/import_consumables.php" class="btn-asset btn-asset-ghost"><i class="fas fa-redo"></i> Import เพิ่ม</a>
        </div>
    </div>
<?php elseif ($step === 'preview' && !empty($preview)): ?>
    <div class="asset-card p-4">
        <h2 class="asset-sec-title mb-3">พรีวิวก่อนยืนยัน — <?= count($preview) ?> แถว</h2>
        <div class="overflow-x-auto" style="max-height:60vh">
            <table class="asset-table text-xs">
                <thead><tr>
                    <th>#</th><th>ชื่อ</th><th>ยี่ห้อ</th><th>หมวด</th><th>จุดเก็บ</th>
                    <th>หน่วยใหญ่</th><th>หน่วยย่อย</th><th class="text-center">ต่อหน่วย</th>
                    <th class="text-center">จุดสั่งซื้อ</th><th class="text-center">ยอดเริ่ม</th>
                    <th>คำเตือน</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($preview as $i => $r): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td class="font-bold"><?= htmlspecialchars($r['name']) ?></td>
                            <td><?= htmlspecialchars($r['brand']) ?></td>
                            <td>
                                <?= htmlspecialchars($r['category']) ?>
                                <?php if (!empty($r['category']) && !$r['category_id']): ?>
                                    <span class="text-amber-600 text-[10px]">(ไม่พบ)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($r['location']) ?>
                                <?php if (!empty($r['location']) && !$r['location_id']): ?>
                                    <span class="text-amber-600 text-[10px]">(ไม่พบ)</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($r['unit_pack']) ?></td>
                            <td><?= htmlspecialchars($r['unit_piece']) ?></td>
                            <td class="text-center"><?= (int)$r['pack_size'] ?></td>
                            <td class="text-center"><?= (int)$r['min_stock'] ?></td>
                            <td class="text-center font-bold text-emerald-700"><?= (int)$r['initial_qty'] ?></td>
                            <td class="text-amber-600 text-[10px]"><?= htmlspecialchars(implode(' · ', $r['_warnings'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="mt-4 flex items-center gap-2 justify-end">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="step" value="confirm">
            <a href="admin/import_consumables.php" class="btn-asset btn-asset-ghost">ยกเลิก</a>
            <button type="submit" class="btn-asset btn-asset-primary"
                    onclick="return confirm('ยืนยันการ import <?= count($preview) ?> รายการ?')">
                <i class="fas fa-check"></i> ยืนยัน Import
            </button>
        </form>
    </div>
<?php else: ?>
    <form method="post" enctype="multipart/form-data" class="asset-card p-5 max-w-2xl mx-auto">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="step" value="upload">
        <h2 class="asset-sec-title mb-4">นำเข้าวัสดุจาก Excel</h2>

        <div class="mb-4 p-4 rounded-xl bg-[#f0faf4] border border-[#c7e8d5] text-sm text-slate-700">
            <p class="font-bold mb-2"><i class="fa-solid fa-circle-info text-[#2e9e63]"></i> วิธีใช้</p>
            <ol class="list-decimal list-inside space-y-1 text-xs">
                <li>ดาวน์โหลด template → กรอกข้อมูลใน Excel</li>
                <li>คอลัมน์บังคับ: <strong>ชื่อวัสดุ</strong> (อื่น ๆ optional)</li>
                <li>หมวดหมู่/จุดจัดเก็บต้องตรงกับที่มีในระบบแล้ว (ไม่งั้นจะเว้นว่าง)</li>
                <li>"ยอดเริ่มต้น" จะถูกบันทึกเป็น "รับเข้า" อัตโนมัติ</li>
            </ol>
            <a href="admin/import_consumables.php?template=1" class="inline-flex items-center gap-2 mt-3 text-[#2e9e63] font-bold hover:underline">
                <i class="fa-solid fa-file-excel"></i> ดาวน์โหลด template (.xlsx)
            </a>
        </div>

        <div>
            <label class="asset-label">เลือกไฟล์ <span class="text-rose-500">*</span></label>
            <input type="file" name="file" accept=".xlsx,.xls,.csv" required class="asset-input">
            <p class="text-[11px] text-slate-500 mt-1">รองรับ .xlsx / .xls / .csv (ไม่เกิน 10 MB)</p>
        </div>

        <div class="mt-6 flex items-center justify-end gap-2">
            <a href="admin/manage_consumables.php" class="btn-asset btn-asset-ghost">ยกเลิก</a>
            <button type="submit" class="btn-asset btn-asset-primary"><i class="fas fa-upload"></i> อัปโหลด & พรีวิว</button>
        </div>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
