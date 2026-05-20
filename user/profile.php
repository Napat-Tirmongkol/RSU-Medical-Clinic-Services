<?php
// user/profile.php — Sectioned profile with view/edit modes, medical fields, avatar
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/lang.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
$_testToken = $__secrets['PLAYWRIGHT_TEST_TOKEN'] ?? '';
$isTest = $_testToken !== '' && isset($_GET['test_token']) && hash_equals($_testToken, $_GET['test_token']);

if ($lineUserId === '' && !$isTest) {
    header('Location: index.php');
    exit;
}

$userData = [
    'prefix' => '', 'first_name' => '', 'last_name' => '', 'full_name' => '',
    'id_number' => '', 'citizen_id' => '', 'phone' => '', 'status' => '',
    'email' => '', 'gender' => '', 'date_of_birth' => '', 'department' => '',
    'picture_url' => '',
    'blood_type' => '', 'height_cm' => '', 'weight_kg' => '',
    'allergies' => '', 'chronic_conditions' => '',
    'emergency_contact_name' => '', 'emergency_contact_phone' => '',
    'emergency_contact_relation' => '',
    'member_id' => '',
    'updated_at' => '',
];

try {
    $pdo = db();

    // Self-healing migration — DESCRIBE ก่อนเพื่อ ALTER เฉพาะ column ที่ขาด
    // (ของเดิมรัน ALTER 9 ครั้งทุก request ~50–500ms ตอนนี้เหลือ 1 DESCRIBE ~1ms)
    $newCols = [
        'date_of_birth'              => "DATE NULL DEFAULT NULL",
        'blood_type'                 => "VARCHAR(8) NOT NULL DEFAULT ''",
        'height_cm'                  => "DECIMAL(5,2) NULL DEFAULT NULL",
        'weight_kg'                  => "DECIMAL(5,2) NULL DEFAULT NULL",
        'allergies'                  => "VARCHAR(500) NOT NULL DEFAULT ''",
        'chronic_conditions'         => "VARCHAR(500) NOT NULL DEFAULT ''",
        'emergency_contact_name'     => "VARCHAR(120) NOT NULL DEFAULT ''",
        'emergency_contact_phone'    => "VARCHAR(20)  NOT NULL DEFAULT ''",
        'emergency_contact_relation' => "VARCHAR(50)  NOT NULL DEFAULT ''",
    ];
    try {
        $existingCols = $pdo->query("DESCRIBE sys_users")->fetchAll(PDO::FETCH_COLUMN);
        $have = array_flip($existingCols);
        foreach ($newCols as $col => $def) {
            if (!isset($have[$col])) {
                try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN {$col} {$def}"); } catch (PDOException) {}
            }
        }
    } catch (PDOException $e) { error_log('[profile.php] schema check: ' . $e->getMessage()); }

    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmt->execute([':line_id' => $lineUserId]);
    $user = $stmt->fetch();

    if ($user) {
        $userData = array_merge($userData, [
            'prefix'         => $user['prefix'] ?? '',
            'first_name'     => $user['first_name'] ?? '',
            'last_name'      => $user['last_name'] ?? '',
            'full_name'      => $user['full_name'] ?? '',
            'id_number'      => $user['student_personnel_id'] ?? '',
            'citizen_id'     => $user['citizen_id'] ?? '',
            'phone'          => $user['phone_number'] ?? '',
            'status'         => $user['status'] ?? '',
            'email'          => $user['email'] ?? '',
            'gender'         => $user['gender'] ?? '',
            'date_of_birth'  => $user['date_of_birth'] ?? '',
            'department'     => $user['department'] ?? '',
            'picture_url'    => $user['picture_url'] ?? '',
            'blood_type'     => $user['blood_type'] ?? '',
            'height_cm'      => $user['height_cm'] ?? '',
            'weight_kg'      => $user['weight_kg'] ?? '',
            'allergies'      => $user['allergies'] ?? '',
            'chronic_conditions' => $user['chronic_conditions'] ?? '',
            'emergency_contact_name'     => $user['emergency_contact_name'] ?? '',
            'emergency_contact_phone'    => $user['emergency_contact_phone'] ?? '',
            'emergency_contact_relation' => $user['emergency_contact_relation'] ?? '',
            'member_id'      => $user['member_id'] ?? '',
            'updated_at'     => $user['updated_at'] ?? '',
        ]);
    }
} catch (PDOException $e) { error_log($e->getMessage()); }

// ── Insurance + Gold Card lookups: ย้ายไปโหลด async ผ่าน
//    ajax_profile_coverage.php หลัง page render เสร็จ → ลด TTFB
// (ของเดิม: query 2 รอบ พร้อม TRIM ใน WHERE → blocking)

if ($userData['full_name'] !== '' && $userData['first_name'] === '' && $userData['last_name'] === '') {
    $parts = explode(' ', trim($userData['full_name']), 2);
    $userData['first_name'] = $parts[0] ?? '';
    $userData['last_name'] = $parts[1] ?? '';
}

$isEditing = !empty($userData['full_name']);
$citizenIdValue = $userData['citizen_id'];
$isPassport = ($citizenIdValue !== '' && (!ctype_digit($citizenIdValue) || strlen($citizenIdValue) > 13));

// ── redirect_back: whitelist internal *.php only (XSS / open-redirect guard) ──
$rawRedirect = (string) ($_GET['redirect_back'] ?? 'hub.php');
$redirectBack = preg_match('/^[a-zA-Z0-9_\-\.]+\.php(\?[^\s]*)?$/', $rawRedirect) ? $rawRedirect : 'hub.php';

// ── Mode: view (default if profile exists) / edit ──
$mode = ($_GET['mode'] ?? '') === 'edit' || !$isEditing ? 'edit' : 'view';
$saved = isset($_GET['saved']);
$err   = (string) ($_GET['error'] ?? '');

