<?php
// user/hub.php — Premium Command Center (Production)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
check_maintenance('e_campaign');

$lineUserId = $_SESSION['line_user_id'] ?? '';
$_testToken = $__secrets['PLAYWRIGHT_TEST_TOKEN'] ?? '';
$isTest = $_testToken !== '' && isset($_GET['test_token']) && hash_equals($_testToken, $_GET['test_token']);

if ($lineUserId === '' && !$isTest) {
    header('Location: index.php');
    exit;
}

$user = null;
$camp_list = [];
$booking_list = [];
$upcoming_count = 0;
$borrow_count = 0;
$borrow_pending_count = 0;
$borrow_overdue_count = 0;
$borrow_total_fine = 0.0;
$borrow_active = [];
$next_appt = null;

try {
    $pdo = db();
    $today = date('Y-m-d');

    // Self-healing migration: dedicated member_id column for QR / check-in
    try { $pdo->exec("ALTER TABLE sys_users ADD COLUMN IF NOT EXISTS member_id VARCHAR(20) NOT NULL DEFAULT ''"); } catch (PDOException) {}

    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmt->execute([':line_id' => $lineUserId]);
    $user = $stmt->fetch();

    if (!$user) {
        header('Location: index.php');
        exit;
    }

    // Lazy backfill: assign a member_id to any user that doesn't have one yet
    if (empty($user['member_id'])) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = generateMemberId();
            try {
                $upd = $pdo->prepare("UPDATE sys_users SET member_id = :mid WHERE id = :id AND (member_id IS NULL OR member_id = '')");
                $upd->execute([':mid' => $candidate, ':id' => $user['id']]);
                if ($upd->rowCount() > 0) {
                    $user['member_id'] = $candidate;
                    break;
                }
                // rowCount==0 means another request raced us; refetch and stop
                $rs = $pdo->prepare("SELECT member_id FROM sys_users WHERE id = :id");
                $rs->execute([':id' => $user['id']]);
                $user['member_id'] = (string) $rs->fetchColumn();
                break;
            } catch (PDOException $e) {
                // Highly unlikely random collision — try again
                continue;
            }
        }
    }

    // ── Store user ID in session for AJAX endpoints ──
    $_SESSION['user_id'] = (int)$user['id'];

    // Campaigns (active, coming_soon, full)
    // Draft and Archived are hidden from users
    $stmt = $pdo->prepare("
        SELECT c.*,
               (SELECT COUNT(*) FROM camp_bookings a WHERE a.campaign_id = c.id AND a.status IN ('booked', 'confirmed')) as used_seats
        FROM camp_list c
        WHERE c.status IN ('active', 'coming_soon', 'full')
        AND (c.available_until IS NULL OR c.available_until >= :today)
        ORDER BY 
            CASE WHEN c.status = 'active' THEN 0 ELSE 1 END ASC,
            c.created_at DESC
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

    // Borrow data (active items + pending fines)
    $borrow_active = [];
    $borrow_total_fine = 0.0;
    try {
        $stmt = $pdo->prepare("
            SELECT t.id AS transaction_id, t.borrow_date, t.due_date,
                   t.approval_status, t.status,
                   ei.name AS equipment_name,
                   et.image_url, et.name AS type_name
            FROM borrow_records t
            JOIN borrow_items ei ON t.item_id = ei.id
            JOIN borrow_categories et ON t.type_id = et.id
            WHERE t.borrower_student_id = :sid
              AND t.status = 'borrowed'
              AND t.approval_status IN ('approved','pending')
            ORDER BY t.borrow_date DESC
        ");
        $stmt->execute([':sid' => $user['id']]);
        $borrow_active = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $borrow_count = count($borrow_active);
    } catch (Exception $e) {
        $borrow_count = 0;
    }
    // Derived alert counters used by notification bell
    $borrow_pending_count = 0;
    $borrow_overdue_count = 0;
    foreach ($borrow_active as $b) {
        if (($b['approval_status'] ?? '') === 'pending') {
            $borrow_pending_count++;
        } elseif (($b['approval_status'] ?? '') === 'approved'
                  && !empty($b['due_date'])
                  && $b['due_date'] < $today) {
            $borrow_overdue_count++;
        }
    }
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(f.amount), 0) AS total
            FROM borrow_fines f
            JOIN borrow_records t ON f.transaction_id = t.id
            WHERE t.borrower_student_id = :sid AND f.status = 'pending'
        ");
        $stmt->execute([':sid' => $user['id']]);
        $borrow_total_fine = (float) $stmt->fetchColumn();
    } catch (Exception $e) {
        $borrow_total_fine = 0.0;
    }

    // Insurance record (linked by student_personnel_id = member_id)
    $insurance = null;
    if (defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE && !empty($user['student_personnel_id'])) {
        try {
            $insStmt = $pdo->prepare("SELECT * FROM insurance_members WHERE member_id = :mid LIMIT 1");
            $insStmt->execute([':mid' => $user['student_personnel_id']]);
            $insurance = $insStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) { /* table may not exist yet */ }
    }

} catch (Exception $e) {
    error_log("Hub DB error: " . $e->getMessage());
}

