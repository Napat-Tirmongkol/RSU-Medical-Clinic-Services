<?php
// user/hub.php — User Hub Dashboard (Bento Grid Design)
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
$next_appt = null;

try {
    $pdo = db();
    $today = date('Y-m-d');

    // User profile
    $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE line_user_id = :line_id LIMIT 1");
    $stmt->execute([':line_id' => $lineUserId]);
    $user = $stmt->fetch();

    if (!$user) {
        header('Location: index.php');
        exit;
    }

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
    $upcoming_count = (int)$stmt->fetchColumn();

    // Borrow count (optional)
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM borrow_records
            WHERE borrower_student_id = :sid AND status IN ('borrowed','approved')
        ");
        $stmt->execute([':sid' => $user['student_personnel_id']]);
        $borrow_count = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $borrow_count = 0;
    }

} catch (Exception $e) {
    error_log("Hub DB error: " . $e->getMessage());
}

// Helpers
function getInitials($name) {
    $parts = explode(' ', trim($name));
    return strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

function formatThaiDate($date) {
    $days = ["อาทิตย์", "จันทร์", "อังคาร", "พุธ", "พฤหัสบดี", "ศุกร์", "เสาร์"];
    $months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $ts = strtotime($date);
    return $days[date('w', $ts)] . ", " . date('j', $ts) . " " . $months[date('n', $ts)] . " " . (date('Y', $ts) + 543);
}

function campIcon($type) {
    return match($type) {
        'vaccine' => '💉',
        'health_check' => '🩺',
        'training' => '📋',
        default => '📅'
    };
}

$thaiDate = formatThaiDate($today);
$userInitials = getInitials($user['full_name']);
$hour = (int)date('H');
$greeting = ($hour >= 5 && $hour < 12) ? "สวัสดีตอนเช้า" : (($hour >= 12 && $hour < 17) ? "สวัสดีตอนบ่าย" : (($hour >= 17 && $hour < 21) ? "สวัสดีตอนเย็น" : "สวัสดีตอนค่ำ"));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>RSU Medical Hub</title>
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        :root {
            --accent: #2563eb;
            --accent-600: #1d4ed8;
            --accent-50: #eff6ff;
            --accent-100: #dbeafe;
            --accent-200: #bfdbfe;
            --bg: #f5f7fb;
            --surface: #ffffff;
            --ink: #0f172a;
            --ink-2: #334155;
            --ink-3: #64748b;
            --ink-4: #94a3b8;
            --line: #e5e8ef;
            --line-2: #eef1f6;
            --ok: #16a34a;
            --ok-bg: #dcfce7;
            --warn: #ca8a04;
            --warn-bg: #fef9c3;
            --danger: #dc2626;
            --danger-bg: #fee2e2;
            --radius: 16px;
            --radius-sm: 10px;
            --gap: 18px;
            --shadow-card: 0 1px 2px rgba(15,23,42,.04), 0 1px 0 rgba(15,23,42,.02);
            --shadow-hover: 0 10px 30px -12px rgba(37,99,235,.25), 0 2px 6px rgba(15,23,42,.06);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Prompt', 'Inter', ui-sans-serif, system-ui, sans-serif;
            background-color: var(--bg);
            color: var(--ink);
            -webkit-tap-highlight-color: transparent;
            line-height: 1.5;
        }

        .hub {
            min-height: 100vh;
            background: var(--bg);
        }

        /* ─── TopBar (Desktop only) ─── */
        .topbar {
            display: grid;
            grid-template-columns: 200px 1fr 300px 1fr auto auto;
            align-items: center;
            gap: 20px;
            padding: 14px 28px;
            border-bottom: 1px solid var(--line);
            background: var(--surface);
            position: sticky;
            top: 0;
            z-index: 50;
        }

        .topbar__brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: var(--ink);
        }

        .topbar__nav {
            display: flex;
            gap: 4px;
        }

        .topbar__nav a {
            padding: 8px 14px;
            border-radius: 10px;
            color: var(--ink-2);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background .15s, color .15s;
        }

        .topbar__nav a:hover {
            background: var(--line-2);
            color: var(--ink);
        }

        .topbar__nav a.is-active {
            background: var(--accent-50);
            color: var(--accent-600);
        }

        .topbar__search {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            height: 38px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--bg);
        }

        .topbar__search input {
            flex: 1;
            border: 0;
            background: transparent;
            outline: none;
            color: var(--ink);
            font-family: inherit;
            font-size: 13px;
        }

        .topbar__tools {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            border: 0;
            background: transparent;
            color: var(--ink-2);
            cursor: pointer;
            transition: background .15s, color .15s;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .btn-icon:hover {
            background: var(--line-2);
            color: var(--ink);
        }

        .topbar__ping {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--danger);
            box-shadow: 0 0 0 2px var(--surface);
        }

        .avatar {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--accent-100);
            color: var(--accent-600);
            font-weight: 600;
            font-size: 11px;
            cursor: pointer;
        }

        /* ─── Main Content ─── */
        .hub__main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 28px 28px 48px;
        }

        .hub__greet {
            margin-bottom: 28px;
        }

        .hub__greet h1 {
            font-size: 28px;
            font-weight: 600;
            letter-spacing: -0.015em;
            margin-bottom: 6px;
        }

        .hub__greet p {
            font-size: 14px;
            color: var(--ink-3);
        }

        /* ─── Bento Grid ─── */
        .bento-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: var(--gap);
        }

        .g-profile { grid-column: span 8; }
        .g-wallet { grid-column: span 4; }
        .g-appt { grid-column: span 5; }
        .g-qa { grid-column: span 4; }
        .g-notif { grid-column: span 3; }
        .g-stats { grid-column: span 8; }
        .g-camp { grid-column: span 4; }
        .g-well { grid-column: span 12; }

        @media (max-width: 1024px) {
            .topbar { grid-template-columns: auto 1fr auto; }
            .topbar__search { display: none; }
            .g-profile, .g-stats, .g-well { grid-column: span 12; }
            .g-wallet, .g-appt, .g-qa, .g-notif, .g-camp { grid-column: span 6; }
        }

        @media (max-width: 640px) {
            .topbar { display: none; }
            .hub__main { padding: 16px 16px 100px; }
            .bento-grid > * { grid-column: span 12; }
            .hub__greet h1 { font-size: 22px; }
        }

        /* ─── Bento Card ─── */
        .bento {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow-card);
            transition: border-color .2s, box-shadow .2s, transform .2s;
            overflow: hidden;
        }

        .bento:hover {
            border-color: var(--accent-200);
            box-shadow: var(--shadow-hover);
            transform: translateY(-1px);
        }

        .card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .card-head h3 {
            margin: 0;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink-3);
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .card-head a {
            color: var(--accent);
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        /* ─── Profile Card ─── */
        .profile {
            position: relative;
            display: flex;
            flex-direction: column;
            min-height: 220px;
            padding: 0;
        }

        .profile__bg {
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.5;
        }

        .profile__row {
            position: relative;
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 22px 22px 10px;
        }

        .profile__avatar {
            position: relative;
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: var(--accent-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent-600);
            font-weight: 600;
            font-size: 18px;
            flex-shrink: 0;
        }

        .profile__tick {
            position: absolute;
            right: -4px;
            bottom: -4px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--ok);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2.5px solid var(--surface);
            font-size: 10px;
        }

        .profile__meta {
            flex: 1;
        }

        .profile__hello {
            font-size: 12px;
            color: var(--ink-3);
            margin-bottom: 2px;
        }

        .profile__name {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        .profile__id {
            font-size: 12px;
            color: var(--ink-3);
        }

        .profile__qr {
            flex-shrink: 0;
            cursor: pointer;
            opacity: 0.6;
            transition: opacity .2s;
        }

        .profile__qr:hover {
            opacity: 1;
        }

        .profile__verified {
            position: relative;
            margin: auto 22px 22px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 12px;
            background: var(--accent-50);
            color: var(--accent-600);
            font-size: 12px;
            font-weight: 500;
            border: 1px solid var(--accent-100);
        }

        .profile__shield {
            width: 22px;
            height: 22px;
            border-radius: 6px;
            background: var(--accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
        }

        /* ─── Appointment Card ─── */
        .appt {
            display: flex;
            flex-direction: column;
        }

        .appt__empty {
            display: flex;
            align-items: center;
            gap: 18px;
            flex: 1;
            padding: 20px 0;
        }

        .appt__empty-art {
            width: 120px;
            height: 90px;
            background: var(--accent-50);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            flex-shrink: 0;
        }

        .appt__empty-copy {
            flex: 1;
        }

        .appt__empty-copy strong {
            display: block;
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .appt__empty-copy span {
            display: block;
            font-size: 13px;
            color: var(--ink-3);
            margin-bottom: 12px;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 14px;
            height: 38px;
            border-radius: 10px;
            border: 0;
            background: var(--accent);
            color: white;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s, transform .1s;
            font-family: inherit;
        }

        .btn-primary:hover {
            background: var(--accent-600);
        }

        .btn-primary:active {
            transform: translateY(1px);
        }

        .appt__body {
            display: flex;
            gap: 18px;
            align-items: flex-start;
            flex: 1;
            margin-bottom: 14px;
        }

        .appt__date {
            flex-shrink: 0;
            width: 72px;
            text-align: center;
            border-radius: 12px;
            border: 1px solid var(--line);
            overflow: hidden;
        }

        .appt__mo {
            background: var(--accent);
            color: white;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .12em;
            padding: 4px 0;
        }

        .appt__d {
            font-size: 28px;
            font-weight: 700;
            padding: 6px 0 0;
        }

        .appt__dow {
            font-size: 11px;
            color: var(--ink-3);
            padding: 0 0 6px;
        }

        .appt__info {
            flex: 1;
        }

        .appt__service {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .appt__doc {
            font-size: 13px;
            color: var(--ink-3);
            margin-bottom: 12px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 500;
            background: var(--line-2);
            color: var(--ink-2);
            margin-right: 6px;
            margin-bottom: 6px;
        }

        .chip--accent {
            background: var(--accent-50);
            color: var(--accent-600);
        }

        .appt__actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            border-top: 1px solid var(--line);
            padding-top: 14px;
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 10px;
            height: 34px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink-2);
            font-size: 12.5px;
            font-weight: 500;
            cursor: pointer;
            transition: background .15s, border-color .15s, color .15s;
            font-family: inherit;
            text-decoration: none;
        }

        .btn-ghost:hover {
            border-color: var(--accent-200);
            color: var(--ink);
            background: var(--accent-50);
        }

        .btn-ghost-danger:hover {
            border-color: #fecaca;
            color: var(--danger);
            background: var(--danger-bg);
        }

        /* ─── Wallet Card ─── */
        .wallet {
            color: white;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-600) 100%);
            border-color: transparent;
            min-height: 220px;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .wallet__deco {
            position: absolute;
            inset: 0;
            opacity: 0.15;
            pointer-events: none;
        }

        .wallet__top {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .wallet__brand {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(255,255,255,.18);
            color: white;
        }

        .pill__dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--ok);
        }

        .wallet__label {
            position: relative;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: rgba(255,255,255,.7);
            margin-bottom: 4px;
        }

        .wallet__amount {
            position: relative;
            display: flex;
            align-items: baseline;
            gap: 4px;
            margin-bottom: 10px;
        }

        .wallet__currency {
            font-size: 18px;
            font-weight: 500;
            opacity: .85;
        }

        .wallet__num {
            font-size: 34px;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .wallet__per {
            font-size: 13px;
            opacity: .7;
            margin-left: 4px;
        }

        .wallet__progress {
            position: relative;
            height: 5px;
            border-radius: 99px;
            background: rgba(255,255,255,.2);
            overflow: hidden;
            margin-bottom: 16px;
        }

        .wallet__progress-bar {
            height: 100%;
            background: white;
            border-radius: 99px;
            width: 48%;
        }

        .wallet__foot {
            position: relative;
            padding-top: 16px;
            display: flex;
            align-items: flex-end;
            gap: 18px;
            margin-top: auto;
            font-size: 12px;
        }

        .wallet__foot-col {
            display: flex;
            flex-direction: column;
        }

        .wallet__foot-col small {
            color: rgba(255,255,255,.65);
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 2px;
        }

        /* ─── Quick Actions ─── */
        .qa__grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .qa__item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-radius: 12px;
            background: var(--line-2);
            border: 1px solid transparent;
            cursor: pointer;
            text-align: left;
            transition: background .15s, border-color .15s, transform .15s;
            font-family: inherit;
            border: 0;
        }

        .qa__item:hover {
            background: var(--surface);
            border-color: var(--accent-200);
            transform: translateY(-1px);
        }

        .qa__ic {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: white;
            font-size: 20px;
        }

        .qa__item--accent .qa__ic { background: var(--accent-100); color: var(--accent-600); }
        .qa__item--teal .qa__ic { background: #ccfbf1; color: #0d9488; }
        .qa__item--violet .qa__ic { background: #ede9fe; color: #7c3aed; }
        .qa__item--slate .qa__ic { background: #e2e8f0; color: #475569; }

        .qa__txt {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }

        .qa__txt strong {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .qa__txt small {
            font-size: 11.5px;
            color: var(--ink-3);
        }

        /* ─── Notifications ─── */
        .notif__list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .notif__item {
            position: relative;
            display: flex;
            gap: 10px;
            padding: 10px 10px 10px 14px;
            border-radius: 10px;
            background: var(--line-2);
        }

        .notif__dot {
            position: absolute;
            left: 6px;
            top: 14px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .notif__item--accent .notif__dot { background: var(--accent); }
        .notif__item--ok .notif__dot { background: var(--ok); }
        .notif__item--warn .notif__dot { background: var(--warn); }

        .notif__body {
            flex: 1;
        }

        .notif__row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 2px;
        }

        .notif__row strong {
            font-size: 12.5px;
            font-weight: 600;
        }

        .notif__row time {
            font-size: 11px;
            color: var(--ink-4);
            white-space: nowrap;
        }

        .notif__body p {
            font-size: 11.5px;
            color: var(--ink-3);
            margin: 0;
        }

        /* ─── Campaigns ─── */
        .camp__list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .camp__row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 10px;
            cursor: pointer;
            color: var(--ink-2);
            transition: background .15s;
            text-decoration: none;
        }

        .camp__row:hover {
            background: var(--line-2);
        }

        .camp__ic {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
        }

        .camp__row--accent .camp__ic { background: var(--accent-100); color: var(--accent-600); }
        .camp__row--teal .camp__ic { background: #ccfbf1; color: #0d9488; }
        .camp__row--violet .camp__ic { background: #ede9fe; color: #7c3aed; }

        .camp__txt {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .camp__txt strong {
            font-size: 13px;
            font-weight: 500;
        }

        .camp__txt small {
            font-size: 11px;
            color: var(--ink-3);
            margin-top: 1px;
        }

        /* ─── Stats ─── */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            align-items: center;
        }

        .stat {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding-right: 20px;
            border-right: 1px solid var(--line);
        }

        .stat:last-child {
            border-right: 0;
        }

        .stat small {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--ink-3);
            font-weight: 600;
        }

        .stat strong {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        .stat span {
            font-size: 11.5px;
            color: var(--ink-4);
        }

        @media (max-width: 640px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 14px;
            }
            .stat {
                padding-right: 0;
                border-right: 0;
            }
        }

        /* ─── Wellness ─── */
        .wellness {
            display: flex;
            gap: 14px;
            align-items: center;
            background: linear-gradient(90deg, var(--accent-50) 0%, var(--surface) 60%);
            border-color: var(--accent-100);
        }

        .wellness__ic {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: var(--accent);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 18px;
        }

        .wellness__body {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .wellness__body small {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--accent-600);
            font-weight: 600;
        }

        .wellness__body strong {
            font-size: 13.5px;
            font-weight: 500;
            line-height: 1.45;
        }

        /* ─── Mobile Bottom Nav ─── */
        .mnav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            background: var(--surface);
            border-top: 1px solid var(--line);
            padding: 8px 8px calc(8px + env(safe-area-inset-bottom, 0));
            z-index: 40;
        }

        .mnav a {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            padding: 6px;
            color: var(--ink-3);
            font-size: 10.5px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 10px;
            text-decoration: none;
            transition: color .15s;
        }

        .mnav a:hover {
            color: var(--accent);
        }

        .mnav__fab {
            background: var(--accent);
            color: white !important;
            width: 52px;
            height: 52px;
            border-radius: 50%;
            align-self: center;
            justify-self: center;
            justify-content: center;
            align-items: center;
            box-shadow: 0 6px 16px -4px rgba(37,99,235,.5);
            transform: translateY(-12px);
        }

        @media (max-width: 640px) {
            .mnav {
                display: grid;
            }
        }

        @media (min-width: 641px) {
            .mnav {
                display: none;
            }
        }

        /* ─── Modals ─── */
        .modal {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .modal.active {
            display: flex;
        }

        .modal__overlay {
            position: absolute;
            inset: 0;
            background: rgba(15,23,42,.4);
            backdrop-filter: blur(4px);
        }

        .modal__content {
            position: relative;
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: 0 30px 60px rgba(0,0,0,.15);
            max-width: 400px;
            width: 100%;
            padding: 20px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal__head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }

        .modal__head h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .modal__close {
            background: none;
            border: 0;
            font-size: 24px;
            cursor: pointer;
            color: var(--ink-3);
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        @media (max-width: 640px) {
            .modal__content {
                max-width: none;
            }
        }

        /* ─── QR Modal ─── */
        #qrcode {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="hub">
        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar__brand">
                <i class="fas fa-shield-heart" style="font-size: 24px; color: var(--accent);"></i>
                <span>RSU Medical</span>
            </div>
            <nav class="topbar__nav">
                <a href="#" class="is-active">หน้าหลัก</a>
                <a href="#">บริการ</a>
                <a href="#">นัดหมาย</a>
                <a href="#">ช่วยเหลือ</a>
            </nav>
            <div class="topbar__search">
                <i class="fas fa-search" style="color: var(--ink-3);"></i>
                <input type="text" placeholder="ค้นหาบริการ ประวัติ แคมเปญ…">
            </div>
            <div style="flex: 1;"></div>
            <div class="topbar__tools">
                <button class="btn-icon">
                    <i class="fas fa-bell" style="font-size: 18px;"></i>
                    <?php if ($upcoming_count > 0): ?>
                        <span class="topbar__ping"></span>
                    <?php endif; ?>
                </button>
                <button class="avatar" title="Account">
                    <?= htmlspecialchars($userInitials) ?>
                </button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="hub__main">
            <!-- Greeting -->
            <div class="hub__greet">
                <h1><?= htmlspecialchars($greeting) ?> <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?></h1>
                <p><?= htmlspecialchars($thaiDate) ?> · <?= $upcoming_count ?> นัดหมาย</p>
            </div>

            <!-- Bento Grid -->
            <div class="bento-grid">
                <!-- Profile Card -->
                <div class="g-profile">
                    <div class="bento profile">
                        <svg class="profile__bg" viewBox="0 0 400 220" preserveAspectRatio="xMidYMid slice">
                            <defs>
                                <radialGradient id="pb1" cx="15%" cy="10%" r="60%">
                                    <stop offset="0" stopColor="var(--accent)" stopOpacity=".28"/>
                                    <stop offset="1" stopColor="var(--accent)" stopOpacity="0"/>
                                </radialGradient>
                            </defs>
                            <rect width="400" height="220" fill="url(#pb1)"/>
                            <g stroke="var(--accent)" strokeOpacity=".08" fill="none">
                                <circle cx="360" cy="40" r="80"/>
                                <circle cx="360" cy="40" r="120"/>
                            </g>
                        </svg>
                        <div class="profile__row">
                            <div class="profile__avatar">
                                <?= htmlspecialchars($userInitials) ?>
                                <span class="profile__tick">✓</span>
                            </div>
                            <div class="profile__meta">
                                <div class="profile__hello">สวัสดี 👋</div>
                                <div class="profile__name"><?= htmlspecialchars($user['full_name']) ?></div>
                                <div class="profile__id"><?= htmlspecialchars($user['student_personnel_id'] ?? 'N/A') ?> · Student</div>
                            </div>
                            <button class="profile__qr" title="QR Code" onclick="openModal('qr-modal')">
                                <i class="fas fa-qrcode" style="font-size: 18px; color: var(--ink-3);"></i>
                            </button>
                        </div>
                        <div class="profile__verified">
                            <span class="profile__shield"><i class="fas fa-shield-check" style="font-size: 11px;"></i></span>
                            <span>Identity Verified</span>
                            <span style="width: 3px; height: 3px; border-radius: 50%; background: currentColor; opacity: 0.5;"></span>
                            <span style="font-size: 11px; color: var(--ink-3); font-weight: 400;">Thai National ID</span>
                        </div>
                    </div>
                </div>

                <!-- Insurance Wallet -->
                <div class="g-wallet">
                    <div class="bento wallet">
                        <div class="wallet__deco">
                            <svg viewBox="0 0 300 180" preserveAspectRatio="xMidYMid slice">
                                <circle cx="260" cy="30" r="80" fill="white" opacity=".25"/>
                                <circle cx="280" cy="140" r="40" fill="white" opacity=".08"/>
                            </svg>
                        </div>
                        <div class="wallet__top">
                            <div class="wallet__brand">
                                <i class="fas fa-heart-pulse"></i>
                                <span>RSU Accident Care</span>
                            </div>
                            <div class="pill">
                                <span class="pill__dot"></span>
                                ใช้งานได้
                            </div>
                        </div>
                        <div class="wallet__label">ยอดคงเหลือ</div>
                        <div class="wallet__amount">
                            <span class="wallet__currency">฿</span>
                            <span class="wallet__num">48,250</span>
                            <span class="wallet__per">/ 100,000</span>
                        </div>
                        <div class="wallet__progress">
                            <div class="wallet__progress-bar"></div>
                        </div>
                        <div class="wallet__foot">
                            <div class="wallet__foot-col">
                                <small>Policy</small>
                                <span>RSU-24-U-0815</span>
                            </div>
                            <div class="wallet__foot-col">
                                <small>Coverage</small>
                                <span>31 ส.ค. 2569</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Appointment Card -->
                <div class="g-appt">
                    <div class="bento appt">
                        <div class="card-head">
                            <h3>นัดหมายถัดไป</h3>
                        </div>
                        <?php if ($next_appt): ?>
                            <div class="appt__body">
                                <div class="appt__date">
                                    <div class="appt__mo"><?= strtoupper(date('M', strtotime($next_appt['slot_date']))) ?></div>
                                    <div class="appt__d"><?= date('d', strtotime($next_appt['slot_date'])) ?></div>
                                    <div class="appt__dow"><?= ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'][date('w', strtotime($next_appt['slot_date']))] ?></div>
                                </div>
                                <div class="appt__info">
                                    <div class="appt__service"><?= htmlspecialchars($next_appt['camp_name']) ?></div>
                                    <div class="appt__doc"><?= htmlspecialchars($next_appt['slot_date']) ?> · <?= htmlspecialchars(substr($next_appt['start_time'], 0, 5)) ?></div>
                                    <div>
                                        <span class="chip chip--accent"><i class="fas fa-clock"></i> <?= htmlspecialchars(substr($next_appt['start_time'], 0, 5)) ?> – <?= htmlspecialchars(substr($next_appt['end_time'], 0, 5)) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="appt__actions">
                                <a href="cancel_booking.php?id=<?= htmlspecialchars($next_appt['id']) ?>" class="btn-ghost btn-ghost-danger"><i class="fas fa-x-mark"></i> ยกเลิก</a>
                                <a href="my_bookings.php" class="btn-ghost"><i class="fas fa-calendar-plus"></i> ดูทั้งหมด</a>
                            </div>
                        <?php else: ?>
                            <div class="appt__empty">
                                <div class="appt__empty-art">📅</div>
                                <div class="appt__empty-copy">
                                    <strong>ยังไม่มีนัดหมาย</strong>
                                    <span>จองคิวคลินิกหรือฉีดวัคซีนได้ในไม่กี่คลิก</span>
                                    <a href="booking_campaign.php" class="btn-primary"><i class="fas fa-plus"></i> จองเลย</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="g-qa">
                    <div class="bento">
                        <div class="card-head">
                            <h3>ทางลัด</h3>
                        </div>
                        <div class="qa__grid">
                            <a href="booking_campaign.php" class="qa__item qa__item--accent">
                                <span class="qa__ic">💉</span>
                                <div class="qa__txt">
                                    <strong>จองแคมเปญ</strong>
                                    <small>วัคซีนและคลินิก</small>
                                </div>
                            </a>
                            <a href="my_bookings.php" class="qa__item qa__item--violet">
                                <span class="qa__ic">📋</span>
                                <div class="qa__txt">
                                    <strong>ประวัติ</strong>
                                    <small><?= count($booking_list) ?> ครั้ง</small>
                                </div>
                            </a>
                            <a href="my_bookings.php" class="qa__item qa__item--teal">
                                <span class="qa__ic">📊</span>
                                <div class="qa__txt">
                                    <strong>เวชระเบียน</strong>
                                    <small>ผลแล็บ</small>
                                </div>
                            </a>
                            <a href="profile.php" class="qa__item qa__item--slate">
                                <span class="qa__ic">⚙️</span>
                                <div class="qa__txt">
                                    <strong>ตั้งค่า</strong>
                                    <small>โปรไฟล์</small>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Notifications -->
                <div class="g-notif">
                    <div class="bento">
                        <div class="card-head">
                            <h3>การแจ้งเตือน</h3>
                            <a href="#">ดูทั้งหมด</a>
                        </div>
                        <ul class="notif__list">
                            <li class="notif__item notif__item--accent">
                                <span class="notif__dot"></span>
                                <div class="notif__body">
                                    <div class="notif__row">
                                        <strong><?php if ($upcoming_count > 0): ?>เตือนนัดหมาย<?php else: ?>ไม่มีนัดหมายใหม่<?php endif; ?></strong>
                                        <time>เมื่อสักครู่</time>
                                    </div>
                                    <p><?php if ($upcoming_count > 0): ?>คุณมีนัดหมาย <?= $upcoming_count ?> รายการรอการรักษา<?php else: ?>ยังไม่มีนัดหมายที่รอดำเนินการ<?php endif; ?></p>
                                </div>
                            </li>
                            <li class="notif__item notif__item--ok">
                                <span class="notif__dot"></span>
                                <div class="notif__body">
                                    <div class="notif__row">
                                        <strong>รายการยืมอุปกรณ์</strong>
                                        <time>เมื่อสักครู่</time>
                                    </div>
                                    <p>คุณมีรายการยืม <?= $borrow_count ?> รายการ</p>
                                </div>
                            </li>
                            <li class="notif__item notif__item--warn">
                                <span class="notif__dot"></span>
                                <div class="notif__body">
                                    <div class="notif__row">
                                        <strong>คำแนะนำสุขภาพ</strong>
                                        <time>เมื่อวาน</time>
                                    </div>
                                    <p>ตรวจสุขภาพประจำปี ครบกำหนด เดือน ส.ค.</p>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Stats Strip -->
                <div class="g-stats">
                    <div class="bento">
                        <div class="stats">
                            <div class="stat">
                                <small>นัดหมายถัดไป</small>
                                <strong><?php if ($next_appt): ?><?= htmlspecialchars(substr($next_appt['slot_date'], 5, 2)) ?>/<?= htmlspecialchars(substr($next_appt['slot_date'], 8, 2)) ?><?php else: ?>—<?php endif; ?></strong>
                                <span><?php if ($next_appt): ?><?= htmlspecialchars($next_appt['camp_name']) ?><?php else: ?>ยังไม่มี<?php endif; ?></span>
                            </div>
                            <div class="stat">
                                <small>เข้าพบแล้ว</small>
                                <strong><?= count($booking_list) ?></strong>
                                <span>ครั้ง</span>
                            </div>
                            <div class="stat">
                                <small>ยืมอุปกรณ์</small>
                                <strong><?= $borrow_count ?></strong>
                                <span>รายการ</span>
                            </div>
                            <div class="stat">
                                <small>ยอดประกัน</small>
                                <strong>฿48,250</strong>
                                <span>48% ของวงเงิน</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Campaigns -->
                <div class="g-camp">
                    <div class="bento">
                        <div class="card-head">
                            <h3>แคมเปญเปิดรับ</h3>
                            <a href="booking_campaign.php">ดูทั้งหมด</a>
                        </div>
                        <?php if (empty($camp_list)): ?>
                            <p style="text-align: center; color: var(--ink-3); padding: 20px 0;">ยังไม่มีแคมเปญในขณะนี้</p>
                        <?php else: ?>
                            <ul class="camp__list">
                                <?php foreach (array_slice($camp_list, 0, 3) as $c):
                                    $tone = match($c['type']) {
                                        'vaccine' => 'accent',
                                        'health_check' => 'teal',
                                        'training' => 'violet',
                                        default => 'accent'
                                    };
                                    $icon = campIcon($c['type']);
                                ?>
                                    <a href="booking_date.php?campaign_id=<?= htmlspecialchars($c['id']) ?>" class="camp__row camp__row--<?= $tone ?>">
                                        <span class="camp__ic"><?= $icon ?></span>
                                        <div class="camp__txt">
                                            <strong><?= htmlspecialchars($c['title']) ?></strong>
                                            <small><?= htmlspecialchars($c['available_until'] ? 'ถึง ' . date('j M', strtotime($c['available_until'])) : 'ไม่มีกำหนด') ?></small>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Wellness Banner -->
                <div class="g-well">
                    <div class="bento wellness">
                        <div class="wellness__ic">✨</div>
                        <div class="wellness__body">
                            <small>คำแนะนำสุขภาพ</small>
                            <strong>ตรวจสุขภาพประจำปี ครบกำหนดเดือน ส.ค. จองเช้าได้คิวไว</strong>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Mobile Bottom Nav -->
    <nav class="mnav">
        <a href="#" title="หน้าหลัก"><i class="fas fa-house-chimney" style="font-size: 20px;"></i> หน้าหลัก</a>
        <a href="my_bookings.php" title="นัดหมาย"><i class="fas fa-calendar-day" style="font-size: 20px;"></i> นัดหมาย</a>
        <a href="booking_campaign.php" class="mnav__fab" title="จอง"><i class="fas fa-plus" style="font-size: 24px;"></i></a>
        <a href="#" title="สุขภาพ"><i class="fas fa-heart-pulse" style="font-size: 20px;"></i> สุขภาพ</a>
        <a href="profile.php" title="บัญชี"><i class="fas fa-user-circle" style="font-size: 20px;"></i> บัญชี</a>
    </nav>

    <!-- QR Modal -->
    <div class="modal" id="qr-modal">
        <div class="modal__overlay" onclick="closeModal('qr-modal')"></div>
        <div class="modal__content">
            <div class="modal__head">
                <h3>QR Code</h3>
                <button class="modal__close" onclick="closeModal('qr-modal')">✕</button>
            </div>
            <div style="text-align: center; padding: 20px 0;">
                <div id="qrcode"></div>
                <p style="margin-top: 16px; font-size: 12px; color: var(--ink-3);">
                    ID: <?= htmlspecialchars($user['student_personnel_id'] ?? 'N/A') ?>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        let qrGenerated = false;

        function openModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.add('active');
                if (id === 'qr-modal' && !qrGenerated) {
                    const qrcodeDiv = document.getElementById('qrcode');
                    qrcodeDiv.innerHTML = '';
                    new QRCode(qrcodeDiv, {
                        text: "<?= htmlspecialchars($user['student_personnel_id'] ?? '') ?>",
                        width: 180,
                        height: 180,
                        colorDark: "#0f172a",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.H
                    });
                    qrGenerated = true;
                }
            }
        }

        function closeModal(id) {
            const modal = document.getElementById(id);
            if (modal) {
                modal.classList.remove('active');
            }
        }

        // Close modal on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal.querySelector('.modal__overlay')) {
                    closeModal(modal.id);
                }
            });
        });

        // Keyboard shortcut for search
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                const search = document.querySelector('.topbar__search input');
                if (search) search.focus();
            }
        });
    </script>
</body>
</html>

<?php
function campIcon($type) {
    return match($type) {
        'vaccine' => '💉',
        'health_check' => '🩺',
        'training' => '📋',
        default => '📅'
    };
}
?>
