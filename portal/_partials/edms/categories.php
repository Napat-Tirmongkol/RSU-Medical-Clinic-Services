<?php
/**
 * portal/_partials/edms/categories.php
 * จัดการหมวดความเร่งด่วน / ชั้นความลับ / หมวดอื่น ๆ
 *
 * Query: ?section=edms&edms_view=categories&kind=priority|confidentiality|custom
 */
declare(strict_types=1);

$pdo = db();

$kind = $_GET['kind'] ?? 'priority';
if (!in_array($kind, ['priority','confidentiality','custom'], true)) $kind = 'priority';

$kindLabels = [
    'priority'       => ['title' => 'ความเร่งด่วน',   'icon' => 'fa-flag',     'tone' => 'amber'],
    'confidentiality'=> ['title' => 'ชั้นความลับ',    'icon' => 'fa-lock',     'tone' => 'rose'],
    'custom'         => ['title' => 'หมวดทั่วไป',     'icon' => 'fa-tags',     'tone' => 'sky'],
];

$rows = [];
try {
    $st = $pdo->prepare("SELECT * FROM sys_doc_categories WHERE kind = ? ORDER BY sort_order ASC, id ASC");
    $st->execute([$kind]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$total = count($rows);
$active = count(array_filter($rows, fn($r) => (int)$r['is_active'] === 1));

$colorOptions = ['slate','sky','emerald','violet','amber','rose','orange','cyan','purple'];
$meta = $kindLabels[$kind];
?>
<div class="max-w-3xl mx-auto px-4 md:px-6 py-6">
    <a href="?section=edms" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับหน้าหลัก EDMS
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-<?= $meta['tone'] ?>-50 text-<?= $meta['tone'] ?>-600 rounded-2xl border border-<?= $meta['tone'] ?>-100 flex items-center justify-center text-xl">
            <i class="fa-solid <?= $meta['icon'] ?>"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">หมวดหมู่ EDMS</h2>
            <p class="text-slate-500 text-sm font-medium">จัดการ <?= htmlspecialchars($meta['title']) ?> · <?= $active ?>/<?= $total ?> active</p>
        </div>
    </div>

    <!-- Kind tabs -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-2 mb-5 inline-flex gap-1 flex-wrap">
        <?php foreach ($kindLabels as $k => $m):
            $isActive = ($k === $kind);
        ?>
            <a href="?section=edms&edms_view=categories&kind=<?= urlencode($k) ?>"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-black transition-all
                      <?= $isActive ? "bg-{$m['tone']}-50 text-{$m['tone']}-700 border border-{$m['tone']}-100" : 'text-slate-500 hover:bg-slate-50' ?>">
                <i class="fa-solid <?= $m['icon'] ?>"></i>
                <?= htmlspecialchars($m['title']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Add form -->
    <form id="cat-add" onsubmit="catAdd(event)" class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 mb-5">
        <p class="text-[11px] font-black uppercase tracking-widest text-slate-500 mb-3">เพิ่มหมวดใหม่</p>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-2.5">
            <input type="text" name="code" placeholder="รหัส *" required
                class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-<?= $meta['tone'] ?>-400">
            <input type="text" name="name" placeholder="ชื่อแสดง *" required
                class="md:col-span-2 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-<?= $meta['tone'] ?>-400">
            <select name="color" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                <option value="">— สี —</option>
                <?php foreach ($colorOptions as $c): ?>
                    <option value="<?= $c ?>"><?= $c ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="sort_order" value="0" placeholder="ลำดับ"
                class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-<?= $meta['tone'] ?>-400">
        </div>
        <div class="flex justify-end mt-3">
            <button type="submit" class="bg-<?= $meta['tone'] ?>-500 hover:bg-<?= $meta['tone'] ?>-600 text-white px-4 py-2 rounded-xl text-xs font-black inline-flex items-center gap-1.5 transition-colors">
                <i class="fa-solid fa-plus"></i> เพิ่มหมวด
            </button>
        </div>
    </form>

    <!-- List -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-5 py-3 text-left">รหัส / ชื่อ</th>
                        <th class="px-5 py-3 text-left">สี</th>
                        <th class="px-5 py-3 text-center">ลำดับ</th>
                        <th class="px-5 py-3 text-center">สถานะ</th>
                        <th class="px-5 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5" class="px-5 py-12 text-center text-slate-400 font-bold text-sm">— ยังไม่มีหมวด —</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr class="hover:bg-slate-50/60"
                            id="cat-row-<?= (int)$r['id'] ?>"
                            data-id="<?= (int)$r['id'] ?>"
                            data-code="<?= htmlspecialchars((string)$r['code'], ENT_QUOTES) ?>"
                            data-name="<?= htmlspecialchars((string)$r['name'], ENT_QUOTES) ?>"
                            data-color="<?= htmlspecialchars((string)($r['color'] ?? ''), ENT_QUOTES) ?>"
                            data-sort="<?= (int)$r['sort_order'] ?>"
                            data-active="<?= (int)$r['is_active'] ?>">
                            <td class="px-5 py-3">
                                <div class="font-black text-slate-800"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="text-[10px] font-bold text-slate-400 font-mono"><?= htmlspecialchars($r['code']) ?></div>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($r['color']): ?>
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-<?= htmlspecialchars($r['color']) ?>-50 text-<?= htmlspecialchars($r['color']) ?>-700 border border-<?= htmlspecialchars($r['color']) ?>-100 text-[10px] font-black">
                                        <span class="w-2 h-2 rounded-full bg-<?= htmlspecialchars($r['color']) ?>-500"></span>
                                        <?= htmlspecialchars($r['color']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-300 text-xs">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-center text-xs font-black text-slate-600"><?= (int)$r['sort_order'] ?></td>
                            <td class="px-5 py-3 text-center">
                                <button onclick="catToggle(<?= (int)$r['id'] ?>)"
                                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= $r['is_active'] ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $r['is_active'] ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                    <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                                </button>
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <button onclick="catEdit(<?= (int)$r['id'] ?>)" title="แก้ไข"
                                    class="text-blue-500 hover:bg-blue-50 px-2 py-1 rounded text-xs font-black mr-1"><i class="fa-solid fa-pen"></i></button>
                                <button onclick="catDelete(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name']), ENT_QUOTES) ?>')" title="ลบ"
                                    class="text-rose-500 hover:bg-rose-50 px-2 py-1 rounded text-xs font-black"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
const CAT_KIND = <?= json_encode($kind) ?>;
const COLOR_OPTIONS = <?= json_encode($colorOptions) ?>;

async function catPost(action, data) {
    const fd = new FormData();
    fd.append('entity', 'category');
    fd.append('action', action);
    fd.append('csrf_token', portal_CSRF);
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v ?? ''));
    const res = await fetch('ajax_edms.php', { method: 'POST', body: fd });
    return res.json();
}

async function catAdd(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd.entries());
    data.kind = CAT_KIND;
    data.is_active = 1;
    const res = await catPost('create', data);
    if (res.ok) {
        Swal.fire({ icon: 'success', title: res.message || 'เพิ่มแล้ว', timer: 800, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'เพิ่มไม่สำเร็จ', text: res.message || '' });
    }
}

async function catToggle(id) {
    const res = await catPost('toggle', { id });
    if (res.ok) location.reload();
    else Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: res.message || '' });
}

