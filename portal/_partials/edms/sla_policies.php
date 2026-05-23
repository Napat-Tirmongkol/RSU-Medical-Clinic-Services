<?php
/**
 * portal/_partials/edms/sla_policies.php
 * จัดการ SLA Policies — matrix (doc_type × priority)
 *
 * Query: ?section=edms&edms_view=sla_policies
 * Permission: superadmin OR access_edms_sla_admin
 */
declare(strict_types=1);

$_role = $_SESSION['admin_role'] ?? '';
$_isSuper = ($_role === 'superadmin');
$_canAdmin = $_isSuper || !empty($_SESSION['access_edms_sla_admin']);

if (!$_canAdmin) {
    echo '<div class="max-w-2xl mx-auto px-4 py-16 text-center">';
    echo '  <i class="fa-solid fa-lock text-rose-400 text-5xl mb-3"></i>';
    echo '  <p class="font-black text-slate-700 text-lg">Access Denied</p>';
    echo '  <p class="text-sm text-slate-500 mt-2">ต้องมีสิทธิ์ <code>access_edms_sla_admin</code> หรือเป็น superadmin</p>';
    echo '</div>';
    return;
}

require_once __DIR__ . '/_helpers.php';
$pdo = db();
$_docTypes = edms_get_doc_types($pdo, true);

