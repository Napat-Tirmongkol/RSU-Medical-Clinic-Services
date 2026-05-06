<?php
// Sub-view: Operating hours & holidays
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_hours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('regular','holiday','special') NOT NULL DEFAULT 'regular',
        weekday TINYINT NULL COMMENT '0=Sun..6=Sat',
        specific_date DATE NULL,
        open_time TIME NULL,
        close_time TIME NULL,
        is_closed TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type), INDEX idx_date (specific_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

$today = date('Y-m-d');

$regular = [];
$holidays = [];
try {
    $regular  = $pdo->query("SELECT * FROM sys_clinic_hours WHERE type='regular' ORDER BY weekday ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stmt = $pdo->prepare("SELECT * FROM sys_clinic_hours WHERE type IN ('holiday','special') AND (specific_date IS NULL OR specific_date >= :today) ORDER BY specific_date ASC LIMIT 50");
    $stmt->execute([':today' => $today]);
    $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException) {}

$weekdayNames = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];

// Build by-weekday lookup
$regularMap = [];
foreach ($regular as $r) {
    $regularMap[(int)$r['weekday']][] = $r;
}
?>
<div class="max-w-[1100px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-purple-50 rounded-xl shadow-sm border border-purple-100 flex items-center justify-center text-purple-600 text-xl">
            <i class="fa-solid fa-calendar-days"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">วันหยุด / ชั่วโมงทำการ</h2>
            <p class="text-slate-500 text-sm font-medium">กำหนดเวลาเปิด-ปิดประจำสัปดาห์ และวันหยุดพิเศษ — ใช้ตรวจสอบเวลาจอง</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Regular weekly hours -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider"><i class="fa-solid fa-repeat text-purple-500 mr-2"></i>เวลาทำการประจำสัปดาห์</h3>
                <span class="text-[10px] font-bold text-slate-400"><?= count($regular) ?> รายการ</span>
            </div>
            <div class="p-5">
                <form id="hr-add-reg" onsubmit="hrAdd(event,'regular')" class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4 pb-4 border-b border-slate-100">
                    <input type="hidden" name="type" value="regular">
                    <select name="weekday" class="px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                        <?php foreach ($weekdayNames as $i=>$n): ?><option value="<?= $i ?>"><?= $n ?></option><?php endforeach; ?>
                    </select>
                    <input type="time" name="open_time" value="08:00" class="px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <input type="time" name="close_time" value="17:00" class="px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <input type="text" name="note" placeholder="หมายเหตุ" class="md:col-span-1 px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <button type="submit" class="px-3 py-2 bg-purple-500 text-white rounded-lg text-xs font-black hover:bg-purple-600 flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-plus"></i>เพิ่ม
                    </button>
                </form>

                <?php for ($i = 0; $i < 7; $i++): ?>
                    <div class="flex items-center gap-3 py-2 <?= $i < 6 ? 'border-b border-slate-50' : '' ?>">
                        <span class="w-20 text-sm font-black text-slate-700"><?= $weekdayNames[$i] ?></span>
                        <?php if (empty($regularMap[$i])): ?>
                            <span class="text-xs font-bold text-slate-300 italic">ไม่ได้ตั้งค่า</span>
                        <?php else: foreach ($regularMap[$i] as $r): ?>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-purple-50 text-purple-700 border border-purple-100 text-[11px] font-black"
                                data-id="<?= (int)$r['id'] ?>"
                                data-type="regular"
                                data-weekday="<?= (int)$r['weekday'] ?>"
                                data-open="<?= htmlspecialchars(substr((string)$r['open_time'],0,5), ENT_QUOTES) ?>"
                                data-close="<?= htmlspecialchars(substr((string)$r['close_time'],0,5), ENT_QUOTES) ?>"
                                data-note="<?= htmlspecialchars((string)$r['note'], ENT_QUOTES) ?>">
                                <i class="fa-solid fa-clock text-[8px]"></i>
                                <?= substr($r['open_time'],0,5) ?>–<?= substr($r['close_time'],0,5) ?>
                                <?php if ($r['note']): ?> · <?= htmlspecialchars($r['note']) ?><?php endif; ?>
                                <button onclick="hrEdit(<?= (int)$r['id'] ?>)" class="text-purple-600 ml-1 hover:bg-purple-100 rounded px-1" title="แก้ไข"><i class="fa-solid fa-pen text-[8px]"></i></button>
                                <button onclick="hrDelete(<?= (int)$r['id'] ?>)" class="text-rose-500 hover:bg-rose-50 rounded px-1" title="ลบ"><i class="fa-solid fa-xmark text-[8px]"></i></button>
                            </span>
                        <?php endforeach; endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Holidays / special dates -->
        <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                <h3 class="text-sm font-black text-slate-700 uppercase tracking-wider"><i class="fa-solid fa-calendar-xmark text-rose-500 mr-2"></i>วันหยุดพิเศษ</h3>
                <div class="flex items-center gap-3">
                    <button onclick="hrFetchThai()" type="button"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-100 text-[11px] font-black hover:bg-emerald-100 transition-all">
                        <i class="fa-solid fa-flag text-[10px]"></i> ดึงวันหยุดไทย
                    </button>
                    <span class="text-[10px] font-bold text-slate-400"><?= count($holidays) ?> รายการที่จะถึง</span>
                </div>
            </div>
            <div class="p-5">
                <form id="hr-add-hol" onsubmit="hrAdd(event,'holiday')" class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4 pb-4 border-b border-slate-100">
                    <input type="hidden" name="type" value="holiday">
                    <input type="hidden" name="is_closed" value="1">
                    <input type="date" name="specific_date" required min="<?= $today ?>" class="px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <input type="text" name="note" placeholder="ชื่อวันหยุด เช่น สงกรานต์" required class="md:col-span-3 px-2 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-800 outline-none">
                    <button type="submit" class="px-3 py-2 bg-rose-500 text-white rounded-lg text-xs font-black hover:bg-rose-600 flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-plus"></i>เพิ่ม
                    </button>
                </form>

                <?php if (empty($holidays)): ?>
                    <p class="py-8 text-center text-xs font-bold text-slate-300 italic">ยังไม่มีวันหยุดที่จะถึง</p>
                <?php else: foreach ($holidays as $h): ?>
                    <div class="flex items-center justify-between py-2.5 border-b border-slate-50 last:border-0"
                        data-id="<?= (int)$h['id'] ?>"
                        data-type="holiday"
                        data-date="<?= htmlspecialchars((string)$h['specific_date'], ENT_QUOTES) ?>"
                        data-open="<?= htmlspecialchars(substr((string)$h['open_time'],0,5), ENT_QUOTES) ?>"
                        data-close="<?= htmlspecialchars(substr((string)$h['close_time'],0,5), ENT_QUOTES) ?>"
                        data-is-closed="<?= (int)$h['is_closed'] ?>"
                        data-note="<?= htmlspecialchars((string)$h['note'], ENT_QUOTES) ?>">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs font-black shrink-0">
                                <?= date('d', strtotime($h['specific_date'])) ?>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-black text-slate-800 truncate"><?= htmlspecialchars($h['note'] ?: '—') ?></p>
                                <p class="text-[10px] font-bold text-slate-400">
                                    <?= date('d M Y', strtotime($h['specific_date'])) ?>
                                    <?php if (!$h['is_closed']): ?>
                                        · <?= substr($h['open_time'],0,5) ?>–<?= substr($h['close_time'],0,5) ?>
                                    <?php else: ?> · ปิดทั้งวัน
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <button onclick="hrEdit(<?= (int)$h['id'] ?>)" class="text-blue-500 hover:bg-blue-50 px-2 py-1 rounded text-xs" title="แก้ไข"><i class="fa-solid fa-pen"></i></button>
                            <button onclick="hrDelete(<?= (int)$h['id'] ?>)" class="text-rose-500 hover:bg-rose-50 px-2 py-1 rounded text-xs" title="ลบ"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Reload while explicitly preserving section + cd_view (sidebar nav can
// strip cd_view via pushState, leaving plain location.reload() landing
// on the card-grid landing instead of staying on this sub-view).
function cdReload(view) {
    const url = new URL(window.location.origin + window.location.pathname + window.location.search);
    url.searchParams.set('section', 'clinic_data');
    url.searchParams.set('cd_view', view);
    window.location.assign(url.toString());
}

async function hrPost(action, data) {
    const fd = new FormData();
    fd.append('entity','hours'); fd.append('action',action); fd.append('csrf_token', portal_CSRF);
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    const r = await fetch('ajax_clinic_master.php', {method:'POST',body:fd});
    return r.json();
}
async function hrAdd(e, type) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const res = await hrPost('add', Object.fromEntries(fd.entries()));
    if (res.ok) { showPortalToast(res.message, 'success'); setTimeout(()=>cdReload('hours'),500); }
    else Swal.fire('Error', res.message, 'error');
}
async function hrDelete(id) {
    const c = await Swal.fire({title:'ลบรายการนี้?', icon:'warning', showCancelButton:true, confirmButtonColor:'#e11d48'});
    if (!c.isConfirmed) return;
    const res = await hrPost('delete', {id});
    if (res.ok) cdReload('hours');
    else Swal.fire('Error', res.message, 'error');
}

async function hrEdit(id) {
    // หา element ที่มี data-id ตรงนี้ (ทั้ง regular badge และ holiday row)
    const el = document.querySelector(`[data-id="${id}"][data-type]`);
    if (!el) { Swal.fire('Error', 'ไม่พบรายการ', 'error'); return; }

    const type = el.dataset.type; // 'regular' หรือ 'holiday'
    const cur = {
        weekday:    el.dataset.weekday || '0',
        date:       el.dataset.date || '',
        open:       el.dataset.open || '',
        close:      el.dataset.close || '',
        isClosed:   el.dataset.isClosed === '1',
        note:       el.dataset.note || '',
    };

    const wdNames = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const inputCls = 'w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none focus:border-purple-400';

    let bodyHtml;
    if (type === 'regular') {
        const wdOpts = wdNames.map((n, i) => `<option value="${i}" ${parseInt(cur.weekday,10) === i ? 'selected' : ''}>${esc(n)}</option>`).join('');
        bodyHtml = `
            <div style="display:grid; gap:.6rem; text-align:left;">
                <div>
                    <label style="font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">วัน</label>
                    <select id="hr-edit-weekday" class="${inputCls}">${wdOpts}</select>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:.5rem;">
                    <div>
                        <label style="font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">เปิด</label>
                        <input id="hr-edit-open" type="time" value="${esc(cur.open)}" class="${inputCls}">
                    </div>
                    <div>
                        <label style="font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">ปิด</label>
                        <input id="hr-edit-close" type="time" value="${esc(cur.close)}" class="${inputCls}">
                    </div>
                </div>
                <div>
                    <label style="font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">หมายเหตุ</label>
                    <input id="hr-edit-note" type="text" value="${esc(cur.note)}" placeholder="เช่น พักเที่ยง, ช่วงเช้า" class="${inputCls}">
                </div>
            </div>
        `;
    } else {
        // holiday / special
        bodyHtml = `
            <div style="display:grid; gap:.6rem; text-align:left;">
                <div>
                    <label style="font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">วันที่</label>
                    <input id="hr-edit-date" type="date" value="${esc(cur.date)}" class="${inputCls}">
                </div>
                <div>
                    <label style="font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">ชื่อวันหยุด *</label>
                    <input id="hr-edit-note" type="text" value="${esc(cur.note)}" placeholder="เช่น สงกรานต์" class="${inputCls}">
                </div>
                <label style="display:flex; align-items:center; gap:.5rem; padding:.5rem; background:#fef2f2; border-radius:.5rem; cursor:pointer;">
                    <input id="hr-edit-closed" type="checkbox" ${cur.isClosed ? 'checked' : ''} style="width:1rem; height:1rem; accent-color:#e11d48;">
                    <span style="font-size:.85rem; font-weight:700; color:#991b1b;">ปิดทั้งวัน</span>
                </label>
                <div id="hr-edit-times" style="display:${cur.isClosed?'none':'grid'}; grid-template-columns:1fr 1fr; gap:.5rem;">
                    <div>
                        <label style="font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">เปิด</label>
                        <input id="hr-edit-open" type="time" value="${esc(cur.open)}" class="${inputCls}">
                    </div>
                    <div>
                        <label style="font-size:.7rem; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">ปิด</label>
                        <input id="hr-edit-close" type="time" value="${esc(cur.close)}" class="${inputCls}">
                    </div>
                </div>
            </div>
        `;
    }

    const result = await Swal.fire({
        title: type === 'regular' ? 'แก้ไขเวลาทำการประจำสัปดาห์' : 'แก้ไขวันหยุดพิเศษ',
        html: bodyHtml,
        width: 480,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-floppy-disk mr-1"></i> บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: type === 'regular' ? '#a855f7' : '#e11d48',
        reverseButtons: true,
        focusConfirm: false,
        didOpen: () => {
            // toggle time fields when "ปิดทั้งวัน" checkbox flips
            const cb = document.getElementById('hr-edit-closed');
            const wrap = document.getElementById('hr-edit-times');
            if (cb && wrap) cb.addEventListener('change', () => wrap.style.display = cb.checked ? 'none' : 'grid');
        },
        preConfirm: () => {
            const v = id => document.getElementById('hr-edit-' + id)?.value || '';
            const note = v('note').trim();
            if (type === 'holiday' && !note) {
                Swal.showValidationMessage('กรุณาระบุชื่อวันหยุด');
                return false;
            }
            if (type === 'holiday' && !v('date')) {
                Swal.showValidationMessage('กรุณาเลือกวันที่');
                return false;
            }
            const isClosedNow = type === 'holiday' && document.getElementById('hr-edit-closed')?.checked;
            const out = { id, note };
            if (type === 'regular') {
                out.weekday    = v('weekday');
                out.open_time  = v('open');
                out.close_time = v('close');
                out.is_closed  = '0';
                if (!out.open_time || !out.close_time) {
                    Swal.showValidationMessage('กรุณากรอกเวลาเปิด-ปิด');
                    return false;
                }
            } else {
                out.specific_date = v('date');
                out.is_closed     = isClosedNow ? '1' : '0';
                if (!isClosedNow) {
                    out.open_time  = v('open');
                    out.close_time = v('close');
                }
            }
            return out;
        },
    });
    if (!result.isConfirmed || !result.value) return;

    const res = await hrPost('edit', result.value);
    if (res.ok) {
        showPortalToast(res.message || 'อัปเดตแล้ว', 'success');
        setTimeout(() => cdReload('hours'), 500);
    } else {
        Swal.fire('Error', res.message || 'อัปเดตไม่สำเร็จ', 'error');
    }
}

async function hrFetchThai() {
    const thisYear = new Date().getFullYear();
    const yearOpts = [thisYear, thisYear+1, thisYear+2]
        .map(y => `<option value="${y}">${y} (พ.ศ. ${y+543})</option>`).join('');

    const { value: year } = await Swal.fire({
        title: 'เลือกปีที่ต้องการดึงวันหยุด',
        html: `<select id="hr-fetch-year" class="swal2-select" style="width:80%; padding:.55rem .8rem; font-size:.9rem; border:1.5px solid #e2e8f0; border-radius:.5rem; font-family:Sarabun,sans-serif;">${yearOpts}</select>
            <p style="font-size:.75rem; color:#64748b; margin-top:.65rem;">ใช้ข้อมูลจาก <a href="https://www.myhora.com" target="_blank" style="color:#10b981; text-decoration:underline;">myhora.com</a> (วันหยุดราชการไทย รวมวันหยุดชดเชย)</p>`,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-magnifying-glass"></i> ค้นหา',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#10b981',
        reverseButtons: true,
        focusConfirm: false,
        preConfirm: () => parseInt(document.getElementById('hr-fetch-year').value, 10),
    });
    if (!year) return;

    Swal.fire({ title: 'กำลังดึงข้อมูล...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const res = await hrPost('fetch_thai_holidays', { year });
    Swal.close();

    if (!res.ok) { Swal.fire('Error', res.message || 'ดึงข้อมูลไม่สำเร็จ', 'error'); return; }
    const rows = res.rows || [];
    if (!rows.length) { Swal.fire('ไม่พบข้อมูล', `ไม่มีวันหยุดสำหรับปี ${year}`, 'info'); return; }

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = d => {
        const dt = new Date(d + 'T00:00:00');
        const days = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
        return `${dt.getDate()}/${dt.getMonth()+1}/${dt.getFullYear()} (${days[dt.getDay()]})`;
    };

    const listHtml = rows.map((r, i) => `
        <label style="display:flex; align-items:center; gap:.6rem; padding:.55rem .6rem; border-bottom:1px solid #f1f5f9; cursor:${r.exists?'not-allowed':'pointer'}; opacity:${r.exists?0.5:1};">
            <input type="checkbox" class="hr-thai-check" data-idx="${i}" ${r.exists?'disabled':'checked'} style="width:1rem; height:1rem; accent-color:#10b981;">
            <div style="flex:1; min-width:0; text-align:left;">
                <div style="font-weight:700; color:#0f172a; font-size:.85rem;">${esc(r.name_th)}</div>
                <div style="font-size:.72rem; color:#64748b;">${fmt(r.date)}${r.name_en ? ' · <span style="color:#94a3b8;">' + esc(r.name_en) + '</span>' : ''}</div>
            </div>
            ${r.exists ? '<span style="font-size:.65rem; padding:.15rem .45rem; border-radius:9999px; background:#fef3c7; color:#92400e; font-weight:700;">นำเข้าแล้ว</span>' : ''}
        </label>
    `).join('');

    const result = await Swal.fire({
        title: `วันหยุดไทย ปี ${year} (${rows.length} รายการ)`,
        html: `
            <div style="display:flex; gap:.5rem; margin-bottom:.6rem; justify-content:flex-end;">
                <button type="button" onclick="document.querySelectorAll('.hr-thai-check:not(:disabled)').forEach(c => c.checked = true)"
                    style="font-size:.75rem; padding:.35rem .65rem; background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; border-radius:.4rem; font-weight:700; cursor:pointer;">เลือกทั้งหมด</button>
                <button type="button" onclick="document.querySelectorAll('.hr-thai-check:not(:disabled)').forEach(c => c.checked = false)"
                    style="font-size:.75rem; padding:.35rem .65rem; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:.4rem; font-weight:700; cursor:pointer;">ล้างเลือก</button>
            </div>
            <div style="max-height:50vh; overflow-y:auto; border:1px solid #e2e8f0; border-radius:.5rem;">${listHtml}</div>
        `,
        width: 600,
        showCancelButton: true,
        confirmButtonText: '<i class="fa-solid fa-download"></i> นำเข้าที่เลือก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#10b981',
        reverseButtons: true,
        focusConfirm: false,
        preConfirm: () => {
            const picks = [];
            document.querySelectorAll('.hr-thai-check:checked:not(:disabled)').forEach(c => picks.push(parseInt(c.dataset.idx, 10)));
            if (picks.length === 0) {
                Swal.showValidationMessage('กรุณาเลือกอย่างน้อย 1 รายการ');
                return false;
            }
            return picks;
        },
    });
    if (!result.isConfirmed || !Array.isArray(result.value)) return;

    const fd = new FormData();
    fd.append('entity', 'hours');
    fd.append('action', 'import_thai_holidays');
    fd.append('csrf_token', portal_CSRF);
    result.value.forEach(idx => {
        fd.append('dates[]', rows[idx].date);
        fd.append('names[]', rows[idx].name_th);
    });

    Swal.fire({ title: 'กำลังนำเข้า...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    const r = await fetch('ajax_clinic_master.php', { method: 'POST', body: fd });
    const data = await r.json();
    Swal.close();

    if (!data.ok) { Swal.fire('Error', data.message || 'นำเข้าไม่สำเร็จ', 'error'); return; }
    await Swal.fire({ icon:'success', title:'นำเข้าสำเร็จ', text: data.message, timer: 2000, showConfirmButton: false });
    cdReload('hours');
}
</script>
