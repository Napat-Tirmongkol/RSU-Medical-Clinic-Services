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

$userId = (int)$_SESSION['evax_student_id'];

// ── Thai month helper ─────────────────────────────────────────────────────────
$thaiMonths = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
function hub_fmt_date(string $d, array $m): string {
    if (!$d) return '-';
    return date('j', strtotime($d)) . ' ' . $m[(int)date('n', strtotime($d))] . ' ' . ((int)date('Y', strtotime($d)) + 543);
}

// ── DB queries ────────────────────────────────────────────────────────────────
$user = null;
$upcomingBookings = [];
$activeBorrows    = [];
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
        $activeBorrows    = $s->fetchAll();
        $borrowTablesExist = true;
    } catch (PDOException) { /* e_Borrow ยังไม่ได้ setup */ }

} catch (PDOException $e) {
    error_log('Hub DB error: ' . $e->getMessage());
}

$statusMap   = ['student' => 'นักศึกษา', 'faculty' => 'อาจารย์', 'staff' => 'เจ้าหน้าที่', 'other' => 'บุคคลทั่วไป'];
$statusLabel = $statusMap[$user['status'] ?? ''] ?? ($user['status'] ?? '');
$displayName = ($user['prefix'] ?? '') . ($user['full_name'] ?? 'ผู้ใช้');

require_once __DIR__ . '/../includes/header.php';
render_header('RSU Medical Hub');
?>

