<?php
// asset/admin/import_assets.php — Import ครุภัณฑ์จาก Excel/CSV (admin/editor)
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
asset_require_manage();

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PssDate;

$pdo = db();

/** Mapping ชื่อคอลัมน์ Excel → field DB (รองรับหลาย variant) */
$columnAliases = [
    'imported_at'    => ['ประทับเวลา', 'timestamp', 'วันที่บันทึก'],
    'name'           => ['ชื่ออุปกรณ์', 'name', 'ชื่อ'],
    'brand'          => ['ยี่ห้ออุปกรณ์', 'ยี่ห้อ', 'brand'],
    'quantity'       => ['จำนวน', 'qty', 'quantity'],
    'purchase_date'  => ['วันที่ซื้อ', 'purchase_date'],
    'warranty_text'  => ['การรับประกัน', 'warranty', 'รับประกัน'],
    'serial_number'  => ['หมายเลขเครื่อง s/n', 'หมายเลขเครื่อง sn', 'serial number', 's/n', 'serial', 'หมายเลขเครื่อง'],
    'rsu_asset_code' => ['หมายเลขเครื่อง s/n ฝ่ายจัดซื้อพัสดุ มรส', 's/n มรส', 'รหัสครุภัณฑ์ มรส', 'รหัส มรส'],
    'vendor'         => ['บริษัทที่ซื้อ', 'vendor', 'บริษัท', 'ร้านที่ซื้อ'],
    'location'       => ['จุดใช้งาน', 'location', 'สถานที่'],
    'note'           => ['หมายเหตุ', 'note', 'remark'],
    'image'          => ['รูปภาพสินค้า', 'image', 'รูป', 'รูปภาพ'],
];

/** Normalize header → lowercase + trim + collapse spaces */
$normalize = function (string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower($s ?? '', 'UTF-8');
};

/** หา field key ของหัวคอลัมน์ */
$resolveField = function (string $header) use ($columnAliases, $normalize): ?string {
    $h = $normalize($header);
    foreach ($columnAliases as $field => $aliases) {
        foreach ($aliases as $alias) {
            if ($h === $normalize($alias)) return $field;
        }
    }
    return null;
};

/** แปลงวันที่จาก Excel/string → 'Y-m-d' หรือ null */
$parseDate = function ($v): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        try {
            $dt = PssDate::excelToDateTimeObject((float)$v);
            return $dt->format('Y-m-d');
        } catch (Throwable $e) { /* fall through */ }
    }
    $s = trim((string)$v);
    if ($s === '') return null;
    // รองรับ d/m/Y, d-m-Y, Y-m-d, รวมถึงปี พ.ศ.
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $s, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if ($y < 100) $y += 2000;
        if ($y > 2400) $y -= 543; // พ.ศ. → ค.ศ.
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d', $ts) : null;
};

$parseDatetime = function ($v) use ($parseDate): ?string {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) {
        try {
            $dt = PssDate::excelToDateTimeObject((float)$v);
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) { /* fall through */ }
    }
    $ts = strtotime((string)$v);
    if ($ts) return date('Y-m-d H:i:s', $ts);
    $d = $parseDate($v);
    return $d ? $d . ' 00:00:00' : null;
};

$step    = $_POST['step'] ?? 'upload';
$message = '';
$error   = '';
$preview = null;

