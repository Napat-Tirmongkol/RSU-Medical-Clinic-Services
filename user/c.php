<?php
// user/c.php — Premium Campaign Invite Landing
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
session_start();

// 1. ตรวจสอบ Login เบื้องต้น
$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    $_SESSION['invite_token'] = trim($_GET['t'] ?? '');
    ?>
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>RSU Medical Hub - Login Required</title>
        <script src="https://cdn.tailwindcss.com/3.4.1"></script>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            @font-face { font-family:'RSU'; src:url('../assets/fonts/RSU_Regular.ttf') format('truetype'); }
            @font-face { font-family:'RSU'; src:url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight:bold; }
            body { font-family:'RSU', sans-serif; background:#F8FAFF; }
            .premium-gradient { background: linear-gradient(135deg, #2e9e63 0%, #10b981 100%); }
        </style>
    </head>
    <body class="flex items-center justify-center min-h-screen p-6">
        <div class="w-full max-w-md text-center">
            <div class="bg-white rounded-[3rem] p-10 shadow-xl border border-slate-100">
                <div class="w-20 h-20 premium-gradient rounded-3xl flex items-center justify-center mx-auto mb-8 shadow-lg shadow-green-100">
                    <i class="fa-solid fa-user-shield text-white text-3xl"></i>
                </div>
                <h1 class="text-slate-900 font-bold text-2xl mb-4">กรุณาเข้าสู่ระบบด้วย LINE</h1>
                <p class="text-slate-500 text-sm mb-8">เพื่อความปลอดภัยและใช้บริการนัดหมายแคมเปญพิเศษ</p>
                <a href="https://healthycampus.rsu.ac.th/e-campaignv2/line_api/line_login.php" 
                   class="flex items-center justify-center gap-3 w-full py-5 bg-[#06C755] hover:bg-[#05b34c] text-white font-bold rounded-2xl shadow-lg transition-all active:scale-95">
                    <i class="fa-brands fa-line text-2xl"></i>
                    Login with LINE
                </a>
            </div>
            <p class="mt-8 text-slate-300 text-[10px] uppercase tracking-widest font-bold">Powered by RSU Healthcare</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 2. ถ้า Login แล้วค่อยโหลดไฟล์หลัก
require_once __DIR__ . '/../config.php';
check_maintenance('e_campaign');

$pdo = db();
$stmt = $pdo->prepare("SELECT id, full_name FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
$stmt->execute([':line_id' => $lineUserId]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$token = trim($_GET['t'] ?? '');
if ($token === '') {
    header('Location: hub.php');
    exit;
}

// ดึงข้อมูลแคมเปญ
$stmt = $pdo->prepare("
    SELECT c.*,
           (SELECT COUNT(*) FROM camp_bookings a
            WHERE a.campaign_id = c.id AND a.status IN ('booked','confirmed')) AS used_seats
    FROM camp_list c
    WHERE c.share_token = :token
    LIMIT 1
");
$stmt->execute([':token' => $token]);
$campaign = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$campaign) {
    die("ไม่พบแคมเปญนี้ในระบบ หรือลิงก์อาจหมดอายุแล้ว");
}

// คำนวณสถานะแคมเปญ
$totalCapacity = (int)($campaign['total_capacity'] ?? 0);
$usedSeats = (int)($campaign['used_seats'] ?? 0);
$remaining = $totalCapacity - $usedSeats;
$isFull = ($remaining <= 0);
$isExpired = (!empty($campaign['available_until']) && strtotime($campaign['available_until']) < strtotime(date('Y-m-d')));
$isActive = in_array($campaign['status'], ['active', 'private']);

// --- ส่วนแสดงผลหน้าแคมเปญ (ถ้า Login แล้ว) ---
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= htmlspecialchars($campaign['title']) ?> - RSU Medical Services</title>
    <script src="https://cdn.tailwindcss.com/3.4.1"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family:'RSU'; src:url('../assets/fonts/RSU_Regular.ttf') format('truetype'); }
        @font-face { font-family:'RSU'; src:url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight:bold; }
        body { font-family:'RSU', sans-serif; background-color: #f0f4f9; }
        .glass-card { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.4); }
        .premium-shadow { box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.08); }
        .rsu-main-color { color: #2e9e63; }
        .rsu-bg-main { background-color: #2e9e63; }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-lg glass-card rounded-[2.5rem] premium-shadow overflow-hidden p-8 text-center">
        <div class="mb-6">
            <span class="px-4 py-1.5 bg-green-50 text-green-600 rounded-full text-xs font-bold uppercase tracking-wider border border-green-100">แคมเปญพิเศษ</span>
        </div>
        
        <h1 class="text-3xl font-bold text-slate-800 mb-4 leading-tight"><?= htmlspecialchars($campaign['title']) ?></h1>
        <p class="text-slate-500 mb-8 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($campaign['description'])) ?></p>

        <div class="grid grid-cols-2 gap-4 mb-8">
            <div class="bg-white/50 rounded-3xl p-5 border border-white/60 shadow-sm">
                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">โควต้าทั้งหมด</p>
                <p class="text-2xl font-bold text-slate-700"><?= number_format($totalCapacity) ?></p>
            </div>
            <div class="bg-white/50 rounded-3xl p-5 border border-white/60 shadow-sm">
                <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">ว่างตอนนี้</p>
                <p class="text-2xl font-bold <?= $isFull ? 'text-rose-500' : 'text-emerald-500' ?>"><?= number_format(max(0, $remaining)) ?></p>
            </div>
        </div>

        <?php if (!$isActive || $isExpired): ?>
            <div class="bg-rose-50 border border-rose-100 rounded-3xl p-6 text-rose-600 font-bold mb-6">
                <i class="fa-solid fa-circle-exclamation mr-2"></i>
                ขออภัย แคมเปญนี้ปิดรับสมัครแล้ว
            </div>
        <?php elseif ($isFull): ?>
            <div class="bg-amber-50 border border-amber-100 rounded-3xl p-6 text-amber-600 font-bold mb-6">
                <i class="fa-solid fa-users-slash mr-2"></i>
                ขออภัย จำนวนที่นั่งเต็มแล้ว
            </div>
        <?php else: ?>
            <a href="booking_date.php?campaign_id=<?= $campaign['id'] ?>" 
               class="block w-full rsu-bg-main hover:bg-emerald-700 text-white font-bold py-6 rounded-3xl shadow-xl shadow-green-100 transition-all active:scale-95 text-lg">
                <i class="fa-solid fa-calendar-check mr-2"></i>
                จองคิวทันที
            </a>
        <?php endif; ?>

        <a href="hub.php" class="inline-block mt-8 text-slate-400 hover:text-blue-600 font-bold text-sm transition-colors">
            <i class="fa-solid fa-arrow-left mr-2"></i>
            กลับสู่หน้าหลัก
        </a>
    </div>
    
    <p class="mt-8 text-slate-400 text-[10px] font-bold uppercase tracking-widest opacity-50">RSU Medical Clinic Services</p>
</body>
</html>
