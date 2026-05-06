<?php
// Sub-view: Clinic Calendar — month view รวม regular hours + holidays + doctor shifts
$pdo = db();

// Year/Month from query (default = current)
$tz = new DateTimeZone('Asia/Bangkok');
$today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
$y = (int)($_GET['y'] ?? date('Y'));
$m = (int)($_GET['m'] ?? date('n'));
if ($m < 1 || $m > 12) $m = (int)date('n');
if ($y < 2020 || $y > 2050) $y = (int)date('Y');

$first    = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $tz);
$daysIn   = (int)$first->format('t');
$startDow = (int)$first->format('w'); // 0=Sun..6=Sat
$prevYM   = $first->modify('-1 month');
$nextYM   = $first->modify('+1 month');
$rangeStart = $first->format('Y-m-d');
$rangeEnd   = $first->modify('+1 month -1 day')->format('Y-m-d');

// ── Load data ──────────────────────────────────────────────────────────────
$regularByWd = [];   // weekday → [{open_time, close_time, note}, ...]
$holidaysByDate = []; // 'YYYY-MM-DD' → [{note, is_closed, open_time, close_time}]
$shiftsByDate   = []; // 'YYYY-MM-DD' → [{title, full_name, start_time, end_time, room_name}]

try {
    $stmt = $pdo->query("SELECT * FROM sys_clinic_hours WHERE type='regular' ORDER BY weekday, open_time");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $regularByWd[(int)$r['weekday']][] = $r;
    }
    $stmt = $pdo->prepare("SELECT * FROM sys_clinic_hours
        WHERE type IN ('holiday','special') AND specific_date BETWEEN :s AND :e");
    $stmt->execute([':s' => $rangeStart, ':e' => $rangeEnd]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $holidaysByDate[$r['specific_date']][] = $r;
    }
} catch (PDOException) {}

