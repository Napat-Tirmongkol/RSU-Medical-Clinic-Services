<?php
// user/hub.php — ศูนย์กลาง User
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

// ── Auth check ────────────────────────────────────────────────────────────────
if (empty($_SESSION['evax_student_id'])) {
  header('Location: index.php');
  exit;
}

$userId = (int) $_SESSION['evax_student_id'];

// ── Thai month helper ─────────────────────────────────────────────────────────
$thaiMonths = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
function hub_fmt_date(string $d, array $m): string
{
  if (!$d)
    return '-';
  return date('j', strtotime($d)) . ' ' . $m[(int) date('n', strtotime($d))] . ' ' . ((int) date('Y', strtotime($d)) + 543);
}

// ── DB queries ────────────────────────────────────────────────────────────────
$user = null;
$upcomingBookings = [];
$activeBorrows = [];
$borrowTablesExist = false;

try {
  $pdo = db();

  // User profile
  $s = $pdo->prepare("SELECT full_name, prefix, status, student_personnel_id, email, phone_number FROM sys_users WHERE id = :id LIMIT 1");
  $s->execute([':id' => $userId]);
  $user = $s->fetch();

  // Upcoming bookings (สูงสุด 3 รายการ)
  $s = $pdo->prepare("
        SELECT b.id, c.title, s.slot_date, s.start_time, s.end_time, b.status
        FROM camp_bookings b
        JOIN camp_slots s  ON b.slot_id     = s.id
        JOIN camp_list  c  ON b.campaign_id = c.id
        WHERE b.student_id = :id
          AND b.status IN ('confirmed','booked')
          AND s.slot_date >= CURDATE()
        ORDER BY s.slot_date ASC
        LIMIT 3
    ");
  $s->execute([':id' => $userId]);
  $upcomingBookings = $s->fetchAll();

  // Active borrows จาก e_Borrow (optional — ถ้าตารางยังไม่มีจะ skip)
  try {
    $s = $pdo->prepare("
            SELECT br.id, bc.name AS category_name, bi.name AS item_name, br.due_date
            FROM borrow_records br
            JOIN borrow_items      bi ON br.item_id    = bi.id
            JOIN borrow_categories bc ON bi.type_id    = bc.id
            WHERE br.borrower_student_id = :id
              AND br.status IN ('borrowed','approved')
            ORDER BY br.due_date ASC
            LIMIT 3
        ");
    $s->execute([':id' => $userId]);
    $activeBorrows = $s->fetchAll();
    $borrowTablesExist = true;
  } catch (PDOException) { /* e_Borrow ยังไม่ได้ setup */
  }

} catch (PDOException $e) {
  error_log('Hub DB error: ' . $e->getMessage());
}

$statusMap = ['student' => 'นักศึกษา', 'faculty' => 'อาจารย์', 'staff' => 'เจ้าหน้าที่', 'other' => 'บุคคลทั่วไป'];
$statusLabel = $statusMap[$user['status'] ?? ''] ?? ($user['status'] ?? '');
$displayName = ($user['prefix'] ?? '') . ($user['full_name'] ?? 'ผู้ใช้');

require_once __DIR__ . '/../includes/header.php';
render_header('RSU Medical Hub');
?>

<div class="flex-1 p-6 pt-10 pb-20 relative z-10 bg-white rounded-t-[32px] animate-in fade-in duration-500">
  <!-- Header Section -->
  <div class="mb-6">
    <div class="flex items-center gap-3 mb-1">
      <div class="w-1.5 h-6 bg-orange-500 rounded-full"></div>
      <h1 class="text-2xl font-black text-gray-900 font-prompt tracking-tight">ศูนย์รวมบริการ</h1>
    </div>
    <p class="text-[13px] text-gray-400 font-medium font-prompt ml-4">
      เข้าถึงทุกบริการด้านสุขภาพของคุณได้ง่ายๆ ในที่เดียว
    </p>
  </div>

  <!-- นัดหมายที่กำลังมา -->
  <div class="bg-white rounded-3xl p-5 mb-5 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center shrink-0">
          <i class="fa-solid fa-calendar-check text-sm text-[#0052CC]"></i>
        </div>
        <span class="text-[15px] font-bold text-gray-900">นัดหมายที่กำลังมา</span>
      </div>
      <a href="my_bookings.php" class="text-xs font-bold text-[#0052CC] hover:underline">ดูทั้งหมด <i
          class="fa-solid fa-arrow-right ml-1"></i></a>
    </div>

    <?php if (empty($upcomingBookings)): ?>
      <div class="text-center pt-6 pb-4 bg-gray-50/50 rounded-2xl border-2 border-dashed border-gray-100">
        <i class="fa-regular fa-calendar-xmark text-4xl text-gray-300 block mb-3"></i>
        <p class="text-sm text-gray-400 mb-4 font-medium">ยังไม่มีนัดหมายที่กำลังมา</p>
        <a href="booking_campaign.php"
          class="inline-block bg-[#0052CC] hover:bg-blue-700 text-white text-xs font-bold py-3 px-6 rounded-xl shadow-sm hover:shadow active:scale-[0.98] transition-all">
          + จองนัดหมายใหม่
        </a>
      </div>
    <?php else: ?>
      <?php foreach ($upcomingBookings as $appt): ?>
        <a href="my_bookings.php"
          class="block p-4 bg-blue-50/30 rounded-2xl mb-3 no-underline border border-blue-100/50 hover:border-[#0052CC] hover:shadow-sm transition-all active:scale-[0.98]">
          <div class="text-sm font-bold text-gray-800 mb-1.5"><?= htmlspecialchars($appt['title']) ?></div>
          <div class="text-[11px] text-gray-500 flex items-center gap-2 flex-wrap font-medium">
            <span class="flex items-center gap-1"><i class="fa-regular fa-clock text-[#0052CC]"></i>
              <?= hub_fmt_date($appt['slot_date'], $thaiMonths) ?></span>
            <span class="text-gray-300">•</span>
            <span><?= substr($appt['start_time'], 0, 5) ?>–<?= substr($appt['end_time'], 0, 5) ?> น.</span>
            <?php if ($appt['status'] === 'confirmed'): ?>
              <span
                class="ml-auto inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 text-emerald-700 text-[10px] font-black rounded-full border border-emerald-100 shadow-sm">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                ยืนยันแล้ว
              </span>
            <?php else: ?>
              <span
                class="ml-auto inline-flex items-center gap-1.5 px-3 py-1 bg-amber-50 text-amber-700 text-[10px] font-black rounded-full border border-amber-100 shadow-sm">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                รอยืนยัน
              </span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- อุปกรณ์ที่ยืมอยู่ -->
  <div class="bg-white rounded-3xl p-5 mb-5 shadow-sm border border-gray-100 hover:shadow-md transition-shadow">
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-orange-50 rounded-xl flex items-center justify-center shrink-0">
          <i class="fa-solid fa-box-open text-sm text-orange-500"></i>
        </div>
        <span class="text-[15px] font-bold text-gray-900">อุปกรณ์ที่ยืมอยู่</span>
      </div>
      <a href="../e_Borrow/auth_bridge.php?to=history.php"
        class="text-xs font-bold text-orange-500 hover:underline">ดูทั้งหมด <i
          class="fa-solid fa-arrow-right ml-1"></i></a>
    </div>

    <?php if (empty($activeBorrows)): ?>
      <div class="text-center pt-6 pb-4 bg-gray-50/50 rounded-2xl border-2 border-dashed border-gray-100">
        <i class="fa-solid fa-box-open text-4xl text-gray-300 block mb-3"></i>
        <p class="text-sm text-gray-400 mb-4 font-medium">ไม่มีรายการยืมอุปกรณ์</p>
        <a href="../e_Borrow/auth_bridge.php"
          class="inline-block bg-orange-500 hover:bg-orange-600 text-white text-xs font-bold py-3 px-6 rounded-xl shadow-sm hover:shadow active:scale-[0.98] transition-all">
          ทำรายการยืมอุปกรณ์
        </a>
      </div>
    <?php else: ?>
      <?php foreach ($activeBorrows as $borrow): ?>
        <?php
        $daysLeft = (int) ceil((strtotime($borrow['due_date']) - time()) / 86400);
        $urgColor = $daysLeft <= 2 ? 'text-red-600' : ($daysLeft <= 5 ? 'text-orange-500' : 'text-emerald-600');
        $urgBg = $daysLeft <= 2 ? 'bg-red-50 border-red-100' : ($daysLeft <= 5 ? 'bg-orange-50 border-orange-100' : 'bg-emerald-50 border-emerald-100');
        ?>
        <div class="p-4 rounded-2xl mb-3 border <?= $urgBg ?> hover:shadow-sm transition-all">
          <div class="text-sm font-bold text-gray-800 mb-1"><?= htmlspecialchars($borrow['item_name']) ?></div>
          <div class="text-[11px] text-gray-500 flex items-center justify-between font-medium">
            <span><?= htmlspecialchars($borrow['category_name']) ?></span>
            <span class="<?= $urgColor ?> font-bold bg-white px-2 py-0.5 rounded-md shadow-sm border border-white/50">
              <i class="fa-solid fa-clock-rotate-left mr-1"></i> คืนภายใน <?= $daysLeft ?> วัน
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Quick Access -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
    <a href="booking_campaign.php"
      class="bg-white rounded-3xl p-5 no-underline shadow-sm border border-gray-100 flex flex-col items-center justify-center text-center gap-3 active:scale-95 transition-all hover:border-[#0052CC]">
      <div class="w-12 h-12 rounded-2xl flex items-center justify-center shadow-lg shadow-blue-200"
        style="background: linear-gradient(135deg, #0052CC, #0070f3);">
        <i class="fa-solid fa-notes-medical text-2xl text-white"></i>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-900">นัดหมายสุขภาพ</div>
        <div class="text-[10px] font-bold text-gray-400 mt-0.5 uppercase tracking-wider">จอง / ดูประวัติ</div>
      </div>
    </a>
    <a href="../e_Borrow/auth_bridge.php"
      class="bg-white rounded-3xl p-5 no-underline shadow-sm border border-gray-100 flex flex-col items-center justify-center text-center gap-3 active:scale-95 transition-all hover:border-orange-500">
      <div class="w-12 h-12 rounded-2xl flex items-center justify-center shadow-lg shadow-orange-200"
        style="background: linear-gradient(135deg, #f97316, #fb923c);">
        <i class="fa-solid fa-wheelchair text-2xl text-white"></i>
      </div>
      <div>
        <div class="text-sm font-bold text-gray-900">ยืมอุปกรณ์</div>
        <div class="text-[10px] font-bold text-gray-400 mt-0.5 uppercase tracking-wider">ระบบ e-Borrow</div>
      </div>
    </a>
  </div>

</div><!-- /cards -->

<?php
require_once __DIR__ . '/../includes/footer.php';
render_footer();
?>