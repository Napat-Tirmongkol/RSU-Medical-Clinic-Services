<?php
// e_Borrow/admin/index.php
declare(strict_types=1);
include('../includes/check_session.php');
require_once __DIR__ . '/../../config.php'; // ใช้ Config กลาง

try {
    $pdo = db();
    $stmt_borrowed = $pdo->query("SELECT COUNT(*) FROM borrow_items WHERE status = 'borrowed'");
    $count_borrowed = $stmt_borrowed->fetchColumn();
    $stmt_available = $pdo->query("SELECT COUNT(*) FROM borrow_items WHERE status = 'available'");
    $count_available = $stmt_available->fetchColumn();
    $stmt_maintenance = $pdo->query("SELECT COUNT(*) FROM borrow_items WHERE status = 'maintenance'");
    $count_maintenance = $stmt_maintenance->fetchColumn();
    $stmt_overdue = $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE status = 'borrowed' AND approval_status IN ('approved', 'staff_added') AND due_date < CURDATE()");
    $count_overdue = $stmt_overdue->fetchColumn();
} catch (PDOException $e) {
    $count_borrowed = $count_available = $count_maintenance = $count_overdue = 0;
    $kpi_error = "เกิดข้อผิดพลาดในการดึงข้อมูล KPI: " . $e->getMessage();
}

// 4. ดึงข้อมูล "รายการรออนุมัติ"
$pending_requests = [];
try {
    $sql_pending = "SELECT 
                        t.id as transaction_id, t.borrow_date, t.due_date,
                        t.reason_for_borrowing, t.attachment_url,
                        t.equipment_id, t.item_id,
                        et.name as equipment_name, ei.serial_number,  
                        s.full_name as student_name, u.full_name as staff_name
                    FROM borrow_records t
                    JOIN borrow_categories et ON t.type_id = et.id 
                    LEFT JOIN borrow_items ei ON t.equipment_id = ei.id 
                    LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                    LEFT JOIN sys_staff u ON t.lending_staff_id = u.id
                    WHERE t.approval_status = 'pending'
                    ORDER BY t.borrow_date ASC";

    $stmt_pending = $pdo->prepare($sql_pending);
    $stmt_pending->execute();
    $pending_requests = $stmt_pending->fetchAll();
} catch (PDOException $e) {
    $pending_error = "เกิดข้อผิดพลาดในการดึงข้อมูลคำขอ: " . $e->getMessage();
}

// 5. ดึงข้อมูล "รายการที่เกินกำหนดคืน"
$overdue_items = [];
try {
    $sql_overdue = "SELECT 
                        t.id as transaction_id, t.equipment_id, t.due_date, t.fine_status,
                        ei.name as equipment_name, 
                        s.id as student_id, s.full_name as student_name, s.phone_number,
                        DATEDIFF(CURDATE(), t.due_date) AS days_overdue
                    FROM borrow_records t
                    JOIN borrow_items ei ON t.equipment_id = ei.id
                    LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                    WHERE t.status = 'borrowed' 
                      AND t.approval_status IN ('approved', 'staff_added') 
                      AND t.due_date < CURDATE()
                      AND t.fine_status = 'none'
                    ORDER BY t.due_date ASC";
    $stmt_overdue = $pdo->prepare($sql_overdue);
    $stmt_overdue->execute();
    $overdue_items = $stmt_overdue->fetchAll();
} catch (PDOException $e) {
    $overdue_error = "เกิดข้อผิดพลาดในการดึงข้อมูลเกินกำหนด: " . $e->getMessage();
}

// 6. ดึงข้อมูล "รายการเคลื่อนไหวล่าสุด" (5 รายการ)
$recent_activity = [];
try {
    $sql_activity = "SELECT 
                        t.approval_status, t.status, t.borrow_date, t.return_date,
                        et.name as equipment_name,
                        s.full_name as student_name
                    FROM borrow_records t
                    JOIN borrow_categories et ON t.type_id = et.id
                    LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                    ORDER BY t.id DESC
                    LIMIT 5";
    $stmt_activity = $pdo->prepare($sql_activity);
    $stmt_activity->execute();
    $recent_activity = $stmt_activity->fetchAll();
} catch (PDOException $e) {
    $activity_error = "เกิดข้อผิดพลาดในการดึงข้อมูลเคลื่อนไหว: " . $e->getMessage();
}

$page_title = "Dashboard - ภาพรวม";
$current_page = "index";
$user_role = $_SESSION['role'] ?? 'employee'; // รับค่า Role เพื่อนำไปจัด Layout
include('../includes/header.php');
?>

<script>
    // Suppress Tailwind CDN production warning (intentional use for development)
    const originalWarn = console.warn;
    console.warn = function (...args) {
        if (typeof args[0] === 'string' && args[0].includes('cdn.tailwindcss.com')) return;
        originalWarn.apply(console, args);
    };
