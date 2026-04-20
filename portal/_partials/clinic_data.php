<?php
// portal/_partials/clinic_data.php — included by portal/index.php
// ข้อมูลคลีนิค: คณะ / หน่วยงาน (CRUD + นำเข้าจากไฟล์)

$pdo = db();

// Auto-create table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_faculties (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        code       VARCHAR(50)  NULL,
        name_th    VARCHAR(255) NOT NULL,
        name_en    VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_name_th (name_th)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException) {}

// Filter
$_cd_search = trim($_GET['cd_search'] ?? '');
$_cd_where  = 'WHERE 1=1';
$_cd_params = [];
if ($_cd_search !== '') {
    $_cd_where   .= ' AND (name_th LIKE ? OR name_en LIKE ? OR code LIKE ?)';
    $_cd_params[] = "%$_cd_search%";
    $_cd_params[] = "%$_cd_search%";
    $_cd_params[] = "%$_cd_search%";
}

$_cd_rows = [];
$_cd_total = 0;
$_cd_totalAll = 0;
try {
    $_cd_totalAll = (int)$pdo->query("SELECT COUNT(*) FROM sys_faculties")->fetchColumn();
    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_faculties $_cd_where");
    $sc->execute($_cd_params);
    $_cd_total = (int)$sc->fetchColumn();

    $sr = $pdo->prepare("SELECT id, code, name_th, name_en, created_at FROM sys_faculties $_cd_where ORDER BY name_th ASC");
    $sr->execute($_cd_params);
    $_cd_rows = $sr->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_cd_dbError = $e->getMessage();
}

$_cd_withCode = 0;
$_cd_withEn   = 0;
foreach ($_cd_rows as $r) {
    if (!empty($r['code']))    $_cd_withCode++;
    if (!empty($r['name_en'])) $_cd_withEn++;
}
?>

