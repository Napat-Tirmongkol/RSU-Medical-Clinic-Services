<?php
// admin/includes/header.php
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaign Management - Admin</title>

    <link rel="icon" href="data:,">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <script>
        if (window.console && window.console.log) {
            const originalLog = console.log;
            console.log = function() {
                if (arguments[0] && typeof arguments[0] === 'string' && arguments[0].includes('cdn.tailwindcss.com')) return;
                originalLog.apply(console, arguments);
            };
        }
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Prompt', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex min-h-screen font-prompt">

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
            <div class="px-6 mb-2">
                <a href="index.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                    <i class="fa-solid fa-chart-pie w-5 text-center"></i> Dashboard
                </a>
            </div>

            <div class="mt-6 mb-2 px-6">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2">Campaign Management</p>
                <div class="space-y-1">
                    <a href="campaigns.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'campaigns.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
    <i class="fa-solid fa-layer-group w-5 text-center"></i> จัดการแคมเปญ
</a>
                    <a href="time_slots.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'time_slots.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                        <i class="fa-solid fa-calendar-alt w-5 text-center"></i> จัดการรอบเวลาแคมเปญ
                    </a>
                    <a href="bookings.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'bookings.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                        <i class="fa-solid fa-clipboard-check w-5 text-center"></i> รายชื่อผู้เข้าร่วมแคมเปญ
                    </a>
					    <a href="reports.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'bookings.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                        <i class="fa-solid fa-clipboard-check w-5 text-center"></i> รายงาน/สถิติ
                    </a>
                </div>
            </div>

            <div class="mt-6 mb-2 px-6">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2">User Management</p>
                <div class="space-y-1">
                    <a href="users.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'users.php' ? 'bg-blue-50 text-[#0052CC] font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                        <i class="fa-solid fa-users w-5 text-center"></i> จัดการรายชื่อนักศึกษา
                    </a>
                </div>
            </div>
            <div class="mt-6 mb-2 px-6 text-xs uppercase tracking-[0.15em] font-extrabold text-gray-400 opacity-60">System Settings</div>
            <div class="px-6 space-y-1">
                <a href="manage_admins.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'manage_admins.php' ? 'bg-blue-50 text-[#0052CC] font-bold shadow-sm' : 'text-gray-500 hover:bg-gray-50' ?> transition-all group">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 <?= basename($_SERVER['PHP_SELF']) == 'manage_admins.php' ? 'bg-blue-100' : 'bg-gray-50 group-hover:bg-blue-50' ?> transition-colors">
                        <i class="fa-solid fa-user-shield text-[13px]"></i>
                    </div>
                    <span class="text-sm">จัดการผู้ดูแลระบบ</span>
                </a>
                <a href="activity_logs.php" class="flex items-center gap-3 p-3 rounded-xl <?= basename($_SERVER['PHP_SELF']) == 'activity_logs.php' ? 'bg-blue-50 text-[#0052CC] font-bold shadow-sm' : 'text-gray-500 hover:bg-gray-50' ?> transition-all group">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 <?= basename($_SERVER['PHP_SELF']) == 'activity_logs.php' ? 'bg-blue-100' : 'bg-gray-50 group-hover:bg-blue-50' ?> transition-colors">
                        <i class="fa-solid fa-file-lines text-[13px]"></i>
                    </div>
                    <span class="text-sm">บันทึกกิจกรรมระบบ</span>
                </a>
            </div>
        </nav>

        <div class="p-4 border-t border-gray-50">
            <a href="logout.php" class="flex items-center justify-center gap-2 w-full p-3 rounded-xl text-red-500 hover:bg-red-50 transition-colors font-semibold">
                <i class="fa-solid fa-sign-out-alt"></i> ออกจากระบบ
            </a>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-screen overflow-hidden">
        <header class="bg-white shadow-sm p-4 flex justify-between items-center border-b border-gray-100 z-10">
            <h2 class="text-lg font-bold text-gray-700 md:hidden flex items-center gap-2"><i class="fa-solid fa-bullhorn text-[#0052CC]"></i> camp_list</h2>
            <div class="hidden md:block"></div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-600">ยินดีต้อนรับ, <b><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></b></span>
                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-[#0052CC] font-bold">
                    <i class="fa-solid fa-user-shield text-sm"></i>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-4 md:p-6 bg-gray-50 relative">
