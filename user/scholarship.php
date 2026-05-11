<?php
// user/scholarship.php — หน้าเก็บชั่วโมงทำงานนักศึกษาทุน
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/scholarship_helper.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$pdo = db();
ensure_scholarship_schema($pdo);

// ดึง user_id จาก LINE
$user = null;
try {
    $stmt = $pdo->prepare("SELECT id, full_name, first_name, last_name, picture_url FROM sys_users WHERE line_user_id = :lid LIMIT 1");
    $stmt->execute([':lid' => $lineUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log($e->getMessage()); }

if (!$user) {
    header('Location: index.php');
    exit;
}

$userId = (int)$user['id'];
$_SESSION['user_id'] = $userId;

$student = get_scholarship_student_by_user($pdo, $userId);
$settings = get_scholarship_settings($pdo);
$now = time();
$today = date('Y-m-d', $now);

$todayShifts = $student ? get_scholarship_shifts_for_date($pdo, (int)$student['id'], $today) : [];
$activeShift = $student ? find_active_scholarship_shift($pdo, (int)$student['id'], 'now') : null;
$lastLog = $student ? get_latest_scholarship_log($pdo, (int)$student['id']) : null;

// ตารางที่ลงไว้ล่วงหน้า (admin assign แล้ว — ไม่ใช่จองจาก slot)
$upcomingShifts = [];
if ($student) {
    $stmt = $pdo->prepare("SELECT * FROM sys_scholarship_shifts
        WHERE student_id = :sid AND status != 'cancelled' AND shift_date > :today
          AND slot_id IS NULL
        ORDER BY shift_date ASC, start_time ASC LIMIT 10");
    $stmt->execute([':sid' => $student['id'], ':today' => $today]);
    $upcomingShifts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// รอบที่นักศึกษาจองไว้ (จาก sys_scholarship_slots)
$myBookings = $student ? get_student_slot_bookings($pdo, (int)$student['id'], true) : [];

// คำนวณ state ปัจจุบัน:
// - ถ้า log ล่าสุดเป็น clock_in (status=approved/pending) → user กำลัง "ทำงาน" → ปุ่มต่อไป = ออกงาน
// - ถ้าไม่มี log หรือล่าสุดเป็น clock_out → ปุ่มต่อไป = เข้างาน
$nextAction = 'clock_in';
if ($lastLog && $lastLog['action'] === 'clock_in' && $lastLog['status'] !== 'rejected') {
    $nextAction = 'clock_out';
}

// ชั่วโมงสะสมเดือนนี้ + รวมทั้งหมด (แยกประเภท: ทุน vs ค่าตอบแทน)
$monthFrom = date('Y-m-01', $now);
$monthTo = date('Y-m-t', $now);
$splitMonth = $student ? sum_scholarship_hours_split($pdo, (int)$student['id'], $monthFrom, $monthTo) : ['hours' => 0, 'paid' => 0];
$splitTotal = $student ? sum_scholarship_hours_split($pdo, (int)$student['id']) : ['hours' => 0, 'paid' => 0];
$hoursMonth = $splitMonth['hours'] + $splitMonth['paid'];
$hoursTotal = $splitTotal['hours'] + $splitTotal['paid'];

// log 10 รายการล่าสุด
$recentLogs = [];
if ($student) {
    $stmt = $pdo->prepare("SELECT * FROM sys_scholarship_clock_logs
        WHERE student_id = :sid ORDER BY event_at DESC, id DESC LIMIT 10");
    $stmt->execute([':sid' => $student['id']]);
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$csrfToken = get_csrf_token();

function vh(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function thai_weekday(string $date): string {
    $w = (int)date('w', strtotime($date));
    $names = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
    return $names[$w] ?? '';
}

$avatarUrl = !empty($user['picture_url'])
    ? $user['picture_url']
    : 'https://ui-avatars.com/api/?background=2e9e63&color=fff&name=' . urlencode($user['full_name'] ?: 'User');

$displayName = trim((string)($user['full_name'] ?? ''))
    ?: trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''))
    ?: 'นักศึกษา';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>เก็บชั่วโมงทุน - RSU Medical</title>
    <link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . vh(SITE_LOGO) : '../favicon.ico?v=' . APP_VERSION ?>">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; background: #F8FAFF; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .clock-btn-in {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 20px 40px rgba(16, 185, 129, 0.35);
        }
        .clock-btn-out {
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            box-shadow: 0 20px 40px rgba(244, 63, 94, 0.35);
        }
        .clock-btn-disabled {
            background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
            box-shadow: 0 10px 20px rgba(100, 116, 139, 0.2);
            cursor: not-allowed;
        }
        .pulse-ring::before {
            content: ''; position: absolute; inset: -8px; border-radius: 9999px;
            border: 3px solid currentColor; opacity: .3;
            animation: pulseRing 2s ease-out infinite;
        }
        @keyframes pulseRing {
            0% { transform: scale(.95); opacity: .5; }
            100% { transform: scale(1.15); opacity: 0; }
        }
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1.5px solid #e2e8f0;
            border-radius: 1.25rem;
        }
        .live-clock {
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body class="text-slate-900 pb-32">

<div class="max-w-md mx-auto relative min-h-screen">

    <!-- Header -->
    <header class="glass-header sticky top-0 z-50 px-6 py-5 flex items-center justify-between border-b border-slate-100">
        <button onclick="window.location.href='hub.php'" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition">
            <i class="fa-solid fa-chevron-left"></i>
        </button>
        <h1 class="text-base font-black text-slate-900">เก็บชั่วโมงทุน</h1>
        <button onclick="window.location.href='scholarship_history.php'" class="w-11 h-11 flex items-center justify-center bg-slate-50 rounded-2xl text-slate-400 active:scale-90 transition" title="ประวัติ">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </button>
    </header>

    <main class="px-5 pt-6 space-y-5">

    <?php if (!$student): ?>
        <!-- ─── ไม่ใช่นักศึกษาทุน ─── -->
        <div class="bg-white rounded-3xl p-8 text-center shadow-sm border border-slate-100">
            <div class="w-20 h-20 mx-auto rounded-full bg-amber-50 flex items-center justify-center mb-4">
                <i class="fa-solid fa-graduation-cap text-3xl text-amber-500"></i>
            </div>
            <h2 class="text-lg font-black text-slate-900 mb-2">ยังไม่ได้ลงทะเบียนทุน</h2>
            <p class="text-sm text-slate-500 leading-relaxed">บัญชีนี้ยังไม่ได้ถูกเพิ่มเป็นนักศึกษาทุนของคลินิก<br>กรุณาติดต่อเจ้าหน้าที่เพื่อลงทะเบียน</p>
            <button onclick="window.location.href='hub.php'" class="mt-6 px-6 py-3 bg-slate-900 text-white rounded-2xl font-black text-sm active:scale-95 transition">
                <i class="fa-solid fa-arrow-left mr-2"></i>กลับหน้าหลัก
            </button>
        </div>
    <?php elseif ($student['status'] !== 'active'): ?>
        <!-- ─── ทุนถูกระงับ ─── -->
        <div class="bg-white rounded-3xl p-8 text-center shadow-sm border border-rose-100">
            <div class="w-20 h-20 mx-auto rounded-full bg-rose-50 flex items-center justify-center mb-4">
                <i class="fa-solid fa-circle-pause text-3xl text-rose-500"></i>
            </div>
            <h2 class="text-lg font-black text-slate-900 mb-2">บัญชีทุนถูกระงับ</h2>
            <p class="text-sm text-slate-500">กรุณาติดต่อเจ้าหน้าที่เพื่อสอบถาม</p>
        </div>
    <?php else: ?>

        <!-- ─── Profile Card ─── -->
        <div class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-3xl p-5 text-white shadow-xl shadow-slate-300/30">
            <div class="flex items-center gap-3">
                <img src="<?= vh($avatarUrl) ?>" class="w-14 h-14 rounded-2xl border-2 border-white/20" alt="">
                <div class="flex-1 min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-widest text-emerald-300">นักศึกษาทุน</p>
                    <h2 class="text-base font-black truncate"><?= vh($displayName) ?></h2>
                    <p class="text-xs text-slate-300 truncate">
                        <?php if ($student['student_code']): ?><i class="fa-solid fa-id-badge mr-1"></i><?= vh($student['student_code']) ?><?php endif; ?>
                        <?php if ($student['faculty']): ?> · <?= vh($student['faculty']) ?><?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- ─── Live Clock + Today's Shift ─── -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100">
            <div class="text-center mb-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">เวลาปัจจุบัน</p>
                <div id="live-clock" class="live-clock text-4xl font-black text-slate-900">--:--:--</div>
                <p class="text-xs text-slate-500 mt-1"><?= vh(thai_weekday($today)) ?> · <?= vh(format_scholarship_thai_date($today)) ?></p>
            </div>

            <div class="flex items-center justify-between mb-2">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">กะวันนี้</p>
                <button onclick="openShiftModal()" class="text-xs font-black text-emerald-600 active:scale-95 transition">
                    <i class="fa-solid fa-plus mr-1"></i>ลงตาราง
                </button>
            </div>
            <?php if (empty($todayShifts)): ?>
                <div class="bg-slate-50 rounded-2xl p-4 text-center">
                    <i class="fa-solid fa-calendar-day text-2xl text-slate-300 mb-2"></i>
                    <p class="text-sm font-bold text-slate-500">วันนี้ยังไม่มีกะ</p>
                    <p class="text-xs text-slate-400 mt-1">เข้างานได้เลย หรือกด "ลงตาราง" เพื่อระบุเวลา</p>
                </div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($todayShifts as $sh): ?>
                        <?php
                            $isActive = $activeShift && (int)$activeShift['id'] === (int)$sh['id'];
                            $shiftBg = $isActive ? 'bg-emerald-50 border-emerald-300' : 'bg-slate-50 border-slate-200';
                            $shiftText = $isActive ? 'text-emerald-700' : 'text-slate-700';
                        ?>
                        <div class="rounded-2xl border-2 <?= $shiftBg ?> p-3 flex items-center justify-between">
                            <div>
                                <p class="text-base font-black <?= $shiftText ?>">
                                    <?= vh(substr((string)$sh['start_time'], 0, 5)) ?> – <?= vh(substr((string)$sh['end_time'], 0, 5)) ?>
                                </p>
                                <?php if ($sh['notes']): ?>
                                    <p class="text-xs text-slate-500"><?= vh($sh['notes']) ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if ($isActive): ?>
                                <span class="px-3 py-1 bg-emerald-500 text-white text-[10px] font-black rounded-full uppercase tracking-wider">
                                    <i class="fa-solid fa-circle-check mr-1"></i>กะปัจจุบัน
                                </span>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 font-bold">
                                    <?= (int)$sh['planned_hours'] ? number_format((float)$sh['planned_hours'], 1) . ' ชม.' : '-' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ─── Clock In/Out Big Button ─── -->
        <div class="text-center py-2">
            <?php
                // ปุ่มเข้า/ออกงาน — ad-hoc: เข้าได้ตลอด ตราบใดที่ยังไม่เข้าค้างอยู่
                $btnDisabled = false;
                if ($nextAction === 'clock_in') {
                    $btnClass = 'clock-btn-in';
                    $btnLabel = $activeShift ? 'เข้างาน' : 'เริ่มงาน';
                    $btnIcon = 'fa-right-to-bracket';
                } else {
                    $btnClass = 'clock-btn-out';
                    $btnLabel = $activeShift ? 'ออกงาน' : 'ออกงาน';
                    $btnIcon = 'fa-right-from-bracket';
                }
            ?>
            <button id="clock-btn"
                    data-action="<?= vh($nextAction) ?>"
                    <?= $btnDisabled ? 'disabled' : '' ?>
                    class="<?= $btnClass ?> relative w-44 h-44 mx-auto rounded-full text-white font-black active:scale-95 transition-transform <?= !$btnDisabled ? 'pulse-ring' : '' ?>">
                <i class="fa-solid <?= $btnIcon ?> text-4xl block mb-2"></i>
                <span class="text-base"><?= vh($btnLabel) ?></span>
            </button>
            <p class="text-xs text-slate-400 mt-3 px-8">
                <?php if (!empty($settings['gps_required'])): ?>
                    <i class="fa-solid fa-location-dot mr-1"></i>
                    ระบบจะขอตำแหน่ง GPS เพื่อยืนยันว่าอยู่ในคลินิก (รัศมี <?= (int)$settings['radius_m'] ?> ม.)
                <?php else: ?>
                    <i class="fa-solid fa-user-check mr-1"></i>
                    เจ้าหน้าที่จะตรวจสอบและอนุมัติการเข้า-ออกงานด้วยตนเอง
                <?php endif; ?>
            </p>
        </div>

        <!-- ─── Hours Stats (แยก ทุน / ค่าตอบแทน) ─── -->
        <?php
            $payRate = (float)($settings['pay_rate_per_hour'] ?? 0);
            $payMonth = $splitMonth['paid'] * $payRate;
        ?>
        <div class="space-y-3">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 px-1">สรุปชั่วโมงเดือนนี้</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="stat-card p-4 border-l-4 border-l-emerald-400">
                    <p class="text-[10px] font-black uppercase tracking-widest text-emerald-600"><i class="fa-solid fa-graduation-cap mr-1"></i>ส่งชั่วโมงทุน</p>
                    <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format($splitMonth['hours'], 1) ?></p>
                    <p class="text-xs text-slate-500">ชั่วโมง</p>
                </div>
                <div class="stat-card p-4 border-l-4 border-l-amber-400">
                    <p class="text-[10px] font-black uppercase tracking-widest text-amber-600"><i class="fa-solid fa-coins mr-1"></i>ค่าตอบแทน</p>
                    <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format($splitMonth['paid'], 1) ?></p>
                    <p class="text-xs text-slate-500">ชั่วโมง</p>
                </div>
            </div>
            <?php if ($payRate > 0): ?>
                <div class="bg-gradient-to-r from-amber-50 to-orange-50 border border-amber-200 rounded-2xl px-4 py-3 flex items-center justify-between">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-amber-700"><i class="fa-solid fa-money-bill-wave mr-1"></i>เงินค่าตอบแทนเดือนนี้</p>
                        <p class="text-[10px] text-amber-600/70 mt-0.5">อัตรา <?= number_format($payRate, 2) ?> บาท/ชม.</p>
                    </div>
                    <p class="text-xl font-black text-amber-700"><?= number_format($payMonth, 2) ?> <span class="text-xs font-bold text-amber-600">฿</span></p>
                </div>
            <?php endif; ?>
            <div class="bg-white rounded-2xl px-4 py-3 flex items-center justify-between border border-slate-100">
                <span class="text-xs font-black text-slate-500 uppercase tracking-widest">รวมทั้งหมด (สะสม)</span>
                <span class="text-base font-black text-slate-900">
                    <?= number_format($splitTotal['hours'], 1) ?>
                    <span class="text-xs text-slate-400">+</span>
                    <?= number_format($splitTotal['paid'], 1) ?>
                    <span class="text-xs text-slate-400">= <?= number_format($hoursTotal, 1) ?> ชม.</span>
                </span>
            </div>
        </div>

        <?php if ((int)$student['max_hours'] > 0): ?>
            <?php $pct = min(100, round(($hoursTotal / (int)$student['max_hours']) * 100)); ?>
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-black text-slate-700">เป้าภาคเรียน</span>
                    <span class="text-xs font-black text-emerald-600"><?= $pct ?>%</span>
                </div>
                <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                    <div class="h-full bg-gradient-to-r from-emerald-400 to-emerald-600 rounded-full transition-all" style="width: <?= $pct ?>%"></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ─── รอบงานว่างให้จอง ─── -->
        <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-100">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-black text-slate-900"><i class="fa-solid fa-layer-group text-emerald-600 mr-1.5"></i>รอบงานว่าง</h3>
                <button onclick="reloadOpenSlots()" class="text-xs font-black text-emerald-600 active:scale-95">
                    <i class="fa-solid fa-rotate"></i>
                </button>
            </div>
            <div id="open-slots-wrap" class="space-y-2">
                <p class="text-center text-xs text-slate-400 py-4"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด…</p>
            </div>
        </div>

        <!-- ─── รอบที่จองไว้ ─── -->
        <?php if (!empty($myBookings)): ?>
        <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-100">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-black text-slate-900"><i class="fa-solid fa-bookmark text-amber-600 mr-1.5"></i>รอบที่จองไว้</h3>
                <span class="text-xs font-black text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full"><?= count($myBookings) ?> รอบ</span>
            </div>
            <div class="space-y-2">
                <?php foreach ($myBookings as $bk):
                    $bkCt = $bk['comp_type'] ?? 'hours';
                    $ctBadge = $bkCt === 'paid'
                        ? ['bg-amber-50', 'text-amber-700', 'fa-coins', 'ค่าตอบแทน']
                        : ['bg-emerald-50', 'text-emerald-700', 'fa-graduation-cap', 'ทุน'];
                ?>
                <div class="flex items-center gap-3 p-2.5 rounded-xl bg-amber-50/50 border border-amber-100">
                    <div class="w-10 h-10 rounded-xl bg-white text-amber-600 flex items-center justify-center shrink-0 border border-amber-100">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-black text-slate-900"><?= vh(thai_weekday($bk['slot_date'])) ?> · <?= vh(format_scholarship_thai_date($bk['slot_date'])) ?></p>
                        <p class="text-xs text-slate-500">
                            <?= vh(substr((string)$bk['start_time'], 0, 5)) ?> – <?= vh(substr((string)$bk['end_time'], 0, 5)) ?>
                        </p>
                        <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 text-[10px] font-black rounded-full <?= $ctBadge[0] ?> <?= $ctBadge[1] ?>">
                            <i class="fa-solid <?= $ctBadge[2] ?> text-[8px]"></i><?= $ctBadge[3] ?>
                        </span>
                    </div>
                    <button onclick="cancelMyBooking(<?= (int)$bk['id'] ?>)" class="w-8 h-8 rounded-lg bg-white text-rose-500 hover:bg-rose-50 flex items-center justify-center transition" title="ยกเลิกการจอง">
                        <i class="fa-solid fa-xmark text-xs"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Upcoming Shifts (admin จัดให้) ─── -->
        <?php if (!empty($upcomingShifts)): ?>
        <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-100">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-black text-slate-900">ตารางที่ลงไว้</h3>
                <button onclick="openShiftModal()" class="text-xs font-black text-emerald-600 active:scale-95">
                    <i class="fa-solid fa-plus mr-1"></i>ลงตาราง
                </button>
            </div>
            <div class="space-y-2">
                <?php foreach ($upcomingShifts as $sh):
                    $shCt = $sh['comp_type'] ?? 'hours';
                    $ctBadge = $shCt === 'paid'
                        ? ['bg-amber-50', 'text-amber-700', 'fa-coins', 'ค่าตอบแทน']
                        : ['bg-emerald-50', 'text-emerald-700', 'fa-graduation-cap', 'ทุน'];
                ?>
                <div class="flex items-center gap-3 p-2.5 rounded-xl bg-slate-50">
                    <div class="w-10 h-10 rounded-xl bg-white text-emerald-600 flex items-center justify-center shrink-0 border border-emerald-100">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-black text-slate-900"><?= vh(thai_weekday($sh['shift_date'])) ?> · <?= vh(format_scholarship_thai_date($sh['shift_date'])) ?></p>
                        <p class="text-xs text-slate-500">
                            <?= vh(substr((string)$sh['start_time'], 0, 5)) ?> – <?= vh(substr((string)$sh['end_time'], 0, 5)) ?>
                            <?php if ((float)$sh['planned_hours'] > 0): ?>· <?= number_format((float)$sh['planned_hours'], 1) ?> ชม.<?php endif; ?>
                        </p>
                        <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 text-[10px] font-black rounded-full <?= $ctBadge[0] ?> <?= $ctBadge[1] ?>">
                            <i class="fa-solid <?= $ctBadge[2] ?> text-[8px]"></i><?= $ctBadge[3] ?>
                        </span>
                    </div>
                    <button onclick="deleteShift(<?= (int)$sh['id'] ?>)" class="w-8 h-8 rounded-lg bg-white text-rose-500 hover:bg-rose-50 flex items-center justify-center transition" title="ลบ">
                        <i class="fa-solid fa-trash text-xs"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ─── Recent Logs ─── -->
        <?php if (!empty($recentLogs)): ?>
        <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-100">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-black text-slate-900">ประวัติล่าสุด</h3>
                <a href="scholarship_history.php" class="text-xs font-black text-emerald-600">ดูทั้งหมด <i class="fa-solid fa-chevron-right text-[10px]"></i></a>
            </div>
            <div class="space-y-2">
                <?php foreach ($recentLogs as $log):
                    $isIn = $log['action'] === 'clock_in';
                    $statusBadge = [
                        'pending'  => ['rose-50', 'rose-600', 'รออนุมัติ'],
                        'approved' => ['emerald-50', 'emerald-600', 'อนุมัติแล้ว'],
                        'rejected' => ['slate-100', 'slate-500', 'ไม่อนุมัติ'],
                    ][$log['status']] ?? ['slate-50', 'slate-500', $log['status']];
                    $logCt = $log['comp_type'] ?? 'hours';
                    $ctIcon = $logCt === 'paid' ? 'fa-coins text-amber-500' : 'fa-graduation-cap text-emerald-500';
                ?>
                <div class="flex items-center gap-3 p-2.5 rounded-xl hover:bg-slate-50 transition">
                    <div class="w-9 h-9 rounded-xl <?= $isIn ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?> flex items-center justify-center shrink-0">
                        <i class="fa-solid <?= $isIn ? 'fa-right-to-bracket' : 'fa-right-from-bracket' ?>"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-slate-900">
                            <?= $isIn ? 'เข้างาน' : 'ออกงาน' ?>
                            <i class="fa-solid <?= $ctIcon ?> text-[10px] ml-1" title="<?= $logCt === 'paid' ? 'ค่าตอบแทน' : 'ทุน' ?>"></i>
                        </p>
                        <p class="text-xs text-slate-500"><?= vh(date('d/m/Y H:i', strtotime($log['event_at']))) ?></p>
                    </div>
                    <span class="px-2 py-0.5 text-[10px] font-black rounded-full bg-<?= $statusBadge[0] ?> text-<?= $statusBadge[1] ?>">
                        <?= vh($statusBadge[2]) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
    </main>
</div>

<?php
$__navActive = '';
include __DIR__ . '/../includes/user_bottom_nav.php';
?>

<script>
const CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
const GPS_REQUIRED = <?= !empty($settings['gps_required']) ? 'true' : 'false' ?>;
// ถ้าอยู่ในกะ → comp_type มาจากกะ ไม่ต้องถาม / ถ้าไม่อยู่ในกะ (ad-hoc) → ถามก่อน clock-in
const ACTIVE_SHIFT_COMP_TYPE = <?= json_encode($activeShift['comp_type'] ?? null, JSON_UNESCAPED_SLASHES) ?>;
// ข้อมูล clock_in ค้างอยู่ (ใช้สรุปก่อนยืนยันออกงาน)
const OPEN_CLOCK_IN = <?php
    if ($lastLog && $lastLog['action'] === 'clock_in' && $lastLog['status'] !== 'rejected') {
        echo json_encode([
            'event_at' => $lastLog['event_at'],
            'comp_type' => $lastLog['comp_type'] ?? 'hours',
            'status' => $lastLog['status'],
        ], JSON_UNESCAPED_SLASHES);
    } else {
        echo 'null';
    }
?>;

// Live clock
function updateClock() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const el = document.getElementById('live-clock');
    if (el) el.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
}
setInterval(updateClock, 1000);
updateClock();

// Clock in/out handler
const btn = document.getElementById('clock-btn');
if (btn && !btn.disabled) {
    btn.addEventListener('click', async () => {
        const action = btn.dataset.action;
        const labelMap = { clock_in: 'เข้างาน', clock_out: 'ออกงาน' };

        // ถ้า clock_in + ad-hoc (ไม่อยู่ในกะ) → ถาม comp_type ก่อน
        let pickedCompType = null;
        if (action === 'clock_in' && !ACTIVE_SHIFT_COMP_TYPE) {
            const r = await Swal.fire({
                title: 'ประเภทเวลาทำงาน',
                html: `
                    <p style="font-size:.85rem;color:#64748b;margin-bottom:1rem">เลือกว่างานครั้งนี้นับเป็นอะไร</p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                        <label style="cursor:pointer;border:2px solid #d1fae5;background:#ecfdf5;border-radius:1rem;padding:1rem;text-align:center;font-weight:800;color:#065f46" id="ct-hours-lbl">
                            <input type="radio" name="ct" value="hours" checked style="display:none">
                            <i class="fa-solid fa-graduation-cap" style="display:block;font-size:1.25rem;margin-bottom:.25rem"></i>
                            ส่งชั่วโมงทุน
                        </label>
                        <label style="cursor:pointer;border:2px solid #fef3c7;background:#fffbeb;border-radius:1rem;padding:1rem;text-align:center;font-weight:800;color:#92400e" id="ct-paid-lbl">
                            <input type="radio" name="ct" value="paid" style="display:none">
                            <i class="fa-solid fa-coins" style="display:block;font-size:1.25rem;margin-bottom:.25rem"></i>
                            ค่าตอบแทน
                        </label>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'ถัดไป',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#10b981',
                didOpen: () => {
                    const sync = () => {
                        const v = document.querySelector('input[name="ct"]:checked').value;
                        document.getElementById('ct-hours-lbl').style.boxShadow = v === 'hours' ? '0 0 0 4px rgba(16,185,129,.25)' : '';
                        document.getElementById('ct-paid-lbl').style.boxShadow = v === 'paid' ? '0 0 0 4px rgba(245,158,11,.25)' : '';
                    };
                    document.getElementById('ct-hours-lbl').addEventListener('click', () => {
                        document.querySelector('input[name="ct"][value="hours"]').checked = true; sync();
                    });
                    document.getElementById('ct-paid-lbl').addEventListener('click', () => {
                        document.querySelector('input[name="ct"][value="paid"]').checked = true; sync();
                    });
                    sync();
                },
                preConfirm: () => document.querySelector('input[name="ct"]:checked').value,
            });
            if (!r.isConfirmed) return;
            pickedCompType = r.value;
        }

        // สรุปก่อนยืนยันออกงาน — โชว์เวลาเข้างาน + ระยะเวลา + ประเภท
        let confirmCfg = {
            title: `ยืนยัน${labelMap[action]}?`,
            text: GPS_REQUIRED
                ? 'ระบบจะขอตำแหน่ง GPS และส่งให้เจ้าหน้าที่อนุมัติ'
                : 'ส่งคำขอให้เจ้าหน้าที่อนุมัติด้วยตนเอง',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: `ยืนยัน${labelMap[action]}`,
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: action === 'clock_in' ? '#10b981' : '#f43f5e',
        };

        if (action === 'clock_out' && OPEN_CLOCK_IN) {
            const inTs = new Date(OPEN_CLOCK_IN.event_at.replace(' ', 'T')).getTime();
            const nowTs = Date.now();
            const diffMs = Math.max(0, nowTs - inTs);
            const totalMin = Math.round(diffMs / 60000); // ปัดเป็นค่าใกล้เคียง
            const hours = Math.floor(totalMin / 60);
            const mins = totalMin % 60;
            // นโยบาย: ปัดลงเป็นชั่วโมงเต็ม (1ชม.30 = 1ชม., 2ชม.59 = 2ชม.)
            const countedHours = Math.floor(diffMs / 3600000);

            const ct = OPEN_CLOCK_IN.comp_type || 'hours';
            const ctLabel = ct === 'paid' ? 'ค่าตอบแทน' : 'ส่งชั่วโมงทุน';
            const ctColor = ct === 'paid' ? '#92400e' : '#065f46';
            const ctBg = ct === 'paid' ? '#fffbeb' : '#ecfdf5';
            const ctBorder = ct === 'paid' ? '#fde68a' : '#a7f3d0';
            const ctIcon = ct === 'paid' ? 'fa-coins' : 'fa-graduation-cap';

            const inTimeStr = OPEN_CLOCK_IN.event_at.slice(11, 16);
            const inDateStr = OPEN_CLOCK_IN.event_at.slice(0, 10);
            const todayStr = new Date().toISOString().slice(0, 10);
            const inDisplay = inDateStr === todayStr
                ? `วันนี้ ${inTimeStr}`
                : `${inDateStr} ${inTimeStr}`;
            const noteMsg = OPEN_CLOCK_IN.status === 'pending'
                ? '<p style="font-size:.7rem;color:#f59e0b;margin-top:.5rem"><i class="fa-solid fa-circle-info"></i> clock-in ยังรออนุมัติ — ชั่วโมงจะนับเมื่ออนุมัติครบทั้งคู่</p>'
                : '';

            confirmCfg = {
                title: 'ยืนยันออกงาน?',
                html: `
                    <div style="text-align:left;background:#f8fafc;border-radius:1rem;padding:1rem;margin:.75rem 0">
                        <div style="display:flex;justify-content:space-between;font-size:.8rem;color:#64748b;margin-bottom:.5rem">
                            <span>เข้างานเมื่อ</span>
                            <span style="font-weight:800;color:#0f172a">${inDisplay}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.8rem;color:#64748b;margin-bottom:.75rem">
                            <span>ประเภท</span>
                            <span style="display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .5rem;border-radius:99px;background:${ctBg};border:1px solid ${ctBorder};color:${ctColor};font-weight:800;font-size:.7rem">
                                <i class="fa-solid ${ctIcon}"></i>${ctLabel}
                            </span>
                        </div>
                        <div style="border-top:1px dashed #cbd5e1;padding-top:.75rem">
                            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.5rem">
                                <span style="font-size:.75rem;color:#64748b">ทำงานจริง</span>
                                <span style="font-size:1rem;font-weight:800;color:#475569;font-variant-numeric:tabular-nums">${hours} ชม. ${mins} นาที</span>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:baseline;background:#fff;border:2px solid #10b981;border-radius:.65rem;padding:.6rem .85rem">
                                <span style="font-size:.75rem;color:#065f46;font-weight:800">นับเข้าระบบ</span>
                                <span style="font-size:1.5rem;font-weight:900;color:#065f46;font-variant-numeric:tabular-nums">${countedHours} ชั่วโมง</span>
                            </div>
                            <p style="font-size:.7rem;color:#94a3b8;margin:.5rem 0 0;text-align:center">
                                <i class="fa-solid fa-circle-info"></i> ปัดลงเป็นชั่วโมงเต็ม (เศษนาทีไม่นับ)
                            </p>
                        </div>
                        ${noteMsg}
                    </div>
                    <p style="font-size:.8rem;color:#64748b;margin:.5rem 0 0">${GPS_REQUIRED ? 'ระบบจะขอตำแหน่ง GPS และส่งให้เจ้าหน้าที่อนุมัติ' : 'ส่งคำขอให้เจ้าหน้าที่อนุมัติด้วยตนเอง'}</p>
                `,
                showCancelButton: true,
                confirmButtonText: 'ยืนยันออกงาน',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#f43f5e',
            };
        }

        const confirm = await Swal.fire(confirmCfg);
        if (!confirm.isConfirmed) return;

        async function submitClock(lat, lng, accuracy) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', action);
            if (pickedCompType) fd.append('comp_type', pickedCompType);
            if (lat != null) fd.append('lat', lat);
            if (lng != null) fd.append('lng', lng);
            fd.append('accuracy', accuracy || 0);

            try {
                const r = await fetch('ajax_scholarship_clock.php', { method: 'POST', body: fd });
                const j = await r.json();
                if (j.ok) {
                    await Swal.fire({
                        icon: 'success',
                        title: `${labelMap[action]}สำเร็จ!`,
                        text: j.message || 'ส่งคำขอให้เจ้าหน้าที่อนุมัติแล้ว',
                        confirmButtonColor: '#10b981',
                    });
                    location.reload();
                } else {
                    Swal.fire('ไม่สำเร็จ', j.error || 'เกิดข้อผิดพลาด', 'error');
                }
            } catch (err) {
                Swal.fire('Network Error', 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์', 'error');
            }
        }

        // ปิด GPS check → ส่งคำขอเลย ไม่ต้องขอตำแหน่ง
        if (!GPS_REQUIRED) {
            Swal.fire({ title: 'กำลังส่งคำขอ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            await submitClock(null, null, 0);
            return;
        }

        // เปิด GPS check → ขอตำแหน่งก่อน
        if (!navigator.geolocation) {
            Swal.fire('ไม่รองรับ GPS', 'อุปกรณ์ของคุณไม่รองรับการระบุตำแหน่ง', 'error');
            return;
        }

        Swal.fire({
            title: 'กำลังระบุตำแหน่ง...',
            html: 'กรุณาอนุญาตการเข้าถึงตำแหน่ง',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        navigator.geolocation.getCurrentPosition(
            pos => submitClock(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy || 0),
            err => {
                Swal.fire({
                    icon: 'error',
                    title: 'ไม่สามารถระบุตำแหน่ง',
                    text: err.code === err.PERMISSION_DENIED
                        ? 'กรุณาอนุญาตการเข้าถึง GPS ในการตั้งค่าเบราว์เซอร์'
                        : 'ระบบไม่สามารถดึงตำแหน่งได้ กรุณาลองใหม่',
                });
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
        );
    });
}

// ─── Self-scheduling: ลงตารางมาทำงาน ───
window.openShiftModal = async function() {
    const todayStr = new Date().toISOString().slice(0, 10);
    const r = await Swal.fire({
        title: 'ลงตารางมาทำงาน',
        html: `
            <div style="text-align:left">
                <label style="display:block;font-size:.7rem;font-weight:800;color:#4b5563;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem">ประเภท</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.75rem">
                    <label style="cursor:pointer;border:2px solid #d1fae5;background:#ecfdf5;border-radius:.85rem;padding:.65rem;text-align:center;font-weight:800;color:#065f46;font-size:.8rem" id="sch-ct-hours-lbl">
                        <input type="radio" name="sch-ct" value="hours" checked style="display:none">
                        <i class="fa-solid fa-graduation-cap" style="margin-right:.35rem"></i>ส่งชั่วโมงทุน
                    </label>
                    <label style="cursor:pointer;border:2px solid #fef3c7;background:#fffbeb;border-radius:.85rem;padding:.65rem;text-align:center;font-weight:800;color:#92400e;font-size:.8rem" id="sch-ct-paid-lbl">
                        <input type="radio" name="sch-ct" value="paid" style="display:none">
                        <i class="fa-solid fa-coins" style="margin-right:.35rem"></i>ค่าตอบแทน
                    </label>
                </div>
                <label style="display:block;font-size:.7rem;font-weight:800;color:#4b5563;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem">วันที่</label>
                <input id="sch-date" type="date" min="${todayStr}" value="${todayStr}" class="swal2-input" style="margin:0 0 .75rem;width:100%">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <div>
                        <label style="display:block;font-size:.7rem;font-weight:800;color:#4b5563;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem">เริ่ม</label>
                        <input id="sch-start" type="time" value="09:00" class="swal2-input" style="margin:0;width:100%">
                    </div>
                    <div>
                        <label style="display:block;font-size:.7rem;font-weight:800;color:#4b5563;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem">สิ้นสุด</label>
                        <input id="sch-end" type="time" value="12:00" class="swal2-input" style="margin:0;width:100%">
                    </div>
                </div>
                <label style="display:block;font-size:.7rem;font-weight:800;color:#4b5563;text-transform:uppercase;letter-spacing:.05em;margin:.75rem 0 .35rem">หมายเหตุ (ถ้ามี)</label>
                <input id="sch-notes" type="text" placeholder="เช่น สอนน้อง, ช่วยจัดเอกสาร" class="swal2-input" style="margin:0;width:100%">
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#10b981',
        didOpen: () => {
            const sync = () => {
                const v = document.querySelector('input[name="sch-ct"]:checked').value;
                document.getElementById('sch-ct-hours-lbl').style.boxShadow = v === 'hours' ? '0 0 0 4px rgba(16,185,129,.25)' : '';
                document.getElementById('sch-ct-paid-lbl').style.boxShadow = v === 'paid' ? '0 0 0 4px rgba(245,158,11,.25)' : '';
            };
            document.getElementById('sch-ct-hours-lbl').addEventListener('click', () => {
                document.querySelector('input[name="sch-ct"][value="hours"]').checked = true; sync();
            });
            document.getElementById('sch-ct-paid-lbl').addEventListener('click', () => {
                document.querySelector('input[name="sch-ct"][value="paid"]').checked = true; sync();
            });
            sync();
        },
        preConfirm: () => {
            const date = document.getElementById('sch-date').value;
            const start = document.getElementById('sch-start').value;
            const end = document.getElementById('sch-end').value;
            const notes = document.getElementById('sch-notes').value;
            const compType = document.querySelector('input[name="sch-ct"]:checked').value;
            if (!date || !start || !end) {
                Swal.showValidationMessage('กรอกวันและเวลาให้ครบ');
                return false;
            }
            if (start >= end) {
                Swal.showValidationMessage('เวลาเริ่มต้องมาก่อนเวลาสิ้นสุด');
                return false;
            }
            return { date, start, end, notes, compType };
        }
    });

    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action', 'create');
    fd.append('shift_date', r.value.date);
    fd.append('start_time', r.value.start);
    fd.append('end_time', r.value.end);
    fd.append('comp_type', r.value.compType);
    fd.append('notes', r.value.notes || '');
    try {
        const resp = await fetch('ajax_scholarship_shifts.php', { method: 'POST', body: fd });
        const j = await resp.json();
        if (j.ok) {
            await Swal.fire({ icon: 'success', title: 'ลงตารางแล้ว', timer: 1200, showConfirmButton: false });
            location.reload();
        } else {
            Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
        }
    } catch (err) {
        Swal.fire('Network Error', '', 'error');
    }
};

window.deleteShift = async function(id) {
    const r = await Swal.fire({
        title: 'ลบกะนี้?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#f43f5e',
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('action', 'delete');
    fd.append('id', id);
    try {
        const resp = await fetch('ajax_scholarship_shifts.php', { method: 'POST', body: fd });
        const j = await resp.json();
        if (j.ok) {
            location.reload();
        } else {
            Swal.fire('ไม่สำเร็จ', j.error || '', 'error');
        }
    } catch (err) {
        Swal.fire('Network Error', '', 'error');
    }
};

// ────────── รอบงานว่าง / จอง / ยกเลิกการจอง ──────────
async function loadOpenSlots() {
    const wrap = document.getElementById('open-slots-wrap');
    if (!wrap) return;
    try {
        const fd = new FormData();
        fd.append('csrf_token', '<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES) ?>');
        fd.append('action', 'list_open');
        const r = await fetch('ajax_scholarship_booking.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.ok) {
            wrap.innerHTML = `<p class="text-center text-xs text-rose-500 py-4">${j.error || 'โหลดไม่สำเร็จ'}</p>`;
            return;
        }
        if (!j.rows.length) {
            wrap.innerHTML = '<p class="text-center text-xs text-slate-400 py-4">ไม่มีรอบว่างในช่วง 14 วันข้างหน้า</p>';
            return;
        }
        // group by date
        const byDate = {};
        j.rows.forEach(s => { (byDate[s.slot_date] = byDate[s.slot_date] || []).push(s); });
        let html = '';
        Object.keys(byDate).sort().forEach(d => {
            const dateLabel = formatThaiSlotDate(d);
            html += `<p class="text-[11px] font-black text-slate-500 mt-2 mb-1">${dateLabel}</p>`;
            byDate[d].forEach(s => {
                const pct = s.max_capacity > 0 ? (s.booked_count / s.max_capacity) : 0;
                const capCls = pct >= 1 ? 'text-rose-600' : pct >= 0.8 ? 'text-amber-600' : 'text-emerald-600';
                const ctBadge = s.comp_type === 'paid'
                    ? '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-black rounded-full bg-amber-50 text-amber-700"><i class="fa-solid fa-coins text-[8px]"></i>ค่าตอบแทน</span>'
                    : '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-black rounded-full bg-emerald-50 text-emerald-700"><i class="fa-solid fa-graduation-cap text-[8px]"></i>ทุน</span>';
                const full = s.available <= 0;
                const btn = full
                    ? '<button disabled class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-400 text-xs font-black cursor-not-allowed">เต็ม</button>'
                    : `<button onclick="bookSlot(${s.id})" class="px-3 py-1.5 rounded-lg bg-emerald-500 text-white text-xs font-black hover:bg-emerald-600 active:scale-95 transition">จอง</button>`;
                const notes = s.notes ? `<p class="text-[11px] text-slate-500 mt-0.5">${escapeHtmlSlot(s.notes)}</p>` : '';
                html += `<div class="flex items-center gap-3 p-2.5 rounded-xl bg-slate-50 border border-slate-100">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-black text-slate-900">${s.start_time.substring(0,5)} – ${s.end_time.substring(0,5)}</p>
                        <p class="text-[11px] ${capCls} font-black">รับ ${s.booked_count}/${s.max_capacity} คน</p>
                        <div class="mt-1">${ctBadge}</div>
                        ${notes}
                    </div>
                    ${btn}
                </div>`;
            });
        });
        wrap.innerHTML = html;
    } catch (err) {
        wrap.innerHTML = '<p class="text-center text-xs text-rose-500 py-4">โหลดไม่สำเร็จ</p>';
    }
}
function reloadOpenSlots() { loadOpenSlots(); }

function formatThaiSlotDate(d) {
    const months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    const [y, m, day] = d.split('-');
    return `${parseInt(day,10)} ${months[parseInt(m,10)-1]} ${parseInt(y,10)+543}`;
}
function escapeHtmlSlot(s) {
    const div = document.createElement('div'); div.textContent = String(s || ''); return div.innerHTML;
}

async function bookSlot(slotId) {
    const c = await Swal.fire({
        icon: 'question', title: 'จองรอบนี้?',
        text: 'เมื่อจองแล้ว ระบบจะสร้างกะให้คุณ พร้อมเข้างานในเวลาที่กำหนด',
        showCancelButton: true, confirmButtonText: 'จอง', cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#10b981',
    });
    if (!c.isConfirmed) return;
    try {
        const fd = new FormData();
        fd.append('csrf_token', '<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES) ?>');
        fd.append('action', 'book');
        fd.append('slot_id', String(slotId));
        const r = await fetch('ajax_scholarship_booking.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.ok) { Swal.fire('จองไม่สำเร็จ', j.error || '', 'error'); return; }
        await Swal.fire({ icon: 'success', title: 'จองสำเร็จ', text: j.message || '', timer: 1500, showConfirmButton: false });
        location.reload();
    } catch (err) {
        Swal.fire('Network Error', '', 'error');
    }
}

async function cancelMyBooking(bookingId) {
    const c = await Swal.fire({
        icon: 'warning', title: 'ยกเลิกการจอง?',
        input: 'text', inputLabel: 'เหตุผล (ไม่บังคับ)',
        inputPlaceholder: 'เช่น ติดธุระ',
        showCancelButton: true, confirmButtonText: 'ยกเลิกการจอง', cancelButtonText: 'ปิด',
        confirmButtonColor: '#e11d48',
    });
    if (!c.isConfirmed) return;
    try {
        const fd = new FormData();
        fd.append('csrf_token', '<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES) ?>');
        fd.append('action', 'cancel');
        fd.append('booking_id', String(bookingId));
        fd.append('reason', c.value || '');
        const r = await fetch('ajax_scholarship_booking.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.ok) { Swal.fire('ยกเลิกไม่สำเร็จ', j.error || '', 'error'); return; }
        await Swal.fire({ icon: 'success', title: 'ยกเลิกแล้ว', timer: 1200, showConfirmButton: false });
        location.reload();
    } catch (err) {
        Swal.fire('Network Error', '', 'error');
    }
}

// Auto-load open slots on page ready
document.addEventListener('DOMContentLoaded', loadOpenSlots);
</script>
</body>
</html>