<div class="p-6">

    <!-- Section Header -->
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-hospital text-teal-500"></i> ข้อมูลคลีนิค
            </h2>
            <p class="text-xs text-gray-400 mt-1">จัดการรายชื่อคณะและหน่วยงานที่ใช้ในระบบ (เพิ่มเอง / แก้ไข / นำเข้าจาก Excel)</p>
        </div>
        <div class="flex gap-2">
            <button onclick="document.getElementById('cd-import-card').scrollIntoView({behavior:'smooth'})"
                class="px-3 py-2 bg-violet-50 text-violet-700 border border-violet-200 text-xs font-bold rounded-xl hover:bg-violet-100 flex items-center gap-1.5">
                <i class="fa-solid fa-file-import"></i> นำเข้าไฟล์
            </button>
            <?php if ($_cd_totalAll > 0): ?>
            <button onclick="cdClearAll()"
                class="px-3 py-2 bg-red-50 text-red-700 border border-red-200 text-xs font-bold rounded-xl hover:bg-red-100 flex items-center gap-1.5">
                <i class="fa-solid fa-trash-can"></i> ล้างทั้งหมด
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        <?php
        $cards = [
            ['label'=>'ทั้งหมด',           'val'=>$_cd_totalAll, 'icon'=>'fa-building-columns', 'bg'=>'#f0fdfa', 'ic'=>'#14b8a6'],
            ['label'=>'มีรหัสย่อ',         'val'=>$_cd_withCode, 'icon'=>'fa-hashtag',          'bg'=>'#eff6ff', 'ic'=>'#3b82f6'],
            ['label'=>'มีชื่อภาษาอังกฤษ',  'val'=>$_cd_withEn,   'icon'=>'fa-language',         'bg'=>'#faf5ff', 'ic'=>'#8b5cf6'],
        ];
        foreach ($cards as $c): ?>
        <div class="bg-white rounded-2xl p-5 border border-gray-100 shadow-sm flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0" style="background:<?= $c['bg'] ?>">
                <i class="fa-solid <?= $c['icon'] ?> text-lg" style="color:<?= $c['ic'] ?>"></i>
            </div>
            <div>
                <p class="text-2xl font-black text-gray-900"><?= number_format((int)$c['val']) ?></p>
                <p class="text-xs text-gray-400 font-semibold mt-0.5"><?= $c['label'] ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Add Row Form -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-9 h-9 bg-teal-50 rounded-xl flex items-center justify-center shrink-0">
                <i class="fa-solid fa-plus text-teal-500 text-sm"></i>
            </div>
            <div>
                <p class="text-sm font-bold text-gray-800">เพิ่มข้อมูลเอง</p>
                <p class="text-xs text-gray-400">กรอกข้อมูลคณะ/หน่วยงานทีละรายการ</p>
            </div>
        </div>
        <form id="cd-add-form" onsubmit="cdAdd(event)" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <div class="md:col-span-2">
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">รหัส</label>
                <input name="code" type="text" maxlength="50" placeholder="เช่น ICT"
                    class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-teal-100 focus:border-teal-400 transition-all bg-gray-50">
            </div>
            <div class="md:col-span-4">
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">ชื่อ (ภาษาไทย) <span class="text-red-400">*</span></label>
                <input name="name_th" type="text" required maxlength="255" placeholder="คณะ/หน่วยงาน ภาษาไทย"
                    class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-teal-100 focus:border-teal-400 transition-all bg-gray-50">
            </div>
            <div class="md:col-span-4">
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">ชื่อ (English)</label>
                <input name="name_en" type="text" maxlength="255" placeholder="Name in English"
                    class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-teal-100 focus:border-teal-400 transition-all bg-gray-50">
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="w-full px-5 py-2.5 bg-teal-600 text-white text-sm font-bold rounded-xl hover:bg-teal-700 flex items-center justify-center gap-2 shadow-sm">
                    <i class="fa-solid fa-plus"></i> เพิ่ม
                </button>
            </div>
        </form>
        <div id="cd-add-result" class="hidden mt-3 text-xs font-bold"></div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="section" value="clinic_data">
            <div class="flex-1 min-w-[200px]">
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">ค้นหา</label>
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs"></i>
                    <input type="text" name="cd_search" value="<?= htmlspecialchars($_cd_search) ?>"
                        placeholder="ชื่อ หรือ รหัส..."
                        class="w-full pl-8 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-teal-100 transition-all bg-gray-50">
                </div>
            </div>
            <button type="submit" class="px-5 py-2.5 bg-[#0052CC] text-white text-sm font-bold rounded-xl hover:bg-blue-700 transition-colors shadow-sm">
                <i class="fa-solid fa-filter mr-1"></i> ค้นหา
            </button>
            <?php if ($_cd_search !== ''): ?>
            <a href="?section=clinic_data" class="px-4 py-2.5 bg-gray-100 text-gray-600 text-sm font-medium rounded-xl hover:bg-gray-200 flex items-center gap-1">
                <i class="fa-solid fa-xmark text-xs"></i> ล้าง
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
            <p class="text-sm font-bold text-gray-700">
                พบ <span class="text-teal-600"><?= number_format($_cd_total) ?></span> รายการ
                <?php if ($_cd_search !== ''): ?><span class="text-gray-400 font-normal">(กรองแล้ว)</span><?php endif; ?>
            </p>
        </div>

        <?php if (isset($_cd_dbError)): ?>
        <div class="p-6 text-red-600 text-sm"><i class="fa-solid fa-triangle-exclamation mr-2"></i> <?= htmlspecialchars($_cd_dbError) ?></div>
        <?php elseif (empty($_cd_rows)): ?>
        <div class="py-16 text-center text-gray-400">
            <i class="fa-solid fa-building-columns text-4xl text-gray-300 mb-3 block"></i>
            <p class="font-semibold">
                <?= $_cd_search !== '' ? 'ไม่พบข้อมูลตามเงื่อนไขที่ค้นหา' : 'ยังไม่มีข้อมูลในระบบ' ?>
            </p>
            <p class="text-xs mt-1">
                <?= $_cd_search !== '' ? 'ลองเปลี่ยนคำค้นหา' : 'เริ่มเพิ่มข้อมูลด้วยฟอร์มด้านบน หรือนำเข้าจากไฟล์' ?>
            </p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-100">
                    <tr class="text-[10px] font-black text-gray-500 uppercase tracking-widest">
                        <th class="py-3 px-4 text-left w-12">#</th>
                        <th class="py-3 px-4 text-left w-28">รหัส</th>
                        <th class="py-3 px-4 text-left">ชื่อ (ภาษาไทย)</th>
                        <th class="py-3 px-4 text-left">ชื่อ (English)</th>
                        <th class="py-3 px-4 text-right w-32">การกระทำ</th>
                    </tr>
                </thead>
                <tbody id="cd-tbody">
                    <?php foreach ($_cd_rows as $i => $r): ?>
                    <tr class="border-b border-gray-50 hover:bg-gray-50/50 transition-colors" data-id="<?= (int)$r['id'] ?>">
                        <td class="py-3 px-4 text-xs text-gray-400 font-semibold"><?= $i + 1 ?></td>
                        <td class="py-3 px-4">
                            <?php if (!empty($r['code'])): ?>
                                <span class="inline-block text-[11px] font-black px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 font-mono"><?= htmlspecialchars($r['code']) ?></span>
                            <?php else: ?>
                                <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 font-semibold text-gray-800"><?= htmlspecialchars($r['name_th']) ?></td>
                        <td class="py-3 px-4 text-gray-500"><?= htmlspecialchars($r['name_en'] ?? '') ?: '<span class="text-gray-300">—</span>' ?></td>
                        <td class="py-3 px-4 text-right">
                            <button onclick='cdEditRow(<?= json_encode($r, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'
                                class="inline-flex items-center gap-1 px-3 py-1.5 bg-blue-50 text-blue-600 border border-blue-100 text-xs font-bold rounded-lg hover:bg-blue-100 transition-colors">
                                <i class="fa-solid fa-pen-to-square"></i> แก้ไข
                            </button>
                            <button onclick="cdDelete(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['name_th'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
                                class="inline-flex items-center gap-1 px-3 py-1.5 bg-red-50 text-red-600 border border-red-100 text-xs font-bold rounded-lg hover:bg-red-100 transition-colors ml-1">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Import from file -->
    <div id="cd-import-card" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-9 h-9 bg-violet-50 rounded-xl flex items-center justify-center shrink-0">
                <i class="fa-solid fa-file-import text-violet-500 text-sm"></i>
            </div>
            <div>
                <p class="text-sm font-bold text-gray-800">นำเข้าจากไฟล์ Excel / CSV</p>
                <p class="text-xs text-gray-400">รูปแบบ: <code class="bg-gray-100 px-1.5 py-0.5 rounded text-[11px]">รหัส | ชื่อ TH | ชื่อ EN</code> — Header row จะถูกข้ามอัตโนมัติ (ขนาดไม่เกิน 5 MB)</p>
            </div>
        </div>

        <div id="cd-drop-zone"
            class="border-2 border-dashed border-gray-200 rounded-xl p-8 text-center cursor-pointer transition-all hover:border-violet-300 hover:bg-violet-50/40"
            onclick="document.getElementById('cd-file-input').click()">
            <i class="fa-solid fa-cloud-arrow-up text-3xl text-gray-300 mb-2"></i>
            <p class="text-gray-500 font-semibold text-sm">คลิก หรือลากไฟล์มาวางที่นี่</p>
            <p class="text-gray-400 text-xs mt-1">.xlsx / .csv</p>
        </div>
        <input type="file" id="cd-file-input" accept=".xlsx,.csv" class="hidden">

        <div id="cd-file-info" class="hidden mt-3 flex items-center gap-3 p-3 bg-emerald-50 border border-emerald-100 rounded-xl">
            <i class="fa-solid fa-file-excel text-emerald-500 text-xl"></i>
            <div class="flex-1 min-w-0">
                <p id="cd-file-name" class="font-bold text-sm text-gray-900 truncate"></p>
                <p id="cd-file-size" class="text-xs text-gray-500"></p>
            </div>
            <button onclick="cdClearFile()" class="text-gray-400 hover:text-red-500 transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="mt-4">
            <button id="cd-import-btn" onclick="cdImport()" disabled
                class="w-full bg-violet-600 hover:bg-violet-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white font-bold py-3 px-5 rounded-xl transition-all text-sm flex items-center justify-center gap-2">
                <i class="fa-solid fa-file-import"></i> นำเข้าข้อมูลจากไฟล์
            </button>
        </div>
        <div id="cd-import-result" class="hidden mt-3 p-3 rounded-xl text-sm font-semibold"></div>
    </div>
