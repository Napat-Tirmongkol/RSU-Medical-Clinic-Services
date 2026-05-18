<?php
// user/clinic_schedule.php — ปฏิทินตารางแพทย์ออกตรวจ (read-only) — FullCalendar month/week/day
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/lang.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$pdo = db();

// Filters
$filterStaff = (int)($_GET['staff'] ?? 0);
$filterSvc   = trim((string)($_GET['svc'] ?? ''));
$initialView = in_array(($_GET['view'] ?? ''), ['month','week','day'], true)
    ? $_GET['view'] : 'week';

// Load shifts (all active schedules — match admin schedule:list semantics
// + clinic_status_helper.php pattern: LEFT JOIN ms WITHOUT filtering on
// ms.is_active. Previously INNER JOIN + ms.is_active=1 caused schedules of
// staff flagged inactive to silently disappear from the user view while
// remaining visible in the admin view → reported as "ข้อมูลไม่ตรงกับ admin").
$allShifts = [];
try {
    $params = [];
    $extraWhere = '';
    if ($filterStaff > 0) { $extraWhere .= ' AND s.staff_id = :sid'; $params[':sid'] = $filterStaff; }
    if ($filterSvc !== '') { $extraWhere .= ' AND s.service_type = :svc'; $params[':svc'] = $filterSvc; }

    $stmt = $pdo->prepare("
        SELECT s.id, s.type, s.weekday, s.specific_date, s.start_time, s.end_time,
               s.staff_id, s.room_id, s.service_type, s.notes, s.recur_end_date,
               ms.title AS doc_title, ms.full_name AS doc_name, ms.role, ms.is_active AS doc_active,
               cr.name AS room_name, cr.code AS room_code
        FROM sys_doctor_schedule s
        LEFT JOIN sys_medical_staff ms ON s.staff_id = ms.id
        LEFT JOIN sys_clinic_rooms cr ON s.room_id = cr.id
        WHERE s.is_active = 1
        $extraWhere
        ORDER BY s.start_time ASC
    ");
    $stmt->execute($params);
    $allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

// All clinic holidays (for background tint + label)
$holidays = [];
try {
    $stmt = $pdo->query("SELECT specific_date, note, is_closed, open_time, close_time
        FROM sys_clinic_hours
        WHERE type IN ('holiday','special') AND specific_date IS NOT NULL");
    $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

// Filter dropdowns data — list every staff who currently has an active
// schedule, regardless of ms.is_active. Keeps the filter aligned with the
// calendar contents (otherwise a doctor visible in the calendar would not
// be selectable in the filter).
$staffList = [];
$svcTypes  = [];
try {
    $staffList = $pdo->query("SELECT DISTINCT ms.id, ms.title, ms.full_name
        FROM sys_medical_staff ms
        INNER JOIN sys_doctor_schedule s ON s.staff_id = ms.id
        WHERE s.is_active = 1
        ORDER BY ms.full_name")->fetchAll(PDO::FETCH_ASSOC);
    $svcTypes = $pdo->query("SELECT DISTINCT service_type FROM sys_doctor_schedule
        WHERE is_active = 1 AND service_type IS NOT NULL AND service_type <> ''
        ORDER BY service_type")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException) {}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ตารางแพทย์ออกตรวจ — RSU Medical Clinic</title>
<link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . htmlspecialchars(SITE_LOGO, ENT_QUOTES, 'UTF-8') : '../favicon.ico?v=' . APP_VERSION ?>">
<link rel="stylesheet" href="../assets/css/tailwind.min.css">
<link rel="stylesheet" href="../assets/css/rsufont.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/locales/th.global.min.js"></script>
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f8fafc; color: #0f172a; min-height: 100vh; }

    /* ── FullCalendar event colors (match portal admin) ────────────── */
    .fc-event { cursor: pointer; border-radius: 6px; padding: 2px 4px; font-weight: 700; }
    .fc-event.svc-default     { background:#0ea5e9; border-color:#0284c7; }
    .fc-event.svc-วัคซีน       { background:#10b981; border-color:#059669; }
    .fc-event.svc-ตรวจสุขภาพ   { background:#a855f7; border-color:#9333ea; }
    .fc-event.svc-ปรึกษา       { background:#f59e0b; border-color:#d97706; }
    .fc-event.svc-ทันตกรรม     { background:#ec4899; border-color:#db2777; }
    .fc-event.svc-off          { background:#94a3b8; border-color:#64748b; opacity:.85; }
    .fc-event .fc-event-title  { white-space: normal; }

    /* Holiday styling */
    .fc-event.cs-holiday-evt {
        background: #fecaca !important;
        border-color: #f87171 !important;
        color: #991b1b !important;
    }
    .fc-event.cs-holiday-evt .fc-event-title,
    .fc-event.cs-holiday-evt .fc-event-time { color: #991b1b !important; }

    /* Make toolbar buttons look softer / mobile-friendly */
    .fc .fc-toolbar.fc-header-toolbar { margin-bottom: .75em; gap:.25em; flex-wrap: wrap; }
    .fc .fc-button {
        background: #fff !important;
        border: 1px solid #e2e8f0 !important;
        color: #475569 !important;
        font-weight: 700;
        box-shadow: 0 1px 2px rgba(15,23,42,.04);
        text-transform: none;
    }
    .fc .fc-button:hover { background: #f1f5f9 !important; }
    .fc .fc-button-primary:not(:disabled).fc-button-active,
    .fc .fc-button-primary:not(:disabled):active {
        background: #0891b2 !important;
        border-color: #0891b2 !important;
        color: #fff !important;
    }
    .fc .fc-toolbar-title { font-size: 1.1rem; font-weight: 800; color: #0f172a; }
    .fc .fc-col-header-cell-cushion { font-weight: 800; color: #475569; padding: 6px 4px; font-size: .8rem; }
    .fc .fc-timegrid-slot-label-cushion { font-size: .7rem; font-weight: 700; color: #94a3b8; }
    .fc .fc-daygrid-day-number { font-weight: 700; color: #475569; }
    .fc-day-today { background: #ecfeff !important; }

    /* Compact toolbar on mobile */
    @media (max-width: 640px) {
        .fc .fc-toolbar.fc-header-toolbar {
            flex-direction: column;
            align-items: stretch;
        }
        .fc .fc-toolbar-chunk { display: flex; justify-content: center; }
        .fc .fc-toolbar-title { font-size: 1rem; text-align: center; }
        .fc .fc-button { padding: .25rem .5rem; font-size: .75rem; }
        .fc .fc-col-header-cell-cushion { font-size: .65rem; padding: 4px 2px; }
        .fc-event { font-size: .65rem; padding: 1px 3px; }
    }
</style>
</head>
<body>

<header class="sticky top-0 z-10 bg-white border-b border-slate-200 shadow-sm">
    <div class="max-w-5xl mx-auto px-4 py-3 flex items-center gap-3">
        <a href="hub.php" class="w-10 h-10 flex items-center justify-center rounded-xl bg-slate-50 text-slate-600 hover:bg-slate-100">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <div class="flex-1 min-w-0">
            <h1 class="text-base font-black text-slate-900 leading-tight">ตารางแพทย์ออกตรวจ</h1>
            <p class="text-[11px] font-bold text-slate-400">ดูรายละเอียดแพทย์ที่ออกตรวจในแต่ละวัน</p>
        </div>
        <div class="w-10 h-10 flex items-center justify-center rounded-xl bg-cyan-50 text-cyan-600">
            <i class="fa-solid fa-user-doctor"></i>
        </div>
    </div>
</header>

<main class="max-w-5xl mx-auto px-3 py-4 space-y-3">

    <!-- Filters -->
    <form method="GET" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3 grid grid-cols-2 gap-2">
        <input type="hidden" name="view" value="<?= htmlspecialchars($initialView) ?>">
        <select name="staff" onchange="this.form.submit()"
            class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none focus:border-cyan-400">
            <option value="0">— ทุกบุคลากร —</option>
            <?php foreach ($staffList as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $filterStaff === (int)$s['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(trim($s['title'].' '.$s['full_name'])) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="svc" onchange="this.form.submit()"
            class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-bold text-slate-700 outline-none focus:border-cyan-400">
            <option value="">— ทุกบริการ —</option>
            <?php foreach ($svcTypes as $svc): ?>
            <option value="<?= htmlspecialchars($svc) ?>" <?= $filterSvc === $svc ? 'selected' : '' ?>>
                <?= htmlspecialchars($svc) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- Calendar -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3 sm:p-4">
        <div id="cs-calendar"></div>
    </div>

    <!-- Legend -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3 flex flex-wrap gap-x-3 gap-y-1.5 text-[10px] font-black text-slate-600">
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#0ea5e9"></span>ตรวจทั่วไป</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#10b981"></span>วัคซีน</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#a855f7"></span>ตรวจสุขภาพ</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#f59e0b"></span>ปรึกษา</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#ec4899"></span>ทันตกรรม</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-rose-200 border border-rose-400"></span>วันหยุด</span>
    </div>

    <p class="text-center text-[10px] font-bold text-slate-400 py-3 px-4 leading-relaxed">
        <i class="fa-solid fa-circle-info mr-1"></i>แตะที่ชื่อแพทย์เพื่อดูรายละเอียด<br>ตารางอาจมีการเปลี่ยนแปลง โปรดยืนยันก่อนเข้ารับบริการ
    </p>
</main>

<!-- Shift Detail Modal -->
<div id="cs-shift-modal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 backdrop-blur-sm p-3"
     onclick="if(event.target===this)csCloseShift()">
    <div class="bg-white rounded-t-3xl sm:rounded-3xl w-full max-w-md shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-black text-slate-900">รายละเอียด</h3>
            <button onclick="csCloseShift()" class="w-9 h-9 flex items-center justify-center rounded-xl bg-slate-50 text-slate-400 hover:bg-slate-100"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div id="cs-shift-detail" class="p-5 space-y-4"></div>
    </div>
</div>

<script>
const CS_SHIFTS   = <?= json_encode($allShifts,  JSON_UNESCAPED_UNICODE) ?>;
const CS_HOLIDAYS = <?= json_encode($holidays,   JSON_UNESCAPED_UNICODE) ?>;
const CS_INITIAL_VIEW_MAP = { month: 'dayGridMonth', week: 'timeGridWeek', day: 'timeGridDay' };
const CS_INITIAL_VIEW = CS_INITIAL_VIEW_MAP[<?= json_encode($initialView) ?>] || 'timeGridWeek';

function csRowToEvent(row) {
    const docName = ((row.doc_title || '') + ' ' + (row.doc_name || '')).trim() || 'แพทย์';
    const room = row.room_name ? ' · ' + (row.room_code || '') + ' ' + row.room_name : '';
    const svcKeyRaw = row.type === 'off' ? 'off' : (row.service_type || '');
    const knownSvc = ['วัคซีน','ตรวจสุขภาพ','ปรึกษา','ทันตกรรม'];
    const svcClass = row.type === 'off'
        ? 'svc-off'
        : (knownSvc.includes(svcKeyRaw) ? 'svc-' + svcKeyRaw : 'svc-default');

    const base = {
        id: String(row.id),
        title: docName + (row.type === 'off' ? ' (ลา)' : '') + room,
        classNames: [svcClass],
        extendedProps: row,
    };

    if (row.type === 'regular') {
        const ev = {
            daysOfWeek: [Number(row.weekday)],
            startTime: row.start_time,
            endTime:   row.end_time,
            startRecur: '2024-01-01',
        };
        if (row.recur_end_date) ev.endRecur = row.recur_end_date;
        return Object.assign(base, ev);
    }
    if (row.type === 'off') {
        return Object.assign(base, {
            start: row.specific_date,
            allDay: true,
        });
    }
    // override
    return Object.assign(base, {
        start: row.specific_date + 'T' + row.start_time,
        end:   row.specific_date + 'T' + row.end_time,
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('cs-calendar');
    if (!el || !window.FullCalendar) return;

    const isMobile = window.matchMedia('(max-width: 640px)').matches;
    const view = isMobile && CS_INITIAL_VIEW === 'timeGridWeek' ? 'timeGridDay' : CS_INITIAL_VIEW;

    // Build events
    const events = [];
    const holidaySet = new Set();
    CS_HOLIDAYS.forEach(h => {
        holidaySet.add(h.specific_date);
        // Background tint (full-day pinkish)
        events.push({
            start: h.specific_date,
            allDay: true,
            display: 'background',
            backgroundColor: '#fee2e2',
            extendedProps: { __holiday: true },
        });
        // Visible label
        events.push({
            title: h.note || 'วันหยุดคลินิก',
            start: h.specific_date,
            allDay: true,
            classNames: ['cs-holiday-evt'],
            editable: false,
            extendedProps: { __holiday: true, holidayInfo: h },
        });
    });
    CS_SHIFTS.forEach(r => events.push(csRowToEvent(r)));

    const calendar = new FullCalendar.Calendar(el, {
        initialView: view,
        locale: 'th',
        firstDay: 0,
        slotMinTime: '07:00:00',
        slotMaxTime: '21:00:00',
        nowIndicator: true,
        height: isMobile ? 'auto' : 720,
        contentHeight: isMobile ? 600 : 'auto',
        expandRows: true,
        editable: false,
        selectable: false,
        dayMaxEvents: 4,
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,timeGridDay',
        },
        buttonText: {
            today: 'วันนี้',
            month: 'เดือน',
            week:  'สัปดาห์',
            day:   'วัน',
        },
        events,
        eventClick: (info) => {
            const ext = info.event.extendedProps || {};
            if (ext.__holiday) {
                // Show holiday info in modal
                const h = ext.holidayInfo || {};
                csShowHoliday(h, info.event.startStr);
                return;
            }
            csShowShift(ext);
        },
        // Note: previously eventClassNames returned ['cs-hidden-on-holiday']
        // for events falling on a clinic-closed day — but the class had no CSS
        // backing (dead code) AND it caused doctor schedules + ลา markers to
        // silently disappear from days marked เทอมเบรค (semester break),
        // even though admin's schedule:list view still shows them. The pink
        // background tint already conveys "clinic closed" to users without
        // hiding the underlying data. Match admin behavior — show everything.
    });
    calendar.render();
});

function cs_localDate(d) {
    const yy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yy}-${mm}-${dd}`;
}

function csShowShift(s) {
    const wdNames = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
    const escHtml = str => String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const docName = escHtml((s.doc_title || '') + ' ' + (s.doc_name || '-')).trim();
    const time = escHtml((s.start_time || '').substring(0,5)) + '–' + escHtml((s.end_time || '').substring(0,5));
    const room = s.room_name
        ? `<span class="font-black text-slate-700">${s.room_code ? escHtml(s.room_code) + ' · ' : ''}${escHtml(s.room_name)}</span>`
        : '<span class="text-slate-400 italic">ไม่ระบุห้อง</span>';
    const svc = escHtml(s.service_type || 'ทั่วไป');
    const recurrence = s.specific_date
        ? `เฉพาะวันที่ <strong>${escHtml(s.specific_date)}</strong>`
        : `ทุกวัน${wdNames[parseInt(s.weekday,10) || 0]}`;
    const notes = s.notes ? escHtml(s.notes) : '';
    const offFlag = s.type === 'off'
        ? '<span class="inline-block px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 text-[10px] font-black uppercase tracking-widest">ลา</span>'
        : '';

    document.getElementById('cs-shift-detail').innerHTML = `
        <div class="text-center pb-3 border-b border-slate-100">
            <div class="w-14 h-14 mx-auto mb-2 rounded-2xl bg-cyan-50 text-cyan-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-user-doctor"></i>
            </div>
            <p class="text-base font-black text-slate-900">${docName}</p>
            <div class="mt-1.5 flex items-center justify-center gap-2">
                <span class="inline-block px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-black uppercase tracking-widest">${svc}</span>
                ${offFlag}
            </div>
        </div>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
            <span class="text-slate-400 font-bold"><i class="fa-regular fa-clock mr-1"></i>เวลา</span>
            <span class="font-black text-slate-700">${time}</span>
            <span class="text-slate-400 font-bold"><i class="fa-solid fa-door-open mr-1"></i>ห้อง</span>
            <span>${room}</span>
            <span class="text-slate-400 font-bold"><i class="fa-solid fa-repeat mr-1"></i>รอบ</span>
            <span class="font-bold text-slate-700">${recurrence}</span>
            ${notes ? `<span class="text-slate-400 font-bold"><i class="fa-solid fa-note-sticky mr-1"></i>หมายเหตุ</span><span class="text-slate-700">${notes}</span>` : ''}
        </div>
        <div class="text-[11px] text-amber-700 bg-amber-50 border border-amber-100 rounded-lg p-2.5 font-bold">
            <i class="fa-solid fa-circle-info mr-1"></i>เวลาออกตรวจอาจมีการเปลี่ยนแปลง โปรดยืนยันที่คลินิกก่อนเข้ารับบริการ
        </div>
    `;
    document.getElementById('cs-shift-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function csShowHoliday(h, dateStr) {
    const escHtml = str => String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const closed  = parseInt(h.is_closed || 0) === 1;
    const hours   = (!closed && h.open_time && h.close_time)
        ? `<span class="font-black text-slate-700">${escHtml(h.open_time.substring(0,5))}–${escHtml(h.close_time.substring(0,5))}</span>`
        : '<span class="font-black text-rose-600">ปิดทำการ</span>';

    document.getElementById('cs-shift-detail').innerHTML = `
        <div class="text-center pb-3 border-b border-slate-100">
            <div class="w-14 h-14 mx-auto mb-2 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-calendar-xmark"></i>
            </div>
            <p class="text-base font-black text-slate-900">${escHtml(h.note || 'วันหยุดคลินิก')}</p>
            <p class="mt-1 text-[11px] font-bold text-slate-400">${escHtml(h.specific_date || dateStr || '')}</p>
        </div>
        <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
            <span class="text-slate-400 font-bold"><i class="fa-regular fa-clock mr-1"></i>สถานะ</span>
            <span>${hours}</span>
        </div>
    `;
    document.getElementById('cs-shift-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function csCloseShift() {
    document.getElementById('cs-shift-modal').classList.add('hidden');
    document.body.style.overflow = '';
}
</script>

</body>
</html>
