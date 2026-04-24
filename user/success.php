<?php
// user/success.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();

$studentId = isset($_SESSION['evax_student_id']) ? (int)$_SESSION['evax_student_id'] : 0;
if ($studentId <= 0) {
    header('Location: index.php', true, 303);
    exit;
}

$pdo = db();

$booking = null;
try {
    $sql = "
        SELECT
            a.id AS appointment_id,
            c.title AS campaign_title,
            t.slot_date,
            t.start_time,
            t.end_time
        FROM camp_bookings a
        JOIN camp_list c ON a.campaign_id = c.id
        JOIN camp_slots t ON a.slot_id = t.id
        WHERE a.student_id = :sid
        ORDER BY a.created_at DESC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sid' => $studentId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("success.php error: " . $e->getMessage());
    header("Location: my_bookings.php");
    exit;
}

if (!$booking) {
    header('Location: my_bookings.php');
    exit;
}

$fullName      = (string)($_SESSION['evax_full_name'] ?? __('bookings.no_name'));
$appointmentId = $booking['appointment_id'];
$campaignTitle = $booking['campaign_title'];
$slotDate      = (string)$booking['slot_date'];
$startTime     = (string)$booking['start_time'];
$endTime       = (string)$booking['end_time'];

$dateLabel   = ecampaign_format_date($slotDate);
$timeLabel   = substr($startTime, 0, 5) . ' - ' . substr($endTime, 0, 5);
$displayCode = 'CAMP-' . str_pad((string)$appointmentId, 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title><?= htmlspecialchars(__('success.page_title')) ?> - RSU Medical</title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <script src="https://cdn.tailwindcss.com/3.4.1"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_Regular.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'RSU'; src: url('../assets/fonts/RSU_BOLD.ttf') format('truetype'); font-weight: bold; }
        body { font-family: 'RSU', sans-serif; background-color: #F8FAFF; -webkit-tap-highlight-color: transparent; }
        .glass-header { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
    </style>
</head>
<body class="text-slate-900 pb-32">
    <div class="max-w-md mx-auto relative min-h-screen">
        <!-- ── Clean White Header ── -->
        <header class="glass-header sticky top-0 z-[60] px-6 py-5 flex items-center justify-between border-b border-slate-100 shadow-sm shadow-slate-50">
            <div class="w-11 h-11"></div>
            <h1 class="text-lg font-black text-slate-900 tracking-tight">ยืนยันการจอง</h1>
            <div class="w-11 h-11"></div>
        </header>

        <div class="p-5 flex flex-col h-full animate-in fade-in slide-in-from-bottom-8 duration-700">
            <div class="flex-1 flex flex-col items-center">
                <div class="mt-6 mb-8 flex flex-col items-center text-center">
                    <div class="relative mb-4">
                        <div class="absolute inset-0 bg-green-200 rounded-full animate-ping opacity-20"></div>
                        <div class="w-24 h-24 bg-green-100 rounded-full flex items-center justify-center shadow-inner relative z-10">
                            <i class="fa-solid fa-check text-5xl text-green-500"></i>
                        </div>
                    </div>
                    <h2 class="text-3xl font-black text-gray-900 tracking-tight font-prompt"><?= htmlspecialchars(__('success.heading')) ?></h2>
                    <p class="text-sm font-medium text-gray-500 mt-2 font-prompt"><?= htmlspecialchars(__('success.show_qr')) ?></p>
                </div>

                <div class="w-full bg-white rounded-[24px] shadow-xl border border-gray-100 overflow-hidden relative mb-8">
                    <div class="absolute left-0 top-[60%] -mt-4 -ml-4 w-8 h-8 bg-[#F8FAFF] rounded-full border-r border-gray-100 shadow-inner"></div>
                    <div class="absolute right-0 top-[60%] -mt-4 -mr-4 w-8 h-8 bg-[#F8FAFF] rounded-full border-l border-gray-100 shadow-inner"></div>
                    <div class="absolute left-6 right-6 top-[60%] border-t-2 border-dashed border-gray-200"></div>

                    <div class="p-7 pb-8">
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-6 text-center">
                            <?= htmlspecialchars(__('success.details_title')) ?>
                        </h3>

                        <div class="space-y-5">
                            <?php
                            $rows = [
                                ['icon' => 'fa-bullhorn',        'label' => __('success.lbl_campaign'), 'value' => $campaignTitle, 'accent' => true],
                                ['icon' => 'fa-user',            'label' => __('success.lbl_name'),     'value' => $fullName],
                                ['icon' => 'fa-regular fa-calendar','label' => __('success.lbl_date'),  'value' => $dateLabel],
                                ['icon' => 'fa-regular fa-clock','label' => __('success.lbl_time'),     'value' => $timeLabel],
                            ];
                            foreach ($rows as $row): ?>
                            <div class="flex gap-4 items-start">
                                <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center shrink-0">
                                    <i class="fa-solid <?= $row['icon'] ?> text-[#0052CC]"></i>
                                </div>
                                <div>
                                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-0.5">
                                        <?= htmlspecialchars($row['label']) ?>
                                    </p>
                                    <p class="font-bold <?= !empty($row['accent']) ? 'text-[#0052CC]' : 'text-gray-900' ?> text-lg font-prompt">
                                        <?= htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="pt-8 pb-7 px-7 flex flex-col items-center justify-center bg-gray-50">
                        <div class="bg-white p-3 rounded-2xl shadow-sm border border-gray-200 mb-3 relative">
                            <img src="api_qrcode.php?id=<?= $appointmentId ?>" alt="QR Code" class="w-36 h-36 object-contain" />
                        </div>
                        <p class="text-sm font-bold tracking-widest text-gray-600 bg-gray-200 px-4 py-1.5 rounded-full font-prompt">
                            ID: <?= htmlspecialchars($displayCode, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    </div>
                </div>

                <!-- Action Bar -->
                <div class="w-full flex flex-col gap-3 pb-8">
                    <a href="my_bookings.php"
                       class="w-full flex items-center justify-center bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-xl transition-all shadow-sm font-prompt active:scale-[0.98]">
                        <i class="fa-solid fa-list-check mr-2"></i> <?= htmlspecialchars(__('success.view_all_btn')) ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- ── Premium Bottom Navigation ── -->
        <nav class="fixed bottom-0 left-0 right-0 z-[70] bg-white/90 backdrop-blur-2xl border-t border-slate-50 px-8 py-4 pb-10 flex justify-between items-center max-w-md mx-auto shadow-[0_-20px_40px_rgba(0,0,0,0.04)]">
            <button onclick="window.location.href='hub.php'" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-house-chimney text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Home</span>
            </button>
            <button onclick="window.location.href='my_bookings.php'" class="flex flex-col items-center gap-1.5 text-blue-600 transition-all scale-110">
                <i class="fa-solid fa-calendar-day text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Booking</span>
            </button>
            <div class="relative -mt-14">
                <button onclick="window.location.href='hub.php#camps'" class="w-16 h-16 bg-blue-600 rounded-[1.8rem] rotate-45 flex items-center justify-center text-white shadow-[0_15px_30px_rgba(0,82,204,0.4)] border-[6px] border-[#F8FAFF] active:scale-90 transition-all group">
                    <i class="fa-solid fa-plus text-2xl -rotate-45 group-hover:scale-125 transition-transform"></i>
                </button>
            </div>
            <button onclick="window.location.href='hub.php#health'" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-heart-pulse text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Health</span>
            </button>
            <button onclick="window.location.href='profile.php'" class="flex flex-col items-center gap-1.5 text-slate-300 transition-all hover:text-slate-500">
                <i class="fa-solid fa-user-ninja text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Account</span>
            </button>
        </nav>
    </div>
</body>
</html>
