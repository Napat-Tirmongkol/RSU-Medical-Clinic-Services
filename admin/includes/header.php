<?php
// admin/includes/header.php
$layout_none = isset($_GET['layout']) && $_GET['layout'] === 'none';

    // Helper Function for Unified Page Headers
    if (!function_exists('renderPageHeader')) {
        function renderPageHeader($title, $subtitle, $actions_html = '') {
            global $layout_none;
            
            // ปรับเปลี่ยน: แสดงผลหัวข้อเสมอ เพื่อความชัดเจนในการใช้งาน (แม้ในโหมด Portal)
            echo '
            <div class="mb-10 flex flex-col md:flex-row md:justify-between md:items-end gap-6 animate-slide-up">
                <div class="relative">
                    <h1 class="text-3xl md:text-4xl font-[950] text-gray-900 tracking-tight flex items-center gap-4">
                        <div class="w-2 h-10 bg-gradient-to-b from-blue-600 to-blue-400 rounded-full shadow-lg shadow-blue-200"></div>
                        ' . $title . '
                    </h1>
                    <p class="text-[11px] text-gray-400 font-black uppercase tracking-[0.25em] mt-3 ml-6 opacity-70">' . $subtitle . '</p>
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
    <title>Campaign Management - Admin</title>

    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
<link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Prompt', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex min-h-screen font-prompt <?= $layout_none ? 'flex-col' : '' ?>">

    <?php if (!$layout_none): ?>
    <aside class="w-64 bg-white shadow-lg hidden md:flex flex-col border-r border-gray-100 z-10">
        <div class="p-6 border-b border-gray-50 flex items-center gap-3">
            <div class="w-10 h-10 bg-[#0052CC] text-white rounded-xl flex items-center justify-center text-xl shadow-md">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <div>
                <h2 class="text-2xl font-bold text-gray-900">Campaign</h2>
                <p class="text-[10px] text-[#0052CC] uppercase tracking-wider font-bold">RSU Medical Clinic</p>
            </div>
        </div>
        
        <nav class="flex-1 py-4 overflow-y-auto">
            <div class="px-6 mb-4">
                <a href="../portal/index.php" class="flex items-center justify-center gap-2 w-full p-3 rounded-xl bg-gray-50 text-gray-500 hover:bg-gray-100 hover:text-gray-900 transition-all font-bold text-xs uppercase tracking-widest border border-gray-200/50">
                    <i class="fa-solid fa-arrow-left-long"></i> กลับหน้า Portal
                </a>
            </div>
            <div class="px-6 mb-2">
                <a href="../admin/index.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                    <i class="fa-solid fa-chart-pie w-5 text-center"></i> Dashboard
                </a>
            </div>

            <div class="mt-6 mb-2 px-6">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2">Campaign Management</p>
                <div class="space-y-1">
                    <a href="../admin/campaigns.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'campaigns.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                        <i class="fa-solid fa-layer-group w-5 text-center"></i> จัดการแคมเปญ
                    </a>
                    <a href="../admin/time_slots.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'time_slots.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                        <i class="fa-solid fa-calendar-alt w-5 text-center"></i> จัดการรอบเวลาแคมเปญ
                    </a>
                    <a href="../admin/bookings.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'bookings.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                        <i class="fa-solid fa-clipboard-check w-5 text-center"></i> รายชื่อผู้เข้าร่วมแคมเปญ
                    </a>
					    <a href="../admin/reports.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'bookings.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                        <i class="fa-solid fa-clipboard-check w-5 text-center"></i> รายงาน/สถิติ
                    </a>
                </div>
            </div>

            <div class="mt-6 mb-2 px-6">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2">User Management</p>
                <div class="space-y-1">
                    <a href="../portal/users.php" class="flex-1 text-center bg-gray-50 hover:bg-gray-100 py-4 rounded-2xl text-sm font-black text-gray-700 transition-all active:scale-95 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-users"></i> Users Center
                    </a>
                </div>
            </div>
            <div class="mt-6 mb-2 px-6 text-xs uppercase tracking-[0.15em] font-extrabold text-gray-400 opacity-60">System Settings</div>
            <div class="px-6 space-y-1">
                <a href="../admin/activity_logs.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'activity_logs.php' ? 'bg-blue-50 text-[#0052CC] font-bold shadow-sm' : 'text-gray-500 hover:bg-gray-50' ?> transition-all group">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 <?= basename($_SERVER['PHP_SELF']) == 'activity_logs.php' ? 'bg-blue-100' : 'bg-gray-50 group-hover:bg-blue-50' ?> transition-colors">
                        <i class="fa-solid fa-file-lines text-[13px]"></i>
                    </div>
                    <span class="text-sm">บันทึกกิจกรรมระบบ</span>
                </a>
                <a href="../admin/error_logs.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'error_logs.php' ? 'bg-red-50 text-red-600 font-bold shadow-sm' : 'text-gray-500 hover:bg-gray-50' ?> transition-all group">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 <?= basename($_SERVER['PHP_SELF']) == 'error_logs.php' ? 'bg-red-100' : 'bg-gray-50 group-hover:bg-red-50' ?> transition-colors">
                        <i class="fa-solid fa-bug text-[13px]"></i>
                    </div>
                    <span class="text-sm">Error Logs</span>
                </a>
            </div>
        </nav>

        <div class="p-4 border-t border-gray-50">
            <a href="../admin/logout.php" class="flex items-center justify-center gap-2 w-full p-3 rounded-xl text-red-500 hover:bg-red-50 transition-colors font-semibold">
                <i class="fa-solid fa-sign-out-alt"></i> ออกจากระบบ
            </a>
        </div>
    </aside>
    <?php endif; ?>

    <main class="flex-1 flex flex-col h-screen overflow-hidden <?= $layout_none ? 'h-auto overflow-visible' : '' ?>">
        <?php if (!$layout_none): ?>
        <header class="bg-white shadow-sm p-4 flex justify-between items-center border-b border-gray-100 z-10">
            <div class="flex items-center gap-3 md:hidden">
                <a href="../portal/index.php" class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-50 text-gray-400">
                    <i class="fa-solid fa-arrow-left text-sm"></i>
                </a>
                <h2 class="text-lg font-bold text-gray-700">Campaign Admin</h2>
            </div>
            <div class="hidden md:block"></div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-600">ยินดีต้อนรับ, <b><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></b></span>
                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-[#0052CC] font-bold">
                    <i class="fa-solid fa-user-shield text-sm"></i>
                </div>
            </div>
        </header>
        <?php endif; ?>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 bg-gray-50 relative">
