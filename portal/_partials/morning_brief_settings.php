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
            <div class="flex items-center justify-end gap-3">
                <a href="?section=dashboard" class="px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-900">ยกเลิก</a>
                <button type="submit" class="px-5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">
                    บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

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
