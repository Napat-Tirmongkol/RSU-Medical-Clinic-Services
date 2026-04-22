<?php
// user/profile.php — Basic Form with Premium Shell
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/lang.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$userData = [
    'prefix' => '', 'first_name' => '', 'last_name' => '', 'full_name' => '',
    'id_number' => '', 'citizen_id' => '', 'phone' => '', 'status' => '',
    'email' => '', 'gender' => '', 'department' => '',
];

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmt->execute([':line_id' => $lineUserId]);
    $user = $stmt->fetch();

    if ($user) {
        $userData = [
            'prefix' => $user['prefix'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'full_name' => $user['full_name'] ?? '',
            'id_number' => $user['student_personnel_id'] ?? '',
            'citizen_id' => $user['citizen_id'] ?? '',
            'phone' => $user['phone_number'] ?? '',
            'status' => $user['status'] ?? '',
            'email' => $user['email'] ?? '',
            'gender' => $user['gender'] ?? '',
            'department' => $user['department'] ?? '',
        ];
    }
} catch (PDOException $e) { error_log($e->getMessage()); }

if ($userData['full_name'] !== '' && $userData['first_name'] === '' && $userData['last_name'] === '') {
    $parts = explode(' ', trim($userData['full_name']), 2);
    $userData['first_name'] = $parts[0] ?? '';
    $userData['last_name'] = $parts[1] ?? '';
}

$isEditing = !empty($userData['full_name']);
$redirectBack = $_GET['redirect_back'] ?? 'hub.php';
$citizenIdValue = $userData['citizen_id'];
$isPassport = ($citizenIdValue !== '' && (!ctype_digit($citizenIdValue) || strlen($citizenIdValue) > 13));

