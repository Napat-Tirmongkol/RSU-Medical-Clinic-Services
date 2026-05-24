<?php
// admin/includes/header.php
$layout_none = (isset($_GET['layout']) && $_GET['layout'] === 'none') || isset($_GET['embed']);

if (!function_exists('renderPageHeader')) {
    function renderPageHeader($title, $subtitle, $actions_html = '') {
        global $layout_none;
        echo '
        <div class="page-header au d1">
            <div class="page-header-text">
                <h1 class="page-header-title">
                    <span class="page-header-bar"></span>
                    ' . $title . '
                </h1>
                <p class="page-header-subtitle">' . $subtitle . '</p>
            </div>
            <div class="page-header-actions">
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
    <title>e-Campaign — Admin</title>
    <link rel="icon" href="../favicon.ico">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/rsufont.css">
    <style>
        * { font-family: 'Sarabun', sans-serif; }

        /* ── Admin shell — brand tokens + dark-mode ready ─────────── */
        :root {
            --ec-brand-50:  #ecfdf5;
            --ec-brand-100: #d1fae5;
            --ec-brand-200: #a7f3d0;
            --ec-brand-400: #34d399;
            --ec-brand-500: #2e9e63;
            --ec-brand-600: #1f7a4d;
            --ec-brand-700: #155e3d;
            --ec-bg:        #e8f4ec;
            --ec-surface:   #ffffff;
            --ec-surface-2: #f8fafc;
            --ec-border:    #e2e8f0;
            --ec-border-soft: #f1f5f9;
            --ec-ink-1:     #0f172a;
            --ec-ink-2:     #334155;
            --ec-ink-3:     #64748b;
            --ec-ink-4:     #94a3b8;
            --ec-shadow-sm: 0 1px 2px rgba(15,23,42,.04);
            --ec-shadow-md: 0 4px 14px rgba(15,23,42,.07);
            --ec-shadow-lg: 0 18px 40px -10px rgba(15,23,42,.14);
            --ec-glow:      0 18px 40px -8px rgba(46,158,99,.45);

            /* Section accents — group-specific colors */
            --ec-acc-overview: #2e9e63;
            --ec-acc-campaign: #d946ef;
            --ec-acc-report:   #6366f1;
            --ec-acc-tools:    #64748b;
        }
        body[data-theme='dark'] {
            --ec-bg:        #0b1220;
            --ec-surface:   #111827;
            --ec-surface-2: #0f172a;
            --ec-border:    #1f2937;
            --ec-border-soft: #1e293b;
            --ec-ink-1:     #f1f5f9;
            --ec-ink-2:     #e2e8f0;
            --ec-ink-3:     #94a3b8;
            --ec-ink-4:     #64748b;
            --ec-shadow-sm: 0 1px 2px rgba(0,0,0,.4);
            --ec-shadow-md: 0 4px 14px rgba(0,0,0,.5);
            --ec-shadow-lg: 0 18px 40px -10px rgba(0,0,0,.6);
            --ec-glow:      0 18px 40px -8px rgba(46,158,99,.55);
        }

        body {
            display: flex;
            min-height: 100vh;
            background: var(--ec-bg);
            color: var(--ec-ink-2);
        }

        /* ── Sidebar ──────────────────────────────────────────────── */
        .admin-sidebar {
            width: 264px;
            background: var(--ec-surface);
            border-right: 1px solid var(--ec-border);
            box-shadow: 2px 0 12px rgba(46,158,99,.06);
            display: flex; flex-direction: column;
            flex-shrink: 0; z-index: 10;
            position: sticky; top: 0; height: 100vh;
            overflow-y: auto;
        }
        body[data-theme='dark'] .admin-sidebar {
            box-shadow: 2px 0 12px rgba(0,0,0,.4);
        }
        .sidebar-brand {
            padding: 18px 20px;
            border-bottom: 1px solid var(--ec-border);
            display: flex; align-items: center; gap: 12px;
        }
        .sidebar-brand-icon {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, #2e9e63, #34d399);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.05rem;
            box-shadow: 0 6px 16px rgba(46,158,99,.4);
        }
        .sidebar-brand-title {
            font-weight: 800;
            color: var(--ec-ink-1);
            font-size: 15px;
            line-height: 1.1;
        }
        .sidebar-brand-sub {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .12em;
            color: var(--ec-brand-500);
            margin-top: 3px;
            text-transform: uppercase;
        }
        body[data-theme='dark'] .sidebar-brand-sub { color: var(--ec-brand-400); }

        /* ── Sidebar nav links ───────────────────────────────────── */
        .nav-link {
            --lift: 0px;
            position: relative;
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 12px;
            font-size: 14px; font-weight: 600;
            color: var(--ec-ink-2); text-decoration: none;
            transition: background .18s, color .18s, transform .15s, box-shadow .18s;
            transform: translateY(var(--lift));
        }
        .nav-link:hover {
            --lift: -1px;
            background: var(--ec-brand-50);
            color: var(--ec-brand-700);
        }
        body[data-theme='dark'] .nav-link:hover {
            background: rgba(46,158,99,.15);
            color: var(--ec-brand-400);
        }
        .nav-link:hover .nav-icon {
            background: var(--ec-brand-100);
            transform: scale(1.06);
        }
        body[data-theme='dark'] .nav-link:hover .nav-icon {
            background: rgba(46,158,99,.25);
        }
        .nav-link.active {
            background: var(--ec-brand-50);
            color: var(--ec-brand-700);
            font-weight: 700;
        }
        body[data-theme='dark'] .nav-link.active {
            background: rgba(46,158,99,.18);
            color: var(--ec-brand-400);
        }
        .nav-link.active::before {
            content: '';
            position: absolute; left: -3px; top: 8px; bottom: 8px;
            width: 3px; border-radius: 0 4px 4px 0;
            background: var(--ec-brand-500);
        }
        .nav-link .nav-icon {
            width: 30px; height: 30px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
            background: var(--ec-surface-2);
            color: var(--ec-ink-3);
            transition: background .18s, transform .18s;
        }
        body[data-theme='dark'] .nav-link .nav-icon {
            background: rgba(255,255,255,.05);
            color: var(--ec-ink-3);
        }
        .nav-link.active .nav-icon {
            background: var(--ec-brand-100);
            color: var(--ec-brand-700);
        }
        body[data-theme='dark'] .nav-link.active .nav-icon {
            background: rgba(46,158,99,.3);
            color: var(--ec-brand-400);
        }
        .nav-link .nav-label { flex: 1; min-width: 0; }
        .nav-link .nav-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 20px; height: 18px; padding: 0 6px;
            background: #ef4444; color: #fff;
            border-radius: 999px;
            font-size: 10px; font-weight: 800;
            box-shadow: 0 2px 4px rgba(239,68,68,.35);
            flex-shrink: 0;
        }
        .nav-link.active .nav-badge {
            background: #fff; color: #ef4444;
            border: 1px solid #fecaca;
            box-shadow: none;
        }
        body[data-theme='dark'] .nav-link.active .nav-badge {
            background: #1f2937; border-color: rgba(239,68,68,.4);
        }

        /* ── Section accent — collapsible group ────────────────── */
        .nav-section {
            margin: 18px 0 6px;
        }
        .nav-section-toggle {
            display: flex; align-items: center; gap: 8px;
            width: 100%;
            padding: 6px 12px;
            background: transparent;
            border: 0;
            cursor: pointer;
            border-radius: 8px;
            transition: background .15s;
        }
        .nav-section-toggle:hover { background: var(--ec-surface-2); }
        body[data-theme='dark'] .nav-section-toggle:hover { background: rgba(255,255,255,.04); }
        .nav-section-toggle .sec-dot {
            width: 7px; height: 7px;
            border-radius: 2px;
            flex-shrink: 0;
        }
        .nav-section-toggle .sec-label {
            font-size: 10px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .12em;
            color: var(--ec-ink-4);
            flex: 1; text-align: left;
        }
        .nav-section-toggle .sec-chevron {
            font-size: 10px; color: var(--ec-ink-4);
            transition: transform .2s;
        }
        .nav-section.collapsed .sec-chevron { transform: rotate(-90deg); }
        .nav-section.collapsed .nav-group { display: none; }
        .nav-group { padding: 4px 0; display: flex; flex-direction: column; gap: 2px; }

        /* ── Back-to-portal button ─────────────────────────────── */
        .back-portal-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 10px 12px;
            border-radius: 12px;
            font-size: 12px; font-weight: 700;
            letter-spacing: .08em; text-transform: uppercase;
            background: var(--ec-brand-50);
            color: var(--ec-brand-700);
            border: 1px solid var(--ec-brand-200);
            transition: all .15s;
            text-decoration: none;
        }
        .back-portal-btn:hover {
            background: var(--ec-brand-100);
            transform: translateY(-1px);
            box-shadow: 0 6px 14px -4px rgba(46,158,99,.3);
        }
        body[data-theme='dark'] .back-portal-btn {
            background: rgba(46,158,99,.15);
            color: var(--ec-brand-400);
            border-color: rgba(46,158,99,.3);
        }

        /* ── Logout ─────────────────────────────────────────────── */
        .logout-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 10px 12px;
            border-radius: 12px;
            font-size: 13px; font-weight: 600;
            color: #ef4444;
            background: transparent;
            transition: background .15s;
            text-decoration: none;
        }
        .logout-btn:hover { background: #fef2f2; }
        body[data-theme='dark'] .logout-btn:hover { background: rgba(239,68,68,.12); }

        /* ── Top bar ────────────────────────────────────────────── */
        .admin-topbar {
            background: var(--ec-surface);
            border-bottom: 1px solid var(--ec-border);
            box-shadow: 0 2px 8px rgba(46,158,99,.05);
            padding: 12px 20px;
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 30;
        }
        body[data-theme='dark'] .admin-topbar {
            box-shadow: 0 2px 8px rgba(0,0,0,.3);
        }
        .topbar-icon-btn {
            width: 36px; height: 36px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 11px;
            background: var(--ec-brand-50);
            color: var(--ec-brand-700);
            border: 1px solid var(--ec-brand-200);
            transition: all .15s;
            position: relative;
        }
        .topbar-icon-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--ec-shadow-md);
        }
        body[data-theme='dark'] .topbar-icon-btn {
            background: rgba(46,158,99,.15);
            color: var(--ec-brand-400);
            border-color: rgba(46,158,99,.3);
        }

        /* User pill — override portal.css default */
        .user-pill {
            display: flex; align-items: center; gap: 10px;
            padding: 6px 12px 6px 6px;
            background: var(--ec-brand-50);
            border: 1px solid var(--ec-brand-200);
            border-radius: 999px;
            box-shadow: 0 2px 8px rgba(46,158,99,.08);
            transition: box-shadow .2s, border-color .2s, transform .15s;
        }
        .user-pill:hover {
            box-shadow: 0 6px 18px rgba(46,158,99,.18);
            transform: translateY(-1px);
        }
        body[data-theme='dark'] .user-pill {
            background: rgba(46,158,99,.12);
            border-color: rgba(46,158,99,.3);
            box-shadow: 0 2px 8px rgba(0,0,0,.3);
        }
        .user-avatar {
            width: 28px; height: 28px;
            border-radius: 999px;
            background: linear-gradient(135deg,#2e9e63,#34d399);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 11px;
            box-shadow: 0 4px 10px rgba(46,158,99,.3), inset 0 1px 0 rgba(255,255,255,.3);
        }
        .user-pill-text {
            line-height: 1.1;
        }
        .user-pill-role {
            font-size: 9px; font-weight: 700;
            color: var(--ec-ink-4);
            letter-spacing: .1em;
            text-transform: uppercase;
        }
        .user-pill-name {
            font-size: 12px; font-weight: 700;
            color: var(--ec-ink-1);
        }

        /* Staff badge */
        .staff-badge {
            font-size: 10px; font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            background: var(--ec-brand-50);
            color: var(--ec-brand-700);
            border: 1px solid var(--ec-brand-200);
        }
        body[data-theme='dark'] .staff-badge {
            background: rgba(46,158,99,.15);
            color: var(--ec-brand-400);
            border-color: rgba(46,158,99,.3);
        }

        /* ── Notification panel ──────────────────────────────── */
        .notif-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 12px);
            width: 340px;
            background: var(--ec-surface);
            border: 1px solid var(--ec-border);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--ec-shadow-lg);
            z-index: 50;
        }
        body[data-theme='dark'] .notif-panel {
            box-shadow: 0 25px 50px -12px rgba(0,0,0,.7);
        }
        .notif-panel-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--ec-border-soft);
            background: var(--ec-surface-2);
            display: flex; align-items: center; justify-content: space-between;
        }
        .notif-panel-title {
            font-size: 14px; font-weight: 800;
            color: var(--ec-ink-1);
        }
        .notif-total-pill {
            font-size: 10px; font-weight: 700;
            padding: 3px 9px;
            border-radius: 999px;
            background: #fee2e2; color: #b91c1c;
        }
        body[data-theme='dark'] .notif-total-pill {
            background: rgba(239,68,68,.18); color: #fecaca;
        }
        .notif-items { max-height: 420px; overflow-y: auto; }
        .notif-item {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 18px;
            border-top: 1px solid var(--ec-border-soft);
            text-decoration: none;
            color: var(--ec-ink-2);
            transition: background .15s;
        }
        .notif-item:hover { background: var(--ec-surface-2); }
        .notif-item-icon {
            width: 38px; height: 38px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: transform .2s;
        }
        .notif-item:hover .notif-item-icon { transform: scale(1.08); }
        .notif-item-title {
            font-size: 13px; font-weight: 700;
            color: var(--ec-ink-1);
            line-height: 1.2;
        }
        .notif-item-meta {
            font-size: 11px;
            color: var(--ec-ink-3);
            margin-top: 2px;
        }
        .notif-empty {
            padding: 28px 16px;
            text-align: center;
            color: var(--ec-ink-3);
            font-size: 13px;
        }

        /* ── Content area ──────────────────────────────────────── */
        .admin-content {
            flex: 1;
            background: var(--ec-bg);
            background-image:
                radial-gradient(circle at 15% 10%, rgba(46,158,99,.07) 0, transparent 380px),
                radial-gradient(circle at 85% 85%, rgba(77,201,138,.05) 0, transparent 320px);
            overflow-y: auto;
            padding: 24px;
            color: var(--ec-ink-2);
        }
        body[data-theme='dark'] .admin-content {
            background-image:
                radial-gradient(circle at 15% 10%, rgba(46,158,99,.08) 0, transparent 380px),
                radial-gradient(circle at 85% 85%, rgba(77,201,138,.05) 0, transparent 320px);
        }

        /* ── Page header (renderPageHeader output) ─────────────── */
        .page-header {
            display: flex; flex-direction: column; gap: 16px;
            margin-bottom: 24px;
        }
        @media (min-width: 768px) {
            .page-header {
                flex-direction: row;
                align-items: flex-end;
                justify-content: space-between;
                gap: 24px;
                margin-bottom: 32px;
            }
        }
        .page-header-text { position: relative; min-width: 0; }
        .page-header-title {
            display: flex; align-items: center; gap: 14px;
            font-size: clamp(20px, 3.5vw, 30px);
            font-weight: 800;
            color: var(--ec-ink-1);
            letter-spacing: -.02em;
            margin: 0;
        }
        .page-header-bar {
            display: block;
            width: 6px;
            height: 32px;
            border-radius: 999px;
            background: linear-gradient(180deg,#2e9e63,#6ee7b7);
            box-shadow: 0 4px 10px rgba(46,158,99,.3);
            flex-shrink: 0;
        }
        .page-header-subtitle {
            font-size: 13px;
            font-weight: 500;
            color: var(--ec-ink-3);
            margin: 8px 0 0 20px;
        }
        .page-header-actions {
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
            margin-left: 20px;
        }
        @media (min-width: 768px) {
            .page-header-actions { margin-left: 0; }
        }

        /* ── Animations ────────────────────────────────────────── */
        @keyframes adminSlideUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-slide-up { animation: adminSlideUp .4s cubic-bezier(.16,1,.3,1) both; }
        .animate-slide-up.delay-100 { animation-delay: .08s; }
        .animate-slide-up.delay-200 { animation-delay: .16s; }
        .animate-slide-up.delay-300 { animation-delay: .24s; }

        /* Page transition (View Transitions API) */
        @view-transition { navigation: auto; }
        ::view-transition-old(root) { animation: adminPageOut 200ms cubic-bezier(.4,0,.2,1) both; }
        ::view-transition-new(root) { animation: adminPageIn 360ms cubic-bezier(.16,1,.3,1) both; }
        @keyframes adminPageOut { to { opacity: 0; transform: translateY(-6px); } }
        @keyframes adminPageIn  { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        @supports not (view-transition-name: none) {
            body { animation: adminPageIn 380ms cubic-bezier(.16,1,.3,1) both; }
        }
        @media (prefers-reduced-motion: reduce) {
            ::view-transition-old(root), ::view-transition-new(root) { animation: none !important; }
            body, .nav-link, .animate-slide-up { animation: none !important; transition: none !important; }
        }

        /* ── Mobile responsive sidebar ─────────────────────────── */
        .admin-sidebar         { display: none; }
        @media (min-width: 768px) {
            .admin-sidebar     { display: flex; }
        }
        .sidebar-hamburger     { display: flex; }
        @media (min-width: 768px) {
            .sidebar-hamburger { display: none; }
        }
        .topbar-desktop-spacer { display: none; }
        @media (min-width: 768px) {
            .topbar-desktop-spacer { display: block; }
        }
        .sidebar-backdrop {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.4);
            z-index: 45;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .sidebar-backdrop.show { display: block; }

        @media (max-width: 767px) {
            .admin-sidebar.mobile-open {
                display: flex;
                position: fixed; top: 0; left: 0; bottom: 0;
                width: 280px;
                z-index: 50;
                box-shadow: 4px 0 24px rgba(0,0,0,.18);
                animation: slideInSidebar .25s ease both;
            }
            .admin-content { padding: 16px; }
        }
        @keyframes slideInSidebar {
            from { transform: translateX(-100%); }
            to   { transform: translateX(0); }
        }

        /* ── Hide tilt on touch + reduced-motion ──────────────── */
        @media (hover: none), (prefers-reduced-motion: reduce) {
            .nav-link, .topbar-icon-btn, .back-portal-btn { transform: none !important; }
        }
    </style>
    <?php if (defined('SENTRY_BROWSER_KEY') && SENTRY_BROWSER_KEY !== ''): ?>
    <script>window.sentryOnLoad = function() { Sentry.init({ tracesSampleRate: 0.1 }); };</script>
    <script src="https://js.sentry-cdn.com/<?= htmlspecialchars(SENTRY_BROWSER_KEY, ENT_QUOTES) ?>.min.js" crossorigin="anonymous" defer></script>
    <?php endif; ?>

    <!-- SweetAlert2 (used by admin pages) -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Suppress harmless AbortError from skipped View Transitions -->
    <script>
        window.addEventListener('unhandledrejection', function(e) {
            var r = e.reason;
            if (r && r.name === 'AbortError' && /transition/i.test(r.message || '')) {
                e.preventDefault();
            }
        });
    </script>

    <!-- Theme Sync Support -->
    <script>
        // Restore theme synchronously before paint to avoid flash
        (function(){
            try {
                if (localStorage.getItem('ecampaign_theme') === 'dark') {
                    document.documentElement.setAttribute('data-theme-preload','dark');
                }
            } catch(e){}
        })();
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'THEME_CHANGE') {
                if (e.data.theme === 'dark') {
                    document.body.setAttribute('data-theme', 'dark');
                    try { localStorage.setItem('ecampaign_theme','dark'); } catch(e){}
                } else {
                    document.body.removeAttribute('data-theme');
                    try { localStorage.setItem('ecampaign_theme','light'); } catch(e){}
                }
                syncDarkToggleIcon();
            }
        });
        function syncDarkToggleIcon() {
            var btn = document.getElementById('adminDarkToggle');
            if (!btn) return;
            var isDark = document.body.getAttribute('data-theme') === 'dark';
            btn.innerHTML = isDark
                ? '<i class="fa-solid fa-sun"></i>'
                : '<i class="fa-solid fa-moon"></i>';
        }
    </script>
