<?php
// user/clinic_schedule.php — ปฏิทินตารางแพทย์ออกตรวจ (read-only สำหรับ user)
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
$tz = new DateTimeZone('Asia/Bangkok');
$today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
$y = max(2020, min(2050, (int)($_GET['y'] ?? date('Y'))));
$m = max(1, min(12, (int)($_GET['m'] ?? date('n'))));

$first      = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $tz);
$daysIn     = (int)$first->format('t');
$startDow   = (int)$first->format('w');
$prevYM     = $first->modify('-1 month');
$nextYM     = $first->modify('+1 month');
$rangeStart = $first->format('Y-m-d');
$rangeEnd   = $first->modify('+1 month -1 day')->format('Y-m-d');

// Filters
$filterStaff = (int)($_GET['staff'] ?? 0);
$filterSvc   = trim((string)($_GET['svc'] ?? ''));

// Load shifts (regular weekly + override/off in this month)
$allShifts = [];
try {
    $params = [':s' => $rangeStart, ':e' => $rangeEnd];
    $extraWhere = '';
    if ($filterStaff > 0) { $extraWhere .= ' AND s.staff_id = :sid'; $params[':sid'] = $filterStaff; }
    if ($filterSvc !== '') { $extraWhere .= ' AND s.service_type = :svc'; $params[':svc'] = $filterSvc; }

    $stmt = $pdo->prepare("
        SELECT s.*, ms.title AS doc_title, ms.full_name AS doc_name, ms.role,
               cr.name AS room_name, cr.code AS room_code
        FROM sys_doctor_schedule s
        JOIN sys_medical_staff ms ON s.staff_id = ms.id
        LEFT JOIN sys_clinic_rooms cr ON s.room_id = cr.id
        WHERE s.is_active = 1 AND ms.is_active = 1
          AND (
              (s.specific_date BETWEEN :s AND :e)
              OR (s.specific_date IS NULL AND s.type = 'regular')
          )
          $extraWhere
        ORDER BY s.start_time ASC
    ");
    $stmt->execute($params);
    $allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

// Holidays this month
$holidaysByDate = [];
try {
    $stmt = $pdo->prepare("SELECT specific_date, note, is_closed, open_time, close_time
        FROM sys_clinic_hours
        WHERE type IN ('holiday','special') AND specific_date BETWEEN :s AND :e");
    $stmt->execute([':s' => $rangeStart, ':e' => $rangeEnd]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $holidaysByDate[$r['specific_date']][] = $r;
    }
} catch (PDOException) {}

// Helper: shifts for date — override (specific_date=this date) wins over regular for same staff
$shiftsFor = function (string $date, int $wd) use ($allShifts): array {
    $overrideStaff = [];
    foreach ($allShifts as $s) {
        if (!empty($s['specific_date']) && $s['specific_date'] === $date) {
            $overrideStaff[(int)$s['staff_id']] = true;
        }
    }
    $out = [];
    foreach ($allShifts as $s) {
        if (!empty($s['specific_date'])) {
            if ($s['specific_date'] === $date && $s['type'] !== 'off') $out[] = $s;
        } else {
            if ((int)$s['weekday'] === $wd && empty($overrideStaff[(int)$s['staff_id']])) $out[] = $s;
        }
    }
    return $out;
};

// Build month grid (with prev/next padding)
$cells = [];
for ($i = 0; $i < $startDow; $i++) {
    $d = $first->modify('-' . ($startDow - $i) . ' day');
    $cells[] = ['date' => $d->format('Y-m-d'), 'day' => (int)$d->format('j'), 'in_month' => false];
}
for ($d = 1; $d <= $daysIn; $d++) {
    $cells[] = ['date' => sprintf('%04d-%02d-%02d', $y, $m, $d), 'day' => $d, 'in_month' => true];
}
while (count($cells) % 7 !== 0) {
    $last = end($cells);
    $next = (new DateTimeImmutable($last['date'], $tz))->modify('+1 day');
    $cells[] = ['date' => $next->format('Y-m-d'), 'day' => (int)$next->format('j'), 'in_month' => false];
}

// Filter dropdowns data
$staffList = [];
$svcTypes  = [];
try {
    $staffList = $pdo->query("SELECT DISTINCT ms.id, ms.title, ms.full_name
        FROM sys_medical_staff ms
        JOIN sys_doctor_schedule s ON s.staff_id = ms.id
        WHERE ms.is_active = 1 AND s.is_active = 1
        ORDER BY ms.full_name")->fetchAll(PDO::FETCH_ASSOC);
    $svcTypes = $pdo->query("SELECT DISTINCT service_type FROM sys_doctor_schedule
        WHERE is_active = 1 AND service_type IS NOT NULL AND service_type <> ''
        ORDER BY service_type")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException) {}

$weekdayShort = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
$monthFullTh  = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

$svcColors = [
    'ตรวจทั่วไป' => 'bg-sky-100 text-sky-700',
    'วัคซีน'      => 'bg-emerald-100 text-emerald-700',
    'ตรวจสุขภาพ' => 'bg-purple-100 text-purple-700',
    'ปรึกษา'     => 'bg-amber-100 text-amber-700',
    'ทันตกรรม'   => 'bg-pink-100 text-pink-700',
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>ตารางแพทย์ออกตรวจ — RSU Medical Clinic</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="../assets/css/tailwind.min.css">
<link rel="stylesheet" href="../assets/css/rsufont.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    body { font-family: 'Sarabun', sans-serif; background: #f8fafc; color: #0f172a; min-height: 100vh; }
    .cs-day-cell { min-height: 84px; }
    @media (min-width: 768px) { .cs-day-cell { min-height: 120px; } }
    .cs-shift-pill {
        display: inline-flex; align-items: center; gap: .25rem;
        padding: .15rem .35rem; border-radius: .35rem;
        font-size: .65rem; font-weight: 800; line-height: 1.15;
        max-width: 100%; overflow: hidden;
    }
    .cs-shift-pill span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-width: 640px) {
        .cs-day-num { font-size: .8rem; }
        .cs-shift-pill { font-size: .58rem; padding: .1rem .3rem; }
    }
</style>
</head>
<body>

<header class="sticky top-0 z-10 bg-white border-b border-slate-200 shadow-sm">
    <div class="max-w-3xl mx-auto px-4 py-3 flex items-center gap-3">
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

<main class="max-w-3xl mx-auto px-3 py-4 space-y-3">

    <!-- Month Nav -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3 flex items-center justify-between gap-2">
        <a href="?y=<?= (int)$prevYM->format('Y') ?>&m=<?= (int)$prevYM->format('n') ?><?= $filterStaff ? '&staff='.$filterStaff : '' ?><?= $filterSvc !== '' ? '&svc='.urlencode($filterSvc) : '' ?>"
           class="w-9 h-9 flex items-center justify-center rounded-xl bg-slate-50 text-slate-600 hover:bg-slate-100">
            <i class="fa-solid fa-chevron-left text-xs"></i>
        </a>
        <div class="flex-1 text-center">
            <p class="text-base font-black text-slate-900"><?= htmlspecialchars($monthFullTh[$m]) ?> <?= $y ?></p>
            <p class="text-[10px] font-bold text-slate-400">พ.ศ. <?= $y + 543 ?></p>
        </div>
        <a href="?y=<?= (int)$nextYM->format('Y') ?>&m=<?= (int)$nextYM->format('n') ?><?= $filterStaff ? '&staff='.$filterStaff : '' ?><?= $filterSvc !== '' ? '&svc='.urlencode($filterSvc) : '' ?>"
           class="w-9 h-9 flex items-center justify-center rounded-xl bg-slate-50 text-slate-600 hover:bg-slate-100">
            <i class="fa-solid fa-chevron-right text-xs"></i>
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3 grid grid-cols-2 gap-2">
        <input type="hidden" name="y" value="<?= $y ?>">
        <input type="hidden" name="m" value="<?= $m ?>">
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

    <!-- Calendar Grid -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="grid grid-cols-7 bg-slate-50 border-b border-slate-100">
            <?php foreach ($weekdayShort as $i => $n): ?>
            <div class="py-2 text-center text-[10px] font-black uppercase tracking-widest <?= $i === 0 || $i === 6 ? 'text-rose-500' : 'text-slate-500' ?>">
                <?= htmlspecialchars($n) ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="grid grid-cols-7">
        <?php foreach ($cells as $cell):
            $date = $cell['date'];
            $wd   = (int)(new DateTimeImmutable($date, $tz))->format('w');
            $isToday   = $date === $today;
            $inMonth   = $cell['in_month'];
            $isWeekend = ($wd === 0 || $wd === 6);

            $holidays = $holidaysByDate[$date] ?? [];
            $allClosed = !empty($holidays) && array_reduce($holidays, fn($c, $h) => $c && (int)$h['is_closed'] === 1, true);

            $shifts = $shiftsFor($date, $wd);

            $bg = '';
            if (!$inMonth)       $bg = 'bg-slate-50/50';
            elseif ($allClosed)  $bg = 'bg-rose-50/40';

            $textCls = $inMonth ? ($isWeekend ? 'text-rose-500' : 'text-slate-700') : 'text-slate-300';
        ?>
            <div class="cs-day-cell <?= $bg ?> border-r border-b border-slate-100 p-1.5 flex flex-col gap-1 <?= $isToday ? 'ring-2 ring-cyan-500 ring-inset z-10 relative' : '' ?>">
                <div class="flex items-center justify-between">
                    <span class="cs-day-num text-xs font-black <?= $textCls ?> <?= $isToday ? '!text-cyan-600' : '' ?>">
                        <?= $cell['day'] ?>
                    </span>
                    <?php if ($inMonth && count($shifts) > 0): ?>
                        <span class="text-[8px] font-black px-1 rounded bg-cyan-100 text-cyan-700"><?= count($shifts) ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!$inMonth): ?>
                <?php elseif ($allClosed): ?>
                    <?php foreach ($holidays as $h): ?>
                    <div class="cs-shift-pill bg-rose-100 text-rose-700">
                        <i class="fa-solid fa-calendar-xmark text-[7px] shrink-0"></i>
                        <span><?= htmlspecialchars($h['note'] ?: 'หยุด') ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php else:
                    foreach (array_slice($shifts, 0, 3) as $s):
                        $cls  = $svcColors[$s['service_type']] ?? 'bg-cyan-100 text-cyan-700';
                        $name = trim(($s['doc_title'] ?? '') . ' ' . ($s['doc_name'] ?? '-'));
                ?>
                    <button type="button"
                            onclick='csShowShift(<?= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                            class="cs-shift-pill <?= $cls ?> w-full text-left active:scale-95 transition-transform"
                            title="<?= htmlspecialchars($name) ?>">
                        <span><?= htmlspecialchars(mb_substr($name, 0, 14)) ?></span>
                    </button>
                    <?php endforeach; ?>
                    <?php if (count($shifts) > 3): ?>
                    <span class="text-[9px] font-black text-cyan-600 pl-0.5">+<?= count($shifts) - 3 ?> ท่าน</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Legend -->
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-3 flex flex-wrap gap-x-3 gap-y-1.5 text-[10px] font-black text-slate-600">
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-sky-100 border border-sky-300"></span>ตรวจทั่วไป</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-emerald-100 border border-emerald-300"></span>วัคซีน</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-purple-100 border border-purple-300"></span>ตรวจสุขภาพ</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-amber-100 border border-amber-300"></span>ปรึกษา</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-pink-100 border border-pink-300"></span>ทันตกรรม</span>
        <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-rose-100 border border-rose-300"></span>วันหยุด</span>
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

    document.getElementById('cs-shift-detail').innerHTML = `
        <div class="text-center pb-3 border-b border-slate-100">
            <div class="w-14 h-14 mx-auto mb-2 rounded-2xl bg-cyan-50 text-cyan-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-user-doctor"></i>
            </div>
            <p class="text-base font-black text-slate-900">${docName}</p>
            <span class="inline-block mt-1.5 px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-black uppercase tracking-widest">${svc}</span>
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
function csCloseShift() {
    document.getElementById('cs-shift-modal').classList.add('hidden');
    document.body.style.overflow = '';
}
</script>

</body>
</html>
