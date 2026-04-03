<?php
// user/index.php
declare(strict_types = 1)
;
session_start();
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/header.php';

// ถ้า Login แล้ว ให้ Redirect ไปหน้าที่เหมาะสมเลย ไม่ต้องให้มาที่นี่อีก
if (isset($_SESSION['evax_student_id'])) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT full_name, student_personnel_id, phone_number, status, email FROM sys_users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $_SESSION['evax_student_id']]);
        $row = $stmt->fetch();

        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings WHERE student_id = :sid AND status IN ('confirmed', 'booked')");
        $stmtCheck->execute([':sid' => $_SESSION['evax_student_id']]);
        $hasBooking = (int)$stmtCheck->fetchColumn() > 0;

        // ถ้ามี invite_token ค้างอยู่ใน session ให้กลับไปหน้าแคมเปญนั้นก่อน
        $inviteToken = $_SESSION['invite_token'] ?? '';
        unset($_SESSION['invite_token']);

        if ($hasBooking) {
            header('Location: my_bookings.php');
        }
        elseif (!empty($row['full_name']) &&
                !empty($row['phone_number']) &&
                !empty($row['status']) &&
                !empty($row['email']) &&
                ($row['status'] === 'other' || !empty($row['student_personnel_id']))) {
            header('Location: ' . ($inviteToken !== '' ? 'c.php?t=' . urlencode($inviteToken) : 'booking_campaign.php'));
        }
        else {
            header('Location: profile.php');
        }
        exit;
    }
    catch (PDOException $e) {
        // ถ้า DB error ให้ไปหน้า Login ใหม่
        session_destroy();
    }
}

render_header('เข้าสู่ระบบ');
?>

<div class="flex flex-col items-center justify-center min-h-screen bg-[#f4f7fa] p-5">

    <div class="w-full max-w-sm animate-in fade-in slide-in-from-bottom-4 duration-500">

        <!-- Header Logo -->
        <div class="text-center mb-8">
            <div class="mx-auto w-20 h-20 bg-white rounded-3xl flex items-center justify-center shadow-lg mb-5 rotate-3 hover:rotate-0 transition-transform duration-300">
                <i class="fa-solid fa-syringe text-4xl text-[#0052CC]"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 font-prompt">ระบบจองคิว E-Vax</h1>
            <p class="text-gray-500 text-sm mt-1 font-prompt">มหาวิทยาลัยรังสิต</p>
        </div>

        <!-- Login Card -->
        <div class="bg-white rounded-3xl shadow-[0_8px_40px_rgba(0,0,0,0.06)] border border-gray-100 overflow-hidden">

            <!-- Header Strip -->
            <div class="bg-gradient-to-r from-[#0052CC] to-[#0070f3] p-6 text-center">
                <p class="text-white text-sm font-prompt opacity-90">เข้าสู่ระบบด้วยบัญชี LINE ของคุณ</p>
                <p class="text-blue-100 text-xs mt-1 font-prompt opacity-75">เพื่อดำเนินการจองคิวรับวัคซีน</p>
            </div>

            <div class="p-7">
                <!-- LINE Login Button -->
                <a href="../archive/line_api/line_login.php"
                   id="btn-line-login"
                   class="w-full flex items-center justify-center gap-3 bg-[#00c300] hover:bg-[#00a800] active:bg-[#009000] text-white font-bold py-4 px-6 rounded-2xl transition-all duration-200 shadow-lg shadow-green-200 active:scale-[0.97] hover:shadow-green-300 hover:shadow-xl">
                    <i class="fa-brands fa-line text-2xl"></i>
                    <span class="text-[16px] font-prompt">เข้าสู่ระบบด้วย LINE</span>
                </a>

                <!-- Divider -->
                <div class="flex items-center gap-3 my-6">
                    <div class="flex-1 h-px bg-gray-100"></div>
                    <span class="text-xs text-gray-400 font-prompt">ข้อมูลของคุณถูกปกป้องด้วย PDPA</span>
                    <div class="flex-1 h-px bg-gray-100"></div>
                </div>

                <!-- Info Badges -->
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="p-3 bg-blue-50 rounded-2xl">
                        <i class="fa-solid fa-shield-halved text-[#0052CC] text-lg mb-1 block"></i>
                        <p class="text-xs text-gray-600 font-prompt leading-tight">ปลอดภัย<br>100%</p>
                    </div>
                    <div class="p-3 bg-green-50 rounded-2xl">
                        <i class="fa-solid fa-clock text-green-600 text-lg mb-1 block"></i>
                        <p class="text-xs text-gray-600 font-prompt leading-tight">รวดเร็ว<br>ทันใจ</p>
                    </div>
                    <div class="p-3 bg-purple-50 rounded-2xl">
                        <i class="fa-solid fa-bell text-purple-600 text-lg mb-1 block"></i>
                        <p class="text-xs text-gray-600 font-prompt leading-tight">แจ้งเตือน<br>ทาง LINE</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Note -->
        <p class="text-center text-xs text-gray-400 mt-6 font-prompt leading-relaxed px-4">
            การเข้าใช้งานถือว่าคุณยอมรับ<br>
            <span class="text-[#0052CC] font-medium">นโยบายความเป็นส่วนตัว</span> และ <span class="text-[#0052CC] font-medium">ข้อกำหนดการใช้งาน</span>
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php';
render_footer(); ?>
