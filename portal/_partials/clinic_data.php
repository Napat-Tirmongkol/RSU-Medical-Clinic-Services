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
        type       ENUM('faculty','department') NOT NULL DEFAULT 'faculty',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_name_th (name_th)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { $pdo->exec("ALTER TABLE sys_faculties ADD COLUMN type ENUM('faculty','department') NOT NULL DEFAULT 'faculty'"); } catch (PDOException) {}
} catch (PDOException) {}

// Filter + Pagination
$_cd_search = trim($_GET['cd_search'] ?? '');
$_cd_page   = max(1, (int)($_GET['cd_page'] ?? 1));
$_cd_limit  = 20;
$_cd_offset = ($_cd_page - 1) * $_cd_limit;
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
$_cd_faculties = 0;
$_cd_departments = 0;
$_cd_totalPages = 0;
try {
    $_cd_totalAll    = (int)$pdo->query("SELECT COUNT(*) FROM sys_faculties")->fetchColumn();
    $_cd_faculties   = (int)$pdo->query("SELECT COUNT(*) FROM sys_faculties WHERE type='faculty'")->fetchColumn();
    $_cd_departments = (int)$pdo->query("SELECT COUNT(*) FROM sys_faculties WHERE type='department'")->fetchColumn();

    $sc = $pdo->prepare("SELECT COUNT(*) FROM sys_faculties $_cd_where");
    $sc->execute($_cd_params);
    $_cd_total      = (int)$sc->fetchColumn();
    $_cd_totalPages = (int)ceil($_cd_total / $_cd_limit);

    $sr = $pdo->prepare("SELECT id, code, name_th, name_en, type FROM sys_faculties $_cd_where ORDER BY type ASC, name_th ASC LIMIT $_cd_limit OFFSET $_cd_offset");
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

// Build base querystring for pagination links
$_cd_qs = http_build_query(array_filter(['section' => 'clinic_data', 'cd_search' => $_cd_search]));
?>
<div class="p-6">
    <!-- Header -->
    <div class="mb-8 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-black text-slate-800 flex items-center gap-3">
                <div class="w-10 h-10 bg-teal-50 text-teal-600 rounded-xl flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-hospital"></i>
                </div>
                ข้อมูลคลีนิค
            </h2>
            <p class="text-slate-500 text-sm font-medium mt-1">ตั้งค่าโครงสร้างพื้นฐานและข้อมูลบุคลากรของคลีนิค</p>
        </div>
    </div>

    <!-- Main Tab Navigation -->
    <div class="flex flex-wrap items-center gap-1 bg-slate-100 p-1 rounded-2xl mb-8 w-fit border border-slate-200">
        <button onclick="switchClinicTab('faculties')" id="tab-btn-faculties" 
                class="clinic-tab-btn active px-6 py-2.5 rounded-xl text-sm font-black bg-white shadow-sm text-slate-800 transition-all flex items-center gap-2">
            <i class="fa-solid fa-building-columns"></i> คณะ/หน่วยงาน
        </button>
        <button onclick="switchClinicTab('rooms')" id="tab-btn-rooms" 
                class="clinic-tab-btn px-6 py-2.5 rounded-xl text-sm font-black text-slate-500 hover:text-slate-700 hover:bg-white/50 transition-all flex items-center gap-2">
            <i class="fa-solid fa-door-open"></i> ห้องตรวจ (อนาคต)
        </button>
        <button onclick="switchClinicTab('staff')" id="tab-btn-staff" 
                class="clinic-tab-btn px-6 py-2.5 rounded-xl text-sm font-black text-slate-500 hover:text-slate-700 hover:bg-white/50 transition-all flex items-center gap-2">
            <i class="fa-solid fa-user-doctor"></i> แพทย์/บุคลากร
        </button>
        <button class="px-4 py-2.5 rounded-xl text-sm font-black text-slate-400 cursor-not-allowed flex items-center gap-2 italic">
            <i class="fa-solid fa-plus-circle text-xs"></i> เพิ่ม tab ใหม่
        </button>
    </div>

    <!-- Tab Contents Container -->
    <div class="clinic-tab-content active" id="tab-content-faculties">
        
        <!-- Summary Cards (Inner Tab) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <?php
            $cards = [
                ['label'=>'ทั้งหมด',           'val'=>$_cd_totalAll, 'icon'=>'fa-building-columns', 'bg'=>'#f0fdf4', 'ic'=>'#10b981'],
                ['label'=>'คณะ',              'val'=>$_cd_faculties, 'icon'=>'fa-building',        'bg'=>'#eff6ff', 'ic'=>'#3b82f6'],
                ['label'=>'หน่วยงาน',         'val'=>$_cd_departments, 'icon'=>'fa-sitemap',       'bg'=>'#fef3c7', 'ic'=>'#f59e0b'],
                ['label'=>'ข้อมูลสมบูรณ์',        'val'=>$_cd_withEn,   'icon'=>'fa-check-double',     'bg'=>'#faf5ff', 'ic'=>'#a855f7'],
            ];
            foreach ($cards as $c): ?>
            <div class="bg-white rounded-3xl p-6 border border-slate-100 shadow-sm flex items-center gap-5 transition-all hover:shadow-md hover:-translate-y-1">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 shadow-inner" style="background:<?= $c['bg'] ?>">
                    <i class="fa-solid <?= $c['icon'] ?> text-xl" style="color:<?= $c['ic'] ?>"></i>
                </div>
                <div>
                    <p class="text-2xl font-black text-slate-800"><?= number_format((int)$c['val']) ?></p>
                    <p class="text-[11px] text-slate-400 font-bold uppercase tracking-wider mt-0.5"><?= $c['label'] ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 2 Column Layout for Form & Import -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <!-- Left: Add Form -->
            <div class="lg:col-span-2 bg-white rounded-3xl border border-slate-100 shadow-sm p-6">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 bg-teal-50 rounded-xl flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-plus text-teal-600"></i>
                    </div>
                    <div>
                        <p class="text-base font-black text-slate-800">เพิ่มข้อมูลคณะ/หน่วยงาน</p>
                        <p class="text-xs text-slate-400 font-medium">ระบุชื่อคณะหรือหน่วยงานเพื่อใช้ในระบบคัดกรอง</p>
                    </div>
                </div>
                
                <form id="cd-add-form" onsubmit="cdAdd(event)" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-4">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">ประเภทข้อมูล <span class="text-red-500">*</span></label>
                            <select name="type" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:ring-2 focus:ring-teal-100 focus:border-teal-400 transition-all">
                                <option value="faculty">คณะ (Faculty)</option>
                                <option value="department">หน่วยงาน (Department)</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">รหัสย่อ (ถ้ามี)</label>
                            <input name="code" type="text" maxlength="50" placeholder="เช่น ICT, MED"
                                class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:ring-2 focus:ring-teal-100 focus:border-teal-400 transition-all">
                        </div>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">ชื่อภาษาไทย <span class="text-red-500">*</span></label>
                            <input name="name_th" type="text" required maxlength="255" placeholder="ระบุชื่อเต็มภาษาไทย"
                                class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:ring-2 focus:ring-teal-100 focus:border-teal-400 transition-all">
                        </div>
                        <div>
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">ชื่อภาษาอังกฤษ</label>
                            <input name="name_en" type="text" maxlength="255" placeholder="English Name"
                                class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:ring-2 focus:ring-teal-100 focus:border-teal-400 transition-all">
                        </div>
                    </div>
                    <div class="md:col-span-2 pt-2 flex justify-end">
                        <button type="submit" class="px-8 py-3 bg-teal-600 text-white text-sm font-black rounded-xl hover:bg-teal-700 flex items-center justify-center gap-2 shadow-lg shadow-teal-100 transition-all">
                            <i class="fa-solid fa-save"></i> บันทึกข้อมูล
                        </button>
                    </div>
                </form>
                <div id="cd-add-result" class="hidden mt-4 p-4 rounded-xl text-xs font-bold bg-slate-50 border border-slate-100"></div>
            </div>

            <!-- Right: Import -->
            <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-6 flex flex-col">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 bg-violet-50 rounded-xl flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-file-excel text-violet-600"></i>
                    </div>
                    <div>
                        <p class="text-base font-black text-slate-800">นำเข้าไฟล์</p>
                        <p class="text-xs text-slate-400 font-medium">รองรับไฟล์ .xlsx และ .csv</p>
                    </div>
                </div>

                <!-- Simple Type Radio -->
                <div class="mb-4 flex gap-2">
                    <label class="flex-1 cursor-pointer">
                        <input type="radio" name="cd-import-type" value="faculty" checked class="hidden peer">
                        <div class="p-2.5 text-center rounded-xl border border-slate-200 text-xs font-black text-slate-400 peer-checked:bg-blue-50 peer-checked:border-blue-200 peer-checked:text-blue-600 transition-all italic">คณะ</div>
                    </label>
                    <label class="flex-1 cursor-pointer">
                        <input type="radio" name="cd-import-type" value="department" class="hidden peer">
                        <div class="p-2.5 text-center rounded-xl border border-slate-200 text-xs font-black text-slate-400 peer-checked:bg-amber-50 peer-checked:border-amber-200 peer-checked:text-amber-600 transition-all italic">หน่วยงาน</div>
                    </label>
                </div>

                <div id="cd-drop-zone"
                    class="flex-1 border-2 border-dashed border-slate-100 rounded-2xl p-6 text-center cursor-pointer transition-all hover:border-violet-300 hover:bg-violet-50/50 flex flex-col items-center justify-center min-h-[150px]"
                    onclick="document.getElementById('cd-file-input').click()">
                    <i class="fa-solid fa-cloud-arrow-up text-3xl text-slate-200 mb-2"></i>
                    <p class="text-slate-500 font-bold text-xs uppercase tracking-wider">ลากไฟล์วางที่นี่</p>
                    <p class="text-[10px] text-slate-400 mt-1 font-medium">Excel / CSV เท่านั้น</p>
                </div>
                <input type="file" id="cd-file-input" accept=".xlsx,.csv" class="hidden">

                <div id="cd-file-info" class="hidden mt-4 p-3 bg-emerald-50 border border-emerald-100 rounded-xl flex items-center gap-3">
                    <i class="fa-solid fa-file-circle-check text-emerald-500"></i>
                    <div class="flex-1 min-w-0">
                        <p id="cd-file-name" class="font-bold text-xs text-slate-800 truncate"></p>
                    </div>
                    <button onclick="cdClearFile()" class="text-slate-300 hover:text-red-500 transition-colors"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <div id="cd-import-btn-wrap" class="mt-4 hidden">
                    <button id="cd-import-btn" onclick="cdImport()" class="w-full py-3 bg-slate-800 text-white rounded-xl text-xs font-black hover:bg-black transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-upload"></i> เริ่มนำเข้าข้อมูล
                    </button>
                </div>
                <div id="cd-import-result" class="hidden mt-3 p-3 rounded-xl text-xs font-bold text-center italic"></div>
            </div>
        </div>

        <!-- Filter & Data Table -->
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden mb-12">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider">รายการข้อมูล</h3>
                    <span class="px-2.5 py-1 bg-white border border-slate-200 text-[10px] font-black text-teal-600 rounded-lg">
                        <?= number_format($_cd_total) ?> TOTAL
                    </span>
                </div>
                
                <form method="GET" class="flex items-center gap-2 max-w-md w-full">
                    <input type="hidden" name="section" value="clinic_data">
                    <div class="relative flex-1">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-300 text-xs"></i>
                        <input type="text" name="cd_search" value="<?= htmlspecialchars($_cd_search) ?>" placeholder="ค้นหาชื่อ หรือ รหัส..."
                            class="w-full pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-bold text-slate-800 outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-slate-800 text-white rounded-xl text-xs font-black hover:bg-black transition-all">ค้นหา</button>
                    <?php if ($_cd_search !== ''): ?>
                    <a href="?section=clinic_data" class="w-9 h-9 flex items-center justify-center bg-slate-100 text-slate-500 rounded-xl hover:bg-slate-200 transition-all">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (isset($_cd_dbError)): ?>
                <div class="p-12 text-center text-red-500 font-bold italic"><?= htmlspecialchars($_cd_dbError) ?></div>
            <?php elseif (empty($_cd_rows)): ?>
                <div class="py-20 text-center">
                    <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <p class="text-slate-400 font-black text-sm uppercase tracking-widest">ไม่พบข้อมูลในขณะนี้</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-slate-50/50 border-b border-slate-100">
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest w-12">#</th>
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest w-24">ประเภท</th>
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest w-24">รหัส</th>
                                <th class="py-4 px-6 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">ชื่อคณะ / หน่วยงาน</th>
                                <th class="py-4 px-6 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest w-32">เครื่องมือ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_cd_rows as $i => $r): 
                                $isF = $r['type'] === 'faculty';
                            ?>
                            <tr class="border-b border-slate-50 hover:bg-slate-50/50 transition-all group" data-id="<?= (int)$r['id'] ?>">
                                <td class="py-4 px-6 text-[11px] font-bold text-slate-400"><?= $_cd_offset + $i + 1 ?></td>
                                <td class="py-4 px-6">
                                    <span class="inline-block text-[10px] font-black px-2.5 py-1 rounded-lg <?= $isF ? 'bg-blue-50 text-blue-600' : 'bg-amber-50 text-amber-600' ?>">
                                        <?= $isF ? 'FACULTY' : 'DEPT' ?>
                                    </span>
                                </td>
                                <td class="py-4 px-6">
                                    <?php if (!empty($r['code'])): ?>
                                        <span class="inline-block text-[10px] font-mono font-black px-2 py-0.5 rounded-md bg-slate-100 text-slate-600"><?= htmlspecialchars($r['code']) ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-200">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-6">
                                    <div class="font-black text-slate-800 text-sm"><?= htmlspecialchars($r['name_th']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-bold uppercase tracking-tight"><?= htmlspecialchars($r['name_en'] ?? '') ?: '— NO ENGLISH NAME —' ?></div>
                                </td>
                                <td class="py-4 px-6 text-right">
                                    <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all">
                                        <button onclick='cdEditRow(<?= json_encode($r, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'
                                                class="w-8 h-8 flex items-center justify-center bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-all">
                                            <i class="fa-solid fa-pen text-[10px]"></i>
                                        </button>
                                        <button onclick="cdDelete(<?= (int)$r['id'] ?>, <?= htmlspecialchars(json_encode($r['name_th'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
                                                class="w-8 h-8 flex items-center justify-center bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-all">
                                            <i class="fa-solid fa-trash text-[10px]"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination (Improved) -->
                <?php if ($_cd_totalPages > 1): ?>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
                    <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest">
                        PAGE <?= $_cd_page ?> / <?= $_cd_totalPages ?>
                    </p>
                    <div class="flex items-center gap-1.5">
                        <?php
                        $prevPage = max(1, $_cd_page - 1);
                        $nextPage = min($_cd_totalPages, $_cd_page + 1);
                        $makeUrl  = fn(int $p) => '?' . $_cd_qs . '&cd_page=' . $p;
                        ?>
                        <a href="<?= $makeUrl(1) ?>" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-xs font-black text-slate-400 hover:bg-slate-100 transition-all"><i class="fa-solid fa-angles-left text-[10px]"></i></a>
                        <a href="<?= $makeUrl($prevPage) ?>" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-xs font-black text-slate-400 hover:bg-slate-100 transition-all"><i class="fa-solid fa-angle-left text-[10px]"></i></a>
                        
                        <?php
                        $start = max(1, $_cd_page - 2);
                        $end   = min($_cd_totalPages, $_cd_page + 2);
                        for ($p = $start; $p <= $end; $p++):
                            $isActive = $p === $_cd_page;
                        ?>
                        <a href="<?= $makeUrl($p) ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-black transition-all <?= $isActive ? 'bg-slate-800 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-100' ?>">
                            <?= $p ?>
                        </a>
                        <?php endfor; ?>

                        <a href="<?= $makeUrl($nextPage) ?>" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-xs font-black text-slate-400 hover:bg-slate-100 transition-all"><i class="fa-solid fa-angle-right text-[10px]"></i></a>
                        <a href="<?= $makeUrl($_cd_totalPages) ?>" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-lg text-xs font-black text-slate-400 hover:bg-slate-100 transition-all"><i class="fa-solid fa-angles-right text-[10px]"></i></a>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab 2: Rooms (Placeholder) -->
    <div class="clinic-tab-content hidden" id="tab-content-rooms">
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-20 text-center">
            <div class="w-20 h-20 bg-blue-50 text-blue-400 rounded-full flex items-center justify-center mx-auto mb-6 text-4xl">
                <i class="fa-solid fa-door-open"></i>
            </div>
            <h3 class="text-xl font-black text-slate-800 mb-2">ระบบจัดการห้องตรวจ</h3>
            <p class="text-slate-500 max-w-sm mx-auto font-medium">ส่วนนี้กำลังอยู่ในขั้นตอนการพัฒนา เพื่อรองรับการจองคิวและการจัดการห้องตรวจในอนาคต</p>
            <div class="mt-8">
                <span class="px-4 py-2 bg-blue-50 text-blue-600 rounded-xl text-xs font-black uppercase tracking-widest italic">Coming Soon</span>
            </div>
        </div>
    </div>

    <!-- Tab 3: Staff (Placeholder) -->
    <div class="clinic-tab-content hidden" id="tab-content-staff">
        <div class="bg-white rounded-3xl border border-slate-100 shadow-sm p-20 text-center">
            <div class="w-20 h-20 bg-emerald-50 text-emerald-400 rounded-full flex items-center justify-center mx-auto mb-6 text-4xl">
                <i class="fa-solid fa-user-doctor"></i>
            </div>
            <h3 class="text-xl font-black text-slate-800 mb-2">รายชื่อแพทย์และบุคลากร</h3>
            <p class="text-slate-500 max-w-sm mx-auto font-medium">ระบบกำลังพัฒนาระบบฐานข้อมูลแพทย์และตารางเวร เพื่อเชื่อมต่อกับระบบนัดหมาย</p>
            <div class="mt-8">
                <span class="px-4 py-2 bg-emerald-50 text-emerald-600 rounded-xl text-xs font-black uppercase tracking-widest italic">Under Development</span>
            </div>
        </div>
    </div>
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
                <label class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 block">ประเภท <span class="text-red-400">*</span></label>
                <select name="type" id="cd-edit-type" required class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all bg-gray-50">
                    <option value="faculty">คณะ</option>
                    <option value="department">หน่วยงาน</option>
                </select>
            </div>
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

    // ── Tab Switcher ─────────────────────────────────────────────────────────
    window.switchClinicTab = function (tabId) {
        // Hide all contents
        document.querySelectorAll('.clinic-tab-content').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.clinic-tab-content').forEach(el => el.classList.remove('active'));
        
        // Deactivate all buttons
        document.querySelectorAll('.clinic-tab-btn').forEach(btn => {
            btn.classList.remove('active', 'bg-white', 'shadow-sm', 'text-slate-800');
            btn.classList.add('text-slate-500', 'hover:text-slate-700', 'hover:bg-white/50');
        });

        // Show selected content
        const targetContent = document.getElementById('tab-content-' + tabId);
        if (targetContent) {
            targetContent.classList.remove('hidden');
            targetContent.classList.add('active');
        }

        // Activate selected button
        const targetBtn = document.getElementById('tab-btn-' + tabId);
        if (targetBtn) {
            targetBtn.classList.add('active', 'bg-white', 'shadow-sm', 'text-slate-800');
            targetBtn.classList.remove('text-slate-500', 'hover:text-slate-700', 'hover:bg-white/50');
        }
    };

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
    const eType  = document.getElementById('cd-edit-type');
    const eCode  = document.getElementById('cd-edit-code');
    const eTh    = document.getElementById('cd-edit-name-th');
    const eEn    = document.getElementById('cd-edit-name-en');
    const eResu  = document.getElementById('cd-edit-result');

    window.cdEditRow = function (row) {
        eIdEl.value = row.id;
        eType.value = row.type || 'faculty';
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

    const impBtnWrap = document.getElementById('cd-import-btn-wrap');

    function applyFile(f) {
        const ext = f.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'csv'].includes(ext)) {
            cdShowImportResult('error', 'รองรับเฉพาะไฟล์ .xlsx และ .csv เท่านั้น');
            return;
        }
        fileNm.textContent = f.name;
        fileSz.textContent = (f.size / 1024).toFixed(1) + ' KB';
        fileInfo.classList.remove('hidden');
        impBtnWrap.classList.remove('hidden');
        impRes.classList.add('hidden');
        impBtn.innerHTML = '<i class="fa-solid fa-file-import"></i> นำเข้าข้อมูลจากไฟล์';
        impBtn.disabled = false;
        impBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    window.cdClearFile = function () {
        fileIn.value = '';
        fileInfo.classList.add('hidden');
        impBtnWrap.classList.add('hidden');
        impRes.classList.add('hidden');
    };
    window.cdImport = async function () {
        if (!fileIn.files[0]) return;
        const importType = document.querySelector('input[name="cd-import-type"]:checked')?.value || 'faculty';
        impBtn.disabled = true;
        impBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังประมวลผล...';
        const fd = new FormData();
        fd.append('action', 'import');
        fd.append('import_type', importType);
        fd.append('import_file', fileIn.files[0]);
        fd.append('csrf_token', CSRF);
        try {
            const res  = await fetch(ENDPOINT, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status === 'ok') {
                cdShowImportResult('ok', data.message + ' (' + (importType === 'faculty' ? 'คณะ' : 'หน่วยงาน') + ')');
                setTimeout(() => location.reload(), 1000);
            } else {
                cdShowImportResult('error', data.message);
                impBtn.disabled = false;
                impBtn.innerHTML = '<i class="fa-solid fa-file-import"></i> นำเข้าข้อมูลจากไฟล์';
            }
        } catch (e) {
            cdShowImportResult('error', 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้');
            impBtn.style.opacity = '1';
            impBtn.style.pointerEvents = 'auto';
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
