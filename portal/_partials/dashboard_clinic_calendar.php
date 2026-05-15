<?php
// portal/_partials/dashboard_clinic_calendar.php
// Compact 7-day clinic schedule widget for the dashboard sidebar.
// Shows: today's status (open/closed/holiday) + doctors on duty,
// and a quick list of the next 6 days. Links out to the full calendar view.

$_pdo = db();
$_tz  = new DateTimeZone('Asia/Bangkok');
$_now = new DateTimeImmutable('today', $_tz);
$_today = $_now->format('Y-m-d');
$_rangeEnd = $_now->modify('+6 day')->format('Y-m-d');

$_regularByWd  = [];   // weekday → rows
$_holidayByDate = [];  // date → rows
$_allShifts    = [];

try {
    $stmt = $_pdo->query("SELECT * FROM sys_clinic_hours WHERE type='regular' ORDER BY weekday, open_time");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $_regularByWd[(int)$r['weekday']][] = $r;
    }
    $stmt = $_pdo->prepare("SELECT * FROM sys_clinic_hours
        WHERE type IN ('holiday','special') AND specific_date BETWEEN :s AND :e");
    $stmt->execute([':s' => $_today, ':e' => $_rangeEnd]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $_holidayByDate[$r['specific_date']][] = $r;
    }
} catch (PDOException) { /* table may not exist yet — degrade silently */ }

try {
    $stmt = $_pdo->prepare("
        SELECT s.*, ms.title AS doc_title, ms.full_name AS doc_name,
               cr.name AS room_name
        FROM sys_doctor_schedule s
        LEFT JOIN sys_medical_staff ms ON s.staff_id = ms.id
        LEFT JOIN sys_clinic_rooms  cr ON s.room_id  = cr.id
        WHERE s.is_active = 1
          AND (s.weekday IS NOT NULL
               OR (s.specific_date BETWEEN :s AND :e))
    ");
    $stmt->execute([':s' => $_today, ':e' => $_rangeEnd]);
    $_allShifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) { /* table may not exist yet */ }

// Resolve which shifts apply to a given date — overrides win over weekly recurring
$_shiftsFor = function (string $date, int $wd) use ($_allShifts): array {
    $out = [];
    foreach ($_allShifts as $s) {
        if (!empty($s['specific_date']) && $s['specific_date'] === $date) {
            $out[] = $s;
            continue;
        }
        if (empty($s['specific_date']) && (int)$s['weekday'] === $wd && $s['type'] === 'regular') {
            $hasOverride = false;
            foreach ($_allShifts as $o) {
                if (!empty($o['specific_date']) && $o['specific_date'] === $date && (int)$o['staff_id'] === (int)$s['staff_id']) {
                    $hasOverride = true; break;
                }
            }
            if (!$hasOverride) $out[] = $s;
        }
    }
    return $out;
};

$_weekdayShortTh = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
$_monthShortTh   = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];

// Build 7 days starting today
$_days = [];
for ($i = 0; $i < 7; $i++) {
    $d = $_now->modify("+{$i} day");
    $dStr = $d->format('Y-m-d');
    $wd = (int)$d->format('w');

    $holidays = $_holidayByDate[$dStr] ?? [];
    $allClosed = !empty($holidays) && array_reduce($holidays, fn($c, $h) => $c && (int)$h['is_closed'] === 1, true);
    $regs = $_regularByWd[$wd] ?? [];
    $shifts = array_values(array_filter($_shiftsFor($dStr, $wd), fn($s) => $s['type'] !== 'off'));

    if ($allClosed) {
        $status = 'holiday';
        $statusLabel = trim((string)($holidays[0]['note'] ?? '')) ?: 'วันหยุด';
        $hours = null;
    } elseif (!empty($holidays) && !$allClosed) {
        $h = $holidays[0];
        $status = 'special';
        $statusLabel = trim((string)($h['note'] ?? '')) ?: 'เปิดพิเศษ';
        $hours = ($h['open_time'] && $h['close_time'])
            ? substr($h['open_time'], 0, 5) . '–' . substr($h['close_time'], 0, 5)
            : null;
    } elseif (!empty($regs)) {
        $status = 'open';
        $statusLabel = 'เปิดทำการ';
        $first = $regs[0];
        $last  = end($regs);
        $hours = substr($first['open_time'], 0, 5) . '–' . substr($last['close_time'], 0, 5);
    } else {
        $status = 'closed';
        $statusLabel = 'ปิดประจำสัปดาห์';
        $hours = null;
    }

    $_days[] = [
        'date'        => $dStr,
        'wd'          => $wd,
        'wd_short'    => $_weekdayShortTh[$wd],
        'day'         => (int)$d->format('j'),
        'month_short' => $_monthShortTh[(int)$d->format('n')],
        'status'      => $status,
        'status_label'=> $statusLabel,
        'hours'       => $hours,
        'shifts'      => $shifts,
        'shift_count' => count($shifts),
    ];
}