async function catDelete(id, name) {
    const c = await Swal.fire({
        title: 'ลบหมวดนี้?',
        text: name,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#e11d48',
    });
    if (!c.isConfirmed) return;
    const res = await catPost('delete', { id });
    if (res.ok) document.getElementById('cat-row-' + id)?.remove();
    else Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: res.message || '' });
}

async function catEdit(id) {
    const row = document.getElementById('cat-row-' + id);
    if (!row) return;
    const cur = {
        code: row.dataset.code || '',
        name: row.dataset.name || '',
        color: row.dataset.color || '',
        sort: row.dataset.sort || '0',
        active: row.dataset.active === '1',
    };

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const colorOpts = '<option value="">— สี —</option>' +
        COLOR_OPTIONS.map(c => `<option value="${esc(c)}" ${cur.color === c ? 'selected' : ''}>${esc(c)}</option>`).join('');
    const inputCls = 'px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none w-full';

    const result = await Swal.fire({
        title: 'แก้ไขหมวด',
        width: 540,
        html: `
            <div style="text-align:left;display:grid;grid-template-columns:1fr 2fr;gap:.6rem;margin-bottom:.6rem">
                <input id="cE-code" type="text" placeholder="รหัส *" value="${esc(cur.code)}" class="${inputCls}">
                <input id="cE-name" type="text" placeholder="ชื่อแสดง *" value="${esc(cur.name)}" class="${inputCls}">
            </div>
            <div style="text-align:left;display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                <select id="cE-color" class="${inputCls}">${colorOpts}</select>
                <input id="cE-sort" type="number" placeholder="ลำดับ" value="${esc(cur.sort)}" class="${inputCls}">
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#3b82f6',
        reverseButtons: true,
        focusConfirm: false,
        preConfirm: () => {
            const v = id => document.getElementById('cE-' + id).value.trim();
            if (!v('code') || !v('name')) {
                Swal.showValidationMessage('กรอกรหัสและชื่อ');
                return false;
            }
            return {
                code: v('code'),
                name: v('name'),
                color: v('color'),
                sort_order: v('sort') || '0',
            };
        },
    });
    if (!result.isConfirmed || !result.value) return;

    const data = { id, kind: CAT_KIND, is_active: cur.active ? 1 : 0, ...result.value };
    const res = await catPost('update', data);
    if (res.ok) {
        Swal.fire({ icon: 'success', title: res.message || 'อัปเดตแล้ว', timer: 800, showConfirmButton: false }).then(() => location.reload());
    } else {
        Swal.fire({ icon: 'error', title: 'อัปเดตไม่สำเร็จ', text: res.message || '' });
    }
}
</script>