// Doctor shifts (regular weekday + override + off) — only if table exists
try {
    $stmt = $pdo->prepare("
        SELECT s.*, ms.title AS doc_title, ms.full_name AS doc_name,
               cr.name AS room_name
        FROM sys_doctor_schedule s
        LEFT JOIN sys_medical_staff ms ON s.staff_id = ms.id
        LEFT JOIN sys_clinic_rooms  cr ON s.room_id  = cr.id
        WHERE s.is_active = 1
          AND (s.weekday IS NOT NULL
               OR (s.specific_date BETWEEN :s AND :e))
    ");
    $stmt->execute([':s' => $rangeStart, ':e' => $rangeEnd]);
    $allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {
    $allShifts = [];
}

$weekdayNames     = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
$weekdayShort     = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
$monthNamesTh     = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

// Helper: list of shifts for a specific date
$shiftsFor = function (string $date, int $wd) use ($allShifts): array {
    $out = [];
    foreach ($allShifts as $s) {
        if (!empty($s['specific_date']) && $s['specific_date'] === $date) {
            $out[] = $s; // override / off — wins over weekly
            continue;
        }
        if (empty($s['specific_date']) && (int)$s['weekday'] === $wd && $s['type'] === 'regular') {
            // Check no override exists for this date+staff
            $hasOverride = false;
            foreach ($allShifts as $o) {
                if (!empty($o['specific_date']) && $o['specific_date'] === $date && (int)$o['staff_id'] === (int)$s['staff_id']) {
                    $hasOverride = true; break;
                }
            }
            if (!$hasOverride) $out[] = $s;
        }
    }
    return $out;
};

// Build cells (with prev-month padding so first row starts on Sunday)
$cells = [];
for ($i = 0; $i < $startDow; $i++) {
    $d = $first->modify('-' . ($startDow - $i) . ' day');
    $cells[] = ['date' => $d->format('Y-m-d'), 'day' => (int)$d->format('j'), 'in_month' => false];
}
for ($d = 1; $d <= $daysIn; $d++) {
    $dStr = sprintf('%04d-%02d-%02d', $y, $m, $d);
    $cells[] = ['date' => $dStr, 'day' => $d, 'in_month' => true];
}
// Pad to multiple of 7
while (count($cells) % 7 !== 0) {
    $last = end($cells);
    $next = (new DateTimeImmutable($last['date'], $tz))->modify('+1 day');
    $cells[] = ['date' => $next->format('Y-m-d'), 'day' => (int)$next->format('j'), 'in_month' => false];
}
?>
<div class="max-w-[1400px] mx-auto px-4 py-6">
    <a href="?section=clinic_data" class="inline-flex items-center gap-2 text-xs font-black text-slate-500 hover:text-emerald-600 mb-4">
        <i class="fa-solid fa-chevron-left text-[10px]"></i>กลับ
    </a>

    <div class="mb-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-cyan-50 rounded-xl shadow-sm border border-cyan-100 flex items-center justify-center text-cyan-600 text-xl">
            <i class="fa-solid fa-calendar"></i>
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="text-2xl font-black text-slate-800">ปฏิทินคลินิก</h2>
            <p class="text-slate-500 text-sm font-medium">ภาพรวมรายเดือน — เวลาเปิด-ปิด, วันหยุด, แพทย์ออกตรวจ</p>
        </div>
    </div>

    <!-- Month nav + legend -->
    <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden mb-5">
        <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <a href="?section=clinic_data&cd_view=calendar&y=<?= (int)$prevYM->format('Y') ?>&m=<?= (int)$prevYM->format('n') ?>"
                    class="w-9 h-9 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-100 hover:border-cyan-300 transition-all">
                    <i class="fa-solid fa-chevron-left text-xs"></i>
                </a>
                <a href="?section=clinic_data&cd_view=calendar"
                    class="px-3 h-9 flex items-center bg-white border border-slate-200 rounded-xl text-xs font-black text-slate-600 hover:bg-slate-100 hover:border-cyan-300 transition-all">
                    วันนี้
                </a>
                <a href="?section=clinic_data&cd_view=calendar&y=<?= (int)$nextYM->format('Y') ?>&m=<?= (int)$nextYM->format('n') ?>"
                    class="w-9 h-9 flex items-center justify-center bg-white border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-100 hover:border-cyan-300 transition-all">
                    <i class="fa-solid fa-chevron-right text-xs"></i>
                </a>
                <h3 class="ml-3 text-lg font-black text-slate-800">
                    <?= htmlspecialchars($monthNamesTh[$m]) ?> <?= $y ?>
                    <span class="text-sm font-bold text-slate-400 ml-1.5">(พ.ศ. <?= $y + 543 ?>)</span>
                </h3>
            </div>

            <div class="flex flex-wrap items-center gap-3 text-[10px] font-black text-slate-500">
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded bg-emerald-100 border border-emerald-200"></span> เปิดทำการ
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded bg-rose-100 border border-rose-200"></span> วันหยุด
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded bg-slate-100 border border-slate-300"></span> ปิดประจำ
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-3 h-3 rounded bg-cyan-100 border-2 border-cyan-500"></span> วันนี้
                </span>
            </div>
        </div>

        <!-- Weekday header -->
        <div class="grid grid-cols-7 bg-slate-50/60 border-b border-slate-100">
            <?php foreach ($weekdayNames as $i => $n):
                $isWeekend = ($i === 0 || $i === 6);
            ?>
                <div class="px-3 py-2.5 text-center text-[11px] font-black uppercase tracking-widest <?= $isWeekend ? 'text-rose-500' : 'text-slate-500' ?>">
                    <?= htmlspecialchars($n) ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Calendar grid -->
        <div class="grid grid-cols-7">
            <?php foreach ($cells as $idx => $cell):
                $date = $cell['date'];
                $wd   = (int)(new DateTimeImmutable($date, $tz))->format('w');
                $isToday   = $date === $today;
                $isInMonth = $cell['in_month'];
                $isPast    = $date < $today;

                $holidays = $holidaysByDate[$date] ?? [];
                $hasHoliday = !empty($holidays);
                $allClosed  = $hasHoliday && array_reduce($holidays, fn($c, $h) => $c && (int)$h['is_closed'] === 1, true);

                $regs = $regularByWd[$wd] ?? [];
                $hasRegular = !empty($regs);

                $shifts = $shiftsFor($date, $wd);
                $shiftCount = count(array_filter($shifts, fn($s) => $s['type'] !== 'off'));

                // Cell background
                $bg = 'bg-white';
                $borderClasses = 'border-r border-b border-slate-100';
                if (!$isInMonth) {
                    $bg = 'bg-slate-50/50';
                } elseif ($allClosed) {
                    $bg = 'bg-rose-50/60';
                } elseif (!$hasRegular) {
                    $bg = 'bg-slate-50/40';
                } elseif ($wd === 0 || $wd === 6) {
                    $bg = 'bg-emerald-50/30';
                }

                $ringClass = $isToday ? 'ring-2 ring-cyan-500 ring-inset z-10 relative' : '';
                $textClass = $isInMonth ? ($isPast ? 'text-slate-400' : 'text-slate-700') : 'text-slate-300';
            ?>
            <div class="<?= $bg ?> <?= $borderClasses ?> <?= $ringClass ?> min-h-[120px] p-2 flex flex-col gap-1.5 hover:bg-slate-50 transition-colors">
                <!-- Date number row -->
                <div class="flex items-center justify-between">
                    <span class="text-sm font-black <?= $textClass ?> <?= $isToday ? '!text-cyan-600' : '' ?>">
                        <?= $cell['day'] ?>
                    </span>
                    <?php if ($shiftCount > 0): ?>
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md bg-blue-50 text-blue-700 border border-blue-100 text-[9px] font-black">
                            <i class="fa-solid fa-user-doctor text-[8px]"></i> <?= $shiftCount ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!$isInMonth): // Don't render details for prev/next month padding ?>
                <?php elseif ($hasHoliday): ?>
                    <?php foreach ($holidays as $h): ?>
                    <div class="text-[10px] leading-tight px-1.5 py-1 rounded bg-rose-100 text-rose-700 border border-rose-200 font-black truncate" title="<?= htmlspecialchars($h['note'] ?? '') ?>">
                        <i class="fa-solid fa-calendar-xmark text-[8px] mr-0.5"></i>
                        <?= htmlspecialchars($h['note'] ?: 'วันหยุด') ?>
                    </div>
                    <?php if (!(int)$h['is_closed'] && $h['open_time']): ?>
                        <div class="text-[10px] text-slate-500 font-bold">
                            <?= substr($h['open_time'],0,5) ?>–<?= substr($h['close_time'],0,5) ?>
                        </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                <?php elseif ($hasRegular): ?>
                    <?php foreach (array_slice($regs, 0, 2) as $r): ?>
                    <div class="text-[10px] leading-tight px-1.5 py-1 rounded bg-emerald-50 text-emerald-700 border border-emerald-100 font-bold truncate"
                        title="<?= htmlspecialchars(($r['note'] ?? '') . ' ' . substr($r['open_time'],0,5) . '–' . substr($r['close_time'],0,5)) ?>">
                        <i class="fa-regular fa-clock text-[8px] mr-0.5"></i>
                        <?= substr($r['open_time'],0,5) ?>–<?= substr($r['close_time'],0,5) ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($regs) > 2): ?>
                    <div class="text-[9px] text-slate-400 font-bold">+<?= count($regs) - 2 ?> ช่วง</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-[10px] text-slate-400 italic font-bold">ปิด</div>
                <?php endif; ?>

                <!-- Doctor shift names (max 2) -->
                <?php if ($isInMonth && !empty($shifts)):
                    $visibleShifts = array_slice(array_filter($shifts, fn($s) => $s['type'] !== 'off'), 0, 2);
                    $extraCount = max(0, $shiftCount - count($visibleShifts));
                ?>
                    <?php foreach ($visibleShifts as $s): ?>
                    <div class="text-[9px] leading-tight px-1 py-0.5 rounded bg-blue-50/60 text-blue-700 truncate font-bold"
                        title="<?= htmlspecialchars(trim(($s['doc_title'] ?? '') . ' ' . ($s['doc_name'] ?? '-'))) ?>">
                        <?= htmlspecialchars(mb_substr(trim(($s['doc_title'] ?? '') . ' ' . ($s['doc_name'] ?? '-')), 0, 18)) ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($extraCount > 0): ?>
                    <div class="text-[9px] text-blue-500 font-black">+<?= $extraCount ?> ท่าน</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Summary stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <?php
        $openDays   = 0; $closedDays = 0; $holidayCnt = 0; $shiftTotal = 0;
        foreach ($cells as $cell) {
            if (!$cell['in_month']) continue;
            $d = $cell['date'];
            $wd = (int)(new DateTimeImmutable($d, $tz))->format('w');
            $hs = $holidaysByDate[$d] ?? [];
            $allC = !empty($hs) && array_reduce($hs, fn($c, $h) => $c && (int)$h['is_closed'] === 1, true);
            $rs = $regularByWd[$wd] ?? [];
            if ($allC) { $closedDays++; $holidayCnt++; }
            elseif (!empty($hs)) $openDays++;
            elseif (!empty($rs)) $openDays++;
            else $closedDays++;
            $shiftTotal += count(array_filter($shiftsFor($d, $wd), fn($s) => $s['type'] !== 'off'));
        }
        $stats = [
            ['label'=>'วันเปิดทำการ', 'val'=>$openDays,   'icon'=>'fa-door-open',     'color'=>'#10b981', 'bg'=>'#ecfdf5'],
            ['label'=>'วันหยุด',       'val'=>$holidayCnt, 'icon'=>'fa-calendar-xmark', 'color'=>'#e11d48', 'bg'=>'#fef2f2'],
            ['label'=>'วันปิด',        'val'=>$closedDays, 'icon'=>'fa-ban',            'color'=>'#64748b', 'bg'=>'#f8fafc'],
            ['label'=>'shift แพทย์',    'val'=>$shiftTotal, 'icon'=>'fa-user-doctor',    'color'=>'#3b82f6', 'bg'=>'#eff6ff'],
        ];
        foreach ($stats as $s): ?>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center gap-4">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-xl shrink-0" style="background:<?= $s['bg'] ?>; color:<?= $s['color'] ?>;">
                <i class="fa-solid <?= $s['icon'] ?>"></i>
            </div>
            <div class="min-w-0">
                <p class="text-2xl font-black text-slate-800"><?= number_format($s['val']) ?></p>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-0.5"><?= htmlspecialchars($s['label']) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Quick links -->
    <div class="mt-5 p-4 rounded-2xl bg-slate-50 border border-slate-200 flex items-center gap-3 text-xs font-bold text-slate-600">
        <i class="fa-solid fa-circle-info text-slate-400"></i>
        <span>แก้ไขเวลาที่</span>
        <a href="?section=clinic_data&cd_view=hours" class="px-2.5 py-1 rounded-lg bg-white border border-slate-200 hover:border-purple-300 hover:bg-purple-50 hover:text-purple-700 transition-all">
            <i class="fa-solid fa-calendar-days mr-1"></i>วันหยุด/ชั่วโมงทำการ
        </a>
        <a href="?section=clinic_data&cd_view=schedule" class="px-2.5 py-1 rounded-lg bg-white border border-slate-200 hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-700 transition-all">
            <i class="fa-solid fa-user-clock mr-1"></i>ตารางแพทย์
        </a>
    </div>
</div>
