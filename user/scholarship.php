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

// คำนวณ state ปัจจุบัน:
// - ถ้า log ล่าสุดเป็น clock_in (status=approved/pending) → user กำลัง "ทำงาน" → ปุ่มต่อไป = ออกงาน
// - ถ้าไม่มี log หรือล่าสุดเป็น clock_out → ปุ่มต่อไป = เข้างาน
$nextAction = 'clock_in';
if ($lastLog && $lastLog['action'] === 'clock_in' && $lastLog['status'] !== 'rejected') {
    $nextAction = 'clock_out';
}

// ชั่วโมงสะสมเดือนนี้
$monthFrom = date('Y-m-01', $now);
$monthTo = date('Y-m-t', $now);
$hoursMonth = $student ? sum_scholarship_hours($pdo, (int)$student['id'], $monthFrom, $monthTo) : 0;
// ชั่วโมงสะสมรวมทั้งหมด (นับ semester ปัจจุบันถ้ามี → ตอนนี้ใช้รวม)
$hoursTotal = $student ? sum_scholarship_hours($pdo, (int)$student['id']) : 0;

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

            <?php if (empty($todayShifts)): ?>
                <div class="bg-slate-50 rounded-2xl p-4 text-center">
                    <i class="fa-solid fa-calendar-xmark text-2xl text-slate-300 mb-2"></i>
                    <p class="text-sm font-bold text-slate-500">วันนี้ไม่มีกะ</p>
                    <p class="text-xs text-slate-400 mt-1">กรุณาตรวจสอบตารางกับเจ้าหน้าที่</p>
                </div>
            <?php else: ?>
                <div class="space-y-2">
                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">กะวันนี้</p>
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
                $btnClass = 'clock-btn-disabled';
                $btnLabel = 'ไม่อยู่ในช่วงกะ';
                $btnIcon = 'fa-circle-xmark';
                $btnDisabled = true;

                if ($activeShift) {
                    if ($nextAction === 'clock_in') {
                        $btnClass = 'clock-btn-in';
                        $btnLabel = 'เข้างาน';
                        $btnIcon = 'fa-right-to-bracket';
                        $btnDisabled = false;
                    } else {
                        $btnClass = 'clock-btn-out';
                        $btnLabel = 'ออกงาน';
                        $btnIcon = 'fa-right-from-bracket';
                        $btnDisabled = false;
                    }
                } elseif ($nextAction === 'clock_out') {
                    // ออกกะมาแล้วแต่ยังไม่ clock out → อนุญาตให้ clock out (overtime)
                    $btnClass = 'clock-btn-out';
                    $btnLabel = 'ออกงาน (เลยกะ)';
                    $btnIcon = 'fa-right-from-bracket';
                    $btnDisabled = false;
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
                <i class="fa-solid fa-location-dot mr-1"></i>
                ระบบจะขอตำแหน่ง GPS เพื่อยืนยันว่าอยู่ในคลินิก (รัศมี <?= (int)$settings['radius_m'] ?> ม.)
            </p>
        </div>

        <!-- ─── Hours Stats ─── -->
        <div class="grid grid-cols-2 gap-3">
            <div class="stat-card p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">เดือนนี้</p>
                <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format($hoursMonth, 1) ?></p>
                <p class="text-xs text-slate-500">ชั่วโมง</p>
            </div>
            <div class="stat-card p-4">
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">รวมทั้งหมด</p>
                <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format($hoursTotal, 1) ?></p>
                <p class="text-xs text-slate-500">
                    <?php if ((int)$student['max_hours'] > 0): ?>
                        / <?= (int)$student['max_hours'] ?> ชม.
                    <?php else: ?>
                        ชั่วโมง
                    <?php endif; ?>
                </p>
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
                ?>
                <div class="flex items-center gap-3 p-2.5 rounded-xl hover:bg-slate-50 transition">
                    <div class="w-9 h-9 rounded-xl <?= $isIn ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600' ?> flex items-center justify-center shrink-0">
                        <i class="fa-solid <?= $isIn ? 'fa-right-to-bracket' : 'fa-right-from-bracket' ?>"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-slate-900"><?= $isIn ? 'เข้างาน' : 'ออกงาน' ?></p>
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
        const confirm = await Swal.fire({
            title: `ยืนยัน${labelMap[action]}?`,
            text: 'ระบบจะขอตำแหน่ง GPS และส่งให้เจ้าหน้าที่อนุมัติ',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: `ยืนยัน${labelMap[action]}`,
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: action === 'clock_in' ? '#10b981' : '#f43f5e',
        });
        if (!confirm.isConfirmed) return;

        // ขอ GPS
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

        navigator.geolocation.getCurrentPosition(async pos => {
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN);
            fd.append('action', action);
            fd.append('lat', pos.coords.latitude);
            fd.append('lng', pos.coords.longitude);
            fd.append('accuracy', pos.coords.accuracy || 0);

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
        }, err => {
            Swal.fire({
                icon: 'error',
                title: 'ไม่สามารถระบุตำแหน่ง',
                text: err.code === err.PERMISSION_DENIED
                    ? 'กรุณาอนุญาตการเข้าถึง GPS ในการตั้งค่าเบราว์เซอร์'
                    : 'ระบบไม่สามารถดึงตำแหน่งได้ กรุณาลองใหม่',
            });
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    });
}
</script>
</body>
</html>
