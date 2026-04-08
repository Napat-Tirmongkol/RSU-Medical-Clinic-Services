<?php
// admin/includes/header.php
$layout_none = isset($_GET['layout']) && $_GET['layout'] === 'none';

if (!function_exists('renderPageHeader')) {
    function renderPageHeader($title, $subtitle, $actions_html = '') {
        global $layout_none;
        echo '
        <div class="mb-10 flex flex-col md:flex-row md:justify-between md:items-end gap-6 au d1">
            <div class="relative">
                <h1 class="text-3xl md:text-4xl font-[950] text-gray-900 tracking-tight flex items-center gap-4">
                    <div class="w-2 h-10 rounded-full shadow-lg" style="background:linear-gradient(180deg,#2e9e63,#6ee7b7);box-shadow:0 4px 10px rgba(46,158,99,.3)"></div>
                    ' . $title . '
                </h1>
                <p class="text-[11px] font-black uppercase tracking-[0.25em] mt-3 ml-6 opacity-60" style="color:#2e7d52">' . $subtitle . '</p>
            </div>
            <div class="flex flex-wrap gap-3 items-center ml-6 md:ml-0" style="position:relative;z-index:100">
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
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Prompt', sans-serif; }

        /* ── Sidebar ───────────────────────────────────────────── */
        .admin-sidebar {
            width: 256px;
            background: #fff;
            border-right: 1.5px solid #c7e8d5;
            box-shadow: 2px 0 12px rgba(46,158,99,.07);
            display: flex; flex-direction: column;
            flex-shrink: 0; z-index: 10;
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
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px; border-radius: 12px;
            font-size: .875rem; font-weight: 500;
            color: #4b5563; text-decoration: none;
            transition: background .18s, color .18s;
        }
        .nav-link:hover { background: #f0faf4; color: #1a5c38; }
        .nav-link.active {
            background: #e8f8f0;
            color: #2e9e63;
            font-weight: 700;
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
        .nav-link:hover .nav-icon  { background: #d6f0e2; }

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
            position: sticky; top: 0; z-index: 20;
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
    </style>
</head>
<body style="display:flex; min-height:100vh; background:#e2f4ea;">

<?php if (!$layout_none): ?>
<!-- ── Sidebar ──────────────────────────────────────────────────────────── -->
<aside class="admin-sidebar hidden md:flex flex-col">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <i class="fa-solid fa-bullhorn"></i>
        </div>
        <div>
            <div class="font-black text-gray-900 text-[16px] leading-none">e-Campaign</div>
            <div class="text-[10px] font-bold tracking-[.14em] uppercase mt-0.5" style="color:#2e9e63">RSU Healthcare</div>
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
        function navLink($href, $icon, $label, $cur) {
            $file = basename($href);
            $active = $cur === $file ? 'active' : '';
            echo "<a href=\"$href\" class=\"nav-link $active\">
                    <span class=\"nav-icon\"><i class=\"fa-solid $icon\"></i></span>
                    $label
                  </a>";
        }
        ?>

        <!-- Dashboard -->
        <div class="mb-1">
            <?php navLink('../admin/index.php', 'fa-chart-pie', 'Dashboard', $cur); ?>
        </div>

        <!-- Campaign Management -->
        <div class="nav-section-label">Campaign</div>
        <div class="space-y-0.5">
            <?php navLink('../admin/campaigns.php',        'fa-layer-group',      'จัดการแคมเปญ',          $cur); ?>
            <?php navLink('../admin/time_slots.php',       'fa-calendar-alt',     'จัดการรอบเวลา',         $cur); ?>
            <?php navLink('../admin/campaign_overview.php','fa-chart-bar',        'ภาพรวมแคมเปญ',          $cur); ?>
            <?php navLink('../admin/bookings.php',         'fa-clipboard-check',  'รายชื่อผู้เข้าร่วม',    $cur); ?>
            <?php navLink('../admin/reports.php',          'fa-file-chart-column','รายงาน / สถิติ',        $cur); ?>
        </div>

        <!-- User Management -->
        <div class="nav-section-label">Users</div>
        <div class="space-y-0.5">
            <?php navLink('../portal/users.php', 'fa-users', 'Users Center', $cur); ?>
        </div>

        <?php if (in_array($_SESSION['admin_role'] ?? '', ['admin', 'superadmin'], true)): ?>
        <!-- Staff Management -->
        <div class="nav-section-label">Staff</div>
        <div class="space-y-0.5">
            <?php navLink('../admin/manage_staff.php', 'fa-user-tie', 'จัดการเจ้าหน้าที่', $cur); ?>
        </div>
        <?php endif; ?>

        <!-- System -->
        <div class="nav-section-label">System</div>
        <div class="space-y-0.5">
            <?php navLink('../admin/activity_logs.php', 'fa-file-lines', 'บันทึกกิจกรรม', $cur); ?>
            <?php navLink('../admin/error_logs.php',    'fa-bug',        'Error Logs',     $cur); ?>
        </div>

    </nav>

    <!-- Logout -->
    <div class="p-3 border-t" style="border-color:#d0ead9">
        <a href="../admin/logout.php"
            class="flex items-center justify-center gap-2 w-full p-2.5 rounded-xl text-sm font-semibold text-red-500 hover:bg-red-50 transition-colors">
            <i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ
        </a>
    </div>

</aside>
<?php endif; ?>

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<main class="flex-1 flex flex-col <?= $layout_none ? '' : 'min-h-screen overflow-hidden' ?>">

    <?php if (!$layout_none): ?>
    <!-- Top bar -->
    <div class="admin-topbar">
        <!-- Mobile: back + title -->
        <div class="flex items-center gap-3 md:hidden">
            <a href="../portal/index.php"
                class="w-8 h-8 flex items-center justify-center rounded-lg"
                style="background:#f0faf4; color:#2e9e63;">
                <i class="fa-solid fa-arrow-left text-sm"></i>
            </a>
            <span class="text-sm font-bold text-gray-700">e-Campaign</span>
        </div>
        <div class="hidden md:block"></div>

        <!-- Right: user info -->
        <div class="flex items-center gap-3">
            <?php if (!empty($_SESSION['is_ecampaign_staff'])): ?>
            <span class="text-[10px] font-bold px-2.5 py-1 rounded-full" style="background:#e8f8f0;color:#2e7d52;">
                <i class="fa-solid fa-user-tie mr-1"></i>Staff
            </span>
            <?php endif; ?>
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
    <?php endif; ?>

    <!-- Content -->
    <div class="flex-1 <?= $layout_none ? '' : 'overflow-y-auto' ?> admin-content">