// ── STEP 1: Upload + Preview ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'upload') {
    try {
        validate_csrf_or_die();
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('กรุณาเลือกไฟล์ Excel/CSV');
        }
        if ($_FILES['file']['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('ไฟล์ใหญ่เกิน 10 MB');
        }

        $tmp  = $_FILES['file']['tmp_name'];
        $orig = $_FILES['file']['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException('รองรับเฉพาะ .xlsx / .xls / .csv');
        }

        $reader = IOFactory::createReaderForFile($tmp);
        $reader->setReadDataOnly(true);
        $sheet  = $reader->load($tmp)->getActiveSheet();
        $rows   = $sheet->toArray(null, true, true, false);

        if (count($rows) < 2) throw new RuntimeException('ไฟล์ว่างเปล่าหรือมีแต่หัวตาราง');

        $headers = array_shift($rows);
        $map     = [];
        $unmatched = [];
        foreach ($headers as $idx => $h) {
            $field = $resolveField((string)($h ?? ''));
            if ($field) $map[$idx] = $field;
            elseif (!empty(trim((string)($h ?? '')))) $unmatched[] = (string)$h;
        }

        if (!in_array('name', $map, true)) {
            throw new RuntimeException('ไม่พบคอลัมน์ "ชื่ออุปกรณ์" — กรุณาตรวจสอบหัวตาราง');
        }

        // Build records
        $records = [];
        foreach ($rows as $r) {
            $rec = [];
            foreach ($map as $idx => $field) {
                $rec[$field] = $r[$idx] ?? null;
            }
            // skip ถ้าไม่มีชื่ออุปกรณ์
            if (empty(trim((string)($rec['name'] ?? '')))) continue;

            // normalize values
            $rec['name']           = trim((string)$rec['name']);
            $rec['brand']          = trim((string)($rec['brand'] ?? '')) ?: null;
            $rec['quantity']       = max(1, (int)($rec['quantity'] ?? 1));
            $rec['purchase_date']  = $parseDate($rec['purchase_date'] ?? null);
            $rec['warranty_text']  = trim((string)($rec['warranty_text'] ?? '')) ?: null;
            $rec['serial_number']  = trim((string)($rec['serial_number'] ?? '')) ?: null;
            $rec['rsu_asset_code'] = trim((string)($rec['rsu_asset_code'] ?? '')) ?: null;
            $rec['vendor']         = trim((string)($rec['vendor'] ?? '')) ?: null;
            $rec['location']       = trim((string)($rec['location'] ?? '')) ?: null;
            $rec['note']           = trim((string)($rec['note'] ?? '')) ?: null;
            $rec['image']          = trim((string)($rec['image'] ?? '')) ?: null;
            $rec['imported_at']    = $parseDatetime($rec['imported_at'] ?? null);

            $records[] = $rec;
        }

        if (empty($records)) throw new RuntimeException('ไม่พบข้อมูลในไฟล์ (ทุกแถวขาดชื่ออุปกรณ์)');

        // เช็ค duplicate ใน DB ตาม serial_number / rsu_asset_code (เฉพาะที่มีค่า)
        $duplicates = [];
        $checkStmt = $pdo->prepare(
            "SELECT id, asset_code, name FROM assets
             WHERE (serial_number IS NOT NULL AND serial_number = ?)
                OR (rsu_asset_code IS NOT NULL AND rsu_asset_code = ?)
             LIMIT 1"
        );
        foreach ($records as $i => $rec) {
            if (!$rec['serial_number'] && !$rec['rsu_asset_code']) continue;
            $checkStmt->execute([$rec['serial_number'], $rec['rsu_asset_code']]);
            if ($row = $checkStmt->fetch(PDO::FETCH_ASSOC)) {
                $duplicates[$i] = $row;
            }
        }

        // เก็บใน session เพื่อ confirm
        $_SESSION['asset_import_buffer'] = [
            'records'    => $records,
            'duplicates' => array_keys($duplicates),
            'unmatched'  => $unmatched,
            'filename'   => $orig,
            'created_at' => time(),
        ];

        $preview = [
            'records'    => array_slice($records, 0, 50),
            'total'      => count($records),
            'duplicates' => $duplicates,
            'unmatched'  => $unmatched,
            'filename'   => $orig,
            'matched'    => array_values($map),
        ];
        $step = 'preview';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ── STEP 2: Confirm + Insert ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'confirm') {
    try {
        validate_csrf_or_die();
        $buf = $_SESSION['asset_import_buffer'] ?? null;
        if (!$buf || empty($buf['records'])) {
            throw new RuntimeException('Session หมดอายุ กรุณาอัปโหลดใหม่');
        }
        $skipDup = isset($_POST['skip_duplicates']);

        // โหลด lookup tables
        $locByName = $pdo->query("SELECT id, name FROM asset_locations")->fetchAll(PDO::FETCH_KEY_PAIR);
        $locByName = array_flip($locByName); // name => id
        $insLoc = $pdo->prepare("INSERT INTO asset_locations (name) VALUES (?)");

        $catId = (int)($_POST['default_category_id'] ?? 0) ?: null;

        $pdo->beginTransaction();
        $stmtInsert = $pdo->prepare(
            "INSERT INTO assets
                (asset_code, rsu_asset_code, serial_number, name, brand, quantity,
                 purchase_date, warranty_text, vendor, category_id, location_id,
                 status, note, imported_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_use', ?, ?, ?)"
        );

        $countInserted = 0;
        $countSkipped  = 0;
        $errors        = [];
        $userId        = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $duplicateIdx  = array_flip($buf['duplicates'] ?? []);

        foreach ($buf['records'] as $i => $rec) {
            try {
                if ($skipDup && isset($duplicateIdx[$i])) {
                    $countSkipped++;
                    continue;
                }

                // resolve location
                $locId = null;
                if (!empty($rec['location'])) {
                    if (!isset($locByName[$rec['location']])) {
                        $insLoc->execute([$rec['location']]);
                        $locByName[$rec['location']] = (int)$pdo->lastInsertId();
                    }
                    $locId = (int)$locByName[$rec['location']];
                }

                $code = asset_generate_code($pdo);
                $stmtInsert->execute([
                    $code,
                    $rec['rsu_asset_code'],
                    $rec['serial_number'],
                    $rec['name'],
                    $rec['brand'],
                    $rec['quantity'],
                    $rec['purchase_date'],
                    $rec['warranty_text'],
                    $rec['vendor'],
                    $catId,
                    $locId,
                    $rec['note'],
                    $rec['imported_at'] ?? date('Y-m-d H:i:s'),
                    $userId,
                ]);
                $newId = (int)$pdo->lastInsertId();
                asset_log_movement($pdo, $newId, 'create', null, $locId, null, 'in_use', 'Import จากไฟล์ ' . ($buf['filename'] ?? ''));
                $countInserted++;
            } catch (Throwable $e) {
                $errors[] = "แถว " . ($i + 2) . ": " . $e->getMessage();
            }
        }

        $pdo->commit();
        unset($_SESSION['asset_import_buffer']);

        $message = "นำเข้าสำเร็จ {$countInserted} รายการ" . ($countSkipped ? " · ข้าม duplicate {$countSkipped}" : "") . (count($errors) ? " · ผิดพลาด " . count($errors) : "");
        $step    = 'done';
        $doneInfo = compact('countInserted', 'countSkipped', 'errors');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
        $step  = 'preview';
        // restore preview from session
        if (!empty($_SESSION['asset_import_buffer'])) {
            $buf = $_SESSION['asset_import_buffer'];
            $preview = [
                'records'    => array_slice($buf['records'], 0, 50),
                'total'      => count($buf['records']),
                'duplicates' => [],
                'unmatched'  => $buf['unmatched'] ?? [],
                'filename'   => $buf['filename'] ?? '',
                'matched'    => [],
            ];
        }
    }
}

// ── STEP 3: Cancel buffer ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'cancel') {
    unset($_SESSION['asset_import_buffer']);
    header('Location: import_assets.php');
    exit;
}