<div class="flex flex-col min-h-full">

  <!-- ── Cards (pulls up over header gradient) ─────────────────────────── -->
  <div class="flex-1 px-3.5 -mt-5">

    <!-- นัดหมายที่กำลังมา -->
    <div class="bg-white rounded-2xl p-[18px] mb-3.5 shadow-[0_4px_24px_rgba(0,0,0,.07)]">
      <div class="flex items-center justify-between mb-3.5">
        <div class="flex items-center gap-2.5">
          <div class="w-[30px] h-[30px] bg-blue-50 rounded-[9px] flex items-center justify-center shrink-0">
            <i class="fa-solid fa-calendar-check text-xs text-[#0052CC]"></i>
          </div>
          <span class="text-sm font-extrabold text-slate-900">นัดหมายที่กำลังมา</span>
        </div>
        <a href="my_bookings.php" class="text-[11px] font-bold text-[#0052CC] no-underline">ดูทั้งหมด →</a>
      </div>

      <?php if (empty($upcomingBookings)): ?>
        <div class="text-center pt-4 pb-2">
          <i class="fa-regular fa-calendar text-4xl text-slate-200 block mb-2"></i>
          <p class="text-[13px] text-slate-400 mb-3.5">ยังไม่มีนัดหมายที่กำลังมา</p>
          <a href="booking_campaign.php" class="inline-block bg-[#0052CC] text-white text-xs font-bold py-2.5 px-[22px] rounded-xl no-underline">
            + จองนัดหมายใหม่
          </a>
        </div>
      <?php else: ?>
        <?php foreach ($upcomingBookings as $appt): ?>
          <a href="my_bookings.php" class="block p-3 bg-[#f8faff] rounded-[13px] mb-2 no-underline border border-[#e8f0ff]">
            <div class="text-[13px] font-bold text-slate-800 mb-1"><?= htmlspecialchars($appt['title']) ?></div>
            <div class="text-[11px] text-slate-500 flex items-center gap-1.5 flex-wrap">
              <i class="fa-regular fa-clock"></i>
              <?= hub_fmt_date($appt['slot_date'], $thaiMonths) ?>
              &nbsp;·&nbsp;<?= substr($appt['start_time'],0,5) ?>–<?= substr($appt['end_time'],0,5) ?> น.
              <?php if ($appt['status'] === 'confirmed'): ?>
                <span class="ml-auto bg-green-100 text-green-700 text-[10px] font-extrabold py-0.5 px-2 rounded">ยืนยันแล้ว</span>
              <?php else: ?>
                <span class="ml-auto bg-yellow-100 text-yellow-700 text-[10px] font-extrabold py-0.5 px-2 rounded">รอยืนยัน</span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- อุปกรณ์ที่ยืมอยู่ -->
    <div class="bg-white rounded-2xl p-[18px] mb-3.5 shadow-[0_4px_24px_rgba(0,0,0,.07)]">
      <div class="flex items-center justify-between mb-3.5">
        <div class="flex items-center gap-2.5">
          <div class="w-[30px] h-[30px] bg-orange-50 rounded-[9px] flex items-center justify-center shrink-0">
            <i class="fa-solid fa-box-open text-xs text-orange-500"></i>
          </div>
          <span class="text-sm font-extrabold text-slate-900">อุปกรณ์ที่ยืมอยู่</span>
        </div>
        <a href="../archive/e_Borrow/" class="text-[11px] font-bold text-orange-500 no-underline">ดูทั้งหมด →</a>
      </div>

      <?php if (empty($activeBorrows)): ?>
        <div class="text-center pt-4 pb-2">
          <i class="fa-solid fa-box-open text-4xl text-slate-200 block mb-2"></i>
          <p class="text-[13px] text-slate-400 mb-3.5">ไม่มีรายการยืมอุปกรณ์</p>
          <a href="../archive/e_Borrow/" class="inline-block bg-orange-500 text-white text-xs font-bold py-2.5 px-[22px] rounded-xl no-underline">
            ยืมอุปกรณ์
          </a>
        </div>
      <?php else: ?>
        <?php foreach ($activeBorrows as $borrow): ?>
          <?php
            $daysLeft = (int)ceil((strtotime($borrow['due_date']) - time()) / 86400);
            $urgColor = $daysLeft <= 2 ? '#ef4444' : ($daysLeft <= 5 ? '#f97316' : '#16a34a');
          ?>
          <div class="p-3 bg-[#fff8f5] rounded-[13px] mb-2 border border-[#ffe4cc]">
            <div class="text-[13px] font-bold text-slate-800 mb-1"><?= htmlspecialchars($borrow['item_name']) ?></div>
            <div class="text-[11px] text-slate-500 flex items-center justify-between">
              <span><?= htmlspecialchars($borrow['category_name']) ?></span>
              <span style="color:<?= $urgColor ?>" class="font-extrabold">คืนภายใน <?= $daysLeft ?> วัน</span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Quick Access -->
    <div class="grid grid-cols-2 gap-3 mb-3.5">
      <a href="booking_campaign.php" class="bg-white rounded-2xl p-[18px_14px] no-underline shadow-[0_4px_20px_rgba(0,0,0,.06)] flex flex-col gap-2.5">
        <div class="w-11 h-11 bg-gradient-to-br from-[#0052CC] to-[#0070f3] rounded-[14px] flex items-center justify-center">
          <i class="fa-solid fa-syringe text-[17px] text-white"></i>
        </div>
        <div>
          <div class="text-[13px] font-extrabold text-slate-800">นัดหมายสุขภาพ</div>
          <div class="text-[11px] text-slate-400 mt-0.5">จอง / ดูประวัติ</div>
        </div>
      </a>
      <a href="../archive/e_Borrow/" class="bg-white rounded-2xl p-[18px_14px] no-underline shadow-[0_4px_20px_rgba(0,0,0,.06)] flex flex-col gap-2.5">
        <div class="w-11 h-11 bg-gradient-to-br from-[#f97316] to-[#fb923c] rounded-[14px] flex items-center justify-center">
          <i class="fa-solid fa-box-open text-[17px] text-white"></i>
        </div>
        <div>
          <div class="text-[13px] font-extrabold text-slate-800">ยืมอุปกรณ์</div>
          <div class="text-[11px] text-slate-400 mt-0.5">e-Borrow</div>
        </div>
      </a>
    </div>

  </div><!-- /cards -->
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
render_footer();
?>
