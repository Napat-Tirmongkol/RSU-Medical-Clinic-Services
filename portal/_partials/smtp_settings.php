<?php
// portal/_partials/smtp_settings.php — included by portal/index.php
// config.php (and mail_helper.php via it) already loaded by portal

require_once __DIR__ . '/../../includes/mail_helper.php';

$_smtp_secrets      = get_secrets();
$_smtp_hasConfig    = !empty($_smtp_secrets['SMTP_HOST']) && !empty($_smtp_secrets['SMTP_USER']);
$_smtp_secretsPath  = __DIR__ . '/../../config/secrets.php';
$_smtp_fileWritable = file_exists($_smtp_secretsPath) ? is_writable($_smtp_secretsPath) : is_writable(dirname($_smtp_secretsPath));
?>
<style>
.smtp-input{width:100%;padding:.75rem 1rem;background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:.875rem;font-size:.9rem;font-weight:500;color:#111827;outline:none;transition:all .2s}
.smtp-input:focus{background:#fff;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.1)}
.smtp-label{display:block;font-size:.7rem;font-weight:900;color:#9ca3af;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem}
.smtp-card{background:#fff;border-radius:1.5rem;border:1.5px solid #e5e7eb;padding:1.75rem;margin-bottom:1.25rem}
</style>

<div class="p-6 max-w-3xl">
    <div class="mb-6">
        <h2 class="text-2xl font-black text-gray-900 flex items-center gap-2">
            <i class="fa-solid fa-paper-plane text-blue-500"></i> SMTP Settings
        </h2>
        <p class="text-xs text-gray-400 mt-1">ตั้งค่าและทดสอบระบบส่งอีเมลแจ้งเตือน</p>
    </div>

    <!-- Status Banner -->
    <div class="flex items-start gap-4 p-5 rounded-2xl mb-6 <?= $_smtp_hasConfig ? 'bg-emerald-50 border border-emerald-100' : 'bg-amber-50 border border-amber-100' ?>">
        <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 <?= $_smtp_hasConfig ? 'bg-emerald-100 text-emerald-600' : 'bg-amber-100 text-amber-600' ?>">
            <i class="fa-solid <?= $_smtp_hasConfig ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> text-lg"></i>
        </div>
        <div class="flex-1">
            <p class="font-black text-sm <?= $_smtp_hasConfig ? 'text-emerald-800' : 'text-amber-800' ?>">
                <?= $_smtp_hasConfig ? 'SMTP ตั้งค่าไว้แล้ว' : 'ยังไม่ได้ตั้งค่า SMTP' ?>
            </p>
            <p class="text-xs mt-0.5 <?= $_smtp_hasConfig ? 'text-emerald-600' : 'text-amber-600' ?>">
                <?php if ($_smtp_hasConfig): ?>
                    Host: <strong><?= htmlspecialchars($_smtp_secrets['SMTP_HOST']) ?></strong> ·
                    Port: <strong><?= htmlspecialchars((string)($_smtp_secrets['SMTP_PORT'] ?? 587)) ?></strong> ·
                    User: <strong><?= htmlspecialchars($_smtp_secrets['SMTP_USER']) ?></strong>
                <?php else: ?>
                    กรอกข้อมูล SMTP ด้านล่างเพื่อเปิดใช้งานระบบส่งอีเมล
                <?php endif; ?>
            </p>
        </div>
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-[10px] font-black uppercase <?= $_smtp_fileWritable ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
            <i class="fa-solid <?= $_smtp_fileWritable ? 'fa-lock-open' : 'fa-lock' ?>"></i>
            secrets.php <?= $_smtp_fileWritable ? 'เขียนได้' : 'เขียนไม่ได้' ?>
        </span>
    </div>

    <!-- SMTP Config Form -->
    <div class="smtp-card shadow-sm">
        <h3 class="font-black text-gray-900 mb-5 flex items-center gap-2">
            <span class="w-8 h-8 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center"><i class="fa-solid fa-server text-sm"></i></span>
            ตั้งค่า SMTP
        </h3>
        <form id="smtpForm" class="space-y-5">
            <?php csrf_field(); ?>
            <div class="grid grid-cols-3 gap-4">
                <div class="col-span-2">
                    <label class="smtp-label">SMTP Host <span class="text-red-400">*</span></label>
                    <input type="text" name="SMTP_HOST" id="smtp_host" class="smtp-input" value="<?= htmlspecialchars($_smtp_secrets['SMTP_HOST'] ?? '') ?>" placeholder="smtp.gmail.com">
                </div>
                <div>
                    <label class="smtp-label">Port</label>
                    <select name="SMTP_PORT" id="smtp_port" class="smtp-input">
                        <option value="587" <?= (($_smtp_secrets['SMTP_PORT'] ?? 587) == 587) ? 'selected' : '' ?>>587 — TLS</option>
                        <option value="465" <?= (($_smtp_secrets['SMTP_PORT'] ?? 587) == 465) ? 'selected' : '' ?>>465 — SSL</option>
                        <option value="25"  <?= (($_smtp_secrets['SMTP_PORT'] ?? 587) == 25)  ? 'selected' : '' ?>>25  — Plain</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="smtp-label">SMTP Username <span class="text-red-400">*</span></label>
                    <input type="text" name="SMTP_USER" id="smtp_user" class="smtp-input" value="<?= htmlspecialchars($_smtp_secrets['SMTP_USER'] ?? '') ?>" placeholder="your@gmail.com">
                </div>
                <div>
                    <label class="smtp-label">SMTP Password <?php if ($_smtp_hasConfig): ?><span class="text-gray-400 font-normal">(เว้นว่างถ้าไม่เปลี่ยน)</span><?php endif; ?></label>
                    <div class="relative">
                        <input type="password" name="SMTP_PASS" id="smtp_pass" class="smtp-input pr-10" placeholder="<?= $_smtp_hasConfig ? '••••••••' : 'App Password' ?>">
                        <button type="button" onclick="smtpTogglePass()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i id="smtpPassEye" class="fa-solid fa-eye-slash text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="smtp-label">From Email</label>
                    <input type="email" name="SMTP_FROM_EMAIL" id="smtp_from_email" class="smtp-input" value="<?= htmlspecialchars($_smtp_secrets['SMTP_FROM_EMAIL'] ?? '') ?>" placeholder="noreply@rsu.ac.th">
                </div>
                <div>
                    <label class="smtp-label">From Name</label>
                    <input type="text" name="SMTP_FROM_NAME" id="smtp_from_name" class="smtp-input" value="<?= htmlspecialchars($_smtp_secrets['SMTP_FROM_NAME'] ?? 'RSU Medical Clinic Services') ?>">
                </div>
            </div>
            <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 text-xs text-blue-700 space-y-1">
                <p class="font-black flex items-center gap-1.5"><i class="fa-brands fa-google"></i> วิธีใช้ Gmail SMTP</p>
                <p>1. เปิด <strong>2-Factor Authentication</strong> → <strong>Google Account → Security → App Passwords</strong></p>
                <p>2. สร้าง App Password → ได้รหัส 16 ตัว → ใส่ใน SMTP Password</p>
                <p>3. Host: <code class="bg-blue-100 px-1 rounded">smtp.gmail.com</code>, Port: <code class="bg-blue-100 px-1 rounded">587</code></p>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="button" onclick="smtpSaveConfig()" class="px-6 py-3 bg-gray-900 text-white rounded-xl font-bold text-sm hover:bg-black flex items-center gap-2 shadow-lg">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึกการตั้งค่า
                </button>
                <div id="smtpSaveStatus" class="hidden flex items-center gap-2 text-sm font-bold text-emerald-600">
                    <i class="fa-solid fa-circle-check"></i> บันทึกแล้ว
                </div>
            </div>
        </form>
    </div>

    <!-- Test Email -->
    <div class="smtp-card shadow-sm">
        <h3 class="font-black text-gray-900 mb-5 flex items-center gap-2">
            <span class="w-8 h-8 bg-green-100 text-green-600 rounded-xl flex items-center justify-center"><i class="fa-solid fa-flask text-sm"></i></span>
            ทดสอบส่งอีเมล
        </h3>
        <div class="mb-5">
            <label class="smtp-label">ส่งทดสอบไปที่</label>
            <input type="email" id="smtpTestEmail" class="smtp-input" placeholder="your@email.com" value="<?= htmlspecialchars($_smtp_secrets['SMTP_USER'] ?? '') ?>">
        </div>
        <div class="space-y-2.5">
            <?php
            $smtpTests = [
                ['basic',               'fa-server',      'bg-gray-200 text-gray-600',   '#374151',  'ทดสอบการเชื่อมต่อ SMTP', 'ส่ง email พื้นฐานยืนยัน SMTP'],
                ['confirmation',        'fa-circle-check','bg-emerald-100 text-emerald-600','#059669','ยืนยันการจอง',          'อีเมลแจ้งเมื่อผู้ใช้จองสำเร็จ'],
                ['approved',            'fa-thumbs-up',   'bg-blue-100 text-blue-600',    '#0052CC',  'อนุมัติการจอง',         'อีเมลแจ้งเมื่อแอดมินอนุมัติคิว'],
                ['cancelled_by_user',   'fa-user-xmark',  'bg-orange-100 text-orange-600','#ea580c',  'ยกเลิกโดยผู้ใช้',       'อีเมลแจ้งเมื่อผู้ใช้ยกเลิกเอง'],
                ['cancelled_by_admin',  'fa-user-shield', 'bg-red-100 text-red-600',      '#dc2626',  'ยกเลิกโดยแอดมิน',       'อีเมลแจ้งเมื่อแอดมินยกเลิกให้'],
            ];
            foreach ($smtpTests as [$type, $ico, $icoCls, $btnColor, $title, $desc]): ?>
            <div class="flex items-center gap-3 p-3 rounded-xl border" style="background:<?= $type==='basic'?'#f9fafb':'' ?>">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 <?= $icoCls ?>">
                    <i class="fa-solid <?= $ico ?> text-xs"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-800 text-sm"><?= $title ?></p>
                    <p class="text-xs text-gray-500"><?= $desc ?></p>
                </div>
                <button onclick="smtpSendTest('<?= $type ?>', this)"
                        data-label='<i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ'
                        class="smtp-btn-test px-4 py-2 rounded-xl font-bold text-xs text-white flex items-center gap-1.5 shrink-0"
                        style="background:<?= $btnColor ?>">
                    <i class="fa-solid fa-paper-plane"></i> ส่งทดสอบ
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <div id="smtpTestResult" class="hidden mt-4 p-4 rounded-xl text-sm font-semibold flex items-start gap-3"></div>
    </div>
</div>

<script>
(function() {
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

    window.smtpSaveConfig = function() {
        const fd = new FormData();
        const d  = getFormData(); d.action = 'save';
        for (const [k, v] of Object.entries(d)) fd.append(k, v);
        fetch('ajax/ajax_test_smtp.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                const el = document.getElementById('smtpSaveStatus');
                el.classList.remove('hidden', 'text-red-500');
                if (data.ok) {
                    el.className = 'flex items-center gap-2 text-sm font-bold text-emerald-600';
                    el.innerHTML = '<i class="fa-solid fa-circle-check"></i> บันทึกแล้ว';
                } else {
                    el.className = 'flex items-center gap-2 text-sm font-bold text-red-500';
                    el.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + (data.error || 'เกิดข้อผิดพลาด');
                }
                setTimeout(() => el.classList.add('hidden'), 4000);
            })
            .catch(() => alert('ไม่สามารถเชื่อมต่อ server ได้'));
    };

    window.smtpSendTest = function(type, btnEl) {
        const to = document.getElementById('smtpTestEmail').value.trim();
        if (!to) { alert('กรุณาระบุอีเมลปลายทาง'); return; }
        const origLabel = btnEl.dataset.label;
        document.querySelectorAll('.smtp-btn-test').forEach(b => b.disabled = true);
        btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังส่ง...';
        const fd = new FormData();
        const d  = getFormData();
        d.to_email = to;
        d.action   = type === 'basic' ? 'test' : 'test_template';
        if (type !== 'basic') d.template_type = type;
        for (const [k, v] of Object.entries(d)) fd.append(k, v);
        fetch('ajax/ajax_test_smtp.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => smtpShowResult(data.ok, data.ok ? data.message : (data.error || 'ส่งอีเมลไม่สำเร็จ')))
            .catch(() => smtpShowResult(false, 'ไม่สามารถเชื่อมต่อ server ได้'))
            .finally(() => {
                document.querySelectorAll('.smtp-btn-test').forEach(b => b.disabled = false);
                btnEl.innerHTML = origLabel;
            });
    };

    window.smtpShowResult = function(ok, msg) {
        const el = document.getElementById('smtpTestResult');
        el.classList.remove('hidden');
        if (ok) {
            el.className = 'mt-4 p-4 rounded-xl text-sm font-semibold flex items-start gap-3 bg-emerald-50 border border-emerald-100 text-emerald-700';
            el.innerHTML = '<i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i><span>' + msg + '</span>';
        } else {
            el.className = 'mt-4 p-4 rounded-xl text-sm font-semibold flex items-start gap-3 bg-red-50 border border-red-100 text-red-600';
            el.innerHTML = '<i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i><span>' + msg + '</span>';
        }
    };

    window.smtpTogglePass = function() {
        const el = document.getElementById('smtp_pass');
        const ico = document.getElementById('smtpPassEye');
        el.type = el.type === 'password' ? 'text' : 'password';
        ico.className = el.type === 'password' ? 'fa-solid fa-eye-slash text-sm' : 'fa-solid fa-eye text-sm';
    };
})();
</script>
