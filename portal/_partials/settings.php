<!-- ════════════ SECTION: SETTINGS (renovated — tabbed layout) ════════════ -->
<?php
$_mFile = __DIR__ . '/../../config/maintenance.json';
$_mData = file_exists($_mFile) ? json_decode(file_get_contents($_mFile), true) : [];
$announcementActive = (bool)($_mData['announcement_active'] ?? false);
$announcementMsg    = $_mData['announcement_message'] ?? '';
$whitelistArr       = $_mData['whitelist'] ?? [];
$whitelistText      = implode("\n", $whitelistArr);
?>

<style>
    /* Settings tab pills */
    .stg-tab {
        flex: 1; padding: 10px 14px; border-radius: 12px;
        font-size: 12px; font-weight: 800; letter-spacing: .02em;
        color: #64748b; background: transparent; border: 0; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        transition: all .18s;
        font-family: inherit;
    }
    .stg-tab i { font-size: 11px; }
    .stg-tab:hover  { color: #334155; background: #f1f5f9; }
    .stg-tab.active { color: #fff; background: #2e9e63; box-shadow: 0 6px 18px rgba(46,158,99,.22); }
    .stg-tab.active:hover { background: #1f7a4d; }
    .stg-pane[hidden] { display: none !important; }

    /* Section card consistency */
    .stg-card { background:#fff; border:1px solid #e5e7eb; border-radius:24px; box-shadow:0 1px 2px rgba(0,0,0,.02); }
    .stg-card-head { padding:16px 24px; background:#f8fafc; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
    .stg-card-head h3 { font-size:13px; font-weight:900; color:#334155; text-transform:uppercase; letter-spacing:.08em; margin:0; }
    .stg-card-body { padding:24px; }

    /* Brand-green save buttons */
    .stg-btn-primary {
        background:#2e9e63; color:#fff; border:0; cursor:pointer;
        padding:10px 22px; border-radius:12px; font-size:12px; font-weight:900;
        display:inline-flex; align-items:center; gap:8px;
        box-shadow: 0 8px 20px rgba(46,158,99,.22);
        transition: all .18s; font-family: inherit;
    }
    .stg-btn-primary:hover { background:#1f7a4d; }
    .stg-btn-primary:active { transform: scale(.97); }
    .stg-btn-ghost {
        background:#f1f5f9; color:#475569; border:0; cursor:pointer;
        padding:10px 18px; border-radius:12px; font-size:12px; font-weight:900;
        display:inline-flex; align-items:center; gap:8px;
        transition: all .18s; font-family: inherit;
    }
    .stg-btn-ghost:hover { background:#e2e8f0; color:#0f172a; }
</style>

<div class="max-w-[1100px] mx-auto px-4 py-6">

    <!-- Header (compact, no duplicate actions) -->
    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-white rounded-xl shadow-sm border border-gray-200 flex items-center justify-center text-emerald-600 text-xl">
            <i class="fa-solid fa-gears"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">Settings & Maintenance</h2>
            <p class="text-slate-500 text-sm font-medium">จัดการตั้งค่าและตรวจสอบสถานะระบบทั้งหมด</p>
        </div>
        <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest border"
             style="<?= $allOnline ? 'background:#f0fdf4;border-color:#bbf7d0;color:#15803d' : 'background:#fffbeb;border-color:#fde68a;color:#b45309' ?>">
            <span class="w-1.5 h-1.5 rounded-full <?= $allOnline ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span>
            <?= $allOnline ? 'All Systems Online' : 'Maintenance Active' ?>
        </div>
    </div>

    <!-- Tabs (sticky-ish near top of pane) -->
    <div class="bg-white border border-gray-200 rounded-2xl p-1.5 flex gap-1 mb-6 shadow-sm">
        <button type="button" class="stg-tab" data-tab="system">
            <i class="fa-solid fa-server"></i>System
        </button>
        <button type="button" class="stg-tab" data-tab="config">
            <i class="fa-solid fa-sliders"></i>Configuration
        </button>
        <button type="button" class="stg-tab" data-tab="integrations">
            <i class="fa-solid fa-plug-circle-bolt"></i>Integrations
        </button>
        <button type="button" class="stg-tab" data-tab="logs">
            <i class="fa-solid fa-clock-rotate-left"></i>Logs &amp; History
        </button>
    </div>

    <!-- ═════════ TAB: System ═════════ -->
    <div class="stg-pane space-y-6" data-pane="system">

        <!-- Status banner + Git Pull (the single one) -->
        <div class="rounded-2xl border p-5 flex items-center gap-5"
             style="<?= $allOnline ? 'background:#f0fdf4; border-color:#bbf7d0;' : 'background:#fffbeb; border-color:#fef3c7;' ?>">
            <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 text-xl"
                 style="<?= $allOnline ? 'background:#dcfce7; color:#16a34a;' : 'background:#fef3c7; color:#d97706;' ?>">
                <i class="fa-solid <?= $allOnline ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="font-black text-slate-800 text-base">
                    <?= $allOnline ? 'ทุกระบบพร้อมใช้งาน' : 'บางระบบปิดปรับปรุง' ?>
                </div>
                <p class="text-slate-600 text-xs mt-0.5 font-medium">
                    <?= $allOnline ? 'ผู้ใช้ทุกคนสามารถเข้าใช้งานได้ตามปกติ' : 'คุณสามารถเปิดระบบที่ปิดอยู่ได้จากรายการด้านล่าง' ?>
                </p>
            </div>
            <button onclick="triggerGitPull()" class="stg-btn-ghost whitespace-nowrap">
                <i class="fa-solid fa-rotate"></i> Git Pull Update
            </button>
        </div>

        <!-- Service toggles -->
        <div class="stg-card">
            <div class="stg-card-head">
                <h3>Services &amp; Maintenance</h3>
                <span class="text-[10px] font-bold text-slate-400 bg-white px-2 py-1 rounded-lg border border-gray-100">REAL-TIME</span>
            </div>
            <div class="stg-card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($mProjects as $p):
                        $isActive = $mData[$p['key']] ?? true;
                    ?>
                        <div class="border border-gray-100 rounded-2xl p-4 flex items-center gap-4 hover:bg-slate-50 transition-all" id="card-<?= $p['key'] ?>">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-lg shadow-sm"
                                 style="background:<?= $p['icon_bg'] ?>; color:<?= $p['icon_color'] ?>;">
                                <i class="fa-solid <?= $p['icon'] ?>"></i>
                            </div>
                            <div class="flex-1">
                                <div class="font-black text-slate-800 text-sm"><?= htmlspecialchars($p['title']) ?></div>
                                <div class="status-badge <?= $isActive ? 'on' : 'off' ?> mt-1" id="badge-<?= $p['key'] ?>">
                                    <span class="status-dot"></span>
                                    <span class="text-[9px]"><?= $isActive ? 'ONLINE' : 'MAINTENANCE' ?></span>
                                </div>
                            </div>
                            <div class="toggle-wrap">
                                <label class="toggle">
                                    <input type="checkbox" data-project="<?= $p['key'] ?>" <?= $isActive ? 'checked' : '' ?> onchange="toggleMaintenance(this)">
                                    <div class="toggle-track"></div>
                                    <div class="toggle-thumb"></div>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Announcement + Whitelist (consolidated under System) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Announcement -->
            <div class="stg-card">
                <div class="stg-card-head">
                    <h3><i class="fa-solid fa-bullhorn text-amber-500 mr-1.5"></i>ประกาศปิดปรับปรุงระบบ</h3>
                </div>
                <div class="stg-card-body space-y-4">
                    <p class="text-xs text-slate-500 font-medium -mt-2">แสดงแถบแจ้งเตือนแผนการปิดระบบให้ผู้ใช้งานทราบล่วงหน้า</p>

                    <div class="flex p-1 bg-slate-50 rounded-2xl border border-slate-100 w-fit min-w-[200px]">
                        <button type="button" onclick="setAnnStatus(0)" id="btn-ann-off"
                                class="ann-status-btn flex-1 px-6 py-2 rounded-xl text-xs font-black transition-all <?= !$announcementActive ? 'bg-white shadow-sm text-slate-600 border border-slate-200' : 'text-slate-400 hover:text-slate-600' ?>">
                            ปิดประกาศ
                        </button>
                        <button type="button" onclick="setAnnStatus(1)" id="btn-ann-on"
                                class="ann-status-btn flex-1 px-6 py-2 rounded-xl text-xs font-black transition-all <?= $announcementActive ? 'bg-white shadow-sm text-amber-600 border border-amber-100' : 'text-slate-400 hover:text-slate-600' ?>">
                            เปิดประกาศ
                        </button>
                        <input type="hidden" id="announcement-toggle-val" value="<?= $announcementActive ? '1' : '0' ?>">
                    </div>

                    <textarea id="announcement-message" rows="3"
                              placeholder="เช่น: ขออภัยในความไม่สะดวก จะทำการปิดปรับปรุงระบบในวันที่ 24 เม.ย. เวลา 23:00 - 05:00 น."
                              class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-bold text-slate-800 outline-none focus:ring-2 focus:ring-amber-100 focus:border-amber-400 transition-all"><?= htmlspecialchars($announcementMsg) ?></textarea>

                    <div class="flex justify-end">
                        <button onclick="saveAnnouncement()" class="stg-btn-primary">
                            <i class="fa-solid fa-save"></i> บันทึกประกาศ
                        </button>
                    </div>
                </div>
            </div>

            <!-- Whitelist -->
            <div class="stg-card">
                <div class="stg-card-head">
                    <h3><i class="fa-solid fa-user-shield text-blue-500 mr-1.5"></i>Maintenance Whitelist</h3>
                </div>
                <div class="stg-card-body space-y-4">
                    <p class="text-xs text-slate-500 font-medium -mt-2">LINE User ID ของผู้ที่อนุญาตให้เข้าใช้งานได้ขณะปิดปรับปรุง (1 รายการต่อบรรทัด)</p>

                    <textarea id="maintenance-whitelist" rows="6"
                              placeholder="Ua1234567890abcdef..."
                              class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-mono font-bold text-slate-800 outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-400 transition-all"><?= htmlspecialchars($whitelistText) ?></textarea>

                    <div class="flex justify-end">
                        <button onclick="saveWhitelist()" class="stg-btn-primary">
                            <i class="fa-solid fa-check-double"></i> อัปเดต Whitelist
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═════════ TAB: Configuration ═════════ -->
    <div class="stg-pane space-y-6" data-pane="config" hidden>
        <div class="stg-card">
            <div class="stg-card-head">
                <h3>App Configuration</h3>
                <i class="fa-solid fa-palette text-slate-300"></i>
            </div>
            <div class="stg-card-body">
                <form id="siteSettingsForm" method="POST" action="ajax_site_settings.php" enctype="multipart/form-data" class="space-y-6">
                    <?php csrf_field(); ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase tracking-wider mb-2">Site Name</label>
                            <input type="text" name="site_name" value="<?= htmlspecialchars(SITE_NAME) ?>"
                                   class="w-full px-4 py-3 bg-slate-50 border border-gray-200 rounded-xl focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 transition-all text-sm font-bold text-slate-800 outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-black text-slate-500 uppercase tracking-wider mb-2">System Logo</label>
                            <div class="flex items-center gap-4">
                                <?php if (defined('SITE_LOGO') && SITE_LOGO !== ''): ?>
                                    <div class="w-12 h-12 border border-gray-200 rounded-xl p-1 bg-white">
                                        <img src="../<?= htmlspecialchars(SITE_LOGO) ?>" class="w-full h-full object-contain">
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="site_logo" class="text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100">
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-gray-50">
                        <label class="block text-xs font-black text-slate-500 uppercase tracking-wider mb-2">Gemini AI API Key</label>
                        <div class="relative max-w-md">
                            <input type="password" id="gemini_api_key_v3" name="gemini_api_key" value="<?= htmlspecialchars(GEMINI_API_KEY) ?>"
                                   class="w-full px-4 py-3 bg-slate-50 border border-gray-200 rounded-xl font-mono text-xs font-bold text-slate-800 pr-12 outline-none">
                            <button type="button" onclick="const p = document.getElementById('gemini_api_key_v3'); p.type = p.type==='password'?'text':'password';"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="submit" class="stg-btn-primary">
                            <i class="fa-solid fa-save"></i> บันทึกการตั้งค่า
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═════════ TAB: Integrations ═════════ -->
    <div class="stg-pane space-y-6" data-pane="integrations" hidden>
        <div class="stg-card">
            <div class="stg-card-head">
                <h3>Integration Hub</h3>
            </div>
            <div class="stg-card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <a href="javascript:switchSection('smtp_settings')" class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl hover:bg-blue-50 hover:border-blue-200 border border-transparent transition-all group">
                        <div class="flex items-center gap-4">
                            <i class="fa-solid fa-at text-slate-400 group-hover:text-blue-500"></i>
                            <span class="text-xs font-bold text-slate-700">SMTP Settings</span>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                    </a>
                    <a href="javascript:switchSection('line_settings')" class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl hover:bg-green-50 hover:border-green-200 border border-transparent transition-all group">
                        <div class="flex items-center gap-4">
                            <i class="fa-brands fa-line text-slate-400 group-hover:text-green-500"></i>
                            <span class="text-xs font-bold text-slate-700">LINE Messaging API</span>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                    </a>
                    <a href="javascript:switchSection('clinic_data')" class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl hover:bg-teal-50 hover:border-teal-200 border border-transparent transition-all group">
                        <div class="flex items-center gap-4">
                            <i class="fa-solid fa-hospital text-slate-400 group-hover:text-teal-500"></i>
                            <span class="text-xs font-bold text-slate-700">Clinic Profile</span>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                    </a>
                    <div class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border border-transparent">
                        <div class="flex items-center gap-4">
                            <i class="fa-solid fa-server text-slate-400"></i>
                            <span class="text-xs font-bold text-slate-700">PHP Version</span>
                        </div>
                        <span class="text-xs font-mono font-black text-slate-400"><?= phpversion() ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═════════ TAB: Logs & History ═════════ -->
    <div class="stg-pane space-y-6" data-pane="logs" hidden>
        <div class="stg-card">
            <div class="stg-card-head">
                <h3>Diagnostic Logs</h3>
            </div>
            <div class="stg-card-body">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <a href="javascript:switchSection('error_logs')" class="flex items-center gap-4 p-4 bg-slate-50 border border-transparent rounded-2xl hover:bg-red-50 hover:border-red-100 transition-all">
                        <div class="w-9 h-9 rounded-lg bg-red-100 text-red-600 flex items-center justify-center"><i class="fa-solid fa-bug"></i></div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-black text-slate-800">Error Logs</div>
                            <div class="text-[10px] font-bold text-slate-400">PHP / Throwable</div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                    </a>
                    <a href="javascript:switchSection('activity_logs')" class="flex items-center gap-4 p-4 bg-slate-50 border border-transparent rounded-2xl hover:bg-emerald-50 hover:border-emerald-100 transition-all">
                        <div class="w-9 h-9 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center"><i class="fa-solid fa-bolt"></i></div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-black text-slate-800">Activity Logs</div>
                            <div class="text-[10px] font-bold text-slate-400">Admin / User actions</div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                    </a>
                    <a href="javascript:switchSection('email_logs')" class="flex items-center gap-4 p-4 bg-slate-50 border border-transparent rounded-2xl hover:bg-blue-50 hover:border-blue-100 transition-all">
                        <div class="w-9 h-9 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center"><i class="fa-solid fa-envelope"></i></div>
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-black text-slate-800">Email Logs</div>
                            <div class="text-[10px] font-bold text-slate-400">Outbound mail history</div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-[10px] text-slate-300"></i>
                    </a>
                </div>
            </div>
        </div>

        <div class="stg-card">
            <div class="stg-card-head">
                <h3>Git Update History</h3>
                <i class="fa-solid fa-clock-rotate-left text-slate-300"></i>
            </div>
            <div class="max-h-[420px] overflow-y-auto divide-y divide-gray-50">
                <?php if (empty($gitPullLogs)): ?>
                    <div class="py-12 text-center text-slate-400 text-xs font-bold">ไม่พบประวัติการอัปเดต</div>
                <?php else: ?>
                    <?php foreach ($gitPullLogs as $log):
                        $isOk = $log['status'] === 'success';
                        $dt = new DateTime($log['created_at']);
                    ?>
                        <div class="px-6 py-3 flex items-center justify-between hover:bg-slate-50 transition-all">
                            <div class="flex items-center gap-4 min-w-0">
                                <div class="w-2 h-2 rounded-full shrink-0 <?= $isOk ? 'bg-emerald-500' : 'bg-rose-500' ?>"></div>
                                <div class="min-w-0">
                                    <div class="text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($log['message'] ?? 'Git Pull') ?></div>
                                    <div class="text-[10px] text-slate-400 font-medium"><?= htmlspecialchars($log['triggered_by']) ?> • <?= $dt->format('d M Y H:i') ?></div>
                                </div>
                            </div>
                            <?php if ($log['detail']): ?>
                                <button onclick="Swal.fire({title:'Update Detail', html:<?= htmlspecialchars(json_encode('<pre style="text-align:left;font-size:11px;background:#f8fafc;padding:15px;border-radius:10px;font-family:monospace;overflow:auto;max-height:400px">' . htmlspecialchars($log['detail']) . '</pre>')) ?>})"
                                        class="text-[9px] font-black text-emerald-600 uppercase tracking-widest hover:underline shrink-0">Details</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
    // ── Tab switcher ───────────────────────────────────────────────────
    (function () {
        const VALID = ['system', 'config', 'integrations', 'logs'];

        function switchSettingsTab(name) {
            if (!VALID.includes(name)) name = 'system';
            document.querySelectorAll('.stg-pane').forEach(p => p.hidden = (p.dataset.pane !== name));
            document.querySelectorAll('.stg-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));

            // Update URL without reload
            const params = new URLSearchParams(location.search);
            params.set('section', 'settings');
            params.set('tab', name);
            history.replaceState(null, '', `${location.pathname}?${params}`);
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.stg-tab').forEach(btn => {
                btn.addEventListener('click', () => switchSettingsTab(btn.dataset.tab));
            });
            const initial = new URLSearchParams(location.search).get('tab') || 'system';
            switchSettingsTab(initial);
        });

        // Expose for external deep-linking
        window.switchSettingsTab = switchSettingsTab;
    })();

    // ── Announcement / Whitelist (kept from original) ─────────────────
    window.setAnnStatus = function (val) {
        document.getElementById('announcement-toggle-val').value = val;
        const btnOff = document.getElementById('btn-ann-off');
        const btnOn  = document.getElementById('btn-ann-on');
        [btnOff, btnOn].forEach(btn => {
            btn.classList.remove('bg-white', 'shadow-sm', 'text-amber-600', 'border-amber-100', 'text-slate-600', 'border-slate-200');
            btn.classList.add('text-slate-400', 'hover:text-slate-600');
        });
        if (val === 1) {
            btnOn.classList.add('bg-white', 'shadow-sm', 'text-amber-600', 'border', 'border-amber-100');
            btnOn.classList.remove('text-slate-400', 'hover:text-slate-600');
        } else {
            btnOff.classList.add('bg-white', 'shadow-sm', 'text-slate-600', 'border', 'border-slate-200');
            btnOff.classList.remove('text-slate-400', 'hover:text-slate-600');
        }
    };

    async function saveAnnouncement() {
        const message = document.getElementById('announcement-message').value;
        const active  = document.getElementById('announcement-toggle-val').value === '1';
        const fd = new FormData();
        fd.append('action', 'set_announcement');
        fd.append('message', message);
        fd.append('active', active ? '1' : '0');
        fd.append('csrf_token', portal_CSRF);
        try {
            const res = await fetch('ajax_maintenance.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) showPortalToast(data.message, 'success');
            else Swal.fire('Error', data.message || 'บันทึกไม่สำเร็จ', 'error');
        } catch (e) {
            Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
        }
    }

    async function saveWhitelist() {
        const ids = document.getElementById('maintenance-whitelist').value;
        const fd = new FormData();
        fd.append('action', 'set_whitelist');
        fd.append('ids', ids);
        fd.append('csrf_token', portal_CSRF);
        try {
            const res = await fetch('ajax_maintenance.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) showPortalToast(data.message, 'success');
            else Swal.fire('Error', data.message || 'บันทึกไม่สำเร็จ', 'error');
        } catch (e) {
            Swal.fire('Error', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
        }
    }
</script>