// ── Health Overview Summary ────────────────────────────────────────────────
$healthOverview = [
    'vaccine_total'      => 0,
    'vaccine_last'       => null,
    'vaccine_next_due'   => null,
    'healthcheck_total'  => 0,
    'healthcheck_last'   => null,
    'upcoming_list'      => [],
];
if ($user && isset($pdo)) {
    try {
        // Vaccination summary
        $vStmt = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   MAX(vaccinated_at) AS last_at
            FROM user_vaccination_records
            WHERE user_id = :uid AND status = 'completed'
        ");
        $vStmt->execute([':uid' => $user['id']]);
        $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);
        if ($vRow) {
            $healthOverview['vaccine_total'] = (int)($vRow['total'] ?? 0);
            $healthOverview['vaccine_last']  = $vRow['last_at'] ?: null;
        }

        // Last vaccination details (vaccine name)
        $vlStmt = $pdo->prepare("
            SELECT vaccine_name, dose_number, vaccinated_at
            FROM user_vaccination_records
            WHERE user_id = :uid AND status = 'completed'
            ORDER BY vaccinated_at DESC LIMIT 1
        ");
        $vlStmt->execute([':uid' => $user['id']]);
        $healthOverview['vaccine_last_detail'] = $vlStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Next due booster
        $ndStmt = $pdo->prepare("
            SELECT vaccine_name, next_due_date
            FROM user_vaccination_records
            WHERE user_id = :uid AND status = 'completed'
              AND next_due_date IS NOT NULL AND next_due_date >= :today
            ORDER BY next_due_date ASC LIMIT 1
        ");
        $ndStmt->execute([':uid' => $user['id'], ':today' => $today]);
        $healthOverview['vaccine_next_due'] = $ndStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) { /* table may not exist yet */ }

    try {
        // Health check summary (from camp_bookings + camp_list)
        $hcStmt = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   MAX(s.slot_date) AS last_at
            FROM camp_bookings b
            JOIN camp_list c ON b.campaign_id = c.id
            JOIN camp_slots s ON b.slot_id = s.id
            WHERE b.student_id = :sid
              AND c.type = 'health_check'
              AND b.status = 'completed'
        ");
        $hcStmt->execute([':sid' => $user['id']]);
        $hcRow = $hcStmt->fetch(PDO::FETCH_ASSOC);
        if ($hcRow) {
            $healthOverview['healthcheck_total'] = (int)($hcRow['total'] ?? 0);
            $healthOverview['healthcheck_last']  = $hcRow['last_at'] ?: null;
        }
    } catch (Exception $e) { /* ignore */ }

    // Upcoming appointments (top 3)
    foreach ($booking_list as $b) {
        if (in_array($b['status'], ['booked', 'confirmed'], true) && $b['slot_date'] >= $today) {
            $healthOverview['upcoming_list'][] = [
                'camp_name'  => $b['camp_name'],
                'camp_type'  => $b['camp_type'],
                'slot_date'  => $b['slot_date'],
                'start_time' => $b['start_time'],
                'end_time'   => $b['end_time'],
            ];
            if (count($healthOverview['upcoming_list']) >= 3) break;
        }
    }
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
function generateMemberId(): string
{
    return 'RSU-' . strtoupper(bin2hex(random_bytes(4)));
}

function getInitials($name)
{
    $parts = explode(' ', trim($name));
    return mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8') . (isset($parts[1]) ? mb_substr($parts[1], 0, 1, 'UTF-8') : ''), 'UTF-8');
}

function getCampStyle($type): array
{
    return match ($type) {
        'vaccine' => ['label' => 'วัคซีน', 'class' => 'bg-emerald-50 text-emerald-600 border-emerald-100', 'icon' => 'fa-syringe'],
        'health_check' => ['label' => 'ตรวจสุขภาพ', 'class' => 'bg-green-50 text-green-600 border-green-100', 'icon' => 'fa-stethoscope'],
        default => ['label' => 'ทั่วไป', 'class' => 'bg-gray-50 text-gray-600 border-gray-100', 'icon' => 'fa-star'],
    };
}

function getStatusStyle($status): array
{
    return match ($status) {
        'confirmed', 'booked' => ['label' => 'ยืนยันแล้ว', 'class' => 'bg-emerald-50 text-emerald-600'],
        'completed' => ['label' => 'สำเร็จแล้ว', 'class' => 'bg-green-50 text-green-600'],
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

function maskCitizenId(?string $citizenId): string
{
    $digits = preg_replace('/\D+/', '', (string)$citizenId);
    if ($digits === '') return '—';
    if (strlen($digits) <= 5) return str_repeat('*', strlen($digits));
    return substr($digits, 0, 3) . str_repeat('*', max(0, strlen($digits) - 5)) . substr($digits, -2);
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

// ── Smart Hero card (priority-driven "today" focus) ──────────────────────
$smartHero = null;
if ($next_appt) {
    $daysUntil = (int) ((strtotime($next_appt['slot_date']) - strtotime($today)) / 86400);
    $smartHero = [
        'kind'       => 'appointment',
        'eyebrow'    => 'นัดหมายถัดไป',
        'title'      => $next_appt['camp_name'],
        'detail'     => formatThaiDate($next_appt['slot_date']) . ' · ' . substr((string)$next_appt['start_time'], 0, 5) . ' น.',
        'days_until' => $daysUntil,
        'icon'       => 'fa-calendar-check',
        'theme'      => 'brand',
        'action'     => "window.location.href='my_bookings.php'",
        'cta_label'  => 'ดูรายละเอียด',
    ];
} elseif (!empty($borrow_overdue_count)) {
    $smartHero = [
        'kind'      => 'overdue',
        'eyebrow'   => 'เกินกำหนดคืน',
        'title'     => "อุปกรณ์ {$borrow_overdue_count} รายการเลยกำหนดคืน",
        'detail'    => 'ติดต่อเจ้าหน้าที่เพื่อคืนของและชำระค่าปรับ',
        'icon'      => 'fa-triangle-exclamation',
        'theme'     => 'rose',
        'action'    => 'showBorrow()',
        'cta_label' => 'จัดการตอนนี้',
    ];
} elseif (!empty($healthOverview['vaccine_next_due'])) {
    $vd = $healthOverview['vaccine_next_due'];
    $smartHero = [
        'kind'      => 'vaccine',
        'eyebrow'   => 'วัคซีนครบกำหนด',
        'title'     => $vd['vaccine_name'],
        'detail'    => 'ครบกำหนด ' . formatThaiDate($vd['next_due_date']),
        'icon'      => 'fa-syringe',
        'theme'     => 'amber',
        'action'    => 'showCampaigns()',
        'cta_label' => 'จองนัด',
    ];
} elseif (!empty($borrow_pending_count)) {
    $smartHero = [
        'kind'      => 'pending',
        'eyebrow'   => 'คำขอรออนุมัติ',
        'title'     => "{$borrow_pending_count} รายการรอเจ้าหน้าที่อนุมัติ",
        'detail'    => 'แตะดูสถานะคำขอ',
        'icon'      => 'fa-hourglass-half',
        'theme'     => 'amber',
        'action'    => 'showBorrow()',
        'cta_label' => 'ดูคำขอ',
    ];
} else {
    $smartHero = [
        'kind'      => 'empty',
        'eyebrow'   => 'วันนี้ของคุณ',
        'title'     => 'ไม่มีรายการเร่งด่วน',
        'detail'    => 'ดูแลสุขภาพของคุณ — ตรวจสุขภาพประจำปีหรือฉีดวัคซีนตามกำหนด',
        'icon'      => 'fa-heart-pulse',
        'theme'     => 'brand',
        'action'    => 'showCampaigns()',
        'cta_label' => 'ดูแคมเปญ',
    ];
}

$heroThemes = [
    'brand' => ['bg' => 'from-emerald-500 to-emerald-600', 'shadow' => 'shadow-[0_15px_40px_rgba(46,158,99,0.25)]', 'btn' => 'bg-white text-[#1f7a4d]'],
    'amber' => ['bg' => 'from-amber-500 to-orange-500',    'shadow' => 'shadow-[0_15px_40px_rgba(245,158,11,0.25)]', 'btn' => 'bg-white text-amber-700'],
    'rose'  => ['bg' => 'from-rose-500 to-rose-600',       'shadow' => 'shadow-[0_15px_40px_rgba(225,29,72,0.25)]',  'btn' => 'bg-white text-rose-600'],
];
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>RSU Medical Hub</title>
    <link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . htmlspecialchars(SITE_LOGO, ENT_QUOTES, 'UTF-8') : '../favicon.ico?v=' . APP_VERSION ?>">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css?v=<?= APP_VERSION ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/rsufont.css?v=<?= APP_VERSION ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://js.pusher.com/8.0.1/pusher.min.js"></script>
    <style>
        body {
            -webkit-tap-highlight-color: transparent;
            background-color: #F8FAFF;
        }

        .glass-header {
            background: rgba(46, 158, 99, 0.95);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
        }

        .custom-scrollbar::-webkit-scrollbar {
            display: none;
        }

        .premium-shadow {
            box-shadow: 0 20px 40px -15px rgba(46, 158, 99, 0.15);
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
        function showQR(bookingId = null, title = 'Identity QR Code', code = "<?= htmlspecialchars($user['member_id'] ?? '') ?>") {
            const modal = document.getElementById('qr-modal');
            const qrContainer = document.getElementById('qrcode');
            const modalTitle = document.getElementById('qr-modal-title');
            const modalCode = document.getElementById('qr-modal-code');
            modal.classList.remove('hidden'); modal.classList.add('flex');
            modalTitle.textContent = title || 'Booking QR Code';
            modalCode.textContent = code || '';
            if (bookingId) {
                qr = null;
                qrContainer.innerHTML = `<img src="api_qrcode.php?id=${encodeURIComponent(bookingId)}" alt="Booking QR Code" class="w-[180px] h-[180px] object-contain">`;
            } else if (typeof qr === 'undefined' || !qr) {
                qrContainer.innerHTML = '';
                // QR payload format: MEMBER:{member_id}:{db_id}
                // Scanner can resolve via member_id (preferred) or db_id (fallback).
                const qrText = "MEMBER:<?= htmlspecialchars($user['member_id'] ?? '') ?>:<?= (int) ($user['id'] ?? 0) ?>";
                qr = new QRCode(qrContainer, { text: qrText, width: 180, height: 180, colorDark: "#0f172a", colorLight: "#ffffff", correctLevel: QRCode.CorrectLevel.H });
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
        function showBorrow() {
            const m = document.getElementById('borrow-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
        }
        function hideBorrow() { document.getElementById('borrow-modal').classList.add('hidden'); }

        // ── Borrow Flow (multi-step) ─────────────────────────────────────
        const bfState = { step: 1, data: null, selected: null, loaded: false };

        function showBorrowFlow() {
            const m = document.getElementById('borrow-flow-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
            bfState.step = 1;
            bfState.selected = null;
            bfRenderStep();
            if (!bfState.loaded) bfLoadData();
        }
        function hideBorrowFlow() {
            document.getElementById('borrow-flow-modal').classList.add('hidden');
            document.getElementById('borrow-flow-modal').classList.remove('flex');
        }

        function bfShowError(msg) {
            const e = document.getElementById('bf-error');
            document.getElementById('bf-error-text').textContent = msg;
            e.classList.remove('hidden');
            setTimeout(() => e.classList.add('hidden'), 4000);
        }

        async function bfLoadData() {
            document.getElementById('bf-loading').classList.remove('hidden');
            document.getElementById('bf-step-1').classList.add('hidden');
            try {
                const res = await fetch('ajax_borrow_data.php');
                const json = await res.json();
                if (!json.ok) throw new Error(json.error || 'load failed');
                bfState.data = json;
                bfState.loaded = true;
                bfRenderCategories(json.categories || []);
                bfRenderStaff(json.staff || []);
                document.getElementById('bf-step-1').classList.remove('hidden');
            } catch (err) {
                bfShowError('ไม่สามารถโหลดข้อมูลได้ กรุณาลองใหม่');
            } finally {
                document.getElementById('bf-loading').classList.add('hidden');
            }
        }

        function escHtml(s) {
            return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
        }

        function bfRenderCategories(cats) {
            const grid = document.getElementById('bf-cat-grid');
            if (!cats.length) {
                grid.innerHTML = '';
                document.getElementById('bf-cat-empty').classList.remove('hidden');
                return;
            }
            document.getElementById('bf-cat-empty').classList.add('hidden');
            grid.innerHTML = cats.map(c => `
                <button type="button" data-name="${escHtml(c.name).toLowerCase()}"
                    onclick="bfSelectCategory(${c.id})"
                    class="bf-cat-card bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden flex flex-col text-left active:scale-95 transition-all">
                    <div class="aspect-[4/3] bg-slate-50 relative flex items-center justify-center overflow-hidden">
                        ${c.image_url
                            ? `<img src="../e_Borrow/${escHtml(c.image_url)}" alt="" class="w-full h-full object-cover" onerror="this.outerHTML='<i class=\\'fa-solid fa-image text-slate-300 text-2xl\\'></i>'">`
                            : `<i class="fa-solid fa-camera text-slate-300 text-2xl"></i>`
                        }
                        <span class="absolute top-2 right-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-white/90 text-[#2e9e63] text-[10px] font-black shadow-sm">
                            <i class="fa-solid fa-check-circle text-[8px]"></i> ว่าง ${parseInt(c.available_quantity, 10)}
                        </span>
                    </div>
                    <div class="p-3 flex-1">
                        <p class="text-[13px] font-black text-slate-900 leading-tight line-clamp-2">${escHtml(c.name)}</p>
                        <p class="mt-1 text-[11px] font-bold text-slate-400 line-clamp-2">${escHtml(c.description || '')}</p>
                    </div>
                </button>
            `).join('');
        }

        function bfRenderStaff(staff) {
            const sel = document.getElementById('bf-staff');
            if (!staff.length) {
                sel.innerHTML = '<option value="">— ไม่มีเจ้าหน้าที่ —</option>';
                return;
            }
            sel.innerHTML = '<option value="">— กรุณาเลือก —</option>'
                + staff.map(s => `<option value="${s.id}">${escHtml(s.full_name)}</option>`).join('');
        }

        function bfSelectCategory(id) {
            const cat = (bfState.data?.categories || []).find(c => parseInt(c.id, 10) === parseInt(id, 10));
            if (!cat) return;
            bfState.selected = cat;
            // Populate selected card
            document.getElementById('bf-selected-name').textContent = cat.name;
            document.getElementById('bf-selected-stock').textContent = `ว่าง ${parseInt(cat.available_quantity, 10)} ชิ้น · ${cat.description || ''}`;
            const thumb = document.getElementById('bf-selected-thumb');
            if (cat.image_url) {
                thumb.innerHTML = `<img src="../e_Borrow/${escHtml(cat.image_url)}" alt="" class="w-full h-full object-cover">`;
            } else {
                thumb.innerHTML = '<i class="fa-solid fa-stethoscope"></i>';
            }
            bfState.step = 2;
            bfRenderStep();
        }

        function bfRenderStep() {
            const step = bfState.step;
            document.getElementById('bf-step-num').textContent = step;
            ['bf-step-1','bf-step-2','bf-step-3'].forEach((id, i) => {
                document.getElementById(id).classList.toggle('hidden', (i + 1) !== step);
                document.getElementById(`bf-bar-${i+1}`).classList.toggle('bg-[#2e9e63]', (i + 1) <= step);
                document.getElementById(`bf-bar-${i+1}`).classList.toggle('bg-slate-200', (i + 1) > step);
            });
            const titles = {
                1: ['เลือกอุปกรณ์', 'เลือกประเภทอุปกรณ์ที่ต้องการยืม'],
                2: ['กรอกข้อมูลคำขอ', 'ระบุเหตุผล วันคืน และเจ้าหน้าที่ผู้อนุมัติ'],
                3: ['ตรวจสอบและยืนยัน', 'กรุณาตรวจข้อมูลให้ถูกต้องก่อนส่งคำขอ'],
            };
            document.getElementById('bf-title').textContent = titles[step][0];
            document.getElementById('bf-subtitle').textContent = titles[step][1];

            // Footer buttons
            document.getElementById('bf-back-btn').classList.toggle('hidden', step === 1);
            document.getElementById('bf-next-btn').classList.toggle('hidden', step !== 2);
            document.getElementById('bf-submit-btn').classList.toggle('hidden', step !== 3);
        }

        function bfBack() {
            if (bfState.step > 1) {
                bfState.step--;
                bfRenderStep();
            }
        }

        function bfNext() {
            // Validate step 2
            const reason = document.getElementById('bf-reason').value.trim();
            const due    = document.getElementById('bf-due').value;
            const staff  = document.getElementById('bf-staff').value;
            if (!reason) return bfShowError('กรุณาระบุเหตุผลการยืม');
            if (!due)    return bfShowError('กรุณาเลือกวันที่กำหนดคืน');
            if (!staff)  return bfShowError('กรุณาเลือกเจ้าหน้าที่ผู้อนุมัติ');
            const file = document.getElementById('bf-file').files[0];
            if (file && file.size > 5 * 1024 * 1024) return bfShowError('ไฟล์ใหญ่เกิน 5MB');

            // Populate confirmation
            document.getElementById('bf-sum-name').textContent = bfState.selected?.name || '-';
            document.getElementById('bf-sum-due').textContent = new Date(due).toLocaleDateString('th-TH', { year: 'numeric', month: 'long', day: 'numeric' });
            const staffOpt = document.getElementById('bf-staff').options[document.getElementById('bf-staff').selectedIndex];
            document.getElementById('bf-sum-staff').textContent = staffOpt?.textContent || '-';
            document.getElementById('bf-sum-reason').textContent = reason;
            const fileRow = document.getElementById('bf-sum-file-row');
            if (file) {
                document.getElementById('bf-sum-file').textContent = file.name;
                fileRow.classList.remove('hidden');
                fileRow.classList.add('flex');
            } else {
                fileRow.classList.add('hidden');
                fileRow.classList.remove('flex');
            }
            bfState.step = 3;
            bfRenderStep();
        }

        async function bfSubmit() {
            const btn = document.getElementById('bf-submit-btn');
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>กำลังส่ง...';

            const fd = new FormData();
            fd.append('type_id', bfState.selected.id);
            fd.append('reason_for_borrowing', document.getElementById('bf-reason').value.trim());
            fd.append('lending_staff_id', document.getElementById('bf-staff').value);
            fd.append('due_date', document.getElementById('bf-due').value);
            const file = document.getElementById('bf-file').files[0];
            if (file) fd.append('attachment', file);

            try {
                const res = await fetch('../e_Borrow/process/request_borrow_process.php', {
                    method: 'POST',
                    body: fd,
                });
                const json = await res.json();
                if (json.status !== 'success') throw new Error(json.message || 'ส่งคำขอไม่สำเร็จ');
                hideBorrowFlow();
                // Reload page to refresh borrow stats + active list in hub
                window.location.reload();
            } catch (err) {
                bfShowError(err.message || 'เกิดข้อผิดพลาด');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }

        // Search filter on step 1
        document.addEventListener('DOMContentLoaded', () => {
            const search = document.getElementById('bf-search');
            if (!search) return;
            search.addEventListener('input', () => {
                const q = search.value.trim().toLowerCase();
                let found = 0;
                document.querySelectorAll('.bf-cat-card').forEach(card => {
                    const match = (card.dataset.name || '').includes(q);
                    card.style.display = match ? 'flex' : 'none';
                    if (match) found++;
                });
                document.getElementById('bf-cat-empty').classList.toggle('hidden', found > 0);
            });
        });

        // ── Tab switcher (Today / Records / Services) ────────────────────
        function switchTab(name) {
            const valid = ['today', 'records', 'services'];
            if (!valid.includes(name)) name = 'today';

            document.querySelectorAll('[data-tab-pane]').forEach(pane => {
                pane.classList.toggle('hidden', pane.dataset.tabPane !== name);
            });

            // Top tab bar styling
            document.querySelectorAll('[data-tab-btn]').forEach(btn => {
                const active = btn.dataset.tabBtn === name;
                btn.classList.toggle('bg-[#2e9e63]', active);
                btn.classList.toggle('text-white', active);
                btn.classList.toggle('shadow-sm', active);
                btn.classList.toggle('text-slate-400', !active);
            });

            // Bottom nav styling
            document.querySelectorAll('[data-bottom-tab]').forEach(btn => {
                const active = btn.dataset.bottomTab === name;
                btn.classList.toggle('text-green-600', active);
                btn.classList.toggle('scale-110', active);
                btn.classList.toggle('text-slate-300', !active);
            });

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ── Open borrow modals + tabs via URL hash (deep-link) ──────────
        document.addEventListener('DOMContentLoaded', () => {
            switchTab('today'); // initial state
            const hash = (window.location.hash || '').toLowerCase();
            if (!hash) return;
            history.replaceState(null, '', window.location.pathname + window.location.search);
            if (hash === '#borrow-flow') showBorrowFlow();
            else if (hash === '#borrow-history') showBorrowHistory();
            else if (hash === '#borrow') showBorrow();
            else if (hash === '#camps') showCampaigns();
            else if (hash === '#records') switchTab('records');
            else if (hash === '#services') switchTab('services');
        });

        // ── Borrow History modal ─────────────────────────────────────────
        let bhPage = 1;
        let bhLoading = false;

        function showBorrowHistory() {
            const m = document.getElementById('borrow-history-modal');
            m.classList.remove('hidden');
            m.classList.add('flex');
            bhPage = 1;
            bhLoad();
        }
        function hideBorrowHistory() {
            const m = document.getElementById('borrow-history-modal');
            m.classList.add('hidden');
            m.classList.remove('flex');
        }

        function bhShowError(msg) {
            const e = document.getElementById('bh-error');
            document.getElementById('bh-error-text').textContent = msg;
            e.classList.remove('hidden');
            setTimeout(() => e.classList.add('hidden'), 4000);
        }

        function bhStatusMeta(row) {
            if (row.status === 'returned') return { cls: 'bg-emerald-50 text-emerald-700 border-emerald-100', accent: 'bg-emerald-500', icon: 'fa-circle-check', text: 'คืนแล้ว', pending: false };
            if (row.approval_status === 'pending') return { cls: 'bg-amber-50 text-amber-700 border-amber-100', accent: 'bg-amber-400', icon: 'fa-hourglass-half', text: 'รอดำเนินการ', pending: true };
            if (row.approval_status === 'rejected') return { cls: 'bg-slate-100 text-slate-600 border-slate-200', accent: 'bg-slate-400', icon: 'fa-ban', text: 'ถูกปฏิเสธ', pending: false };
            if (row.status === 'cancelled') return { cls: 'bg-rose-50 text-rose-700 border-rose-100', accent: 'bg-rose-500', icon: 'fa-circle-xmark', text: 'ยกเลิกแล้ว', pending: false };
            return { cls: 'bg-slate-100 text-slate-600 border-slate-200', accent: 'bg-slate-300', icon: 'fa-question-circle', text: 'ไม่ทราบสถานะ', pending: false };
        }

        function bhFmtDate(s) {
            if (!s) return '-';
            const d = new Date(String(s).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return escHtml(s);
            return d.toLocaleString('th-TH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        async function bhLoad() {
            if (bhLoading) return;
            bhLoading = true;
            const list = document.getElementById('bh-list');
            const empty = document.getElementById('bh-empty');
            const info = document.getElementById('bh-info');
            list.innerHTML = '<div class="bg-white rounded-2xl p-6 text-center text-sm font-bold text-slate-400">กำลังโหลด...</div>';
            empty.classList.add('hidden');
            info.textContent = 'กำลังโหลด...';
            document.getElementById('bh-pagination').innerHTML = '';

            try {
                const res = await fetch(`ajax_borrow_history.php?page=${bhPage}`);
                const json = await res.json();
                if (!json.ok) throw new Error(json.error || 'load failed');
                bhRender(json.rows || []);
                bhRenderPagination(json.pagination || { page: 1, total_pages: 1, total: 0 });
            } catch (err) {
                list.innerHTML = '';
                bhShowError('ไม่สามารถโหลดประวัติได้');
            } finally {
                bhLoading = false;
            }
        }

        function bhRender(rows) {
            const list = document.getElementById('bh-list');
            const empty = document.getElementById('bh-empty');
            if (!rows.length) {
                list.innerHTML = '';
                empty.classList.remove('hidden');
                return;
            }
            empty.classList.add('hidden');
            list.innerHTML = rows.map(row => {
                const m = bhStatusMeta(row);
                const displayName = row.eq_name || row.type_name || '-';
                const displayType = row.eq_name ? row.type_name : 'ประเภทอุปกรณ์';
                const img = row.image_url
                    ? `<img src="../e_Borrow/${escHtml(row.image_url)}" alt="" class="w-full h-full object-cover" onerror="this.outerHTML='<i class=\\'fa-solid fa-stethoscope text-slate-300\\'></i>'">`
                    : `<i class="fa-solid fa-stethoscope text-slate-300"></i>`;
                const returnedRow = (row.status === 'returned' && row.return_date)
                    ? `<div class="flex justify-between text-[11px]"><span class="text-slate-400 font-bold">คืนเมื่อ</span><strong class="text-emerald-600 font-black">${escHtml(bhFmtDate(row.return_date))}</strong></div>`
                    : '';
                const cancelBtn = m.pending
                    ? `<button type="button" onclick="cancelBorrowRequest(${parseInt(row.id, 10)}, this, true)" class="px-3 py-1.5 rounded-xl bg-rose-50 text-rose-600 text-[11px] font-black border border-rose-100 active:scale-95 transition-all">ยกเลิก</button>`
                    : '';
                return `
                <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden flex">
                    <div class="w-1 ${m.accent} shrink-0"></div>
                    <div class="p-4 flex-1 min-w-0">
                        <div class="flex items-start gap-3 mb-3">
                            <div class="w-12 h-12 rounded-xl bg-slate-50 flex items-center justify-center shrink-0 overflow-hidden">${img}</div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-black text-slate-900 truncate">${escHtml(displayName)}</p>
                                <p class="text-[11px] font-bold text-slate-400">${escHtml(displayType)}</p>
                            </div>
                        </div>
                        <div class="bg-slate-50 rounded-xl px-3 py-2 mb-3 space-y-1">
                            <div class="flex justify-between text-[11px]">
                                <span class="text-slate-400 font-bold">ส่งคำขอ</span>
                                <strong class="text-slate-700 font-black">${escHtml(bhFmtDate(row.borrow_date))}</strong>
                            </div>
                            ${returnedRow}
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full border text-[10px] font-black ${m.cls}">
                                <i class="fa-solid ${m.icon} text-[9px]"></i>${m.text}
                            </span>
                            ${cancelBtn}
                        </div>
                    </div>
                </div>`;
            }).join('');
        }

        function bhRenderPagination(p) {
            const wrap = document.getElementById('bh-pagination');
            const info = document.getElementById('bh-info');
            const page = parseInt(p.page || 1, 10);
            const totalPages = Math.max(1, parseInt(p.total_pages || 1, 10));
            const total = parseInt(p.total || 0, 10);
            info.textContent = `หน้า ${page} / ${totalPages} · รวม ${total.toLocaleString('th-TH')} รายการ`;

            if (totalPages <= 1) { wrap.innerHTML = ''; return; }

            const buttons = [];
            const add = (label, target, disabled = false, active = false) => {
                const base = 'min-w-9 h-9 px-3 rounded-xl text-xs font-black flex items-center justify-center transition-all';
                if (active) buttons.push(`<span class="${base} bg-[#2e9e63] text-white">${label}</span>`);
                else if (disabled) buttons.push(`<span class="${base} bg-white border border-slate-200 text-slate-300 opacity-50 cursor-not-allowed">${label}</span>`);
                else buttons.push(`<button type="button" onclick="bhGoPage(${target})" class="${base} bg-white border border-slate-200 text-slate-500 hover:border-[#2e9e63] hover:text-[#2e9e63]">${label}</button>`);
            };
            add('&laquo;', 1, page === 1);
            add('&lsaquo;', Math.max(1, page - 1), page === 1);
            for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) add(String(i), i, false, i === page);
            add('&rsaquo;', Math.min(totalPages, page + 1), page === totalPages);
            add('&raquo;', totalPages, page === totalPages);
            wrap.innerHTML = buttons.join('');
        }

        function bhGoPage(p) { bhPage = p; bhLoad(); }

        // ── Cancel pending request (used by borrow-modal + history modal) ─
        async function cancelBorrowRequest(transactionId, btn, fromHistory = false) {
            if (!confirm('ยืนยันยกเลิกคำขอนี้?')) return;
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            try {
                const fd = new FormData();
                fd.append('transaction_id', String(transactionId));
                const res = await fetch('../e_Borrow/process/cancel_request_process.php', {
                    method: 'POST', body: fd,
                });
                const json = await res.json();
                if (json.status !== 'success') throw new Error(json.message || 'ยกเลิกไม่สำเร็จ');
                if (fromHistory) {
                    bhLoad();
                } else {
                    window.location.reload();
                }
            } catch (err) {
                alert(err.message || 'เกิดข้อผิดพลาด');
                btn.disabled = false;
                btn.innerHTML = original;
            }
        }
        let vaccinationPage = 1;
        let vaccinationQuery = '';
        let vaccinationStatus = '';
        let vaccinationLoading = false;

        function showVaccinationHistory() {
            const modal = document.getElementById('vaccination-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            switchHealthTab('overview');
        }

        function hideVaccinationHistory() {
            document.getElementById('vaccination-modal').classList.add('hidden');
        }

        function switchHealthTab(tab) {
            const ov = document.getElementById('ho-overview-panel');
            const vc = document.getElementById('ho-vaccine-panel');
            const ovBtn = document.getElementById('ho-tab-overview-btn');
            const vcBtn = document.getElementById('ho-tab-vaccine-btn');
            if (tab === 'vaccine') {
                ov.classList.add('hidden');
                vc.classList.remove('hidden');
                vc.classList.add('flex');
                if (!vc.dataset.loaded) {
                    vaccinationPage = 1;
                    loadVaccinationRecords();
                    vc.dataset.loaded = '1';
                }
            } else {
                ov.classList.remove('hidden');
                vc.classList.add('hidden');
                vc.classList.remove('flex');
            }
            ovBtn.classList.toggle('text-[#2e9e63]', tab === 'overview');
            ovBtn.classList.toggle('text-slate-400', tab !== 'overview');
            vcBtn.classList.toggle('text-[#2e9e63]', tab === 'vaccine');
            vcBtn.classList.toggle('text-slate-400', tab !== 'vaccine');
            ovBtn.querySelector('.ho-tab-underline').classList.toggle('hidden', tab !== 'overview');
            vcBtn.querySelector('.ho-tab-underline').classList.toggle('hidden', tab !== 'vaccine');
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[char]);
        }

        function formatVaccinationDate(value) {
            if (!value) return '-';
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) return escapeHtml(value);
            return date.toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
        }

        function vaccinationStatusLabel(status) {
            const labels = {
                completed: 'ฉีดแล้ว',
                cancelled: 'ยกเลิก',
                entered_in_error: 'บันทึกผิด'
            };
            return labels[status] || status || '-';
        }

        function vaccinationStatusClass(status) {
            if (status === 'completed') return 'bg-emerald-50 text-emerald-700 border-emerald-100';
            if (status === 'cancelled') return 'bg-slate-100 text-slate-600 border-slate-200';
            return 'bg-amber-50 text-amber-700 border-amber-100';
        }

        function renderVaccinationRows(rows) {
            const body = document.getElementById('vaccination-table-body');
            if (!rows.length) {
                body.innerHTML = `
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center">
                            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-400">
                                <i class="fa-solid fa-syringe"></i>
                            </div>
                            <p class="text-sm font-black text-slate-700">ยังไม่พบประวัติการฉีดวัคซีน</p>
                            <p class="mt-1 text-xs font-semibold text-slate-400">เมื่อมีการบันทึกข้อมูล รายการจะแสดงที่นี่</p>
                        </td>
                    </tr>
                `;
                return;
            }

            body.innerHTML = rows.map((row) => `
                <tr class="border-b border-slate-50 last:border-0">
                    <td class="px-5 py-4 align-top">
                        <p class="text-sm font-black text-slate-900">${escapeHtml(row.vaccine_name)}</p>
                        <p class="mt-1 text-[11px] font-bold text-slate-400">${row.manufacturer ? escapeHtml(row.manufacturer) : 'ไม่ระบุผู้ผลิต'}</p>
                    </td>
                    <td class="px-5 py-4 align-top text-sm font-bold text-slate-700">${row.dose_number ? `เข็มที่ ${escapeHtml(row.dose_number)}` : '-'}</td>
                    <td class="px-5 py-4 align-top text-sm font-bold text-slate-700">${formatVaccinationDate(row.vaccinated_at)}</td>
                    <td class="px-5 py-4 align-top">
                        <p class="text-sm font-bold text-slate-700">${row.lot_number ? escapeHtml(row.lot_number) : '-'}</p>
                        <p class="mt-1 text-[11px] font-bold text-slate-400">${row.injection_site ? escapeHtml(row.injection_site) : ''}</p>
                    </td>
                    <td class="px-5 py-4 align-top">
                        <p class="text-sm font-bold text-slate-700">${row.provider_name ? escapeHtml(row.provider_name) : '-'}</p>
                        <p class="mt-1 text-[11px] font-bold text-slate-400">${row.location ? escapeHtml(row.location) : ''}</p>
                    </td>
                    <td class="px-5 py-4 align-top">
                        <span class="inline-flex rounded-full border px-3 py-1 text-[11px] font-black ${vaccinationStatusClass(row.status)}">${vaccinationStatusLabel(row.status)}</span>
                    </td>
                </tr>
            `).join('');
        }

        function renderVaccinationPagination(pagination) {
            const info = document.getElementById('vaccination-pagination-info');
            const controls = document.getElementById('vaccination-pagination-controls');
            const page = Number(pagination.page || 1);
            const totalPages = Math.max(1, Number(pagination.total_pages || 1));
            const total = Number(pagination.total || 0);
            info.textContent = `หน้า ${page} / ${totalPages} · รวม ${total.toLocaleString('th-TH')} รายการ`;

            const buttons = [];
            const addButton = (label, targetPage, disabled = false, active = false) => {
                buttons.push(`
                    <button type="button"
                        class="min-w-9 h-9 px-3 rounded-xl text-xs font-black transition-all ${active ? 'bg-[#2e9e63] text-white' : 'bg-white border border-slate-200 text-slate-500'} ${disabled ? 'opacity-40 cursor-not-allowed' : 'active:scale-95 hover:border-[#2e9e63] hover:text-[#2e9e63]'}"
                        ${disabled ? 'disabled' : `onclick="goVaccinationPage(${targetPage})"`}>
                        ${label}
                    </button>
                `);
            };

            addButton('&laquo;', 1, page === 1);
            addButton('&lsaquo;', page - 1, page === 1);
            for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
                addButton(String(i), i, false, i === page);
            }
            addButton('&rsaquo;', page + 1, page === totalPages);
            addButton('&raquo;', totalPages, page === totalPages);
            controls.innerHTML = buttons.join('');
        }

        function goVaccinationPage(page) {
            vaccinationPage = page;
            loadVaccinationRecords();
        }

        function applyVaccinationFilters() {
            vaccinationQuery = document.getElementById('vaccination-search').value.trim();
            vaccinationStatus = document.getElementById('vaccination-status').value;
            vaccinationPage = 1;
            loadVaccinationRecords();
        }

        async function loadVaccinationRecords() {
            if (vaccinationLoading) return;
            vaccinationLoading = true;
            const body = document.getElementById('vaccination-table-body');
            body.innerHTML = `<tr><td colspan="6" class="px-5 py-10 text-center text-sm font-black text-slate-400">กำลังโหลดข้อมูล...</td></tr>`;

            const params = new URLSearchParams({
                page: String(vaccinationPage),
                per_page: '20',
                q: vaccinationQuery,
                status: vaccinationStatus
            });

            try {
                const response = await fetch(`ajax_vaccination_records.php?${params.toString()}`);
                const result = await response.json();
                if (!result.ok) throw new Error(result.error || 'Load failed');
                if (result.setup_required) {
                    body.innerHTML = `<tr><td colspan="6" class="px-5 py-10 text-center text-sm font-black text-amber-600">ยังไม่ได้สร้างตาราง user_vaccination_records</td></tr>`;
                    renderVaccinationPagination({ page: 1, total_pages: 1, total: 0 });
                    return;
                }
                renderVaccinationRows(result.rows || []);
                renderVaccinationPagination(result.pagination || { page: 1, total_pages: 1, total: 0 });
            } catch (error) {
                body.innerHTML = `<tr><td colspan="6" class="px-5 py-10 text-center text-sm font-black text-rose-600">ไม่สามารถโหลดข้อมูลได้</td></tr>`;
            } finally {
                vaccinationLoading = false;
            }
        }

        let qr = null;
    </script>

<body class="text-slate-900 pb-32">

    <div class="max-w-md mx-auto relative min-h-screen">

        <!-- ── Clean White Header (Target Design) ── -->
        <header
            class="bg-white/80 backdrop-blur-xl sticky top-0 z-[60] px-6 py-4 flex items-center justify-between border-b border-slate-50 shadow-sm shadow-slate-100">
            <div class="flex items-center gap-4">
                <button onclick="showCampaigns()"
                    class="w-12 h-12 bg-[#2e9e63] rounded-2xl flex items-center justify-center text-white shadow-lg shadow-green-100 active:scale-90 transition-all">
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
                    class="w-10 h-10 flex items-center justify-center text-slate-600 hover:text-green-600 transition-colors">
                    <i class="fa-solid fa-qrcode text-lg"></i>
                </button>
                <?php $notif_total = $upcoming_count + $borrow_pending_count + $borrow_overdue_count; ?>
                <button onclick="showNotifications()"
                    class="w-10 h-10 flex items-center justify-center text-slate-400 hover:text-slate-600 transition-colors relative">
                    <i class="fa-solid fa-bell text-lg"></i>
                    <?php if ($notif_total > 0): ?>
                        <span
                            class="absolute top-1.5 right-1.5 min-w-4 h-4 px-1 <?= $borrow_overdue_count > 0 ? 'bg-rose-500' : 'bg-red-500' ?> text-white text-[9px] font-black rounded-full border-2 border-white flex items-center justify-center"><?= $notif_total ?></span>
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
                class="relative overflow-hidden bg-gradient-to-br from-[#2e9e63] via-[#10b981] to-[#2e9e63] rounded-[3rem] p-8 shadow-[0_25px_50px_-12px_rgba(46,158,99,0.3)] group active:scale-[0.97] transition-all cursor-pointer">
                <!-- Abstract Decorations -->
                <div
                    class="absolute -right-6 -top-6 w-48 h-48 bg-white/10 rounded-full blur-3xl group-hover:scale-150 transition-transform duration-1000">
                </div>
                <div class="absolute -left-12 -bottom-12 w-56 h-56 bg-emerald-400/20 rounded-full blur-3xl"></div>

                <div class="relative z-10">
                    <div class="flex items-center gap-5 mb-10">
                        <div class="relative">
                            <div class="w-20 h-20 rounded-[2rem] overflow-hidden border-2 border-white/20 shadow-2xl">
                                <img src="<?= $user['picture_url'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name']); ?>"
                                    class="w-full h-full object-cover">
                            </div>
                            <div
                                class="absolute -bottom-1 -right-1 w-6 h-6 bg-emerald-400 rounded-full border-4 border-[#237a4c] animate-pulse">
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-emerald-50 text-sm font-bold mb-1">สวัสดี 👋</p>
                            <h3 class="text-white text-2xl font-black tracking-tight leading-tight mb-1 truncate">
                                <?= $user['full_name'] ?>
                            </h3>
                            <?php if (!empty($user['student_personnel_id'])): ?>
                            <p class="text-emerald-100/60 text-[11px] font-black uppercase tracking-[0.1em]">ID:
                                <?= htmlspecialchars($user['student_personnel_id']) ?>
                            </p>
                            <?php endif; ?>
                            <?php if (!empty($user['member_id'])): ?>
                            <p class="text-emerald-100/60 text-[11px] font-black uppercase tracking-[0.1em]">
                                <i class="fa-solid fa-id-badge text-[9px] mr-0.5"></i><?= htmlspecialchars($user['member_id']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <div
                            class="w-12 h-12 bg-white/15 backdrop-blur-xl rounded-2xl flex items-center justify-center border border-white/20 shadow-xl group-hover:translate-x-1 transition-transform">
                            <i class="fa-solid fa-chevron-right text-white text-sm"></i>
                        </div>
                    </div>

                    <div class="relative flex items-center justify-between pt-6 border-t border-white/10">
                        <div class="flex items-center gap-3 text-white">
                            <i class="fa-solid fa-graduation-cap text-emerald-200 text-sm"></i>
                            <p class="text-emerald-50 text-[11px] font-bold tracking-wide truncate max-w-[200px]">
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

                    <?php if (defined('SITE_SHOW_INSURANCE') && SITE_SHOW_INSURANCE && $insurance !== null):
                        $insActive = ($insurance['insurance_status'] ?? '') === 'Active';
                        $coverEnd  = $insurance['coverage_end'] ?? '';
                        $coverEndShort = $coverEnd
                            ? date('d/m/', strtotime($coverEnd)) . substr((string)((int)date('Y', strtotime($coverEnd)) + 543), -2)
                            : '—';
                    ?>
                    <button type="button"
                        onclick="event.stopPropagation(); document.getElementById('insDetailModal').classList.remove('hidden');"
                        class="relative mt-4 w-full flex items-center gap-3 rounded-2xl <?= $insActive ? 'bg-white/10 border-white/20' : 'bg-amber-400/15 border-amber-300/30' ?> backdrop-blur-md border px-4 py-3 text-left active:scale-[0.98] transition-all">
                        <div class="w-9 h-9 rounded-xl bg-white/15 border border-white/20 flex items-center justify-center text-white shrink-0">
                            <i class="fa-solid fa-shield-heart text-sm"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-white/70 text-[9px] font-black uppercase tracking-[0.22em]">ประกันอุบัติเหตุ</p>
                            <p class="text-white text-[12px] font-black mt-0.5 truncate">
                                <?php if ($insActive): ?>
                                    Active · ฿40,000 · ครบ <?= htmlspecialchars($coverEndShort) ?>
                                <?php else: ?>
                                    Inactive · ติดต่อเจ้าหน้าที่
                                <?php endif; ?>
                            </p>
                        </div>
                        <i class="fa-solid fa-chevron-right text-white/60 text-xs shrink-0"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Tab Switcher (matches bottom nav) ── -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-1.5 flex gap-1" role="tablist">
                <button type="button" role="tab" data-tab-btn="today" onclick="switchTab('today')"
                    class="tab-btn flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-[12px] font-black transition-all">
                    <i class="fa-solid fa-house-chimney text-[11px]"></i>วันนี้
                </button>
                <button type="button" role="tab" data-tab-btn="records" onclick="switchTab('records')"
                    class="tab-btn flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-[12px] font-black transition-all">
                    <i class="fa-solid fa-folder-open text-[11px]"></i>สุขภาพ
                </button>
                <button type="button" role="tab" data-tab-btn="services" onclick="switchTab('services')"
                    class="tab-btn flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl text-[12px] font-black transition-all">
                    <i class="fa-solid fa-grip text-[11px]"></i>บริการ
                </button>
            </div>

            <!-- ── Group A: วันนี้ของคุณ (Smart Hero + Quick Stats) ── -->
            <section id="today-section" data-tab-pane="today" aria-label="วันนี้ของคุณ" class="tab-pane space-y-4">
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-slate-900 font-black text-sm uppercase tracking-widest">วันนี้ของคุณ</h3>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Today</p>
                </div>

                <?php $heroT = $heroThemes[$smartHero['theme']] ?? $heroThemes['brand']; ?>
                <button type="button" onclick="<?= htmlspecialchars($smartHero['action'], ENT_QUOTES) ?>"
                    class="relative w-full text-left bg-gradient-to-br <?= $heroT['bg'] ?> rounded-[2.5rem] p-6 text-white <?= $heroT['shadow'] ?> overflow-hidden active:scale-[0.98] transition-all">
                    <div class="absolute -right-8 -top-8 w-40 h-40 bg-white/10 rounded-full blur-3xl"></div>
                    <div class="absolute -left-12 -bottom-12 w-48 h-48 bg-white/5 rounded-full blur-3xl"></div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="text-white/70 text-[10px] font-black uppercase tracking-[0.22em]"><?= htmlspecialchars($smartHero['eyebrow']) ?></span>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-white/15 backdrop-blur-md border border-white/20 flex items-center justify-center text-white text-lg shrink-0">
                                <i class="fa-solid <?= $smartHero['icon'] ?>"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h4 class="text-white text-lg font-black leading-tight tracking-tight truncate"><?= htmlspecialchars($smartHero['title']) ?></h4>
                                <p class="mt-1 text-white/80 text-[12px] font-bold leading-snug"><?= htmlspecialchars($smartHero['detail']) ?></p>
                                <?php if (isset($smartHero['days_until'])): ?>
                                    <p class="mt-2 inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white/20 backdrop-blur-sm text-[11px] font-black">
                                        <i class="fa-regular fa-clock text-[9px]"></i>
                                        <?= $smartHero['days_until'] === 0 ? 'วันนี้' : ($smartHero['days_until'] === 1 ? 'พรุ่งนี้' : 'อีก ' . (int)$smartHero['days_until'] . ' วัน') ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-5 flex items-center justify-end">
                            <span class="inline-flex items-center gap-2 <?= $heroT['btn'] ?> font-black text-[12px] px-4 py-2 rounded-2xl shadow-sm">
                                <?= htmlspecialchars($smartHero['cta_label']) ?> <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            </span>
                        </div>
                    </div>
                </button>

                <!-- Quick stats: 3 compact tiles -->
                <div class="grid grid-cols-3 gap-3">
                    <button onclick="document.getElementById('records-section').scrollIntoView({behavior:'smooth'})"
                        class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm text-center active:scale-95 transition-all">
                        <div class="w-9 h-9 mx-auto mb-2 rounded-xl bg-emerald-50 text-[#2e9e63] flex items-center justify-center">
                            <i class="fa-solid fa-calendar-check text-sm"></i>
                        </div>
                        <p class="text-lg font-black text-slate-900"><?= $upcoming_count ?></p>
                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">นัดหมาย</p>
                    </button>
                    <button onclick="showVaccinationHistory()"
                        class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm text-center active:scale-95 transition-all">
                        <div class="w-9 h-9 mx-auto mb-2 rounded-xl bg-emerald-50 text-[#2e9e63] flex items-center justify-center">
                            <i class="fa-solid fa-syringe text-sm"></i>
                        </div>
                        <p class="text-lg font-black text-slate-900"><?= (int) ($healthOverview['vaccine_total'] ?? 0) ?></p>
                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">วัคซีน</p>
                    </button>
                    <button onclick="showBorrow()"
                        class="relative bg-white rounded-2xl p-4 border border-slate-100 shadow-sm text-center active:scale-95 transition-all">
                        <?php if ($borrow_total_fine > 0): ?>
                            <span class="absolute top-2 right-2 px-1.5 py-0.5 bg-rose-500 text-white text-[8px] font-black rounded-full shadow-sm">฿<?= number_format($borrow_total_fine, 0) ?></span>
                        <?php endif; ?>
                        <div class="w-9 h-9 mx-auto mb-2 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                            <i class="fa-solid fa-box-archive text-sm"></i>
                        </div>
                        <p class="text-lg font-black text-slate-900"><?= $borrow_count ?></p>
                        <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">ยืมอยู่</p>
                    </button>
                </div>
            </section>

            <!-- ── Group B: สุขภาพของฉัน (Records) ── -->
            <section id="records-section" data-tab-pane="records" aria-label="สุขภาพของฉัน" class="tab-pane hidden space-y-4">
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-slate-900 font-black text-sm uppercase tracking-widest">สุขภาพของฉัน</h3>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Records</p>
                </div>

                <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5">
                    <div class="grid grid-cols-2 gap-3">
                        <!-- Vaccination — ACTIVE -->
                        <button onclick="showVaccinationHistory()"
                            class="text-left p-4 rounded-2xl bg-emerald-50/60 border border-emerald-100 active:scale-95 transition-all">
                            <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-[#2e9e63] shadow-sm mb-3">
                                <i class="fa-solid fa-syringe"></i>
                            </div>
                            <p class="text-[13px] font-black text-slate-900 leading-tight">ประวัติวัคซีน</p>
                            <p class="mt-0.5 text-[10px] font-bold text-slate-500"><?= (int)($healthOverview['vaccine_total'] ?? 0) ?> รายการ</p>
                        </button>

                        <!-- Visit history — ACTIVE -->
                        <a href="my_bookings.php"
                            class="block text-left p-4 rounded-2xl bg-indigo-50/60 border border-indigo-100 active:scale-95 transition-all">
                            <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-indigo-500 shadow-sm mb-3">
                                <i class="fa-solid fa-clipboard-list"></i>
                            </div>
                            <p class="text-[13px] font-black text-slate-900 leading-tight">ประวัติการเข้าพบ</p>
                            <p class="mt-0.5 text-[10px] font-bold text-slate-500"><?= count($booking_list) ?> ครั้ง</p>
                        </a>

                        <!-- Health checks — ACTIVE (existing in Health Overview modal) -->
                        <button onclick="showVaccinationHistory()"
                            class="text-left p-4 rounded-2xl bg-green-50/60 border border-green-100 active:scale-95 transition-all">
                            <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-green-600 shadow-sm mb-3">
                                <i class="fa-solid fa-stethoscope"></i>
                            </div>
                            <p class="text-[13px] font-black text-slate-900 leading-tight">ตรวจสุขภาพ</p>
                            <p class="mt-0.5 text-[10px] font-bold text-slate-500"><?= (int)($healthOverview['healthcheck_total'] ?? 0) ?> ครั้ง</p>
                        </button>

                        <!-- Prescriptions — COMING SOON -->
                        <button onclick="showUpcoming('ใบสั่งยา / ยาที่ใช้อยู่')"
                            class="relative text-left p-4 rounded-2xl bg-slate-50 border border-slate-100 active:scale-95 transition-all opacity-90">
                            <span class="absolute top-2 right-2 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[8px] font-black uppercase tracking-widest">Soon</span>
                            <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-rose-400 shadow-sm mb-3">
                                <i class="fa-solid fa-pills"></i>
                            </div>
                            <p class="text-[13px] font-black text-slate-700 leading-tight">ใบสั่งยา</p>
                            <p class="mt-0.5 text-[10px] font-bold text-slate-400">เร็วๆ นี้</p>
                        </button>

                        <!-- Lab results — COMING SOON -->
                        <button onclick="showUpcoming('ผลตรวจห้องแล็บ')"
                            class="relative text-left p-4 rounded-2xl bg-slate-50 border border-slate-100 active:scale-95 transition-all opacity-90">
                            <span class="absolute top-2 right-2 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[8px] font-black uppercase tracking-widest">Soon</span>
                            <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-purple-500 shadow-sm mb-3">
                                <i class="fa-solid fa-flask"></i>
                            </div>
                            <p class="text-[13px] font-black text-slate-700 leading-tight">ผลตรวจแล็บ</p>
                            <p class="mt-0.5 text-[10px] font-bold text-slate-400">เร็วๆ นี้</p>
                        </button>

                        <!-- Vital signs — COMING SOON -->
                        <button onclick="showUpcoming('สัญญาณชีพและกราฟสุขภาพ')"
                            class="relative text-left p-4 rounded-2xl bg-slate-50 border border-slate-100 active:scale-95 transition-all opacity-90">
                            <span class="absolute top-2 right-2 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[8px] font-black uppercase tracking-widest">Soon</span>
                            <div class="w-10 h-10 rounded-xl bg-white flex items-center justify-center text-cyan-500 shadow-sm mb-3">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                            <p class="text-[13px] font-black text-slate-700 leading-tight">สัญญาณชีพ</p>
                            <p class="mt-0.5 text-[10px] font-bold text-slate-400">เร็วๆ นี้</p>
                        </button>
                    </div>
                </div>
            </section>

            <!-- ── Group C: บริการ (Services) ── -->
            <section id="services-section" data-tab-pane="services" aria-label="บริการ" class="tab-pane hidden space-y-4">
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-slate-900 font-black text-sm uppercase tracking-widest">บริการ</h3>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Services</p>
                </div>

                <div class="bg-white rounded-[2rem] border border-slate-100 shadow-sm p-5 space-y-5">
                    <!-- Primary services -->
                    <div class="grid grid-cols-2 gap-3">
                        <button onclick="showCampaigns()"
                            class="relative flex flex-col items-start p-5 rounded-2xl bg-[#2e9e63] shadow-[0_10px_25px_rgba(46,158,99,0.25)] active:scale-95 transition-all text-white overflow-hidden text-left group">
                            <div class="absolute -right-4 -top-4 w-16 h-16 bg-white/10 rounded-full blur-xl group-hover:scale-150 transition-transform"></div>
                            <div class="w-10 h-10 rounded-2xl bg-white/20 flex items-center justify-center mb-3 border border-white/20">
                                <i class="fa-solid fa-calendar-plus text-white text-sm"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight">จองคิว /<br>แคมเปญ</p>
                        </button>

                        <button onclick="showBorrowFlow()"
                            class="relative flex flex-col items-start p-5 rounded-2xl bg-amber-50 border border-amber-100 active:scale-95 transition-all text-left group">
                            <?php if ($borrow_count > 0): ?>
                                <span class="absolute top-3 right-3 w-5 h-5 bg-amber-500 text-white text-[9px] font-black rounded-full flex items-center justify-center border-2 border-white shadow"><?= $borrow_count ?></span>
                            <?php endif; ?>
                            <div class="w-10 h-10 rounded-2xl bg-white flex items-center justify-center mb-3 shadow-sm border border-amber-50">
                                <i class="fa-solid fa-box-archive text-amber-500 text-sm"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight text-slate-800">ยืมอุปกรณ์<br>e-Borrow</p>
                        </button>

                        <button onclick="showUpcoming('ขอเอกสาร / ใบรับรองแพทย์')"
                            class="relative flex flex-col items-start p-5 rounded-2xl bg-slate-50 border border-slate-100 active:scale-95 transition-all text-left opacity-90">
                            <span class="absolute top-3 right-3 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[8px] font-black uppercase tracking-widest">Soon</span>
                            <div class="w-10 h-10 rounded-2xl bg-white flex items-center justify-center mb-3 shadow-sm border border-slate-100 text-blue-500">
                                <i class="fa-solid fa-file-medical text-sm"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight text-slate-700">ขอเอกสาร<br>ใบรับรอง</p>
                        </button>

                        <button onclick="showUpcoming('ปรึกษาแพทย์ออนไลน์ (Telemedicine)')"
                            class="relative flex flex-col items-start p-5 rounded-2xl bg-slate-50 border border-slate-100 active:scale-95 transition-all text-left opacity-90">
                            <span class="absolute top-3 right-3 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[8px] font-black uppercase tracking-widest">Soon</span>
                            <div class="w-10 h-10 rounded-2xl bg-white flex items-center justify-center mb-3 shadow-sm border border-slate-100 text-emerald-500">
                                <i class="fa-solid fa-video text-sm"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight text-slate-700">ปรึกษาแพทย์<br>ออนไลน์</p>
                        </button>

                        <button onclick="showUpcoming('เคลมประกัน / ตรวจสอบสิทธิ์')"
                            class="relative flex flex-col items-start p-5 rounded-2xl bg-slate-50 border border-slate-100 active:scale-95 transition-all text-left opacity-90">
                            <span class="absolute top-3 right-3 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[8px] font-black uppercase tracking-widest">Soon</span>
                            <div class="w-10 h-10 rounded-2xl bg-white flex items-center justify-center mb-3 shadow-sm border border-slate-100 text-indigo-500">
                                <i class="fa-solid fa-shield-heart text-sm"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight text-slate-700">เคลม<br>ประกัน</p>
                        </button>

                        <button onclick="showUpcoming('ชำระค่าบริการ')"
                            class="relative flex flex-col items-start p-5 rounded-2xl bg-slate-50 border border-slate-100 active:scale-95 transition-all text-left opacity-90">
                            <span class="absolute top-3 right-3 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[8px] font-black uppercase tracking-widest">Soon</span>
                            <div class="w-10 h-10 rounded-2xl bg-white flex items-center justify-center mb-3 shadow-sm border border-slate-100 text-rose-500">
                                <i class="fa-solid fa-credit-card text-sm"></i>
                            </div>
                            <p class="text-[13px] font-black leading-tight text-slate-700">ชำระเงิน<br>ใบเสร็จ</p>
                        </button>
                    </div>

                    <!-- Quick contacts -->
                    <div class="pt-5 border-t border-slate-50">
                        <p class="text-slate-400 text-[9px] font-black uppercase tracking-[0.3em] mb-4 text-center">ติดต่อด่วน</p>
                        <div class="grid grid-cols-4 gap-4">
                            <a href="https://lin.ee/C3CJ2A9" target="_blank"
                                class="flex flex-col items-center gap-2 active:scale-90 transition-all">
                                <div class="w-12 h-12 bg-purple-50 rounded-2xl flex items-center justify-center text-purple-600 shadow-sm">
                                    <i class="fa-solid fa-comment-dots text-lg"></i>
                                </div>
                                <span class="text-slate-500 text-[8px] font-black text-center leading-tight uppercase tracking-widest">Counseling</span>
                            </a>
                            <a href="https://line.me/R/ti/p/@115vbibe?oat_content=url&ts=12222134" target="_blank"
                                class="flex flex-col items-center gap-2 active:scale-90 transition-all">
                                <div class="w-12 h-12 bg-cyan-50 rounded-2xl flex items-center justify-center text-cyan-600 shadow-sm">
                                    <i class="fa-solid fa-heart-pulse text-lg"></i>
                                </div>
                                <span class="text-slate-500 text-[8px] font-black text-center leading-tight uppercase tracking-widest">NCD<br>Clinic</span>
                            </a>
                            <button onclick="showContact()"
                                class="flex flex-col items-center gap-2 active:scale-90 transition-all">
                                <div class="w-12 h-12 bg-emerald-50 rounded-2xl flex items-center justify-center text-emerald-600 shadow-sm">
                                    <i class="fa-solid fa-phone-flip text-base"></i>
                                </div>
                                <span class="text-slate-500 text-[8px] font-black text-center leading-tight uppercase tracking-widest">Contact</span>
                            </button>
                            <button onclick="showChat()"
                                class="flex flex-col items-center gap-2 active:scale-90 transition-all">
                                <div class="w-12 h-12 bg-orange-50 rounded-2xl flex items-center justify-center text-orange-600 shadow-sm">
                                    <i class="fa-solid fa-circle-question text-lg"></i>
                                </div>
                                <span class="text-slate-500 text-[8px] font-black text-center leading-tight uppercase tracking-widest">Help</span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ── Active Appointments ── -->
            <div class="space-y-4">
                <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.3em] px-1">Upcoming Appointments
                </p>
                <div
                    class="bg-white rounded-[3rem] border border-slate-100 shadow-[0_20px_50px_rgba(0,0,0,0.04)] overflow-hidden">
                    <div class="flex items-center justify-between px-7 pt-7 pb-4 border-b border-slate-50">
                        <h3 class="text-slate-900 font-black text-xs uppercase tracking-widest">Latest Queue</h3>
                        <span
                            class="bg-green-50 text-green-600 text-[9px] font-black px-3 py-1 rounded-full uppercase"><?= $upcoming_count ?>
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
                                <button type="button"
                                    onclick="showQR('<?= (int) $b['id'] ?>', 'Campaign QR Code', 'BOOKING #<?= (int) $b['id'] ?>')"
                                    class="w-full text-left bg-slate-50/50 rounded-[2.2rem] p-6 border border-slate-100 relative group active:scale-[0.98] transition-all hover:bg-white hover:shadow-xl focus:outline-none focus:ring-4 focus:ring-green-100">
                                    <div class="flex items-start justify-between mb-5">
                                        <div class="flex items-center gap-4">
                                            <div
                                                class="w-12 h-12 bg-white rounded-2xl flex items-center justify-center shadow-sm text-green-600 border border-slate-100">
                                                <i class="fa-solid fa-calendar-check text-base"></i>
                                            </div>
                                            <div>
                                                <h4 class="text-slate-900 font-black text-sm leading-tight mb-1.5">
                                                    <?= htmlspecialchars($b['camp_name'] ?? '') ?>
                                                </h4>
                                                <div class="flex items-center gap-2">
                                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
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
                                            <i class="fa-regular fa-calendar text-green-500 text-xs"></i>
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
                                            <i class="fa-regular fa-clock text-green-500 text-xs"></i>
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
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

<!-- ── Insurance Detail Modal ──────────────────────────────────────────────── -->
<div id="insDetailModal" class="fixed inset-0 z-[200] hidden bg-black/50 backdrop-blur-sm overflow-y-auto">
    <div class="min-h-full flex items-end sm:items-center justify-center p-0 sm:p-4">
        <div class="bg-white w-full sm:max-w-lg sm:rounded-[2rem] rounded-t-[2rem] shadow-2xl flex flex-col max-h-[92vh] sm:max-h-[88vh]">

            <!-- Header -->
            <div class="flex items-center justify-between px-6 pt-6 pb-4 border-b border-slate-100 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-[#2e9e63]/10 rounded-xl flex items-center justify-center text-[#2e9e63]">
                        <i class="fa-solid fa-shield-heart"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 text-sm">รายละเอียดประกัน</h3>
                        <p class="text-[10px] text-slate-400 font-bold">เมืองไทยประกันภัย</p>
                    </div>
                </div>
                <button onclick="document.getElementById('insDetailModal').classList.add('hidden')"
                    class="w-9 h-9 bg-slate-100 rounded-full flex items-center justify-center text-slate-400 hover:text-slate-600 transition-colors">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Content -->
            <div class="overflow-y-auto flex-1 px-6 py-5 space-y-3">

                <!-- Accordion 1: ความคุ้มครองหลัก -->
                <div class="border border-slate-100 rounded-2xl overflow-hidden">
                    <button onclick="toggleInsAcc('acc1')"
                        class="w-full flex items-center justify-between px-5 py-4 text-left bg-slate-50 hover:bg-slate-100 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-emerald-100 rounded-xl flex items-center justify-center text-emerald-600 text-xs">
                                <i class="fa-solid fa-umbrella"></i>
                            </div>
                            <span class="font-black text-slate-800 text-sm">ผลประโยชน์และความคุ้มครองหลัก</span>
                        </div>
                        <i id="acc1-icon" class="fa-solid fa-chevron-down text-slate-400 text-xs transition-transform"></i>
                    </button>
                    <div id="acc1" class="hidden px-5 py-4 space-y-3 text-sm text-slate-700">
                        <div class="space-y-2">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">การเสียชีวิต</p>
                            <div class="flex justify-between text-xs font-bold border-b border-slate-50 pb-1.5">
                                <span class="text-slate-600">จากอุบัติเหตุ</span>
                                <span class="text-slate-900">สูงสุด 250,000 บาท</span>
                            </div>
                            <div class="flex justify-between text-xs font-bold">
                                <span class="text-slate-600">กรณีอื่น (ไม่ใช่อุบัติเหตุ)</span>
                                <span class="text-slate-900">10,000 บาท</span>
                            </div>
                        </div>
                        <div class="space-y-2 pt-1">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">ความคุ้มครองอื่น</p>
                            <div class="flex justify-between text-xs font-bold border-b border-slate-50 pb-1.5">
                                <span class="text-slate-600">ทุพพลภาพ / สูญเสียอวัยวะ</span>
                                <span class="text-slate-900">สูงสุด 250,000 บาท</span>
                            </div>
                            <div class="flex justify-between text-xs font-bold border-b border-slate-50 pb-1.5">
                                <span class="text-slate-600">ค่ารักษาพยาบาล (ต่ออุบัติเหตุ)</span>
                                <span class="text-slate-900">จ่ายตามจริง สูงสุด <?= htmlspecialchars($medicalLimit ?? '40,000') ?> บาท</span>
                            </div>
                            <div class="flex justify-between text-xs font-bold">
                                <span class="text-slate-600">ภัยพิเศษ (จยย./ถูกทำร้าย/จลาจล)</span>
                                <span class="text-slate-900">สูงสุด 250,000 บาท</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Accordion 2: วิธีใช้สิทธิ์ -->
                <div class="border border-slate-100 rounded-2xl overflow-hidden">
                    <button onclick="toggleInsAcc('acc2')"
                        class="w-full flex items-center justify-between px-5 py-4 text-left bg-slate-50 hover:bg-slate-100 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-blue-100 rounded-xl flex items-center justify-center text-blue-600 text-xs">
                                <i class="fa-solid fa-hospital"></i>
                            </div>
                            <span class="font-black text-slate-800 text-sm">วิธีการใช้สิทธิ์รักษาพยาบาล</span>
                        </div>
                        <i id="acc2-icon" class="fa-solid fa-chevron-down text-slate-400 text-xs transition-transform"></i>
                    </button>
                    <div id="acc2" class="hidden px-5 py-4 space-y-4 text-xs text-slate-700">
                        <div class="space-y-2">
                            <p class="font-black text-slate-800 text-[11px]">🏥 โรงพยาบาลในโครงการ</p>
                            <p class="leading-relaxed text-slate-600">แสดง<b>บัตรประชาชน</b>หรือ<b>Passport</b> ใช้สิทธิ์ได้เลย ไม่ต้องสำรองจ่าย (เฉพาะรายการที่อยู่ในความคุ้มครอง)</p>
                        </div>
                        <div class="space-y-2 border-t border-slate-100 pt-3">
                            <p class="font-black text-slate-800 text-[11px]">💳 กรณีสำรองจ่ายไปก่อน</p>
                            <p class="text-slate-500 leading-relaxed">นำหลักฐานยื่นเบิกที่สำนักงานสวัสดิการสุขภาพ</p>
                            <ul class="space-y-1 text-slate-600">
                                <li class="flex gap-2"><span class="text-slate-400 shrink-0">•</span>ใบเรียกร้องค่าสินไหม (รับแบบฟอร์มที่มหาลัย)</li>
                                <li class="flex gap-2"><span class="text-slate-400 shrink-0">•</span>ใบรับรองแพทย์ (ต้นฉบับ) และประวัติการรักษา</li>
                                <li class="flex gap-2"><span class="text-slate-400 shrink-0">•</span>ใบเสร็จรับเงิน (ต้นฉบับ)</li>
                                <li class="flex gap-2"><span class="text-slate-400 shrink-0">•</span>สำเนาบัตรประชาชน และสำเนาหน้าสมุดบัญชีธนาคาร</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Accordion 3: ติดต่อ -->
                <div class="border border-slate-100 rounded-2xl overflow-hidden">
                    <button onclick="toggleInsAcc('acc3')"
                        class="w-full flex items-center justify-between px-5 py-4 text-left bg-slate-50 hover:bg-slate-100 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-amber-100 rounded-xl flex items-center justify-center text-amber-600 text-xs">
                                <i class="fa-solid fa-phone"></i>
                            </div>
                            <span class="font-black text-slate-800 text-sm">ข้อมูลติดต่อ</span>
                        </div>
                        <i id="acc3-icon" class="fa-solid fa-chevron-down text-slate-400 text-xs transition-transform"></i>
                    </button>
                    <div id="acc3" class="hidden px-5 py-4 space-y-4 text-xs text-slate-700">
                        <div class="space-y-2">
                            <p class="font-black text-slate-800 text-[11px]">เมืองไทยประกันภัย</p>
                            <div class="flex items-center gap-2 text-slate-600">
                                <i class="fa-solid fa-phone w-4 text-center text-slate-400"></i>
                                <span>1484 (Call Center)</span>
                            </div>
                            <div class="flex items-center gap-2 text-blue-600">
                                <i class="fa-solid fa-globe w-4 text-center text-slate-400"></i>
                                <span>www.muangthaiinsurance.com</span>
                            </div>
                        </div>
                        <div class="space-y-2 border-t border-slate-100 pt-3">
                            <p class="font-black text-slate-800 text-[11px]">งานประกันอุบัติเหตุนักศึกษา RSU</p>
                            <p class="text-slate-500">ชั้น 2 อาคาร 12/1 (รังสิตประยูรศักดิ์ 2)</p>
                            <div class="flex items-center gap-2 text-slate-600">
                                <i class="fa-solid fa-phone w-4 text-center text-slate-400"></i>
                                <span>0-2791-6000 ต่อ 4498, 4499</span>
                            </div>
                            <div class="flex items-center gap-2 text-green-600">
                                <i class="fa-brands fa-line w-4 text-center text-slate-400"></i>
                                <span>@rsu.clinic</span>
                            </div>
                            <div class="flex items-center gap-2 text-slate-600">
                                <i class="fa-solid fa-envelope w-4 text-center text-slate-400"></i>
                                <span>healthy@rsu.ac.th</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div class="px-6 pb-6 pt-3 shrink-0">
                <button onclick="document.getElementById('insDetailModal').classList.add('hidden')"
                    class="w-full h-12 bg-slate-900 text-white font-black rounded-2xl text-sm active:scale-95 transition-all">
                    ปิด
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function toggleInsAcc(id) {
    const el   = document.getElementById(id);
    const icon = document.getElementById(id + '-icon');
    const open = !el.classList.contains('hidden');
    el.classList.toggle('hidden', open);
    icon.style.transform = open ? '' : 'rotate(180deg)';
}
document.getElementById('insDetailModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});
</script>

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
            <button type="button" data-bottom-tab="today" onclick="switchTab('today')"
                class="flex flex-col items-center gap-1.5 transition-all">
                <i class="fa-solid fa-house-chimney text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Home</span>
            </button>
            <button type="button" data-bottom-tab="records" onclick="switchTab('records')"
                class="flex flex-col items-center gap-1.5 transition-all">
                <i class="fa-solid fa-folder-open text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Records</span>
            </button>
            <div class="relative -mt-14">
                <button onclick="showCampaigns()"
                    class="w-16 h-16 bg-[#2e9e63] rounded-[1.8rem] rotate-45 flex items-center justify-center text-white shadow-[0_15px_30px_rgba(46,158,99,0.4)] border-[6px] border-[#F8FAFF] active:scale-90 transition-all group">
                    <i class="fa-solid fa-plus text-2xl -rotate-45 group-hover:scale-125 transition-transform"></i>
                </button>
            </div>
            <button type="button" data-bottom-tab="services" onclick="switchTab('services')"
                class="flex flex-col items-center gap-1.5 transition-all">
                <i class="fa-solid fa-grip text-xl"></i>
                <span class="text-[8px] font-black uppercase tracking-[0.1em]">Services</span>
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
                        <div class="bg-[#2e9e63] p-4 rounded-2xl rounded-tr-none shadow-lg shadow-green-100"><p class="text-white text-xs leading-relaxed text-left">${message}</p></div>
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
                                        <div class="bg-[#2e9e63] p-4 rounded-2xl rounded-tr-none shadow-lg shadow-green-100"><p class="text-white text-xs leading-relaxed text-left">${msg.message}</p></div>
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
            <h3 id="qr-modal-title" class="text-slate-900 font-black text-xl mb-1.5">Identity QR Code</h3>
            <p id="qr-modal-code" class="text-[#2e9e63] font-mono font-black text-sm tracking-[0.2em] mb-8">
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
                    class="bg-blue-50 text-[#2e9e63] text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-widest"><?= $notif_total ?>
                    NEW</span>
            </div>
            <div class="flex-1 overflow-y-auto custom-scrollbar divide-y divide-slate-50">

                <?php if ($borrow_overdue_count > 0): ?>
                <button type="button" onclick="hideNotifications(); showBorrow();" class="w-full text-left flex gap-5 p-6 bg-rose-50/40 relative active:bg-rose-50 transition-all">
                    <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-rose-500"></div>
                    <div class="w-12 h-12 rounded-2xl bg-rose-100 flex items-center justify-center shrink-0 border border-rose-200/50 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation text-rose-600 text-base"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start mb-1.5">
                            <p class="text-slate-900 font-black text-sm">อุปกรณ์เกินกำหนดคืน</p>
                            <span class="text-rose-500 text-[9px] font-black uppercase tracking-widest">URGENT</span>
                        </div>
                        <p class="text-slate-500 text-[11px] font-medium leading-relaxed">
                            มีอุปกรณ์ <?= $borrow_overdue_count ?> รายการที่เลยกำหนดคืนแล้ว — แตะเพื่อดูรายละเอียด
                        </p>
                    </div>
                </button>
                <?php endif; ?>

                <?php if ($borrow_pending_count > 0): ?>
                <button type="button" onclick="hideNotifications(); showBorrow();" class="w-full text-left flex gap-5 p-6 bg-amber-50/40 relative active:bg-amber-50 transition-all">
                    <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-amber-500"></div>
                    <div class="w-12 h-12 rounded-2xl bg-amber-100 flex items-center justify-center shrink-0 border border-amber-200/50 shadow-sm">
                        <i class="fa-solid fa-hourglass-half text-amber-600 text-base"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-start mb-1.5">
                            <p class="text-slate-900 font-black text-sm">คำขอยืมรออนุมัติ</p>
                            <span class="text-amber-500 text-[9px] font-black uppercase tracking-widest">PENDING</span>
                        </div>
                        <p class="text-slate-500 text-[11px] font-medium leading-relaxed">
                            คุณมีคำขอยืม <?= $borrow_pending_count ?> รายการรอเจ้าหน้าที่อนุมัติ
                        </p>
                    </div>
                </button>
                <?php endif; ?>

                <?php if ($upcoming_count > 0): ?>
                <div class="flex gap-5 p-6 bg-blue-50/30 relative">
                    <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-[#2e9e63]"></div>
                    <div class="w-12 h-12 rounded-2xl bg-blue-100 flex items-center justify-center shrink-0 border border-blue-200/50 shadow-sm">
                        <i class="fa-solid fa-calendar-check text-[#2e9e63] text-base"></i>
                    </div>
                    <div class="flex-1 min-w-0 text-left">
                        <div class="flex justify-between items-start mb-1.5">
                            <p class="text-slate-900 font-black text-sm">นัดหมายสุขภาพ</p>
                            <span class="text-slate-400 text-[9px] font-bold">UPCOMING</span>
                        </div>
                        <p class="text-slate-500 text-[11px] font-medium leading-relaxed">
                            คุณมีนัดหมายสุขภาพที่กำลังจะถึงจำนวน <?= $upcoming_count ?> รายการ กรุณาตรวจสอบเวลาอีกครั้ง
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($notif_total === 0): ?>
                <div class="p-12 text-center">
                    <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-slate-50 text-slate-400 flex items-center justify-center text-xl">
                        <i class="fa-solid fa-bell-slash"></i>
                    </div>
                    <p class="text-sm font-black text-slate-700">ไม่มีการแจ้งเตือนใหม่</p>
                    <p class="mt-1 text-[11px] font-bold text-slate-400">รายการแจ้งเตือนจะแสดงที่นี่</p>
                </div>
                <?php endif; ?>
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
                    <div class="w-2 h-6 bg-[#2e9e63] rounded-full"></div>
                    <h3 class="text-slate-900 font-black text-2xl tracking-tight text-left">เลือกแคมเปญ</h3>
                </div>
                <p class="text-slate-400 text-xs font-bold tracking-wide text-left opacity-70">AVAILABLE MEDICAL
                    CAMPAIGNS</p>
            </div>
            <div class="flex-1 overflow-y-auto custom-scrollbar px-8 py-6 space-y-5 bg-slate-50/30 text-left">
                <?php if (empty($camp_list)): ?>
                    <div class="py-16 text-center text-slate-300 font-black text-sm italic">ขออภัย
                        ยังไม่มีแคมเปญที่เปิดรับจอง</div>
                <?php else: ?>
                    <?php foreach ($camp_list as $c):
                        $style = getCampStyle($c['type']);
                        $remaining = $c['total_capacity'] - $c['used_seats'];
                        $isFull = ($remaining <= 0 || $c['status'] === 'full');
                        $isComing = ($c['status'] === 'coming_soon');
                        ?>
                        <div
                            class="bg-white rounded-[2.5rem] p-7 border border-slate-100 shadow-[0_15px_30px_rgba(0,0,0,0.03)] relative transition-all hover:shadow-xl <?= ($isFull || $isComing) ? 'opacity-70' : '' ?>">
                            <div class="flex justify-between items-start mb-5">
                                <span class="px-4 py-1.5 rounded-xl border <?= $style['class'] ?> text-[10px] font-black uppercase tracking-widest"><?= $style['label'] ?></span>
                                <?php if ($isComing): ?>
                                    <span class="text-purple-600 text-[10px] font-black uppercase tracking-widest">Coming Soon</span>
                                <?php elseif ($isFull): ?>
                                    <span class="text-red-500 text-[10px] font-black uppercase tracking-widest">Fully Booked</span>
                                <?php else: ?>
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 bg-blue-500 rounded-full animate-pulse"></span>
                                        <span class="text-[#2e9e63] text-[10px] font-black uppercase tracking-widest"><?= $remaining ?> SLOTS LEFT</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h4 class="text-slate-900 font-black text-base mb-6 leading-snug">
                                <?= htmlspecialchars($c['title'] ?? '') ?>
                            </h4>
                            <?php if ($isComing): ?>
                                <button disabled class="w-full h-16 bg-purple-50 text-purple-400 font-black rounded-2xl cursor-not-allowed text-sm">COMING SOON</button>
                            <?php elseif ($isFull): ?>
                                <button disabled class="w-full h-16 bg-slate-100 text-slate-400 font-black rounded-2xl cursor-not-allowed text-sm">NOT AVAILABLE</button>
                            <?php else: ?>
                                <a href="booking_date.php?campaign_id=<?= $c['id'] ?>"
                                    class="w-full h-16 bg-[#2e9e63] text-white font-black rounded-2xl flex items-center justify-center gap-3 active:scale-95 transition-all text-sm shadow-lg shadow-green-100">BOOK THIS CAMPAIGN <i class="fa-solid fa-chevron-right text-[10px]"></i></a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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
                            class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center text-[#2e9e63] shadow-xl border border-slate-50 shrink-0">
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
                        class="absolute right-2.5 top-2.5 w-13 h-13 bg-[#2e9e63] text-white rounded-2xl shadow-[0_10px_25px_rgba(0,82,204,0.3)] active:scale-90 transition-all flex items-center justify-center overflow-hidden"><i
                            class="fa-solid fa-paper-plane-top text-sm"></i></button></form>
            </div>
        </div>
    </div>
    <div id="vaccination-modal" class="fixed inset-0 z-[115] hidden flex items-end sm:items-center justify-center p-0 sm:p-6">
        <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm" onclick="hideVaccinationHistory()"></div>
        <div
            class="relative bg-[#f8fafc] w-full max-w-[920px] rounded-t-[2rem] sm:rounded-[1.5rem] border border-slate-200 shadow-2xl animate-in slide-in-from-bottom duration-300 flex flex-col max-h-[92vh] overflow-hidden text-left">
            <div class="flex items-start justify-between gap-5 border-b border-slate-200 bg-white px-6 py-5 sm:px-8">
                <div class="min-w-0">
                    <div class="mb-2 flex items-center gap-3">
                        <span class="h-6 w-1 rounded-full bg-[#2e9e63]"></span>
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Health Overview</p>
                    </div>
                    <h3 class="text-xl font-black tracking-tight text-slate-900">สรุปสุขภาพของคุณ</h3>
                    <p class="mt-1 text-xs font-semibold text-slate-500">ภาพรวมการดูแลสุขภาพ วัคซีน และการนัดหมาย</p>
                </div>
                <button onclick="hideVaccinationHistory()"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-400 transition-all active:scale-95 hover:text-slate-700">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- ── Tabs ── -->
            <div class="flex gap-1 border-b border-slate-200 bg-white px-6 sm:px-8">
                <button type="button" id="ho-tab-overview-btn" onclick="switchHealthTab('overview')"
                    class="ho-tab-btn relative px-4 py-3 text-sm font-black text-[#2e9e63] transition-colors">
                    <i class="fa-solid fa-chart-pie mr-1.5 text-xs"></i>ภาพรวม
                    <span class="ho-tab-underline absolute bottom-0 left-0 right-0 h-0.5 bg-[#2e9e63]"></span>
                </button>
                <button type="button" id="ho-tab-vaccine-btn" onclick="switchHealthTab('vaccine')"
                    class="ho-tab-btn relative px-4 py-3 text-sm font-black text-slate-400 transition-colors">
                    <i class="fa-solid fa-syringe mr-1.5 text-xs"></i>ประวัติวัคซีน
                    <span class="ho-tab-underline absolute bottom-0 left-0 right-0 h-0.5 bg-[#2e9e63] hidden"></span>
                </button>
            </div>

            <!-- ── Overview Tab ── -->
            <div id="ho-overview-panel" class="flex-1 overflow-auto bg-[#f8fafc] p-4 sm:p-6 space-y-4">
                <?php
                    $hoVaccTotal     = $healthOverview['vaccine_total'];
                    $hoVaccLast      = $healthOverview['vaccine_last_detail'] ?? null;
                    $hoVaccNextDue   = $healthOverview['vaccine_next_due'] ?? null;
                    $hoHcTotal       = $healthOverview['healthcheck_total'];
                    $hoHcLast        = $healthOverview['healthcheck_last'];
                    $hoUpcoming      = $healthOverview['upcoming_list'];
                ?>

                <!-- Quick stats -->
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-2xl border border-emerald-100 bg-white p-4">
                        <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <i class="fa-solid fa-syringe text-sm"></i>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">วัคซีน</p>
                        <p class="mt-1 text-xl font-black text-slate-900"><?= $hoVaccTotal ?></p>
                        <p class="text-[10px] font-bold text-slate-400">รายการ</p>
                    </div>
                    <div class="rounded-2xl border border-green-100 bg-white p-4">
                        <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-xl bg-green-50 text-green-600">
                            <i class="fa-solid fa-stethoscope text-sm"></i>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">ตรวจสุขภาพ</p>
                        <p class="mt-1 text-xl font-black text-slate-900"><?= $hoHcTotal ?></p>
                        <p class="text-[10px] font-bold text-slate-400">ครั้ง</p>
                    </div>
                    <div class="rounded-2xl border border-blue-100 bg-white p-4">
                        <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
                            <i class="fa-solid fa-calendar-check text-sm"></i>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">นัดหมาย</p>
                        <p class="mt-1 text-xl font-black text-slate-900"><?= count($hoUpcoming) ?></p>
                        <p class="text-[10px] font-bold text-slate-400">ที่จะถึง</p>
                    </div>
                </div>

                <?php if ($hoVaccNextDue): ?>
                <!-- Booster reminder -->
                <div class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                        <i class="fa-solid fa-bell"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-black uppercase tracking-widest text-amber-700">แจ้งเตือนวัคซีนกระตุ้น</p>
                        <p class="mt-1 text-sm font-black text-slate-900"><?= htmlspecialchars($hoVaccNextDue['vaccine_name'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-xs font-bold text-amber-700">ครบกำหนดวันที่ <?= htmlspecialchars(formatThaiDate($hoVaccNextDue['next_due_date']), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Upcoming appointments card -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">นัดหมายที่จะถึง</p>
                        <a href="my_bookings.php" class="text-[11px] font-black text-[#2e9e63] hover:underline">ดูทั้งหมด <i class="fa-solid fa-arrow-right text-[9px]"></i></a>
                    </div>
                    <?php if (empty($hoUpcoming)): ?>
                        <p class="py-4 text-center text-xs font-bold text-slate-400">ยังไม่มีนัดหมายที่จะถึง</p>
                    <?php else: foreach ($hoUpcoming as $up): $cs = getCampStyle($up['camp_type']); ?>
                        <div class="flex items-center gap-3 border-t border-slate-100 py-3 first:border-0 first:pt-0">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl <?= $cs['class'] ?>">
                                <i class="fa-solid <?= $cs['icon'] ?>"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-black text-slate-900"><?= htmlspecialchars($up['camp_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-[11px] font-bold text-slate-500"><?= htmlspecialchars(formatThaiDate($up['slot_date']), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(substr((string)$up['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>–<?= htmlspecialchars(substr((string)$up['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Vaccine summary card -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">วัคซีนล่าสุด</p>
                        <button type="button" onclick="switchHealthTab('vaccine')" class="text-[11px] font-black text-[#2e9e63] hover:underline">ดูทั้งหมด <i class="fa-solid fa-arrow-right text-[9px]"></i></button>
                    </div>
                    <?php if (!$hoVaccLast): ?>
                        <p class="py-4 text-center text-xs font-bold text-slate-400">ยังไม่มีประวัติการฉีดวัคซีน</p>
                    <?php else: ?>
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                                <i class="fa-solid fa-syringe"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-black text-slate-900"><?= htmlspecialchars($hoVaccLast['vaccine_name'], ENT_QUOTES, 'UTF-8') ?><?= $hoVaccLast['dose_number'] ? ' · เข็มที่ ' . (int)$hoVaccLast['dose_number'] : '' ?></p>
                                <p class="text-[11px] font-bold text-slate-500"><?= htmlspecialchars(formatThaiDate(substr((string)$hoVaccLast['vaccinated_at'], 0, 10)), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Health check card -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="mb-3 text-[11px] font-black uppercase tracking-widest text-slate-400">ตรวจสุขภาพล่าสุด</p>
                    <?php if (!$hoHcLast): ?>
                        <p class="py-4 text-center text-xs font-bold text-slate-400">ยังไม่มีประวัติการตรวจสุขภาพ</p>
                    <?php else: ?>
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-green-50 text-green-600">
                                <i class="fa-solid fa-stethoscope"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-black text-slate-900">ตรวจสุขภาพ</p>
                                <p class="text-[11px] font-bold text-slate-500"><?= htmlspecialchars(formatThaiDate($hoHcLast), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Health profile -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">ข้อมูลผู้ใช้</p>
                        <a href="profile.php" class="text-[11px] font-black text-[#2e9e63] hover:underline">แก้ไข <i class="fa-solid fa-arrow-right text-[9px]"></i></a>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">ชื่อ-นามสกุล</p>
                            <p class="mt-1 text-sm font-black text-slate-900 truncate"><?= htmlspecialchars($user['full_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">รหัส</p>
                            <p class="mt-1 text-sm font-black text-slate-900 truncate"><?= htmlspecialchars($user['student_personnel_id'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Vaccine Tab ── -->
            <div id="ho-vaccine-panel" class="hidden flex-1 flex-col overflow-hidden">
            <div class="border-b border-slate-200 bg-white px-6 py-4 sm:px-8">
                <div class="grid gap-3 sm:grid-cols-[1fr_190px_auto]">
                    <label class="relative block">
                        <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-xs text-slate-300"></i>
                        <input id="vaccination-search" type="search"
                            class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm font-bold text-slate-700 outline-none transition-all focus:border-[#2e9e63] focus:bg-white"
                            placeholder="ค้นหาชื่อวัคซีน, lot, สถานที่"
                            onkeydown="if(event.key === 'Enter') applyVaccinationFilters()">
                    </label>
                    <select id="vaccination-status"
                        class="h-12 rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm font-bold text-slate-700 outline-none transition-all focus:border-[#2e9e63] focus:bg-white">
                        <option value="">ทุกสถานะ</option>
                        <option value="completed">ฉีดแล้ว</option>
                        <option value="cancelled">ยกเลิก</option>
                        <option value="entered_in_error">บันทึกผิด</option>
                    </select>
                    <button type="button" onclick="applyVaccinationFilters()"
                        class="h-12 rounded-2xl bg-[#2e9e63] px-6 text-sm font-black text-white transition-all active:scale-95">
                        ค้นหา
                    </button>
                </div>
            </div>

            <div class="flex-1 overflow-auto bg-[#f8fafc] p-4 sm:p-6">
                <div class="overflow-hidden rounded-[1.25rem] border border-slate-200 bg-white">
                    <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p id="vaccination-pagination-info" class="text-xs font-black text-slate-500">หน้า 1 / 1 · รวม 0 รายการ</p>
                        <div id="vaccination-pagination-controls" class="flex flex-wrap gap-2"></div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-[760px] w-full">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">วัคซีน</th>
                                    <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">เข็ม</th>
                                    <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">วันที่ฉีด</th>
                                    <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">Lot / จุดฉีด</th>
                                    <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">ผู้ให้บริการ</th>
                                    <th class="px-5 py-3 text-left text-[10px] font-black uppercase tracking-[0.14em] text-slate-400">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody id="vaccination-table-body" class="divide-y divide-slate-50">
                                <tr>
                                    <td colspan="6" class="px-5 py-10 text-center text-sm font-black text-slate-400">กำลังโหลดข้อมูล...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>

    <!-- ── Borrow Modal ── -->
    <div id="borrow-modal" class="fixed inset-0 z-[115] hidden flex items-end sm:items-center justify-center p-0 sm:p-6">
        <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm" onclick="hideBorrow()"></div>
        <div class="relative bg-[#f8fafc] w-full max-w-[760px] rounded-t-[2rem] sm:rounded-[1.5rem] border border-slate-200 shadow-2xl animate-in slide-in-from-bottom duration-300 flex flex-col max-h-[92vh] overflow-hidden text-left">

            <div class="flex items-start justify-between gap-5 border-b border-slate-200 bg-white px-6 py-5 sm:px-8">
                <div class="min-w-0">
                    <div class="mb-2 flex items-center gap-3">
                        <span class="h-6 w-1 rounded-full bg-amber-500"></span>
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">e-Borrow</p>
                    </div>
                    <h3 class="text-xl font-black tracking-tight text-slate-900">ยืม-คืนอุปกรณ์</h3>
                    <p class="mt-1 text-xs font-semibold text-slate-500">รายการยืมที่ active และค่าปรับค้างชำระ</p>
                </div>
                <button onclick="hideBorrow()"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-400 transition-all active:scale-95 hover:text-slate-700">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="flex-1 overflow-auto bg-[#f8fafc] p-4 sm:p-6 space-y-4">

                <!-- Stats row -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-amber-100 bg-white p-4">
                        <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
                            <i class="fa-solid fa-box-archive text-sm"></i>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">กำลังยืม</p>
                        <p class="mt-1 text-xl font-black text-slate-900"><?= $borrow_count ?></p>
                        <p class="text-[10px] font-bold text-slate-400">รายการ</p>
                    </div>
                    <div class="rounded-2xl border <?= $borrow_total_fine > 0 ? 'border-rose-200 bg-rose-50' : 'border-slate-100 bg-white' ?> p-4">
                        <div class="mb-2 flex h-9 w-9 items-center justify-center rounded-xl <?= $borrow_total_fine > 0 ? 'bg-rose-100 text-rose-700' : 'bg-slate-50 text-slate-400' ?>">
                            <i class="fa-solid fa-coins text-sm"></i>
                        </div>
                        <p class="text-[10px] font-black uppercase tracking-widest <?= $borrow_total_fine > 0 ? 'text-rose-700' : 'text-slate-400' ?>">ค่าปรับค้างชำระ</p>
                        <p class="mt-1 text-xl font-black <?= $borrow_total_fine > 0 ? 'text-rose-700' : 'text-slate-900' ?>">฿<?= number_format($borrow_total_fine, 2) ?></p>
                    </div>
                </div>

                <!-- Active list -->
                <div class="rounded-2xl border border-slate-200 bg-white">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <p class="text-[11px] font-black uppercase tracking-widest text-slate-400">รายการที่ยืมอยู่</p>
                    </div>
                    <?php if (empty($borrow_active)): ?>
                        <div class="px-5 py-10 text-center">
                            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-400">
                                <i class="fa-solid fa-box-open"></i>
                            </div>
                            <p class="text-sm font-black text-slate-700">ไม่มีรายการที่ยืมอยู่</p>
                            <p class="mt-1 text-xs font-semibold text-slate-400">กดปุ่มด้านล่างเพื่อเริ่มยืมอุปกรณ์</p>
                        </div>
                    <?php else: foreach ($borrow_active as $b):
                        $today = date('Y-m-d');
                        $dueDate = $b['due_date'] ?? '';
                        $isPending = ($b['approval_status'] ?? '') === 'pending';
                        $isOverdue = $dueDate && $dueDate < $today;
                        $daysLeft = $dueDate ? (int) ((strtotime($dueDate) - strtotime($today)) / 86400) : null;
                        $img = $b['image_url'] ?? '';
                        $imgSrc = $img !== '' ? '../e_Borrow/uploads/' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') : '';
                    ?>
                        <div class="flex items-center gap-4 border-t border-slate-100 px-5 py-4 first:border-0">
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-amber-50 text-amber-600">
                                <?php if ($imgSrc !== ''): ?>
                                    <img src="<?= $imgSrc ?>" alt="" class="h-full w-full object-cover" onerror="this.outerHTML='<i class=\'fa-solid fa-box text-lg\'></i>'">
                                <?php else: ?>
                                    <i class="fa-solid fa-box text-lg"></i>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-black text-slate-900"><?= htmlspecialchars($b['equipment_name'] ?: $b['type_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="text-[11px] font-bold text-slate-500">
                                    <?= $b['type_name'] ? htmlspecialchars($b['type_name'], ENT_QUOTES, 'UTF-8') . ' · ' : '' ?>
                                    ยืม <?= htmlspecialchars(formatThaiDate(substr((string)$b['borrow_date'], 0, 10)), ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <?php if ($dueDate): ?>
                                <p class="mt-0.5 text-[11px] font-bold <?= $isOverdue ? 'text-rose-600' : ($daysLeft !== null && $daysLeft <= 3 ? 'text-amber-600' : 'text-slate-500') ?>">
                                    <i class="fa-solid fa-calendar-days mr-1"></i>
                                    คืน <?= htmlspecialchars(formatThaiDate($dueDate), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($isOverdue): ?>
                                        · เลยกำหนด <?= abs((int)$daysLeft) ?> วัน
                                    <?php elseif ($daysLeft !== null && $daysLeft >= 0): ?>
                                        · เหลือ <?= (int)$daysLeft ?> วัน
                                    <?php endif; ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <div class="shrink-0 flex flex-col items-end gap-1.5">
                                <span class="rounded-full px-3 py-1 text-[10px] font-black <?= $isPending ? 'bg-amber-50 text-amber-700 border border-amber-100' : 'bg-emerald-50 text-emerald-700 border border-emerald-100' ?>">
                                    <?= $isPending ? 'รออนุมัติ' : 'อนุมัติแล้ว' ?>
                                </span>
                                <?php if ($isPending): ?>
                                <button type="button" onclick="cancelBorrowRequest(<?= (int)$b['transaction_id'] ?>, this)"
                                    class="rounded-lg px-2.5 py-1 text-[10px] font-black bg-rose-50 text-rose-600 border border-rose-100 active:scale-95 transition-all">
                                    <i class="fa-solid fa-xmark mr-0.5"></i>ยกเลิก
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Action buttons -->
                <div class="grid grid-cols-2 gap-3">
                    <button type="button" onclick="showBorrowFlow()" class="flex h-14 items-center justify-center gap-2 rounded-2xl bg-[#2e9e63] text-white font-black text-sm shadow-[0_10px_25px_rgba(46,158,99,0.25)] active:scale-95 transition-all">
                        <i class="fa-solid fa-plus"></i> ยืมอุปกรณ์
                    </button>
                    <button type="button" onclick="showBorrowHistory()" class="flex h-14 items-center justify-center gap-2 rounded-2xl bg-white border border-slate-200 text-slate-600 font-black text-sm active:scale-95 transition-all">
                        <i class="fa-solid fa-clock-rotate-left"></i> ประวัติทั้งหมด
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Borrow Flow Modal (multi-step) ── -->
    <div id="borrow-flow-modal" class="fixed inset-0 z-[120] hidden flex items-end sm:items-center justify-center p-0 sm:p-6">
        <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm" onclick="hideBorrowFlow()"></div>
        <div class="relative bg-[#f8fafc] w-full max-w-[680px] rounded-t-[2rem] sm:rounded-[1.5rem] border border-slate-200 shadow-2xl animate-in slide-in-from-bottom duration-300 flex flex-col max-h-[92vh] overflow-hidden text-left">

            <div class="flex items-start justify-between gap-5 border-b border-slate-200 bg-white px-6 py-5 sm:px-8">
                <div class="min-w-0">
                    <div class="mb-2 flex items-center gap-3">
                        <span class="h-6 w-1 rounded-full bg-[#2e9e63]"></span>
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">
                            ขั้นตอน <span id="bf-step-num">1</span>/3
                        </p>
                    </div>
                    <h3 id="bf-title" class="text-xl font-black tracking-tight text-slate-900">เลือกอุปกรณ์</h3>
                    <p id="bf-subtitle" class="mt-1 text-xs font-semibold text-slate-500">เลือกประเภทอุปกรณ์ที่ต้องการยืม</p>
                </div>
                <button onclick="hideBorrowFlow()" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-400 transition-all active:scale-95 hover:text-slate-700">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Progress -->
            <div class="px-6 sm:px-8 py-3 bg-white border-b border-slate-100">
                <div class="flex items-center gap-2">
                    <div id="bf-bar-1" class="h-1.5 flex-1 rounded-full bg-[#2e9e63] transition-colors"></div>
                    <div id="bf-bar-2" class="h-1.5 flex-1 rounded-full bg-slate-200 transition-colors"></div>
                    <div id="bf-bar-3" class="h-1.5 flex-1 rounded-full bg-slate-200 transition-colors"></div>
                </div>
            </div>

            <!-- Body -->
            <div class="flex-1 overflow-auto bg-[#f8fafc] p-4 sm:p-6">

                <!-- Loading -->
                <div id="bf-loading" class="hidden py-16 text-center">
                    <div class="inline-block w-10 h-10 border-4 border-emerald-200 border-t-[#2e9e63] rounded-full animate-spin mb-3"></div>
                    <p class="text-sm font-bold text-slate-500">กำลังโหลดข้อมูล...</p>
                </div>

                <!-- Error -->
                <div id="bf-error" class="hidden rounded-2xl bg-rose-50 border border-rose-100 text-rose-700 px-4 py-3 text-sm font-bold mb-3 flex items-start gap-2">
                    <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                    <span id="bf-error-text" class="flex-1"></span>
                </div>

                <!-- Step 1: Browse categories -->
                <div id="bf-step-1">
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm flex items-center gap-3 px-5 h-12 mb-4">
                        <i class="fa-solid fa-magnifying-glass text-slate-400"></i>
                        <input type="text" id="bf-search" placeholder="ค้นหาชื่ออุปกรณ์..."
                            class="flex-1 bg-transparent border-0 outline-none text-sm font-bold text-slate-700 placeholder:text-slate-300">
                    </div>
                    <div id="bf-cat-grid" class="grid grid-cols-2 gap-3"></div>
                    <div id="bf-cat-empty" class="hidden bg-white rounded-2xl p-8 border border-slate-100 shadow-sm text-center">
                        <i class="fa-solid fa-box-open text-slate-300 text-2xl mb-2"></i>
                        <p class="text-sm font-bold text-slate-400">ไม่พบอุปกรณ์ที่ตรงกับการค้นหา</p>
                    </div>
                </div>

                <!-- Step 2: Form -->
                <div id="bf-step-2" class="hidden space-y-4">
                    <div id="bf-selected-card" class="bg-white rounded-2xl border border-emerald-100 shadow-sm p-4 flex items-center gap-3">
                        <div id="bf-selected-thumb" class="w-14 h-14 rounded-xl bg-emerald-50 flex items-center justify-center shrink-0 overflow-hidden text-[#2e9e63]">
                            <i class="fa-solid fa-stethoscope"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] font-black uppercase tracking-widest text-[#2e9e63]">เลือกแล้ว</p>
                            <p id="bf-selected-name" class="text-sm font-black text-slate-900 truncate">-</p>
                            <p id="bf-selected-stock" class="text-[11px] font-bold text-slate-400">-</p>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5 space-y-4">
                        <div>
                            <label class="text-sm font-bold text-slate-700 block mb-1.5">เหตุผลการยืม <span class="text-rose-500">*</span></label>
                            <textarea id="bf-reason" rows="3" placeholder="ระบุเหตุผลและการใช้งาน..."
                                class="w-full px-4 py-3 bg-slate-50 border border-slate-100 rounded-2xl outline-none font-bold text-slate-700 resize-none focus:ring-4 focus:ring-emerald-50 focus:border-[#2e9e63]"></textarea>
                        </div>
                        <div>
                            <label class="text-sm font-bold text-slate-700 block mb-1.5">วันที่กำหนดคืน <span class="text-rose-500">*</span></label>
                            <input type="date" id="bf-due" min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                                class="w-full h-12 px-4 bg-slate-50 border border-slate-100 rounded-2xl outline-none font-bold text-slate-700 focus:ring-4 focus:ring-emerald-50 focus:border-[#2e9e63]">
                        </div>
                        <div>
                            <label class="text-sm font-bold text-slate-700 block mb-1.5">เจ้าหน้าที่ผู้อนุมัติ <span class="text-rose-500">*</span></label>
                            <select id="bf-staff"
                                class="w-full h-12 px-4 bg-slate-50 border border-slate-100 rounded-2xl outline-none font-bold text-slate-700 focus:ring-4 focus:ring-emerald-50 focus:border-[#2e9e63]"></select>
                        </div>
                        <div>
                            <label class="text-sm font-bold text-slate-700 block mb-1.5">
                                <i class="fa-solid fa-paperclip text-slate-400 mr-1"></i>เอกสารแนบ <span class="text-slate-400 text-xs font-bold">(ถ้ามี)</span>
                            </label>
                            <input type="file" id="bf-file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                class="w-full text-xs font-bold text-slate-500 file:h-10 file:px-4 file:rounded-xl file:border-0 file:bg-emerald-50 file:text-[#2e9e63] file:font-black file:text-xs file:cursor-pointer">
                            <p class="mt-1 text-[10px] font-bold text-slate-400">PDF, Word, รูปภาพ — ไม่เกิน 5MB</p>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Confirm -->
                <div id="bf-step-3" class="hidden space-y-4">
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-3">สรุปคำขอ</p>
                        <dl class="space-y-3 text-sm">
                            <div class="flex gap-3">
                                <dt class="w-24 shrink-0 text-slate-400 font-bold">อุปกรณ์</dt>
                                <dd id="bf-sum-name" class="flex-1 font-black text-slate-900">-</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-24 shrink-0 text-slate-400 font-bold">วันที่คืน</dt>
                                <dd id="bf-sum-due" class="flex-1 font-black text-slate-900">-</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-24 shrink-0 text-slate-400 font-bold">เจ้าหน้าที่</dt>
                                <dd id="bf-sum-staff" class="flex-1 font-black text-slate-900">-</dd>
                            </div>
                            <div class="flex gap-3">
                                <dt class="w-24 shrink-0 text-slate-400 font-bold">เหตุผล</dt>
                                <dd id="bf-sum-reason" class="flex-1 font-bold text-slate-700 whitespace-pre-wrap break-words">-</dd>
                            </div>
                            <div id="bf-sum-file-row" class="hidden flex gap-3">
                                <dt class="w-24 shrink-0 text-slate-400 font-bold">ไฟล์แนบ</dt>
                                <dd id="bf-sum-file" class="flex-1 font-bold text-slate-700 truncate">-</dd>
                            </div>
                        </dl>
                    </div>
                    <div class="rounded-2xl bg-amber-50 border border-amber-100 px-4 py-3 text-[12px] font-bold text-amber-700 flex items-start gap-2">
                        <i class="fa-solid fa-circle-info mt-0.5"></i>
                        <span>คำขอจะถูกส่งให้เจ้าหน้าที่อนุมัติ คุณจะได้รับการแจ้งเตือนเมื่อสถานะเปลี่ยน</span>
                    </div>
                </div>
            </div>

            <!-- Footer actions -->
            <div class="border-t border-slate-200 bg-white px-6 py-4 sm:px-8 flex gap-3">
                <button id="bf-back-btn" type="button" onclick="bfBack()"
                    class="hidden flex-1 h-12 rounded-2xl bg-slate-100 text-slate-500 font-black text-sm active:scale-95 transition-all">
                    <i class="fa-solid fa-chevron-left mr-1"></i>ย้อนกลับ
                </button>
                <button id="bf-next-btn" type="button" onclick="bfNext()"
                    class="hidden flex-1 h-12 rounded-2xl bg-[#2e9e63] text-white font-black text-sm shadow-[0_10px_20px_rgba(46,158,99,0.25)] active:scale-95 transition-all">
                    ถัดไป<i class="fa-solid fa-chevron-right ml-1"></i>
                </button>
                <button id="bf-submit-btn" type="button" onclick="bfSubmit()"
                    class="hidden flex-1 h-12 rounded-2xl bg-[#2e9e63] text-white font-black text-sm shadow-[0_10px_20px_rgba(46,158,99,0.25)] active:scale-95 transition-all">
                    <i class="fa-solid fa-paper-plane mr-1"></i>ส่งคำขอ
                </button>
            </div>
        </div>
    </div>

    <!-- ── Borrow History Modal ── -->
    <div id="borrow-history-modal" class="fixed inset-0 z-[120] hidden flex items-end sm:items-center justify-center p-0 sm:p-6">
        <div class="absolute inset-0 bg-slate-900/55 backdrop-blur-sm" onclick="hideBorrowHistory()"></div>
        <div class="relative bg-[#f8fafc] w-full max-w-[760px] rounded-t-[2rem] sm:rounded-[1.5rem] border border-slate-200 shadow-2xl animate-in slide-in-from-bottom duration-300 flex flex-col max-h-[92vh] overflow-hidden text-left">

            <div class="flex items-start justify-between gap-5 border-b border-slate-200 bg-white px-6 py-5 sm:px-8">
                <div class="min-w-0">
                    <div class="mb-2 flex items-center gap-3">
                        <span class="h-6 w-1 rounded-full bg-slate-400"></span>
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">e-Borrow</p>
                    </div>
                    <h3 class="text-xl font-black tracking-tight text-slate-900">ประวัติคำขอ</h3>
                    <p class="mt-1 text-xs font-semibold text-slate-500">รายการที่คืนแล้ว, ยกเลิก, ปฏิเสธ และรอดำเนินการ</p>
                </div>
                <button onclick="hideBorrowHistory()" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-400 transition-all active:scale-95 hover:text-slate-700">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="flex-1 overflow-auto bg-[#f8fafc] p-4 sm:p-6">
                <div class="flex items-center justify-between mb-3 px-1">
                    <p id="bh-info" class="text-[11px] font-black text-slate-500">กำลังโหลด...</p>
                </div>

                <div id="bh-error" class="hidden rounded-2xl bg-rose-50 border border-rose-100 text-rose-700 px-4 py-3 text-sm font-bold mb-3 flex items-start gap-2">
                    <i class="fa-solid fa-circle-exclamation mt-0.5"></i>
                    <span id="bh-error-text" class="flex-1"></span>
                </div>

                <div id="bh-list" class="space-y-3"></div>

                <div id="bh-empty" class="hidden bg-white rounded-2xl p-10 border border-slate-100 shadow-sm text-center">
                    <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-slate-50 text-slate-400 flex items-center justify-center text-xl">
                        <i class="fa-solid fa-clipboard-list"></i>
                    </div>
                    <p class="text-sm font-black text-slate-700">ยังไม่มีประวัติการทำรายการ</p>
                </div>

                <div id="bh-pagination" class="flex flex-wrap justify-center gap-2 mt-5"></div>
            </div>
        </div>
    </div>

    <div id="upcoming-modal" class="fixed inset-0 z-[110] hidden flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" onclick="hideUpcoming()"></div>
        <div
            class="relative bg-white w-full max-w-[340px] rounded-[3.5rem] shadow-[0_30px_60px_rgba(0,0,0,0.15)] p-10 text-center animate-in zoom-in duration-300 overflow-hidden">
            <div class="absolute -right-10 -top-10 w-32 h-32 bg-blue-50 rounded-full blur-2xl"></div>
            <div
                class="w-24 h-24 bg-blue-50 rounded-[2.5rem] flex items-center justify-center mx-auto mb-8 text-[#2e9e63] text-4xl shadow-inner border border-blue-100/50">
                <i class="fa-solid fa-rocket animate-float"></i>
            </div>
            <h3 class="text-slate-900 font-black text-2xl mb-3 tracking-tight">Coming Soon</h3>
            <p class="text-slate-400 text-sm font-bold mb-10 leading-relaxed px-2">ฟีเจอร์ <span id="upcoming-name"
                    class="text-[#2e9e63] font-black bg-blue-50 px-2 py-0.5 rounded-lg"></span> อยู่ในแผนการพัฒนา
                และจะพร้อมให้คุณใช้งานในเร็วๆ นี้ครับ</p><button onclick="hideUpcoming()"
                class="w-full h-18 bg-[#2e9e63] text-white font-black rounded-2xl shadow-[0_15px_30px_rgba(0,82,204,0.3)] active:scale-95 transition-all text-sm tracking-widest">GOT
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
                <div class="flex items-center gap-2">
                    <button id="ann-lang-toggle" onclick="toggleLang()" class="hidden px-2.5 py-1 rounded-lg bg-white/20 hover:bg-white/30 text-white text-[10px] font-black transition-all">
                        EN
                    </button>
                    <button onclick="annClose()" class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center text-white transition-colors" aria-label="ปิด">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
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
    let currentLang  = 'th';

    const typeConfig = {
        info:    { cls: 'ann-header-info',    icon: 'fa-bullhorn',         label: 'ข้อมูลทั่วไป',    btn: '#0052CC' },
        warning: { cls: 'ann-header-warning',  icon: 'fa-triangle-exclamation', label: 'แจ้งเตือน',  btn: '#d97706' },
        success: { cls: 'ann-header-success',  icon: 'fa-circle-check',    label: 'ข่าวดี',           btn: '#059669' },
        urgent:  { cls: 'ann-header-urgent',   icon: 'fa-siren-on',        label: 'ด่วน!',            btn: '#dc2626' },
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

        // Language Toggle
        const langToggle = document.getElementById('ann-lang-toggle');
        if (ann.title_en || ann.content_en) {
            langToggle.classList.remove('hidden');
            langToggle.textContent = currentLang === 'th' ? 'EN' : 'TH';
        } else {
            langToggle.classList.add('hidden');
            currentLang = 'th';
        }

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
        if (currentLang === 'en' && (ann.title_en || ann.content_en)) {
            document.getElementById('ann-title').textContent   = ann.title_en || ann.title;
            document.getElementById('ann-content').textContent = ann.content_en || ann.content;
        } else {
            document.getElementById('ann-title').textContent   = ann.title;
            document.getElementById('ann-content').textContent = ann.content;
        }

        // Dismiss button color
        const btn = document.getElementById('ann-btn-dismiss');
        btn.style.background  = cfg.btn;
        btn.style.boxShadow   = '';

        // Counter + dots
        updateDots(idx);
        document.getElementById('ann-counter').textContent =
            announcements.length > 1 ? (idx + 1) + ' / ' + announcements.length : '';
    }

    window.toggleLang = function() {
        currentLang = currentLang === 'th' ? 'en' : 'th';
        render(currentIndex);
    };

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