// Faculty list (cache 1 hr — เปลี่ยนแปลงน้อย)
$_facultyList = [];
$_facCache = dirname(__DIR__) . '/storage/cache/faculties.json';
$_facTTL   = 3600;
if (is_readable($_facCache) && (time() - (int)filemtime($_facCache)) < $_facTTL) {
    $_facultyList = json_decode((string)file_get_contents($_facCache), true) ?: [];
}
if (empty($_facultyList)) {
    try {
        $stmt_fac = db()->query("SELECT name_th FROM sys_faculties ORDER BY name_th");
        if ($stmt_fac) $_facultyList = $stmt_fac->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!empty($_facultyList)) {
            @mkdir(dirname($_facCache), 0755, true);
            @file_put_contents($_facCache, json_encode($_facultyList, JSON_UNESCAPED_UNICODE));
        }
    } catch (Exception $e) { error_log("Faculty query failed: " . $e->getMessage()); }
}

// ── Profile completeness ──
$weighted = [
    'first_name' => 8, 'last_name' => 8, 'prefix' => 4, 'gender' => 4,
    'status' => 6, 'citizen_id' => 8, 'phone' => 8, 'department' => 6,
    'email' => 8,
    'blood_type' => 8, 'allergies' => 6, 'chronic_conditions' => 6,
    'height_cm' => 4, 'weight_kg' => 4,
    'emergency_contact_name' => 6, 'emergency_contact_phone' => 6,
];
$totalW = array_sum($weighted); $gotW = 0;
foreach ($weighted as $k => $w) {
    $v = $userData[$k] ?? '';
    if ($v !== '' && $v !== null && $v !== '0' && $v !== 0) $gotW += $w;
}
$completeness = (int) round(($gotW / $totalW) * 100);

$avatarUrl = $userData['picture_url'] !== ''
    ? $userData['picture_url']
    : 'https://ui-avatars.com/api/?background=2e9e63&color=fff&name=' . urlencode($userData['full_name'] ?: 'User');

