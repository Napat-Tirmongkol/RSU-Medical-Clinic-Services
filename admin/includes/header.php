<?php
// admin/includes/header.php
$layout_none = (isset($_GET['layout']) && $_GET['layout'] === 'none') || isset($_GET['embed']);

if (!function_exists('renderPageHeader')) {
    function renderPageHeader($title, $subtitle, $actions_html = '') {
        global $layout_none;
        echo '
        <div class="mb-6 md:mb-10 flex flex-col md:flex-row md:justify-between md:items-end gap-4 md:gap-6 au d1">
            <div class="relative">
                <h1 class="text-xl sm:text-3xl md:text-4xl font-[950] text-gray-900 tracking-tight flex items-center gap-3 sm:gap-4">
                    <div class="w-1.5 h-8 sm:w-2 sm:h-10 rounded-full shadow-lg flex-shrink-0" style="background:linear-gradient(180deg,#2e9e63,#6ee7b7);box-shadow:0 4px 10px rgba(46,158,99,.3)"></div>
                    ' . $title . '
                </h1>
                <p class="text-[10px] sm:text-[11px] font-black uppercase tracking-[0.25em] mt-2 sm:mt-3 ml-5 sm:ml-6 opacity-60" style="color:#2e7d52">' . $subtitle . '</p>
            </div>
            <div class="flex flex-wrap gap-3 items-center ml-5 sm:ml-6 md:ml-0" style="position:relative;z-index:100">
                ' . $actions_html . '
            </div>
        </div>';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaign Management — Admin</title>
    <link rel="icon" href="../favicon.ico">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <style>
        * { font-family: 'Sarabun', sans-serif; }

        /* ── Sidebar ───────────────────────────────────────────── */
        .admin-sidebar {
            width: 256px;
            background: #fff;
            border-right: 1.5px solid #c7e8d5;
            box-shadow: 2px 0 12px rgba(46,158,99,.07);
            display: flex; flex-direction: column;
            flex-shrink: 0; z-index: 10;
            /* ไม่ให้ยืดตาม content — ยึดติดกับ viewport */
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar-brand {
            padding: 20px 24px;
            border-bottom: 1.5px solid #d0ead9;
            display: flex; align-items: center; gap: 12px;
        }
        .sidebar-brand-icon {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, #2e9e63, #3bba7a);
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(46,158,99,.35);
        }

        /* ── Sidebar nav links ─────────────────────────────────── */
        .nav-link {
            position: relative;
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 12px;
            font-size: .875rem; font-weight: 500;
            color: #4b5563; text-decoration: none;
            transition: background .18s, color .18s, transform .15s;
        }
        .nav-link:hover { background: #f0faf4; color: #1a5c38; }
        .nav-link:hover .nav-icon { background: #d6f0e2; }
        .nav-link:hover .nav-label { transform: translateX(2px); }
        .nav-link.active {
            background: #e8f8f0;
            color: #2e9e63;
            font-weight: 700;
        }
        .nav-link.active::before {
            content: '';
            position: absolute; left: -3px; top: 8px; bottom: 8px;
            width: 3px; border-radius: 0 4px 4px 0;
            background: #2e9e63;
        }
        .nav-link .nav-icon {
            width: 32px; height: 32px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: .8rem; flex-shrink: 0;
            background: transparent;
            transition: background .18s;
        }
        .nav-link.active .nav-icon { background: #c7e8d5; color: #2e7d52; }
        .nav-link .nav-label {
            flex: 1; min-width: 0;
            transition: transform .15s;
        }
        .nav-link .nav-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 20px; height: 18px; padding: 0 6px;
            background: #ef4444; color: #fff;
            border-radius: 999px;
            font-size: 10px; font-weight: 800;
            box-shadow: 0 2px 4px rgba(239,68,68,.3);
            flex-shrink: 0;
        }
        .nav-link.active .nav-badge {
            background: #fff; color: #ef4444;
            border: 1.5px solid #fecaca;
            box-shadow: none;
        }

        .nav-section-label {
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .14em;
            color: #94a3b8;
            padding: 0 12px; margin: 20px 0 6px;
        }

        /* ── Top bar ───────────────────────────────────────────── */
        .admin-topbar {
            background: #fff;
            border-bottom: 1.5px solid #c7e8d5;
            box-shadow: 0 2px 8px rgba(46,158,99,.07);
            padding: 12px 20px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 110;
        }

        /* ── Content area ──────────────────────────────────────── */
        .admin-content {
            flex: 1;
            background: #e8f4ec;
            background-image:
                radial-gradient(circle at 15% 10%, rgba(46,158,99,.07) 0, transparent 380px),
                radial-gradient(circle at 85% 85%, rgba(77,201,138,.05) 0, transparent 320px);
            overflow-y: auto;
            padding: 24px;
        }

        /* ── Slide-up animation for content ────────────────────── */
        @keyframes adminSlideUp {
            from { opacity:0; transform:translateY(14px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .animate-slide-up { animation: adminSlideUp .4s cubic-bezier(.16,1,.3,1) both; }

        /* ── Mobile sidebar overlay ───────────────────────────── */

        /* Control sidebar visibility with pure CSS (not Tailwind responsive) */
        .admin-sidebar         { display: none; }  /* hidden on mobile */
        @media (min-width: 768px) {
            .admin-sidebar     { display: flex; }  /* show as sidebar on desktop */
        }

        /* Hamburger: visible on mobile only */
        .sidebar-hamburger     { display: flex; }
        @media (min-width: 768px) {
            .sidebar-hamburger { display: none; }
        }

        /* Desktop spacer in topbar */
        .topbar-desktop-spacer { display: none; }
        @media (min-width: 768px) {
            .topbar-desktop-spacer { display: block; }
        }

        /* Mobile slide-in overlay */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.4);
            z-index: 45;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .sidebar-backdrop.show { display: block; }

        @media (max-width: 767px) {
            .admin-sidebar.mobile-open {
                display: flex;
                position: fixed;
                top: 0; left: 0; bottom: 0;
                width: 280px;
                z-index: 50;
                box-shadow: 4px 0 24px rgba(0,0,0,.15);
                animation: slideInSidebar .25s ease both;
            }
            .admin-content { padding: 16px; }
        }
        @keyframes slideInSidebar {
            from { transform: translateX(-100%); }
            to   { transform: translateX(0); }
        }
    </style>
    <?php if (defined('SENTRY_BROWSER_KEY') && SENTRY_BROWSER_KEY !== ''): ?>
    <script>window.sentryOnLoad = function() { Sentry.init({ tracesSampleRate: 0.1 }); };</script>
    <script src="https://js.sentry-cdn.com/<?= htmlspecialchars(SENTRY_BROWSER_KEY, ENT_QUOTES) ?>.min.js" crossorigin="anonymous" defer></script>
    <?php endif; ?>

    <!-- Theme Sync Support -->
    <script>
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'THEME_CHANGE') {
                if (e.data.theme === 'dark') {
                    document.body.setAttribute('data-theme', 'dark');
                } else {
                    document.body.removeAttribute('data-theme');
                }
                var btn = document.getElementById('adminDarkToggle');
                if (btn) btn.innerHTML = e.data.theme === 'dark'
                    ? '<i class="fa-solid fa-sun text-amber-400"></i>'
                    : '<i class="fa-solid fa-moon"></i>';
            }
        });
    </script>
</head>
<body style="display:flex; min-height:100vh; background:#e2f4ea;">
<script>
    (function(){
        if(localStorage.getItem('ecampaign_theme')==='dark') document.body.setAttribute('data-theme','dark');
    })();
</script>

<?php if (!$layout_none): ?>
<!-- ── Sidebar ──────────────────────────────────────────────────────────── -->
<aside class="admin-sidebar flex-col">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <i class="fa-solid fa-bullhorn"></i>
        </div>
        <div>
            <div class="font-black text-gray-900 text-[16px] leading-none">e-Campaign</div>
            <div class="text-[10px] font-bold tracking-[.14em] uppercase mt-0.5" style="color:#2e9e63">RSU Medical Clinic</div>
        </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 py-3 overflow-y-auto px-3">

        <!-- Back to portal -->
        <div class="mb-3">
            <a href="../portal/index.php"
                class="flex items-center justify-center gap-2 w-full p-2.5 rounded-xl text-xs font-bold uppercase tracking-widest transition-all border"
                style="background:#f0faf4;color:#2e7d52;border-color:#c7e8d5;">
                <i class="fa-solid fa-arrow-left-long"></i> กลับหน้า Portal
            </a>
        </div>

        <?php
        $cur = basename($_SERVER['PHP_SELF']);

        // Badge: นับ booking ที่ยังไม่ confirmed (status='booked') ของวันนี้+อนาคต
        $pendingBookings = 0;
        try {
            if (function_exists('db')) {
                $pdo = db();
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM camp_bookings b
                    JOIN camp_slots s ON b.slot_id = s.id
                    WHERE b.status = 'booked' AND s.slot_date >= CURDATE()
                ");
                $stmt->execute();
                $pendingBookings = (int)$stmt->fetchColumn();
            }
        } catch (Throwable) {}

        function navLink($href, $icon, $label, $cur, $badge = 0) {
            $file   = basename($href);
            $active = $cur === $file ? 'active' : '';
            $badgeHtml = $badge > 0
                ? '<span class="nav-badge">' . ($badge > 99 ? '99+' : (int)$badge) . '</span>'
                : '';
            echo "<a href=\"$href\" class=\"nav-link $active\">
                    <span class=\"nav-icon\"><i class=\"fa-solid $icon\"></i></span>
                    <span class=\"nav-label\">$label</span>
                    $badgeHtml
                  </a>";
        }
        ?>

        <!-- Dashboard -->
        <div class="mb-1">
            <?php navLink('../admin/index.php', 'fa-chart-pie', 'Dashboard', $cur); ?>
        </div>

        <!-- ภาพรวม -->
        <div class="nav-section-label">ภาพรวม</div>
        <div class="space-y-0.5 mb-1">
            <?php navLink('../admin/kpi.php',              'fa-gauge-high',  'KPI',           $cur); ?>
            <?php navLink('../admin/campaign_overview.php','fa-chart-bar',   'ภาพรวมแคมเปญ',  $cur); ?>
            <?php navLink('../admin/line_stats.php',       'fa-comment-dots','LINE OA',        $cur); ?>
        </div>

        <!-- แคมเปญ -->
        <div class="nav-section-label">แคมเปญ</div>
        <div class="space-y-0.5 mb-1">
            <?php navLink('../admin/campaigns.php',  'fa-layer-group',     'แคมเปญ',     $cur); ?>
            <?php navLink('../admin/time_slots.php', 'fa-calendar-alt',    'รอบเวลา',    $cur); ?>
            <?php navLink('../admin/bookings.php',   'fa-clipboard-check', 'ผู้เข้าร่วม', $cur, $pendingBookings); ?>
        </div>

        <!-- รายงาน -->
        <div class="nav-section-label">รายงาน</div>
        <div class="space-y-0.5 mb-1">
            <?php navLink('../admin/reports.php',      'fa-file-lines',   'รายงานรวม',     $cur); ?>
            <?php navLink('../admin/daily_report.php', 'fa-calendar-day', 'รายงานรายวัน',  $cur); ?>
        </div>

        <!-- เครื่องมือ -->
        <div class="nav-section-label">เครื่องมือ</div>
        <div class="space-y-0.5">
            <?php navLink('../admin/activity_logs.php', 'fa-clipboard-list',  'บันทึกกิจกรรม',  $cur); ?>
        </div>

    </nav>

    <!-- Logout -->
    <div class="p-3 border-t" style="border-color:#d0ead9">
        <a href="../admin/auth/logout.php"
            class="flex items-center justify-center gap-2 w-full p-2.5 rounded-xl text-sm font-semibold text-red-500 hover:bg-red-50 transition-colors">
            <i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ
        </a>
    </div>

</aside>
<!-- Mobile backdrop -->
<div id="sidebarBackdrop" class="sidebar-backdrop" onclick="closeMobileSidebar()"></div>
<script>
function toggleMobileSidebar(){
    var sb=document.querySelector('.admin-sidebar'),bd=document.getElementById('sidebarBackdrop');
    if(!sb||!bd)return;
    if(sb.classList.contains('mobile-open')){closeMobileSidebar();}
    else{sb.classList.add('mobile-open');bd.classList.add('show');document.body.style.overflow='hidden';}
}
function closeMobileSidebar(){
    var sb=document.querySelector('.admin-sidebar'),bd=document.getElementById('sidebarBackdrop');
    if(sb)sb.classList.remove('mobile-open');
    if(bd)bd.classList.remove('show');
    document.body.style.overflow='';
}
function adminToggleDark(){
    var isDark=document.body.getAttribute('data-theme')==='dark';
    var theme=isDark?'light':'dark';
    if(theme==='dark'){
        document.body.setAttribute('data-theme','dark');
        localStorage.setItem('ecampaign_theme','dark');
    } else {
        document.body.removeAttribute('data-theme');
        localStorage.setItem('ecampaign_theme','light');
    }
    var btn=document.getElementById('adminDarkToggle');
    if(btn) btn.innerHTML=theme==='dark'
        ?'<i class="fa-solid fa-sun text-amber-400"></i>'
        :'<i class="fa-solid fa-moon"></i>';
}
</script>
<?php endif; ?>

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<main class="flex-1 flex flex-col <?= $layout_none ? '' : 'min-h-screen overflow-hidden' ?>">

    <?php if (!$layout_none): ?>
    <!-- Top bar -->
    <div class="admin-topbar">
        <!-- Mobile: hamburger + title -->
        <div class="sidebar-hamburger items-center gap-3">
            <button onclick="toggleMobileSidebar()"
                class="w-9 h-9 flex items-center justify-center rounded-lg border"
                style="background:#f0faf4; color:#2e9e63; border-color:#c7e8d5;">
                <i class="fa-solid fa-bars text-sm"></i>
            </button>
            <span class="text-sm font-bold text-gray-700">e-Campaign</span>
        </div>
        <div class="topbar-desktop-spacer"></div>

        <!-- Right: user info -->
        <?php
        // Compute relative URL to ajax_notifications.php from current page
        $_curDir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
        $notifAjaxUrl = (substr($_curDir, -6) === '/admin')
            ? 'ajax/ajax_notifications.php'
            : '../admin/ajax/ajax_notifications.php';
        $notifErrorUrl   = '../portal/index.php?section=error_logs';
        $notifBookingUrl = (substr($_curDir, -6) === '/admin') ? 'bookings.php'      : '../admin/bookings.php';
        unset($_curDir);
        ?>
        <div class="flex items-center gap-3">
            <?php if (!empty($_SESSION['is_ecampaign_staff'])): ?>
            <span class="text-[10px] font-bold px-2.5 py-1 rounded-full" style="background:#e8f8f0;color:#2e7d52;">
                <i class="fa-solid fa-user-tie mr-1"></i>Staff
            </span>
            <?php endif; ?>

            <!-- Dark Mode Toggle -->
            <button id="adminDarkToggle" onclick="adminToggleDark()" title="สลับโหมดมืด/สว่าง"
                class="w-9 h-9 flex items-center justify-center rounded-xl border transition-all hover:shadow-sm focus:outline-none dark-mode-btn"
                style="background:#f0faf4;color:#2e9e63;border-color:#c7e8d5;">
                <i class="fa-solid fa-moon"></i>
            </button>

            <!-- Notification Bell -->
            <div class="relative" id="notif-wrapper">
                <button id="notif-btn"
                    class="relative w-9 h-9 flex items-center justify-center rounded-xl border transition-all hover:shadow-sm focus:outline-none"
                    style="background:#f0faf4;color:#2e9e63;border-color:#c7e8d5;"
                    aria-label="การแจ้งเตือน">
                    <i class="fa-solid fa-bell text-sm"></i>
                    <span id="notif-badge"
                        class="hidden absolute -top-1 -right-1 w-4 h-4 items-center justify-center text-[9px] font-black text-white bg-rose-500 rounded-full leading-none shadow-sm z-10 border border-white">
                        0
                    </span>
                    <!-- Decorative pulse effect -->
                    <span id="notif-ping" class="hidden absolute -top-1 -right-1 w-4 h-4 bg-rose-500 rounded-full animate-ping opacity-75"></span>
                </button>
                <!-- Dropdown panel -->
                <div id="notif-panel"
                    class="hidden absolute right-0 top-full mt-3 bg-white border border-gray-100 rounded-3xl overflow-hidden"
                    style="z-index:200; width: 320px; min-width: 320px; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-gray-50 bg-gray-50/50" style="display: flex; align-items: center; justify-content: space-between;">
                        <span class="text-[15px] font-black text-gray-900" style="white-space: nowrap;">การแจ้งเตือน</span>
                        <span id="notif-total-label" class="hidden text-[10px] font-black px-2.5 py-1 rounded-full shrink-0" style="background:#fee2e2;color:#b91c1c; white-space: nowrap;"></span>
                    </div>
                    <div id="notif-items" class="divide-y divide-gray-50 max-h-[400px] overflow-y-auto">
                        <div class="px-4 py-8 text-sm text-gray-400 text-center">กำลังโหลด...</div>
                    </div>
                </div>
            </div>

            <div class="user-pill">
                <div class="user-avatar"><i class="fa-solid fa-user-shield text-[10px]"></i></div>
                <div class="hidden sm:block">
                    <div class="text-[9px] font-bold text-gray-400 uppercase tracking-wider leading-none mb-0.5">
                        <?= htmlspecialchars(ucfirst($_SESSION['admin_role'] ?? 'admin')) ?>
                    </div>
                    <div class="text-xs font-black text-gray-900 leading-none">
                        <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var notifOpen  = false;
        var btn        = document.getElementById('notif-btn');
        var panel      = document.getElementById('notif-panel');
        var badge      = document.getElementById('notif-badge');
        var totalLabel = document.getElementById('notif-total-label');
        var items      = document.getElementById('notif-items');
        var ajaxUrl    = <?= json_encode($notifAjaxUrl) ?>;
        var errorUrl   = <?= json_encode($notifErrorUrl) ?>;
        var bookingUrl = <?= json_encode($notifBookingUrl) ?>;

        function renderItems(d) {
            var html = '';
            if (d.errors_today > 0) {
                html += '<a href="' + errorUrl + '" class="flex items-center gap-4 px-5 py-4 hover:bg-rose-50/50 transition-all group" style="display: flex; align-items: center; text-decoration: none;">'
                      + '<div class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0 shadow-sm group-hover:scale-110 transition-transform" style="background:#fff1f2;color:#e11d48; flex-shrink: 0; display: flex; align-items: center; justify-content: center;">'
                      + '<i class="fa-solid fa-bug text-sm"></i></div>'
                      + '<div class="flex-1 min-w-0" style="flex: 1; min-width: 0; margin-left: 1rem;">'
                      + '<div class="text-[13px] font-bold text-gray-900 mb-0.5" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Error Logs วันนี้</div>'
                      + '<div class="text-[11px] font-medium text-gray-500" style="white-space: nowrap;">' + d.errors_today + ' รายการใหม่</div></div>'
                      + '<i class="fa-solid fa-chevron-right text-[10px] text-gray-300 group-hover:text-rose-400 transition-colors shrink-0" style="flex-shrink: 0; margin-left: auto;"></i></a>';
            }
            if (d.pending_bookings > 0) {
                html += '<a href="' + bookingUrl + '" class="flex items-center gap-4 px-5 py-4 hover:bg-amber-50/50 transition-all group" style="display: flex; align-items: center; text-decoration: none;">'
                      + '<div class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0 shadow-sm group-hover:scale-110 transition-transform" style="background:#fffbeb;color:#d97706; flex-shrink: 0; display: flex; align-items: center; justify-content: center;">'
                      + '<i class="fa-solid fa-clock-rotate-left text-sm"></i></div>'
                      + '<div class="flex-1 min-w-0" style="flex: 1; min-width: 0; margin-left: 1rem;">'
                      + '<div class="text-[13px] font-bold text-gray-900 mb-0.5" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">รอการอนุมัติ</div>'
                      + '<div class="text-[11px] font-medium text-gray-500" style="white-space: nowrap;">' + d.pending_bookings + ' คิวรอพิจารณา</div></div>'
                      + '<i class="fa-solid fa-chevron-right text-[10px] text-gray-300 group-hover:text-amber-400 transition-colors shrink-0" style="flex-shrink: 0; margin-left: auto;"></i></a>';
            }
            if (html === '') {
                html = '<div class="px-4 py-5 text-center">'
                     + '<i class="fa-solid fa-circle-check text-2xl text-green-400 mb-1.5 block"></i>'
                     + '<div class="text-sm text-gray-500">ไม่มีการแจ้งเตือน</div></div>';
            }
            items.innerHTML = html;
        }

        function fetchNotifications() {
            fetch(ajaxUrl)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.status !== 'success') return;
                    var total = d.total;
                    if (total > 0) {
                        badge.textContent = total > 99 ? '99+' : total;
                        badge.classList.remove('hidden');
                        badge.classList.add('flex');
                        
                        // Show ping effect
                        var ping = document.getElementById('notif-ping');
                        if (ping) ping.classList.remove('hidden');

                        totalLabel.textContent = total + ' รายการ';
                        totalLabel.classList.remove('hidden');
                    } else {
                        badge.classList.add('hidden');
                        badge.classList.remove('flex');
                        
                        // Hide ping effect
                        var ping = document.getElementById('notif-ping');
                        if (ping) ping.classList.add('hidden');

                        totalLabel.classList.add('hidden');
                    }
                    if (notifOpen) renderItems(d);
                })
                .catch(function () {});
        }

        if (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                notifOpen = !notifOpen;
                if (notifOpen) {
                    panel.classList.remove('hidden');
                    items.innerHTML = '<div class="px-4 py-4 text-sm text-gray-400 text-center">กำลังโหลด...</div>';
                    fetch(ajaxUrl)
                        .then(function (r) { return r.json(); })
                        .then(renderItems)
                        .catch(function () {
                            items.innerHTML = '<div class="px-4 py-4 text-sm text-red-400 text-center">โหลดข้อมูลไม่สำเร็จ</div>';
                        });
                } else {
                    panel.classList.add('hidden');
                }
            });
        }

        document.addEventListener('click', function (e) {
            var wrapper = document.getElementById('notif-wrapper');
            if (notifOpen && wrapper && !wrapper.contains(e.target)) {
                notifOpen = false;
                panel.classList.add('hidden');
            }
        });

        fetchNotifications();
        setInterval(fetchNotifications, 30000);
    })();
    </script>
    <script>
    // Sync dark mode toggle icon on load
    (function(){
        var btn=document.getElementById('adminDarkToggle');
        if(btn && localStorage.getItem('ecampaign_theme')==='dark')
            btn.innerHTML='<i class="fa-solid fa-sun text-amber-400"></i>';
    })();
    </script>
    <?php endif; ?>

    <!-- Content -->
    <div class="flex-1 <?= $layout_none ? '' : 'overflow-y-auto' ?> admin-content">
