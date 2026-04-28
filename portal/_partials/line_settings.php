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

    <!-- ════════════════════════════════════════════════════ -->
    <!-- สถิติการส่งข้อความ                                   -->
    <!-- ════════════════════════════════════════════════════ -->
    <div style="display:flex;align-items:center;gap:14px;margin:36px 0 24px">
        <div style="flex:1;height:1.5px;background:#f1f5f9"></div>
        <span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.18em;color:#94a3b8;white-space:nowrap">
            <i class="fa-solid fa-chart-bar" style="color:#2e9e63;margin-right:5px"></i>สถิติการส่งข้อความ
        </span>
        <div style="flex:1;height:1.5px;background:#f1f5f9"></div>
    </div>

    <!-- Date Picker + Refresh -->
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e5e7eb;border-radius:12px;padding:8px 14px;box-shadow:0 1px 4px rgba(0,0,0,.05)">
            <i class="fa-regular fa-calendar" style="color:#2e9e63;font-size:13px"></i>
            <label style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em">วันที่</label>
            <input type="date" id="ls-date"
                   style="font-size:13px;font-weight:700;color:#1e293b;border:none;outline:none;background:transparent;cursor:pointer"
                   max="<?= date('Y-m-d', strtotime('-1 day')) ?>"
                   value="<?= date('Y-m-d', strtotime('-1 day')) ?>">
        </div>
        <button id="ls-btn-load"
                style="display:flex;align-items:center;gap:6px;padding:9px 18px;border-radius:11px;font-size:13px;font-weight:800;color:#fff;border:none;cursor:pointer;background:linear-gradient(135deg,#2e9e63,#3bba7a);box-shadow:0 4px 12px rgba(46,158,99,.3)">
            <i class="fa-solid fa-rotate"></i> โหลดสถิติ
        </button>
        <div id="ls-status" style="display:none;font-size:12px;font-weight:800;padding:5px 12px;border-radius:20px"></div>
        <div id="ls-spinner" style="display:none"><i class="fa-solid fa-circle-notch fa-spin" style="color:#2e9e63"></i></div>
    </div>

    <!-- Error -->
    <div id="ls-error" style="display:none;background:#fff1f2;border:1.5px solid #fecdd3;border-radius:14px;padding:14px 18px;font-size:13px;color:#be123c;align-items:flex-start;gap:10px;margin-bottom:20px">
        <i class="fa-solid fa-triangle-exclamation" style="color:#f43f5e;flex-shrink:0"></i>
        <span id="ls-error-msg"></span>
    </div>

    <!-- Quota Cards -->
    <p style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;margin-bottom:12px">โควต้าข้อความ (เดือนนี้)</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:16px">
        <?php foreach ([
            ['id'=>'ls-q-limit','icon'=>'fa-envelope',    'color'=>'#2e9e63','bg'=>'#e8f8f0','label'=>'โควต้าต่อเดือน'],
            ['id'=>'ls-q-used', 'icon'=>'fa-paper-plane', 'color'=>'#2563eb','bg'=>'#eff6ff','label'=>'ส่งไปแล้ว'],
            ['id'=>'ls-q-left', 'icon'=>'fa-gauge',       'color'=>'#d97706','bg'=>'#fffbeb','label'=>'คงเหลือ'],
        ] as $c): ?>
        <div class="line-card" style="display:flex;align-items:center;gap:14px;margin-bottom:0;padding:16px">
            <div style="width:42px;height:42px;border-radius:13px;background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:16px">
                <i class="fa-solid <?= $c['icon'] ?>"></i>
            </div>
            <div>
                <div id="<?= $c['id'] ?>" style="font-size:22px;font-weight:900;color:#0f172a;line-height:1">—</div>
                <div style="font-size:11px;font-weight:600;color:#94a3b8;margin-top:3px"><?= $c['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Quota progress bar -->
    <div id="ls-quota-bar-wrap" style="display:none" class="line-card" style="padding:14px 18px;margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:#64748b;margin-bottom:8px">
            <span>การใช้งาน</span><span id="ls-quota-pct">0%</span>
        </div>
        <div style="background:#f1f5f9;border-radius:99px;height:10px;overflow:hidden">
            <div id="ls-quota-bar" style="height:10px;border-radius:99px;width:0%;background:linear-gradient(90deg,#2e9e63,#86efac);transition:width .7s"></div>
        </div>
    </div>

    <!-- Delivery Cards -->
    <p id="ls-delivery-label" style="font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;margin-bottom:12px">
        สถิติการส่งข้อความ — <?= date('d/m/Y', strtotime('-1 day')) ?>
    </p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:12px;margin-bottom:24px">
        <?php foreach ([
            ['key'=>'broadcast',        'icon'=>'fa-bullhorn',       'color'=>'#7c3aed','bg'=>'#f5f3ff','label'=>'Broadcast (OA)'],
            ['key'=>'targeting',        'icon'=>'fa-crosshairs',     'color'=>'#0891b2','bg'=>'#ecfeff','label'=>'Targeting (OA)'],
            ['key'=>'apiBroadcast',     'icon'=>'fa-satellite-dish', 'color'=>'#be185d','bg'=>'#fdf2f8','label'=>'API Broadcast'],
            ['key'=>'apiPush',          'icon'=>'fa-bell',           'color'=>'#2563eb','bg'=>'#eff6ff','label'=>'API Push'],
            ['key'=>'apiMulticast',     'icon'=>'fa-users',          'color'=>'#059669','bg'=>'#ecfdf5','label'=>'API Multicast'],
            ['key'=>'apiNarrowcast',    'icon'=>'fa-filter',         'color'=>'#d97706','bg'=>'#fffbeb','label'=>'API Narrowcast'],
            ['key'=>'apiReply',         'icon'=>'fa-reply',          'color'=>'#16a34a','bg'=>'#f0fdf4','label'=>'API Reply'],
            ['key'=>'pnpNoticeMessage', 'icon'=>'fa-mobile-screen',  'color'=>'#6b7280','bg'=>'#f9fafb','label'=>'PNP Notice'],
        ] as $d): ?>
        <div data-ls-key="<?= $d['key'] ?>" class="line-card" style="margin-bottom:0;padding:14px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                <div style="width:30px;height:30px;border-radius:10px;background:<?= $d['bg'] ?>;color:<?= $d['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:12px">
                    <i class="fa-solid <?= $d['icon'] ?>"></i>
                </div>
                <span style="font-size:11px;font-weight:700;color:#64748b;line-height:1.3"><?= $d['label'] ?></span>
            </div>
            <div class="ls-dval" style="font-size:22px;font-weight:900;color:#0f172a">—</div>
            <div style="font-size:10px;font-weight:600;color:#94a3b8;margin-top:2px">ข้อความ</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
        <div class="line-card" style="margin-bottom:0">
            <p style="font-size:13px;font-weight:900;color:#374151;margin-bottom:16px">ปริมาณข้อความตามประเภท</p>
            <div style="position:relative;height:240px"><canvas id="ls-bar-chart"></canvas></div>
        </div>
        <div class="line-card" style="margin-bottom:0;display:flex;flex-direction:column">
            <p style="font-size:13px;font-weight:900;color:#374151;margin-bottom:16px">อัตราใช้โควต้า</p>
            <div style="flex:1;display:flex;align-items:center;justify-content:center;max-height:240px">
                <canvas id="ls-donut-chart"></canvas>
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

    fetch('ajax_test_line.php', { method: 'POST', body: fd })
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

    fetch('ajax_test_line.php', { method: 'POST', body: fd })
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

// ── LINE Stats ───────────────────────────────────────────────────────────────
(function () {
    'use strict';

    var AJAX   = 'ajax_line_stats.php';
    var barChart = null, donutChart = null;
    var KEYS   = ['broadcast','targeting','apiBroadcast','apiPush','apiMulticast','apiNarrowcast','apiReply','pnpNoticeMessage'];
    var LABELS = ['Broadcast (OA)','Targeting (OA)','API Broadcast','API Push','API Multicast','API Narrowcast','API Reply','PNP Notice'];
    var COLORS = ['#7c3aed','#0891b2','#be185d','#2563eb','#059669','#d97706','#16a34a','#6b7280'];

    function fmt(n) { return (n == null || n === '') ? '—' : Number(n).toLocaleString('th-TH'); }

    function spin(on) {
        document.getElementById('ls-spinner').style.display = on ? 'inline' : 'none';
        document.getElementById('ls-btn-load').disabled = on;
    }

    function setStatus(text, type) {
        var el = document.getElementById('ls-status');
        el.textContent = text;
        el.style.display = 'inline-block';
        el.style.background = type==='ready' ? '#dcfce7' : type==='unready' ? '#fef9c3' : type==='err' ? '#fee2e2' : '#f1f5f9';
        el.style.color      = type==='ready' ? '#15803d' : type==='unready' ? '#a16207' : type==='err' ? '#be123c' : '#64748b';
    }

    function showError(msg) {
        var el = document.getElementById('ls-error');
        document.getElementById('ls-error-msg').textContent = msg;
        el.style.display = 'flex';
    }
    function hideError() { document.getElementById('ls-error').style.display = 'none'; }

    function buildDonut(used, left, limit) {
        if (typeof Chart === 'undefined') return;
        var ctx = document.getElementById('ls-donut-chart').getContext('2d');
        if (donutChart) donutChart.destroy();
        var unlimited = (limit === null);
        donutChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: unlimited ? ['ส่งแล้ว (ไม่จำกัด)'] : ['ส่งแล้ว','คงเหลือ'],
                datasets: [{ data: unlimited ? [used||1] : [used, Math.max(0,left)],
                    backgroundColor: unlimited ? ['#2e9e63'] : ['#2e9e63','#e5e7eb'],
                    borderWidth: 0, hoverOffset: 6 }]
            },
            options: { cutout:'72%', plugins: {
                legend: { position:'bottom', labels:{ font:{size:11,weight:'bold'}, padding:12 } },
                tooltip: { callbacks: { label: function(c){ return ' '+c.label+': '+Number(c.raw).toLocaleString('th-TH'); } } }
            }}
        });
    }

    function buildBar(d) {
        if (typeof Chart === 'undefined') return;
        var ctx = document.getElementById('ls-bar-chart').getContext('2d');
        if (barChart) barChart.destroy();
        barChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: LABELS,
                datasets: [{ label:'ข้อความ', data: KEYS.map(function(k){ return Number(d[k]||0); }),
                    backgroundColor: COLORS.map(function(c){ return c+'cc'; }),
                    borderColor: COLORS, borderWidth:1.5, borderRadius:5, borderSkipped:false }]
            },
            options: {
                indexAxis:'y', responsive:true, maintainAspectRatio:false,
                plugins: { legend:{display:false}, tooltip:{ callbacks:{ label:function(c){ return ' '+Number(c.raw).toLocaleString('th-TH')+' ข้อความ'; } } } },
                scales: {
                    x: { beginAtZero:true, ticks:{ font:{size:10}, callback:function(v){ return Number(v).toLocaleString('th-TH'); } }, grid:{color:'#f0f0f0'} },
                    y: { ticks:{ font:{size:10,weight:'bold'} }, grid:{display:false} }
                }
            }
        });
    }

    function loadStats() {
        hideError();
        spin(true);
        var dateVal   = document.getElementById('ls-date').value;
        var dateParam = dateVal.replace(/-/g,'');
        var parts     = dateVal.split('-');
        document.getElementById('ls-delivery-label').textContent =
            'สถิติการส่งข้อความ — '+parts[2]+'/'+parts[1]+'/'+parts[0];

        Promise.all([
            fetch(AJAX+'?action=quota').then(function(r){ return r.json(); }),
            fetch(AJAX+'?action=delivery&date='+encodeURIComponent(dateParam)).then(function(r){ return r.json(); })
        ]).then(function(res) {
            // Quota
            var qRes = res[0];
            if (qRes.status === 'ok') {
                var q    = qRes.quota||{}, c = qRes.consumption||{};
                var used = Number(c.totalUsage||0);
                var limit = q.type==='limited' ? Number(q.value||0) : null;
                var left  = limit !== null ? Math.max(0,limit-used) : null;
                document.getElementById('ls-q-limit').textContent = limit !== null ? fmt(limit) : 'ไม่จำกัด';
                document.getElementById('ls-q-used').textContent  = fmt(used);
                document.getElementById('ls-q-left').textContent  = left !== null ? fmt(left) : '∞';
                if (limit !== null && limit > 0) {
                    var pct = Math.round((used/limit)*100);
                    var bw = document.getElementById('ls-quota-bar-wrap');
                    bw.style.display = 'block';
                    document.getElementById('ls-quota-bar').style.width = pct+'%';
                    document.getElementById('ls-quota-pct').textContent = pct+'%';
                    var bar = document.getElementById('ls-quota-bar');
                    bar.style.background = pct>=90 ? 'linear-gradient(90deg,#ef4444,#fca5a5)'
                                         : pct>=70 ? 'linear-gradient(90deg,#d97706,#fcd34d)'
                                         : 'linear-gradient(90deg,#2e9e63,#86efac)';
                }
                buildDonut(used, left, limit);
            }
            // Delivery
            var dRes = res[1];
            if (dRes.status === 'ok') {
                var d = dRes.data||{};
                if      (d.status==='ready')           setStatus('ข้อมูลพร้อม','ready');
                else if (d.status==='unready')         setStatus('ข้อมูลยังไม่พร้อม','unready');
                else if (d.status==='out_of_service')  setStatus('ไม่มีข้อมูลสำหรับวันนี้','err');
                else if (d._error)                     { setStatus('เกิดข้อผิดพลาด','err'); showError(d._error); }
                KEYS.forEach(function(key) {
                    var card = document.querySelector('[data-ls-key="'+key+'"]');
                    if (card) card.querySelector('.ls-dval').textContent = d[key]!=null ? fmt(d[key]) : '—';
                });
                buildBar(d);
            } else {
                showError('โหลดข้อมูล delivery ไม่สำเร็จ');
            }
        }).catch(function(){ showError('ไม่สามารถเชื่อมต่อ API ได้'); })
          .finally(function(){ spin(false); });
    }

    document.getElementById('ls-btn-load').addEventListener('click', loadStats);
    document.getElementById('ls-date').addEventListener('change', loadStats);

    // โหลดอัตโนมัติเมื่อ Chart.js พร้อม
    if (document.readyState === 'complete') {
        loadStats();
    } else {
        window.addEventListener('load', loadStats);
    }
})();
</script>