$_todayCell = $_days[0];
$_upcoming  = array_slice($_days, 1);
?>

<div class="dash-clinic-cal">
    <div class="sec-title mb-3">
        ปฏิทินคลินิก
        <a href="?section=clinic_data&amp;cd_view=calendar"
           class="ml-auto eyebrow dash-clinic-cal__more"
           title="เปิดปฏิทินเดือนเต็ม">
           ดูทั้งหมด <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>

    <!-- Today card -->
    <div class="dash-clinic-cal__today dash-clinic-cal__today--<?= htmlspecialchars($_todayCell['status']) ?>">
        <div class="dash-clinic-cal__today-head">
            <div class="dash-clinic-cal__today-eyebrow">วันนี้</div>
            <div class="dash-clinic-cal__today-date">
                <?= htmlspecialchars($_todayCell['wd_short']) ?>
                <?= $_todayCell['day'] ?>
                <?= htmlspecialchars($_todayCell['month_short']) ?>
                <?= (int)$_now->format('Y') + 543 ?>
            </div>
        </div>

        <div class="dash-clinic-cal__today-status">
            <?php if ($_todayCell['status'] === 'open'): ?>
                <i class="fa-solid fa-door-open"></i>
                <span><?= htmlspecialchars($_todayCell['hours'] ?? '') ?></span>
            <?php elseif ($_todayCell['status'] === 'special'): ?>
                <i class="fa-solid fa-star"></i>
                <span><?= htmlspecialchars($_todayCell['status_label']) ?><?= $_todayCell['hours'] ? ' · ' . htmlspecialchars($_todayCell['hours']) : '' ?></span>
            <?php elseif ($_todayCell['status'] === 'holiday'): ?>
                <i class="fa-solid fa-calendar-xmark"></i>
                <span><?= htmlspecialchars($_todayCell['status_label']) ?></span>
            <?php else: ?>
                <i class="fa-solid fa-ban"></i>
                <span>ปิดประจำสัปดาห์</span>
            <?php endif; ?>
        </div>

        <?php if ($_todayCell['shift_count'] > 0): ?>
            <div class="dash-clinic-cal__doctors">
                <?php
                $visible = array_slice($_todayCell['shifts'], 0, 3);
                $extra = $_todayCell['shift_count'] - count($visible);
                foreach ($visible as $s):
                    $name = trim(($s['doc_title'] ?? '') . ' ' . ($s['doc_name'] ?? '-'));
                    $time = ($s['start_time'] && $s['end_time'])
                        ? substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5)
                        : '';
                ?>
                    <div class="dash-clinic-cal__doctor" title="<?= htmlspecialchars($name . ($time ? ' ' . $time : '')) ?>">
                        <i class="fa-solid fa-user-doctor"></i>
                        <span class="dash-clinic-cal__doctor-name"><?= htmlspecialchars($name) ?></span>
                        <?php if ($time): ?>
                            <span class="dash-clinic-cal__doctor-time"><?= htmlspecialchars($time) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($extra > 0): ?>
                    <div class="dash-clinic-cal__doctor-extra">+ <?= $extra ?> ท่าน</div>
                <?php endif; ?>
            </div>
        <?php elseif ($_todayCell['status'] === 'open'): ?>
            <div class="dash-clinic-cal__doctors-empty">
                <i class="fa-regular fa-user"></i> ยังไม่มีตารางแพทย์วันนี้
            </div>
        <?php endif; ?>
    </div>

    <!-- Upcoming 6 days -->
    <ul class="dash-clinic-cal__list">
        <?php foreach ($_upcoming as $d):
            $cls = 'dash-clinic-cal__row dash-clinic-cal__row--' . $d['status'];
        ?>
            <li class="<?= $cls ?>">
                <div class="dash-clinic-cal__row-date">
                    <span class="dash-clinic-cal__row-wd"><?= htmlspecialchars($d['wd_short']) ?></span>
                    <span class="dash-clinic-cal__row-day"><?= $d['day'] ?></span>
                    <span class="dash-clinic-cal__row-mon"><?= htmlspecialchars($d['month_short']) ?></span>
                </div>
                <div class="dash-clinic-cal__row-body">
                    <?php if ($d['status'] === 'holiday'): ?>
                        <span class="dash-clinic-cal__row-label dash-clinic-cal__row-label--holiday">
                            <i class="fa-solid fa-calendar-xmark"></i>
                            <?= htmlspecialchars($d['status_label']) ?>
                        </span>
                    <?php elseif ($d['status'] === 'closed'): ?>
                        <span class="dash-clinic-cal__row-label dash-clinic-cal__row-label--closed">
                            <i class="fa-solid fa-ban"></i> ปิด
                        </span>
                    <?php else: ?>
                        <span class="dash-clinic-cal__row-hours">
                            <i class="fa-regular fa-clock"></i>
                            <?= htmlspecialchars($d['hours'] ?? '—') ?>
                        </span>
                        <?php if ($d['shift_count'] > 0): ?>
                            <span class="dash-clinic-cal__row-docs">
                                <i class="fa-solid fa-user-doctor"></i> <?= $d['shift_count'] ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<style>