$categories = $pdo->query("SELECT id, name FROM asset_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title   = 'นำเข้าครุภัณฑ์จาก Excel';
$current_page = 'assets';
include __DIR__ . '/../includes/header.php';
?>

<div class="flex items-center justify-between mb-4">
    <div>
        <h2 class="asset-sec-title">นำเข้าครุภัณฑ์จาก Excel</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">รองรับ .xlsx / .xls / .csv ขนาดไม่เกิน 10 MB</p>
    </div>
    <a href="manage_assets.php" class="btn-asset btn-asset-ghost"><i class="fas fa-arrow-left"></i> กลับ</a>
</div>

<?php if ($message): ?>
    <div class="mb-3 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
        <i class="fas fa-circle-check"></i> <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="mb-3 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
        <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="asset-card p-5 lg:col-span-2">
            <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-cloud-arrow-up text-[#2e9e63]"></i> อัปโหลดไฟล์</h3>
            <form method="post" enctype="multipart/form-data" class="space-y-4">
                <?php csrf_field(); ?>
                <input type="hidden" name="step" value="upload">
                <div>
                    <label class="asset-label">ไฟล์ Excel หรือ CSV <span class="text-rose-500">*</span></label>
                    <input type="file" name="file" required accept=".xlsx,.xls,.csv" class="asset-input">
                </div>
                <button type="submit" class="btn-asset btn-asset-primary">
                    <i class="fas fa-upload"></i> อัปโหลดและตรวจสอบ
                </button>
            </form>
        </div>
        <div class="asset-card p-5">
            <h3 class="font-bold text-slate-700 mb-3"><i class="fas fa-circle-info text-[#2e9e63]"></i> รูปแบบไฟล์ที่รองรับ</h3>
            <p class="text-sm text-slate-600 mb-2">หัวคอลัมน์ที่ระบบรู้จัก:</p>
            <ul class="text-xs text-slate-700 space-y-1.5">
                <li><strong>ชื่ออุปกรณ์</strong> <span class="text-rose-500">*</span> (จำเป็น)</li>
                <li>ยี่ห้ออุปกรณ์</li>
                <li>จำนวน</li>
                <li>วันที่ซื้อ</li>
                <li>การรับประกัน</li>
                <li>หมายเลขเครื่อง S/N</li>
                <li>หมายเลขเครื่อง S/N ฝ่ายจัดซื้อพัสดุ มรส</li>
                <li>บริษัทที่ซื้อ</li>
                <li>จุดใช้งาน <span class="text-slate-500 text-[10px]">(สร้างอัตโนมัติถ้ายังไม่มี)</span></li>
                <li>หมายเหตุ</li>
                <li>ประทับเวลา</li>
            </ul>
        </div>
    </div>

<?php elseif ($step === 'preview' && $preview): ?>
    <div class="asset-card p-5 mb-4">
        <div class="flex items-center justify-between flex-wrap gap-2 mb-4">
            <div>
                <h3 class="font-bold text-slate-700">
                    <i class="fas fa-eye text-[#2e9e63]"></i> ตรวจสอบก่อนยืนยัน
                </h3>
                <p class="text-xs text-slate-500 mt-1">ไฟล์: <strong><?= htmlspecialchars($preview['filename']) ?></strong></p>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge-status bg-[#e8f8f0] text-[#258052] border border-[#c7e8d5]">รวม <?= number_format($preview['total']) ?> รายการ</span>
                <?php if (!empty($preview['duplicates'])): ?>
                    <span class="badge-status bg-amber-50 text-amber-700 border border-amber-200">Duplicate <?= count($preview['duplicates']) ?> รายการ</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($preview['unmatched'])): ?>
            <div class="mb-3 p-3 rounded-xl bg-amber-50 border border-amber-200 text-amber-700 text-xs">
                <strong>คอลัมน์ที่ระบบไม่รู้จัก (จะถูกข้าม):</strong>
                <?= htmlspecialchars(implode(', ', $preview['unmatched'])) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <?php csrf_field(); ?>
            <input type="hidden" name="step" value="confirm">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="asset-label">หมวดหมู่ default ของรายการที่นำเข้า</label>
                    <select name="default_category_id" class="asset-input">
                        <option value="0">— ไม่ระบุ —</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 font-semibold pb-2">
                        <input type="checkbox" name="skip_duplicates" value="1" checked
                               class="w-4 h-4 rounded border-slate-300 text-[#2e9e63] focus:ring-[#2e9e63]">
                        ข้ามรายการที่ S/N ซ้ำกับใน DB
                    </label>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="asset-table">
                    <thead><tr>
                        <th>#</th><th>ชื่อ</th><th>ยี่ห้อ</th><th>จำนวน</th>
                        <th>S/N</th><th>S/N มรส</th><th>จุดใช้งาน</th><th>วันที่ซื้อ</th><th>สถานะ</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $dupSet = array_flip($_SESSION['asset_import_buffer']['duplicates'] ?? []);
                    foreach ($preview['records'] as $i => $r):
                        $isDup = isset($dupSet[$i]); ?>
                        <tr class="<?= $isDup ? 'bg-amber-50/40' : '' ?>">
                            <td data-label="#"><?= $i + 1 ?></td>
                            <td data-label="ชื่อ"><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                            <td data-label="ยี่ห้อ"><?= htmlspecialchars($r['brand'] ?? '-') ?></td>
                            <td data-label="จำนวน" class="text-center"><?= (int)$r['quantity'] ?></td>
                            <td data-label="S/N" class="text-xs text-slate-500"><?= htmlspecialchars($r['serial_number'] ?? '-') ?></td>
                            <td data-label="S/N มรส" class="text-xs text-slate-500"><?= htmlspecialchars($r['rsu_asset_code'] ?? '-') ?></td>
                            <td data-label="จุดใช้งาน"><?= htmlspecialchars($r['location'] ?? '-') ?></td>
                            <td data-label="วันที่ซื้อ" class="text-xs"><?= htmlspecialchars($r['purchase_date'] ?? '-') ?></td>
                            <td data-label="สถานะ">
                                <?php if ($isDup): ?>
                                    <span class="badge-status bg-amber-50 text-amber-700 border border-amber-200">DUPLICATE</span>
                                <?php else: ?>
                                    <span class="badge-status bg-[#f0faf4] text-[#2e7d52] border border-[#c7e8d5]">OK</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($preview['total'] > 50): ?>
                    <p class="text-center text-xs text-slate-500 mt-2">… แสดง 50 รายการแรก จากทั้งหมด <?= number_format($preview['total']) ?></p>
                <?php endif; ?>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t border-[#f0faf4]">
                <button type="submit" form="cancel-form" class="btn-asset btn-asset-ghost">
                    <i class="fas fa-times"></i> ยกเลิก
                </button>
                <button type="submit" class="btn-asset btn-asset-primary">
                    <i class="fas fa-circle-check"></i> ยืนยันนำเข้า
                </button>
            </div>
        </form>

        <form id="cancel-form" method="post" class="hidden">
            <?php csrf_field(); ?>
            <input type="hidden" name="step" value="cancel">
        </form>
    </div>

<?php elseif ($step === 'done' && isset($doneInfo)): ?>
    <div class="asset-card p-6 text-center">
        <div class="w-16 h-16 mx-auto rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mb-3">
            <i class="fas fa-circle-check text-3xl"></i>
        </div>
        <h3 class="text-lg font-extrabold text-slate-800">นำเข้าสำเร็จ</h3>
        <p class="text-sm text-slate-600 mt-1">
            เพิ่ม <strong class="text-[#2e9e63]"><?= number_format($doneInfo['countInserted']) ?></strong> รายการ
            <?php if ($doneInfo['countSkipped']): ?> · ข้าม <?= $doneInfo['countSkipped'] ?> รายการ<?php endif; ?>
        </p>
        <?php if (!empty($doneInfo['errors'])): ?>
            <div class="mt-4 text-left max-h-40 overflow-auto p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-700">
                <strong>มีข้อผิดพลาด <?= count($doneInfo['errors']) ?> รายการ:</strong>
                <ul class="mt-1 space-y-0.5 list-disc pl-4">
                    <?php foreach (array_slice($doneInfo['errors'], 0, 30) as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <div class="mt-5 flex justify-center gap-2">
            <a href="manage_assets.php" class="btn-asset btn-asset-primary">
                <i class="fas fa-list"></i> ดูทะเบียนครุภัณฑ์
            </a>
            <a href="import_assets.php" class="btn-asset btn-asset-ghost">
                <i class="fas fa-rotate-left"></i> นำเข้าอีกไฟล์
            </a>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
