<?php
/**
 * portal/_partials/morning_brief_settings.php — Morning Brief preferences.
 *
 * เปิดผ่าน ?section=morning_brief_settings — ผู้ใช้แต่ละคนตั้งค่าของตัวเอง:
 *   · ช่องทาง: Portal / LINE / Email (เลือกได้หลายอัน)
 *   · เวลาที่อยากให้ส่ง (0-23)
 *   · โมดูลที่ต้องการให้ครอบคลุม
 *   · LINE User ID + Email (ถ้าเปิดช่องทางนั้น)
 *
 * Layout ผ่าน $layout. AJAX endpoint ที่ portal/ajax_morning_brief.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../queries/morning_brief_queries.php';

$pdo = db();
ensure_morning_brief_schema($pdo);
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$pref = morning_brief_get_or_create_pref($pdo, $adminId, 'admin');
$modules = json_decode($pref['modules_json'] ?? '[]', true) ?: ['scholarship','finance','edms','clinic','inventory'];

$MODULE_LABELS = [
    'scholarship' => ['นักศึกษาทุน', 'รออนุมัติ · กะวันนี้ · ค่าตอบแทนค้าง', 'fa-graduation-cap'],
    'finance'     => ['การเงิน',     'ยอดเข้า-ออกเมื่อวาน · รายการประจำเดือน', 'fa-money-check-dollar'],
    'edms'        => ['สารบรรณ',     'งานครบกำหนด · SLA เกินเวลา', 'fa-file-lines'],
    'clinic'      => ['คลินิก',       'สถานะเปิด-ปิด · จำนวนนัดหมาย · เวรพยาบาล', 'fa-hospital'],
    'inventory'   => ['คลังพัสดุ',     'วัสดุใกล้หมด · ของใกล้หมดอายุ', 'fa-boxes-stacked'],
];
?>
<div id="section-morning-brief-settings" class="portal-section">
    <div class="max-w-4xl mx-auto px-5 md:px-8 py-8 space-y-6">

        <div class="flex items-center gap-3">
            <span class="inline-flex w-11 h-11 rounded-xl items-center justify-center" style="background:#fef3c7;color:#d97706">
                <i class="fa-solid fa-sun text-lg"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-slate-900">ตั้งค่า Morning Brief</h1>
                <p class="text-sm text-slate-500 mt-1">เลือกช่องทางและเนื้อหาที่อยากเห็นในสรุปประจำเช้า — ตั้งครั้งเดียว ใช้ทุกวัน</p>
            </div>
        </div>

        <form id="mbs-form" class="space-y-6">

            <!-- Channels card -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-base font-bold text-slate-900 mb-4">ช่องทางรับ brief</h2>
                <div class="space-y-3">
                    <label class="mbs-channel">
                        <input type="checkbox" name="channel_portal" value="1" <?= $pref['channel_portal'] ? 'checked' : '' ?>>
                        <span class="mbs-channel-icon" style="background:#dcfce7;color:#166534"><i class="fa-solid fa-display"></i></span>
                        <div>
                            <p class="font-semibold text-slate-900">Portal widget</p>
                            <p class="text-xs text-slate-500">เปิดหน้า Dashboard เห็นทันที — ไม่ต้องรอ notification</p>
                        </div>
                    </label>
                    <label class="mbs-channel">
                        <input type="checkbox" name="channel_line" value="1" <?= $pref['channel_line'] ? 'checked' : '' ?>>
                        <span class="mbs-channel-icon" style="background:#dcfce7;color:#06c755"><i class="fa-brands fa-line"></i></span>
                        <div class="flex-1">
                            <p class="font-semibold text-slate-900">LINE Official Account</p>
                            <p class="text-xs text-slate-500">ส่งเข้า LINE ทุกเช้าตามเวลาที่ตั้ง</p>
                            <input type="text" name="line_user_id" placeholder="LINE User ID (Uxxxxxxxxxx)"
                                   value="<?= htmlspecialchars($pref['line_user_id'] ?? '') ?>"
                                   class="mt-2 w-full max-w-md px-3 py-1.5 rounded-lg border border-slate-200 text-sm" />
                        </div>
                    </label>
                    <label class="mbs-channel">
                        <input type="checkbox" name="channel_email" value="1" <?= $pref['channel_email'] ? 'checked' : '' ?>>
                        <span class="mbs-channel-icon" style="background:#e0e7ff;color:#3730a3"><i class="fa-solid fa-envelope"></i></span>
                        <div class="flex-1">
                            <p class="font-semibold text-slate-900">Email</p>
                            <p class="text-xs text-slate-500">ส่งเข้าอีเมล สำหรับวันที่ LINE ใช้ไม่ได้</p>
                            <input type="email" name="email" placeholder="you@example.com"
                                   value="<?= htmlspecialchars($pref['email'] ?? '') ?>"
                                   class="mt-2 w-full max-w-md px-3 py-1.5 rounded-lg border border-slate-200 text-sm" />
                        </div>
                    </label>
                </div>
            </div>

            <!-- Delivery time card -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-base font-bold text-slate-900 mb-4">เวลาส่ง</h2>
                <div class="flex items-center gap-3">
                    <label class="text-sm text-slate-600">ส่งทุกวันเวลา</label>
                    <select name="delivery_hour" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm">
                        <?php for ($h = 5; $h <= 22; $h++): ?>
                            <option value="<?= $h ?>" <?= (int)$pref['delivery_hour'] === $h ? 'selected' : '' ?>>
                                <?= sprintf('%02d:00', $h) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <span class="text-xs text-slate-500">(เฉพาะช่องทาง LINE / Email — Portal เห็นทันทีเมื่อเปิดหน้า)</span>
                </div>
            </div>

            <!-- Modules card -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-base font-bold text-slate-900 mb-1">เนื้อหา brief</h2>
                <p class="text-xs text-slate-500 mb-4">เลือกโมดูลที่อยากให้ครอบคลุม — ติ๊กออกถ้าไม่อยากเห็น</p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                    <?php foreach ($MODULE_LABELS as $key => [$label, $hint, $icon]): ?>
                        <label class="mbs-module">
                            <input type="checkbox" name="modules[]" value="<?= $key ?>" <?= in_array($key, $modules, true) ? 'checked' : '' ?>>
                            <span class="mbs-module-icon"><i class="fa-solid <?= $icon ?>"></i></span>
                            <div>
                                <p class="font-semibold text-slate-900 text-sm"><?= htmlspecialchars($label) ?></p>
                                <p class="text-xs text-slate-500"><?= htmlspecialchars($hint) ?></p>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-2">
                    <button type="button" onclick="mbsPreview()" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 text-sm font-medium">
                        <i class="fa-solid fa-eye"></i>พรีวิว
                    </button>
                    <button type="button" onclick="mbsTestSend()" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 text-sm font-medium">
                        <i class="fa-solid fa-paper-plane"></i>ทดสอบส่ง
                    </button>
                </div>
                <div class="flex items-center gap-3">
                    <a href="?section=dashboard" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-900">ยกเลิก</a>
                    <button type="submit" class="px-5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">
                        บันทึก
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ─── Preview Modal ─── -->
<div id="mbs-preview-modal" class="hidden">
    <div class="mbs-modal-backdrop" onclick="mbsClosePreview()"></div>
    <div class="mbs-modal-box">
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-200">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-eye text-slate-500"></i>
                <h3 class="font-bold text-slate-900">พรีวิว Morning Brief</h3>
                <span class="text-xs text-slate-500" id="mbs-pv-date"></span>
            </div>
            <button onclick="mbsClosePreview()" class="text-slate-400 hover:text-slate-700 w-8 h-8 rounded-lg hover:bg-slate-100"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="px-5 py-3 border-b border-slate-100">
            <div class="inline-flex rounded-lg bg-slate-100 p-1">
                <button class="mbs-pv-tab active" data-pv="line"><i class="fa-brands fa-line mr-1.5"></i>LINE</button>
                <button class="mbs-pv-tab" data-pv="email"><i class="fa-solid fa-envelope mr-1.5"></i>Email</button>
            </div>
        </div>
        <div class="mbs-pv-content overflow-y-auto" id="mbs-pv-body">
            <p class="text-center text-slate-400 py-12"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>
        </div>
    </div>
</div>

<style>
#mbs-preview-modal.show { display:block; }
#mbs-preview-modal .mbs-modal-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.55); backdrop-filter:blur(4px); z-index:9000; }
#mbs-preview-modal .mbs-modal-box { position:fixed; inset:0; margin:auto; width:min(680px, 92vw); max-height:88vh; background:#fff; border-radius:1rem; box-shadow:0 25px 50px -12px rgba(0,0,0,.25); z-index:9001; display:flex; flex-direction:column; }
.mbs-pv-tab { padding:.45rem .9rem; border-radius:.4rem; font-size:.8rem; font-weight:600; color:#64748b; background:transparent; border:none; cursor:pointer; }
.mbs-pv-tab.active { background:#fff; color:#0f172a; box-shadow:0 1px 2px rgba(0,0,0,.06); }
.mbs-pv-content { padding:1.25rem; flex:1; min-height:0; }
#mbs-pv-body iframe { width:100%; min-height:540px; border:0; border-radius:.5rem; background:#fff; }
.mbs-pv-line-bubble { max-width:340px; margin:0 auto; border-radius:.9rem; overflow:hidden; box-shadow:0 4px 12px rgba(15,23,42,.1); border:1px solid #e2e8f0; }
.mbs-pv-line-header { padding:1rem 1.25rem; color:#fff; }
.mbs-pv-line-body { padding:1rem 1.25rem; background:#fff; }
.mbs-pv-line-narrative { font-size:13px; color:#475569; line-height:1.5; }
.mbs-pv-line-sep { height:1px; background:#e2e8f0; margin:.85rem 0; }
.mbs-pv-line-priority { margin-bottom:.6rem; }
.mbs-pv-line-priority-title { font-size:13px; font-weight:700; color:#0f172a; }
.mbs-pv-line-priority-detail { font-size:11px; color:#64748b; margin-top:.15rem; }
.mbs-pv-line-kpi-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:.5rem; margin-top:.5rem; }
.mbs-pv-line-kpi-label { font-size:10px; color:#94a3b8; text-align:center; }
.mbs-pv-line-kpi-value { font-size:16px; font-weight:700; color:#0f172a; text-align:center; }
body[data-theme='dark'] #mbs-preview-modal .mbs-modal-box { background:#0f172a; }
body[data-theme='dark'] #mbs-preview-modal h3 { color:#f1f5f9; }
body[data-theme='dark'] .mbs-pv-tab { color:#94a3b8; }
body[data-theme='dark'] .mbs-pv-tab.active { background:#1e293b; color:#f1f5f9; }
</style>

<script>
(function(){
    const CSRF = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';
    let previewData = null;
    let currentTab = 'line';

    function esc(s) { const d = document.createElement('div'); d.textContent = String(s||''); return d.innerHTML; }

    window.mbsClosePreview = function() {
        document.getElementById('mbs-preview-modal').classList.remove('show');
    };

    window.mbsPreview = async function() {
        const m = document.getElementById('mbs-preview-modal');
        m.classList.add('show');
        document.getElementById('mbs-pv-date').textContent = '';
        document.getElementById('mbs-pv-body').innerHTML = '<p class="text-center text-slate-400 py-12"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>';
        try {
            const r = await fetch('ajax_morning_brief.php?action=preview');
            const j = await r.json();
            if (!j.ok) {
                document.getElementById('mbs-pv-body').innerHTML = '<p class="text-center text-rose-500 py-8">' + esc(j.error || 'โหลดไม่สำเร็จ') + '</p>';
                return;
            }
            previewData = j;
            const meta = j.brief_meta;
            document.getElementById('mbs-pv-date').textContent =
                meta.date_thai + ' · วัน' + meta.weekday_thai +
                (meta.model && meta.model !== 'fallback' ? ' · ' + meta.model : '');
            renderPreview(currentTab);
        } catch(e) {
            document.getElementById('mbs-pv-body').innerHTML = '<p class="text-center text-rose-500 py-8">' + esc(String(e)) + '</p>';
        }
    };

    function renderPreview(which) {
        if (!previewData) return;
        const body = document.getElementById('mbs-pv-body');
        if (which === 'line') {
            body.innerHTML = renderLineBubble(previewData);
        } else {
            const html = previewData.email_html || '';
            body.innerHTML = '<iframe sandbox srcdoc="' + esc(html).replace(/"/g, '&quot;') + '"></iframe>';
        }
    }

    function renderLineBubble(d) {
        const meta = d.brief_meta;
        const flex = d.line_flex;
        const bubble = flex.contents;
        const header = bubble.header;
        const body = bubble.body;
        const bg = header.backgroundColor;
        const title = (header.contents[0] || {}).text || '';
        const sub = (header.contents[1] || {}).text || '';
        let bodyHtml = '';
        const sch = (meta.priorities || []);
        bodyHtml += '<p class="mbs-pv-line-narrative">' + esc(meta.narrative) + '</p>';
        if (sch.length) {
            bodyHtml += '<div class="mbs-pv-line-sep"></div>';
            sch.slice(0,4).forEach(p => {
                bodyHtml += '<div class="mbs-pv-line-priority">'
                    + '<div class="mbs-pv-line-priority-title">• ' + esc(p.title||'') + '</div>'
                    + '<div class="mbs-pv-line-priority-detail">' + esc(p.detail||'') + '</div>'
                    + '</div>';
            });
        }
        // KPIs (extract from flex last box.horizontal)
        const lastBox = body.contents[body.contents.length - 1];
        if (lastBox && lastBox.layout === 'horizontal') {
            bodyHtml += '<div class="mbs-pv-line-sep"></div>';
            bodyHtml += '<div class="mbs-pv-line-kpi-grid">';
            lastBox.contents.forEach(c => {
                const lbl = (c.contents[0] || {}).text || '';
                const val = (c.contents[1] || {}).text || '';
                bodyHtml += '<div><div class="mbs-pv-line-kpi-label">' + esc(lbl) + '</div><div class="mbs-pv-line-kpi-value">' + esc(val) + '</div></div>';
            });
            bodyHtml += '</div>';
        }
        return '<div class="mbs-pv-line-bubble">'
            + '<div class="mbs-pv-line-header" style="background:' + esc(bg) + '">'
            + '<div style="font-size:16px;font-weight:700">' + esc(title) + '</div>'
            + '<div style="font-size:11px;opacity:.9;margin-top:.15rem">' + esc(sub) + '</div>'
            + '</div>'
            + '<div class="mbs-pv-line-body">' + bodyHtml + '</div>'
            + '</div>';
    }

    document.querySelectorAll('.mbs-pv-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.mbs-pv-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            currentTab = btn.dataset.pv;
            renderPreview(currentTab);
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.getElementById('mbs-preview-modal').classList.contains('show')) {
            mbsClosePreview();
        }
    });

    window.mbsTestSend = async function() {
        const conf = await Swal.fire({
            icon: 'question',
            title: 'ทดสอบส่ง brief วันนี้?',
            html: 'ระบบจะส่งเข้า LINE/Email ตามที่เปิดในการตั้งค่า · ข้อความจะมี <b>[ทดสอบ]</b> นำหน้า',
            showCancelButton: true,
            confirmButtonText: 'ส่งเลย',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#059669',
        });
        if (!conf.isConfirmed) return;

        Swal.fire({ title: 'กำลังส่ง...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            const r = await fetch('ajax_morning_brief.php?action=test_send', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok) {
                Swal.fire({ icon: 'error', title: 'ส่งไม่สำเร็จ', text: j.error || 'unknown' });
                return;
            }
            const rs = j.results || {};
            const lineLine = rs.line.ok
                ? `✓ LINE → ${rs.line.target}`
                : `✗ LINE — ${rs.line.error}`;
            const emailLine = rs.email.ok
                ? `✓ Email → ${rs.email.target}`
                : `✗ Email — ${rs.email.error}`;
            const overall = rs.line.ok || rs.email.ok;
            Swal.fire({
                icon: overall ? 'success' : 'warning',
                title: overall ? 'ส่งเสร็จ' : 'ส่งไม่สำเร็จทุก channel',
                html: `<div style="text-align:left;font-size:14px"><div style="color:${rs.line.ok ? '#059669' : '#dc2626'}">${esc(lineLine)}</div><div style="color:${rs.email.ok ? '#059669' : '#dc2626'};margin-top:.4rem">${esc(emailLine)}</div></div>`,
                confirmButtonColor: '#059669',
            });
        } catch(e) {
            Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(e) });
        }
    };
})();
</script>

<style>
.mbs-channel { display:flex; gap:.85rem; padding:1rem; border-radius:.85rem; border:1.5px solid #e2e8f0; cursor:pointer; transition:border-color .15s; align-items:flex-start; }
.mbs-channel:hover { border-color:#94a3b8; }
.mbs-channel:has(input:checked) { border-color:#10b981; background:#f0fdf4; }
.mbs-channel input[type=checkbox] { margin-top:.25rem; accent-color:#10b981; width:18px; height:18px; flex-shrink:0; }
.mbs-channel-icon { flex-shrink:0; width:2.25rem; height:2.25rem; border-radius:.6rem; display:flex; align-items:center; justify-content:center; font-size:.95rem; }
.mbs-module { display:flex; gap:.65rem; padding:.85rem; border-radius:.65rem; border:1px solid #e2e8f0; cursor:pointer; transition:all .15s; align-items:center; }
.mbs-module:hover { border-color:#94a3b8; }
.mbs-module:has(input:checked) { border-color:#10b981; background:#f0fdf4; }
.mbs-module input[type=checkbox] { accent-color:#10b981; width:16px; height:16px; flex-shrink:0; }
.mbs-module-icon { width:1.75rem; height:1.75rem; border-radius:.5rem; background:#f1f5f9; color:#475569; display:flex; align-items:center; justify-content:center; font-size:.8rem; flex-shrink:0; }
body[data-theme='dark'] .mbs-channel, body[data-theme='dark'] .mbs-module { background:#0f172a; border-color:#1e293b; }
body[data-theme='dark'] .mbs-channel:has(input:checked), body[data-theme='dark'] .mbs-module:has(input:checked) { background:rgba(16,185,129,.1); border-color:#10b981; }
body[data-theme='dark'] .mbs-channel p, body[data-theme='dark'] .mbs-module p { color:#f1f5f9; }
body[data-theme='dark'] .mbs-channel .text-slate-500, body[data-theme='dark'] .mbs-module .text-slate-500 { color:#94a3b8 !important; }
body[data-theme='dark'] .mbs-module-icon { background:#1e293b; color:#94a3b8; }
</style>

<script>
(function(){
    document.getElementById('mbs-form').addEventListener('submit', async function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>');
        try {
            const r = await fetch('ajax_morning_brief.php?action=pref:save', { method:'POST', body: fd });
            const j = await r.json();
            if (j.ok) {
                Swal.fire({ icon:'success', title:'บันทึกแล้ว', timer:1400, showConfirmButton:false });
            } else {
                Swal.fire({ icon:'error', title:'บันทึกไม่สำเร็จ', text: j.error || 'unknown' });
            }
        } catch(e) {
            Swal.fire({ icon:'error', title:'เกิดข้อผิดพลาด', text: String(e) });
        }
    });
})();
</script>
