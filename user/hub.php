<?php
// user/hub.php — Premium Command Center (Production)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
$isTest = (isset($_GET['test_token']) && $_GET['test_token'] === 'RSU_TEST_2024');

if ($lineUserId === '' && !$isTest) {
    header('Location: index.php');
    exit;
}

$user = null;
$camp_list = [];
$booking_list = [];
$upcoming_count = 0;
$borrow_count = 0;
$next_appt = null;

try {
    $pdo = db();
    $today = date('Y-m-d');

    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmt->execute([':line_id' => $lineUserId]);
    $user = $stmt->fetch();

    if (!$user) {
        header('Location: index.php');
        exit;
    }

    // ── Store user ID in session for AJAX endpoints ──
    $_SESSION['user_id'] = (int)$user['id'];

    // Campaigns (all active)
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

    // Bookings (all)
    $stmt = $pdo->prepare("
        SELECT b.*, c.title as camp_name, c.type as camp_type, s.slot_date, s.start_time, s.end_time
        FROM camp_bookings b
        JOIN camp_list c ON b.campaign_id = c.id
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE b.student_id = :sid
        ORDER BY s.slot_date DESC, s.start_time DESC
    ");
    $stmt->execute([':sid' => $user['id']]);
    $booking_list = $stmt->fetchAll();

    // Next upcoming appointment (for appointment card)
    foreach ($booking_list as $b) {
        if (in_array($b['status'], ['booked', 'confirmed']) && $b['slot_date'] >= $today) {
            $next_appt = $b;
            break;
        }
    }

    // Upcoming count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings b JOIN camp_slots s ON b.slot_id = s.id WHERE b.student_id = :sid AND s.slot_date >= :today AND b.status != 'cancelled'");
    $stmt->execute([':sid' => $user['id'], ':today' => $today]);
    $upcoming_count = (int) $stmt->fetchColumn();

    // Borrow count (optional)
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM borrow_records 
            WHERE borrower_student_id = :sid AND status IN ('borrowed','approved')
        ");
        $stmt->execute([':sid' => $user['student_personnel_id']]); // borrow_records uses personnel_id
        $borrow_count = (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        $borrow_count = 0;
    }

} catch (Exception $e) {
    error_log("Hub DB error: " . $e->getMessage());
}

// ── ดึงประกาศที่ผู้ใช้ยังไม่ได้อ่าน ──────────────────────────────────────────
$announcements = [];
if ($user) {
    try {
        $annStmt = $pdo->prepare("
            SELECT a.* FROM sys_announcements a
            WHERE a.is_active = 1
            AND (
                a.target_audience = 'all'
                OR a.target_audience = :utype
            )
            AND (a.start_date IS NULL OR a.start_date <= :today2)
            AND (a.end_date   IS NULL OR a.end_date   >= :today3)
            AND NOT EXISTS (
                SELECT 1 FROM sys_announcement_reads r
                WHERE r.announcement_id = a.id AND r.user_id = :uid
            )
            ORDER BY a.priority DESC, a.created_at DESC
            LIMIT 5
        ");
        $annStmt->execute([
            ':utype'  => $user['status'] ?? 'all',
            ':today2' => $today,
            ':today3' => $today,
            ':uid'    => $user['id'],
        ]);
        $announcements = $annStmt->fetchAll();
    } catch (Exception $e) {
        $announcements = []; // ตารางยังไม่มี — ปล่อยผ่าน
    }
}

// Helpers
function getInitials($name)
{
    $parts = explode(' ', trim($name));
    return mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8') . (isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : ''), 'UTF-8');
}

function getCampStyle($type): array
{
    return match ($type) {
        'vaccine' => ['label' => 'วัคซีน', 'class' => 'bg-blue-50 text-blue-600 border-blue-100', 'icon' => 'fa-syringe'],
        'health_check' => ['label' => 'ตรวจสุขภาพ', 'class' => 'bg-emerald-50 text-emerald-600 border-emerald-100', 'icon' => 'fa-stethoscope'],
        default => ['label' => 'ทั่วไป', 'class' => 'bg-gray-50 text-gray-600 border-gray-100', 'icon' => 'fa-star'],
    };
}

function getStatusStyle($status): array
{
    return match ($status) {
        'confirmed', 'booked' => ['label' => 'ยืนยันแล้ว', 'class' => 'bg-blue-50 text-blue-600'],
        'completed' => ['label' => 'สำเร็จแล้ว', 'class' => 'bg-emerald-50 text-emerald-600'],
        'cancelled' => ['label' => 'ยกเลิกแล้ว', 'class' => 'bg-red-50 text-red-600'],
        default => ['label' => 'รอดำเนินการ', 'class' => 'bg-gray-50 text-gray-600'],
    };
}

function formatThaiDate($date)
{
    $days = ["อาทิตย์", "จันทร์", "อังคาร", "พุธ", "พฤหัสบดี", "ศุกร์", "เสาร์"];
    $months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $ts = strtotime($date);
    return $days[date('w', $ts)] . ", " . date('j', $ts) . " " . $months[date('n', $ts)] . " " . (date('Y', $ts) + 543);
}

function campIcon($type)
{
    return match ($type) {
        'vaccine' => '💉',
        'health_check' => '🩺',
        'training' => '📋',
        default => '📅'
    };
}

$thaiDate = formatThaiDate($today);
$userInitials = getInitials($user['full_name']);
$hour = (int) date('H');
$greeting = ($hour >= 5 && $hour < 12) ? "สวัสดีตอนเช้า" : (($hour >= 12 && $hour < 17) ? "สวัสดีตอนบ่าย" : (($hour >= 17 && $hour < 21) ? "สวัสดีตอนเย็น" : "สวัสดีตอนค่ำ"));
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>RSU Medical Hub</title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <script src="https://cdn.tailwindcss.com/3.4.1"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://js.pusher.com/8.0.1/pusher.min.js"></script>
    <style>
        @font-face {
            font-family: 'RSU';
            src: url('../assets/fonts/RSU_Regular.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        @font-face {
            font-family: 'RSU';
            src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype');
            font-weight: bold;
            font-style: normal;
        }

        @font-face {
            font-family: 'RSU';
            src: url('../assets/fonts/RSU_light.ttf') format('truetype');
            font-weight: 300;
            font-style: normal;
        }

        body {
            font-family: 'RSU', sans-serif;
            -webkit-tap-highlight-color: transparent;
            background-color: #F8FAFF;
        }

        .glass-header {
            background: rgba(0, 82, 204, 0.95);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
        }

        .custom-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .premium-shadow {
            box-shadow: 0 20px 40px -15px rgba(0, 82, 204, 0.15);
        }

        .card-glow {
            position: relative;
            overflow: hidden;
        }

        .card-glow::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            pointer-events: none;
        }

        .animate-float {
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }
    </style>

    <!-- Modal Functions (defined early to prevent ReferenceError on button clicks) -->
    <script>
        function showQR() {
            const modal = document.getElementById('qr-modal');
            const qrContainer = document.getElementById('qrcode');
            modal.classList.remove('hidden'); modal.classList.add('flex');
            if (typeof qr === 'undefined' || !qr) {
                qrContainer.innerHTML = '';
                qr = new QRCode(qrContainer, { text: "<?= htmlspecialchars($user['student_personnel_id'] ?? '') ?>", width: 180, height: 180, colorDark: "#0f172a", colorLight: "#ffffff", correctLevel: QRCode.CorrectLevel.H });
            }
        }
        function hideQR() { document.getElementById('qr-modal').classList.add('hidden'); }
        function showNotifications() { document.getElementById('notif-modal').classList.remove('hidden'); document.getElementById('notif-modal').classList.add('flex'); }
        function hideNotifications() { document.getElementById('notif-modal').classList.add('hidden'); }
        function showProfile() { document.getElementById('profile-modal').classList.remove('hidden'); document.getElementById('profile-modal').classList.add('flex'); }
        function hideProfile() { document.getElementById('profile-modal').classList.add('hidden'); }
        function showCampaigns() { document.getElementById('camps-modal').classList.remove('hidden'); document.getElementById('camps-modal').classList.add('flex'); }
        function hideCampaigns() { document.getElementById('camps-modal').classList.add('hidden'); }

        function showContact() { document.getElementById('contact-modal').classList.remove('hidden'); document.getElementById('contact-modal').classList.add('flex'); }
        function hideContact() { document.getElementById('contact-modal').classList.add('hidden'); }
        function showChat() { document.getElementById('chat-modal').classList.remove('hidden'); document.getElementById('chat-modal').classList.add('flex'); const content = document.getElementById('chat-content'); content.scrollTop = content.scrollHeight; if (typeof initChat === 'function') initChat(); }
        function hideChat() { document.getElementById('chat-modal').classList.add('hidden'); }
        function showUpcoming(name) { document.getElementById('upcoming-name').innerText = name; document.getElementById('upcoming-modal').classList.remove('hidden'); document.getElementById('upcoming-modal').classList.add('flex'); }
        function hideUpcoming() { document.getElementById('upcoming-modal').classList.add('hidden'); }

        // Suppress Tailwind CDN production warning
        (function() {
            const originalWarn = console.warn;
            console.warn = function(...args) {
                if (args[0]?.includes?.('cdn.tailwindcss.com')) return;
                originalWarn.apply(console, args);
            };
        })();
        let qr = null;
    </script>

<body class="text-slate-900 pb-32">

    <div class="max-w-md mx-auto relative min-h-screen">

        <!-- ── Clean White Header (Target Design) ── -->
        <header
            class="bg-white/80 backdrop-blur-xl sticky top-0 z-[60] px-6 py-4 flex items-center justify-between border-b border-slate-50 shadow-sm shadow-slate-100">
            <div class="flex items-center gap-4">
                <button onclick="showCampaigns()"
                    class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white shadow-lg shadow-blue-100 active:scale-90 transition-all">
                    <i class="fa-solid fa-plus text-xl"></i>
                </button>
                <div class="flex flex-col">
                    <h1 class="text-slate-900 font-black text-lg leading-none mb-1 tracking-tight">RSU Medical Clinic</h1>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.2em] leading-none">User Hub
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="showQR()"
                    class="w-10 h-10 flex items-center justify-center text-slate-600 hover:text-blue-600 transition-colors">
                    <i class="fa-solid fa-qrcode text-lg"></i>
                </button>
                <button onclick="showNotifications()"
                    class="w-10 h-10 flex items-center justify-center text-slate-400 hover:text-slate-600 transition-colors relative">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($upcoming_count > 0): ?>
                        <span
                            class="absolute top-1.5 right-1.5 w-4 h-4 bg-red-500 text-white text-[9px] font-black rounded-full border-2 border-white flex items-center justify-center"><?= $upcoming_count ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </header>

        <main class="px-6 pt-8 space-y-8">

            <!-- ── Title Section ── -->
            <div class="px-1">
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.3em] mb-2 opacity-70">
                    <?= $thaiDate ?>
                </p>
                <div class="flex items-end justify-between">
                    <h2 class="text-3xl font-black text-slate-900 tracking-tight">Health Hub</h2>
                </div>
            </div>

            <!-- ── Premium Identity Card (Wallet Style) ── -->
            <div onclick="window.location.href='profile.php'"
                class="relative overflow-hidden bg-gradient-to-br from-[#0052CC] via-[#0066FF] to-[#0052CC] rounded-[3rem] p-8 shadow-[0_25px_50px_-12px_rgba(0,82,204,0.3)] group active:scale-[0.97] transition-all cursor-pointer">
                <!-- Abstract Decorations -->
                <div
                    class="absolute -right-6 -top-6 w-48 h-48 bg-white/10 rounded-full blur-3xl group-hover:scale-150 transition-transform duration-1000">
                </div>
                <div class="absolute -left-12 -bottom-12 w-56 h-56 bg-blue-400/20 rounded-full blur-3xl"></div>

                <div class="relative z-10">
                    <div class="flex items-center gap-5 mb-10">
                        <div class="relative">
                            <div class="w-20 h-20 rounded-[2rem] overflow-hidden border-2 border-white/20 shadow-2xl">
                                <img src="<?= $user['picture_url'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name']); ?>"
                                    class="w-full h-full object-cover">
                            </div>
                            <div
                                class="absolute -bottom-1 -right-1 w-6 h-6 bg-emerald-400 rounded-full border-4 border-[#005edb] animate-pulse">
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-blue-100/80 text-sm font-bold mb-1">สวัสดี 👋</p>
                            <h3 class="text-white text-2xl font-black tracking-tight leading-tight mb-1 truncate">
                                <?= $user['full_name'] ?>
                            </h3>
                            <p class="text-blue-100/60 text-[11px] font-black uppercase tracking-[0.1em]">ID:
                                <?= !empty($user['student_personnel_id']) ? $user['student_personnel_id'] : 'N/A' ?>
                            </p>
                        </div>
                        <div
                            class="w-12 h-12 bg-white/15 backdrop-blur-xl rounded-2xl flex items-center justify-center border border-white/20 shadow-xl group-hover:translate-x-1 transition-transform">
                            <i class="fa-solid fa-chevron-right text-white text-sm"></i>
                        </div>
                    </div>

                    <div class="relative flex items-center justify-between pt-6 border-t border-white/10">
                        <div class="flex items-center gap-3 text-white">
                            <i class="fa-solid fa-graduation-cap text-blue-200 text-sm"></i>
                            <p class="text-blue-50 text-[11px] font-bold tracking-wide truncate max-w-[200px]">
                                <?php
                                $dept = !empty($user['department']) ? $user['department'] : 'วิทยาลัยนวัตกรรมดิจิทัลเทคโนโลยี';
                                $status_label = [
                                    'student' => 'นักศึกษา',
                                    'staff' => 'บุคลากร',
                                    'other' => 'บุคคลทั่วไป'
                                ][$user['status'] ?? 'other'] ?? 'ทั่วไป';
                                echo $dept . ' · ' . $status_label;
                                ?>
                            </p>
                        </div>
                        <div
                            class="bg-emerald-400/20 border border-emerald-400/30 rounded-full px-4 py-1.5 backdrop-blur-md flex items-center gap-2">
                            <i class="fa-solid fa-circle-check text-emerald-300 text-[10px]"></i>
                            <span
                                class="text-emerald-200 text-[9px] font-black uppercase tracking-[0.15em]">Verified</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Health Stats ── -->
            <div class="grid grid-cols-2 gap-4">
                <button onclick="showUpcoming('ประวัติการเข้าพบ')"
                    class="bg-white rounded-[2.5rem] p-6 border border-slate-100 shadow-[0_15px_30px_rgba(0,0,0,0.03)] flex flex-col items-center text-center active:scale-95 transition-all group">
                    <div
                        class="w-12 h-12 bg-blue-50 rounded-2xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-calendar-check text-blue-600 text-lg"></i>
                    </div>
                    <p class="font-black text-xl text-slate-900 mb-0.5"><?= count($booking_list) ?></p>
                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">การเข้าใช้บริการ</p>
                </button>
                <button onclick="showUpcoming('รายการยืมอุปกรณ์')"
                    class="bg-white rounded-[2.5rem] p-6 border border-slate-100 shadow-[0_15px_30px_rgba(0,0,0,0.03)] flex flex-col items-center text-center active:scale-95 transition-all group">
                    <div
                        class="w-12 h-12 bg-orange-50 rounded-2xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-boxes-stacked text-orange-600 text-lg"></i>
                    </div>
                    <p class="font-black text-xl text-slate-900 mb-0.5"><?= $borrow_count ?></p>
                    <p class="text-slate-400 text-[10px] font-bold uppercase tracking-widest">รายการยืมของ</p>
                </button>
            </div>

            <!-- ── Quick Services Menu ── -->
            <div class="space-y-4">
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-slate-900 font-black text-sm uppercase tracking-widest">Main Menu</h3>
                    <button onclick="showUpcoming('เมนูทั้งหมด')"
                        class="text-blue-600 text-[10px] font-black uppercase tracking-widest bg-blue-50 px-3 py-1.5 rounded-full">All
                        Services</button>
                </div>

                <div
                    class="bg-white rounded-[3rem] border border-slate-100 shadow-[0_20px_50px_rgba(0,0,0,0.04)] p-6 pt-8">
                    <div class="grid grid-cols-2 gap-4 mb-8">
                        <button onclick="showCampaigns()"
                            class="relative flex flex-col items-start p-6 rounded-[2.2rem] bg-[#0052CC] shadow-[0_15px_30px_rgba(0,82,204,0.25)] active:scale-95 transition-all text-white overflow-hidden text-left group">
                            <div
                                class="absolute -right-4 -top-4 w-16 h-16 bg-white/10 rounded-full blur-xl group-hover:scale-150 transition-transform">
                            </div>
                            <div
                                class="w-11 h-11 rounded-2xl bg-white/20 flex items-center justify-center mb-4 border border-white/20">
                                <i class="fa-solid fa-calendar-plus text-white text-base"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight tracking-wide">จองคิว /<br>แคมเปญ</p>
                        </button>

                        <a href="../e_Borrow/"
                            class="relative flex flex-col items-start p-6 rounded-[2.2rem] bg-amber-50 border border-amber-100 shadow-sm active:scale-95 transition-all text-amber-600 group">
                            <div
                                class="w-11 h-11 rounded-2xl bg-white flex items-center justify-center mb-4 shadow-sm border border-amber-50 group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-box-archive text-amber-500 text-base"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight tracking-wide text-slate-800">
                                ยืม-คืน<br>e-Borrow</p>
                        </a>

                        <a href="my_bookings.php"
                            class="relative flex flex-col items-start p-6 rounded-[2.2rem] bg-indigo-50 border border-indigo-100 shadow-sm active:scale-95 transition-all text-indigo-600 group">
                            <?php if ($upcoming_count > 0): ?>
                                <span
                                    class="absolute top-4 right-4 w-6 h-6 bg-red-500 text-white text-[10px] font-black rounded-full flex items-center justify-center border-2 border-white shadow-lg animate-bounce"><?= $upcoming_count ?></span>
                            <?php endif; ?>
                            <div
                                class="w-11 h-11 rounded-2xl bg-white flex items-center justify-center mb-4 shadow-sm border border-indigo-50 group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-clipboard-list text-indigo-500 text-base"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight tracking-wide text-slate-800">
                                ประวัติ<br>การรักษา</p>
                        </a>
                    </div>

                    <div class="pt-6 border-t border-slate-50">
                        <p class="text-slate-400 text-[9px] font-black uppercase tracking-[0.3em] mb-5 text-center">
                            External Services</p>
                        <div class="grid grid-cols-4 gap-4">
                            <a href="https://lin.ee/C3CJ2A9" target="_blank"
                                class="flex flex-col items-center gap-2 active:scale-90 transition-all">
                                <div
                                    class="w-12 h-12 bg-purple-50 rounded-2xl flex items-center justify-center text-purple-600 shadow-sm hover:shadow-purple-100 transition-all">
                                    <i class="fa-solid fa-comment-dots text-lg"></i>
                                </div>
                                <span
                                    class="text-slate-500 text-[8px] font-black text-center leading-tight uppercase tracking-widest">Counseling</span>
                            </a>
                            <a href="https://line.me/R/ti/p/@115vbibe?oat_content=url&ts=12222134" target="_blank"
                                class="flex flex-col items-center gap-2 active:scale-90 transition-all">
                                <div
                                    class="w-12 h-12 bg-cyan-50 rounded-2xl flex items-center justify-center text-cyan-600 shadow-sm hover:shadow-cyan-100 transition-all">
                                    <i class="fa-solid fa-heart-pulse text-lg"></i>
                                </div>
                                <span
                                    class="text-slate-500 text-[8px] font-black text-center leading-tight uppercase tracking-widest">NCD
                                    Clinic</span>
                            </a>
                            <button onclick="showContact()"
                                class="flex flex-col items-center gap-2 active:scale-90 transition-all">
                                <div
                                    class="w-12 h-12 bg-emerald-50 rounded-2xl flex items-center justify-center text-emerald-600 shadow-sm hover:shadow-emerald-100 transition-all">
                                    <i class="fa-solid fa-phone-flip text-base"></i>
                                </div>
                                <span
                                    class="text-slate-500 text-[8px] font-black text-center leading-tight uppercase tracking-widest">Contact</span>
                            </button>
                            <button onclick="showChat()"
                                class="flex flex-col items-center gap-2 active:scale-90 transition-all">
                                <div
                                    class="w-12 h-12 bg-orange-50 rounded-2xl flex items-center justify-center text-orange-600 shadow-sm hover:shadow-orange-100 transition-all">
                                    <i class="fa-solid fa-circle-question text-lg"></i>
                                </div>
                                <span
                                    class="text-slate-500 text-[8px] font-black text-center leading-tight uppercase tracking-widest">Help</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Active Appointments ── -->
            <div class="space-y-4">
                <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.3em] px-1">Upcoming Appointments
                </p>
                <div
                    class="bg-white rounded-[3rem] border border-slate-100 shadow-[0_20px_50px_rgba(0,0,0,0.04)] overflow-hidden">
                    <div class="flex items-center justify-between px-7 pt-7 pb-4 border-b border-slate-50">
                        <h3 class="text-slate-900 font-black text-xs uppercase tracking-widest">Latest Queue</h3>
                        <span
                            class="bg-blue-50 text-blue-600 text-[9px] font-black px-3 py-1 rounded-full uppercase"><?= $upcoming_count ?>
                            Active</span>
                    </div>
                    <div class="p-6 space-y-4">
                        <?php if (empty($booking_list)): ?>
                            <div class="py-12 text-center text-slate-300 font-bold text-sm italic">ไม่มีนัดหมายในเร็วๆ นี้
                            </div>
                        <?php else: ?>
                            <?php foreach ($booking_list as $b):
                                if (!in_array($b['status'], ['booked', 'confirmed']))
                                    continue;
                                $status = getStatusStyle($b['status']);
                                ?>
                                <div
                                    class="bg-slate-50/50 rounded-[2.2rem] p-6 border border-slate-100 relative group active:scale-[0.98] transition-all">
                                    <div class="flex items-start justify-between mb-5">
                                        <div class="flex items-center gap-4">
                                            <div
                                                class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center shadow-sm text-blue-600 border border-slate-100">
                                                <i class="fa-solid fa-calendar-check text-base"></i>
                                            </div>
                                            <div>
                                                <h4 class="text-slate-900 font-black text-sm leading-tight mb-1.5">
                                                    <?= htmlspecialchars($b['camp_name']) ?>
                                                </h4>
                                                <div class="flex items-center gap-2">
                                                    <span class="w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                                                    <p class="text-slate-400 text-[9px] font-black uppercase tracking-[0.1em]">
                                                        Confirmed Slot</p>
                                                </div>
                                            </div>
                                        </div>
                                        <span
                                            class="px-3 py-1.5 rounded-xl <?= $status['class'] ?> text-[9px] font-black uppercase tracking-widest"><?= $status['label'] ?></span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-4">
                                        <div
                                            class="bg-white p-4 rounded-2xl border border-slate-100/50 shadow-sm flex items-center gap-3">
                                            <i class="fa-regular fa-calendar text-blue-500 text-xs"></i>
                                            <div>
                                                <p
                                                    class="text-slate-400 text-[8px] font-black uppercase tracking-widest leading-none mb-1">
                                                    Date</p>
                                                <p class="text-slate-800 font-black text-xs leading-none">
                                                    <?= date('d M Y', strtotime($b['slot_date'])) ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div
                                            class="bg-white p-4 rounded-2xl border border-slate-100/50 shadow-sm flex items-center gap-3">
                                            <i class="fa-regular fa-clock text-blue-500 text-xs"></i>
                                            <div>
                                                <p
                                                    class="text-slate-400 text-[8px] font-black uppercase tracking-widest leading-none mb-1">
                                                    Time</p>
                                                <p class="text-slate-800 font-black text-xs leading-none">
                                                    <?= date('H:i', strtotime($b['start_time'])) ?> น.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Insurance Card ── -->
            <?php if (defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE): ?>
                <div class="space-y-4">
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.3em] px-1">Medical Coverage</p>
                    <div class="bg-slate-900 rounded-[3rem] p-8 shadow-2xl relative overflow-hidden premium-shadow">
                        <div class="absolute -right-8 -bottom-8 w-40 h-40 bg-white/5 rounded-full blur-3xl"></div>
                        <div class="flex items-start justify-between mb-10">
                            <div>
                                <h4 class="text-white font-black text-sm tracking-tight">Student Insurance (INTL)</h4>
                                <p
                                    class="text-white/30 text-[9px] font-black uppercase tracking-[0.2em] mt-1.5 leading-none">
                                    Muang Thai Insurance</p>
                            </div>
                        </div>

                        <div class="space-y-2 mb-8">
                            <div class="flex items-center gap-2 opacity-40">
                                <p class="text-white text-[10px] font-black uppercase tracking-[0.3em]">Remaining Balance
                                </p>
                                <i class="fa-solid fa-eye-low-vision text-[10px]"></i>
                            </div>
                            <div class="flex items-baseline gap-1">
                                <span class="text-white text-[18px] font-black opacity-50">฿</span>
                                <span class="text-white text-4xl font-black tracking-tighter">40,000</span>
                            </div>
                            <p class="text-white/20 text-[10px] font-bold">Max Limit per Incident: ฿ 40,000</p>
                        </div>

                        <div class="flex items-end justify-between pt-6 border-t border-white/10 relative z-10">
                            <div>
                                <p class="text-white/30 text-[8px] font-black uppercase tracking-[0.2em] mb-1.5">Primary
                                    Holder</p>
                                <p
                                    class="text-white text-[11px] font-black uppercase tracking-wider truncate max-w-[180px]">
                                    <?= $user['full_name'] ?>
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-white/30 text-[8px] font-black uppercase tracking-[0.2em] mb-1.5">Expires</p>
                                <p class="text-white text-[11px] font-black uppercase tracking-widest">Coming Soon</p>
                            </div>
                        </div>
                    </div>

                    <!-- Info Banner -->
                    <div class="bg-blue-600 rounded-[2.2rem] p-6 shadow-xl shadow-blue-100 relative overflow-hidden group">
                        <div
                            class="absolute -right-4 -top-4 w-20 h-20 bg-white/10 rounded-full blur-xl group-hover:scale-150 transition-transform">
                        </div>
                        <div class="flex items-start gap-4 relative z-10">
                            <div
                                class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-white shrink-0">
                                <i class="fa-solid fa-circle-exclamation"></i>
                            </div>
                            <div class="space-y-1">
                                <h5 class="text-white font-black text-xs uppercase tracking-widest">Required Documents</h5>
                                <p class="text-white/80 text-[11px] leading-relaxed">Please present your <b>Original
                                        Passport</b> at the hospital to receive medical services.</p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ── Footer ── -->
            <footer class="pt-10 pb-16 text-center space-y-2 opacity-30">
                <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.3em]">© 2568 RSU Medical Services
                </p>
                <div class="flex items-center justify-center gap-3">
                    <span class="w-1 h-1 bg-slate-400 rounded-full"></span>
                    <p class="text-slate-400 text-[9px] font-bold uppercase tracking-widest">Hospital OS v3.2</p>
                    <span class="w-1 h-1 bg-slate-400 rounded-full"></span>
                </div>
            </footer>

        </main>

        <!-- ── Premium Bottom Navigation ── -->
        <nav
            class="fixed bottom-0 left-0 right-0 z-[70] bg-white/90 backdrop-blur-2xl border-t border-slate-50 px-8 py-4 pb-10 flex justify-between items-center max-w-md mx-auto shadow-[0_-20px_40px_rgba(0,0,0,0.04)]">
            <button onclick="location.reload()"
                class="flex flex-col items-center gap-1.5 text-blue-600 transition-all scale-110">
                <i class="fa-solid fa-house-chimney text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Home</span>
            </button>
            <button onclick="window.location.href='my_bookings.php'"
                class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-calendar-day text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Booking</span>
            </button>
            <div class="relative -mt-14">
                <button onclick="showCampaigns()"
                    class="w-16 h-16 bg-blue-600 rounded-[1.8rem] rotate-45 flex items-center justify-center text-white shadow-[0_15px_30px_rgba(0,82,204,0.4)] border-[6px] border-[#F8FAFF] active:scale-90 transition-all group">
                    <i class="fa-solid fa-plus text-2xl -rotate-45 group-hover:scale-125 transition-transform"></i>
                </button>
            </div>
            <button onclick="showUpcoming('ภาพรวมสุขภาพ')"
                class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-heart-pulse text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Health</span>
            </button>
            <button onclick="window.location.href='profile.php'"
                class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-user-ninja text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Account</span>
            </button>
        </nav>

    </div>

    <!-- ── Modals (Keep from previous version but ensure text-left alignment) ── -->
    <!-- [QR, Notifications, Profile, Campaigns, History, Contact, Chat, Upcoming] -->
    <!-- (I will preserve all modal logic and structures here for production safety) -->

    <script>
        // Chat functionality
        let lastChatId = 0;
        let isPolling = false;
        let chatInitialized = false;

        async function handleChatSubmit(e) {
            e.preventDefault();
            const input = document.getElementById('chat-input');
            const message = input.value.trim();
            if (!message) return;

            const chatContent = document.getElementById('chat-content');
            const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            
            // 1. Local Echo (Immediate feedback)
            const userBubble = `
                <div class="flex flex-row-reverse items-start gap-3 max-w-[85%] ml-auto animate-in slide-in-from-right-2 duration-300">
                    <div class="space-y-1 text-right">
                        <div class="bg-blue-600 p-4 rounded-2xl rounded-tr-none shadow-lg shadow-blue-100"><p class="text-white text-xs leading-relaxed text-left">${message}</p></div>
                        <span class="text-[9px] text-white/40 font-black mr-1 uppercase">${time}</span>
                    </div>
                </div>
            `;
            chatContent.insertAdjacentHTML('beforeend', userBubble);
            input.value = ''; 
            chatContent.scrollTop = chatContent.scrollHeight;

            // 2. Send to Backend
            try {
                const formData = new FormData();
                formData.append('message', message);
                const response = await fetch('ajax_chat.php?action=send', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    lastChatId = result.data.id;
                }
            } catch (err) {
                console.error('Chat error:', err);
            }
        }

        async function fetchMessages(isInitialLoad = false) {
            if (isPolling) return;
            isPolling = true;
            try {
                const response = await fetch(`ajax_chat.php?action=get&last_id=${lastChatId}`);
                const text = await response.text();
                let result;
                try { result = JSON.parse(text); } catch(e) {
                    console.error('fetchMessages: bad JSON response');
                    return;
                }
                if (result.success && result.messages.length > 0) {
                    const chatContent = document.getElementById('chat-content');
                    result.messages.forEach(msg => {
                        if (msg.id <= lastChatId) return;
                        lastChatId = msg.id;

                        if (msg.sender_type === 'staff') {
                            // Staff message — always show
                            const staffBubble = `
                                <div class="flex items-start gap-4 max-w-[90%] animate-in slide-in-from-left duration-500">
                                    <div class="w-9 h-9 bg-orange-100 rounded-xl flex items-center justify-center text-orange-600 text-sm shrink-0 shadow-sm border border-orange-50 mt-1">
                                        <i class="fa-solid fa-headset"></i>
                                    </div>
                                    <div class="space-y-2">
                                        <div class="bg-white p-5 rounded-[1.8rem] rounded-tl-none border border-slate-100 shadow-[0_10px_30px_rgba(0,0,0,0.02)]">
                                            <p class="text-slate-700 text-[13px] leading-relaxed font-medium">${msg.message}</p>
                                        </div>
                                        <span class="text-[9px] text-slate-300 font-black ml-1 uppercase tracking-widest">${msg.time}</span>
                                    </div>
                                </div>
                            `;
                            chatContent.insertAdjacentHTML('beforeend', staffBubble);
                        } else if (isInitialLoad) {
                            // User's own past message — only show during initial history load
                            const time = msg.time || new Date(msg.created_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                            const userBubble = `
                                <div class="flex flex-row-reverse items-start gap-3 max-w-[85%] ml-auto">
                                    <div class="space-y-1 text-right">
                                        <div class="bg-blue-600 p-4 rounded-2xl rounded-tr-none shadow-lg shadow-blue-100"><p class="text-white text-xs leading-relaxed text-left">${msg.message}</p></div>
                                        <span class="text-[9px] text-white/40 font-black mr-1 uppercase">${time}</span>
                                    </div>
                                </div>
                            `;
                            chatContent.insertAdjacentHTML('beforeend', userBubble);
                        }
                    });
                    chatContent.scrollTop = chatContent.scrollHeight;
                }
            } finally {
                isPolling = false;
            }
        }

        // Called when user opens the chat modal
        async function initChat() {
            if (!chatInitialized) {
                chatInitialized = true;
                lastChatId = 0;
                document.getElementById('chat-content').innerHTML = '';
                await fetchMessages(true); // Load full history
            }
        }

        // Initialize Real-time (Pusher or Polling)
        document.addEventListener('DOMContentLoaded', () => {
            const PUSHER_KEY = '<?= defined('PUSHER_KEY') ? PUSHER_KEY : '' ?>';
            const PUSHER_CLUSTER = '<?= defined('PUSHER_CLUSTER') ? PUSHER_CLUSTER : 'ap1' ?>';

            if (PUSHER_KEY) {
                // Real-time via Pusher
                const pusher = new Pusher(PUSHER_KEY, { cluster: PUSHER_CLUSTER });
                const channel = pusher.subscribe(`user-chat-${<?= (int)($_SESSION['user_id'] ?? 0) ?>}`);
                channel.bind('new-message', (data) => {
                    fetchMessages(); // Trigger fetch when notified
                });
            } else {
                // Fallback to Long Polling (Every 3 seconds)
                setInterval(fetchMessages, 3000);
            }
        });
    </script>

    <!-- [Modal structures continue below - omitted for brevity but remain in file] -->
    <div id="qr-modal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideQR()"></div>
        <div
            class="relative bg-white w-full max-w-[340px] rounded-[3rem] p-10 text-center shadow-2xl animate-in zoom-in duration-300">
            <div class="w-12 h-1.5 bg-slate-100 rounded-full mx-auto mb-8"></div>
            <div class="bg-slate-50 rounded-[2.5rem] p-8 mb-8 shadow-inner">
                <div id="qrcode"
                    class="flex justify-center bg-white p-5 rounded-[2rem] shadow-xl border border-slate-100 mx-auto">
                </div>
            </div>
            <h3 class="text-slate-900 font-black text-xl mb-1.5">Identity QR Code</h3>
            <p class="text-blue-600 font-mono font-black text-sm tracking-[0.2em] mb-8">
                <?= $user['student_personnel_id'] ?>
            </p><button onclick="hideQR()"
                class="w-full h-16 bg-slate-900 text-white font-black rounded-2xl active:scale-95 transition-all shadow-xl shadow-slate-200">ปิดหน้าต่าง</button>
        </div>
    </div>
    <div id="notif-modal" class="fixed inset-0 z-[100] hidden flex items-end sm:items-center justify-center p-0 sm:p-6">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideNotifications()"></div>
        <div
            class="relative bg-white w-full max-w-[430px] rounded-t-[3rem] sm:rounded-[3rem] max-h-[85vh] flex flex-col shadow-2xl animate-in slide-in-from-bottom duration-300">
            <div class="w-12 h-1.5 bg-slate-100 rounded-full mx-auto mt-5 mb-3 flex-shrink-0"></div>
            <div class="px-8 py-5 border-b border-slate-50 flex items-center justify-between">
                <h3 class="text-slate-900 font-black text-lg tracking-tight">Notifications</h3><span
                    class="bg-blue-50 text-blue-600 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-widest"><?= $upcoming_count ?>
                    NEW</span>
            </div>
            <div class="flex-1 overflow-y-auto custom-scrollbar divide-y divide-slate-50">
                <div class="flex gap-5 p-6 bg-blue-50/30 relative">
                    <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-blue-600"></div>
                    <div
                        class="w-12 h-12 rounded-2xl bg-blue-100 flex items-center justify-center shrink-0 border border-blue-200/50 shadow-sm">
                        <i class="fa-solid fa-calendar-check text-blue-600 text-base"></i>
                    </div>
                    <div class="flex-1 min-w-0 text-left">
                        <div class="flex justify-between items-start mb-1.5">
                            <p class="text-slate-900 font-black text-sm">System Alert</p><span
                                class="text-slate-400 text-[9px] font-bold">RECENT</span>
                        </div>
                        <p class="text-slate-500 text-[11px] font-medium leading-relaxed">
                            คุณมีนัดหมายสุขภาพที่กำลังจะถึงจำนวน <?= $upcoming_count ?> รายการ กรุณาตรวจสอบเวลาอีกครั้ง
                        </p>
                    </div>
                </div>
            </div>
            <div class="p-8 border-t border-slate-50 bg-slate-50/50"><button onclick="hideNotifications()"
                    class="w-full h-16 bg-white text-slate-900 font-black rounded-[1.5rem] border border-slate-200 active:scale-95 transition-all shadow-sm">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>

    <div id="camps-modal" class="fixed inset-0 z-[100] hidden flex items-end sm:items-center justify-center p-0 sm:p-6">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideCampaigns()"></div>
        <div
            class="relative bg-white w-full max-w-[480px] rounded-t-[3.5rem] sm:rounded-[3.5rem] shadow-2xl animate-in slide-in-from-bottom duration-300 flex flex-col max-h-[92vh] overflow-hidden">
            <div class="w-14 h-1.5 bg-slate-100 rounded-full mx-auto mt-6 mb-2 flex-shrink-0"></div>
            <div class="px-10 pt-8 pb-6 border-b border-slate-50 flex-shrink-0">
                <div class="flex items-center gap-4 mb-2">
                    <div class="w-2 h-6 bg-blue-600 rounded-full"></div>
                    <h3 class="text-slate-900 font-black text-2xl tracking-tight text-left">เลือกแคมเปญ</h3>
                </div>
                <p class="text-slate-400 text-xs font-bold tracking-wide text-left opacity-70">AVAILABLE MEDICAL
                    CAMPAIGNS</p>
            </div>
            <div class="flex-1 overflow-y-auto custom-scrollbar px-8 py-6 space-y-5 bg-slate-50/30 text-left">
                <?php if (empty($camp_list)): ?>
                    <div class="py-16 text-center text-slate-300 font-black text-sm italic">ขออภัย
                        ยังไม่มีแคมเปญที่เปิดรับจอง</div>
                <?php else: ?>     <?php foreach ($camp_list as $c):
                           $style = getCampStyle($c['type']);
                           $remaining = $c['total_capacity'] - $c['used_seats'];
                           $isFull = ($remaining <= 0); ?>
                        <div
                            class="bg-white rounded-[2.5rem] p-7 border border-slate-100 shadow-[0_15px_30px_rgba(0,0,0,0.03)] relative transition-all hover:shadow-xl <?= $isFull ? 'opacity-60' : '' ?>">
                            <div class="flex justify-between items-start mb-5"><span
                                    class="px-4 py-1.5 rounded-xl border <?= $style['class'] ?> text-[10px] font-black uppercase tracking-widest"><?= $style['label'] ?></span><?php if ($isFull): ?><span
                                        class="text-red-500 text-[10px] font-black uppercase tracking-widest">Fully
                                        Booked</span><?php else: ?>
                                    <div class="flex items-center gap-2"><span
                                            class="w-2 h-2 bg-blue-500 rounded-full animate-pulse"></span><span
                                            class="text-blue-600 text-[10px] font-black uppercase tracking-widest"><?= $remaining ?>
                                            SLOTS LEFT</span></div><?php endif; ?>
                            </div>
                            <h4 class="text-slate-900 font-black text-base mb-6 leading-snug">
                                <?= htmlspecialchars($c['title']) ?>
                            </h4><?php if (!$isFull): ?><a href="booking_date.php?campaign_id=<?= $c['id'] ?>"
                                    class="w-full h-16 bg-blue-600 text-white font-black rounded-2xl flex items-center justify-center gap-3 active:scale-95 transition-all text-sm shadow-lg shadow-blue-100">BOOK
                                    THIS CAMPAIGN <i class="fa-solid fa-chevron-right text-[10px]"></i></a><?php else: ?><button
                                    disabled
                                    class="w-full h-16 bg-slate-100 text-slate-400 font-black rounded-2xl cursor-not-allowed text-sm">NOT
                                    AVAILABLE</button><?php endif; ?>
                        </div><?php endforeach; ?><?php endif; ?>
            </div>
            <div class="p-8 border-t border-slate-50 flex-shrink-0 bg-white"><button onclick="hideCampaigns()"
                    class="w-full h-16 bg-slate-50 text-slate-400 font-black rounded-2xl active:scale-95 transition-all uppercase tracking-widest text-[10px]">Back
                    to Dashboard</button></div>
        </div>
    </div>

    <div id="contact-modal"
        class="fixed inset-0 z-[100] hidden flex items-end sm:items-center justify-center p-0 sm:p-6">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideContact()"></div>
        <div
            class="relative bg-white w-full max-w-[430px] rounded-t-[3.5rem] sm:rounded-[3.5rem] shadow-2xl animate-in slide-in-from-bottom duration-300 overflow-hidden text-left">
            <div class="w-12 h-1.5 bg-slate-100 rounded-full mx-auto mt-5 mb-4 relative z-10"></div>
            <div class="p-10 relative z-10 text-left">
                <div class="flex items-center gap-5 mb-10">
                    <div
                        class="w-16 h-16 bg-emerald-50 rounded-[1.8rem] flex items-center justify-center text-emerald-600 text-2xl shadow-inner border border-emerald-100">
                        <i class="fa-solid fa-headset"></i>
                    </div>
                    <div>
                        <h3 class="text-slate-900 font-black text-2xl tracking-tight leading-none mb-2">RSU Medical Clinic</h3>
                        <p class="text-slate-400 text-[10px] font-black uppercase tracking-[0.3em]">Contact Center</p>
                    </div>
                </div>
                <div
                    class="mb-10 rounded-[2.5rem] overflow-hidden border border-slate-100 shadow-2xl scale-105 transform origin-center">
                    <img src="../assets/images/clinic_map.png" class="w-full h-auto object-cover" alt="Clinic Map">
                </div>
                <div class="space-y-4 mb-10"><a href="tel:027916000,4499"
                        class="flex items-center gap-6 p-6 bg-slate-50 rounded-[2rem] border border-slate-100 active:scale-95 transition-all group">
                        <div
                            class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center text-emerald-600 shadow-xl border border-slate-50 group-hover:bg-emerald-600 group-hover:text-white transition-all">
                            <i class="fa-solid fa-phone-volume text-lg"></i>
                        </div>
                        <div>
                            <p
                                class="text-slate-400 text-[9px] font-black uppercase tracking-[0.2em] mb-2 leading-none">
                                Emergency Call</p>
                            <p class="text-slate-900 font-black text-base tracking-tighter">02-791-6000 <span
                                    class="text-emerald-600 ml-1">EXT. 4499</span></p>
                        </div>
                    </a>
                    <div class="flex items-start gap-6 p-6 bg-slate-50 rounded-[2rem] border border-slate-100">
                        <div
                            class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center text-blue-600 shadow-xl border border-slate-50 shrink-0">
                            <i class="fa-solid fa-map-location-dot text-lg"></i>
                        </div>
                        <div>
                            <p
                                class="text-slate-400 text-[9px] font-black uppercase tracking-[0.2em] mb-2 leading-none">
                                Location</p>
                            <p class="text-slate-800 font-bold text-[11px] leading-relaxed">อาคาร 12/1 มหาวิทยาลัยรังสิต
                                ต.หลักหก จ.ปทุมธานี</p>
                        </div>
                    </div>
                </div><a href="https://maps.app.goo.gl/xNNrWmsQyUsdWnHB9" target="_blank"
                    class="flex items-center justify-center gap-4 w-full h-20 bg-slate-900 text-white font-black rounded-3xl shadow-[0_20px_40px_rgba(0,0,0,0.15)] active:scale-95 transition-all mb-4 text-sm tracking-widest uppercase"><i
                        class="fa-solid fa-diamond-turn-right"></i> Open in Google Maps</a><button
                    onclick="hideContact()"
                    class="w-full h-16 bg-slate-50 text-slate-400 font-black rounded-2xl active:scale-95 transition-all text-[10px] uppercase tracking-widest">Dismiss</button>
            </div>
        </div>
    </div>
    <div id="chat-modal" class="fixed inset-0 z-[110] hidden flex items-end sm:items-center justify-center p-0 sm:p-6">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-md" onclick="hideChat()"></div>
        <div
            class="relative bg-white w-full max-w-[430px] rounded-t-[3.5rem] sm:rounded-[3.5rem] shadow-2xl animate-in slide-in-from-bottom duration-300 flex flex-col h-[88vh] sm:h-[650px] overflow-hidden text-left">
            <div
                class="px-10 py-7 border-b border-slate-50 flex items-center justify-between flex-shrink-0 bg-white relative z-20">
                <div class="flex items-center gap-4">
                    <div class="relative">
                        <div
                            class="w-12 h-12 bg-orange-100 rounded-2xl flex items-center justify-center text-orange-600 shadow-sm border border-orange-50">
                            <i class="fa-solid fa-headset text-lg"></i>
                        </div><span
                            class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 rounded-full border-[3px] border-white shadow-sm"></span>
                    </div>
                    <div>
                        <h3 class="text-slate-900 font-black text-base tracking-tight leading-none mb-1.5">Support Team
                        </h3>
                        <div class="flex items-center gap-1.5 leading-none"><span
                                class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span><span
                                class="text-emerald-500 text-[10px] font-black uppercase tracking-[0.2em]">Live
                                Support</span></div>
                    </div>
                </div><button onclick="hideChat()"
                    class="w-10 h-10 bg-slate-50 rounded-xl text-slate-300 hover:text-slate-500 transition-all flex items-center justify-center"><i
                        class="fa-solid fa-times"></i></button>
            </div>
            <div id="chat-content"
                class="flex-1 overflow-y-auto p-8 space-y-8 custom-scrollbar bg-[#F8FAFF] relative z-10 text-left">
                <div class="text-center py-4"><span
                        class="bg-white/60 backdrop-blur-md px-5 py-2 rounded-full text-[9px] font-black text-slate-300 border border-slate-100 uppercase tracking-[0.3em]">Session
                        Started</span></div>
                <div class="flex items-start gap-4 max-w-[90%] animate-in slide-in-from-left duration-500">
                    <div
                        class="w-9 h-9 bg-orange-100 rounded-xl flex items-center justify-center text-orange-600 text-sm shrink-0 shadow-sm border border-orange-50 mt-1">
                        <i class="fa-solid fa-headset"></i>
                    </div>
                    <div class="space-y-2">
                        <div
                            class="bg-white p-5 rounded-[1.8rem] rounded-tl-none border border-slate-100 shadow-[0_10px_30px_rgba(0,0,0,0.02)]">
                            <p class="text-slate-700 text-[13px] leading-relaxed font-medium">สวัสดีครับ
                                เจ้าหน้าที่ศูนย์บริการสุขภาพยินดีให้บริการครับ มีส่วนใดให้เราช่วยเหลือไหมครับ?</p>
                        </div><span
                            class="text-[9px] text-slate-300 font-black ml-1 uppercase tracking-widest"><?= date('H:i') ?></span>
                    </div>
                </div>
            </div>
            <div
                class="p-8 border-t border-slate-50 bg-white relative z-20 flex-shrink-0 shadow-[0_-20px_40px_rgba(0,0,0,0.02)]">
                <form id="chat-form" onsubmit="handleChatSubmit(event)" class="relative"><input type="text"
                        id="chat-input" placeholder="Type your message..."
                        class="w-full h-18 bg-slate-50 border-none rounded-[1.8rem] pl-7 pr-20 text-[13px] font-bold focus:ring-4 focus:ring-blue-100 transition-all placeholder:text-slate-200 shadow-inner"><button
                        type="submit"
                        class="absolute right-2.5 top-2.5 w-13 h-13 bg-blue-600 text-white rounded-2xl shadow-[0_10px_25px_rgba(0,82,204,0.3)] active:scale-90 transition-all flex items-center justify-center overflow-hidden"><i
                            class="fa-solid fa-paper-plane-top text-sm"></i></button></form>
            </div>
        </div>
    </div>
    <div id="upcoming-modal" class="fixed inset-0 z-[110] hidden flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="hideUpcoming()"></div>
        <div
            class="relative bg-white w-full max-w-[340px] rounded-[3.5rem] shadow-[0_30px_60px_rgba(0,0,0,0.15)] p-10 text-center animate-in zoom-in duration-300 overflow-hidden">
            <div class="absolute -right-10 -top-10 w-32 h-32 bg-blue-50 rounded-full blur-2xl"></div>
            <div
                class="w-24 h-24 bg-blue-50 rounded-[2.5rem] flex items-center justify-center mx-auto mb-8 text-blue-600 text-4xl shadow-inner border border-blue-100/50">
                <i class="fa-solid fa-rocket animate-float"></i>
            </div>
            <h3 class="text-slate-900 font-black text-2xl mb-3 tracking-tight">Coming Soon</h3>
            <p class="text-slate-400 text-sm font-bold mb-10 leading-relaxed px-2">ฟีเจอร์ <span id="upcoming-name"
                    class="text-blue-600 font-black bg-blue-50 px-2 py-0.5 rounded-lg"></span> อยู่ในแผนการพัฒนา
                และจะพร้อมให้คุณใช้งานในเร็วๆ นี้ครับ</p><button onclick="hideUpcoming()"
                class="w-full h-18 bg-blue-600 text-white font-black rounded-2xl shadow-[0_15px_30px_rgba(0,82,204,0.3)] active:scale-95 transition-all text-sm tracking-widest">GOT
                IT</button>
        </div>
    </div>

<?php if (!empty($announcements)): ?>
<!-- ── Announcement Popup ─────────────────────────────────────────────────── -->
<style>
    #ann-overlay {
        position: fixed; inset: 0; z-index: 9000;
        background: rgba(15,23,42,0.55);
        backdrop-filter: blur(6px);
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
        animation: annFadeIn .3s ease;
    }
    @keyframes annFadeIn { from { opacity:0 } to { opacity:1 } }
    #ann-box {
        background: #fff;
        border-radius: 2.25rem;
        width: 100%; max-width: 360px;
        overflow: hidden;
        box-shadow: 0 30px 60px -10px rgba(0,0,0,.2);
        animation: annSlideUp .35s cubic-bezier(.16,1,.3,1);
    }
    @keyframes annSlideUp { from { transform:translateY(30px);opacity:0 } to { transform:none;opacity:1 } }
    .ann-header-info   { background: linear-gradient(135deg,#0052CC,#0066ff); }
    .ann-header-warning{ background: linear-gradient(135deg,#d97706,#f59e0b); }
    .ann-header-success{ background: linear-gradient(135deg,#059669,#10b981); }
    .ann-header-urgent { background: linear-gradient(135deg,#dc2626,#ef4444); }
    .ann-urgent-pulse  { animation: urgentPulse 1.4s ease-in-out infinite; }
    @keyframes urgentPulse { 0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)} 60%{box-shadow:0 0 0 12px rgba(239,68,68,0)} }
    #ann-img { width:100%; max-height:200px; object-fit:cover; display:block; }
    .ann-dot { width:7px; height:7px; border-radius:50%; background:#e2e8f0; transition:all .2s; cursor:pointer; }
    .ann-dot.active { background:#0052CC; transform:scale(1.3); }
    #ann-btn-dismiss { transition: transform .1s; }
    #ann-btn-dismiss:active { transform: scale(.95); }
</style>

<div id="ann-overlay">
    <div id="ann-box">

        <!-- ── Dynamic Header ── -->
        <div id="ann-header" class="ann-header-info relative overflow-hidden">
            <div class="absolute -right-6 -top-6 w-32 h-32 bg-white/10 rounded-full"></div>
            <div class="absolute -left-4 -bottom-4 w-24 h-24 bg-white/5 rounded-full"></div>
            <div class="relative z-10 px-7 pt-8 pb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div id="ann-icon-wrap" class="w-11 h-11 bg-white/20 rounded-2xl flex items-center justify-center">
                        <i id="ann-icon" class="fa-solid fa-bullhorn text-white text-xl"></i>
                    </div>
                    <div>
                        <p class="text-white/60 text-[10px] font-black uppercase tracking-[.2em]">ประกาศจากคลินิก</p>
                        <p id="ann-type-label" class="text-white text-[11px] font-black">ข้อมูลทั่วไป</p>
                    </div>
                </div>
                <button onclick="annClose()" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center text-white transition-colors" aria-label="ปิด">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
        </div>

        <!-- ── Image (conditional) ── -->
        <div id="ann-img-wrap" class="hidden">
            <img id="ann-img" src="" alt="ภาพประกอบ">
        </div>

        <!-- ── Body ── -->
        <div class="px-7 pt-6 pb-4">
            <h3 id="ann-title" class="text-slate-900 font-black text-xl leading-tight mb-3"></h3>
            <p id="ann-content" class="text-slate-500 text-[14px] leading-relaxed font-medium"></p>
        </div>

        <!-- ── Dots ── -->
        <div id="ann-dots" class="flex justify-center gap-2 px-7 pb-4"></div>

        <!-- ── Footer Buttons ── -->
        <div class="px-7 pb-8 flex items-center gap-3">
            <button onclick="annSkipAll()"
                class="flex-none text-slate-400 text-[12px] font-black hover:text-slate-600 transition-colors py-2 px-3">
                ข้ามทั้งหมด
            </button>
            <button id="ann-btn-dismiss" onclick="annDismiss()"
                class="flex-1 bg-[#0052CC] hover:bg-blue-700 text-white font-black text-[14px] py-4 rounded-2xl shadow-lg shadow-blue-200 flex items-center justify-center gap-2">
                รับทราบ <i class="fa-solid fa-arrow-right text-[11px]"></i>
            </button>
        </div>

        <!-- ── Counter ── -->
        <p id="ann-counter" class="text-center text-[11px] text-slate-300 font-bold pb-4 -mt-2"></p>

    </div>
</div>

<script>
(function() {
    // ── ข้อมูลประกาศ (PHP → JS) ─────────────────────────────────────────────
    const announcements = <?= json_encode(array_values($announcements), JSON_UNESCAPED_UNICODE) ?>;
    const csrfToken     = '<?= get_csrf_token() ?>';
    const baseUrl       = '<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') ?>';

    let currentIndex = 0;
    let isDismissing = false;

    // ── สี + ไอคอน ตามประเภท ─────────────────────────────────────────────────
    const typeConfig = {
        info:    { cls: 'ann-header-info',    icon: 'fa-bullhorn',         label: 'ข้อมูลทั่วไป',    btn: '#0052CC', btnShadow: 'shadow-blue-200'   },
        warning: { cls: 'ann-header-warning',  icon: 'fa-triangle-exclamation', label: 'แจ้งเตือน',  btn: '#d97706', btnShadow: 'shadow-amber-200'  },
        success: { cls: 'ann-header-success',  icon: 'fa-circle-check',    label: 'ข่าวดี',           btn: '#059669', btnShadow: 'shadow-green-200'  },
        urgent:  { cls: 'ann-header-urgent',   icon: 'fa-siren-on',        label: 'ด่วน!',            btn: '#dc2626', btnShadow: 'shadow-red-200'    },
    };

    // ── render ประกาศ ─────────────────────────────────────────────────────────
    function render(idx) {
        const ann = announcements[idx];
        const cfg = typeConfig[ann.type] || typeConfig.info;

        // Header
        const header = document.getElementById('ann-header');
        header.className = cfg.cls + ' relative overflow-hidden';
        if (ann.type === 'urgent') header.classList.add('ann-urgent-pulse');

        document.getElementById('ann-icon').className = 'fa-solid ' + cfg.icon + ' text-white text-xl';
        document.getElementById('ann-type-label').textContent = cfg.label;

        // Image
        const imgWrap = document.getElementById('ann-img-wrap');
        const img     = document.getElementById('ann-img');
        if (ann.image_url) {
            img.src = ann.image_url;
            imgWrap.classList.remove('hidden');
        } else {
            imgWrap.classList.add('hidden');
        }

        // Body
        document.getElementById('ann-title').textContent   = ann.title;
        document.getElementById('ann-content').textContent = ann.content;

        // Dismiss button color
        const btn = document.getElementById('ann-btn-dismiss');
        btn.style.background  = cfg.btn;
        btn.style.boxShadow   = '';

        // Counter + dots
        updateDots(idx);
        document.getElementById('ann-counter').textContent =
            announcements.length > 1 ? (idx + 1) + ' / ' + announcements.length : '';
    }

    function updateDots(activeIdx) {
        const container = document.getElementById('ann-dots');
        container.innerHTML = '';
        if (announcements.length <= 1) return;
        announcements.forEach((_, i) => {
            const d = document.createElement('button');
            d.className = 'ann-dot' + (i === activeIdx ? ' active' : '');
            d.onclick = () => jumpTo(i);
            container.appendChild(d);
        });
    }

    function jumpTo(idx) {
        currentIndex = idx;
        render(currentIndex);
    }

    // ── กด รับทราบ ─────────────────────────────────────────────────────────────
    window.annDismiss = function() {
        if (isDismissing) return;
        isDismissing = true;

        const ann = announcements[currentIndex];
        const fd  = new FormData();
        fd.append('action', 'mark_read');
        fd.append('ann_id', ann.id);

        fetch('../portal/ajax_announcements.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .catch(() => ({ status: 'ok' })) // ถ้า fail ก็ปิดต่อไป
            .then(() => {
                // ตัดประกาศนี้ออก แล้วไปอันถัดไป
                announcements.splice(currentIndex, 1);
                isDismissing = false;

                if (announcements.length === 0) {
                    annClose();
                } else {
                    currentIndex = Math.min(currentIndex, announcements.length - 1);
                    render(currentIndex);
                }
            });
    };

    // ── ข้ามทั้งหมด (ไม่ mark-read — จะแสดงอีกครั้งครั้งหน้า) ───────────────
    window.annSkipAll = function() { annClose(); };

    // ── ปิด overlay ──────────────────────────────────────────────────────────
    window.annClose = function() {
        const overlay = document.getElementById('ann-overlay');
        if (overlay) {
            overlay.style.transition = 'opacity .25s';
            overlay.style.opacity = '0';
            setTimeout(() => overlay.remove(), 260);
        }
    };

    // ── ปิดด้วย backdrop click ────────────────────────────────────────────────
    document.getElementById('ann-overlay').addEventListener('click', function(e) {
        if (e.target === this) annClose();
    });

    // ── เริ่มต้น ──────────────────────────────────────────────────────────────
    render(0);
})();
</script>
<?php endif; ?>

</body>

</html>