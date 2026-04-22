<?php
// user/hub.php — Premium Command Center (Production Ready)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: index.php');
    exit;
}

$user = null;
$camp_list = [];
$booking_list = [];
$upcoming_count = 0;
$borrow_count = 0;

try {
    $pdo = db();
    $today = date('Y-m-d');

    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmt->execute([':line_id' => $lineUserId]);
    $user = $stmt->fetch();

    if (!$user) { header('Location: index.php'); exit; }

    $stmt = $pdo->prepare("
        SELECT c.*,
               (SELECT COUNT(*) FROM camp_bookings a WHERE a.campaign_id = c.id AND a.status IN ('booked', 'confirmed')) as used_seats
        FROM camp_list c
        WHERE c.status = 'active'
        AND (c.available_until IS NULL OR c.available_until >= :today)
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([':today' => $today]);
    $camp_list = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT b.*, c.title as camp_name, c.type as camp_type, s.slot_date, s.start_time
        FROM camp_bookings b
        JOIN camp_list c ON b.campaign_id = c.id
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE b.student_id = :sid
        ORDER BY s.slot_date DESC, s.start_time DESC
        LIMIT 5
    ");
    $stmt->execute([':sid' => $user['id']]);
    $booking_list = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings b JOIN camp_slots s ON b.slot_id = s.id WHERE b.student_id = :sid AND s.slot_date >= :today AND b.status != 'cancelled'");
    $stmt->execute([':sid' => $user['id'], ':today' => $today]);
    $upcoming_count = (int)$stmt->fetchColumn();

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_records WHERE borrower_student_id = :sid AND status IN ('borrowed','approved')");
        $stmt->execute([':sid' => $user['student_personnel_id']]);
        $borrow_count = (int)$stmt->fetchColumn();
    } catch (Exception $e) { $borrow_count = 0; }

} catch (Exception $e) { }

function getCampStyle($type): array {
    return match($type) {
        'vaccine'      => ['label' => 'วัคซีน', 'class' => 'bg-blue-50 text-blue-600 border-blue-100', 'icon' => 'fa-syringe'],
        'health_check' => ['label' => 'ตรวจสุขภาพ', 'class' => 'bg-emerald-50 text-emerald-600 border-emerald-100', 'icon' => 'fa-stethoscope'],
        default        => ['label' => 'ทั่วไป', 'class' => 'bg-gray-50 text-gray-600 border-gray-100', 'icon' => 'fa-star'],
    };
}

function getStatusStyle($status): array {
    return match($status) {
        'confirmed', 'booked' => ['label' => 'ยืนยันแล้ว', 'class' => 'bg-blue-50 text-blue-600'],
        'completed'           => ['label' => 'สำเร็จแล้ว', 'class' => 'bg-emerald-50 text-emerald-600'],
        'cancelled'           => ['label' => 'ยกเลิกแล้ว', 'class' => 'bg-red-50 text-red-600'],
        default               => ['label' => 'รอดำเนินการ', 'class' => 'bg-gray-50 text-gray-600'],
    };
}