</div>

<!-- Edit Modal -->
<div id="cd-edit-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <h3 class="text-base font-black text-gray-900 flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-blue-500"></i> แก้ไขข้อมูล
            </h3>
            <button onclick="cdCloseEdit()" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <form id="cd-edit-form" onsubmit="cdSaveEdit(event)" class="p-6 space-y-4">
            <input type="hidden" name="id" id="cd-edit-id">
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">รหัส</label>
                <input name="code" id="cd-edit-code" type="text" maxlength="50"
                    class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all bg-gray-50">
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">ชื่อ (ภาษาไทย) <span class="text-red-400">*</span></label>
                <input name="name_th" id="cd-edit-name-th" type="text" required maxlength="255"
                    class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all bg-gray-50">
            </div>
            <div>
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">ชื่อ (English)</label>
                <input name="name_en" id="cd-edit-name-en" type="text" maxlength="255"
                    class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all bg-gray-50">
            </div>
            <div id="cd-edit-result" class="hidden text-xs font-bold"></div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="cdCloseEdit()" class="flex-1 px-5 py-2.5 bg-gray-100 text-gray-700 text-sm font-bold rounded-xl hover:bg-gray-200">
                    ยกเลิก
                </button>
                <button type="submit" class="flex-1 px-5 py-2.5 bg-blue-600 text-white text-sm font-bold rounded-xl hover:bg-blue-700 shadow-sm">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const CSRF = '<?= htmlspecialchars(get_csrf_token()) ?>';
    const ENDPOINT = 'ajax_clinic_data.php';

    // ── Add ──────────────────────────────────────────────────────────────────
    window.cdAdd = async function (e) {
        e.preventDefault();
        const f  = e.target;
        const fd = new FormData(f);
        fd.append('action', 'add');
        fd.append('csrf_token', CSRF);
        const res = await fetch(ENDPOINT, { method: 'POST', body: fd });
        const data = await res.json();
        const box = document.getElementById('cd-add-result');
        box.classList.remove('hidden', 'text-emerald-600', 'text-red-600');
        if (data.status === 'ok') {
            box.classList.add('text-emerald-600');
            box.innerHTML = '<i class="fa-solid fa-circle-check mr-1"></i>' + data.message;
            f.reset();
            setTimeout(() => location.reload(), 700);
        } else {
            box.classList.add('text-red-600');
            box.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1"></i>' + data.message;
        }
    };

    // ── Delete ───────────────────────────────────────────────────────────────
    window.cdDelete = async function (id, name) {
        if (!confirm('ยืนยันลบ "' + name + '" ออกจากระบบ?')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fd.append('csrf_token', CSRF);
        const res = await fetch(ENDPOINT, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'ok') {
            const row = document.querySelector('tr[data-id="' + id + '"]');
            if (row) row.remove();
            setTimeout(() => location.reload(), 300);
        } else {
            alert(data.message);
        }
    };

    // ── Clear All ────────────────────────────────────────────────────────────
    window.cdClearAll = async function () {
        if (!confirm('ยืนยันลบข้อมูลคณะ/หน่วยงานทั้งหมด?')) return;
        const fd = new FormData();
        fd.append('action', 'clear_all');
        fd.append('csrf_token', CSRF);
        const res = await fetch(ENDPOINT, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'ok') location.reload();
        else alert(data.message);
    };

    // ── Edit ─────────────────────────────────────────────────────────────────
    const modal  = document.getElementById('cd-edit-modal');
    const eIdEl  = document.getElementById('cd-edit-id');
    const eCode  = document.getElementById('cd-edit-code');
    const eTh    = document.getElementById('cd-edit-name-th');
    const eEn    = document.getElementById('cd-edit-name-en');
    const eResu  = document.getElementById('cd-edit-result');

    window.cdEditRow = function (row) {
        eIdEl.value = row.id;
        eCode.value = row.code || '';
        eTh.value   = row.name_th || '';
        eEn.value   = row.name_en || '';
        eResu.classList.add('hidden');
        modal.classList.remove('hidden');
    };
    window.cdCloseEdit = function () {
        modal.classList.add('hidden');
    };
    modal.addEventListener('click', (e) => {
        if (e.target === modal) cdCloseEdit();
    });

    window.cdSaveEdit = async function (e) {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('action', 'update');
        fd.append('csrf_token', CSRF);
        const res = await fetch(ENDPOINT, { method: 'POST', body: fd });
        const data = await res.json();
        eResu.classList.remove('hidden', 'text-emerald-600', 'text-red-600');
        if (data.status === 'ok') {
            eResu.classList.add('text-emerald-600');
            eResu.innerHTML = '<i class="fa-solid fa-circle-check mr-1"></i>' + data.message;
            setTimeout(() => location.reload(), 500);
        } else {
            eResu.classList.add('text-red-600');
            eResu.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1"></i>' + data.message;
        }
    };

    // ── Import ───────────────────────────────────────────────────────────────
    const dropZone = document.getElementById('cd-drop-zone');
    const fileIn   = document.getElementById('cd-file-input');
    const fileInfo = document.getElementById('cd-file-info');
    const fileNm   = document.getElementById('cd-file-name');
    const fileSz   = document.getElementById('cd-file-size');
    const impBtn   = document.getElementById('cd-import-btn');
    const impRes   = document.getElementById('cd-import-result');

    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('border-violet-400', 'bg-violet-50'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('border-violet-400', 'bg-violet-50'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('border-violet-400', 'bg-violet-50');
        if (e.dataTransfer.files[0]) applyFile(e.dataTransfer.files[0]);
    });
    fileIn.addEventListener('change', () => { if (fileIn.files[0]) applyFile(fileIn.files[0]); });

    function applyFile(f) {
        const ext = f.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'csv'].includes(ext)) {
            cdShowImportResult('error', 'รองรับเฉพาะไฟล์ .xlsx และ .csv เท่านั้น');
            return;
        }
        fileNm.textContent = f.name;
        fileSz.textContent = (f.size / 1024).toFixed(1) + ' KB';
        fileInfo.classList.remove('hidden');
        impBtn.disabled = false;
        impRes.classList.add('hidden');
    }
    window.cdClearFile = function () {
        fileIn.value = '';
        fileInfo.classList.add('hidden');
        impBtn.disabled = true;
        impRes.classList.add('hidden');
    };
    window.cdImport = async function () {
        if (!fileIn.files[0]) return;
        impBtn.disabled = true;
        impBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังประมวลผล...';
        const fd = new FormData();
        fd.append('action', 'import');
        fd.append('import_file', fileIn.files[0]);
        fd.append('csrf_token', CSRF);
        try {
            const res  = await fetch(ENDPOINT, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'ok') {
                cdShowImportResult('ok', data.message);
                setTimeout(() => location.reload(), 1000);
            } else {
                cdShowImportResult('error', data.message);
                impBtn.disabled = false;
                impBtn.innerHTML = '<i class="fa-solid fa-file-import"></i> นำเข้าข้อมูลจากไฟล์';
            }
        } catch (e) {
            cdShowImportResult('error', 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้');
            impBtn.disabled = false;
            impBtn.innerHTML = '<i class="fa-solid fa-file-import"></i> นำเข้าข้อมูลจากไฟล์';
        }
    };
    function cdShowImportResult(type, msg) {
        impRes.classList.remove('hidden', 'bg-emerald-50', 'border-emerald-100', 'text-emerald-700', 'bg-red-50', 'border-red-100', 'text-red-700');
        if (type === 'ok') {
            impRes.classList.add('bg-emerald-50', 'border', 'border-emerald-100', 'text-emerald-700');
            impRes.innerHTML = '<i class="fa-solid fa-circle-check mr-2"></i>' + msg;
        } else {
            impRes.classList.add('bg-red-50', 'border', 'border-red-100', 'text-red-700');
            impRes.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-2"></i>' + msg;
        }
    }
})();
</script>
