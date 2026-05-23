<?php
/**
 * portal/_partials/edms/sla_calendar.php
 * จัดการเวลาทำการ (business_hours) + วันหยุดราชการ (holiday)
 *
 * Query: ?section=edms&edms_view=sla_calendar
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

$pdo = db();
$weekdayNames = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

$hours = [];
$holidays = [];
try {
    $rows = $pdo->query("SELECT * FROM sys_doc_sla_calendar ORDER BY kind ASC, weekday ASC, specific_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        if ($r['kind'] === 'business_hours') $hours[] = $r;
        else $holidays[] = $r;
    }
} catch (PDOException $e) {
    echo '<div class="max-w-2xl mx-auto px-4 py-12 text-center text-rose-600 font-black">';
    echo '  ตาราง sys_doc_sla_calendar ยังไม่ถูกสร้าง — รัน migrate_edms_sla.php ก่อน';
    echo '</div>';
    return;
}

// holidays pagination
$hPage = max(1, (int)($_GET['hp'] ?? 1));
$hLimit = 20;
$hTotal = count($holidays);
$hPages = max(1, (int)ceil($hTotal / $hLimit));
$holidaysPaged = array_slice($holidays, ($hPage - 1) * $hLimit, $hLimit);
?>
<style>
#sla-cal-modal { z-index: 9000 !important; background: rgba(15,23,42,.55) !important; backdrop-filter: blur(6px); }
#sla-cal-box { max-height: 90vh; }
</style>
<div class="max-w-5xl mx-auto px-4 md:px-6 py-6" id="sla-calendar">
    <a href="?section=edms" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-sky-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับหน้าหลัก EDMS
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-cyan-50 text-cyan-600 rounded-2xl border border-cyan-100 flex items-center justify-center text-xl">
            <i class="fa-solid fa-calendar-days"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">เวลาทำการ &amp; วันหยุด</h2>
            <p class="text-slate-500 text-sm font-medium">ใช้คำนวณ SLA deadline (business_hours_only mode)</p>
        </div>
    </div>

    <!-- Business hours grid -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm p-5 mb-5">
        <div class="flex items-center justify-between mb-4">
            <div>
                <p class="text-sm font-black text-slate-800">เวลาทำการประจำสัปดาห์</p>
                <p class="text-[11px] font-bold text-slate-400">วันที่ไม่ปรากฏ = วันหยุดประจำสัปดาห์</p>
            </div>
            <button onclick="slaCalOpen('business_hours', 0)"
                class="bg-cyan-500 hover:bg-cyan-600 text-white px-3 py-1.5 rounded-xl text-xs font-black inline-flex items-center gap-1.5">
                <i class="fa-solid fa-plus"></i> เพิ่มวัน
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <?php if (empty($hours)): ?>
                <p class="md:col-span-2 text-center text-slate-400 text-xs font-bold py-8">ยังไม่มีเวลาทำการ — กดเพิ่มวัน</p>
            <?php else: foreach ($hours as $h): ?>
                <div class="bg-slate-50 rounded-2xl border border-slate-200 p-3 flex items-center justify-between hover:border-cyan-300 transition-colors">
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <div class="w-10 h-10 bg-white rounded-xl border border-slate-200 flex items-center justify-center font-black text-cyan-600 text-sm">
                            <?= htmlspecialchars(mb_substr($weekdayNames[(int)$h['weekday']] ?? '?', 0, 1, 'UTF-8')) ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-black text-slate-800"><?= htmlspecialchars($weekdayNames[(int)$h['weekday']] ?? '?') ?></p>
                            <p class="text-[11px] font-bold text-slate-500"><?= substr((string)$h['start_time'], 0, 5) ?> – <?= substr((string)$h['end_time'], 0, 5) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="text-[10px] font-black px-2 py-0.5 rounded-full border <?= (int)$h['is_active'] === 1 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                            <?= (int)$h['is_active'] === 1 ? 'เปิด' : 'ปิด' ?>
                        </span>
                        <button onclick='slaCalOpen("business_hours", <?= (int)$h["id"] ?>, <?= json_encode($h, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                            class="text-sky-600 hover:bg-sky-50 px-2 py-1 rounded-lg text-xs"><i class="fa-solid fa-pen"></i></button>
                        <button onclick="slaCalDelete(<?= (int)$h['id'] ?>)"
                            class="text-rose-600 hover:bg-rose-50 px-2 py-1 rounded-lg text-xs"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Holidays -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <p class="text-sm font-black text-slate-800">วันหยุด</p>
                <p class="text-[11px] font-bold text-slate-400">หน้า <?= $hPage ?> / <?= $hPages ?> · รวม <?= $hTotal ?> วัน</p>
            </div>
            <button onclick="slaCalOpen('holiday', 0)"
                class="bg-rose-500 hover:bg-rose-600 text-white px-3 py-1.5 rounded-xl text-xs font-black inline-flex items-center gap-1.5">
                <i class="fa-solid fa-plus"></i> เพิ่มวันหยุด
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-[10px] font-black uppercase tracking-widest text-slate-400">
                    <tr>
                        <th class="px-4 py-3 text-left">วันที่</th>
                        <th class="px-4 py-3 text-left">ชื่อ</th>
                        <th class="px-4 py-3 text-center">สถานะ</th>
                        <th class="px-4 py-3 text-right"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if (empty($holidaysPaged)): ?>
                        <tr><td colspan="4" class="px-4 py-12 text-center text-slate-400 font-bold text-xs">
                            <i class="fa-solid fa-umbrella-beach text-2xl mb-2 block text-slate-200"></i>
                            ยังไม่มีวันหยุด — กดเพิ่มวันหยุด
                        </td></tr>
                    <?php else: foreach ($holidaysPaged as $h): ?>
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-4 py-3 text-xs font-black text-slate-700 whitespace-nowrap">
                                <?= htmlspecialchars(date('d/m/Y', strtotime($h['specific_date']))) ?>
                                <span class="text-[10px] font-bold text-slate-400 ml-1">(<?= htmlspecialchars($weekdayNames[(int)date('w', strtotime($h['specific_date']))]) ?>)</span>
                            </td>
                            <td class="px-4 py-3 text-xs font-bold text-slate-600"><?= htmlspecialchars($h['name'] ?: '—') ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="text-[10px] font-black px-2 py-0.5 rounded-full border <?= (int)$h['is_active'] === 1 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-500 border-slate-200' ?>">
                                    <?= (int)$h['is_active'] === 1 ? 'เปิด' : 'ปิด' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap space-x-1">
                                <button onclick='slaCalOpen("holiday", <?= (int)$h["id"] ?>, <?= json_encode($h, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="text-xs font-black text-sky-600 hover:underline px-2">
                                    <i class="fa-solid fa-pen"></i> แก้
                                </button>
                                <button onclick="slaCalDelete(<?= (int)$h['id'] ?>)"
                                    class="text-xs font-black text-rose-600 hover:underline px-2">
                                    <i class="fa-solid fa-trash"></i> ลบ
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($hPages > 1): ?>
        <div class="px-4 py-3 border-t border-slate-100 flex justify-center gap-1">
            <?php
            $btn = function($label, $target, $active=false, $disabled=false) {
                $base = 'min-w-9 h-8 px-3 rounded-lg text-xs font-black flex items-center justify-center transition-all';
                if ($active) return "<span class='$base bg-rose-500 text-white'>$label</span>";
                if ($disabled) return "<span class='$base bg-slate-50 text-slate-300'>$label</span>";
                $qs = http_build_query(['section'=>'edms','edms_view'=>'sla_calendar','hp'=>$target]);
                return "<a href='?$qs#sla-calendar' class='$base bg-white border border-slate-200 text-slate-500 hover:border-rose-500 hover:text-rose-500'>$label</a>";
            };
            echo $btn('&laquo;', 1, false, $hPage === 1);
            echo $btn('&lsaquo;', max(1, $hPage - 1), false, $hPage === 1);
            for ($i = max(1, $hPage - 2); $i <= min($hPages, $hPage + 2); $i++) echo $btn((string)$i, $i, $i === $hPage);
            echo $btn('&rsaquo;', min($hPages, $hPage + 1), false, $hPage === $hPages);
            echo $btn('&raquo;', $hPages, false, $hPage === $hPages);
            ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div id="sla-cal-modal" class="fixed inset-0 hidden items-center justify-center p-4">
    <div id="sla-cal-box" class="bg-white rounded-3xl shadow-2xl max-w-md w-full overflow-y-auto p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-black text-slate-800" id="sla-cal-title">เพิ่มรายการ</h3>
            <button onclick="slaCalClose()" class="text-slate-400 hover:text-rose-500 text-xl"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="sla-cal-form" onsubmit="slaCalSave(event)" class="space-y-3">
            <input type="hidden" name="id" id="sc-id" value="0">
            <input type="hidden" name="kind" id="sc-kind" value="business_hours">

            <div id="sc-weekday-group">
                <label class="text-[11px] font-black text-slate-500 uppercase">วัน</label>
                <select name="weekday" id="sc-weekday" class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
                    <?php foreach ($weekdayNames as $i => $n): ?>
                        <option value="<?= $i ?>"><?= htmlspecialchars($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="sc-date-group" class="hidden">
                <label class="text-[11px] font-black text-slate-500 uppercase">วันที่ *</label>
                <input type="date" name="specific_date" id="sc-date" class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
            </div>

            <div id="sc-time-group" class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[11px] font-black text-slate-500 uppercase">เวลาเริ่ม *</label>
                    <input type="time" name="start_time" id="sc-start" value="08:00" class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
                </div>
                <div>
                    <label class="text-[11px] font-black text-slate-500 uppercase">เวลาสิ้นสุด *</label>
                    <input type="time" name="end_time" id="sc-end" value="16:00" class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
                </div>
            </div>

            <div id="sc-name-group" class="hidden">
                <label class="text-[11px] font-black text-slate-500 uppercase">ชื่อวันหยุด</label>
                <input type="text" name="name" id="sc-name" placeholder="เช่น วันสงกรานต์" class="w-full mt-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800">
            </div>

            <label class="flex items-center gap-2 cursor-pointer pt-2">
                <input type="checkbox" name="is_active" id="sc-active" value="1" checked class="w-4 h-4 accent-cyan-500">
                <span class="text-xs font-bold text-slate-700">เปิดใช้งาน</span>
            </label>

            <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 mt-4">
                <button type="button" onclick="slaCalClose()" class="px-4 py-2 rounded-xl text-xs font-black text-slate-600 hover:bg-slate-50">ยกเลิก</button>
                <button type="submit" class="bg-cyan-500 hover:bg-cyan-600 text-white px-5 py-2 rounded-xl text-xs font-black inline-flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    const csrf = window.portal_CSRF || '';

    function teleport(id) {
        const el = document.getElementById(id);
        if (el && el.parentElement !== document.body) document.body.appendChild(el);
        return el;
    }

    window.slaCalOpen = function(kind, id, data) {
        const m = teleport('sla-cal-modal');
        document.getElementById('sla-cal-title').textContent = id > 0
            ? (kind === 'business_hours' ? 'แก้ไขเวลาทำการ' : 'แก้ไขวันหยุด')
            : (kind === 'business_hours' ? 'เพิ่มเวลาทำการ' : 'เพิ่มวันหยุด');
        document.getElementById('sc-id').value = id || 0;
        document.getElementById('sc-kind').value = kind;

        const isBiz = kind === 'business_hours';
        document.getElementById('sc-weekday-group').style.display = isBiz ? '' : 'none';
        document.getElementById('sc-date-group').style.display = isBiz ? 'none' : '';
        document.getElementById('sc-time-group').style.display = isBiz ? '' : 'none';
        document.getElementById('sc-name-group').style.display = isBiz ? 'none' : '';

        if (id > 0 && data) {
            document.getElementById('sc-weekday').value = data.weekday ?? 1;
            document.getElementById('sc-date').value = data.specific_date || '';
            document.getElementById('sc-start').value = (data.start_time || '08:00:00').substring(0, 5);
            document.getElementById('sc-end').value = (data.end_time || '16:00:00').substring(0, 5);
            document.getElementById('sc-name').value = data.name || '';
            document.getElementById('sc-active').checked = !!parseInt(data.is_active);
        } else {
            document.getElementById('sc-weekday').value = 1;
            document.getElementById('sc-date').value = '';
            document.getElementById('sc-start').value = '08:00';
            document.getElementById('sc-end').value = '16:00';
            document.getElementById('sc-name').value = '';
            document.getElementById('sc-active').checked = true;
        }
        m.classList.remove('hidden'); m.classList.add('flex');
    };

    window.slaCalClose = function() {
        const m = document.getElementById('sla-cal-modal');
        m.classList.add('hidden'); m.classList.remove('flex');
    };

    window.slaCalSave = async function(e) {
        e.preventDefault();
        const form = e.target;
        const fd = new FormData(form);
        fd.append('csrf_token', csrf);
        fd.append('entity', 'calendar');
        fd.append('action', 'upsert');
        fd.set('is_active', form.is_active.checked ? '1' : '0');
        const r = await fetch('ajax_edms_sla.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r=>r.json());
        if (r.ok) {
            Swal.fire({ icon: 'success', title: r.message, timer: 1200, showConfirmButton: false });
            setTimeout(()=>location.reload(), 1300);
        } else {
            Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: r.message });
        }
    };

    window.slaCalDelete = async function(id) {
        const { isConfirmed } = await Swal.fire({
            icon: 'warning', title: 'ลบรายการนี้?',
            showCancelButton: true, confirmButtonText: 'ลบ', cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc2626'
        });
        if (!isConfirmed) return;
        const fd = new FormData();
        fd.append('csrf_token', csrf); fd.append('entity', 'calendar'); fd.append('action', 'delete'); fd.append('id', id);
        const r = await fetch('ajax_edms_sla.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r=>r.json());
        if (r.ok) { Swal.fire({ icon: 'success', title: r.message, timer: 1200, showConfirmButton: false }); setTimeout(()=>location.reload(), 1300); }
        else Swal.fire({ icon: 'error', title: r.message });
    };
})();
</script>
