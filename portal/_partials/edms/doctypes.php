<?php
/**
 * portal/_partials/edms/doctypes.php
 * จัดการประเภทเอกสาร (sys_doc_types) — เพิ่ม/แก้ไข/ซ่อนได้
 *
 * Query: ?section=edms&edms_view=doctypes
 */
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';

$pdo = db();

// Section gate: ต้องเป็น superadmin หรือ access_edms
$canManage = (($_SESSION['admin_role'] ?? '') === 'superadmin' || !empty($_SESSION['access_edms']));

edms_ensure_doc_types_schema($pdo);

$rows = [];
try {
    $rows = $pdo->query("SELECT t.*,
                                (SELECT COUNT(*) FROM sys_doc_documents d WHERE d.doc_type = t.code) AS used_count
                         FROM sys_doc_types t
                         ORDER BY t.sort_order ASC, t.id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException) {}

$total = count($rows);
$active = count(array_filter($rows, fn($r) => (int)$r['is_active'] === 1));

$toneOptions = ['sky','emerald','violet','amber','rose','cyan','slate','teal','indigo','orange'];
$iconSuggestions = [
    'fa-inbox', 'fa-paper-plane', 'fa-file-lines', 'fa-bullhorn', 'fa-stamp', 'fa-clipboard',
    'fa-folder', 'fa-folder-open', 'fa-scroll', 'fa-receipt', 'fa-newspaper', 'fa-envelope',
    'fa-flag', 'fa-circle-info', 'fa-gavel', 'fa-handshake', 'fa-pen-clip', 'fa-square-poll-horizontal',
];
?>
<style>
.dt-tone-sky     { background:#f0f9ff; color:#0284c7; border-color:#bae6fd; }
.dt-tone-emerald { background:#ecfdf5; color:#059669; border-color:#a7f3d0; }
.dt-tone-violet  { background:#f5f3ff; color:#7c3aed; border-color:#ddd6fe; }
.dt-tone-amber   { background:#fffbeb; color:#d97706; border-color:#fde68a; }
.dt-tone-rose    { background:#fff1f2; color:#e11d48; border-color:#fecdd3; }
.dt-tone-cyan    { background:#ecfeff; color:#0891b2; border-color:#a5f3fc; }
.dt-tone-slate   { background:#f8fafc; color:#475569; border-color:#e2e8f0; }
.dt-tone-teal    { background:#f0fdfa; color:#0d9488; border-color:#99f6e4; }
.dt-tone-indigo  { background:#eef2ff; color:#4f46e5; border-color:#c7d2fe; }
.dt-tone-orange  { background:#fff7ed; color:#ea580c; border-color:#fed7aa; }
</style>

<div class="max-w-4xl mx-auto px-4 md:px-6 py-6">
    <a href="?section=edms" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับหน้าหลัก EDMS
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-sky-50 text-sky-600 rounded-2xl border border-sky-100 flex items-center justify-center text-xl">
            <i class="fa-solid fa-folder-tree"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">ประเภทเอกสาร</h2>
            <p class="text-slate-500 text-sm font-medium">
                เพิ่ม/แก้ไข/ซ่อนประเภทเอกสารที่ใช้ในระบบ EDMS · <?= $active ?>/<?= $total ?> active
            </p>
        </div>
    </div>

    <?php if (!$canManage): ?>
        <div class="bg-rose-50 border border-rose-200 rounded-2xl p-5 text-rose-700 font-bold">
            คุณไม่มีสิทธิ์จัดการประเภทเอกสาร (ต้องเป็น superadmin หรือมี access_edms)
        </div>
    <?php else: ?>

    <!-- Info -->
    <div class="bg-sky-50 border border-sky-100 rounded-2xl p-4 mb-5 flex gap-3 items-start">
        <i class="fa-solid fa-circle-info text-sky-500 mt-0.5"></i>
        <div class="text-xs text-sky-800 font-medium leading-relaxed">
            <span class="font-black">เลขที่เอกสาร</span> จะใช้ <em>คำนำหน้า</em> ของแต่ละประเภทตามด้วยลำดับ + ปี (เช่น <span class="font-mono font-black">คำสั่ง-001/2569</span>)
            ประเภทมาตรฐาน 4 ตัว (รับ/ส่ง/บันทึก/เวียน) แก้ชื่อ/สี/ไอคอน/ลำดับได้ แต่ลบไม่ได้
        </div>
    </div>

    <!-- Add form -->
    <form id="dt-add" onsubmit="dtAdd(event)" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 mb-5">
        <p class="text-[11px] font-black uppercase tracking-widest text-slate-500 mb-3">เพิ่มประเภทใหม่</p>
        <div class="grid grid-cols-2 md:grid-cols-6 gap-2.5">
            <input type="text" name="code" placeholder="code * (a-z, _)" required pattern="[a-z0-9_]+"
                title="ตัวอักษร a-z, 0-9, _ เท่านั้น ไม่มีช่องว่าง"
                class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-sky-400">
            <input type="text" name="name" placeholder="ชื่อแสดง *" required
                class="md:col-span-2 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-sky-400">
            <input type="text" name="short_label" placeholder="คำนำหน้าเลข เช่น 'คำสั่ง'"
                class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-sky-400">
            <select name="tone" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                <?php foreach ($toneOptions as $t): ?>
                    <option value="<?= $t ?>"<?= $t==='slate' ? ' selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="sort_order" value="100" placeholder="ลำดับ"
                class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-sky-400">
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-2.5 mt-2.5">
            <input type="text" name="icon" placeholder="icon (เช่น fa-folder)" list="dt-icon-suggestions"
                class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-sky-400">
            <input type="text" name="description" placeholder="คำอธิบาย (เพิ่มเติม)"
                class="md:col-span-2 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-sky-400">
        </div>
        <datalist id="dt-icon-suggestions">
            <?php foreach ($iconSuggestions as $i): ?>
                <option value="<?= $i ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <div class="flex justify-end mt-3">
            <button type="submit" class="bg-sky-500 hover:bg-sky-600 text-white px-4 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                <i class="fa-solid fa-plus"></i> เพิ่มประเภท
            </button>
        </div>
    </form>

    <!-- List -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-4 py-3 text-left">ประเภท</th>
                        <th class="px-4 py-3 text-left">คำนำหน้า</th>
                        <th class="px-4 py-3 text-left">สี</th>
                        <th class="px-4 py-3 text-center">ลำดับ</th>
                        <th class="px-4 py-3 text-center">ใช้งาน</th>
                        <th class="px-4 py-3 text-center">สถานะ</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="7" class="px-5 py-12 text-center text-slate-400 font-bold text-sm">— ยังไม่มีประเภท —</td></tr>
                    <?php else: foreach ($rows as $r):
                        $tone = $r['tone'] ?? 'slate';
                        $isSys = (int)$r['is_system'] === 1;
                        $used  = (int)$r['used_count'];
                    ?>
                        <tr class="hover:bg-slate-50/60"
                            id="dt-row-<?= (int)$r['id'] ?>"
                            data-id="<?= (int)$r['id'] ?>"
                            data-code="<?= htmlspecialchars((string)$r['code'], ENT_QUOTES) ?>"
                            data-name="<?= htmlspecialchars((string)$r['name'], ENT_QUOTES) ?>"
                            data-short="<?= htmlspecialchars((string)($r['short_label'] ?? ''), ENT_QUOTES) ?>"
                            data-desc="<?= htmlspecialchars((string)($r['description'] ?? ''), ENT_QUOTES) ?>"
                            data-icon="<?= htmlspecialchars((string)($r['icon'] ?? ''), ENT_QUOTES) ?>"
                            data-tone="<?= htmlspecialchars($tone, ENT_QUOTES) ?>"
                            data-sort="<?= (int)$r['sort_order'] ?>"
                            data-active="<?= (int)$r['is_active'] ?>"
                            data-system="<?= $isSys ? 1 : 0 ?>">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <?php if (!empty($r['icon'])): ?>
                                        <span class="inline-flex w-7 h-7 rounded-lg items-center justify-center border dt-tone-<?= htmlspecialchars($tone) ?>">
                                            <i class="fa-solid <?= htmlspecialchars($r['icon']) ?> text-xs"></i>
                                        </span>
                                    <?php endif; ?>
                                    <div>
                                        <div class="font-black text-slate-800 flex items-center gap-1.5">
                                            <?= htmlspecialchars($r['name']) ?>
                                            <?php if ($isSys): ?>
                                                <span class="text-[9px] font-black uppercase tracking-widest bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded-full">SYSTEM</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-[10px] font-bold text-slate-400 font-mono"><?= htmlspecialchars($r['code']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 font-bold text-slate-600 text-xs"><?= htmlspecialchars((string)($r['short_label'] ?? '')) ?: '<span class="text-slate-300">-</span>' ?></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full border text-[10px] font-black dt-tone-<?= htmlspecialchars($tone) ?>">
                                    <?= htmlspecialchars($tone) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center text-xs font-black text-slate-600"><?= (int)$r['sort_order'] ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($used > 0): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-sky-50 text-sky-700 border border-sky-100 text-[10px] font-black">
                                        <i class="fa-solid fa-file text-[8px]"></i> <?= number_format($used) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-300 text-xs">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button onclick="dtToggle(<?= (int)$r['id'] ?>)"
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= $r['is_active'] ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $r['is_active'] ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                    <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button onclick="dtEdit(<?= (int)$r['id'] ?>)" title="แก้ไข"
                                    class="text-blue-500 hover:bg-blue-50 px-2 py-1 rounded text-xs font-black mr-1"><i class="fa-solid fa-pen"></i></button>
                                <?php if (!$isSys && $used === 0): ?>
                                    <button onclick="dtDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name']), ENT_QUOTES) ?>')" title="ลบ"
                                        class="text-rose-500 hover:bg-rose-50 px-2 py-1 rounded text-xs font-black"><i class="fa-solid fa-trash"></i></button>
                                <?php else: ?>
                                    <button disabled title="<?= $isSys ? 'ประเภทมาตรฐานลบไม่ได้' : 'มีเอกสารใช้งานอยู่' ?>"
                                        class="text-slate-300 px-2 py-1 rounded text-xs font-black cursor-not-allowed"><i class="fa-solid fa-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
const DT_TONE_OPTIONS = <?= json_encode($toneOptions) ?>;
const DT_ICON_SUGGESTIONS = <?= json_encode($iconSuggestions) ?>;

async function dtPost(action, data) {
    const fd = new FormData();
    fd.append('entity', 'doctype');
    fd.append('action', action);
    fd.append('csrf_token', portal_CSRF);
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v ?? ''));
    const res = await fetch('ajax_edms.php', { method: 'POST', body: fd });
    return res.json();
}

async function dtAdd(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd.entries());
    data.is_active = 1;
    const res = await dtPost('create', data);
    if (res.ok) {
        Swal.fire({ icon: 'success', title: res.message || 'เพิ่มแล้ว', timer: 800, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'เพิ่มไม่สำเร็จ', text: res.message || '' });
    }
}

async function dtToggle(id) {
    const res = await dtPost('toggle', { id });
    if (res.ok) location.reload();
    else Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || '' });
}

async function dtDelete(id, name) {
    const c = await Swal.fire({
        title: 'ลบประเภทนี้?',
        text: name,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#e11d48',
    });
    if (!c.isConfirmed) return;
    const res = await dtPost('delete', { id });
    if (res.ok) document.getElementById('dt-row-' + id)?.remove();
    else Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.message || '' });
}

async function dtEdit(id) {
    const row = document.getElementById('dt-row-' + id);
    if (!row) return;
    const cur = {
        code:  row.dataset.code || '',
        name:  row.dataset.name || '',
        short: row.dataset.short || '',
        desc:  row.dataset.desc || '',
        icon:  row.dataset.icon || '',
        tone:  row.dataset.tone || 'slate',
        sort:  row.dataset.sort || '0',
        active: row.dataset.active === '1',
        system: row.dataset.system === '1',
    };

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const toneOpts = DT_TONE_OPTIONS.map(t => `<option value="${esc(t)}" ${cur.tone === t ? 'selected' : ''}>${esc(t)}</option>`).join('');
    const iconList = DT_ICON_SUGGESTIONS.map(i => `<option value="${esc(i)}"></option>`).join('');
    const inputCls = 'px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none w-full';

    const result = await Swal.fire({
        title: 'แก้ไขประเภทเอกสาร',
        width: 640,
        html: `
            <div style="text-align:left;display:grid;grid-template-columns:1fr 2fr;gap:.6rem;margin-bottom:.6rem">
                <input id="dE-code" type="text" placeholder="code *" value="${esc(cur.code)}" ${cur.system ? 'disabled title="ประเภทมาตรฐานห้ามแก้ code"' : ''} class="${inputCls}">
                <input id="dE-name" type="text" placeholder="ชื่อแสดง *" value="${esc(cur.name)}" class="${inputCls}">
            </div>
            <div style="text-align:left;display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem;margin-bottom:.6rem">
                <input id="dE-short" type="text" placeholder="คำนำหน้าเลข" value="${esc(cur.short)}" class="${inputCls}">
                <select id="dE-tone" class="${inputCls}">${toneOpts}</select>
                <input id="dE-sort" type="number" placeholder="ลำดับ" value="${esc(cur.sort)}" class="${inputCls}">
            </div>
            <div style="text-align:left;display:grid;grid-template-columns:1fr 2fr;gap:.6rem">
                <input id="dE-icon" type="text" placeholder="fa-folder" value="${esc(cur.icon)}" list="dE-icons" class="${inputCls}">
                <input id="dE-desc" type="text" placeholder="คำอธิบาย" value="${esc(cur.desc)}" class="${inputCls}">
            </div>
            <datalist id="dE-icons">${iconList}</datalist>
            ${cur.system ? '<p style="text-align:left;color:#94a3b8;font-size:11px;margin-top:.6rem;font-weight:600">SYSTEM type — แก้ name/short_label/tone/icon/sort ได้ แต่ code ห้ามแก้</p>' : ''}
        `,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#3b82f6',
        reverseButtons: true,
        focusConfirm: false,
        preConfirm: () => {
            const v = id => document.getElementById('dE-' + id).value.trim();
            if (!v('code') || !v('name')) {
                Swal.showValidationMessage('กรอก code และชื่อ');
                return false;
            }
            return {
                code: v('code'),
                name: v('name'),
                short_label: v('short'),
                description: v('desc'),
                icon: v('icon'),
                tone: v('tone'),
                sort_order: v('sort') || '0',
            };
        },
    });
    if (!result.isConfirmed || !result.value) return;

    const data = { id, is_active: cur.active ? 1 : 0, ...result.value };
    const res = await dtPost('update', data);
    if (res.ok) {
        Swal.fire({ icon: 'success', title: res.message || 'อัปเดตแล้ว', timer: 800, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'อัปเดตไม่สำเร็จ', text: res.message || '' });
    }
}
</script>