date_default_timezone_set('Asia/Bangkok');
$hour = date('H');
$greeting = ($hour >= 5 && $hour < 12) ? "อรุณสวัสดิ์" : (($hour >= 12 && $hour < 17) ? "สวัสดีตอนบ่าย" : (($hour >= 17 && $hour < 21) ? "สวัสดีตอนเย็น" : "สวัสดีตอนค่ำ"));
$days = ["อาทิตย์", "จันทร์", "อังคาร", "พุธ", "พฤหัสบดี", "ศุกร์", "เสาร์"];
$months = ["มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
$thaiDate = $days[date('w')] . ", " . date('j') . " " . $months[date('n')-1] . " " . (date('Y') + 543);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>RSU Medical Hub</title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <script src="https://cdn.tailwindcss.com/3.4.1"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; -webkit-tap-highlight-color: transparent; background-color: #F8FAFF; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .custom-scrollbar::-webkit-scrollbar { display: none; }
        .animate-float { animation: float 6s ease-in-out infinite; }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
    </style>
    <script>
        let qr = null;
        function toggleModal(id, show) {
            const el = document.getElementById(id);
            if (!el) return;
            if (show) {
                el.classList.remove('hidden'); el.classList.add('flex');
                if (id === 'qr-modal' && !qr) {
                    qr = new QRCode(document.getElementById('qrcode'), { 
                        text: "<?= htmlspecialchars($user['student_personnel_id'] ?? '') ?>", 
                        width: 180, height: 180, colorDark : "#0f172a", colorLight : "#ffffff", correctLevel : QRCode.CorrectLevel.H 
                    });
                }
            } else {
                el.classList.add('hidden'); el.classList.remove('flex');
            }
        }
        function showQR() { toggleModal('qr-modal', true); }
        function hideQR() { toggleModal('qr-modal', false); }
        function showNotifications() { toggleModal('notif-modal', true); }
        function hideNotifications() { toggleModal('notif-modal', false); }
        function showProfile() { toggleModal('profile-modal', true); }
        function hideProfile() { toggleModal('profile-modal', false); }
        function showCampaigns() { toggleModal('camps-modal', true); }
        function hideCampaigns() { toggleModal('camps-modal', false); }
        function showHistory() { toggleModal('history-modal', true); }
        function hideHistory() { toggleModal('history-modal', false); }
        function showContact() { toggleModal('contact-modal', true); }
        function hideContact() { toggleModal('contact-modal', false); }
        function showChat() { toggleModal('chat-modal', true); }
        function hideChat() { toggleModal('chat-modal', false); }
        function showUpcoming(name) { 
            const nameEl = document.getElementById('upcoming-name');
            if (nameEl) nameEl.innerText = name;
            toggleModal('upcoming-modal', true); 
        }
        function hideUpcoming() { toggleModal('upcoming-modal', false); }

        (function() {
            const originalWarn = console.warn;
            console.warn = function(...args) {
                if (typeof args[0] === 'string' && args[0].includes('cdn.tailwindcss.com')) return;
                originalWarn.apply(console, args);
            };
        })();
    </script>
</head>
<body class="text-slate-900 pb-32">
    <div class="max-w-md mx-auto relative min-h-screen">
        
        <!-- Header -->
        <header class="glass-header sticky top-0 z-[60] px-6 py-4 flex items-center justify-between border-b border-slate-100 shadow-sm">
            <div class="flex items-center gap-4">
                <button onclick="showCampaigns()" class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-blue-100 active:scale-90 transition-all">
                    <i class="fa-solid fa-plus text-xl"></i>
                </button>
                <div class="flex flex-col text-left">
                    <h1 class="text-slate-900 font-black text-lg leading-none mb-1 tracking-tight">RSU Medical</h1>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] leading-none">User Hub</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="showQR()" class="w-10 h-10 flex items-center justify-center text-slate-600 hover:text-blue-600 transition-colors"><i class="fa-solid fa-qrcode text-lg"></i></button>
                <button onclick="showNotifications()" class="w-10 h-10 flex items-center justify-center text-slate-400 hover:text-slate-600 transition-colors relative">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($upcoming_count > 0): ?><span class="absolute top-1.5 right-1.5 w-4 h-4 bg-red-500 text-white text-[9px] font-black rounded-full border-2 border-white flex items-center justify-center"><?= $upcoming_count ?></span><?php endif; ?>
                </button>
            </div>
        </header>

        <main class="px-6 pt-8 space-y-8">
            <!-- Title Section -->
            <div class="px-1 text-left">
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.3em] mb-2 opacity-70"><?= $thaiDate ?></p>
                <div class="flex items-end justify-between">
                    <h2 class="text-3xl font-black text-slate-900 tracking-tight">Command Center</h2>
                    <div class="w-2 h-2 bg-blue-600 rounded-full mb-2 animate-pulse"></div>
                </div>
            </div>

            <!-- Identity Card -->
            <div onclick="showProfile()" class="relative overflow-hidden bg-gradient-to-br from-[#0052CC] via-[#0066FF] to-[#0052CC] rounded-[3rem] p-8 shadow-[0_25px_50px_-12px_rgba(0,82,204,0.3)] group active:scale-[0.97] transition-all cursor-pointer">
                <div class="absolute -right-6 -top-6 w-48 h-48 bg-white/10 rounded-full blur-3xl group-hover:scale-150 transition-transform duration-1000"></div>
                <div class="relative z-10 text-left">
                    <div class="flex items-center gap-5 mb-10">
                        <div class="w-20 h-20 rounded-[2rem] overflow-hidden border-2 border-white/20 shadow-2xl">
                            <img src="<?= $user['picture_url'] ?? 'https://ui-avatars.com/api/?name='.urlencode($user['full_name']); ?>" class="w-full h-full object-cover">
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-blue-100/80 text-sm font-bold mb-1">สวัสดี 👋</p>
                            <h3 class="text-white text-2xl font-black tracking-tight leading-tight mb-1 truncate"><?= $user['full_name'] ?></h3>
                            <p class="text-blue-100/60 text-[11px] font-black uppercase tracking-[0.1em]">ID: <?= !empty($user['student_personnel_id']) ? $user['student_personnel_id'] : 'N/A' ?></p>
                        </div>
                    </div>
                    <div class="relative flex items-center justify-between pt-6 border-t border-white/10">
                        <div class="flex items-center gap-3 text-white">
                            <i class="fa-solid fa-graduation-cap text-blue-200 text-sm"></i>
                            <p class="text-blue-50 text-[11px] font-bold tracking-wide truncate max-w-[180px]"><?= $user['status'] ?> · RSU</p>
                        </div>
                        <div class="bg-emerald-400/20 border border-emerald-400/30 rounded-full px-4 py-1.5 backdrop-blur-md flex items-center gap-2">
                            <i class="fa-solid fa-circle-check text-emerald-300 text-[10px]"></i>
                            <span class="text-emerald-200 text-[9px] font-black uppercase tracking-[0.15em]">Verified</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4">
                <button onclick="showHistory()" class="bg-white rounded-[2.5rem] p-6 border border-slate-100 shadow-sm flex flex-col items-center text-center active:scale-95 transition-all group">
                    <div class="w-12 h-12 bg-blue-50 rounded-2xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fa-solid fa-calendar-check text-blue-600 text-lg"></i></div>
                    <p class="font-black text-xl text-slate-900 mb-0.5"><?= count($booking_list) ?></p>
                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">การเข้าพบ</p>
                </button>
                <button onclick="showUpcoming('รายการยืมอุปกรณ์')" class="bg-white rounded-[2.5rem] p-6 border border-slate-100 shadow-sm flex flex-col items-center text-center active:scale-95 transition-all group">
                    <div class="w-12 h-12 bg-orange-50 rounded-2xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform"><i class="fa-solid fa-boxes-stacked text-orange-600 text-lg"></i></div>
                    <p class="font-black text-xl text-slate-900 mb-0.5"><?= $borrow_count ?></p>
                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">รายการยืม</p>
                </button>
            </div>

            <!-- Quick Services Menu -->
            <div class="space-y-4">
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-slate-900 font-black text-sm uppercase tracking-widest">Main Menu</h3>
                </div>
                <div class="bg-white rounded-[3rem] border border-slate-100 shadow-sm p-6 pt-8">
                    <div class="grid grid-cols-2 gap-4 mb-8">
                        <button onclick="showCampaigns()" class="relative flex flex-col items-start p-6 rounded-[2.2rem] bg-[#0052CC] text-white active:scale-95 transition-all text-left overflow-hidden group">
                            <div class="w-11 h-11 rounded-2xl bg-white/20 flex items-center justify-center mb-4"><i class="fa-solid fa-calendar-plus text-base"></i></div>
                            <p class="text-[13px] font-black leading-tight tracking-wide">จองคิว /<br>แคมเปญ</p>
                        </button>
                        <a href="../e_Borrow/" class="relative flex flex-col items-start p-6 rounded-[2.2rem] bg-amber-50 border border-amber-100 active:scale-95 transition-all text-left group">
                            <div class="w-11 h-11 rounded-2xl bg-white flex items-center justify-center mb-4"><i class="fa-solid fa-box-archive text-amber-500 text-base"></i></div>
                            <p class="text-[13px] font-black leading-tight tracking-wide text-slate-800">ยืม-คืน<br>e-Borrow</p>
                        </a>
                        <button onclick="showHistory()" class="relative flex flex-col items-start p-6 rounded-[2.2rem] bg-indigo-50 border border-indigo-100 active:scale-95 transition-all text-left group">
                            <div class="w-11 h-11 rounded-2xl bg-white flex items-center justify-center mb-4"><i class="fa-solid fa-clipboard-list text-indigo-500 text-base"></i></div>
                            <p class="text-[13px] font-black leading-tight tracking-wide text-slate-800">ประวัติ<br>การรักษา</p>
                        </button>
                        <button onclick="showProfile()" class="relative flex flex-col items-start p-6 rounded-[2.2rem] bg-slate-50 border border-slate-100 active:scale-95 transition-all text-left group">
                            <div class="w-11 h-11 rounded-2xl bg-white flex items-center justify-center mb-4"><i class="fa-solid fa-gear text-slate-400 text-base"></i></div>
                            <p class="text-[13px] font-black leading-tight tracking-wide text-slate-800">ตั้งค่า<br>โปรไฟล์</p>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Insurance Card (INTL) -->
            <div class="space-y-4">
                <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.3em] px-1 text-left">Medical Coverage</p>
                <div class="bg-slate-900 rounded-[3rem] p-8 shadow-2xl relative overflow-hidden text-left">
                    <div class="absolute -right-8 -bottom-8 w-40 h-40 bg-white/5 rounded-full blur-3xl"></div>
                    <div class="flex items-start justify-between mb-10">
                        <div>
                            <h4 class="text-white font-black text-sm tracking-tight">Insurance Card (INTL)</h4>
                            <p class="text-white/30 text-[9px] font-black uppercase tracking-[0.2em] mt-1.5 leading-none">Muang Thai Insurance</p>
                        </div>
                    </div>
                    <div class="space-y-2 mb-8">
                        <div class="flex items-center gap-2 opacity-40">
                            <p class="text-white text-[10px] font-black uppercase tracking-[0.3em]">Remaining Balance</p>
                        </div>
                        <div class="flex items-baseline gap-1 text-white">
                            <span class="text-[18px] font-black opacity-50">฿</span>
                            <span class="text-4xl font-black tracking-tighter">40,000</span>
                        </div>
                    </div>
                    <div class="flex items-end justify-between pt-6 border-t border-white/10 relative z-10 text-white">
                        <div>
                            <p class="text-white/30 text-[8px] font-black uppercase tracking-[0.2em] mb-1.5">Primary Holder</p>
                            <p class="text-[11px] font-black uppercase tracking-wider truncate max-w-[180px]"><?= $user['full_name'] ?></p>
                        </div>
                        <div class="text-right text-white/30 text-[8px] font-black uppercase tracking-[0.2em]">Expires: INTL ONLY</div>
                    </div>
                </div>
            </div>

            <footer class="pt-10 pb-16 text-center opacity-30">
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.3em]">© 2568 RSU Medical Services</p>
            </footer>
        </main>

        <!-- Navigation -->
        <nav class="fixed bottom-0 left-0 right-0 z-[70] bg-white/90 backdrop-blur-2xl border-t border-slate-50 px-8 py-4 pb-10 flex justify-between items-center max-w-md mx-auto shadow-xl">
            <button onclick="location.reload()" class="flex flex-col items-center gap-1.5 text-blue-600 transition-all scale-110">
                <i class="fa-solid fa-house-chimney text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Home</span>
            </button>
            <button onclick="showHistory()" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all">
                <i class="fa-solid fa-calendar-day text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Booking</span>
            </button>
            <div class="relative -mt-14">
                <button onclick="showCampaigns()" class="w-16 h-16 bg-blue-600 rounded-[1.8rem] rotate-45 flex items-center justify-center text-white shadow-lg active:scale-90 transition-all group">
                    <i class="fa-solid fa-plus text-2xl -rotate-45 group-hover:scale-125 transition-transform"></i>
                </button>
            </div>
            <button onclick="showUpcoming('ภาพรวมสุขภาพ')" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all">
                <i class="fa-solid fa-heart-pulse text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Health</span>
            </button>
            <button onclick="showProfile()" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all">
                <i class="fa-solid fa-user-ninja text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Account</span>
            </button>
        </nav>
    </div>

    <!-- Modals (Moved up for better parsing) -->
    <div id="qr-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideQR()"></div><div class="relative bg-white w-full max-w-[340px] rounded-[3rem] p-10 text-center shadow-2xl animate-in zoom-in duration-300"><div id="qrcode" class="flex justify-center bg-white p-5 rounded-[2rem] shadow-xl border border-slate-100 mx-auto mb-8"></div><h3 class="text-slate-900 font-black text-xl mb-1.5">Identity QR Code</h3><p class="text-blue-600 font-mono font-black text-sm tracking-[0.2em] mb-8"><?= $user['student_personnel_id'] ?></p><button onclick="hideQR()" class="w-full h-16 bg-slate-900 text-white font-black rounded-2xl">ปิดหน้าต่าง</button></div></div>
    <div id="notif-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideNotifications()"></div><div class="relative bg-white w-full max-w-[400px] rounded-[3rem] p-10 text-center shadow-2xl animate-in zoom-in duration-300"><h3 class="text-slate-900 font-black text-xl mb-4">Notifications</h3><div class="text-left text-sm text-slate-500 mb-8">คุณมีนัดหมายสุขภาพที่กำลังจะถึงจำนวน <?= $upcoming_count ?> รายการ</div><button onclick="hideNotifications()" class="w-full h-16 bg-slate-900 text-white font-black rounded-2xl">ปิดหน้าต่าง</button></div></div>
    <div id="profile-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideProfile()"></div><div class="relative bg-white w-full max-w-[400px] rounded-[3rem] p-10 text-center shadow-2xl animate-in zoom-in duration-300"><div class="w-24 h-24 rounded-full overflow-hidden mx-auto mb-4 border-4 border-slate-50 shadow-lg"><img src="<?= $user['picture_url'] ?? 'https://ui-avatars.com/api/?name='.urlencode($user['full_name']); ?>" class="w-full h-full object-cover"></div><h3 class="text-slate-900 font-black text-xl mb-2"><?= $user['full_name'] ?></h3><p class="text-slate-400 text-xs mb-8"><?= $user['status'] ?> · RSU</p><div class="grid grid-cols-2 gap-4"><button onclick="window.location.href='profile.php'" class="h-14 bg-blue-600 text-white font-black rounded-xl text-sm">Profile</button><a href="logout.php" class="h-14 bg-red-50 text-red-600 font-black rounded-xl text-sm flex items-center justify-center">Logout</a></div><button onclick="hideProfile()" class="mt-4 text-slate-300 text-[10px] uppercase font-black tracking-widest">Close</button></div></div>
    <div id="camps-modal" class="fixed inset-0 z-[100] hidden flex items-end justify-center p-0"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideCampaigns()"></div><div class="relative bg-white w-full max-w-md rounded-t-[3rem] p-8 max-h-[85vh] overflow-y-auto shadow-2xl animate-in slide-in-from-bottom duration-300 text-left"><h3 class="text-slate-900 font-black text-2xl mb-6">เลือกแคมเปญ</h3><div class="space-y-4"><?php if (empty($camp_list)): ?><p class="text-slate-300 italic text-center py-10">ยังไม่มีแคมเปญในขณะนี้</p><?php else: ?><?php foreach ($camp_list as $c): $style = getCampStyle($c['type'] ?? ''); $remaining = $c['total_capacity'] - $c['used_seats']; $isFull = ($remaining <= 0); ?><div class="bg-slate-50 p-6 rounded-[2rem] border border-slate-100 flex flex-col gap-4"><div><span class="px-3 py-1 rounded-lg <?= $style['class'] ?> text-[9px] font-black uppercase"><?= $style['label'] ?></span><h4 class="text-slate-900 font-black text-base mt-2"><?= htmlspecialchars($c['title']) ?></h4></div><a href="booking_date.php?campaign_id=<?= $c['id'] ?>" class="w-full h-14 bg-blue-600 text-white font-black rounded-xl flex items-center justify-center text-sm shadow-lg shadow-blue-100">จองแคมเปญนี้</a></div><?php endforeach; ?><?php endif; ?></div><button onclick="hideCampaigns()" class="mt-6 w-full text-center text-slate-300 font-black text-xs uppercase tracking-widest">Back</button></div></div>
    <div id="history-modal" class="fixed inset-0 z-[100] hidden flex items-end justify-center p-0"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideHistory()"></div><div class="relative bg-white w-full max-w-md rounded-t-[3rem] p-8 max-h-[85vh] overflow-y-auto shadow-2xl animate-in slide-in-from-bottom duration-300 text-left"><h3 class="text-slate-900 font-black text-2xl mb-6">ประวัติการจอง</h3><div class="space-y-4"><?php if (empty($booking_list)): ?><p class="text-slate-300 italic text-center py-10">ยังไม่มีประวัติในขณะนี้</p><?php else: ?><?php foreach ($booking_list as $b): $status = getStatusStyle($b['status']); ?><div class="bg-slate-50 p-6 rounded-[2rem] border border-slate-100 flex items-start justify-between"><div><p class="text-slate-900 font-black text-sm"><?= htmlspecialchars($b['camp_name']) ?></p><p class="text-slate-400 text-xs mt-1"><?= date('d M Y', strtotime($b['slot_date'])) ?> · <?= date('H:i', strtotime($b['start_time'])) ?> น.</p></div><span class="px-2 py-1 rounded-lg <?= $status['class'] ?> text-[8px] font-black uppercase"><?= $status['label'] ?></span></div><?php endforeach; ?><?php endif; ?></div><a href="my_bookings.php" class="mt-6 block w-full text-center py-4 bg-indigo-50 text-indigo-600 font-black rounded-xl text-sm">ดูประวัติทั้งหมด</a><button onclick="hideHistory()" class="mt-4 w-full text-center text-slate-300 font-black text-xs uppercase tracking-widest">Back</button></div></div>
    <div id="contact-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideContact()"></div><div class="relative bg-white w-full max-w-[400px] rounded-[3rem] p-10 text-center shadow-2xl animate-in zoom-in duration-300 text-left"><h3 class="text-slate-900 font-black text-2xl mb-6">ติดต่อเรา</h3><div class="space-y-4"><div class="p-5 bg-slate-50 rounded-2xl border border-slate-100"><p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Clinic Phone</p><p class="text-slate-900 font-black">02-791-6000 ต่อ 4499</p></div></div><button onclick="hideContact()" class="mt-8 w-full h-16 bg-slate-900 text-white font-black rounded-2xl">ปิดหน้าต่าง</button></div></div>
    <div id="chat-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideChat()"></div><div class="relative bg-white w-full max-w-[400px] rounded-[3rem] p-10 text-center shadow-2xl animate-in zoom-in duration-300 text-left"><h3 class="text-slate-900 font-black text-2xl mb-4">ฝ่ายสนับสนุน</h3><p class="text-slate-500 mb-8">ขออภัย ระบบแชทยังไม่เปิดให้บริการในขณะนี้ กรุณาติดต่อผ่าน LINE OA</p><button onclick="hideChat()" class="w-full h-16 bg-slate-900 text-white font-black rounded-2xl">ปิดหน้าต่าง</button></div></div>
    <div id="upcoming-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6"><div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideUpcoming()"></div><div class="relative bg-white w-full max-w-[340px] rounded-[3rem] p-10 text-center shadow-2xl animate-in zoom-in duration-300"><h3 class="text-slate-900 font-black text-2xl mb-4">Coming Soon</h3><p class="text-slate-500 text-sm mb-10">ฟีเจอร์ <span id="upcoming-name" class="text-blue-600 font-black"></span> จะพร้อมใช้งานเร็วๆ นี้</p><button onclick="hideUpcoming()" class="w-full h-16 bg-blue-600 text-white font-black rounded-2xl">รับทราบ</button></div></div>

</body>
</html>