// pagination 20/หน้า (กฎโปรเจกต์)
$page = max(1, (int)($_GET['p'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$total = 0;
$policies = [];
try {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_sla_policies")->fetchColumn();
    $policies = $pdo->query("SELECT p.*, c.name AS priority_name, c.color AS priority_color
        FROM sys_doc_sla_policies p
        LEFT JOIN sys_doc_categories c ON c.id = p.priority_id
        ORDER BY p.doc_type ASC, p.sort_order ASC, c.sort_order ASC
        LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    echo '<div class="max-w-2xl mx-auto px-4 py-12 text-center text-rose-600 font-black">';
    echo '  ตาราง sys_doc_sla_policies ยังไม่ถูกสร้าง — กรุณารัน migrate_edms_sla.php ก่อน';
    echo '</div>';
    return;
}
$totalPages = max(1, (int)ceil($total / $limit));

$priorities = [];
try {
    $priorities = $pdo->query("SELECT id, name, color FROM sys_doc_categories WHERE kind='priority' AND is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException) {}
?>
<style>
#sla-policy-modal { z-index: 9000 !important; background: rgba(15,23,42,.55) !important; backdrop-filter: blur(6px); }
#sla-policy-box { max-height: 90vh; }
</style>
<div class="max-w-5xl mx-auto px-4 md:px-6 py-6" id="sla-policies">
    <a href="?section=edms" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับหน้าหลัก EDMS
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-purple-50 text-purple-600 rounded-2xl border border-purple-100 flex items-center justify-center text-xl">
            <i class="fa-solid fa-stopwatch-20"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">SLA Policies</h2>
            <p class="text-slate-500 text-sm font-medium">กำหนดเวลา ack/resolve ตามประเภทเอกสาร × ความเร่งด่วน · <?= $total ?> นโยบาย</p>
        </div>
        <button onclick="slaPolicyOpen(0)"
            class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-xl text-xs font-black inline-flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-plus"></i> เพิ่ม Policy
        </button>
    </div>

    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
            <p class="text-[11px] font-black text-slate-400">หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= $total ?> รายการ</p>
            <p class="text-[10px] font-bold text-slate-400">หน่วย: business hours (Mon-Fri 08:00-16:00)</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-4 py-3 text-left">ประเภท</th>
                        <th class="px-4 py-3 text-left">ความเร่งด่วน</th>
                        <th class="px-4 py-3 text-left">ชื่อ</th>
                        <th class="px-4 py-3 text-center">Ack (ชม.)</th>
                        <th class="px-4 py-3 text-center">Resolve (ชม.)</th>
                        <th class="px-4 py-3 text-center">Warn @ %</th>
                        <th class="px-4 py-3 text-center">Escalate</th>
                        <th class="px-4 py-3 text-center">สถานะ</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($policies)): ?>
                        <tr><td colspan="9" class="px-4 py-16 text-center text-slate-400 font-bold text-sm">
                            <i class="fa-solid fa-stopwatch text-3xl mb-3 block text-slate-200"></i>
                            ยังไม่มี policy — กดปุ่ม "เพิ่ม Policy" หรือรัน migration เพื่อ seed
                        </td></tr>
                    <?php else: foreach ($policies as $p):
                        $docTypeRow = null;
                        foreach ($_docTypes as $dt) {
                            if ($dt['code'] === $p['doc_type']) { $docTypeRow = $dt; break; }
                        }
                        $dtName = $docTypeRow['short_label'] ?? $docTypeRow['name'] ?? $p['doc_type'];
                        $dtIcon = $docTypeRow['icon'] ?? 'fa-file';
                        $dtTone = $docTypeRow['tone'] ?? 'slate';
                        $priColor = $p['priority_color'] ?: 'slate';
                        $escLabel = ['superadmin' => 'Superadmin', 'dept_head' => 'หัวหน้าฝ่าย', 'superadmin+dept_head' => 'ทั้งคู่'][$p['escalate_to_role']] ?? '—';
                    ?>
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-<?= $dtTone ?>-50 text-<?= $dtTone ?>-700 border border-<?= $dtTone ?>-100 text-[10px] font-black">
                                    <i class="fa-solid <?= $dtIcon ?> text-[9px]"></i> <?= htmlspecialchars($dtName) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($p['priority_name']): ?>
                                    <span class="inline-flex px-2 py-0.5 rounded-full bg-<?= $priColor ?>-50 text-<?= $priColor ?>-700 border border-<?= $priColor ?>-100 text-[10px] font-black">
                                        <?= htmlspecialchars($p['priority_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-[10px] font-bold text-slate-400">ทุก priority</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-xs font-black text-slate-700"><?= htmlspecialchars($p['name']) ?></td>
                            <td class="px-4 py-3 text-center text-sm font-black text-amber-600"><?= number_format((float)$p['ack_hours'], 2) ?></td>
                            <td class="px-4 py-3 text-center text-sm font-black text-emerald-600"><?= number_format((float)$p['resolve_hours'], 2) ?></td>
                            <td class="px-4 py-3 text-center text-xs font-bold text-slate-600"><?= (int)$p['warn_at_pct'] ?>%</td>
                            <td class="px-4 py-3 text-center text-xs font-bold text-slate-600"><?= htmlspecialchars($escLabel) ?></td>
                            <td class="px-4 py-3 text-center">
                                <button onclick="slaPolicyToggle(<?= (int)$p['id'] ?>)" class="inline-flex px-2.5 py-0.5 rounded-full text-[10px] font-black border <?= (int)$p['is_active'] === 1 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                                    <?= (int)$p['is_active'] === 1 ? 'เปิด' : 'ปิด' ?>
                                </button>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap space-x-1">
                                <button onclick='slaPolicyOpen(<?= (int)$p["id"] ?>, <?= json_encode($p, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="text-xs font-black text-sky-600 hover:underline px-2">
                                    <i class="fa-solid fa-pen"></i> แก้
                                </button>
                                <button onclick="slaPolicyDelete(<?= (int)$p['id'] ?>)"
                                    class="text-xs font-black text-rose-600 hover:underline px-2">
                                    <i class="fa-solid fa-trash"></i> ลบ
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="px-4 py-3 border-t border-slate-100 flex justify-center gap-1">
            <?php
            $btn = function($label, $target, $active=false, $disabled=false) {
                $base = 'min-w-9 h-8 px-3 rounded-lg text-xs font-black flex items-center justify-center transition-all';
                if ($active) return "<span class='$base bg-purple-500 text-white'>$label</span>";
                if ($disabled) return "<span class='$base bg-slate-50 text-slate-300'>$label</span>";
                $qs = http_build_query(['section'=>'edms','edms_view'=>'sla_policies','p'=>$target]);
                return "<a href='?$qs' class='$base bg-white border border-slate-200 text-slate-500 hover:border-purple-500 hover:text-purple-500'>$label</a>";
            };
            echo $btn('&laquo;', 1, false, $page === 1);
            echo $btn('&lsaquo;', max(1, $page - 1), false, $page === 1);
            for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) echo $btn((string)$i, $i, $i === $page);
            echo $btn('&rsaquo;', min($totalPages, $page + 1), false, $page === $totalPages);
            echo $btn('&raquo;', $totalPages, false, $page === $totalPages);
            ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit/Add Modal -->
<div id="sla-policy-modal" class="fixed inset-0 hidden items-center justify-center p-4">
    <div id="sla-policy-box" class="bg-white rounded-3xl shadow-2xl max-w-lg w-full overflow-y-auto p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-black text-slate-800" id="sla-policy-title">เพิ่ม SLA Policy</h3>
            <button onclick="slaPolicyClose()" class="text-slate-400 hover:text-rose-500 text-xl"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="sla-policy-form" onsubmit="slaPolicySave(event)" class="space-y-3">
            <input type="hidden" name="id" id="sp-id" value="0">
            <div>
                <label class="text-[11px] font-black text-slate-500 uppercase">ประเภทเอกสาร *</label>
                <select name="doc_type" id="sp-doc-type" required class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
                    <?php foreach ($_docTypes as $dt): ?>
                        <option value="<?= htmlspecialchars($dt['code']) ?>"><?= htmlspecialchars($dt['name']) ?> (<?= htmlspecialchars($dt['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-[11px] font-black text-slate-500 uppercase">ความเร่งด่วน</label>
                <select name="priority_id" id="sp-priority" class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
                    <option value="">— ทุก priority (catch-all) —</option>
                    <?php foreach ($priorities as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-[11px] font-black text-slate-500 uppercase">ชื่อ *</label>
                <input type="text" name="name" id="sp-name" required class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[11px] font-black text-slate-500 uppercase">Ack hours *</label>
                    <input type="number" step="0.5" min="0.5" max="720" name="ack_hours" id="sp-ack" required value="4"
                        class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-amber-600">
                </div>
                <div>
                    <label class="text-[11px] font-black text-slate-500 uppercase">Resolve hours *</label>
                    <input type="number" step="0.5" min="0.5" max="720" name="resolve_hours" id="sp-resolve" required value="48"
                        class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-emerald-600">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[11px] font-black text-slate-500 uppercase">Warn @ %</label>
                    <input type="number" step="1" min="0" max="100" name="warn_at_pct" id="sp-warn" value="20"
                        class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
                </div>
                <div>
                    <label class="text-[11px] font-black text-slate-500 uppercase">Escalate to</label>
                    <select name="escalate_to_role" id="sp-escalate" class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
                        <option value="superadmin+dept_head">Superadmin + หัวหน้าฝ่าย</option>
                        <option value="superadmin">Superadmin เท่านั้น</option>
                        <option value="dept_head">หัวหน้าฝ่ายเท่านั้น</option>
                    </select>
                </div>
            </div>
            <label class="flex items-center gap-2 cursor-pointer pt-2">
                <input type="checkbox" name="business_hours_only" id="sp-biz" checked class="w-4 h-4 accent-purple-500">
                <span class="text-xs font-bold text-slate-700">นับเฉพาะเวลาทำการ (Mon-Fri 08:00-16:00)</span>
            </label>
            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 mt-4">
                <button type="button" onclick="slaPolicyClose()" class="px-4 py-2 rounded-xl text-xs font-black text-slate-600 hover:bg-slate-50">ยกเลิก</button>
                <button type="submit" class="bg-purple-500 hover:bg-purple-600 text-white px-5 py-2 rounded-xl text-xs font-black inline-flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    const csrf = window.portal_CSRF || '';

    function slaPolicyTeleport() {
        const el = document.getElementById('sla-policy-modal');
        if (el && el.parentElement !== document.body) document.body.appendChild(el);
        return el;
    }

    window.slaPolicyOpen = function(id, data) {
        const m = slaPolicyTeleport();
        document.getElementById('sla-policy-title').textContent = id > 0 ? 'แก้ไข SLA Policy' : 'เพิ่ม SLA Policy';
        document.getElementById('sp-id').value = id || 0;
        if (id > 0 && data) {
            document.getElementById('sp-doc-type').value = data.doc_type || '';
            document.getElementById('sp-priority').value = data.priority_id || '';
            document.getElementById('sp-name').value = data.name || '';
            document.getElementById('sp-ack').value = data.ack_hours || 4;
            document.getElementById('sp-resolve').value = data.resolve_hours || 48;
            document.getElementById('sp-warn').value = data.warn_at_pct || 20;
            document.getElementById('sp-escalate').value = data.escalate_to_role || 'superadmin+dept_head';
            document.getElementById('sp-biz').checked = !!parseInt(data.business_hours_only);
        } else {
            document.getElementById('sla-policy-form').reset();
            document.getElementById('sp-id').value = 0;
        }
        m.classList.remove('hidden'); m.classList.add('flex');
    };

    window.slaPolicyClose = function() {
        const m = document.getElementById('sla-policy-modal');
        m.classList.add('hidden'); m.classList.remove('flex');
    };

    window.slaPolicySave = async function(e) {
        e.preventDefault();
        const form = e.target;
        const fd = new FormData(form);
        fd.append('csrf_token', csrf);
        fd.append('entity', 'policy');
        fd.append('action', 'upsert');
        if (!form.business_hours_only.checked) fd.set('business_hours_only', '0');
        else fd.set('business_hours_only', '1');
        const r = await fetch('ajax_edms_sla.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r=>r.json());
        if (r.ok) {
            Swal.fire({ icon: 'success', title: r.message, timer: 1200, showConfirmButton: false });
            setTimeout(() => location.reload(), 1300);
        } else {
            Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: r.message });
        }
    };

    window.slaPolicyToggle = async function(id) {
        const fd = new FormData();
        fd.append('csrf_token', csrf); fd.append('entity', 'policy'); fd.append('action', 'toggle'); fd.append('id', id);
        const r = await fetch('ajax_edms_sla.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r=>r.json());
        if (r.ok) location.reload();
        else Swal.fire({ icon: 'error', title: r.message });
    };

    window.slaPolicyDelete = async function(id) {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning', title: 'ลบ policy นี้?',
            text: 'ลบไม่ได้ถ้ามี routing อ้างอิงอยู่ — ปิดการใช้งานแทนได้',
            showCancelButton: true, confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626'
        });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf); fd.append('entity', 'policy'); fd.append('action', 'delete'); fd.append('id', id);
        const r = await fetch('ajax_edms_sla.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r=>r.json());
        if (r.ok) { Swal.fire({ icon: 'success', title: r.message, timer: 1200, showConfirmButton: false }); setTimeout(()=>location.reload(), 1300); }
        else Swal.fire({ icon: 'error', title: r.message });
    };
})();
</script>
