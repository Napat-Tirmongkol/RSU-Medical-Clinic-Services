<?php
// user/checkin_campaign.php — QR Self Check-in via Campaign-level QR (works every day)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

$pdo        = db();
$campaignId = (int)($_GET['campaign'] ?? 0);
$token      = trim($_GET['token'] ?? '');
$isPost     = $_SERVER['REQUEST_METHOD'] === 'POST';

function base_url_c(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $dir;
}

// ── Validate token ────────────────────────────────────────────────────────────
$valid_token = hash_hmac('sha256', "qr:campaign:{$campaignId}", QR_SLOT_SECRET);
$token_ok    = ($campaignId > 0 && hash_equals($valid_token, $token));

// ── Fetch campaign ────────────────────────────────────────────────────────────
$campaign = null;
if ($token_ok) {
    try {
        $st = $pdo->prepare("SELECT id, title, qr_enabled FROM camp_list WHERE id = :id LIMIT 1");
        $st->execute([':id' => $campaignId]);
        $campaign = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {}
}

// ── Get current user (LINE session) ──────────────────────────────────────────
$lineUserId = $_SESSION['line_user_id'] ?? '';
$user       = null;

if ($lineUserId !== '') {
    try {
        $st = $pdo->prepare("
            SELECT id, full_name, student_personnel_id
            FROM sys_users
            WHERE line_user_id = :lid OR line_user_id_new = :lid2
            LIMIT 1
        ");
        $st->execute([':lid' => $lineUserId, ':lid2' => $lineUserId]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {}
}

// ── States: confirm | success | already | no_booking | qr_disabled | invalid | not_logged_in
$result  = null;
$booking = null;
$slot    = null;

if (!$token_ok || !$campaign) {
    $result = 'invalid';
} elseif (!(int)($campaign['qr_enabled'] ?? 0)) {
    $result = 'qr_disabled';
} elseif (!$user) {
    $result = 'not_logged_in';
} else {
    try {
        // Find today's slot booking (or earliest future slot for early arrivals)
        $st = $pdo->prepare("
            SELECT b.id, b.attended_at, b.status,
                   s.slot_date, s.start_time, s.end_time
            FROM camp_bookings b
            JOIN camp_slots s ON b.slot_id = s.id
            WHERE b.campaign_id = :cid
              AND b.student_id  = :uid
              AND s.slot_date   = CURDATE()
              AND b.status IN ('booked','confirmed')
            LIMIT 1
        ");
        $st->execute([':cid' => $campaignId, ':uid' => (int)$user['id']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $st2 = $pdo->prepare("
                SELECT b.id, b.attended_at, b.status,
                       s.slot_date, s.start_time, s.end_time
                FROM camp_bookings b
                JOIN camp_slots s ON b.slot_id = s.id
                WHERE b.campaign_id = :cid
                  AND b.student_id  = :uid
                  AND s.slot_date   > CURDATE()
                  AND b.status IN ('booked','confirmed')
                ORDER BY s.slot_date ASC
                LIMIT 1
            ");
            $st2->execute([':cid' => $campaignId, ':uid' => (int)$user['id']]);
            $row = $st2->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) {
            $result = 'no_booking';
        } elseif (!empty($row['attended_at'])) {
            $slot    = $row;
            $booking = $row;
            $result  = 'already';
        } elseif ($isPost) {
            // POST = user confirmed → validate CSRF + mark attended
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                $result = 'invalid';
            } else {
                $bookingId = (int)($_POST['booking_id'] ?? 0);
                if ($bookingId !== (int)$row['id']) {
                    $result = 'invalid';
                } else {
                    $pdo->prepare("UPDATE camp_bookings SET attended_at = NOW(), status = 'completed' WHERE id = ?")
                        ->execute([$bookingId]);
                    $row['attended_at'] = date('Y-m-d H:i:s');
                    $slot    = $row;
                    $booking = $row;
                    $result  = 'success';
                }
            }
        } else {
            // GET = show confirmation screen
            $slot   = $row;
            $result = 'confirm';
        }
    } catch (PDOException) {
        $result = 'invalid';
    }
}

// ── Login redirect ────────────────────────────────────────────────────────────
$return_url = base_url_c() . '/user/checkin_campaign.php?campaign=' . $campaignId . '&token=' . urlencode($token);
if ($result === 'not_logged_in') {
    $_SESSION['checkin_return'] = $return_url;
}

function fmt_date_c(string $d): string {
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $parts  = explode('-', $d);
    return (int)$parts[2] . ' ' . ($months[(int)$parts[1]] ?? '') . ' ' . ((int)$parts[0] + 543);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>QR Check-in</title>
<link rel="stylesheet" href="../assets/css/tailwind.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rsufont.css">
<style>
  * { font-family: 'Sarabun', sans-serif; }
  body { background: linear-gradient(135deg,#e8f5f0 0%,#f0faf4 50%,#e8f5ec 100%); min-height: 100vh; }
  @keyframes popIn { from{opacity:0;transform:scale(.88) translateY(24px)} to{opacity:1;transform:scale(1) translateY(0)} }
  .pop-in { animation: popIn .45s cubic-bezier(.16,1,.3,1) both; }
</style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

<div class="w-full max-w-sm pop-in">

  <div class="text-center mb-6">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl shadow-lg mb-3"
         style="background:linear-gradient(135deg,#2e9e63,#4ade80)">
      <i class="fa-solid fa-qrcode text-white text-2xl"></i>
    </div>
    <p class="text-xs font-black uppercase tracking-widest text-emerald-700 opacity-70">RSU Medical Clinic</p>
  </div>

  <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-emerald-50">

    <?php if ($result === 'confirm'): ?>
    <!-- ✅ CONFIRM STEP -->
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-emerald-100">
        <i class="fa-solid fa-user-check text-4xl text-emerald-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">ยืนยันเช็คอิน</h2>
      <p class="text-sm text-gray-500 mb-5">กรุณาตรวจสอบข้อมูลก่อนยืนยัน</p>

      <div class="bg-gray-50 rounded-2xl p-4 text-left space-y-2 mb-6 border border-gray-100">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-emerald-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-user text-emerald-600 text-xs"></i>
          </div>
          <div>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">ชื่อ</p>
            <p class="font-black text-gray-800 text-sm"><?= htmlspecialchars($user['full_name']) ?></p>
          </div>
        </div>
        <?php if (!empty($user['student_personnel_id'])): ?>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-gray-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-id-card text-gray-500 text-xs"></i>
          </div>
          <div>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">รหัส</p>
            <p class="font-bold text-gray-700 text-sm"><?= htmlspecialchars($user['student_personnel_id']) ?></p>
          </div>
        </div>
        <?php endif; ?>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-calendar text-blue-600 text-xs"></i>
          </div>
          <div>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">กิจกรรม</p>
            <p class="font-bold text-gray-700 text-sm"><?= htmlspecialchars($campaign['title']) ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-amber-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-clock text-amber-600 text-xs"></i>
          </div>
          <div>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">รอบเวลา</p>
            <p class="font-bold text-gray-700 text-sm">
              <?= fmt_date_c($slot['slot_date']) ?>
              · <?= substr($slot['start_time'],0,5) ?>–<?= substr($slot['end_time'],0,5) ?>
            </p>
          </div>
        </div>
      </div>

      <form method="POST" action="checkin_campaign.php?campaign=<?= $campaignId ?>&token=<?= urlencode($token) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="booking_id" value="<?= (int)$slot['id'] ?>">
        <button type="submit"
                class="w-full py-3.5 rounded-2xl font-black text-sm text-white transition-all active:scale-95"
                style="background:linear-gradient(135deg,#2e9e63,#4ade80)">
          <i class="fa-solid fa-circle-check mr-2"></i> ยืนยันเช็คอิน
        </button>
      </form>
    </div>

    <?php elseif ($result === 'success'): ?>
    <!-- 🎉 SUCCESS -->
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-emerald-100">
        <i class="fa-solid fa-circle-check text-4xl text-emerald-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">เช็คอินสำเร็จ!</h2>
      <p class="text-sm text-gray-500 mb-5">ระบบบันทึกการเข้าร่วมของคุณแล้ว</p>

      <div class="bg-gray-50 rounded-2xl p-4 text-left space-y-2 mb-6 border border-gray-100">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-emerald-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-user text-emerald-600 text-xs"></i>
          </div>
          <div>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">ชื่อ</p>
            <p class="font-black text-gray-800 text-sm"><?= htmlspecialchars($user['full_name']) ?></p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-calendar text-blue-600 text-xs"></i>
          </div>
          <div>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">กิจกรรม</p>
            <p class="font-bold text-gray-700 text-sm"><?= htmlspecialchars($campaign['title']) ?></p>
          </div>
        </div>
        <?php if ($slot): ?>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-amber-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-clock text-amber-600 text-xs"></i>
          </div>
          <div>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">รอบเวลา</p>
            <p class="font-bold text-gray-700 text-sm">
              <?= fmt_date_c($slot['slot_date']) ?>
              · <?= substr($slot['start_time'],0,5) ?>–<?= substr($slot['end_time'],0,5) ?>
            </p>
          </div>
        </div>
        <?php endif; ?>
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 bg-purple-100 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-stamp text-purple-600 text-xs"></i>
          </div>
          <div>
            <p class="text-[10px] text-gray-400 font-bold uppercase tracking-wider">เวลาเช็คอิน</p>
            <p class="font-bold text-gray-700 text-sm"><?= date('H:i น.', strtotime($booking['attended_at'])) ?></p>
          </div>
        </div>
      </div>

      <a href="my_bookings.php"
         class="block w-full py-3 rounded-2xl font-black text-sm text-white text-center transition-all"
         style="background:linear-gradient(135deg,#2e9e63,#4ade80)">
        ดูการจองของฉัน
      </a>
    </div>

    <?php elseif ($result === 'already'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-blue-100">
        <i class="fa-solid fa-clock-rotate-left text-4xl text-blue-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">เช็คอินแล้ว</h2>
      <p class="text-sm text-gray-500 mb-5">คุณเช็คอินกิจกรรมนี้ไปแล้ว</p>
      <div class="bg-blue-50 rounded-2xl p-4 border border-blue-100 mb-6 text-sm text-blue-800 font-bold">
        <i class="fa-solid fa-circle-info mr-2"></i>
        เวลาเช็คอินเดิม: <?= date('d/m/Y H:i น.', strtotime($booking['attended_at'])) ?>
      </div>
      <a href="my_bookings.php"
         class="block w-full py-3 rounded-2xl font-black text-sm text-white text-center"
         style="background:#3b82f6">
        ดูการจองของฉัน
      </a>
    </div>

    <?php elseif ($result === 'no_booking'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-red-100">
        <i class="fa-solid fa-user-xmark text-4xl text-red-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">ไม่พบการจอง</h2>
      <p class="text-sm text-gray-500 mb-5">คุณไม่มีการจองในกิจกรรมนี้สำหรับวันนี้</p>
      <?php if ($campaign): ?>
      <div class="bg-gray-50 rounded-2xl p-4 border border-gray-100 text-sm text-gray-600 mb-6">
        <p class="font-bold"><?= htmlspecialchars($campaign['title']) ?></p>
      </div>
      <?php endif; ?>
      <a href="booking_campaign.php"
         class="block w-full py-3 rounded-2xl font-black text-sm text-white text-center"
         style="background:#ef4444">
        จองกิจกรรม
      </a>
    </div>

    <?php elseif ($result === 'not_logged_in'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-amber-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-amber-100">
        <i class="fa-brands fa-line text-4xl text-green-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">เข้าสู่ระบบก่อน</h2>
      <p class="text-sm text-gray-500 mb-5">กรุณา Login ผ่าน LINE เพื่อยืนยันตัวตนก่อนเช็คอิน</p>
      <?php if ($campaign): ?>
      <div class="bg-gray-50 rounded-2xl p-3 border border-gray-100 text-xs text-gray-500 mb-6">
        <span class="font-bold text-gray-700"><?= htmlspecialchars($campaign['title']) ?></span>
      </div>
      <?php endif; ?>
      <a href="line_login.php"
         class="flex items-center justify-center gap-2 w-full py-3 rounded-2xl font-black text-sm text-white transition-all"
         style="background:#06c755">
        <i class="fa-brands fa-line text-lg"></i> เข้าสู่ระบบด้วย LINE
      </a>
    </div>

    <?php elseif ($result === 'qr_disabled'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-gray-200">
        <i class="fa-solid fa-qrcode text-4xl text-gray-400"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">QR ปิดใช้งาน</h2>
      <p class="text-sm text-gray-500">ขณะนี้การเช็คอินด้วย QR Code ถูกปิดไว้ชั่วคราว<br>กรุณาติดต่อเจ้าหน้าที่</p>
    </div>

    <?php else: ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-orange-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-orange-100">
        <i class="fa-solid fa-triangle-exclamation text-4xl text-orange-400"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">QR ไม่ถูกต้อง</h2>
      <p class="text-sm text-gray-500">QR Code นี้ไม่ถูกต้องหรือหมดอายุแล้ว<br>กรุณาสแกน QR Code ใหม่จากจุดเช็คอิน</p>
    </div>
    <?php endif; ?>

  </div>

  <p class="text-center text-xs text-gray-400 mt-5">RSU Medical Clinic · ระบบเช็คอินอัตโนมัติ</p>
</div>

</body>
</html>
