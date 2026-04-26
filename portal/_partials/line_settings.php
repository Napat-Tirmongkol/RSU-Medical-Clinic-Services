<?php
/**
 * portal/_partials/line_settings.php — ส่วนตั้งค่า LINE Messaging API (Partial for SPA)
 */
declare(strict_types=1);

// กรณีเรียกแยกไฟล์ (ไม่ใช่ผ่าน index.php)
if (!isset($secrets)) {
    $secrets = require __DIR__ . '/../../config/secrets.php';
}

// ดึง Webhook URL อัตโนมัติ
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$uri = str_replace(['portal/index.php', 'portal/_partials/line_settings.php'], 'api/line_webhook.php', $_SERVER['REQUEST_URI']);
$uri = strtok($uri, '?');
$webhookUrl = "$protocol://$host$uri";
?>

<style>
    .line-input {
        width:100%; padding:.75rem 1rem;
        background:#f9fafb; border:1.5px solid #e5e7eb; border-radius:.875rem;
        font-size:.9rem; font-weight:500; color:#111827; outline:none;
        transition: all .2s;
    }
    .line-input:focus { background:#fff; border-color:#06b6d4; box-shadow:0 0 0 3px rgba(6,182,212,.1); }
    .line-label { display:block; font-size:.75rem; font-weight:800; color:#4b5563; text-transform:uppercase; letter-spacing:.05em; margin-bottom:.5rem; }
    .line-card  { background:#fff; border-radius:1.5rem; border:1.5px solid #e5e7eb; padding:1.75rem; margin-bottom:1.25rem; }
</style>

<div class="px-4 py-8">

    <!-- Header -->
    <div class="mb-8 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-white rounded-xl shadow-sm border border-gray-200 flex items-center justify-center text-green-500 text-2xl">
                <i class="fa-brands fa-line"></i>
            </div>
            <div>
                <h2 class="text-2xl font-black text-slate-800">LINE Messaging API</h2>
                <p class="text-slate-500 text-sm font-medium">ตั้งค่า Webhook และทดสอบการส่งข้อความแจ้งเตือน</p>
            </div>
        </div>
        <button onclick="switchSection('settings')" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-xl text-xs font-bold hover:bg-slate-200 transition-all flex items-center gap-2">
            <i class="fa-solid fa-arrow-left"></i> กลับไปที่ Settings
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Left Column: Config -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- Webhook Info -->
            <div class="line-card bg-gradient-to-br from-slate-900 to-slate-800 border-none text-white shadow-xl overflow-hidden relative">
                <div class="absolute right-[-20px] top-[-20px] opacity-10 rotate-12">
                    <i class="fa-brands fa-line text-[120px]"></i>
                </div>
                <div class="relative z-10">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 bg-green-500 rounded-xl flex items-center justify-center shadow-lg shadow-green-500/20">
                            <i class="fa-solid fa-link text-white text-lg"></i>
                        </div>
                        <div>
                            <h3 class="font-black text-lg leading-tight">Webhook URL</h3>
                            <p class="text-[10px] text-green-400 font-bold uppercase tracking-widest">คัดลอกไปวางที่ LINE Developers</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 bg-black/30 p-4 rounded-2xl border border-white/10 group">
                        <code class="flex-1 font-mono text-sm text-blue-300 break-all" id="webhook_url_text_p"><?= $webhookUrl ?></code>
                        <button onclick="copyWebhookPartial()" class="p-2.5 bg-white/10 hover:bg-white/20 rounded-xl transition-all active:scale-95 flex-shrink-0">
                            <i id="copyIconP" class="fa-solid fa-copy text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- API Config Form -->
            <div class="line-card shadow-sm">
                <h2 class="font-black text-gray-900 mb-5 flex items-center gap-2">
                    <span class="w-8 h-8 bg-cyan-100 text-cyan-600 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-key text-sm"></i>
                    </span>
                    LINE API Credentials
                </h2>

                <form id="lineFormP" class="space-y-5">
                    <?php csrf_field(); ?>
                    <div>
                        <label class="line-label">Channel Access Token</label>
                        <textarea name="LINE_MESSAGING_CHANNEL_ACCESS_TOKEN" id="line_token_p" class="line-input font-mono text-xs placeholder:text-slate-400" rows="3"
                                  placeholder="Long-lived access token..."><?= htmlspecialchars($secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label class="line-label">Channel Secret</label>
                        <div class="relative">
                            <input type="password" name="LINE_MESSAGING_CHANNEL_SECRET" id="line_secret_p" class="line-input pr-10 placeholder:text-slate-400"
                                   value="<?= htmlspecialchars($secrets['LINE_MESSAGING_CHANNEL_SECRET'] ?? '') ?>"
                                   placeholder="Channel Secret">
                            <button type="button" onclick="toggleSecretP()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i id="secretEyeP" class="fa-solid fa-eye-slash text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="button" onclick="saveLineConfigP()"
                                class="px-6 py-3 bg-gray-900 text-white rounded-xl font-black text-sm hover:opacity-90 transition-all active:scale-95 shadow-lg flex items-center gap-2">
                            <i class="fa-solid fa-floppy-disk"></i> บันทึกข้อมูล
                        </button>
                        <div id="saveStatusP" class="hidden flex items-center gap-2 text-sm font-bold text-emerald-600">
                            <i class="fa-solid fa-circle-check"></i> บันทึกแล้ว
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column: Testing -->
        <div class="space-y-6">
            <div class="line-card shadow-sm border-t-4 border-t-green-500">
                <h2 class="font-black text-gray-900 mb-5 flex items-center gap-2">
                    <span class="w-8 h-8 bg-green-100 text-green-600 rounded-xl flex items-center justify-center">
                        <i class="fa-solid fa-paper-plane text-sm"></i>
                    </span>
                    Test Tool
                </h2>

                <div class="mb-5">
                    <label class="line-label">LINE User ID ผู้รับ</label>
                    <input type="text" id="toUserIdP" class="line-input font-mono text-sm placeholder:text-slate-400"
                           placeholder="Uxxxxxxxxxxxxxxxx..."
                           value="<?= htmlspecialchars($_SESSION['line_user_id'] ?? '') ?>">
                    <p class="text-[11px] text-slate-600 mt-2 font-medium leading-relaxed">
                        <i class="fa-solid fa-circle-info text-blue-500"></i> ส่งข้อความ Push หาตัวเองเพื่อทดสอบความถูกต้องของ Token
                    </p>
                </div>

                <button onclick="sendTestLineP()" id="btnTestP"
                        class="w-full py-3 bg-[#06C755] text-white rounded-xl font-black text-sm hover:opacity-90 transition-all active:scale-[0.98] shadow-lg flex items-center justify-center gap-2">
                    <i class="fa-solid fa-flask"></i> ส่งข้อความทดสอบ
                </button>

                <div id="testResultP" class="hidden mt-4 p-4 rounded-xl text-xs font-semibold flex items-start gap-3"></div>
            </div>

            <!-- Helpful Links -->
            <div class="bg-blue-50 rounded-2xl p-5 border border-blue-100">
                <h4 class="text-blue-800 font-black text-xs uppercase tracking-wider mb-3">คู่มือเบื้องต้น</h4>
                <ul class="text-[11px] text-blue-700 space-y-2 font-bold">
                    <li><a href="https://developers.line.biz/console/" target="_blank" class="hover:underline flex items-center gap-2"><i class="fa-solid fa-external-link"></i> LINE Developers Console</a></li>
                    <li><a href="https://developers.line.biz/en/docs/messaging-api/overview/" target="_blank" class="hover:underline flex items-center gap-2"><i class="fa-solid fa-book"></i> API Documentation</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function copyWebhookPartial() {
    const text = document.getElementById('webhook_url_text_p').innerText;
    const ico  = document.getElementById('copyIconP');
    navigator.clipboard.writeText(text).then(() => {
        ico.className = 'fa-solid fa-check text-green-400';
        setTimeout(() => ico.className = 'fa-solid fa-copy text-sm', 2000);
    });
}

function toggleSecretP() {
    const el = document.getElementById('line_secret_p');
    const ico = document.getElementById('secretEyeP');
    if (el.type === 'password') {
        el.type = 'text';
        ico.className = 'fa-solid fa-eye text-sm';
    } else {
        el.type = 'password';
        ico.className = 'fa-solid fa-eye-slash text-sm';
    }
}

function saveLineConfigP() {
    const fd = new FormData();
    fd.append('csrf_token', '<?= get_csrf_token() ?>');
    fd.append('action', 'save');
    fd.append('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', document.getElementById('line_token_p').value);
    fd.append('LINE_MESSAGING_CHANNEL_SECRET', document.getElementById('line_secret_p').value);

    fetch('ajax/ajax_test_line.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('saveStatusP');
            el.classList.remove('hidden');
            if (data.ok) {
                el.className = 'flex items-center gap-2 text-sm font-bold text-emerald-600';
                el.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + data.message;
            } else {
                el.className = 'flex items-center gap-2 text-sm font-bold text-red-500';
                el.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + data.error;
            }
            setTimeout(() => el.classList.add('hidden'), 4000);
        });
}

function sendTestLineP() {
    const userId = document.getElementById('toUserIdP').value.trim();
    const btn = document.getElementById('btnTestP');
    const result = document.getElementById('testResultP');

    if (!userId) { Swal.fire('Error', 'กรุณาระบุ User ID', 'error'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังส่ง...';
    result.classList.add('hidden');

    const fd = new FormData();
    fd.append('csrf_token', '<?= get_csrf_token() ?>');
    fd.append('action', 'test');
    fd.append('to_user_id', userId);
    fd.append('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN', document.getElementById('line_token_p').value);

    fetch('ajax/ajax_test_line.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            result.classList.remove('hidden');
            if (data.ok) {
                result.className = 'mt-4 p-4 rounded-xl text-xs font-semibold flex items-start gap-3 bg-emerald-50 border border-emerald-100 text-emerald-700';
                result.innerHTML = '<i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i><span>' + data.message + '</span>';
                Swal.fire('สำเร็จ!', data.message, 'success');
            } else {
                result.className = 'mt-4 p-4 rounded-xl text-xs font-semibold flex items-start gap-3 bg-red-50 border border-red-100 text-red-600';
                result.innerHTML = '<i class="fa-solid fa-circle-exclamation mt-0.5 shrink-0"></i><span>' + data.error + '</span>';
                Swal.fire('ล้มเหลว', data.error, 'error');
            }
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-flask"></i> ส่งข้อความทดสอบ';
        });
}
</script>
