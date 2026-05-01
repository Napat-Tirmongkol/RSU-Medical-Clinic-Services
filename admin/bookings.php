<?php
/**
 * admin/bookings.php (v3.0 Premium Redesign)
 * High Performance Booking Command Center
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php'; // ตรวจสอบความปลอดภัย

$pdo = db();

/** (1) MONTH/YEAR CALCULATION */
$year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('m');
if ($month < 1) {
    $month = 12;
    $year--;
}
if ($month > 12) {
    $month = 1;
    $year++;
}

$thaiMonths = [1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน', 5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม', 9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'];
$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));

/** (2) KPI + DATA — รวม round-trips ให้เหลือ 2 queries */
try {
    // KPI: conditional aggregation แทน 5 queries แยก
    $kpi = $pdo->query("
        SELECT
            SUM(status = 'booked')                               AS total_pending,
            SUM(status = 'confirmed')                            AS total_confirmed,
            SUM(status = 'completed')                            AS total_completed,
            SUM(status IN ('cancelled','cancelled_by_admin'))    AS total_cancelled
        FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
    ")->fetch(PDO::FETCH_ASSOC);
    $total_pending   = (int)($kpi['total_pending']   ?? 0);
    $total_confirmed = (int)($kpi['total_confirmed'] ?? 0);
    $total_completed = (int)($kpi['total_completed'] ?? 0);
    $total_cancelled = (int)($kpi['total_cancelled'] ?? 0);
    $total_today     = (int)$pdo->query("
        SELECT COUNT(*) FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE s.slot_date = CURDATE()
    ")->fetchColumn();
} catch (PDOException $e) {
    $total_pending = $total_confirmed = $total_completed = $total_cancelled = $total_today = 0;
}

/** (3) DATA FETCHING — 25 รายการแรก + COUNT */
$perPage = 25;
try {
    $cntStmt = $pdo->prepare("
        SELECT COUNT(*) FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE s.slot_date BETWEEN :start AND :end
          AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin')
    ");
    $cntStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $totalBookings = (int)$cntStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            b.id AS booking_id, b.status, b.created_at, b.campaign_id,
            u.full_name, u.student_personnel_id, u.phone_number,
            s.slot_date, s.start_time, s.end_time,
            c.title AS campaign_title
        FROM camp_bookings b
        JOIN sys_users u  ON b.student_id  = u.id
        JOIN camp_slots s ON b.slot_id     = s.id
        JOIN camp_list c  ON b.campaign_id = c.id
        WHERE s.slot_date BETWEEN :start AND :end
          AND b.status IN ('booked','confirmed','completed','cancelled','cancelled_by_admin')
        ORDER BY
            CASE WHEN b.status = 'booked' THEN 0 ELSE 1 END,
            s.slot_date ASC, s.start_time ASC
        LIMIT :lim OFFSET 0
    ");
    $stmt->bindValue(':start', $startDate);
    $stmt->bindValue(':end',   $endDate);
    $stmt->bindValue(':lim',   $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Data Fetch Error: " . $e->getMessage());
}

require_once __DIR__ . '/includes/booking_row.php';

require_once __DIR__ . '/includes/header.php';
?>

<!-- CUSTOM STYLES & ANIMATIONS -->
<style>
    @keyframes sideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @keyframes barSlideUp {
        from {
            transform: translate(-50%, 100%);
            opacity: 0;
        }

        to {
            transform: translate(-50%, 0);
            opacity: 1;
        }
    }

    .animate-sideIn {
        animation: sideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .animate-bar {
        animation: barSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    .drawer-overlay {
        background: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(4px);
        transition: opacity 0.3s;
    }

    .tab-active {
        border-color: #0052CC;
        color: #0052CC;
        background: #E7F0FF;
        padding: 0.5rem 1.2rem;
        transform: scale(1.05);
    }

    .no-scrollbar::-webkit-scrollbar {
        display: none;
    }
</style>

<div class="max-w-[1600px] mx-auto space-y-8 pb-32">

    <!-- HEADER & KPI CARDS -->
    <?php
    $header_actions = '
        <div class="bg-white border border-gray-100 p-4 px-6 rounded-[24px] shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 bg-amber-50 text-amber-500 rounded-full flex items-center justify-center text-xl shadow-inner animate-pulse"><i class="fa-solid fa-clock-rotate-left"></i></div>
            <div>
                <h5 class="text-[10px] text-amber-600 font-black uppercase tracking-widest">Pending Approval</h5>
                <p class="text-2xl font-black text-gray-900">' . number_format((float) $total_pending) . '</p>
            </div>
        </div>
        <div class="bg-white border border-gray-100 p-4 px-6 rounded-[24px] shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center text-xl shadow-inner"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <h5 class="text-[10px] text-emerald-600 font-black uppercase tracking-widest">Active Bookings</h5>
                <p class="text-2xl font-black text-gray-900">' . number_format((float) $total_confirmed) . '</p>
            </div>
        </div>
        <div class="bg-white border border-gray-100 p-4 px-6 rounded-[24px] shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 bg-teal-50 text-teal-500 rounded-full flex items-center justify-center text-xl shadow-inner"><i class="fa-solid fa-user-check"></i></div>
            <div>
                <h5 class="text-[10px] text-teal-600 font-black uppercase tracking-widest">เข้าร่วมแล้ว</h5>
                <p class="text-2xl font-black text-gray-900">' . number_format((float) $total_completed) . '</p>
            </div>
        </div>';

    renderPageHeader("Booking Management Center", "ศูนย์บริหารจัดการคิวการจองแบบองค์รวม", $header_actions);
    ?>

    <!-- TAB & SEARCH FILTER BAR -->
    <section
        class="bg-white border border-gray-100 p-5 rounded-[32px] shadow-sm flex flex-col lg:flex-row justify-between items-center gap-6">
        <div class="flex items-center gap-1.5 p-1.5 bg-gray-50 rounded-2xl overflow-x-auto no-scrollbar max-w-full">
            <button onclick="filterByStatus('all')"
                class="status-tab tab-active px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all">All</button>
            <button onclick="filterByStatus('booked')"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">Pending
                <span
                    class="px-2 py-0.5 bg-amber-100 text-amber-600 rounded-lg text-[10px]"><?= $total_pending ?></span></button>
            <button onclick="filterByStatus('confirmed')"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">Confirmed
                <span
                    class="px-2 py-0.5 bg-emerald-100 text-emerald-600 rounded-lg text-[10px]"><?= $total_confirmed ?></span></button>
            <button onclick="filterByStatus('completed')"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">เข้าร่วมแล้ว
                <span
                    class="px-2 py-0.5 bg-teal-100 text-teal-600 rounded-lg text-[10px]"><?= $total_completed ?></span></button>
            <button onclick="filterByStatus('cancelled')"
                class="status-tab px-5 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest text-gray-400 hover:text-gray-900 transition-all flex items-center gap-2">Cancelled
                <span
                    class="px-2 py-0.5 bg-red-100 text-red-600 rounded-lg text-[10px]"><?= $total_cancelled ?></span></button>
        </div>

        <div class="flex-1 w-full lg:max-w-md relative">
            <i class="fa-solid fa-magnifying-glass absolute left-5 top-1/2 -translate-y-1/2 text-gray-300"></i>
            <input type="text" id="globalSearch" placeholder="ค้นหาตามชื่อ, รหัส, หรือกิจกรรม..."
                class="w-full pl-12 pr-6 py-4 bg-gray-50 border border-gray-100 rounded-2xl text-sm font-medium outline-none focus:ring-4 focus:ring-blue-50/50 focus:bg-white transition-all">
        </div>
    </section>

    <!-- DATA TABLE SECTION -->
    <div class="bg-white rounded-[40px] shadow-sm border border-gray-100 overflow-hidden relative">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr
                        class="bg-gray-50 text-gray-400 text-[11px] font-black uppercase tracking-[0.15em] border-b border-gray-100">
                        <td class="p-6 w-12 text-center">
                            <input type="checkbox" onchange="toggleAllRows(this)"
                                class="w-5 h-5 rounded-md border-gray-300 text-blue-600 focus:ring-blue-500">
                        </td>
                        <td class="p-6">Date & Time</td>
                        <td class="p-6">User/Student</td>
                        <td class="p-6">Campaign Info</td>
                        <td class="p-6 text-center">Status</td>
                        <td class="p-6 text-center">Quick Actions</td>
                    </tr>
                </thead>
                <tbody id="bookingTbody" class="divide-y divide-gray-50">
                    <?= render_booking_rows($bookings) ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- LOAD MORE -->
    <div id="loadMoreWrap" class="flex flex-col items-center gap-3 py-4 <?= count($bookings) >= $totalBookings ? 'hidden' : '' ?>">
        <p class="text-xs text-gray-400">
            แสดง <span id="loadedCount"><?= count($bookings) ?></span> / <?= $totalBookings ?> รายการ
        </p>
        <button id="loadMoreBtn" onclick="loadMore()"
            class="px-8 py-3 bg-white border border-gray-200 rounded-2xl text-sm font-bold text-gray-600 hover:bg-gray-50 hover:border-gray-300 transition-all flex items-center gap-2 shadow-sm">
            <i class="fa-solid fa-chevron-down"></i> โหลดเพิ่ม
        </button>
    </div>
</div>

<!-- SIDE DRAWER COMPONENT (Slide-over Details) -->
<div id="drawerOverlay" class="fixed inset-0 drawer-overlay hidden opacity-0" style="z-index:150" onclick="closeDrawer()"></div>
<aside id="sideDrawer"
    class="fixed top-0 right-0 h-screen w-full md:w-[480px] bg-white shadow-2xl translate-x-full hidden flex flex-col transition-all duration-300" style="z-index:200">
    <div class="p-8 border-b border-gray-100 flex justify-between items-center">
        <h3 class="text-2xl font-black text-gray-900 tracking-tight">Booking Info</h3>
        <button onclick="closeDrawer()"
            class="w-10 h-10 bg-gray-50 rounded-full flex items-center justify-center hover:bg-gray-100 transition-all"><i
                class="fa-solid fa-xmark"></i></button>
    </div>

    <div id="drawerContent" class="flex-1 overflow-y-auto p-10 space-y-12 no-scrollbar">
        <!-- Content injected by JS -->
    </div>

    <div id="drawerFooter" class="p-8 bg-gray-50 border-t border-gray-100 flex gap-3">
        <!-- Action buttons injected by JS -->
    </div>
</aside>

<!-- FLOATING ACTION BAR -->
<div id="actionBar"
    class="fixed bottom-10 left-1/2 -translate-x-1/2 z-[50] glass-card px-8 py-4 rounded-[32px] shadow-2xl border-2 border-blue-600/10 hidden translate-y-full flex items-center gap-10">
    <div class="flex flex-col">
        <span class="text-[10px] font-black uppercase tracking-widest text-blue-600 opacity-60">Operations</span>
        <span class="text-sm font-black text-gray-900"><span id="selectedCount">0</span> รายการที่เลือก</span>
    </div>
    <div class="flex items-center gap-3">
        <button onclick="bulkApprove()"
            class="bg-blue-600 text-white px-8 py-3 rounded-2xl text-xs font-black uppercase tracking-widest shadow-xl shadow-blue-200 hover:brightness-110 transition-all active:scale-95">Approve
            All</button>
        <button onclick="bulkCancel()"
            class="bg-white border border-gray-100 text-red-500 px-8 py-3 rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-red-50 transition-all active:scale-95">Cancel</button>
    </div>
</div>

<!-- JS INTERACTION ENGINE -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    /** TAB FILTERING SYSTEM */
    function filterByStatus(status) {
        const tabs = document.querySelectorAll('.status-tab');
        const rows = document.querySelectorAll('.booking-row');

        // Update URL/Tab UI
        tabs.forEach(t => {
            t.classList.remove('tab-active');
            t.classList.add('text-gray-400');
        });
        event.target.classList.add('tab-active');
        event.target.classList.remove('text-gray-400');

        rows.forEach(row => {
            if (status === 'all' || row.dataset.status === status) {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        });
    }

    /** SEARCH FILTERING (Real-time) */
    document.getElementById('globalSearch').addEventListener('input', (e) => {
        const term = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('.booking-row');

        rows.forEach(row => {
            if (row.dataset.search.includes(term)) {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        });
    });

    /** DRAWER LOGIC (Slide-over) */
    function openDrawer(dataStr) {
        const data = JSON.parse(dataStr);
        const drawer = document.getElementById('sideDrawer');
        const overlay = document.getElementById('drawerOverlay');
        const content = document.getElementById('drawerContent');
        const footer = document.getElementById('drawerFooter');

        content.innerHTML = `
            <div class="space-y-3">
                <span class="px-3 py-1 bg-blue-100 text-blue-700 text-[10px] font-black rounded-lg uppercase tracking-widest">Profile Detail</span>
                <h4 class="text-4xl font-[900] text-gray-900 tracking-tight leading-tight">${data.full_name}</h4>
                <p class="text-gray-400 font-bold uppercase tracking-widest text-sm underline decoration-blue-500 decoration-2">ID: ${data.student_personnel_id}</p>
            </div>

            <div class="grid grid-cols-2 gap-8">
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-2">Primary Phone</label>
                    <p class="text-xl font-black text-gray-800">${data.phone_number}</p>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-2">Campaign Unit</label>
                    <p class="text-xl font-black text-primary">#${data.campaign_id}</p>
                </div>
            </div>

            <div class="space-y-6">
                <div class="p-8 bg-gray-50 rounded-[32px] border border-gray-100">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-4">Booked Schedule</label>
                    <div class="flex items-center gap-5">
                        <div class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center text-2xl text-primary shadow-sm"><i class="fa-regular fa-calendar-check"></i></div>
                        <div>
                            <p class="text-lg font-black text-gray-900 leading-none">${data.slot_date}</p>
                            <p class="text-xs font-bold text-blue-600 mt-2">${data.start_time} - ${data.end_time}</p>
                        </div>
                    </div>
                </div>

                <div class="p-8 bg-gray-50 rounded-[32px] border border-gray-100">
                    <label class="text-[10px] font-black uppercase tracking-widest text-gray-400 block mb-4">Selected Activity</label>
                    <p class="text-base font-bold text-gray-800 leading-relaxed">${data.campaign_title}</p>
                </div>
            </div>
        `;

        if (data.status === 'booked') {
            footer.innerHTML = `
                <button onclick="approveOne(${data.booking_id})" class="flex-1 bg-blue-600 text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-xl shadow-blue-200">Approve Booking</button>
                <button onclick="rejectOne(${data.booking_id})" class="flex-1 bg-white text-red-500 py-5 rounded-2xl font-black uppercase tracking-widest text-xs border border-gray-200">Reject</button>
            `;
        } else if (data.status === 'confirmed') {
            footer.innerHTML = `
                <button onclick="checkinOne(${data.booking_id})" class="flex-1 bg-[#0052CC] text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-xl shadow-blue-200"><i class="fa-solid fa-user-check mr-2"></i>รับเข้าร่วมงาน</button>
                <button onclick="rescheduleOne(${data.booking_id})" class="flex-1 bg-orange-500 text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-xl shadow-orange-200">เลื่อนคิว</button>
            `;
        } else {
            footer.innerHTML = `<button onclick="closeDrawer()" class="w-full bg-gray-900 text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs">Close Profile</button>`;
        }

        drawer.classList.remove('hidden');
        overlay.classList.remove('hidden');
        setTimeout(() => {
            drawer.classList.add('translate-x-0');
            drawer.classList.remove('translate-x-full');
            overlay.classList.add('opacity-100');
            overlay.classList.remove('opacity-0');
        }, 10);
    }

    function closeDrawer() {
        const drawer = document.getElementById('sideDrawer');
        const overlay = document.getElementById('drawerOverlay');
        drawer.classList.add('translate-x-full');
        drawer.classList.remove('translate-x-0');
        overlay.classList.remove('opacity-100');
        overlay.classList.add('opacity-0');
        setTimeout(() => {
            drawer.classList.add('hidden');
            overlay.classList.add('hidden');
        }, 300);
    }

    /** BULK ACTION MANAGEMENT */
    function toggleAllRows(master) {
        document.querySelectorAll('.row-checkbox').forEach(cb => {
            if (cb.closest('tr').style.display !== 'none') cb.checked = master.checked;
        });
        updateActionBar();
    }

    function updateActionBar() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        const bar = document.getElementById('actionBar');
        const count = document.getElementById('selectedCount');

        if (checked.length > 0) {
            count.innerText = checked.length;
            bar.classList.remove('hidden', 'translate-y-full');
            bar.classList.add('flex', 'animate-bar');
        } else {
            bar.classList.add('translate-y-full');
            setTimeout(() => { if (document.querySelectorAll('.row-checkbox:checked').length === 0) bar.classList.add('hidden'); }, 300);
        }
    }

    /** API ACTIONS (Approve/Reject) */
    function approveOne(id) {
        Swal.fire({
            title: 'ยืนยันการอนุมัติ?',
            text: "ระบบจะเปลี่ยนสถานะเป็น Confirmed และส่งอีเมล/LINE แจ้งเตือนผู้ใช้",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0052CC',
            confirmButtonText: 'ใช่, อนุมัติเลย',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                performApiCall('ajax/ajax_approve_booking.php', id, 'อนุมัติเรียบร้อย!', 'success', 'confirmed');
            }
        });
    }

    function bulkApprove() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        const ids = Array.from(checked).map(cb => cb.closest('tr').dataset.id);
        
        Swal.fire({
            title: `อนุมัติทั้งหมด ${ids.length} รายการ?`,
            text: "สถานะจะเปลี่ยนเป็น Confirmed ทั้งหมด",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0052CC',
            confirmButtonText: 'ยืนยันอนุมัติทั้งหมด'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'กำลังดำเนินการ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                
                // วนลูปส่งทีละตัว (เพื่อความง่ายและใช้ API เดิม) 
                // หรือจะปรับ API ให้รับ Array ก็ได้ แต่ในขั้นนี้เพื่อความรวดเร็วจะใช้วิธี Promise.all
                const requests = ids.map(id => {
                    return fetch('ajax/ajax_approve_booking.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'appointment_id=' + id + '&csrf_token=<?= get_csrf_token() ?>'
                    }).then(res => res.json());
                });

                Promise.all(requests).then(results => {
                    Swal.fire({ title: 'สำเร็จ!', text: `อนุมัติข้อมูลทั้งหมด ${ids.length} รายการแล้ว`, icon: 'success',
                        timer: 1800, showConfirmButton: false, customClass: { title:'font-prompt', popup:'font-prompt rounded-2xl' } });
                    refreshBookingsTable();
                }).catch(err => {
                    Swal.fire('Error', 'เกิดข้อผิดพลาดบางรายการ', 'error');
                });
            }
        });
    }

    function bulkCancel() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        const ids = Array.from(checked).map(cb => cb.closest('tr').dataset.id);
        
        Swal.fire({
            title: `ยกเลิกทั้งหมด ${ids.length} รายการ?`,
            html: `<p class="font-prompt text-sm text-gray-600">การดำเนินการนี้ไม่สามารถย้อนกลับได้</p>
                   <div class="mt-4 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                     <p class="font-prompt text-sm font-semibold text-orange-700">📧 ระบบจะส่งอีเมลแจ้งเตือนให้กับผู้ใช้ทั้งหมดที่ถูกยกเลิก</p>
                   </div>`,
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#EF4444',
            confirmButtonText: 'ยืนยันยกเลิกทั้งหมด',
            customClass: { title:'font-prompt', htmlContainer:'font-prompt', confirmButton:'font-prompt', cancelButton:'font-prompt' }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'กำลังดำเนินการ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                const requests = ids.map(id => {
                    return fetch('ajax/ajax_force_cancel.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'appointment_id=' + id + '&csrf_token=<?= get_csrf_token() ?>'
                    }).then(res => res.json());
                });

                Promise.all(requests).then(results => {
                    Swal.fire({ title: 'สำเร็จ!', text: 'ยกเลิกข้อมูลที่เลือกเรียบร้อยแล้ว', icon: 'success',
                        timer: 1800, showConfirmButton: false, customClass: { title:'font-prompt', popup:'font-prompt rounded-2xl' } });
                    refreshBookingsTable();
                });
            }
        });
    }

    function rejectOne(id) {
        Swal.fire({
            title: 'ปฏิเสธการจอง?',
            html: `<p class="font-prompt text-sm text-gray-600">การจองนี้จะถูกยกเลิกและไม่สามารถย้อนกลับได้</p>
                   <div class="mt-4 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                     <p class="font-prompt text-sm font-semibold text-orange-700">📧 ระบบจะส่งอีเมลแจ้งเตือนให้กับผู้ใช้ทั้งหมดที่ถูกยกเลิก</p>
                   </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#EF4444',
            confirmButtonText: 'ยืนยันปฏิเสธ',
            cancelButtonText: 'ยกเลิก',
            customClass: { title:'font-prompt', htmlContainer:'font-prompt', confirmButton:'font-prompt', cancelButton:'font-prompt' }
        }).then((result) => {
            if (result.isConfirmed) {
                performApiCall('ajax/ajax_force_cancel.php', id, 'ปฏิเสธการจองสำเร็จ', 'success', 'cancelled_by_admin');
            }
        });
    }

    function checkinOne(id) {
        Swal.fire({
            title: 'รับเข้าร่วมงาน?',
            text: 'ยืนยันว่าผู้ใช้คนนี้เข้าร่วมกิจกรรมแล้ว สถานะจะเปลี่ยนเป็น "เข้าร่วมแล้ว"',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d9488',
            confirmButtonText: '<i class="fa-solid fa-user-check mr-2"></i>ยืนยันรับเข้าร่วม',
            cancelButtonText: 'ยกเลิก',
            customClass: { title: 'font-prompt', htmlContainer: 'font-prompt', confirmButton: 'font-prompt', cancelButton: 'font-prompt' }
        }).then((result) => {
            if (result.isConfirmed) {
                closeDrawer();
                performApiCall('ajax/ajax_checkin_booking.php', id, 'รับเข้าร่วมงานสำเร็จ!', 'success', 'completed');
            }
        });
    }

    function rescheduleOne(id) {
        Swal.fire({
            title: 'ยืนยันการเลื่อนคิว?',
            html: `<p class="font-prompt text-sm text-gray-700">ระบบจะแจ้งให้ผู้ใช้ทราบว่าคิวที่ยืนยันแล้วถูกยกเลิกเพื่อให้ <b>เลื่อนวันจองใหม่</b></p>
                   <div class="mt-4 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                     <p class="font-prompt text-sm font-semibold text-orange-700">📧 ระบบจะส่งอีเมลแจ้งเตือนให้กับผู้ใช้ทั้งหมดที่ถูกยกเลิก</p>
                   </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#f97316',
            confirmButtonText: '<i class="fa-solid fa-paper-plane mr-2"></i> ยืนยันแจ้งเลื่อน',
            cancelButtonText: 'ยกเลิก',
            customClass: { title:'font-prompt', htmlContainer:'font-prompt', confirmButton:'font-prompt', cancelButton:'font-prompt' }
        }).then((result) => {
            if (result.isConfirmed) {
                performApiCall('ajax/ajax_force_cancel.php', id, 'แจ้งเลื่อนคิวสำเร็จ!', 'success', 'cancelled_by_admin');
            }
        });
    }

    // Refresh bookings table without full page reload
    async function refreshBookingsTable() {
        try {
            const res  = await fetch(window.location.href);
            const html = await res.text();
            const doc  = new DOMParser().parseFromString(html, 'text/html');
            const newTbody = doc.getElementById('bookingTbody');
            if (newTbody) document.getElementById('bookingTbody').innerHTML = newTbody.innerHTML;
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
            updateActionBar();
        } catch(e) { console.error('refreshBookingsTable failed:', e); }
    }

    // Update single row status inline
    function updateRowStatus(id, newStatus) {
        const tr = document.querySelector(`tr[data-id="${id}"]`);
        if (!tr) return;
        tr.dataset.status = newStatus;
        const statusTd = tr.cells[4];
        const actionDiv = tr.querySelector('td:last-child div');
        if (statusTd) {
            if (newStatus === 'confirmed')
                statusTd.innerHTML = '<span class="px-4 py-1.5 bg-emerald-50 text-emerald-600 text-[10px] font-black uppercase rounded-full border border-emerald-100 tracking-widest">Confirmed</span>';
            else if (newStatus === 'completed')
                statusTd.innerHTML = '<span class="px-4 py-1.5 bg-teal-50 text-teal-600 text-[10px] font-black uppercase rounded-full border border-teal-100 tracking-widest">เข้าร่วมแล้ว</span>';
            else
                statusTd.innerHTML = `<span class="px-4 py-1.5 bg-gray-50 text-gray-400 text-[10px] font-black uppercase rounded-full tracking-widest">${newStatus}</span>`;
        }
        if (actionDiv) {
            if (newStatus === 'confirmed')
                actionDiv.innerHTML = `
                    <button onclick="checkinOne(${id})" class="px-3 py-1.5 bg-[#0052CC] text-white rounded-xl text-[11px] font-black flex items-center gap-1.5 hover:brightness-110 active:scale-95 transition-all shadow-md shadow-blue-200 whitespace-nowrap"><i class="fa-solid fa-user-check"></i> รับเข้าร่วม</button>
                    <button onclick="rescheduleOne(${id})" class="w-9 h-9 bg-orange-50 text-orange-600 border border-orange-100 rounded-xl flex items-center justify-center hover:bg-orange-500 hover:text-white hover:scale-110 active:scale-95 transition-all shadow-sm" title="แจ้งเลื่อนคิว"><i class="fa-solid fa-clock-rotate-left"></i></button>`;
            else
                actionDiv.innerHTML = `<button onclick='openDrawer(this.closest("tr").dataset.details)' class="text-gray-400 hover:text-blue-600 text-lg transition-colors"><i class="fa-solid fa-circle-info"></i></button>`;
        }
    }

    function performApiCall(url, id, successTitle, icon, newStatus) {
        Swal.fire({ title: 'กำลังดำเนินการ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'appointment_id=' + id + '&csrf_token=<?= get_csrf_token() ?>'
        })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (newStatus) updateRowStatus(id, newStatus);
                    Swal.fire({ title: successTitle, icon: icon, timer: 1500, showConfirmButton: false,
                        customClass: { title:'font-prompt', popup:'font-prompt rounded-2xl' } });
                } else {
                    Swal.fire('Error', data.message || 'เกิดข้อผิดพลาด', 'error');
                }
            });
    }

    /** LOAD MORE */
    let bookingOffset = <?= count($bookings) ?>;
    const bookingTotal = <?= $totalBookings ?>;
    const bookingMonth = <?= $month ?>;
    const bookingYear  = <?= $year ?>;
    let loadingMore    = false;

    async function loadMore() {
        if (loadingMore || bookingOffset >= bookingTotal) return;
        loadingMore = true;

        const btn = document.getElementById('loadMoreBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลด...';

        try {
            const res = await fetch('ajax/ajax_load_more_bookings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `offset=${bookingOffset}&month=${bookingMonth}&year=${bookingYear}&csrf_token=<?= get_csrf_token() ?>`
            });
            const data = await res.json();

            if (!data.success) {
                Swal.fire('Error', data.message || 'เกิดข้อผิดพลาด', 'error');
                return;
            }

            document.getElementById('bookingTbody').insertAdjacentHTML('beforeend', data.html);
            bookingOffset = data.loaded;
            document.getElementById('loadedCount').textContent = data.loaded;

            if (!data.hasMore) {
                document.getElementById('loadMoreWrap').classList.add('hidden');
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-chevron-down"></i> โหลดเพิ่ม';
            }
        } catch (e) {
            console.error('loadMore failed:', e);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-chevron-down"></i> โหลดเพิ่ม';
        } finally {
            loadingMore = false;
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>