/* dashboard mini clinic calendar — scoped to .dash-clinic-cal */
.dash-clinic-cal { display: flex; flex-direction: column; }
.dash-clinic-cal__more {
    display: inline-flex; align-items: center; gap: 4px;
    color: #2e9e63; text-decoration: none; transition: color .15s;
}
.dash-clinic-cal__more:hover { color: #1f7a4a; }
.dash-clinic-cal__more i { font-size: 9px; }

.dash-clinic-cal__today {
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 12px;
    border: 1px solid #e2e8f0;
    background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
    position: relative;
    overflow: hidden;
}
.dash-clinic-cal__today--holiday {
    background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%);
    border-color: #fecdd3;
}
.dash-clinic-cal__today--closed {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-color: #e2e8f0;
}
.dash-clinic-cal__today--special {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border-color: #fde68a;
}

.dash-clinic-cal__today-head {
    display: flex; align-items: baseline; gap: 8px;
    margin-bottom: 8px;
}
.dash-clinic-cal__today-eyebrow {
    font-size: 9px; font-weight: 900; letter-spacing: .25em;
    text-transform: uppercase; color: #64748b;
    padding: 2px 8px; border-radius: 999px;
    background: rgba(255,255,255,.7); border: 1px solid rgba(15,23,42,.06);
}
.dash-clinic-cal__today-date {
    font-size: 13px; font-weight: 800; color: #0f172a;
}