$bloodOptions = ['', 'A', 'B', 'AB', 'O', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$disabled = $mode === 'view' ? 'disabled' : '';
$readonlyAttr = $mode === 'view' ? 'readonly' : '';

function vh(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= __('profile.heading_edit') ?> - RSU Medical</title>
    <link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . vh(SITE_LOGO) : '../favicon.ico?v=' . APP_VERSION ?>">
    <!-- ── Resource hints: เริ่ม DNS/TLS handshake ก่อนใช้งานจริง ── -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://ui-avatars.com" crossorigin>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
    <!-- Font Awesome async (preload + swap แทน blocking load) -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css?v=<?= APP_VERSION ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css?v=<?= APP_VERSION ?>" rel="stylesheet"></noscript>
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; background-color: #F8FAFF; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .safe-area-bottom { padding-bottom: env(safe-area-inset-bottom); }
        .field-input:disabled, .field-input[readonly] { background:#f8fafc; color:#475569; }
        .toast { animation: slideDown .3s cubic-bezier(.16,1,.3,1); }
        @keyframes slideDown { from { transform: translate(-50%, -120%); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
        .pdpa-box { transition: border-color .2s; }
    </style>
</head>
<body class="text-slate-900 pb-32">

    <?php if ($saved): ?>
    <div id="toast-saved" class="toast fixed top-5 left-1/2 z-[120] -translate-x-1/2 rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-black text-white shadow-xl shadow-emerald-200">
        <i class="fa-solid fa-circle-check mr-2"></i><?= __('profile.toast_saved') ?>
    </div>
    <script>setTimeout(() => document.getElementById('toast-saved')?.remove(), 2800);</script>
    <?php endif; ?>

    <?php if ($err !== ''): ?>
    <?php
        $errMap = [
            'invalid_email' => __('profile.toast_error_email'),
            'invalid_phone' => __('profile.toast_error_phone'),
            'invalid_citizen' => __('profile.toast_error_citizen'),
            'no_prefix' => __('profile.lbl_prefix'),
            'no_status'  => __('profile.lbl_user_type'),
            'no_gender'  => __('profile.lbl_gender'),
            'empty'      => __('profile.toast_error_phone'),
            'empty_student' => __('profile.lbl_id'),
            'no_consent_general'   => 'กรุณายอมรับเงื่อนไขข้อมูลส่วนบุคคลทั่วไป (มาตรา 24)',
            'no_consent_sensitive' => 'กรุณายอมรับเงื่อนไขข้อมูลอ่อนไหว (มาตรา 26)',
        ];
        $errMsg = $errMap[$err] ?? $err;
    ?>
    <div id="toast-err" class="toast fixed top-5 left-1/2 z-[120] -translate-x-1/2 rounded-2xl bg-rose-600 px-5 py-3 text-sm font-black text-white shadow-xl shadow-rose-200">
        <i class="fa-solid fa-circle-exclamation mr-2"></i><?= vh($errMsg) ?>
    </div>
    <script>setTimeout(() => document.getElementById('toast-err')?.remove(), 4000);</script>
    <?php endif; ?>

    <div class="max-w-md mx-auto relative min-h-screen">

        <!-- ── Header ── -->
        <header class="glass-header sticky top-0 z-[60] px-6 py-5 flex items-center justify-between border-b border-slate-100 shadow-sm shadow-slate-50">
            <button onclick="window.location.href='<?= vh($redirectBack) ?>'" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition-all">
                <i class="fa-solid fa-chevron-left"></i>
            </button>
            <h1 class="text-lg font-black text-slate-900 tracking-tight"><?= __($mode === 'view' ? 'profile.heading' : 'profile.heading_edit') ?></h1>
            <div class="flex items-center gap-2">
                <a href="<?= vh(lang_switch_url()) ?>" class="h-11 px-3 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-500 text-[11px] font-black active:scale-90 transition-all">
                    <?= current_lang() === 'th' ? 'EN' : 'TH' ?>
                </a>
                <button type="button" onclick="confirmLogout()" class="w-11 h-11 flex items-center justify-center bg-red-50 text-red-500 rounded-2xl active:scale-90 transition-all shadow-sm">
                    <i class="fa-solid fa-power-off"></i>
                </button>
            </div>
        </header>

        <main class="px-6 pt-8 pb-12">

            <!-- ── Avatar + Completeness Card ── -->
            <div class="bg-white rounded-[2.5rem] p-6 border border-slate-50 shadow-sm mb-6">
                <div class="flex items-center gap-4">
                    <img src="<?= vh($avatarUrl) ?>" alt="avatar"
                         class="w-20 h-20 rounded-3xl object-cover border-2 border-emerald-100 shadow-sm bg-slate-100"
                         onerror="this.src='https://ui-avatars.com/api/?background=2e9e63&color=fff&name=<?= urlencode($userData['full_name'] ?: 'U') ?>'">
                    <div class="flex-1 min-w-0">
                        <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">
                            <?= vh($userData['prefix']) ?>
                        </p>
                        <h2 class="truncate text-lg font-black text-slate-900"><?= vh($userData['full_name'] ?: '—') ?></h2>
                        <p class="text-xs font-bold text-slate-500 truncate"><?= vh($userData['email'] ?: ($userData['phone'] ?: '—')) ?></p>
                        <?php if (!empty($userData['member_id'])): ?>
                        <p class="mt-1 inline-flex items-center gap-1 text-[10px] font-black tracking-[0.15em] text-[#2e9e63]">
                            <i class="fa-solid fa-id-badge text-[9px]"></i><?= vh($userData['member_id']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-5">
                    <div class="flex items-end justify-between mb-1.5">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400"><?= __('profile.completeness') ?></p>
                        <p class="text-sm font-black <?= $completeness >= 100 ? 'text-emerald-600' : 'text-slate-700' ?>"><?= $completeness ?>%</p>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-600 transition-all" style="width: <?= $completeness ?>%"></div>
                    </div>
                    <p class="mt-2 text-[11px] font-bold text-slate-400">
                        <?= $completeness >= 100 ? __('profile.completeness_done') : __('profile.completeness_more') ?>
                    </p>
                </div>

                <?php if ($mode === 'view'): ?>
                <div class="mt-5 flex gap-3">
                    <a href="?mode=edit" class="flex-1 h-12 flex items-center justify-center bg-[#2e9e63] text-white rounded-2xl font-black text-sm active:scale-95 transition-all">
                        <i class="fa-solid fa-pen mr-2"></i><?= __('profile.btn_edit') ?>
                    </a>
                </div>
                <p class="mt-3 text-center text-[11px] font-bold text-slate-400"><?= __('profile.view_mode_hint') ?></p>
                <?php endif; ?>
            </div>

            <!-- ── Coverage sections (insurance + gold card) — โหลด async หลัง page render ── -->
            <?php if (defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE): ?>
            <div id="profile-coverage-insurance" class="mb-6">
                <div class="bg-white rounded-[2.5rem] p-6 border border-slate-50 shadow-sm">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="h-5 w-1 rounded-full bg-rose-500"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700">ความคุ้มครอง</h3>
                        <span class="ml-auto text-[10px] font-black text-slate-400 uppercase tracking-widest">Medical Coverage</span>
                    </div>
                    <div class="rounded-2xl bg-slate-50 border border-slate-100 p-5 flex items-center gap-3 animate-pulse">
                        <div class="w-10 h-10 rounded-xl bg-slate-200"></div>
                        <div class="flex-1 space-y-2">
                            <div class="h-3 bg-slate-200 rounded w-2/3"></div>
                            <div class="h-2.5 bg-slate-200 rounded w-1/2"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div id="profile-coverage-gold-card" class="mb-6"></div>

            <form id="profileForm" action="save_profile.php" method="POST" class="space-y-6" novalidate>
                <?php csrf_field(); ?>
                <input type="hidden" name="redirect_back" value="<?= vh($redirectBack) ?>">

                <!-- ── Section: Basic Info ── -->
                <div class="bg-white rounded-[2.5rem] p-7 border border-slate-50 shadow-sm space-y-5">
                    <div class="flex items-center gap-2">
                        <span class="h-5 w-1 rounded-full bg-[#2e9e63]"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700"><?= __('profile.section_basic') ?></h3>
                    </div>

                    <!-- Prefix -->
                    <div class="space-y-1.5">
                        <label for="name_title" class="text-sm font-bold text-slate-700"><?= __('profile.lbl_prefix') ?> <span class="text-red-500">*</span></label>
                        <?php
                        $_stdPrefixes = ['นาย', 'นาง', 'นางสาว', 'นพ.', 'พญ.', 'ทพ.', 'ทญ.', 'ภก.', 'ภญ.', 'พย.', 'ดร.', 'อ.', 'ผศ.', 'รศ.', 'ศ.'];
                        $_isCustomPrefix = ($userData['prefix'] !== '' && !in_array($userData['prefix'], $_stdPrefixes, true));
                        $_selectVal = $_isCustomPrefix ? 'other' : $userData['prefix'];
                        $_customVal = $_isCustomPrefix ? $userData['prefix'] : '';
                        ?>
                        <select name="name_title" id="name_title" onchange="toggleCustomTitle()" required <?= $disabled ?>
                            class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none transition-all font-bold text-slate-700">
                            <option value="" disabled <?= $_selectVal === '' ? 'selected' : '' ?>><?= __('profile.select_placeholder') ?></option>
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
                            <input type="text" id="custom_title" name="custom_title" value="<?= vh($_customVal) ?>"
                                <?= $disabled ?> placeholder="ระบุเอง..."
                                class="field-input w-full h-12 px-5 bg-white border border-slate-200 rounded-2xl outline-none font-bold">
                        </div>
                    </div>

                    <!-- Name -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_first_name') ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="first_name" required value="<?= vh($userData['first_name']) ?>" <?= $readonlyAttr ?>
                                class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none font-bold">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_last_name') ?> <span class="text-red-500">*</span></label>
                            <input type="text" name="last_name" required value="<?= vh($userData['last_name']) ?>" <?= $readonlyAttr ?>
                                class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none font-bold">
                        </div>
                    </div>

                    <!-- Gender -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_gender') ?> <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            <?php foreach(['male' => 'ชาย', 'female' => 'หญิง', 'other' => 'อื่นๆ'] as $v => $l): ?>
                            <label class="flex-1 cursor-pointer <?= $disabled ? 'pointer-events-none opacity-90' : '' ?>">
                                <input type="radio" name="gender" value="<?= $v ?>" required class="peer hidden" <?= $userData['gender'] === $v ? 'checked' : '' ?> <?= $disabled ?>>
                                <div class="py-4 text-center rounded-2xl border border-slate-100 bg-slate-50 font-bold text-sm text-slate-400 peer-checked:bg-[#2e9e63] peer-checked:text-white peer-checked:border-[#2e9e63] transition-all"><?= $l ?></div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Date of Birth -->
                    <?php
                        $dobValue = $userData['date_of_birth'] ?? '';
                        if ($dobValue === '0000-00-00') $dobValue = '';
                    ?>
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700">วันเดือนปีเกิด</label>
                        <input type="date" name="date_of_birth" value="<?= vh($dobValue) ?>" max="<?= date('Y-m-d') ?>" <?= $readonlyAttr ?>
                            class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none font-bold">
                        <p class="text-[11px] text-slate-400 font-semibold">ใช้สำหรับสมัครสิทธิ/บัตรทอง — กรอกตามบัตรประชาชน</p>
                    </div>
                </div>

                <!-- ── Section: Identification ── -->
                <div class="bg-white rounded-[2.5rem] p-7 border border-slate-50 shadow-sm space-y-5">
                    <div class="flex items-center gap-2">
                        <span class="h-5 w-1 rounded-full bg-[#2e9e63]"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700"><?= __('profile.section_id') ?></h3>
                    </div>

                    <!-- User Type -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_user_type') ?> <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-3 gap-3">
                            <?php foreach(['student' => 'นักศึกษา', 'staff' => 'บุคลากร', 'other' => 'ทั่วไป'] as $v => $l): ?>
                            <label class="cursor-pointer <?= $disabled ? 'pointer-events-none opacity-90' : '' ?>">
                                <input type="radio" name="status" value="<?= $v ?>" required class="peer hidden" <?= $userData['status'] === $v ? 'checked' : '' ?> <?= $disabled ?>>
                                <div class="py-4 text-center rounded-2xl border border-slate-100 bg-slate-50 font-bold text-[11px] text-slate-400 peer-checked:bg-emerald-500 peer-checked:text-white peer-checked:border-emerald-500 transition-all"><?= $l ?></div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Citizen ID with show/hide -->
                    <div id="id_section" class="space-y-5">
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_citizen_id') ?> <span class="text-red-500">*</span></label>
                            <div class="flex gap-2 mb-3">
                                <label class="flex-1 cursor-pointer <?= $disabled ? 'pointer-events-none' : '' ?>">
                                    <input type="radio" name="id_type" value="citizen" class="peer hidden" <?= !$isPassport ? 'checked' : '' ?> <?= $disabled ?>>
                                    <div class="py-2.5 text-center border border-slate-100 bg-slate-50 rounded-xl peer-checked:bg-green-50 peer-checked:border-green-200 peer-checked:text-[#2e9e63] font-bold text-[10px] transition-all"><?= __('profile.lbl_identity_th') ?></div>
                                </label>
                                <label class="flex-1 cursor-pointer <?= $disabled ? 'pointer-events-none' : '' ?>">
                                    <input type="radio" name="id_type" value="passport" class="peer hidden" <?= $isPassport ? 'checked' : '' ?> <?= $disabled ?>>
                                    <div class="py-2.5 text-center border border-slate-100 bg-slate-50 rounded-xl peer-checked:bg-green-50 peer-checked:border-green-200 peer-checked:text-[#2e9e63] font-bold text-[10px] transition-all">Passport</div>
                                </label>
                            </div>
                            <div class="relative">
                                <input type="password" id="citizen_id" name="citizen_id" required value="<?= vh($userData['citizen_id']) ?>" <?= $readonlyAttr ?> autocomplete="off"
                                    class="field-input w-full h-14 pl-4 pr-14 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none font-bold tracking-widest"
                                    placeholder="<?= __('profile.lbl_citizen_id') ?>">
                                <button type="button" id="cid-toggle" onclick="toggleCidVisibility()"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-xl bg-white border border-slate-200 text-slate-400 active:scale-95 transition-all">
                                    <i id="cid-icon" class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <p id="citizen-error" class="hidden text-[11px] font-bold text-rose-500"><i class="fa-solid fa-circle-exclamation mr-1"></i><?= __('profile.toast_error_citizen') ?></p>
                        </div>

                        <div id="student_id_container" class="space-y-1.5">
                            <label for="id_number" class="text-sm font-bold text-slate-700"><?= __('profile.lbl_id') ?> <span class="text-red-500">*</span></label>
                            <input type="text" id="id_number" name="id_number" value="<?= vh($userData['id_number']) ?>" <?= $readonlyAttr ?>
                                class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none font-bold"
                                placeholder="<?= __('profile.id_placeholder') ?>">
                        </div>
                    </div>
                </div>

                <!-- ── Section: Contact Info ── -->
                <div class="bg-white rounded-[2.5rem] p-7 border border-slate-50 shadow-sm space-y-5">
                    <div class="flex items-center gap-2">
                        <span class="h-5 w-1 rounded-full bg-[#2e9e63]"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700"><?= __('profile.section_contact') ?></h3>
                    </div>

                    <!-- Department -->
                    <div class="space-y-1.5">
                        <label for="department" class="text-sm font-bold text-slate-700">คณะ / หน่วยงาน <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="text" id="department" name="department" list="faculty-datalist" value="<?= vh($userData['department']) ?>" <?= $readonlyAttr ?>
                                class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none font-bold"
                                placeholder="<?= __('profile.dept_placeholder') ?>">
                            <datalist id="faculty-datalist">
                                <?php foreach ($_facultyList as $_f) echo "<option value='".vh($_f['name_th'])."'>"; ?>
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

                    <!-- Phone -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_phone') ?> <span class="text-red-500">*</span></label>
                        <input type="tel" id="phone_number" name="phone_number" required value="<?= vh($userData['phone']) ?>" <?= $readonlyAttr ?>
                            inputmode="numeric" maxlength="10"
                            class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none font-bold">
                        <p id="phone-error" class="hidden text-[11px] font-bold text-rose-500"><i class="fa-solid fa-circle-exclamation mr-1"></i><?= __('profile.toast_error_phone') ?></p>
                    </div>

                    <!-- Email -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_email') ?> <?= __('profile.optional') ?></label>
                        <input type="email" id="email" name="email" value="<?= vh($userData['email']) ?>" <?= $readonlyAttr ?>
                            class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-green-50 outline-none font-bold">
                        <p id="email-error" class="hidden text-[11px] font-bold text-rose-500"><i class="fa-solid fa-circle-exclamation mr-1"></i><?= __('profile.toast_error_email') ?></p>
                        <p class="text-[11px] text-amber-600 font-bold mt-2 px-1"><i class="fa-solid fa-circle-info mr-1"></i> <?= __('profile.email_note') ?></p>
                    </div>
                </div>

                <!-- ── Section: Health Info ── -->
                <div class="bg-white rounded-[2.5rem] p-7 border border-slate-50 shadow-sm space-y-5">
                    <div class="flex items-center gap-2">
                        <span class="h-5 w-1 rounded-full bg-rose-500"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700"><?= __('profile.section_health') ?></h3>
                    </div>
                    <p class="text-[11px] font-bold text-slate-400 -mt-3"><i class="fa-solid fa-shield-heart mr-1 text-rose-400"></i><?= __('profile.section_health_desc') ?></p>

                    <!-- Blood type -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_blood_type') ?></label>
                        <select name="blood_type" <?= $disabled ?>
                            class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-rose-50 outline-none font-bold text-slate-700">
                            <?php foreach ($bloodOptions as $bo): ?>
                                <option value="<?= vh($bo) ?>" <?= $userData['blood_type'] === $bo ? 'selected' : '' ?>><?= $bo === '' ? '— ' . __('profile.select_placeholder') . ' —' : vh($bo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Height / Weight -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_height') ?></label>
                            <input type="number" name="height_cm" min="50" max="250" step="0.1" value="<?= vh((string) $userData['height_cm']) ?>" <?= $readonlyAttr ?>
                                class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-rose-50 outline-none font-bold">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_weight') ?></label>
                            <input type="number" name="weight_kg" min="10" max="300" step="0.1" value="<?= vh((string) $userData['weight_kg']) ?>" <?= $readonlyAttr ?>
                                class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-rose-50 outline-none font-bold">
                        </div>
                    </div>

                    <!-- Allergies -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_allergies') ?></label>
                        <textarea name="allergies" rows="2" <?= $readonlyAttr ?> placeholder="<?= __('profile.placeholder_allergies') ?>"
                            class="field-input w-full px-4 py-3 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-rose-50 outline-none font-bold resize-none"><?= vh($userData['allergies']) ?></textarea>
                    </div>

                    <!-- Chronic conditions -->
                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_chronic') ?></label>
                        <textarea name="chronic_conditions" rows="2" <?= $readonlyAttr ?> placeholder="<?= __('profile.placeholder_chronic') ?>"
                            class="field-input w-full px-4 py-3 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-rose-50 outline-none font-bold resize-none"><?= vh($userData['chronic_conditions']) ?></textarea>
                    </div>
                </div>

                <!-- ── Section: Emergency Contact ── -->
                <div class="bg-white rounded-[2.5rem] p-7 border border-slate-50 shadow-sm space-y-5">
                    <div class="flex items-center gap-2">
                        <span class="h-5 w-1 rounded-full bg-amber-500"></span>
                        <h3 class="text-sm font-black uppercase tracking-widest text-slate-700"><?= __('profile.section_emergency') ?></h3>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_emergency_name') ?></label>
                        <input type="text" name="emergency_contact_name" value="<?= vh($userData['emergency_contact_name']) ?>" <?= $readonlyAttr ?>
                            placeholder="<?= __('profile.placeholder_emergency_name') ?>"
                            class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-amber-50 outline-none font-bold">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_emergency_phone') ?></label>
                            <input type="tel" id="emergency_phone" name="emergency_contact_phone" value="<?= vh($userData['emergency_contact_phone']) ?>" <?= $readonlyAttr ?>
                                inputmode="numeric" maxlength="10"
                                class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-amber-50 outline-none font-bold">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-sm font-bold text-slate-700"><?= __('profile.lbl_emergency_relation') ?></label>
                            <input type="text" name="emergency_contact_relation" value="<?= vh($userData['emergency_contact_relation']) ?>" <?= $readonlyAttr ?>
                                placeholder="<?= __('profile.placeholder_relation') ?>"
                                class="field-input w-full h-14 px-4 bg-slate-50 border border-slate-100 rounded-2xl focus:ring-4 focus:ring-amber-50 outline-none font-bold">
                        </div>
                    </div>
                </div>

                <!-- ── PDPA Consent v2 (Sec. 24 + Sec. 26 split) ── -->
                <?php
                // Bump version when text materially changes — server validates a hash
                // of the served text and stores both with the consent record so a
                // future audit can reconstruct exactly what the user saw.
                $pdpaVersion = 'pdpa_v2_2025-05';
                ?>
                <input type="hidden" name="pdpa_version" value="<?= htmlspecialchars($pdpaVersion) ?>">
                <div class="bg-white rounded-[2.5rem] p-7 border border-slate-50 shadow-sm space-y-5">
                    <div class="space-y-1">
                        <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest leading-snug"><?= __('profile.pdpa_title') ?></h3>
                        <p class="text-xs font-bold text-slate-400 leading-snug"><?= __('profile.pdpa_version_label') ?></p>
                    </div>
                    <div id="pdpa-box" class="pdpa-box bg-slate-50 p-6 rounded-3xl text-[12px] text-slate-600 leading-relaxed max-h-72 overflow-y-auto custom-scrollbar border border-slate-100 space-y-4">
                        <div class="text-slate-900 font-black"><?= __('profile.pdpa_welcome') ?></div>

                        <!-- Data Controller / DPO (มาตรา 23) -->
                        <div>
                            <div class="font-black text-slate-700"><?= __('profile.pdpa_controller_title') ?></div>
                            <p><?= __('profile.pdpa_controller_desc') ?></p>
                        </div>

                        <!-- Legal basis (มาตรา 24, 26) -->
                        <div>
                            <div class="font-black text-slate-700"><?= __('profile.pdpa_legal_basis_title') ?></div>
                            <p><?= __('profile.pdpa_legal_basis_desc') ?></p>
                        </div>

                        <p><?= __('profile.pdpa_intro') ?></p>

                        <!-- ── Section 1: General data (มาตรา 24) ── -->
                        <div class="pt-2 border-t border-slate-200">
                            <div class="font-black text-emerald-700 text-[13px] mb-2"><?= __('profile.pdpa_section_general') ?></div>
                            <div class="space-y-3">
                                <div>
                                    <div class="font-black text-slate-700"><?= __('profile.pdpa_general_cats_title') ?></div>
                                    <p><?= __('profile.pdpa_general_cats_desc') ?></p>
                                </div>
                                <div>
                                    <div class="font-black text-slate-700"><?= __('profile.pdpa_purposes_title') ?></div>
                                    <div class="space-y-2 mt-1">
                                        <?php foreach ([1,2,3,4] as $i): ?>
                                        <div>
                                            <div class="font-bold text-slate-700"><?= __("profile.pdpa_item{$i}_title") ?></div>
                                            <p class="pl-3"><?= __("profile.pdpa_item{$i}_desc") ?></p>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="font-black text-slate-700"><?= __('profile.pdpa_third_party_title') ?></div>
                                    <p><?= __('profile.pdpa_third_party_desc') ?></p>
                                </div>
                                <div>
                                    <div class="font-black text-slate-700"><?= __('profile.pdpa_retention_title') ?></div>
                                    <p><?= __('profile.pdpa_retention_desc') ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- ── Section 2: Sensitive data (มาตรา 26) ── -->
                        <div class="pt-2 border-t border-slate-200">
                            <div class="font-black text-rose-700 text-[13px] mb-2"><?= __('profile.pdpa_section_sensitive') ?></div>
                            <p class="text-rose-700 font-bold"><?= __('profile.pdpa_sensitive_intro') ?></p>
                            <div class="space-y-3 mt-2">
                                <div>
                                    <div class="font-black text-slate-700"><?= __('profile.pdpa_sensitive_cats_title') ?></div>
                                    <p><?= __('profile.pdpa_sensitive_cats_desc') ?></p>
                                </div>
                                <div>
                                    <div class="font-black text-slate-700"><?= __('profile.pdpa_sensitive_purpose_title') ?></div>
                                    <p><?= __('profile.pdpa_sensitive_purpose_desc') ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- ── Rights, withdrawal, refusal, complaint ── -->
                        <div class="pt-2 border-t border-slate-200">
                            <div class="font-black text-slate-700"><?= __('profile.pdpa_rights_title') ?></div>
                            <p><?= __('profile.pdpa_rights_desc') ?></p>
                        </div>
                        <div>
                            <div class="font-black text-slate-700"><?= __('profile.pdpa_withdrawal_title') ?></div>
                            <p><?= __('profile.pdpa_withdrawal_desc') ?></p>
                        </div>
                        <div>
                            <div class="font-black text-slate-700"><?= __('profile.pdpa_refusal_title') ?></div>
                            <p><?= __('profile.pdpa_refusal_desc') ?></p>
                        </div>
                        <div>
                            <div class="font-black text-slate-700"><?= __('profile.pdpa_complaint_title') ?></div>
                            <p><?= __('profile.pdpa_complaint_desc') ?></p>
                        </div>
                    </div>

                    <p id="pdpa-scroll-hint" class="<?= $isEditing ? 'hidden' : '' ?> text-[11px] font-bold text-amber-600 -mt-2"><i class="fa-solid fa-arrow-down mr-1"></i><?= __('profile.pdpa_scroll_hint') ?></p>

                    <!-- Two separate consent checkboxes — required under PDPA Sec. 26
                         for explicit separate consent on sensitive data -->
                    <label id="pdpa-agree-wrap" class="flex items-start gap-4 p-5 bg-emerald-50 rounded-3xl border border-emerald-100 cursor-pointer active:scale-95 transition-all <?= !$isEditing ? 'opacity-50 pointer-events-none' : '' ?>">
                        <input type="checkbox" id="pdpa-agree" name="consent_general" value="1" required <?= $isEditing ? 'checked' : '' ?> <?= $disabled ?> class="mt-0.5 w-6 h-6 rounded-lg text-[#2e9e63] focus:ring-[#2e9e63]">
                        <span class="text-xs text-slate-700 font-bold leading-relaxed"><?= __('profile.lbl_agree_general') ?></span>
                    </label>
                    <label id="pdpa-agree-sensitive-wrap" class="flex items-start gap-4 p-5 bg-rose-50 rounded-3xl border border-rose-100 cursor-pointer active:scale-95 transition-all <?= !$isEditing ? 'opacity-50 pointer-events-none' : '' ?>">
                        <input type="checkbox" id="pdpa-agree-sensitive" name="consent_sensitive" value="1" required <?= $isEditing ? 'checked' : '' ?> <?= $disabled ?> class="mt-0.5 w-6 h-6 rounded-lg text-rose-600 focus:ring-rose-500">
                        <span class="text-xs text-slate-700 font-bold leading-relaxed"><?= __('profile.lbl_agree_sensitive') ?></span>
                    </label>

                    <!-- Legacy field for backwards compatibility with any old handlers
                         that still look at `agreed` — gets ticked iff both granular
                         checkboxes are ticked -->
                    <input type="hidden" id="pdpa-agree-legacy" name="agreed" value="<?= $isEditing ? '1' : '' ?>">
                </div>

                <?php if ($mode === 'edit'): ?>
                <div class="flex gap-4">
                    <a href="<?= $isEditing ? '?mode=view' : 'hub.php' ?>" class="flex-1 h-16 flex items-center justify-center bg-white border border-slate-200 text-slate-400 font-black rounded-2xl"><?= __('profile.btn_cancel') ?></a>
                    <button type="submit" class="flex-[2] h-16 bg-slate-900 text-white font-black rounded-2xl shadow-xl shadow-slate-200"><?= __('profile.save_btn') ?></button>
                </div>
                <?php endif; ?>

            </form>

            <footer class="pt-10 pb-20 text-center opacity-30">
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.3em]">© 2568 RSU Medical Services</p>
                <p class="text-slate-400 text-[8px] font-black uppercase tracking-[0.2em] mt-2">v<?= APP_VERSION ?> · build <?= APP_BUILD ?></p>
            </footer>
        </main>

        <?php $__navActive = 'account'; require __DIR__ . '/../includes/user_bottom_nav.php'; ?>
    </div>

    <!-- ── Logout confirm modal ── -->
    <div id="logout-modal" class="hidden fixed inset-0 z-[120] items-center justify-center p-6">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="hideLogoutModal()"></div>
        <div class="relative bg-white w-full max-w-[320px] rounded-[2rem] p-8 text-center shadow-2xl">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-rose-50 text-rose-500 flex items-center justify-center text-2xl">
                <i class="fa-solid fa-power-off"></i>
            </div>
            <p class="text-base font-black text-slate-900 mb-6"><?= __('profile.logout_confirm') ?></p>
            <div class="flex gap-3">
                <button type="button" onclick="hideLogoutModal()" class="flex-1 h-12 bg-slate-100 text-slate-500 font-black rounded-2xl"><?= __('profile.btn_cancel') ?></button>
                <a href="logout.php" class="flex-1 h-12 flex items-center justify-center bg-rose-500 text-white font-black rounded-2xl">Logout</a>
            </div>
        </div>
    </div>

    <script>
        const MODE = <?= json_encode($mode) ?>;
        const IS_EDITING = <?= json_encode($isEditing) ?>;

        function toggleCustomTitle() {
            const sel = document.getElementById('name_title');
            const container = document.getElementById('custom_title_container');
            const input = document.getElementById('custom_title');
            if (sel.value === 'other') { container.classList.remove('hidden'); input.focus(); }
            else { container.classList.add('hidden'); input.value = ''; }
        }

        function confirmLogout() {
            const m = document.getElementById('logout-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
        function hideLogoutModal() {
            const m = document.getElementById('logout-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        function toggleCidVisibility() {
            const inp = document.getElementById('citizen_id');
            const ic = document.getElementById('cid-icon');
            if (inp.type === 'password') { inp.type = 'text'; ic.className = 'fa-solid fa-eye-slash'; }
            else { inp.type = 'password'; ic.className = 'fa-solid fa-eye'; }
        }

        // ── Thai citizen ID checksum (13 digits) ──
        function isValidThaiCID(s) {
            if (!/^\d{13}$/.test(s)) return false;
            let sum = 0;
            for (let i = 0; i < 12; i++) sum += parseInt(s[i], 10) * (13 - i);
            const check = (11 - (sum % 11)) % 10;
            return check === parseInt(s[12], 10);
        }
        function isValidThaiPhone(s) { return /^0\d{9}$/.test(s); }

        document.addEventListener('DOMContentLoaded', function () {
            // ID Type
            const idTypeInputs = document.querySelectorAll('input[name="id_type"]');
            const citizenIdInput = document.getElementById('citizen_id');
            const citizenError = document.getElementById('citizen-error');
            function applyIdType(type) {
                if (type === 'passport') {
                    citizenIdInput.setAttribute('placeholder', 'Passport Number');
                    citizenIdInput.removeAttribute('maxlength');
                } else {
                    citizenIdInput.setAttribute('placeholder', '<?= addslashes(__('profile.lbl_citizen_id')) ?>');
                    citizenIdInput.setAttribute('maxlength', '13');
                }
                citizenError.classList.add('hidden');
            }
            idTypeInputs.forEach(i => i.addEventListener('change', e => applyIdType(e.target.value)));

            citizenIdInput.addEventListener('blur', () => {
                const type = document.querySelector('input[name="id_type"]:checked')?.value;
                const v = citizenIdInput.value.trim();
                if (type === 'citizen' && v && !isValidThaiCID(v)) {
                    citizenError.classList.remove('hidden');
                } else { citizenError.classList.add('hidden'); }
            });

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

            // Phone validation
            const phone = document.getElementById('phone_number');
            const phoneErr = document.getElementById('phone-error');
            phone?.addEventListener('blur', () => {
                if (phone.value.trim() && !isValidThaiPhone(phone.value.trim())) phoneErr.classList.remove('hidden');
                else phoneErr.classList.add('hidden');
            });
            phone?.addEventListener('input', () => phone.value = phone.value.replace(/\D/g, '').slice(0, 10));

            // Emergency phone digit-only
            const epPhone = document.getElementById('emergency_phone');
            epPhone?.addEventListener('input', () => epPhone.value = epPhone.value.replace(/\D/g, '').slice(0, 10));

            // Email validation
            const email = document.getElementById('email');
            const emailErr = document.getElementById('email-error');
            email?.addEventListener('blur', () => {
                const v = email.value.trim();
                if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) emailErr.classList.remove('hidden');
                else emailErr.classList.add('hidden');
            });

            // Submit guard
            document.getElementById('profileForm')?.addEventListener('submit', (e) => {
                if (MODE === 'view') { e.preventDefault(); return; }
                const type = document.querySelector('input[name="id_type"]:checked')?.value;
                const cid = citizenIdInput.value.trim();
                if (type === 'citizen' && cid && !isValidThaiCID(cid)) {
                    e.preventDefault();
                    citizenError.classList.remove('hidden');
                    citizenIdInput.focus();
                    return;
                }
                if (phone && phone.value.trim() && !isValidThaiPhone(phone.value.trim())) {
                    e.preventDefault();
                    phoneErr.classList.remove('hidden');
                    phone.focus();
                    return;
                }
                if (email && email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
                    e.preventDefault();
                    emailErr.classList.remove('hidden');
                    email.focus();
                    return;
                }
            });

            // PDPA scroll-to-enable + dual-checkbox sync (only for first-time users)
            const pdpaBox = document.getElementById('pdpa-box');
            const pdpaAgree = document.getElementById('pdpa-agree');
            const pdpaAgreeSensitive = document.getElementById('pdpa-agree-sensitive');
            const pdpaWrap = document.getElementById('pdpa-agree-wrap');
            const pdpaWrapSensitive = document.getElementById('pdpa-agree-sensitive-wrap');
            const pdpaHint = document.getElementById('pdpa-scroll-hint');
            const pdpaLegacy = document.getElementById('pdpa-agree-legacy');

            // Keep the legacy `agreed` field in sync — only "1" when BOTH granular
            // consents are ticked, so any downstream code that still inspects it
            // can't see partial consent and think the user is fully on board
            function syncLegacyAgreed() {
                if (!pdpaLegacy) return;
                pdpaLegacy.value = (pdpaAgree?.checked && pdpaAgreeSensitive?.checked) ? '1' : '';
            }
            pdpaAgree?.addEventListener('change', syncLegacyAgreed);
            pdpaAgreeSensitive?.addEventListener('change', syncLegacyAgreed);
            syncLegacyAgreed();

            if (pdpaBox && !IS_EDITING) {
                const onScroll = () => {
                    if (pdpaBox.scrollTop + pdpaBox.clientHeight >= pdpaBox.scrollHeight - 10) {
                        pdpaWrap?.classList.remove('opacity-50', 'pointer-events-none');
                        pdpaWrapSensitive?.classList.remove('opacity-50', 'pointer-events-none');
                        pdpaHint?.classList.add('hidden');
                        pdpaBox.removeEventListener('scroll', onScroll);
                    }
                };
                pdpaBox.addEventListener('scroll', onScroll);
            }

            // AI Dept
            const deptInput = document.getElementById('department');
            const deptHint = document.getElementById('dept-ai-hint');
            const deptHintText = document.getElementById('dept-ai-hint-text');
            const deptAccept = document.getElementById('dept-ai-accept');
            const deptDismiss = document.getElementById('dept-ai-dismiss');
            let _deptSuggested = null;
            deptInput?.addEventListener('blur', async function() {
                const val = this.value.trim();
                if (!val || MODE === 'view') return;
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
            deptInput?.addEventListener('input', () => deptHint?.classList.add('hidden'));
            deptAccept?.addEventListener('click', () => { if(_deptSuggested) deptInput.value = _deptSuggested; deptHint.classList.add('hidden'); });
            deptDismiss?.addEventListener('click', () => deptHint.classList.add('hidden'));
        });
    </script>

    <!-- ── Defer-load coverage (insurance + gold card) — non-blocking ── -->
    <script>
    (function () {
        const insBox = document.getElementById('profile-coverage-insurance');
        const gcBox  = document.getElementById('profile-coverage-gold-card');
        if (!insBox && !gcBox) return;
        fetch('ajax_profile_coverage.php', { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(j => {
                if (!j || !j.ok) {
                    // ถ้า fetch fail ปล่อย skeleton แสดงไว้ (ไม่ใช่ critical)
                    return;
                }
                if (insBox && typeof j.insurance_html === 'string') {
                    insBox.innerHTML = j.insurance_html;
                }
                if (gcBox && typeof j.gold_card_html === 'string') {
                    gcBox.innerHTML = j.gold_card_html;
                }
            })
            .catch(() => { /* silent — skeleton remains */ });
    })();
    </script>
</body>
</html>