</head>
<body>
<script>
    // Apply theme on body ASAP (after body element is parsed)
    (function(){
        try {
            if(localStorage.getItem('ecampaign_theme')==='dark') document.body.setAttribute('data-theme','dark');
        } catch(e){}
    })();
</script>

<?php if (!$layout_none): ?>
<!-- ── Sidebar ────────────────────────────────────────────────────────── -->
<aside class="admin-sidebar">

    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <i class="fa-solid fa-bullhorn"></i>
        </div>
        <div>
            <div class="sidebar-brand-title">e-Campaign</div>
            <div class="sidebar-brand-sub">RSU Medical Clinic</div>
        </div>
    </div>

    <nav class="flex-1 py-3 overflow-y-auto px-3">

        <a href="../portal/index.php" class="back-portal-btn">
            <i class="fa-solid fa-arrow-left-long"></i>
            กลับหน้า Portal
        </a>

        <?php
        $cur = basename($_SERVER['PHP_SELF']);

        // Badge: นับ booking ที่ยังไม่ confirmed ของวันนี้+อนาคต
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

        if (!function_exists('navLink')) {
        function navLink($href, $icon, $label, $cur, $badge = 0) {
            $file   = basename($href);
            $active = $cur === $file ? 'active' : '';
            $badgeHtml = $badge > 0
                ? '<span class="nav-badge">' . ($badge > 99 ? '99+' : (int)$badge) . '</span>'
                : '';
            echo "<a href=\"" . htmlspecialchars($href) . "\" class=\"nav-link {$active}\">
                    <span class=\"nav-icon\"><i class=\"fa-solid {$icon}\"></i></span>
                    <span class=\"nav-label\">" . htmlspecialchars($label) . "</span>
                    {$badgeHtml}
                  </a>";
        }
        }

        if (!function_exists('navSection')) {
        function navSection($key, $color, $label, $items, $cur, $startCollapsed = false) {
            // Auto-expand section if it contains the active page
            $hasActive = false;
            foreach ($items as $it) {
                if (basename($_SERVER['PHP_SELF']) === basename($it[0])) { $hasActive = true; break; }
            }
            $collapsedClass = ($startCollapsed && !$hasActive) ? 'collapsed' : '';
            echo "<div class=\"nav-section {$collapsedClass}\" data-section=\"{$key}\">
                    <button type=\"button\" class=\"nav-section-toggle\" onclick=\"toggleNavSection(this)\">
                        <span class=\"sec-dot\" style=\"background:{$color}\"></span>
                        <span class=\"sec-label\">" . htmlspecialchars($label) . "</span>
                        <i class=\"fa-solid fa-chevron-down sec-chevron\"></i>
                    </button>
                    <div class=\"nav-group\">";
            foreach ($items as $it) {
                $badge = $it[3] ?? 0;
                navLink($it[0], $it[1], $it[2], $cur, $badge);
            }
            echo "</div></div>";
        }
        }
        ?>

        <!-- Top-level shortcuts (no section grouping) -->
        <div style="margin-top:14px; display:flex; flex-direction:column; gap:2px;">
            <?php
            navLink('../admin/index.php',             'fa-chart-pie',    'Dashboard',       $cur);
            navLink('../admin/campaign_overview.php', 'fa-chart-bar',    'เจาะแคมเปญ',      $cur);
            navLink('../admin/daily_report.php',      'fa-calendar-day', 'รายงานประจำวัน',  $cur);
            ?>
        </div>

        <?php
        // Hidden from sidebar (still accessible via direct URL):
        //   kpi.php         → Dashboard already covers it
        //   line_stats.php  → removed per user request
        //   campaign_report.php → "พิมพ์ PDF" button in เจาะแคมเปญ
        //   reports.php     → Export CSV in ผู้เข้าร่วม (more powerful filter)

        navSection('campaign', 'var(--ec-acc-campaign)', 'แคมเปญ', [
            ['../admin/campaigns.php',  'fa-layer-group',     'จัดการแคมเปญ'],
            ['../admin/time_slots.php', 'fa-calendar-alt',    'รอบเวลา'],
            ['../admin/bookings.php',   'fa-clipboard-check', 'ผู้เข้าร่วม', $pendingBookings],
        ], $cur);

        navSection('tools', 'var(--ec-acc-tools)', 'เครื่องมือ', [
            ['../admin/activity_logs.php', 'fa-clipboard-list', 'บันทึกกิจกรรม'],
        ], $cur);
        ?>

    </nav>

    <div style="padding:12px; border-top:1px solid var(--ec-border);">
        <a href="../admin/auth/logout.php" class="logout-btn">
            <i class="fa-solid fa-right-from-bracket"></i>
            ออกจากระบบ
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
    if(isDark){
        document.body.removeAttribute('data-theme');
        try{localStorage.setItem('ecampaign_theme','light');}catch(e){}
    } else {
        document.body.setAttribute('data-theme','dark');
        try{localStorage.setItem('ecampaign_theme','dark');}catch(e){}
    }
    syncDarkToggleIcon();
    // Notify any embedded charts to re-render
    try{ window.dispatchEvent(new CustomEvent('ec-theme-change',{detail:{dark:!isDark}})); }catch(e){}
}
function toggleNavSection(btn){
    var sec = btn.closest('.nav-section'); if(!sec) return;
    sec.classList.toggle('collapsed');
    try {
        var key = sec.getAttribute('data-section');
        var collapsed = JSON.parse(localStorage.getItem('ec_nav_collapsed')||'[]');
        if (sec.classList.contains('collapsed')) {
            if (collapsed.indexOf(key)===-1) collapsed.push(key);
        } else {
            collapsed = collapsed.filter(function(k){return k!==key;});
        }
        localStorage.setItem('ec_nav_collapsed', JSON.stringify(collapsed));
    } catch(e){}
}
// Restore collapsed state on load
(function(){
    try {
        var collapsed = JSON.parse(localStorage.getItem('ec_nav_collapsed')||'[]');
        collapsed.forEach(function(k){
            var sec = document.querySelector('.nav-section[data-section="'+k+'"]');
            // don't collapse if it has the active nav-link
            if (sec && !sec.querySelector('.nav-link.active')) sec.classList.add('collapsed');
        });
    } catch(e){}
})();
</script>
<?php endif; ?>