.dash-clinic-cal__today-status {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 14px; font-weight: 800;
    color: #047857;
    margin-bottom: 10px;
}
.dash-clinic-cal__today--holiday .dash-clinic-cal__today-status { color: #be123c; }
.dash-clinic-cal__today--closed  .dash-clinic-cal__today-status { color: #475569; }
.dash-clinic-cal__today--special .dash-clinic-cal__today-status { color: #b45309; }
.dash-clinic-cal__today-status i { font-size: 13px; }

.dash-clinic-cal__doctors {
    display: flex; flex-direction: column; gap: 4px;
    padding-top: 8px; border-top: 1px dashed rgba(15,23,42,.08);
}
.dash-clinic-cal__doctor {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 700; color: #1e3a8a;
    background: rgba(255,255,255,.7);
    padding: 4px 8px; border-radius: 8px;
    border: 1px solid rgba(30,64,175,.1);
}
.dash-clinic-cal__doctor i { font-size: 10px; color: #2563eb; flex-shrink: 0; }
.dash-clinic-cal__doctor-name {
    flex: 1; min-width: 0;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.dash-clinic-cal__doctor-time {
    font-size: 10px; font-weight: 800;
    color: #475569; flex-shrink: 0;
}
.dash-clinic-cal__doctor-extra {
    font-size: 11px; font-weight: 800; color: #2563eb;
    padding-left: 8px;
}
.dash-clinic-cal__doctors-empty {
    padding-top: 8px; border-top: 1px dashed rgba(15,23,42,.08);
    font-size: 11px; font-weight: 700; color: #94a3b8; font-style: italic;
}

.dash-clinic-cal__list {
    list-style: none; margin: 0; padding: 0;
    display: flex; flex-direction: column; gap: 1px;
    background: #f1f5f9;
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
}
.dash-clinic-cal__row {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px;
    background: #fff;
    transition: background .15s;
}
.dash-clinic-cal__row:hover { background: #f8fafc; }

.dash-clinic-cal__row--holiday { background: #fef2f2; }
.dash-clinic-cal__row--holiday:hover { background: #fee2e2; }
.dash-clinic-cal__row--closed  { background: #f8fafc; }

.dash-clinic-cal__row-date {
    display: flex; align-items: baseline; gap: 4px;
    width: 70px; flex-shrink: 0;
}
.dash-clinic-cal__row-wd {
    font-size: 10px; font-weight: 900; color: #94a3b8;
    text-transform: uppercase; letter-spacing: .05em;
    min-width: 22px;
}
.dash-clinic-cal__row--holiday .dash-clinic-cal__row-wd,
.dash-clinic-cal__row-wd:first-letter { color: #94a3b8; }
.dash-clinic-cal__row-day {
    font-size: 14px; font-weight: 900; color: #0f172a;
}
.dash-clinic-cal__row-mon {
    font-size: 10px; font-weight: 700; color: #94a3b8;
}

.dash-clinic-cal__row-body {
    flex: 1; min-width: 0;
    display: flex; align-items: center; gap: 8px;
    justify-content: flex-end;
    font-size: 11px; font-weight: 800;
}
.dash-clinic-cal__row-hours {
    display: inline-flex; align-items: center; gap: 5px;
    color: #047857;
}
.dash-clinic-cal__row-hours i { font-size: 9px; }
.dash-clinic-cal__row-docs {
    display: inline-flex; align-items: center; gap: 4px;
    background: #eff6ff; color: #1d4ed8;
    padding: 2px 7px; border-radius: 999px;
    border: 1px solid #dbeafe;
    font-size: 10px;
}
.dash-clinic-cal__row-docs i { font-size: 9px; }
.dash-clinic-cal__row-label {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 800;
}
.dash-clinic-cal__row-label--holiday { color: #be123c; }
.dash-clinic-cal__row-label--closed  { color: #94a3b8; }
.dash-clinic-cal__row-label i { font-size: 9px; }

/* Sundays + Saturdays in the upcoming list — gentle weekend tint on weekday label */
.dash-clinic-cal__row[data-weekend="1"] .dash-clinic-cal__row-wd { color: #fb7185; }

/* ── Dark mode ──────────────────────────────────────────── */
body[data-theme='dark'] .dash-clinic-cal__more { color: #6ee7b7; }
body[data-theme='dark'] .dash-clinic-cal__more:hover { color: #a7f3d0; }

body[data-theme='dark'] .dash-clinic-cal__today {
    background: linear-gradient(135deg, #0f2a1e 0%, #14302b 100%);
    border-color: #1f4d3a;
}
body[data-theme='dark'] .dash-clinic-cal__today--holiday {
    background: linear-gradient(135deg, #2a0e1a 0%, #3a1426 100%);
    border-color: #6b1e3a;
}
body[data-theme='dark'] .dash-clinic-cal__today--closed {
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    border-color: #334155;
}
body[data-theme='dark'] .dash-clinic-cal__today--special {
    background: linear-gradient(135deg, #2a200c 0%, #3a2a14 100%);
    border-color: #6b4e1a;
}
body[data-theme='dark'] .dash-clinic-cal__today-eyebrow {
    color: #cbd5e1;
    background: rgba(255,255,255,.08);
    border-color: rgba(255,255,255,.10);
}
body[data-theme='dark'] .dash-clinic-cal__today-date { color: #f1f5f9; }
body[data-theme='dark'] .dash-clinic-cal__today-status { color: #6ee7b7; }
body[data-theme='dark'] .dash-clinic-cal__today--holiday .dash-clinic-cal__today-status { color: #fb7185; }
body[data-theme='dark'] .dash-clinic-cal__today--closed  .dash-clinic-cal__today-status { color: #94a3b8; }
body[data-theme='dark'] .dash-clinic-cal__today--special .dash-clinic-cal__today-status { color: #fbbf24; }

body[data-theme='dark'] .dash-clinic-cal__doctors { border-top-color: rgba(255,255,255,.12); }
body[data-theme='dark'] .dash-clinic-cal__doctor { color: #e2e8f0; }
body[data-theme='dark'] .dash-clinic-cal__doctor i { color: #60a5fa; }
body[data-theme='dark'] .dash-clinic-cal__doctor-name { color: #f1f5f9; }
body[data-theme='dark'] .dash-clinic-cal__doctor-time { color: #94a3b8; }
body[data-theme='dark'] .dash-clinic-cal__doctor-extra { color: #94a3b8; }
body[data-theme='dark'] .dash-clinic-cal__doctors-empty { color: #64748b; }

body[data-theme='dark'] .dash-clinic-cal__list {
    background: #334155;
    border-color: #334155;
}
body[data-theme='dark'] .dash-clinic-cal__row {
    background: #1e293b;
    border-bottom-color: #334155;
    color: #e2e8f0;
}
body[data-theme='dark'] .dash-clinic-cal__row:hover { background: #0f172a; }
body[data-theme='dark'] .dash-clinic-cal__row--holiday { background: #2a0e1a; }
body[data-theme='dark'] .dash-clinic-cal__row--holiday:hover { background: #3a1426; }
body[data-theme='dark'] .dash-clinic-cal__row--closed { background: #0f172a; }
body[data-theme='dark'] .dash-clinic-cal__row-wd { color: #cbd5e1; }
body[data-theme='dark'] .dash-clinic-cal__row-wd:first-letter { color: #64748b; }
body[data-theme='dark'] .dash-clinic-cal__row-day { color: #f1f5f9; }
body[data-theme='dark'] .dash-clinic-cal__row-mon { color: #94a3b8; }
body[data-theme='dark'] .dash-clinic-cal__row-hours { color: #6ee7b7; }
body[data-theme='dark'] .dash-clinic-cal__row-docs { color: #94a3b8; }
body[data-theme='dark'] .dash-clinic-cal__row-label--holiday { color: #fb7185; }
body[data-theme='dark'] .dash-clinic-cal__row-label--closed  { color: #64748b; }
body[data-theme='dark'] .dash-clinic-cal__row[data-weekend="1"] .dash-clinic-cal__row-wd { color: #fb7185; }
</style>
