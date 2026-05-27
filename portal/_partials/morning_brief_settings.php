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
    'campaign'    => ['e-Campaign',   'นัดแคมเปญวันนี้ · Top 3 · ขาดนัดเมื่อวาน', 'fa-calendar-check'],
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
                            <input type="text" name="line_user_id" placeholder="U + 32 ตัว hex (เช่น Uabcd1234...)"
                                   pattern="^U[0-9a-fA-F]{32}$"
                                   value="<?= htmlspecialchars($pref['line_user_id'] ?? '') ?>"
                                   class="mt-2 w-full max-w-md px-3 py-1.5 rounded-lg border border-slate-200 text-sm font-mono" />
                            <p class="text-[11px] text-slate-400 mt-1.5">
                                <i class="fa-solid fa-circle-info text-slate-400 mr-0.5"></i>
                                หา LINE User ID ของตัวเองได้ที่
                                <a href="?section=line_chat" class="text-emerald-600 hover:underline">LINE Chat</a>
                                · ต้อง add LINE OA ของคลินิกเป็นเพื่อนก่อน
                            </p>
                        </div>
                    </label>
                    <label class="mbs-channel">
                        <input type="checkbox" name="channel_line_group" value="1" <?= !empty($pref['channel_line_group']) ? 'checked' : '' ?>>
                        <span class="mbs-channel-icon" style="background:#dcfce7;color:#06c755"><i class="fa-solid fa-users"></i></span>
                        <div class="flex-1">
                            <div class="flex items-center justify-between gap-2 flex-wrap">
                                <div>
                                    <p class="font-semibold text-slate-900">LINE Group / Room</p>
                                    <p class="text-xs text-slate-500">ส่งเข้ากลุ่ม/ห้องแชท LINE ทุกเช้า — เลือกได้หลายกลุ่ม</p>
                                </div>
                                <button type="button" onclick="mbsReloadGroups()" class="text-xs text-slate-500 hover:text-emerald-700 flex items-center gap-1" title="รีโหลดรายการกลุ่ม">
                                    <i class="fa-solid fa-rotate"></i>รีโหลด
                                </button>
                            </div>
                            <div id="mbs-groups-list" class="mt-2 space-y-1.5 max-h-56 overflow-y-auto pr-1">
                                <p class="text-xs text-slate-400 py-2"><i class="fa-solid fa-spinner fa-spin mr-1"></i>กำลังโหลด...</p>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1.5">
                                <i class="fa-solid fa-circle-info text-slate-400 mr-0.5"></i>
                                เชิญ LINE OA เข้ากลุ่ม → กลุ่มจะปรากฏอัตโนมัติ · จัดการที่
                                <a href="?section=line_settings" class="text-emerald-600 hover:underline">LINE Settings</a>
                            </p>
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
                            <p class="text-[11px] text-slate-400 mt-1.5">
                                <i class="fa-solid fa-circle-info text-slate-400 mr-0.5"></i>
                                ต้องตั้ง SMTP ที่
                                <a href="?section=smtp_settings" class="text-emerald-600 hover:underline">SMTP Settings</a>
                                ก่อน (ถ้ายังไม่ได้ตั้ง · ระบบจะ fallback ไป PHP mail() ซึ่งอาจไม่ทำงาน)
                            </p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Delivery schedule card -->
            <div class="rounded-2xl border border-slate-200 bg-white p-6">
                <h2 class="text-base font-bold text-slate-900 mb-4">กำหนดการส่ง</h2>
                <div class="space-y-4">
                    <div class="flex items-center gap-3 flex-wrap">
                        <label class="text-sm text-slate-600 min-w-[90px]">ส่งทุกวันเวลา</label>
                        <select name="delivery_hour" class="px-3 py-1.5 rounded-lg border border-slate-200 text-sm">
                            <?php for ($h = 5; $h <= 22; $h++): ?>
                                <option value="<?= $h ?>" <?= (int)$pref['delivery_hour'] === $h ? 'selected' : '' ?>>
                                    <?= sprintf('%02d:00', $h) ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <span class="text-xs text-slate-500">(เฉพาะ LINE / Email — Portal เห็นทันทีเมื่อเปิดหน้า)</span>
                    </div>
                    <label class="flex items-start gap-3 cursor-pointer p-3 rounded-lg hover:bg-slate-50 border border-slate-100">
                        <input type="checkbox" name="respect_clinic_calendar" value="1"
                               <?= !empty($pref['respect_clinic_calendar']) ? 'checked' : '' ?>
                               style="accent-color:#10b981;width:18px;height:18px;margin-top:.15rem;flex-shrink:0">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">อ้างอิงปฏิทินคลินิก</p>
                            <p class="text-xs text-slate-500 mt-0.5">ส่ง brief เฉพาะวันที่คลินิกเปิด — วันหยุด/วันหยุดพิเศษ ไม่ต้องส่ง (ตรวจจาก
                                <a href="?section=clinic_data&cd_tab=hours" class="text-emerald-600 hover:underline">ตารางเวลาคลินิก</a>)</p>
                        </div>
                    </label>
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
    const SAVED_GROUP_IDS = <?= json_encode(json_decode($pref['line_group_ids'] ?? '[]', true) ?: []) ?>;
    let previewData = null;
    let currentTab = 'line';

    function esc(s) { const d = document.createElement('div'); d.textContent = String(s||''); return d.innerHTML; }

    // โหลดรายการ LINE groups → render เป็น multi-checkbox list
    window.mbsReloadGroups = async function(){
        const wrap = document.getElementById('mbs-groups-list');
        if (!wrap) return;
        wrap.innerHTML = '<p class="text-xs text-slate-400 py-2"><i class="fa-solid fa-spinner fa-spin mr-1"></i>กำลังโหลด...</p>';
        // Preserve currently checked ids (UI state) ก่อน re-render — กัน user ติ๊กไว้แล้วโดน clear
        const prevChecked = new Set(Array.from(wrap.querySelectorAll('input[name="line_group_ids[]"]:checked')).map(c => c.value));
        const selected = prevChecked.size > 0 ? prevChecked : new Set(SAVED_GROUP_IDS);
        try {
            const r = await fetch('ajax_morning_brief.php?action=groups:list');
            const j = await r.json();
            if (!j.ok || !Array.isArray(j.groups) || j.groups.length === 0) {
                wrap.innerHTML = `<p class="text-xs text-slate-500 py-3 px-3 bg-slate-50 rounded border border-dashed border-slate-300">
                    <i class="fa-solid fa-circle-info mr-1"></i>ยังไม่มีกลุ่มที่บันทึก — เชิญ LINE OA เข้ากลุ่มก่อน
                </p>`;
                return;
            }
            wrap.innerHTML = j.groups.map(g => {
                const isChecked = selected.has(g.id);
                const typeClass = g.type === 'room' ? 'mbs-group-type-room' : 'mbs-group-type-group';
                const typeLabel = g.type === 'room' ? 'Room' : 'Group';
                const memberText = g.member_count > 0 ? `${g.member_count} คน · ` : '';
                return `<label class="mbs-group-item">
                    <input type="checkbox" name="line_group_ids[]" value="${esc(g.id)}" ${isChecked ? 'checked' : ''}>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center flex-wrap">
                            <span class="mbs-group-name">${esc(g.name || '(ไม่ทราบชื่อ)')}</span>
                            <span class="${typeClass}">${typeLabel}</span>
                        </div>
                        <div class="mbs-group-meta" style="font-family:ui-monospace,SFMono-Regular,Consolas,monospace">${memberText}${esc(g.id)}</div>
                    </div>
                </label>`;
            }).join('');
        } catch(e) {
            wrap.innerHTML = `<p class="text-xs text-rose-500 py-2">โหลดไม่สำเร็จ: ${esc(String(e))}</p>`;
        }
    };
    // Auto-load on page open
    mbsReloadGroups();

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
            let metaText = meta.date_thai + ' · วัน' + meta.weekday_thai;
            if (meta.model === 'fallback') {
                metaText += ' · ⚠ ใช้ fallback (Gemini ไม่ตอบ)' + (meta.ai_error ? ': ' + meta.ai_error : '');
            } else if (meta.model) {
                metaText += ' · ' + meta.model;
            }
            document.getElementById('mbs-pv-date').textContent = metaText;
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
            html: 'ระบบจะ <b>บันทึกการตั้งค่าปัจจุบัน</b> ก่อน แล้วส่งเข้า channel ที่เปิดอยู่<br>ข้อความจะมี <b>[ทดสอบ]</b> นำหน้า',
            showCancelButton: true,
            confirmButtonText: 'บันทึกและส่ง',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#059669',
        });
        if (!conf.isConfirmed) return;

        Swal.fire({ title: 'กำลังบันทึก + ส่ง...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

        try {
            // 1) Auto-save current form first — กัน user ลืมกด "บันทึก"
            const saveFd = new FormData(document.getElementById('mbs-form'));
            saveFd.append('csrf_token', CSRF);
            const sr = await fetch('ajax_morning_brief.php?action=pref:save', { method:'POST', body: saveFd });
            const sj = await sr.json();
            if (!sj.ok) {
                Swal.fire({ icon:'error', title:'บันทึกการตั้งค่าไม่สำเร็จ', text: sj.error || 'unknown' });
                return;
            }

            // 2) Then call test_send
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            const r = await fetch('ajax_morning_brief.php?action=test_send', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok) {
                Swal.fire({ icon: 'error', title: 'ส่งไม่สำเร็จ', text: j.error || 'unknown' });
                return;
            }
            const rs = j.results || {};
            // 3-state rendering + sub-rows ต่อกลุ่ม สำหรับ LINE Group
            function renderRow(label, r) {
                if (!r) return { color:'#94a3b8', icon:'⊝', text: `${label} —`, sub:'' };
                let sub = '';
                if (Array.isArray(r.per_group) && r.per_group.length > 0) {
                    sub = '<div style="margin-top:.25rem;padding-left:1.25rem;font-size:12px">'
                        + r.per_group.map(g => g.ok
                            ? `<div style="color:#059669">✓ <code style="font-family:ui-monospace;background:#f1f5f9;padding:1px 4px;border-radius:3px;font-size:11px">…${esc(g.id.slice(-6))}</code></div>`
                            : `<div style="color:#dc2626">✗ <code style="font-family:ui-monospace;background:#f1f5f9;padding:1px 4px;border-radius:3px;font-size:11px">…${esc(g.id.slice(-6))}</code> — ${esc(g.error || '')}</div>`
                          ).join('')
                        + '</div>';
                }
                if (r.ok) return { color:'#059669', icon:'✓', text: `${label} → ${r.target}`, sub };
                if (r.skipped) return { color:'#94a3b8', icon:'⊝', text: `${label} (ปิดอยู่) — ${r.error}`, sub };
                return { color:'#dc2626', icon:'✗', text: `${label} — ${r.error}`, sub };
            }
            const channels = [
                { key: 'line',       label: 'LINE (ส่วนตัว)' },
                { key: 'line_group', label: 'LINE Group' },
                { key: 'email',      label: 'Email' },
            ];
            const rows = channels.map(c => ({ ...renderRow(c.label, rs[c.key]), r: rs[c.key] }));

            // overall logic — ดู per_group ด้วย: ถ้ามี group ใดส่งสำเร็จก็นับว่า any sent
            const anySent = rows.some(x => x.r && (x.r.ok || (Array.isArray(x.r.per_group) && x.r.per_group.some(g => g.ok))));
            const allSkipped = rows.every(x => x.r && !x.r.ok && x.r.skipped);
            let icon = 'success', title = 'ส่งเสร็จ';
            if (!anySent) {
                if (allSkipped) { icon = 'info'; title = 'ยังไม่ได้เปิด channel ใด · ติ๊ก channel ที่ต้องการก่อน'; }
                else { icon = 'warning'; title = 'ส่งไม่สำเร็จ — ดูรายละเอียดด้านล่าง'; }
            } else if (rows.some(x => !x.r?.ok && !x.r?.skipped)) {
                title = 'ส่งเสร็จ — แต่บางช่องทาง fail';
            }

            const html = rows.map(x =>
                `<div style="color:${x.color};margin-top:.4rem"><b>${x.icon}</b> ${esc(x.text)}</div>${x.sub}`
            ).join('');

            Swal.fire({
                icon: icon,
                title: title,
                html: `<div style="text-align:left;font-size:14px;line-height:1.7">${html}</div>`,
                confirmButtonColor: '#059669',
                width: 560,
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
.mbs-group-item { display:flex; gap:.6rem; padding:.5rem .65rem; border-radius:.45rem; background:#fff; border:1px solid #e2e8f0; cursor:pointer; transition:border-color .12s, background .12s; align-items:center; font-size:.8rem; }
.mbs-group-item:hover { border-color:#94a3b8; }
.mbs-group-item:has(input:checked) { border-color:#06c755; background:#f0fdf4; }
.mbs-group-item input[type=checkbox] { accent-color:#06c755; width:15px; height:15px; flex-shrink:0; }
.mbs-group-name { font-weight:600; color:#0f172a; }
.mbs-group-meta { font-size:.65rem; color:#94a3b8; }
.mbs-group-type-room  { display:inline-block;font-size:9px;font-weight:700;padding:1px 5px;border-radius:99px;background:#e0f2fe;color:#0369a1;margin-left:.25rem; }
.mbs-group-type-group { display:inline-block;font-size:9px;font-weight:700;padding:1px 5px;border-radius:99px;background:#dcfce7;color:#15803d;margin-left:.25rem; }
body[data-theme='dark'] .mbs-group-item { background:#0f172a; border-color:#1e293b; }
body[data-theme='dark'] .mbs-group-item:has(input:checked) { background:rgba(6,199,85,.1); border-color:#06c755; }
body[data-theme='dark'] .mbs-group-name { color:#f1f5f9; }
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
