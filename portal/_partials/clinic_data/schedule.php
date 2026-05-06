<?php
// Sub-view: Doctor Schedule (drag-drop calendar)
$pdo = db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doctor_schedule (
        id INT AUTO_INCREMENT PRIMARY KEY,
        staff_id INT NOT NULL,
        type ENUM('regular','override','off') NOT NULL DEFAULT 'regular',
        weekday TINYINT NULL,
        specific_date DATE NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        room_id INT NULL,
        service_type VARCHAR(100) NULL,
        notes VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_staff (staff_id),
        INDEX idx_weekday (weekday),
        INDEX idx_date (specific_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException) {}

// Pre-fetch dropdowns
$staffList = [];
$roomsList = [];
try {
    $staffList = $pdo->query("SELECT id, title, full_name, role FROM sys_medical_staff WHERE is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $roomsList = $pdo->query("SELECT id, code, name, type FROM sys_clinic_rooms WHERE is_active = 1 ORDER BY type, code")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$serviceTypes = ['ตรวจทั่วไป', 'วัคซีน', 'ตรวจสุขภาพ', 'ปรึกษา', 'ทันตกรรม', 'อื่นๆ'];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<style>
    .fc { font-family: inherit; font-size: 13px; }
    .fc .fc-toolbar-title { font-weight: 900; color: #1e293b; }
    .fc .fc-button-primary {
        background: #fff; border-color: #e5e7eb; color: #475569;
        font-weight: 800; font-size: 12px; padding: 6px 14px;
    }
    .fc .fc-button-primary:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .fc .fc-button-primary:not(:disabled).fc-button-active,
    .fc .fc-button-primary:not(:disabled):active { background: #0e7490; border-color: #0e7490; }
    .fc-event { cursor: pointer; border-radius: 6px; padding: 2px 4px; font-weight: 700; }
    .fc-event.svc-ตรวจทั่วไป { background:#0ea5e9; border-color:#0284c7; }
    .fc-event.svc-วัคซีน      { background:#10b981; border-color:#059669; }
    .fc-event.svc-ตรวจสุขภาพ  { background:#a855f7; border-color:#9333ea; }
    .fc-event.svc-ปรึกษา      { background:#f59e0b; border-color:#d97706; }
    .fc-event.svc-ทันตกรรม    { background:#ec4899; border-color:#db2777; }
    .fc-event.svc-off         { background:#94a3b8; border-color:#64748b; opacity:.85; }

    /* Doctor palette draggable cards */
    .ds-doc-card {
        cursor: grab; user-select: none;
        transition: transform .15s, box-shadow .15s, border-color .15s;
    }
    .ds-doc-card:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(14,116,144,.15); border-color: #06b6d4; }
    .ds-doc-card:active { cursor: grabbing; transform: scale(.98); }
    .ds-doc-card.fc-event { background: transparent; border: none; padding: 0; } /* override fc default */
    .fc-highlight { background: #ecfeff !important; border: 2px dashed #06b6d4 !important; }
</style>

<div class="max-w-[1400px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-cyan-50 rounded-xl shadow-sm border border-cyan-100 flex items-center justify-center text-cyan-600 text-xl">
            <i class="fa-solid fa-user-clock"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">ตารางแพทย์ออกตรวจ</h2>
            <p class="text-slate-500 text-sm font-medium">drag-drop เพื่อย้าย shift · คลิก slot ว่างเพื่อเพิ่ม · คลิก event เพื่อแก้/ลบ</p>
        </div>
        <button onclick="dsOpenAdd()" class="px-4 py-2 bg-cyan-600 text-white rounded-xl text-sm font-black hover:bg-cyan-700 transition-all flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-plus"></i>เพิ่ม shift
        </button>
    </div>

    <!-- Legend -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 mb-4 flex flex-wrap items-center gap-3 text-[11px]">
        <span class="font-black text-slate-500 uppercase tracking-widest mr-2">Service:</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-sky-500"></span>ตรวจทั่วไป</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-emerald-500"></span>วัคซีน</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-purple-500"></span>ตรวจสุขภาพ</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-amber-500"></span>ปรึกษา</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-pink-500"></span>ทันตกรรม</span>
        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-slate-400"></span>ลา/หยุด</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-4">

        <!-- Doctor Palette (drag source) -->
        <aside class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 lg:sticky lg:top-4 lg:self-start lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto">
            <div class="flex items-center justify-between mb-3 px-1">
                <h4 class="text-[11px] font-black uppercase tracking-widest text-slate-500">
                    <i class="fa-solid fa-hand-pointer text-cyan-500 mr-1"></i> ลากแพทย์ลงปฏิทิน
                </h4>
                <span class="text-[10px] font-bold text-slate-400"><?= count($staffList) ?> ท่าน</span>
            </div>
            <div class="mb-2.5">
                <input type="text" id="ds-doc-search" placeholder="ค้นหาแพทย์..."
                    class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-700 outline-none focus:border-cyan-400">
            </div>
            <div id="ds-doc-list" class="space-y-1.5">
                <?php if (empty($staffList)): ?>
                <p class="text-xs text-slate-400 italic text-center py-6">ยังไม่มีบุคลากร — ไป<a href="?section=clinic_data&cd_view=staff" class="text-cyan-600 underline">เพิ่มบุคลากร</a></p>
                <?php else: foreach ($staffList as $s):
                    $roleColors = [
                        'doctor'=>['bg'=>'#dbeafe','fg'=>'#1d4ed8','label'=>'แพทย์'],
                        'nurse'=>['bg'=>'#fce7f3','fg'=>'#be185d','label'=>'พยาบาล'],
                        'pharmacist'=>['bg'=>'#dcfce7','fg'=>'#15803d','label'=>'เภสัช'],
                        'dentist'=>['bg'=>'#fef3c7','fg'=>'#a16207','label'=>'ทันตะ'],
                        'other'=>['bg'=>'#e2e8f0','fg'=>'#475569','label'=>'อื่นๆ'],
                    ];
                    $rc = $roleColors[$s['role']] ?? $roleColors['other'];
                ?>
                <div class="ds-doc-card flex items-center gap-2 px-2.5 py-2 bg-white border border-slate-200 rounded-lg"
                    data-staff-id="<?= (int)$s['id'] ?>"
                    data-staff-name="<?= htmlspecialchars(trim($s['title'].' '.$s['full_name']), ENT_QUOTES) ?>"
                    data-staff-search="<?= htmlspecialchars(mb_strtolower(trim($s['title'].' '.$s['full_name'])), ENT_QUOTES) ?>">
                    <div class="w-7 h-7 rounded-md flex items-center justify-center text-[10px] font-black shrink-0" style="background:<?= $rc['bg'] ?>; color:<?= $rc['fg'] ?>;">
                        <i class="fa-solid fa-user-doctor text-[10px]"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-[12px] font-black text-slate-800 truncate leading-tight"><?= htmlspecialchars(trim($s['title'].' '.$s['full_name'])) ?></div>
                        <div class="text-[9px] font-black uppercase tracking-wider" style="color:<?= $rc['fg'] ?>"><?= htmlspecialchars($rc['label']) ?></div>
                    </div>
                    <i class="fa-solid fa-grip-vertical text-slate-300 text-[10px]"></i>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="mt-3 pt-3 border-t border-slate-100 text-[10px] text-slate-400 leading-relaxed px-1">
                <p class="mb-1"><i class="fa-solid fa-circle-info mr-1"></i>ลาก card ลง slot บนปฏิทิน</p>
                <p>ระบบสร้าง shift 1 ชม. ที่เวลาที่วาง — แก้รายละเอียดเพิ่มเติมได้</p>
            </div>
        </aside>

        <!-- Calendar -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 min-w-0">
            <div id="ds-calendar"></div>
        </div>
    </div>
</div>

<!-- Edit/Add Modal -->
<div id="ds-modal" class="hidden fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 id="ds-modal-title" class="font-black text-slate-800">เพิ่ม shift</h3>
            <button onclick="dsCloseModal()" class="text-slate-400 hover:text-slate-700"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="ds-form" onsubmit="dsSave(event)" class="p-6 space-y-4">
            <input type="hidden" name="id" id="ds-id">

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ประเภท *</label>
                <div class="grid grid-cols-3 gap-1.5 p-1 bg-slate-50 border border-slate-200 rounded-xl">
                    <?php foreach (['regular'=>'ทุกสัปดาห์', 'override'=>'แทนที่ (เฉพาะวัน)', 'off'=>'ลา/หยุด'] as $k => $l): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="type" value="<?= $k ?>" class="peer hidden" <?= $k === 'regular' ? 'checked' : '' ?> onchange="dsToggleType()">
                        <div class="text-center py-2 rounded-lg text-[11px] font-black text-slate-500 peer-checked:bg-white peer-checked:text-cyan-700 peer-checked:shadow-sm transition-all"><?= $l ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="ds-field-weekday">
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">วันในสัปดาห์ *</label>
                <select name="weekday" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                    <?php foreach (['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'] as $i=>$n): ?>
                        <option value="<?= $i ?>"><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="ds-field-date" class="hidden">
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">วันที่ *</label>
                <input type="date" name="specific_date" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            </div>

            <div id="ds-field-time" class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">เริ่ม *</label>
                    <input type="time" name="start_time" value="09:00" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">สิ้นสุด *</label>
                    <input type="time" name="end_time" value="12:00" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                </div>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">แพทย์ *</label>
                <select name="staff_id" required class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                    <option value="">— เลือกแพทย์ —</option>
                    <?php foreach ($staffList as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars(trim($s['title'].' '.$s['full_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ห้อง</label>
                <select name="room_id" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($roomsList as $r): ?>
                        <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['code'].' · '.$r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">ประเภทบริการ</label>
                <select name="service_type" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($serviceTypes as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">หมายเหตุ</label>
                <input type="text" name="notes" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-bold text-slate-800 outline-none">
            </div>

            <div class="flex gap-2 pt-3 border-t border-slate-100">
                <button type="button" id="ds-delete-btn" onclick="dsDelete()" class="hidden flex-1 px-4 py-2.5 bg-rose-50 text-rose-600 border border-rose-200 rounded-xl text-sm font-black hover:bg-rose-100 transition-all">
                    <i class="fa-solid fa-trash"></i> ลบ
                </button>
                <button type="button" onclick="dsCloseModal()" class="flex-1 px-4 py-2.5 bg-slate-100 text-slate-600 rounded-xl text-sm font-black hover:bg-slate-200 transition-all">ยกเลิก</button>
                <button type="submit" class="flex-[2] px-4 py-2.5 bg-cyan-600 text-white rounded-xl text-sm font-black hover:bg-cyan-700 transition-all shadow-sm">
                    <i class="fa-solid fa-save"></i> บันทึก
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const STAFF_LIST = <?= json_encode($staffList, JSON_UNESCAPED_UNICODE) ?>;
const ROOMS_LIST = <?= json_encode($roomsList, JSON_UNESCAPED_UNICODE) ?>;
let dsCalendar = null;
let dsRows = [];

// ── Local time helpers (avoid toISOString() UTC shift in Asia/Bangkok) ──
function dsPad2(n) { return String(n).padStart(2, '0'); }
function dsLocalDate(d) {
    return `${d.getFullYear()}-${dsPad2(d.getMonth()+1)}-${dsPad2(d.getDate())}`;
}
function dsLocalTime(d) {
    return `${dsPad2(d.getHours())}:${dsPad2(d.getMinutes())}`;
}
function dsAddMin(d, mins) {
    return new Date(d.getTime() + mins * 60000);
}

async function dsPost(action, data) {
    const fd = new FormData();
    fd.append('entity', 'schedule'); fd.append('action', action); fd.append('csrf_token', portal_CSRF);
    Object.entries(data).forEach(([k,v]) => fd.append(k, v ?? ''));
    const res = await fetch('ajax_clinic_master.php', { method: 'POST', body: fd });
    return res.json();
}

function rowToEvent(row) {
    const staff = STAFF_LIST.find(s => +s.id === +row.staff_id);
    const docName = row.doc_name || (staff ? (staff.title + ' ' + staff.full_name).trim() : 'แพทย์');
    const room = row.room_name ? ' · ' + (row.room_code || '') + ' ' + row.room_name : '';
    const svcKey = row.type === 'off' ? 'off' : (row.service_type || 'ตรวจทั่วไป');

    const base = {
        id: String(row.id),
        title: docName + (row.type === 'off' ? ' (ลา)' : '') + room,
        classNames: ['svc-' + svcKey],
        extendedProps: row,
    };

    if (row.type === 'regular') {
        return Object.assign(base, {
            daysOfWeek: [Number(row.weekday)],
            startTime: row.start_time,
            endTime:   row.end_time,
            startRecur: '2024-01-01',
        });
    }
    if (row.type === 'off') {
        return Object.assign(base, {
            start: row.specific_date,
            allDay: true,
            display: 'background',
            classNames: ['svc-off'],
        });
    }
    // override
    return Object.assign(base, {
        start: row.specific_date + 'T' + row.start_time,
        end:   row.specific_date + 'T' + row.end_time,
    });
}

async function dsLoadAndRender() {
    const res = await dsPost('list', {});
    if (!res.ok) { Swal.fire('Error', res.message || 'โหลดไม่สำเร็จ', 'error'); return; }
    dsRows = res.rows;
    dsCalendar.removeAllEvents();
    dsRows.forEach(r => dsCalendar.addEvent(rowToEvent(r)));
}

function dsToggleType() {
    const t = document.querySelector('[name=type]:checked').value;
    document.getElementById('ds-field-weekday').classList.toggle('hidden', t !== 'regular');
    document.getElementById('ds-field-date').classList.toggle('hidden', t === 'regular');
    document.getElementById('ds-field-time').classList.toggle('hidden', t === 'off');
    document.querySelector('[name=specific_date]').required = (t !== 'regular');
}

function dsOpenAdd(prefill = {}) {
    document.getElementById('ds-modal-title').textContent = 'เพิ่ม shift';
    document.getElementById('ds-id').value = '';
    document.getElementById('ds-delete-btn').classList.add('hidden');
    document.getElementById('ds-form').reset();
    document.querySelector('[name=type][value=regular]').checked = true;
    if (prefill.weekday !== undefined) document.querySelector('[name=weekday]').value = prefill.weekday;
    if (prefill.date) {
        document.querySelector('[name=type][value=override]').checked = true;
        document.querySelector('[name=specific_date]').value = prefill.date;
    }
    if (prefill.time) document.querySelector('[name=start_time]').value = prefill.time;
    dsToggleType();
    document.getElementById('ds-modal').classList.remove('hidden');
    document.getElementById('ds-modal').classList.add('flex');
}

function dsOpenEdit(row) {
    document.getElementById('ds-modal-title').textContent = 'แก้ shift #' + row.id;
    document.getElementById('ds-id').value = row.id;
    document.getElementById('ds-delete-btn').classList.remove('hidden');
    document.querySelector(`[name=type][value=${row.type}]`).checked = true;
    document.querySelector('[name=weekday]').value = row.weekday ?? 0;
    document.querySelector('[name=specific_date]').value = row.specific_date ?? '';
    document.querySelector('[name=start_time]').value = (row.start_time || '').substring(0,5);
    document.querySelector('[name=end_time]').value   = (row.end_time   || '').substring(0,5);
    document.querySelector('[name=staff_id]').value = row.staff_id;
    document.querySelector('[name=room_id]').value = row.room_id ?? '';
    document.querySelector('[name=service_type]').value = row.service_type ?? '';
    document.querySelector('[name=notes]').value = row.notes ?? '';
    dsToggleType();
    document.getElementById('ds-modal').classList.remove('hidden');
    document.getElementById('ds-modal').classList.add('flex');
}

function dsCloseModal() {
    document.getElementById('ds-modal').classList.add('hidden');
    document.getElementById('ds-modal').classList.remove('flex');
}

async function dsSave(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd.entries());
    const action = data.id ? 'update' : 'add';
    const res = await dsPost(action, data);
    if (res.ok) {
        showPortalToast(res.message, 'success');
        dsCloseModal();
        dsLoadAndRender();
    } else {
        Swal.fire('Error', res.message || 'บันทึกไม่สำเร็จ', 'error');
    }
}

async function dsDelete() {
    const id = document.getElementById('ds-id').value;
    if (!id) return;
    const c = await Swal.fire({title:'ลบ shift นี้?', icon:'warning', showCancelButton:true, confirmButtonColor:'#e11d48'});
    if (!c.isConfirmed) return;
    const res = await dsPost('delete', {id});
    if (res.ok) { showPortalToast('ลบแล้ว', 'success'); dsCloseModal(); dsLoadAndRender(); }
    else Swal.fire('Error', res.message, 'error');
}

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('ds-calendar');
    if (!el || !window.FullCalendar) return;

    dsCalendar = new FullCalendar.Calendar(el, {
        initialView: 'timeGridWeek',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
        locale: 'th',
        firstDay: 0,
        slotMinTime: '07:00:00',
        slotMaxTime: '21:00:00',
        nowIndicator: true,
        height: 700,
        editable: true,
        selectable: true,
        eventClick: (info) => {
            const row = dsRows.find(r => +r.id === +info.event.id);
            if (row) dsOpenEdit(row);
        },
        dateClick: (info) => {
            // Clicking on empty slot opens add modal pre-filled with date+time
            dsOpenAdd({ date: info.dateStr.substring(0,10), time: info.dateStr.substring(11,16) || '09:00' });
        },
        droppable: true, // Accept external drag from doctor palette
        drop: async (info) => {
            const el = info.draggedEl;
            const staffId = el?.dataset?.staffId;
            const staffName = el?.dataset?.staffName || 'แพทย์';
            if (!staffId) return;

            // ถ้า drop ใน month view (allDay) ใช้ default 09:00–10:00
            // ถ้า week/day view ใช้เวลาที่วาง + 60 นาที
            const start = info.date;
            const isAllDay = info.allDay;
            const startTime = isAllDay ? '09:00' : dsLocalTime(start);
            const endTime   = isAllDay ? '10:00' : dsLocalTime(dsAddMin(start, 60));

            const data = {
                type: 'override',
                specific_date: dsLocalDate(start),
                start_time: startTime,
                end_time:   endTime,
                staff_id:   staffId,
                service_type: 'ตรวจทั่วไป',
            };
            const res = await dsPost('add', data);
            if (res.ok) {
                showPortalToast(`เพิ่ม shift ${staffName} แล้ว`, 'success');
                dsLoadAndRender();
            } else {
                Swal.fire('Error', res.message || 'เพิ่ม shift ไม่สำเร็จ', 'error');
            }
        },
        eventDrop: async (info) => {
            const row = dsRows.find(r => +r.id === +info.event.id);
            if (!row) return;
            const newStart = info.event.start;
            const newEnd   = info.event.end || new Date(newStart.getTime() + 60*60*1000);
            const data = { id: row.id };
            if (row.type === 'regular') {
                data.weekday = newStart.getDay();
                data.start_time = dsLocalTime(newStart);
                data.end_time   = dsLocalTime(newEnd);
            } else {
                data.specific_date = dsLocalDate(newStart);
                data.start_time = dsLocalTime(newStart);
                data.end_time   = dsLocalTime(newEnd);
            }
            // Carry over other fields
            data.staff_id = row.staff_id;
            data.room_id  = row.room_id || '';
            data.service_type = row.service_type || '';
            data.notes = row.notes || '';
            const res = await dsPost('update', data);
            if (res.ok) showPortalToast('ย้าย shift แล้ว', 'success');
            else { Swal.fire('Error', res.message, 'error'); info.revert(); }
            dsLoadAndRender();
        },
        eventResize: async (info) => {
            const row = dsRows.find(r => +r.id === +info.event.id);
            if (!row) return;
            const data = {
                id: row.id,
                start_time: dsLocalTime(info.event.start),
                end_time:   dsLocalTime(info.event.end),
                staff_id:   row.staff_id,
            };
            if (row.type === 'regular') data.weekday = row.weekday;
            else data.specific_date = dsLocalDate(new Date(row.specific_date));
            data.room_id = row.room_id || '';
            data.service_type = row.service_type || '';
            data.notes = row.notes || '';
            const res = await dsPost('update', data);
            if (res.ok) showPortalToast('ปรับเวลาแล้ว', 'success');
            else { Swal.fire('Error', res.message, 'error'); info.revert(); }
        },
    });
    dsCalendar.render();
    dsLoadAndRender();

    // ── External draggable: doctor palette → calendar ──
    const palette = document.getElementById('ds-doc-list');
    if (palette && window.FullCalendar?.Draggable) {
        new FullCalendar.Draggable(palette, {
            itemSelector: '.ds-doc-card',
            eventData: function (el) {
                return {
                    title: el.dataset.staffName || 'แพทย์',
                    duration: '01:00',
                    create: false, // เราจัดการสร้าง event ผ่าน drop handler เอง
                };
            },
        });
    }

    // ── Doctor palette search filter ──
    const search = document.getElementById('ds-doc-search');
    if (search) {
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            document.querySelectorAll('.ds-doc-card').forEach(card => {
                const name = (card.dataset.staffSearch || '').toLowerCase();
                card.style.display = (q === '' || name.includes(q)) ? '' : 'none';
            });
        });
    }
});
</script>
