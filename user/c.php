<?php
// user/c.php — Campaign Invite Landing
// เข้าถึงแคมเปญเดี่ยวผ่าน share token เช่น /user/c.php?t=abc123
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/footer.php';

session_start();

// ตรวจสอบว่า login แล้วหรือยัง
$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    // เก็บ token ไว้ใน session แล้ว redirect ไป login
    $_SESSION['invite_token'] = trim($_GET['t'] ?? '');
    header('Location: index.php', true, 303);
    exit;
}

check_user_profile((int)($_SESSION['evax_student_id'] ?? 0));

$token = trim($_GET['t'] ?? '');
if ($token === '') {
    header('Location: booking_campaign.php', true, 303);
    exit;
}

$campaign = null;
$usedSeats = 0;

try {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT c.*,
               (SELECT COUNT(*) FROM camp_bookings a
                WHERE a.campaign_id = c.id AND a.status IN ('booked','confirmed')) AS used_seats
        FROM camp_list c
        WHERE c.share_token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($campaign) {
        $usedSeats = (int)$campaign['used_seats'];
    }
} catch (PDOException $e) {
    // ปล่อยให้แสดง error page ด้านล่าง
}

function getBadge($type): array {
    return match($type) {
        'vaccine'      => ['label' => 'ฉีดวัคซีน',   'class' => 'bg-blue-100 text-blue-700',   'icon' => 'fa-syringe'],
        'training'     => ['label' => 'อบรม/สัมมนา', 'class' => 'bg-purple-100 text-purple-700','icon' => 'fa-chalkboard-user'],
        'health_check' => ['label' => 'ตรวจสุขภาพ',  'class' => 'bg-green-100 text-green-700', 'icon' => 'fa-stethoscope'],
        default        => ['label' => 'กิจกรรม',      'class' => 'bg-gray-100 text-gray-700',   'icon' => 'fa-star'],
    };
}

$today = date('Y-m-d');
$isExpired = $campaign && $campaign['available_until'] && ($campaign['available_until'] < $today);
$isInactive = $campaign && ($campaign['status'] !== 'active' || $isExpired);
$remaining = $campaign ? max(0, (int)$campaign['total_capacity'] - $usedSeats) : 0;
$isFull = ($remaining <= 0 && $campaign);

render_header(($campaign ? htmlspecialchars($campaign['title']) : 'ไม่พบแคมเปญ') . ' - E-Campaign');
?>

<div class="max-w-md mx-auto px-4 py-8 pb-28 min-h-screen animate-in fade-in slide-in-from-bottom-4 duration-500">

<?php if (!$campaign): ?>
  <!-- Not found -->
  <div class="flex flex-col items-center justify-center py-20 text-center">
    <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mb-5">
      <i class="fa-solid fa-link-slash text-3xl text-red-300"></i>
    </div>
    <h2 class="text-xl font-black text-gray-800 mb-2">ไม่พบลิงก์แคมเปญนี้</h2>
    <p class="text-sm text-gray-500 mb-6 leading-relaxed">ลิงก์อาจหมดอายุหรือถูกยกเลิกแล้ว<br>ลองดูแคมเปญทั้งหมดได้ที่ด้านล่าง</p>
    <a href="booking_campaign.php" class="bg-[#0052CC] text-white px-6 py-3 rounded-2xl font-bold text-sm shadow-lg shadow-blue-200 transition-all hover:bg-blue-700 active:scale-95">
      <i class="fa-solid fa-list mr-2"></i>ดูแคมเปญทั้งหมด
    </a>
  </div>

<?php elseif ($isInactive): ?>
  <!-- Closed / Expired -->
  <?php $badge = getBadge($campaign['type']); ?>
  <div class="mb-6">
    <div class="w-12 h-12 <?= $badge['class'] ?> rounded-2xl flex items-center justify-center text-xl mb-4">
      <i class="fa-solid <?= $badge['icon'] ?>"></i>
    </div>
    <h1 class="text-2xl font-black text-gray-900 leading-tight mb-1"><?= htmlspecialchars($campaign['title']) ?></h1>
    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $badge['class'] ?>">
      <i class="fa-solid <?= $badge['icon'] ?> mr-0.5"></i><?= $badge['label'] ?>
    </span>
  </div>

  <div class="bg-red-50 border border-red-200 rounded-3xl p-8 text-center">
    <div class="text-4xl mb-3 text-gray-400"><i class="fa-solid fa-lock"></i></div>
    <p class="font-black text-red-700 text-lg mb-1"><?= $isExpired ? 'หมดเขตรับสมัครแล้ว' : 'ปิดรับสมัครชั่วคราว' ?></p>
    <p class="text-sm text-red-500">
      <?= $isExpired ? 'แคมเปญนี้ปิดรับสมัครเมื่อ ' . date('d/m/Y', strtotime($campaign['available_until'])) : 'ผู้ดูแลระบบได้ปิดการรับสมัครไว้ชั่วคราว' ?>
    </p>
  </div>

  <div class="mt-6 text-center">
    <a href="booking_campaign.php" class="text-sm font-bold text-gray-400 hover:text-[#0052CC] transition-colors">
      <i class="fa-solid fa-arrow-left mr-1"></i>ดูแคมเปญที่เปิดรับอยู่
    </a>
  </div>

