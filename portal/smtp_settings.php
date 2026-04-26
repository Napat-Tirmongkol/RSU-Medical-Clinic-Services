<?php
// portal/smtp_settings.php — ตั้งค่า SMTP และทดสอบส่งอีเมล (Superadmin only)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/mail_helper.php';

if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    header('Location: index.php'); exit;
}

$secrets     = get_secrets();
$hasConfig   = !empty($secrets['SMTP_HOST']) && !empty($secrets['SMTP_USER']);
$secretsPath = __DIR__ . '/../config/secrets.php';
$fileExists  = file_exists($secretsPath);
$fileWritable= $fileExists ? is_writable($secretsPath) : is_writable(dirname($secretsPath));
$embed       = isset($_GET['embed']);

$_GET['layout'] = 'none'; // บังคับไม่ให้โหลด sidebar ของ admin
require_once __DIR__ . '/../admin/includes/header.php';
?>

<style>
    .smtp-input {
        width:100%; padding:.75rem 1rem;
        background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:.875rem;
        font-size:.9rem; font-weight:500; color:#111827; outline:none;
        transition: all .2s;
    }
    .smtp-input:focus { background:#fff; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }
    .smtp-label { display:block; font-size:.7rem; font-weight:900; color:#9ca3af; text-transform:uppercase; letter-spacing:.1em; margin-bottom:.4rem; }
    .smtp-card  { background:#fff; border-radius:1.5rem; border:1.5px solid #e5e7eb; padding:1.75rem; margin-bottom:1.25rem; }
</style>

<div class="<?= $embed ? 'p-0' : 'max-w-3xl mx-auto px-4 py-8' ?>">

    <?php if (!$embed): ?>
    <?php
    $header_actions = '<a href="index.php" class="bg-white border border-gray-100 hover:bg-gray-50 text-gray-500 px-5 py-2.5 rounded-2xl font-bold flex items-center gap-2 transition-all shadow-sm text-sm group">
        <i class="fa-solid fa-arrow-left group-hover:-translate-x-1 transition-transform"></i> กลับ Dashboard
    </a>';
    <div class="mb-6 md:mb-10 flex flex-col md:flex-row md:justify-between md:items-end gap-4 md:gap-6 au d1">
        <div class="relative">
            <h1 class="text-xl sm:text-3xl md:text-4xl font-[950] text-gray-900 tracking-tight flex items-center gap-3 sm:gap-4">
                <div class="w-1.5 h-8 sm:w-2 sm:h-10 rounded-full shadow-lg flex-shrink-0" style="background:linear-gradient(180deg,#6366f1,#a5b4fc);box-shadow:0 4px 10px rgba(99,102,241,.3)"></div>
                SMTP Settings
            </h1>
            <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.25em] mt-2 sm:mt-3 ml-5 sm:ml-6 opacity-60" style="color:#6366f1">ตั้งค่าและทดสอบระบบส่งอีเมลแจ้งเตือน</p>
        </div>
        <div class="flex flex-wrap gap-3 items-center ml-5 sm:ml-6 md:ml-0" style="position:relative;z-index:100">
            <?= $header_actions ?>
        </div>
    </div>
    ?>
    <?php else: ?>
        <div class="mb-6">
            <h1 class="text-2xl font-black text-gray-900">SMTP Settings</h1>
            <p class="text-xs text-gray-500">ตั้งค่าและทดสอบระบบส่งอีเมลแจ้งเตือน</p>
        </div>
    <?php endif; ?>

    <!-- Status Banner -->
    <div class="flex items-start gap-4 p-5 rounded-2xl mb-6 <?= $hasConfig ? 'bg-emerald-50 border border-emerald-100' : 'bg-amber-50 border border-amber-100' ?>">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 <?= $hasConfig ? 'bg-emerald-100 text-emerald-600' : 'bg-amber-100 text-amber-600' ?>">
            <i class="fa-solid <?= $hasConfig ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> text-lg"></i>
        </div>
        <div>
            <p class="font-black text-sm <?= $hasConfig ? 'text-emerald-800' : 'text-amber-800' ?>">
                <?= $hasConfig ? 'SMTP ตั้งค่าไว้แล้ว' : 'ยังไม่ได้ตั้งค่า SMTP' ?>
            </p>
            <p class="text-xs mt-0.5 <?= $hasConfig ? 'text-emerald-600' : 'text-amber-600' ?>">
                <?php if ($hasConfig): ?>
                    Host: <strong><?= htmlspecialchars($secrets['SMTP_HOST']) ?></strong> ·
                    Port: <strong><?= htmlspecialchars((string)($secrets['SMTP_PORT'] ?? 587)) ?></strong> ·
                    User: <strong><?= htmlspecialchars($secrets['SMTP_USER']) ?></strong>
                <?php else: ?>
                    กรอกข้อมูล SMTP ด้านล่างเพื่อเปิดใช้งานระบบส่งอีเมลแจ้งเตือน
                <?php endif; ?>
            </p>
        </div>
        <div class="ml-auto shrink-0">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-[10px] font-black uppercase <?= $fileWritable ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                <i class="fa-solid <?= $fileWritable ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                secrets.php <?= $fileWritable ? 'เขียนได้' : 'เขียนไม่ได้' ?>
            </span>
        </div>
    </div>

    <!-- SMTP Config Form -->
    <div class="smtp-card shadow-sm">
        <h2 class="font-black text-gray-900 mb-5 flex items-center gap-2">
            <span class="w-8 h-8 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center">
                <i class="fa-solid fa-server text-sm"></i>
            </span>
            ตั้งค่า SMTP
        </h2>

        <form id="smtpForm" class="space-y-5">
            <?php csrf_field(); ?>

            <div class="grid grid-cols-3 gap-4">
                <div class="col-span-2">
                    <label class="smtp-label">SMTP Host <span class="text-red-400">*</span></label>
                    <input type="text" name="SMTP_HOST" id="smtp_host" class="smtp-input"
                           value="<?= htmlspecialchars($secrets['SMTP_HOST'] ?? '') ?>"
                           placeholder="smtp.gmail.com">
                </div>
                <div>
                    <label class="smtp-label">Port</label>
                    <select name="SMTP_PORT" id="smtp_port" class="smtp-input">
                        <option value="587" <?= ($secrets['SMTP_PORT'] ?? 587) == 587 ? 'selected' : '' ?>>587 — TLS (แนะนำ)</option>
                        <option value="465" <?= ($secrets['SMTP_PORT'] ?? 587) == 465 ? 'selected' : '' ?>>465 — SSL</option>
                        <option value="25"  <?= ($secrets['SMTP_PORT'] ?? 587) == 25  ? 'selected' : '' ?>>25  — Plain</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="smtp-label">SMTP Username (Email) <span class="text-red-400">*</span></label>
                    <input type="text" name="SMTP_USER" id="smtp_user" class="smtp-input"
                           value="<?= htmlspecialchars($secrets['SMTP_USER'] ?? '') ?>"
                           placeholder="your@gmail.com">
                </div>
                <div>
                    <label class="smtp-label">
                        SMTP Password / App Password
                        <?php if ($hasConfig): ?><span class="text-gray-400 font-normal">(เว้นว่างไว้ถ้าไม่ต้องการเปลี่ยน)</span><?php endif; ?>
                    </label>
                    <div class="relative">
                        <input type="password" name="SMTP_PASS" id="smtp_pass" class="smtp-input pr-10"
                               placeholder="<?= $hasConfig ? '••••••••' : 'App Password จาก Google' ?>">
                        <button type="button" onclick="togglePass()"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i id="passEye" class="fa-solid fa-eye-slash text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="smtp-label">From Email</label>
                    <input type="email" name="SMTP_FROM_EMAIL" id="smtp_from_email" class="smtp-input"
                           value="<?= htmlspecialchars($secrets['SMTP_FROM_EMAIL'] ?? '') ?>"
                           placeholder="noreply@rsu.ac.th">
                </div>
                <div>
                    <label class="smtp-label">From Name</label>
                    <input type="text" name="SMTP_FROM_NAME" id="smtp_from_name" class="smtp-input"
                           value="<?= htmlspecialchars($secrets['SMTP_FROM_NAME'] ?? 'RSU Medical Clinic Services') ?>">
                </div>
            </div>

            <!-- Gmail hint -->
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 text-xs text-blue-700 space-y-1">
                <p class="font-black flex items-center gap-1.5"><i class="fa-brands fa-google"></i> วิธีใช้ Gmail SMTP</p>
                <p>1. เปิด <strong>2-Factor Authentication</strong> ใน Google Account ก่อน</p>
                <p>2. ไปที่ <strong>Google Account → Security → App Passwords</strong></p>
                <p>3. สร้าง App Password (ชื่ออะไรก็ได้) → ได้รหัส 16 ตัวอักษร → ใส่ใน SMTP Password</p>
                <p>4. SMTP Host: <code class="bg-blue-100 px-1 rounded">smtp.gmail.com</code>, Port: <code class="bg-blue-100 px-1 rounded">587</code></p>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="saveConfig()"
                        class="px-6 py-3 bg-gray-900 text-white rounded-xl font-bold text-sm hover:bg-black transition-all active:scale-95 shadow-lg flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึกการตั้งค่า
                </button>
                <div id="saveStatus" class="hidden flex items-center gap-2 text-sm font-bold text-emerald-600">
                    <i class="fa-solid fa-circle-check"></i> บันทึกแล้ว
                </div>
            </div>
        </form>
    </div>

    <!-- Test Email -->
    <div class="smtp-card shadow-sm">
        <h2 class="font-black text-gray-900 mb-5 flex items-center gap-2">
            <span class="w-8 h-8 bg-green-100 text-green-600 rounded-xl flex items-center justify-center">
                <i class="fa-solid fa-flask text-sm"></i>
            </span>
            ทดสอบส่งอีเมลทุกระบบ
        </h2>

        <div class="mb-5">
            <label class="smtp-label">ส่งอีเมลทดสอบไปที่</label>
            <input type="email" id="testEmail" class="smtp-input"
                   placeholder="your@email.com"
                   value="<?= htmlspecialchars($secrets['SMTP_USER'] ?? '') ?>">
        </div>

        <div class="space-y-2.5">
            <!-- SMTP Connection -->
            <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 border border-gray-100">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-gray-200 text-gray-600 shrink-0">
                    <i class="fa-solid fa-server text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-800 text-sm">ทดสอบการเชื่อมต่อ SMTP</p>
                    <p class="text-xs text-gray-500">ส่ง email พื้นฐานเพื่อยืนยันว่า SMTP ทำงานได้</p>
                </div>
                <button onclick="sendTest('basic', this)"
                        data-label='<i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ'
                        class="btn-test px-4 py-2 rounded-xl font-bold text-xs text-white transition-all active:scale-95 flex items-center gap-1.5 shrink-0 bg-gray-700 hover:bg-gray-900">
                    <i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ
                </button>
            </div>

            <!-- Confirmation -->
            <div class="flex items-center gap-3 p-3 rounded-xl bg-emerald-50 border border-emerald-100">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-emerald-100 text-emerald-600 shrink-0">
                    <i class="fa-solid fa-circle-check text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-800 text-sm">ยืนยันการจอง</p>
                    <p class="text-xs text-gray-500">อีเมลแจ้งเมื่อผู้ใช้จองกิจกรรมสำเร็จ</p>
                </div>
                <button onclick="sendTest('confirmation', this)"
                        data-label='<i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ'
                        class="btn-test px-4 py-2 rounded-xl font-bold text-xs text-white transition-all active:scale-95 flex items-center gap-1.5 shrink-0"
                        style="background:#059669">
                    <i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ
                </button>
            </div>

            <!-- Approved -->
            <div class="flex items-center gap-3 p-3 rounded-xl bg-blue-50 border border-blue-100">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-blue-100 text-blue-600 shrink-0">
                    <i class="fa-solid fa-thumbs-up text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-800 text-sm">อนุมัติการจอง</p>
                    <p class="text-xs text-gray-500">อีเมลแจ้งเมื่อแอดมินอนุมัติคิวของผู้ใช้</p>
                </div>
                <button onclick="sendTest('approved', this)"
                        data-label='<i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ'
                        class="btn-test px-4 py-2 rounded-xl font-bold text-xs text-white transition-all active:scale-95 flex items-center gap-1.5 shrink-0"
                        style="background:#0052CC">
                    <i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ
                </button>
            </div>

            <!-- Cancelled by user -->
            <div class="flex items-center gap-3 p-3 rounded-xl bg-orange-50 border border-orange-100">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-orange-100 text-orange-600 shrink-0">
                    <i class="fa-solid fa-user-xmark text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-800 text-sm">ยกเลิกโดยผู้ใช้</p>
                    <p class="text-xs text-gray-500">อีเมลแจ้งเมื่อผู้ใช้ยกเลิกการจองของตัวเอง</p>
                </div>
                <button onclick="sendTest('cancelled_by_user', this)"
                        data-label='<i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ'
                        class="btn-test px-4 py-2 rounded-xl font-bold text-xs text-white transition-all active:scale-95 flex items-center gap-1.5 shrink-0"
                        style="background:#ea580c">
                    <i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ
                </button>
            </div>

            <!-- Cancelled by admin -->
            <div class="flex items-center gap-3 p-3 rounded-xl bg-red-50 border border-red-100">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center bg-red-100 text-red-600 shrink-0">
                    <i class="fa-solid fa-user-shield text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-800 text-sm">ยกเลิกโดยแอดมิน</p>
                    <p class="text-xs text-gray-500">อีเมลแจ้งเมื่อแอดมินยกเลิกคิวให้ผู้ใช้</p>
                </div>
                <button onclick="sendTest('cancelled_by_admin', this)"
                        data-label='<i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ'
                        class="btn-test px-4 py-2 rounded-xl font-bold text-xs text-white transition-all active:scale-95 flex items-center gap-1.5 shrink-0"
                        style="background:#dc2626">
                    <i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ
                </button>
            </div>
        </div>

        <!-- Result -->
        <div id="testResult" class="hidden mt-4 p-4 rounded-xl text-sm font-semibold flex items-start gap-3"></div>
    </div>

</div>

<script>
const CSRF = document.querySelector('[name="csrf_token"]')?.value || '';

function getFormData() {
    return {
        csrf_token:      CSRF,
        SMTP_HOST:       document.getElementById('smtp_host').value,
        SMTP_PORT:       document.getElementById('smtp_port').value,
        SMTP_USER:       document.getElementById('smtp_user').value,
        SMTP_PASS:       document.getElementById('smtp_pass').value,
        SMTP_FROM_EMAIL: document.getElementById('smtp_from_email').value,
        SMTP_FROM_NAME:  document.getElementById('smtp_from_name').value,
    };
}

function saveConfig() {
    const fd = new FormData();
    const d  = getFormData();
    d.action = 'save';
    for (const [k, v] of Object.entries(d)) fd.append(k, v);

    fetch('ajax/ajax_test_smtp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('saveStatus');
            if (data.ok) {
                el.classList.remove('hidden', 'text-red-500');
                el.classList.add('text-emerald-600');
                el.innerHTML = '<i class="fa-solid fa-circle-check"></i> บันทึกแล้ว';
            } else {
                el.classList.remove('hidden', 'text-emerald-600');
                el.classList.add('text-red-500');
                el.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + (data.error || 'เกิดข้อผิดพลาด');
            }
            el.classList.remove('hidden');
            setTimeout(() => el.classList.add('hidden'), 4000);
        })
        .catch(() => alert('ไม่สามารถเชื่อมต่อ server ได้'));
}

function sendTest(type, btnEl) {
    const to = document.getElementById('testEmail').value.trim();
    if (!to) { alert('กรุณาระบุอีเมลปลายทาง'); return; }

    const origLabel = btnEl.dataset.label;
    document.querySelectorAll('.btn-test').forEach(b => b.disabled = true);
    btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังส่ง...';

    const fd = new FormData();
    const d  = getFormData();
    d.to_email = to;
    d.action   = type === 'basic' ? 'test' : 'test_template';
    if (type !== 'basic') d.template_type = type;
    for (const [k, v] of Object.entries(d)) fd.append(k, v);

    fetch('ajax/ajax_test_smtp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => showTestResult(data.ok, data.ok ? data.message : (data.error || 'ส่งอีเมลไม่สำเร็จ')))
        .catch(() => showTestResult(false, 'ไม่สามารถเชื่อมต่อ server ได้'))
        .finally(() => {
            document.querySelectorAll('.btn-test').forEach(b => b.disabled = false);
            btnEl.innerHTML = origLabel;
        });
}

function showTestResult(ok, msg) {
    const el = document.getElementById('testResult');
    el.classList.remove('hidden');
    if (ok) {
        el.className = 'mt-4 p-4 rounded-xl text-sm font-semibold flex items-start gap-3 bg-emerald-50 border border-emerald-100 text-emerald-700';
        el.innerHTML = '<i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i><span>' + msg + '</span>';
    } else {
        el.className = 'mt-4 p-4 rounded-xl text-sm font-semibold flex items-start gap-3 bg-red-50 border border-red-100 text-red-600';
        el.innerHTML = '<i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i><span>' + msg + '</span>';
    }
}

function togglePass() {
    const el  = document.getElementById('smtp_pass');
    const ico = document.getElementById('passEye');
    if (el.type === 'password') {
        el.type = 'text';
        ico.className = 'fa-solid fa-eye text-sm';
    } else {
        el.type = 'password';
        ico.className = 'fa-solid fa-eye-slash text-sm';
    }
}
</script>

<?php require_once __DIR__ . '/../admin/includes/footer.php'; ?>