<!-- ── Main ────────────────────────────────────────────────────────────── -->
<main class="flex-1 flex flex-col <?= $layout_none ? '' : 'min-h-screen overflow-hidden' ?>">

    <?php if (!$layout_none): ?>
    <!-- Top bar -->
    <div class="admin-topbar">
        <!-- Mobile: hamburger + title -->
        <div class="sidebar-hamburger items-center gap-3">
            <button onclick="toggleMobileSidebar()" class="topbar-icon-btn" aria-label="เปิดเมนู">
                <i class="fa-solid fa-bars text-sm"></i>
            </button>
            <span class="text-sm font-bold" style="color:var(--ec-ink-1)">e-Campaign</span>
        </div>
        <div class="topbar-desktop-spacer"></div>

        <!-- Right: user info -->
        <?php
        $_curDir = rtrim(dirname($_SERVER['PHP_SELF']), '/');
        $notifAjaxUrl = (substr($_curDir, -6) === '/admin')
            ? 'ajax/ajax_notifications.php'
            : '../admin/ajax/ajax_notifications.php';
        $notifErrorUrl   = '../portal/index.php?section=error_logs';
        $notifBookingUrl = (substr($_curDir, -6) === '/admin') ? 'bookings.php' : '../admin/bookings.php';
        unset($_curDir);
        ?>
        <div class="flex items-center gap-3">
            <?php if (!empty($_SESSION['is_ecampaign_staff'])): ?>
            <span class="staff-badge">
                <i class="fa-solid fa-user-tie mr-1"></i>Staff
            </span>
            <?php endif; ?>

            <!-- Dark Mode Toggle -->
            <button id="adminDarkToggle" onclick="adminToggleDark()" class="topbar-icon-btn" title="สลับโหมดมืด/สว่าง" aria-label="สลับโหมดมืด/สว่าง">
                <i class="fa-solid fa-moon"></i>
            </button>

            <!-- Notification Bell -->
            <div class="relative" id="notif-wrapper">
                <button id="notif-btn" class="topbar-icon-btn" aria-label="การแจ้งเตือน">
                    <i class="fa-solid fa-bell text-sm"></i>
                    <span id="notif-badge"
                        class="hidden absolute -top-1 -right-1 items-center justify-center text-[9px] font-black text-white bg-rose-500 rounded-full leading-none shadow-sm border border-white"
                        style="width:18px; height:18px; min-width:18px;">
                        0
                    </span>
                    <span id="notif-ping" class="hidden absolute -top-1 -right-1 bg-rose-500 rounded-full animate-ping opacity-75"
                        style="width:18px; height:18px;"></span>
                </button>
                <!-- Dropdown panel -->
                <div id="notif-panel" class="notif-panel hidden">
                    <div class="notif-panel-header">
                        <span class="notif-panel-title">การแจ้งเตือน</span>
                        <span id="notif-total-label" class="notif-total-pill hidden"></span>
                    </div>
                    <div id="notif-items" class="notif-items">
                        <div class="notif-empty">กำลังโหลด...</div>
                    </div>
                </div>
            </div>

            <div class="user-pill">
                <div class="user-avatar"><i class="fa-solid fa-user-shield text-[10px]"></i></div>
                <div class="hidden sm:block user-pill-text">
                    <div class="user-pill-role"><?= htmlspecialchars(ucfirst($_SESSION['admin_role'] ?? 'admin')) ?></div>
                    <div class="user-pill-name"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></div>
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
        var ping       = document.getElementById('notif-ping');
        var ajaxUrl    = <?= json_encode($notifAjaxUrl) ?>;
        var errorUrl   = <?= json_encode($notifErrorUrl) ?>;
        var bookingUrl = <?= json_encode($notifBookingUrl) ?>;

        function escapeHtml(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}

        function renderItems(d) {
            var html = '';
            if (d.errors_today > 0) {
                html += '<a href="' + escapeHtml(errorUrl) + '" class="notif-item">'
                      + '<div class="notif-item-icon" style="background:#fff1f2;color:#e11d48;"><i class="fa-solid fa-bug text-sm"></i></div>'
                      + '<div class="flex-1 min-w-0">'
                      + '<div class="notif-item-title">Error Logs วันนี้</div>'
                      + '<div class="notif-item-meta">' + escapeHtml(d.errors_today) + ' รายการใหม่</div></div>'
                      + '<i class="fa-solid fa-chevron-right text-[10px]" style="color:var(--ec-ink-4)"></i></a>';
            }
            if (d.pending_bookings > 0) {
                html += '<a href="' + escapeHtml(bookingUrl) + '" class="notif-item">'
                      + '<div class="notif-item-icon" style="background:#fffbeb;color:#d97706;"><i class="fa-solid fa-clock-rotate-left text-sm"></i></div>'
                      + '<div class="flex-1 min-w-0">'
                      + '<div class="notif-item-title">รอการอนุมัติ</div>'
                      + '<div class="notif-item-meta">' + escapeHtml(d.pending_bookings) + ' คิวรอพิจารณา</div></div>'
                      + '<i class="fa-solid fa-chevron-right text-[10px]" style="color:var(--ec-ink-4)"></i></a>';
            }
            if (html === '') {
                html = '<div class="notif-empty">'
                     + '<i class="fa-solid fa-circle-check text-2xl mb-2 block" style="color:var(--ec-brand-500)"></i>'
                     + 'ไม่มีการแจ้งเตือน</div>';
            }
            items.innerHTML = html;
        }

        function updateBadge(total) {
            if (total > 0) {
                badge.textContent = total > 99 ? '99+' : total;
                badge.classList.remove('hidden');
                badge.classList.add('flex');
                if (ping) ping.classList.remove('hidden');
                totalLabel.textContent = total + ' รายการ';
                totalLabel.classList.remove('hidden');
            } else {
                badge.classList.add('hidden');
                badge.classList.remove('flex');
                if (ping) ping.classList.add('hidden');
                totalLabel.classList.add('hidden');
            }
        }

        function fetchNotifications() {
            if (document.hidden) return; // Skip when tab not visible
            fetch(ajaxUrl)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.status !== 'success') return;
                    updateBadge(d.total);
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
                    items.innerHTML = '<div class="notif-empty">กำลังโหลด...</div>';
                    fetch(ajaxUrl)
                        .then(function (r) { return r.json(); })
                        .then(renderItems)
                        .catch(function () {
                            items.innerHTML = '<div class="notif-empty" style="color:#ef4444">โหลดข้อมูลไม่สำเร็จ</div>';
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

        // Initial + interval (30s) — visibility-aware
        fetchNotifications();
        setInterval(fetchNotifications, 30000);
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) fetchNotifications();
        });
    })();
    syncDarkToggleIcon();
    </script>
    <?php endif; ?>

    <!-- Content -->
    <div class="flex-1 <?= $layout_none ? '' : 'overflow-y-auto' ?> admin-content">