</script>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<!-- SweetAlert2 สำหรับแจ้งเตือน (กรณีไม่ได้เรียกใน header) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    /* CSS Animations เพิ่มความแพง (นำมาจากฝั่ง Campaign) */
    @keyframes slideUpFade {
        0% {
            opacity: 0;
            transform: translateY(20px);
        }

        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-slide-up {
        animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .delay-100 {
        animation-delay: 0.1s;
    }

    .delay-200 {
        animation-delay: 0.2s;
    }

    .delay-300 {
        animation-delay: 0.3s;
    }

    /* Custom Scrollbar for list */
    .custom-scrollbar::-webkit-scrollbar {
        width: 6px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: #e2e8f0;
        border-radius: 4px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: #cbd5e1;
    }

    .admin-wrap {
        padding: 20px 24px 80px;
        max-width: 1400px;
        margin: 0 auto;
    }

    /* พื้นหลังเริ่มต้น */
    body {
        background-color: #f8fafc;
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    /* =========================================================
       DARK MODE CSS OVERRIDES
       =========================================================
       Since the class .dark-mode is toggled on body, we intercept
       all the Tailwind classes below to force a dark theme.
    ========================================================= */
    body.dark-mode {
        background-color: #0f172a !important;
        /* slate-900 */
        color: #f1f5f9 !important;
        /* slate-100 */
    }

    /* Cards, Modals, and Elements with White Background */
    body.dark-mode .bg-white {
        background-color: #1e293b !important;
        /* slate-800 */
        border-color: #334155 !important;
        /* slate-700 */
    }

    /* Text Colors */
    body.dark-mode .text-gray-900,
    body.dark-mode .text-gray-800,
    body.dark-mode .text-slate-800 {
        color: #f8fafc !important;
        /* slate-50 */
    }

    body.dark-mode .text-gray-600,
    body.dark-mode .text-gray-500,
    body.dark-mode .text-slate-600,
    body.dark-mode .text-slate-500 {
        color: #94a3b8 !important;
        /* slate-400 */
    }

    /* Borders */
    body.dark-mode .border-gray-100,
    body.dark-mode .border-gray-50,
    body.dark-mode .border-gray-200 {
        border-color: #334155 !important;
    }

    /* Hover States & Secondary Backgrounds */
    body.dark-mode .bg-slate-50\/50,
    body.dark-mode .bg-slate-50,
    body.dark-mode .hover\:bg-slate-50:hover {
        background-color: #0f172a !important;
    }

    /* Sub-component status backgrounds (Blue, Amber, Red, Emerald) */
    body.dark-mode .bg-blue-50 {
        background-color: rgba(59, 130, 246, 0.15) !important;
        border-color: rgba(59, 130, 246, 0.3) !important;
    }

    body.dark-mode .border-b-blue-50 {
        border-bottom-color: rgba(59, 130, 246, 0.3) !important;
    }

    body.dark-mode .bg-amber-50 {
        background-color: rgba(245, 158, 11, 0.15) !important;
        border-color: rgba(245, 158, 11, 0.3) !important;
    }

    body.dark-mode .hover\:bg-amber-200:hover {
        border-color: #f59e0b !important;
        background-color: rgba(245, 158, 11, 0.25) !important;
    }

    body.dark-mode .bg-red-50 {
        background-color: rgba(239, 68, 68, 0.15) !important;
        border-color: rgba(239, 68, 68, 0.3) !important;
    }

    body.dark-mode .hover\:bg-red-50\/40:hover {
        background-color: rgba(239, 68, 68, 0.15) !important;
    }

    body.dark-mode .bg-emerald-50 {
        background-color: rgba(16, 185, 129, 0.15) !important;
        border-color: rgba(16, 185, 129, 0.3) !important;
    }

    body.dark-mode .bg-green-50 {
        background-color: rgba(34, 197, 94, 0.15) !important;
        border-color: rgba(34, 197, 94, 0.3) !important;
    }

    /* SweetAlert2 Popup Dark Mode Match */
    body.dark-mode .swal2-popup {
        background-color: #1e293b !important;
        color: #f1f5f9 !important;
        border: 1px solid #334155 !important;
    }

    body.dark-mode .swal2-title {
        color: #f8fafc !important;
        border-bottom-color: #334155 !important;
    }

    body.dark-mode .swal2-html-container {
        color: #cbd5e1 !important;
    }

    body.dark-mode .swal2-html-container strong {
        color: #f8fafc !important;
    }
</style>

<div class="admin-wrap font-sans text-gray-800">

    <?php if (isset($kpi_error))
        echo "<div class='bg-red-50 text-red-500 p-3 rounded-xl mb-4 text-sm font-bold border border-red-100'><i class='fas fa-exclamation-triangle'></i> $kpi_error</div>"; ?>
    <?php if (isset($pending_error))
        echo "<div class='bg-red-50 text-red-500 p-3 rounded-xl mb-4 text-sm font-bold border border-red-100'><i class='fas fa-exclamation-triangle'></i> $pending_error</div>"; ?>
    <?php if (isset($overdue_error))
        echo "<div class='bg-red-50 text-red-500 p-3 rounded-xl mb-4 text-sm font-bold border border-red-100'><i class='fas fa-exclamation-triangle'></i> $overdue_error</div>"; ?>

    <!-- HEADER SECTION แบบใหม่ -->
    <div class="mb-8 flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4">
        <div>
            <h2 class="text-3xl font-black text-gray-900 flex items-center gap-3" style="transition: color 0.3s ease;">
                <div
                    class="w-10 h-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center text-xl shadow-sm">
                    <i class="fas fa-box"></i>
                </div>
                ภาพรวมคลังอุปกรณ์ (Dashboard)
                <div class="relative flex h-3 w-3 -ml-1 -mt-4 shadow-sm" title="ระบบกำลังอัปเดตแบบ Real-time">
                    <span
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                </div>
            </h2>
            <p class="text-gray-500 mt-2 text-sm font-medium">สถิติการยืม-คืน อุปกรณ์ทางการแพทย์ และเวชภัณฑ์ (Real-time)
            </p>
        </div>

        <div class="flex items-center gap-3 w-full sm:w-auto">
            <?php if ($user_role !== 'employee'): ?>
                <a href="admin/walkin_borrow.php"
                    class="flex-1 sm:flex-none justify-center bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:shadow-lg hover:shadow-blue-500/30 hover:-translate-y-0.5 transition-all flex items-center gap-2">
                    <i class="fas fa-qrcode"></i> ยืม/คืน (Walk-in)
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- STATS GRID แบบใหม่ (Role-Based) -->
    <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 mb-10">

            <!-- STAT 1: พร้อมใช้งาน -->
            <div
                class="group bg-gradient-to-br from-[#0B6623] to-[#1a8c35] p-6 rounded-[24px] shadow-lg shadow-green-900/20 text-white relative overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1 animate-slide-up">
                <div
                    class="absolute -right-6 -top-6 w-32 h-32 bg-white/10 rounded-full blur-2xl group-hover:bg-white/20 transition-all duration-500">
                </div>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <p class="text-green-100 text-sm font-semibold mb-1 uppercase tracking-wide">พร้อมใช้งาน</p>
                        <h3 id="kp-available" class="text-4xl font-black transition-all duration-300">
                            <?= number_format((float) $count_available) ?>
                        </h3>
                    </div>
                    <div
                        class="w-14 h-14 bg-white/20 backdrop-blur-md rounded-[18px] flex items-center justify-center text-white shadow-inner">
                        <i class="fas fa-box-open text-2xl"></i>
                    </div>
                </div>
                <div
                    class="mt-4 flex items-center gap-2 text-[11px] text-green-100/90 font-bold bg-green-900/30 px-3 py-1.5 rounded-full w-max border border-green-800/40 tracking-wider">
                    <i class="fas fa-check-circle"></i> อุปกรณ์นอนรออยู่ในคลัง
                </div>
            </div>

            <!-- STAT 2: กำลังถูกยืม -->
            <div
                class="group bg-white p-6 rounded-[24px] shadow-sm border border-gray-100 relative overflow-hidden transition-all duration-300 hover:shadow-xl hover:shadow-blue-500/10 hover:-translate-y-1 animate-slide-up delay-100">
                <div class="absolute right-0 top-0 w-2 h-full bg-gradient-to-b from-blue-500 to-indigo-600"></div>
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-gray-500 text-sm font-semibold mb-1 uppercase tracking-wide">กำลังถูกยืม</p>
                        <h3 id="kp-borrowed" class="text-4xl font-black text-gray-800 transition-all duration-300">
                            <?= number_format((float) $count_borrowed) ?>
                        </h3>
                    </div>
                    <div
                        class="w-14 h-14 bg-blue-50 text-blue-600 rounded-[18px] flex items-center justify-center shadow-inner">
                        <i class="fas fa-briefcase-medical text-2xl group-hover:scale-110 transition-transform"></i>
                    </div>
                </div>
                <div
                    class="mt-4 flex items-center gap-2 text-[11px] text-blue-600 font-bold bg-blue-50 w-max px-3 py-1.5 rounded-full border border-blue-100 tracking-wider">
                    <i class="fas fa-people-carry"></i> อยู่ระหว่างการใช้งานจริง
                </div>
            </div>

            <!-- STAT 3: ส่งซ่อมบำรุง -->
            <div
                class="group bg-white p-6 rounded-[24px] shadow-sm border border-gray-100 relative overflow-hidden transition-all duration-300 hover:shadow-xl hover:shadow-amber-500/10 hover:-translate-y-1 animate-slide-up delay-200">
                <div class="absolute right-0 top-0 w-2 h-full bg-gradient-to-b from-amber-400 to-orange-500"></div>
                <div class="flex justify-between items-start">
                    <div>
                        <p class="text-gray-500 text-sm font-semibold mb-1 uppercase tracking-wide">ส่งซ่อมบำรุง</p>
                        <h3 id="kp-maintenance" class="text-4xl font-black text-gray-800 transition-all duration-300">
                            <?= number_format((float) $count_maintenance) ?>
                        </h3>
                    </div>
                    <div
                        class="w-14 h-14 bg-amber-50 text-amber-500 rounded-[18px] flex items-center justify-center shadow-inner">
                        <i class="fas fa-tools text-2xl group-hover:rotate-12 transition-transform"></i>
                    </div>
                </div>
                <div
                    class="mt-4 flex items-center gap-2 text-[11px] text-amber-600 font-bold bg-amber-50 w-max px-3 py-1.5 rounded-full border border-amber-100 tracking-wider">
                    <i class="fas fa-exclamation-triangle"></i> ไม่พร้อมสำหรับการใช้งาน
                </div>
            </div>

            <!-- STAT 4: เกินกำหนด -->
            <div
                class="group bg-gradient-to-br from-[#dc2626] to-[#991b1b] p-6 rounded-[24px] shadow-lg shadow-red-900/20 text-white relative overflow-hidden transition-all duration-300 hover:-translate-y-1 animate-slide-up delay-300 <?= $count_overdue == 0 ? 'opacity-40 grayscale pointer-events-none' : 'hover:shadow-xl' ?>">
                <div
                    class="absolute -right-6 -top-6 w-32 h-32 bg-white/10 rounded-full blur-2xl group-hover:bg-white/20 transition-all duration-500">
                </div>
                <div class="flex justify-between items-start relative z-10">
                    <div>
                        <p class="text-red-100 text-sm font-semibold mb-1 uppercase tracking-wide">เกินกำหนดคืน</p>
                        <h3 id="kp-overdue" class="text-4xl font-black transition-all duration-300">
                            <?= number_format((float) $count_overdue) ?>
                        </h3>
                    </div>
                    <div
                        class="w-14 h-14 bg-white/20 backdrop-blur-md rounded-[18px] flex items-center justify-center text-white shadow-inner <?= $count_overdue > 0 ? 'animate-pulse' : '' ?>">
                        <i class="fas fa-history text-2xl"></i>
                    </div>
                </div>
                <div
                    class="mt-4 flex items-center gap-2 text-[11px] text-red-100 font-bold bg-red-900/40 px-3 py-1.5 rounded-full w-max border border-red-800/40 tracking-wider">
                    <i class="fa-solid fa-bullhorn"></i> ต้องติดตามด่วน (ค่าปรับไหล)
                </div>
            </div>

        </div>
    <?php else: ?>
        <!-- STAFF QUICK ACTIONS (Mobile First) -->
        <div class="grid grid-cols-2 gap-4 mb-8 animate-slide-up">
            <a href="admin/return_dashboard.php"
                class="bg-blue-600 text-white p-5 rounded-[20px] shadow-lg shadow-blue-600/30 flex flex-col items-center justify-center text-center hover:bg-blue-700 transition-colors">
                <i class="fas fa-undo-alt text-3xl mb-2 opacity-90"></i>
                <span class="font-bold text-[15px]">รับคืนอุปกรณ์</span>
            </a>
            <a href="admin/walkin_borrow.php"
                class="bg-emerald-600 text-white p-5 rounded-[20px] shadow-lg shadow-emerald-600/30 flex flex-col items-center justify-center text-center hover:bg-emerald-700 transition-colors">
                <i class="fas fa-qrcode text-3xl mb-2 opacity-90"></i>
                <span class="font-bold text-[15px]">จ่ายอุปกรณ์</span>
            </a>
        </div>
    <?php endif; ?>

    <!-- BOTTOM SECTION: LISTS & ACTIVITY -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-8 animate-slide-up delay-200">

        <!-- LEFT COLUMN -->
        <div class="xl:col-span-<?= ($user_role === 'employee') ? '3' : '2' ?> flex flex-col gap-8">

            <!-- รอดำเนินการ (รออนุมัติ) -->
            <div class="bg-white rounded-[24px] shadow-sm border border-gray-100 flex flex-col">
                <div class="p-6 border-b border-gray-50 flex justify-between items-center">
                    <div>
                        <h3 class="font-bold text-gray-900 text-lg flex items-center gap-2">
                            <i class="fas fa-bell text-amber-500"></i> รออนุมัติการยืม
                        </h3>
                        <p class="text-xs text-gray-500 mt-1">รายการคำขอยืมอุปกรณ์จากหน้าเว็บที่รอการพิจารณาอนุมัติ</p>
                    </div>
                    <span
                        class="bg-amber-100 text-amber-600 font-bold px-3 py-1 mt-1 rounded-full text-sm"><?php echo count($pending_requests); ?></span>
                </div>
                <div class="p-4 flex-1 custom-scrollbar" style="max-height: 480px; overflow-y: auto;">
                    <div class="space-y-3">
                        <?php if (empty($pending_requests)): ?>
                            <div class="text-center py-12 flex flex-col items-center">
                                <div
                                    class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center text-green-400 mb-3">
                                    <i class="fas fa-check text-4xl"></i>
                                </div>
                                <h4 class="font-bold text-gray-800 text-lg">ไม่มีรอดำเนินการ</h4>
                                <p class="text-gray-500 text-sm mt-1">คุณได้จัดการคำขอทั้งหมดเรียบร้อยแล้ว!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pending_requests as $req): ?>
                                <div
                                    class="group flex flex-col sm:flex-row justify-between sm:items-center p-4 rounded-2xl bg-white border border-gray-100 shadow-sm hover:border-amber-200 hover:shadow-md transition-all gap-4">

                                    <div class="flex items-start gap-4 flex-1 w-full min-w-0">
                                        <div
                                            class="w-12 h-12 rounded-xl bg-amber-50 text-amber-500 flex items-center justify-center flex-shrink-0 mt-1 sm:mt-0">
                                            <i class="fas fa-hourglass-half text-lg"></i>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h4 class="font-bold text-gray-900 text-[15px] truncate">
                                                <?php echo htmlspecialchars($req['equipment_name']); ?>
                                            </h4>
                                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1.5 text-[13px]">
                                                <span class="text-gray-600"><i
                                                        class="fas fa-user-circle text-gray-400 mr-1"></i> <strong
                                                        class="text-gray-800"><?php echo htmlspecialchars($req['student_name'] ?? '-'); ?></strong></span>
                                                <span class="text-gray-600"><i
                                                        class="fas fa-calendar-alt text-gray-400 mr-1"></i> กำหนดคืน: <strong
                                                        class="text-gray-800"><?php echo date('d/m/Y', strtotime($req['due_date'])); ?></strong></span>
                                            </div>

                                            <!-- Subactions -->
                                            <div class="mt-2.5 flex gap-3 text-[12px]">
                                                <a href="javascript:void(0)"
                                                    class="font-bold text-blue-600 bg-blue-50 px-2 py-1 rounded hover:bg-blue-100 transition-colors inline-flex items-center gap-1.5"
                                                    onclick="openDetailModal(this)"
                                                    data-item="<?php echo htmlspecialchars($req['equipment_name']); ?>"
                                                    data-serial="<?php echo htmlspecialchars($req['serial_number'] ?? '-'); ?>"
                                                    data-requester="<?php echo htmlspecialchars($req['student_name'] ?? '-'); ?>"
                                                    data-borrow="<?php echo date('d/m/Y', strtotime($req['borrow_date'])); ?>"
                                                    data-due="<?php echo date('d/m/Y', strtotime($req['due_date'])); ?>"
                                                    data-reason="<?php echo htmlspecialchars($req['reason_for_borrowing']); ?>"
                                                    data-attachment="<?php echo htmlspecialchars($req['attachment_url'] ?? ''); ?>">
                                                    <i class="fas fa-search-plus opacity-70"></i> ข้อมูลเพิ่มเติม
                                                </a>
                                                <?php if (!empty($req['attachment_url'])): ?>
                                                    <a href="<?php echo htmlspecialchars($req['attachment_url']); ?>"
                                                        target="_blank"
                                                        class="font-bold text-green-600 bg-green-50 px-2 py-1 rounded hover:bg-green-100 transition-colors inline-flex items-center gap-1.5">
                                                        <i class="fas fa-paperclip opacity-70"></i> แฟ้มแนบ
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- CTA Actions -->
                                    <div
                                        class="flex flex-col sm:flex-row items-center gap-2 flex-shrink-0 w-full sm:w-auto border-t sm:border-t-0 sm:border-l border-gray-100 pt-3 sm:pt-0 sm:pl-4">
                                        <button
                                            class="flex-1 sm:flex-none bg-emerald-50 text-emerald-600 hover:bg-emerald-500 hover:text-white border border-emerald-100 hover:border-emerald-500 rounded-xl px-5 py-2.5 text-[13px] font-bold transition-all flex items-center justify-center gap-1.5 group/btn min-w-[100px]"
                                            onclick="openApproveSelectionModal(<?php echo $req['transaction_id']; ?>, <?php echo $req['item_id'] ?? 0; ?>, '<?php echo htmlspecialchars($req['equipment_name'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-check group-hover/btn:scale-125 transition-transform"></i> อนุมัติ
                                        </button>
                                        <button
                                            class="flex-1 sm:flex-none bg-white border border-red-200 text-red-500 hover:bg-red-50 hover:border-red-300 rounded-xl px-5 py-2.5 text-[13px] font-bold transition-all flex items-center justify-center gap-1.5 min-w-[100px]"
                                            onclick="openRejectPopup(<?php echo $req['transaction_id']; ?>)">
                                            <i class="fas fa-times"></i> ปฏิเสธ
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- เกินกำหนดคืน -->
            <div class="bg-white rounded-[24px] shadow-sm border border-gray-100 flex flex-col">
                <div
                    class="p-6 border-b border-gray-50 flex justify-between items-center bg-red-50/30 rounded-t-[24px]">
                    <div>
                        <h3 class="font-bold text-gray-900 text-lg flex items-center gap-2">
                            <i class="fas fa-exclamation-circle text-red-500"></i> เกินกำหนดคืน
                        </h3>
                        <p class="text-xs text-red-500 mt-1">ค้างส่งคืน ทวงถามและปรับ</p>
                    </div>
                    <?php if (count($overdue_items) > 0): ?>
                        <span
                            class="bg-red-500 text-white shadow-sm font-black px-3 py-1 rounded-full text-[13px] animate-pulse"><?php echo count($overdue_items); ?>
                            รายการ</span>
                    <?php endif; ?>
                </div>
                <div class="p-4 flex-1 custom-scrollbar" style="max-height: 400px; overflow-y: auto;">
                    <div class="space-y-3">
                        <?php if (empty($overdue_items)): ?>
                            <div class="text-center py-10 flex flex-col items-center">
                                <i class="fas fa-smile-wink text-4xl text-gray-300 mb-3 block"></i>
                                <p class="text-gray-500 text-sm">ยอดเยี่ยม! ไม่มีรายการยืมค้างคืน</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($overdue_items as $item):
                                $days_overdue = max(0, (int) $item['days_overdue']);
                                $fine = $days_overdue * (defined('FINE_RATE_PER_DAY') ? FINE_RATE_PER_DAY : 0);
                                ?>
                                <div
                                    class="group flex flex-col sm:flex-row justify-between sm:items-center p-4 rounded-2xl bg-white border border-red-100 hover:border-red-300 hover:shadow-md hover:bg-red-50/40 transition-all gap-4">
                                    <div class="flex items-start gap-4 flex-1 min-w-0">
                                        <div
                                            class="w-12 h-12 rounded-xl bg-red-50 text-red-500 border border-red-100 flex items-center justify-center flex-shrink-0">
                                            <i class="fas fa-calendar-times text-lg"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <h4 class="font-bold text-gray-900 text-[15px] truncate">
                                                <?php echo htmlspecialchars($item['equipment_name']); ?>
                                            </h4>
                                            <p class="text-[13px] text-gray-600 mt-1 flex items-center gap-2"><i
                                                    class="fas fa-user-circle text-gray-400"></i> ผู้ยืม: <strong
                                                    class="text-gray-900"><?php echo htmlspecialchars($item['student_name'] ?? '-'); ?></strong>
                                            </p>
                                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-[12px]">
                                                <span class="text-gray-500"><i class="fas fa-phone-alt"></i>
                                                    <?php echo htmlspecialchars($item['phone_number'] ?? '-'); ?></span>
                                                <span class="font-black text-red-500 bg-red-50 px-2 py-0.5 rounded"><i
                                                        class="fas fa-clock"></i> เลยกำหนดมาแล้ว <?php echo $days_overdue; ?>
                                                    วัน</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="flex-shrink-0 border-t sm:border-t-0 sm:border-l border-red-100 pt-3 sm:pt-0 sm:pl-4 w-full sm:w-auto">
                                        <button
                                            class="w-full bg-red-500 hover:bg-red-600 text-white rounded-xl px-4 py-2.5 text-[13px] font-bold shadow-md shadow-red-500/20 hover:shadow-lg transition-all flex items-center gap-2 justify-center"
                                            onclick="openFineAndReturnPopup(
                                                <?php echo $item['transaction_id']; ?>, <?php echo $item['student_id'] ?? 0; ?>,
                                                '<?php echo htmlspecialchars(addslashes($item['student_name'] ?? '-')); ?>',
                                                '<?php echo htmlspecialchars(addslashes($item['equipment_name'])); ?>',
                                                <?php echo $days_overdue; ?>, <?php echo $fine; ?>, <?php echo $item['equipment_id']; ?>
                                            )">
                                            <i class="fas fa-coins text-[11px] opacity-80"></i> คืนของ/ชำระค่าปรับ
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
            <!-- RIGHT COLUMN (Span 1 on XL -> 3) -->
            <div class="flex flex-col gap-6">

                <!-- Quick Actions คล้าย e-Campaign -->
                <div class="grid grid-cols-2 lg:grid-cols-2 xl:grid-cols-1 gap-4">

                    <a href="admin/manage_fines.php"
                        class="relative overflow-hidden bg-white border border-gray-200 p-6 rounded-[24px] flex flex-col justify-between hover:border-emerald-300 hover:shadow-xl hover:shadow-emerald-500/10 hover:-translate-y-1 transition-all group">
                        <div
                            class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center mb-4 border border-emerald-100">
                            <i class="fas fa-clipboard-list text-xl"></i>
                        </div>
                        <div>
                            <span
                                class="block font-black text-gray-900 text-[17px] mb-1 group-hover:text-emerald-600 transition-colors">ประวัติธุรกรรม</span>
                            <span class="text-gray-500 text-[12px] leading-tight block">ติดตามการยืม/คืน
                                อดีตถึงปัจจุบัน</span>
                        </div>
                        <i
                            class="fas fa-arrow-right absolute bottom-6 right-6 text-xl text-emerald-500 opacity-0 -translate-x-4 transition-all duration-300 group-hover:opacity-100 group-hover:translate-x-0"></i>
                    </a>

                    <a href="admin/manage_equipment.php"
                        class="relative overflow-hidden bg-white border border-gray-200 p-6 rounded-[24px] flex flex-col justify-between hover:border-blue-300 hover:shadow-xl hover:shadow-blue-500/10 hover:-translate-y-1 transition-all group">
                        <div
                            class="w-12 h-12 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-4 border border-blue-100">
                            <i class="fas fa-box text-xl"></i>
                        </div>
                        <div>
                            <span
                                class="block font-black text-gray-900 text-[17px] mb-1 group-hover:text-blue-600 transition-colors">คลังอุปกรณ์
                                (Inventory)</span>
                            <span class="text-gray-500 text-[12px] leading-tight block">จัดการสต็อก เพิ่ม/ลบ
                                อุปกรณ์ในระบบ</span>
                        </div>
                        <i
                            class="fas fa-arrow-right absolute bottom-6 right-6 text-xl text-blue-500 opacity-0 -translate-x-4 transition-all duration-300 group-hover:opacity-100 group-hover:translate-x-0"></i>
                    </a>
                </div>

                <!-- สัดส่วน Chart -->
                <div class="bg-white rounded-[24px] shadow-sm border border-gray-100 flex flex-col mt-2">
                    <div class="p-5 border-b border-gray-50 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-blue-500"><i
                                class="fas fa-chart-pie"></i></div>
                        <h3 class="font-bold text-gray-900 text-[15px]">สัดส่วนสถานะอุปกรณ์</h3>
                    </div>
                    <div class="p-6 flex justify-center pb-8 border-b-[5px] border-b-blue-50">
                        <div style="width: 100%; max-width: 260px;">
                            <canvas id="equipmentStatusChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Activity Log -->
                <div
                    class="bg-white rounded-[24px] shadow-sm border border-gray-100 flex flex-col items-stretch overflow-hidden">
                    <div class="p-5 border-b border-gray-50 bg-slate-50/50">
                        <h3 class="font-bold text-slate-800 text-[15px] flex items-center gap-2">
                            <i class="fas fa-history text-slate-400"></i> ความเคลื่อนไหวล่าสุด
                        </h3>
                    </div>
                    <div class="p-2">
                        <?php if (empty($recent_activity)): ?>
                            <div class="p-8 text-center text-gray-400 text-sm italic">ยังไม่มีความเคลื่อนไหวในขณะนี้</div>
                        <?php else: ?>
                            <div class="flex flex-col">
                                <?php foreach ($recent_activity as $act):
                                    $icon = '<i class="fas fa-circle text-[8px] text-blue-500"></i>';
                                    $name = htmlspecialchars($act['student_name'] ?? 'N/A');
                                    $eq = htmlspecialchars($act['equipment_name']);
                                    if ($act['approval_status'] == 'pending') {
                                        $icon = '<i class="fas fa-circle text-[8px] text-amber-500"></i>';
                                        $txt = "<strong class='text-gray-900'>$name</strong> ขอยืม <u class='decoration-gray-300'>$eq</u>";
                                    } elseif ($act['approval_status'] == 'rejected') {
                                        $icon = '<i class="fas fa-circle text-[8px] text-gray-300"></i>';
                                        $txt = "ปฏิเสธคำขอของ <strong class='text-gray-900'>$name</strong> (<span class='text-gray-400'>$eq</span>)";
                                    } elseif ($act['status'] == 'returned') {
                                        $icon = '<i class="fas fa-circle text-[8px] text-emerald-500 animate-pulse"></i>';
                                        $txt = "<strong class='text-gray-900'>$name</strong> ส่งคืนแล้ว ($eq)";
                                    } elseif ($act['approval_status'] == 'approved') {
                                        $icon = '<i class="fas fa-circle text-[8px] text-blue-500"></i>';
                                        $txt = "อนุมัติให้ <strong class='text-gray-900'>$name</strong> นำออก ($eq)";
                                    } elseif ($act['approval_status'] == 'staff_added') {
                                        $icon = '<i class="fas fa-circle text-[8px] text-purple-500"></i>';
                                        $txt = "Walk-in <strong class='text-gray-900'>$name</strong> เบิก ($eq)";
                                    } else {
                                        $txt = "อัปเดตสถานะ $eq";
                                    }
                                    ?>
                                    <div
                                        class="flex items-start gap-4 p-3 hover:bg-slate-50 rounded-xl transition-colors border-b border-gray-50 last:border-0 border-solid mx-2">
                                        <div
                                            class="mt-0.5 flex-shrink-0 w-6 h-6 rounded-full bg-white border border-gray-100 shadow-sm flex items-center justify-center">
                                            <?= $icon ?>
                                        </div>
                                        <p class="text-[12px] text-slate-600 leading-relaxed font-medium"><?= $txt ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // ฟังก์ชันเปิด Modal รายละเอียด แบบ SweetAlert2 พรีเมียม (UI ตามที่โคลนมา)
    function openDetailModal(el) {
        const item = el.getAttribute('data-item');
        const serial = el.getAttribute('data-serial');
        const req = el.getAttribute('data-requester');
        const bDate = el.getAttribute('data-borrow');
        const dDate = el.getAttribute('data-due');
        const reason = el.getAttribute('data-reason');
        const attachment = el.getAttribute('data-attachment');

        Swal.fire({
            title: 'รายละเอียดคำขอ',
            html: `
            <div class="text-left font-sans text-[14px] text-gray-700 bg-white">
                <div class="grid grid-cols-3 border-b border-gray-100 pb-3 mb-3 items-center">
                    <span class="font-bold text-gray-500 col-span-1"><i class="fas fa-box-open mr-1"></i> อุปกรณ์</span>
                    <span class="col-span-2 text-gray-900 font-bold">${item}</span>
                </div>
                <div class="grid grid-cols-3 border-b border-gray-100 pb-3 mb-3 items-center">
                    <span class="font-bold text-gray-500 col-span-1"><i class="fas fa-barcode mr-1"></i> S/N</span>
                    <span class="col-span-2 font-mono text-xs bg-gray-100 px-2 py-1 rounded w-max inline-block">${serial !== '-' ? serial : '<span class="text-gray-400 italic">ไม่ได้เจาะจง S/N</span>'}</span>
                </div>
                <div class="grid grid-cols-3 border-b border-gray-100 pb-3 mb-3 items-center">
                    <span class="font-bold text-gray-500 col-span-1"><i class="fas fa-user-circle mr-1"></i> ผู้ขอ</span>
                    <span class="col-span-2 text-gray-900 font-semibold">${req}</span>
                </div>
                <div class="grid grid-cols-3 border-b border-gray-100 pb-3 mb-3 items-center">
                    <span class="font-bold text-gray-500 col-span-1"><i class="fas fa-calendar-plus mr-1"></i> ยืมเมื่อ</span>
                    <span class="col-span-2 text-gray-900">${bDate}</span>
                </div>
                <div class="grid grid-cols-3 border-b border-gray-100 pb-3 mb-3 items-center">
                    <span class="font-bold text-gray-500 col-span-1"><i class="fas fa-calendar-check mr-1"></i> กำหนดคืน</span>
                    <span class="col-span-2 text-red-500 font-black bg-red-50 px-2 py-0.5 rounded w-max">${dDate}</span>
                </div>
                <div class="mt-4 pt-1">
                    <span class="font-bold text-gray-500 block mb-2"><i class="fas fa-align-left mr-1"></i> เหตุผลการขอยืม:</span>
                    <div class="bg-gray-50 p-4 rounded-xl border border-gray-100 whitespace-pre-wrap leading-relaxed shadow-inner">${reason}</div>
                </div>
                ${attachment ? `<div class="mt-5">
                    <a href="${attachment}" target="_blank" class="flex items-center justify-center gap-2 bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white px-4 py-3 rounded-xl transition-all font-bold w-full border border-blue-100 shadow-sm"><i class="fas fa-file-download"></i> โหลดหรือดูรูปหลักฐานแนบ</a>
                </div>` : ''}
            </div>
        `,
            confirmButtonText: 'ปิดหน้าต่าง',
            confirmButtonColor: '#1e293b',
            width: '520px',
            customClass: {
                popup: 'rounded-3xl shadow-2xl font-sans !p-6 border border-gray-100',
                confirmButton: 'rounded-xl font-bold px-8 py-3 shadow-md',
                title: 'text-2xl font-black text-gray-900 !pt-2 pb-2 text-left w-full border-b border-gray-100 mb-4 inline-block',
                htmlContainer: '!m-0 !mt-0 text-left'
            }
        });
    }

    document.addEventListener("DOMContentLoaded", function () {

        // Animate Number Counting (Same logic as e-Campaign)
        function animateValue(obj, start, end, duration) {
            if (!obj) return;
            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                obj.innerHTML = Math.floor(progress * (end - start) + start).toLocaleString();
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            };
            window.requestAnimationFrame(step);
        }

        // Initialize count up animations
        animateValue(document.getElementById('kp-available'), 0, parseInt(<?= (int) $count_available ?>), 1000);
        animateValue(document.getElementById('kp-borrowed'), 0, parseInt(<?= (int) $count_borrowed ?>), 1000);
        animateValue(document.getElementById('kp-maintenance'), 0, parseInt(<?= (int) $count_maintenance ?>), 1000);

        const overdueEl = document.getElementById('kp-overdue');
        if (overdueEl) animateValue(overdueEl, 0, parseInt(<?= (int) $count_overdue ?>), 1000);

        // Initializing Chart.js
        const ctx = document.getElementById('equipmentStatusChart');
        if (ctx) {
            const isDark = document.body.classList.contains('dark-mode');
            const equipmentChart = new Chart(ctx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['พร้อมใช้', 'ถูกยืม', 'ส่งซ่อม'],
                    datasets: [{
                        data: [<?= $count_available ?>, <?= $count_borrowed ?>, <?= $count_maintenance ?>],
                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b'],
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    cutout: '72%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: isDark ? '#e2e8f0' : '#64748b', font: { size: 13, family: "'Prompt', 'Outfit', sans-serif", weight: '600' } },
                            padding: 20
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            titleFont: { family: "'Prompt', sans-serif" },
                            bodyFont: { family: "'Prompt', sans-serif", size: 14 },
                            padding: 12,
                            cornerRadius: 8
                        }
                    }
                }
            });
        }
    });
</script>

<?php include('../includes/footer.php'); ?>