// Faculty list
$_facultyList = [];
try {
    $_pdo2 = db();
    $_facultyList = $_pdo2->query("SELECT name_th FROM sys_faculties ORDER BY name_th")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= __('profile.heading_edit') ?> - RSU Medical</title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <script src="https://cdn.tailwindcss.com/3.4.1"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; background-color: #F8FAFF; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .nav-item-active { color: #2563eb; }
        .nav-item-inactive { color: #94a3b8; }
        .safe-area-bottom { padding-bottom: env(safe-area-inset-bottom); }
    </style>
</head>
<body class="text-slate-900 pb-32">

    <div class="max-w-md mx-auto relative min-h-screen">
        
        <!-- ── Clean White Header ── -->
        <header class="glass-header sticky top-0 z-[60] px-6 py-5 flex items-center justify-between border-b border-slate-100 shadow-sm shadow-slate-50">
            <button onclick="window.location.href='<?= $redirectBack ?>'" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition-all">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <h1 class="text-lg font-black text-slate-900 tracking-tight"><?= __('profile.heading_edit') ?></h1>
            <div class="w-11 h-11"></div>
        </header>

        <main class="px-6 pt-8 pb-12">
            
            <form id="profileForm" action="save_profile.php" method="POST" class="space-y-6">
                <?php csrf_field(); ?>
                <input type="hidden" name="redirect_back" value="<?= htmlspecialchars($redirectBack) ?>">

                <!-- Basic Form Content (Original Structure) -->
                <div class="bg-white rounded-[2.5rem] p-8 border border-slate-50 shadow-sm space-y-6">
                    
                    <!-- Prefix -->
                    <div class="space-y-1.5">
                        <label for="name_title" class="text-sm font-bold text-slate-700"><?= __('profile.lbl_prefix') ?> <span class="text-red-500">*</span></label>
                        <?php
                        $_stdPrefixes = ['นาย', 'นาง', 'นางสาว', 'นพ.', 'พญ.', 'ทพ.', 'ทญ.', 'ภก.', 'ภญ.', 'พย.', 'ดร.', 'อ.', 'ผศ.', 'รศ.', 'ศ.'];
                        $_isCustomPrefix = ($userData['prefix'] !== '' && !in_array($userData['prefix'], $_stdPrefixes, true));
                        $_selectVal = $_isCustomPrefix ? 'other' : $userData['prefix'];
                        $_customVal = $_isCustomPrefix ? $userData['prefix'] : '';
                        ?>
                        <select name="name_title" id="name_title" onchange="toggleCustomTitle()" required
                            class="w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-50 outline-none transition-all font-bold text-slate-700">
                            <option value="" disabled <?= $_selectVal === '' ? 'selected' : '' ?>><?= __('profile.ph_prefix') ?></option>
                            <option value="นาย" <?= $_selectVal === 'นาย' ? 'selected' : '' ?>>นาย</option>
                            <option value="นาง" <?= $_selectVal === 'นาง' ? 'selected' : '' ?>>นาง</option>
                            <option value="นางสาว" <?= $_selectVal === 'นางสาว' ? 'selected' : '' ?>>นางสาว</option>
                            <optgroup label="การแพทย์">
                                <?php foreach(['นพ.','พญ.','ทพ.','ทญ.','ภก.','ภญ.','พย.'] as $p) echo "<option value='$p' ".($_selectVal==$p?'selected':'').">$p</option>"; ?>
                            </optgroup>
                            <optgroup label="วิชาการ">
                                <?php foreach(['ดร.','อ.','ผศ.','รศ.','ศ.'] as $p) echo "<option value='$p' ".($_selectVal==$p?'selected':'').">$p</option>"; ?>
                            </optgroup>
                            <option value="other" <?= $_selectVal === 'other' ? 'selected' : '' ?>>อื่นๆ...</option>
                        </select>
                        <div id="custom_title_container" class="<?= $_isCustomPrefix ? '' : 'hidden' ?> mt-3">
                            <input type="text" id="custom_title" name="custom_title" value="<?= htmlspecialchars($_customVal) ?>" 
                                placeholder="ระบุเอง..." 
                                class="w-full h-12 px-5 bg-white border border-slate-200 rounded-2xl outline-none font-bold">
                        </div>
                    </div>

                    <!-- Name -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_first_name') ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" required value="<?= htmlspecialchars($userData['first_name']) ?>"
                                class="w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-50 outline-none font-bold">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_last_name') ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="last_name" required value="<?= htmlspecialchars($userData['last_name']) ?>"
                                class="w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-50 outline-none font-bold">
                        </div>
                    </div>

                    <!-- Gender -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_gender') ?> <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            <?php foreach(['male' => 'ชาย', 'female' => 'หญิง', 'other' => 'อื่นๆ'] as $v => $l): ?>
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="gender" value="<?= $v ?>" required class="peer hidden" <?= $userData['gender'] === $v ? 'checked' : '' ?>>
                                <div class="py-4 text-center rounded-2xl border border-slate-100 bg-slate-50 font-bold text-sm text-slate-400 peer-checked:bg-blue-600 peer-checked:text-white peer-checked:border-blue-600 transition-all"><?= $l ?></div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="h-px bg-slate-50 my-2"></div>

                    <!-- User Type -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_user_type') ?> <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-3 gap-3">
                            <?php foreach(['student' => 'นักศึกษา', 'staff' => 'บุคลากร', 'other' => 'ทั่วไป'] as $v => $l): ?>
                            <label class="cursor-pointer">
                                <input type="radio" name="status" value="<?= $v ?>" required class="peer hidden" <?= $userData['status'] === $v ? 'checked' : '' ?>>
                                <div class="py-4 text-center rounded-2xl border border-slate-100 bg-slate-50 font-bold text-[11px] text-slate-400 peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:border-emerald-500 transition-all"><?= $l ?></div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ID Card Section -->
                    <div id="id_section" class="space-y-5">
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_citizen_id') ?> <span class="text-red-500">*</span></label>
                            <div class="flex gap-2 mb-3">
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="id_type" value="citizen" class="peer hidden" <?= !$isPassport ? 'checked' : '' ?>>
                                    <div class="py-2.5 text-center border border-slate-100 bg-slate-50 rounded-xl peer-checked:bg-blue-50 peer-checked:border-blue-200 peer-checked:text-blue-600 font-bold text-[10px] transition-all"><?= __('profile.lbl_identity_th') ?></div>
                                </label>
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="id_type" value="passport" class="peer hidden" <?= $isPassport ? 'checked' : '' ?>>
                                    <div class="py-2.5 text-center border border-slate-100 bg-slate-50 rounded-xl peer-checked:bg-blue-50 peer-checked:border-blue-200 peer-checked:text-blue-600 font-bold text-[10px] transition-all">Passport</div>
                                </label>
                            </div>
                            <input type="text" id="citizen_id" name="citizen_id" required value="<?= htmlspecialchars($userData['citizen_id']) ?>"
                                class="w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-50 outline-none font-bold" 
                                placeholder="<?= __('profile.lbl_citizen_id') ?>">
                        </div>

                        <div id="student_id_container" class="space-y-1.5">
                            <label for="id_number" class="text-sm font-bold text-slate-700"><?= __('profile.lbl_id') ?> <span class="text-red-500">*</span></label>
                            <input type="text" id="id_number" name="id_number" value="<?= htmlspecialchars($userData['id_number']) ?>"
                                class="w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-50 outline-none font-bold"
                                placeholder="<?= __('profile.id_placeholder') ?>">
                        </div>
                    </div>

                    <!-- Department -->
                    <div class="space-y-1.5">
                        <label for="department" class="text-sm font-bold text-slate-700">คณะ / หน่วยงาน <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="text" id="department" name="department" list="faculty-datalist" value="<?= htmlspecialchars($userData['department']) ?>"
                                class="w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-50 outline-none font-bold"
                                placeholder="<?= __('profile.dept_placeholder') ?>">
                            <datalist id="faculty-datalist">
                                <?php foreach ($_facultyList as $_f) echo "<option value='".htmlspecialchars($_f['name_th'])."'>"; ?>
                            </datalist>
                        </div>
                        <div id="dept-ai-hint" class="hidden items-center gap-2 text-[12px] text-teal-700 font-bold mt-2 bg-teal-50/50 p-3 rounded-xl border border-teal-100/50">
                            <i class="fa-solid fa-wand-magic-sparkles text-teal-500"></i>
                            <span id="dept-ai-hint-text" class="flex-1"></span>
                            <div class="flex gap-2">
                                <button type="button" id="dept-ai-accept" class="px-2 py-1 bg-teal-600 text-white rounded text-[10px]">ใช้ชื่อนี้</button>
                                <button type="button" id="dept-ai-dismiss" class="px-2 py-1 bg-slate-200 text-slate-500 rounded text-[10px]"><i class="fa-solid fa-xmark"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_phone') ?> <span class="text-red-500">*</span></label>
                        <input type="tel" name="phone_number" required value="<?= htmlspecialchars($userData['phone']) ?>"
                            class="w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-50 outline-none font-bold">
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_email') ?> (Optional)</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($userData['email']) ?>"
                            class="w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-blue-50 outline-none font-bold">
                        <p class="text-[11px] text-amber-600 font-bold mt-2 px-1"><i class="fa-solid fa-circle-info mr-1"></i> <?= __('profile.msg_email_benefit') ?></p>
                    </div>

                </div>

                <!-- PDPA Content -->
                <div class="bg-white rounded-[2.5rem] p-8 border border-slate-50 shadow-sm space-y-6">
                    <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest"><?= __('profile.pdpa_title') ?></h3>
                    <div class="bg-slate-50 p-5 rounded-3xl text-[12px] text-slate-500 leading-relaxed max-h-40 overflow-y-auto custom-scrollbar border border-slate-100">
                        <?= __('profile.pdpa_intro') ?>
                    </div>
                    <label class="flex items-center gap-4 p-5 bg-white rounded-3xl border border-slate-100 cursor-pointer active:scale-95 transition-all">
                        <input type="checkbox" name="agreed" value="1" required <?= $isEditing ? 'checked' : '' ?> class="w-6 h-6 rounded-lg text-blue-600 focus:ring-blue-500">
                        <span class="text-xs text-slate-600 font-bold"><?= __('profile.lbl_agree') ?></span>
                    </label>
                </div>

                <div class="flex gap-4">
                    <button type="button" onclick="window.history.back()" class="flex-1 h-16 bg-white border border-slate-200 text-slate-400 font-black rounded-2xl"><?= __('profile.back_btn') ?></button>
                    <button type="submit" class="flex-[2] h-16 bg-slate-900 text-white font-black rounded-2xl shadow-xl shadow-slate-200"><?= __('profile.save_btn') ?></button>
                </div>

            </form>
        </main>

        <!-- ── Premium Bottom Navigation ── -->
        <nav class="fixed bottom-0 left-0 right-0 z-[60] bg-white/80 backdrop-blur-xl border-t border-slate-100 px-8 py-4 flex items-center justify-between safe-area-bottom">
            <button onclick="window.location.href='hub.php'" class="flex flex-col items-center gap-1 nav-item-inactive">
                <i class="fa-solid fa-house text-xl"></i>
                <span class="text-[10px] font-black uppercase tracking-widest">หน้าหลัก</span>
            </button>
            <button onclick="window.location.href='hub.php#camps'" class="flex flex-col items-center gap-1 nav-item-inactive">
                <i class="fa-solid fa-syringe text-xl"></i>
                <span class="text-[10px] font-black uppercase tracking-widest">จองคิว</span>
            </button>
            <button onclick="window.location.href='my_bookings.php'" class="flex flex-col items-center gap-1 nav-item-inactive">
                <i class="fa-solid fa-calendar-days text-xl"></i>
                <span class="text-[10px] font-black uppercase tracking-widest">นัดหมาย</span>
            </button>
            <button onclick="window.location.href='profile.php'" class="flex flex-col items-center gap-1 nav-item-active">
                <div class="relative">
                    <i class="fa-solid fa-user-gear text-xl"></i>
                    <div class="absolute -top-1 -right-1 w-2 h-2 bg-blue-600 rounded-full"></div>
                </div>
                <span class="text-[10px] font-black uppercase tracking-widest">โปรไฟล์</span>
            </button>
        </nav>

    </div>

    <script>
        function toggleCustomTitle() {
            const sel = document.getElementById('name_title');
            const container = document.getElementById('custom_title_container');
            const input = document.getElementById('custom_title');
            if (sel.value === 'other') { container.classList.remove('hidden'); input.focus(); }
            else { container.classList.add('hidden'); input.value = ''; }
        }

        document.addEventListener('DOMContentLoaded', function () {
            // ID Type
            const idTypeInputs = document.querySelectorAll('input[name="id_type"]');
            const citizenIdInput = document.getElementById('citizen_id');
            function applyIdType(type) {
                if (type === 'passport') {
                    citizenIdInput.setAttribute('placeholder', 'Passport Number');
                    citizenIdInput.removeAttribute('maxlength');
                } else {
                    citizenIdInput.setAttribute('placeholder', '<?= __('profile.lbl_citizen_id') ?>');
                    citizenIdInput.setAttribute('maxlength', '13');
                }
            }
            idTypeInputs.forEach(i => i.addEventListener('change', e => applyIdType(e.target.value)));

            // Status Toggle
            const statusInputs = document.querySelectorAll('input[name="status"]');
            const studentIdContainer = document.getElementById('student_id_container');
            function toggleStatusFields() {
                const checked = document.querySelector('input[name="status"]:checked');
                if (checked && checked.value === 'other') studentIdContainer.classList.add('hidden');
                else studentIdContainer.classList.remove('hidden');
            }
            statusInputs.forEach(i => i.addEventListener('change', toggleStatusFields));
            toggleStatusFields();

            // AI Dept
            const deptInput = document.getElementById('department');
            const deptHint = document.getElementById('dept-ai-hint');
            const deptHintText = document.getElementById('dept-ai-hint-text');
            const deptAccept = document.getElementById('dept-ai-accept');
            const deptDismiss = document.getElementById('dept-ai-dismiss');
            let _deptSuggested = null;

            deptInput.addEventListener('blur', async function() {
                const val = this.value.trim();
                if (!val) return;
                try {
                    const fd = new FormData(); fd.append('input', val);
                    const res = await fetch('api_faculty_suggest.php', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.status === 'ok' && json.matched && json.matched !== val) {
                        _deptSuggested = json.matched;
                        deptHintText.textContent = 'AI แนะนำ: ' + json.matched;
                        deptHint.classList.remove('hidden'); deptHint.classList.add('flex');
                    }
                } catch(e) {}
            });
            deptInput.addEventListener('input', () => deptHint.classList.add('hidden'));
            deptAccept.addEventListener('click', () => { if(_deptSuggested) deptInput.value = _deptSuggested; deptHint.classList.add('hidden'); });
            deptDismiss.addEventListener('click', () => deptHint.classList.add('hidden'));
        });
    </script>
</body>
</html>