<?php else: ?>
  <!-- Active campaign -->
  <?php $badge = getBadge($campaign['type']); ?>

  <!-- Invite banner -->
  <div class="bg-gradient-to-br from-[#0052CC] to-[#0070f3] rounded-3xl p-5 mb-6 text-white relative overflow-hidden">
    <div class="absolute -right-6 -top-6 w-28 h-28 bg-white opacity-[0.06] rounded-full"></div>
    <div class="absolute -left-4 -bottom-4 w-20 h-20 bg-cyan-300 opacity-[0.08] rounded-full blur-xl"></div>
    <div class="relative">
      <p class="text-xs font-bold text-blue-200 uppercase tracking-widest mb-1">
        <i class="fa-solid fa-link mr-1"></i>ลิงก์เชิญพิเศษ
      </p>
      <p class="text-sm text-blue-100 leading-snug">คุณได้รับการเชิญให้ลงทะเบียนเข้าร่วมกิจกรรม</p>
    </div>
  </div>

  <!-- Campaign card -->
  <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">
    <?php if ($isFull): ?>
      <div class="absolute inset-0 bg-white/60 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-[2rem]">
        <span class="bg-red-500 text-white px-4 py-1 rounded-full text-sm font-bold rotate-[-5deg] shadow-lg">เต็มแล้ว (Full)</span>
      </div>
    <?php endif; ?>

    <div class="p-6">
      <div class="flex justify-between items-start mb-4">
        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider <?= $badge['class'] ?>">
          <i class="fa-solid <?= $badge['icon'] ?> mr-1"></i> <?= $badge['label'] ?>
        </span>
        <?php if ($campaign['available_until']): ?>
          <span class="text-[10px] text-red-500 font-bold italic">
            <i class="fa-solid fa-clock mr-1"></i> ปิดรับ <?= date('d/m/Y', strtotime($campaign['available_until'])) ?>
          </span>
        <?php endif; ?>
      </div>

      <h2 class="text-xl font-black text-gray-900 mb-2"><?= htmlspecialchars($campaign['title']) ?></h2>
      <p class="text-gray-500 text-xs leading-relaxed mb-5">
        <?= nl2br(htmlspecialchars($campaign['description'] ?: 'ไม่มีรายละเอียดเพิ่มเติมสำหรับกิจกรรมนี้')) ?>
      </p>

      <!-- Seats info -->
      <div class="bg-gray-50 rounded-2xl p-4 mb-5 flex items-center justify-between">
        <div>
          <p class="text-[10px] text-gray-400 uppercase font-bold tracking-widest">ที่นั่งคงเหลือ</p>
          <p class="text-3xl font-black <?= $remaining <= 10 ? 'text-red-500' : 'text-gray-900' ?>">
            <?= number_format($remaining) ?>
            <span class="text-sm font-normal text-gray-500">ที่นั่ง</span>
          </p>
        </div>
        <div class="text-right">
          <p class="text-[10px] text-gray-400 uppercase font-bold tracking-widest">จองแล้ว</p>
          <p class="text-xl font-bold text-gray-600"><?= number_format($usedSeats) ?> / <?= number_format($campaign['total_capacity']) ?></p>
        </div>
      </div>

      <?php if ($campaign['is_auto_approve']): ?>
        <div class="flex items-center gap-2 text-xs font-bold text-blue-600 bg-blue-50 rounded-xl px-3 py-2 mb-5">
          <i class="fa-solid fa-bolt text-yellow-500"></i> อนุมัติสิทธิ์อัตโนมัติทันทีหลังจอง
        </div>
      <?php else: ?>
        <div class="flex items-center gap-2 text-xs font-bold text-gray-500 bg-gray-50 rounded-xl px-3 py-2 mb-5">
          <i class="fa-solid fa-user-shield text-gray-400"></i> รอแอดมินอนุมัติหลังจาก Booking
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Action buttons -->
  <div class="mt-6 space-y-3">
    <?php if (!$isFull): ?>
      <a href="booking_date.php?campaign_id=<?= (int)$campaign['id'] ?>"
         class="flex items-center justify-center gap-3 w-full bg-[#0052CC] hover:bg-blue-700 text-white font-bold py-4 rounded-2xl shadow-lg shadow-blue-200 transition-all active:scale-[0.98] text-base">
        <i class="fa-solid fa-calendar-check text-lg"></i>
        จองคิวสำหรับแคมเปญนี้
      </a>
    <?php else: ?>
      <button disabled
        class="flex items-center justify-center gap-3 w-full bg-gray-100 text-gray-400 font-bold py-4 rounded-2xl cursor-not-allowed text-base">
        <i class="fa-solid fa-lock text-lg"></i>
        ที่นั่งเต็มแล้ว
      </button>
    <?php endif; ?>
    <a href="my_bookings.php" class="flex items-center justify-center gap-2 w-full py-3 text-sm font-bold text-gray-400 hover:text-[#0052CC] transition-colors">
      <i class="fa-solid fa-receipt text-xs"></i> ตรวจสอบการจองของฉัน
    </a>
  </div>

<?php endif; ?>
</div>

<?php render_footer(); ?>
