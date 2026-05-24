<?php
// user/walkin.php — Walk-in registration entry page (scanned from poster QR)
//
// Flow guard:
//   1. Verify HMAC token (qr:walkin:{cid})
//   2. Verify campaign exists + walkin_enabled = 1 + status='active' + not expired
//   3. Check LINE session → if missing, redirect to line_login.php (returns here)
//   4. Check sys_users profile complete → if missing, redirect to profile.php
//      with redirect_back=walkin.php?cid=N&t=TOKEN
//   5. Check duplicate booking (this user × this campaign, active+completed) → reject
//   6. Pick today's available slot (FIFO by start_time, where capacity left + time not passed)
//      → if none, show "no slot available" with link to booking_campaign.php
//   7. Render confirm form → POST to ajax_walkin_submit.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

$pdo        = db();
$campaignId = (int)($_GET['cid'] ?? 0);
$token      = trim((string)($_GET['t'] ?? ''));
$success    = isset($_GET['success']);

function walkin_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . $dir;
}

// ── Verify token ──────────────────────────────────────────────────────────────
$validToken = hash_hmac('sha256', "qr:walkin:{$campaignId}", QR_SLOT_SECRET);
$tokenOk    = ($campaignId > 0 && $token !== '' && hash_equals($validToken, $token));

// ── States: confirm | success | already | no_slot | not_logged_in | no_profile
//           | invalid | walkin_disabled | campaign_closed | full
$state    = null;
$campaign = null;
$slot     = null;
$booking  = null;
$user     = null;
$slotsAvailable = [];

if (!$tokenOk) {
    $state = 'invalid';
} else {
    // Auto-migrate walkin_enabled column (safe — runs once per request, fast no-op after)
    try {
        $pdo->exec("ALTER TABLE camp_list ADD COLUMN IF NOT EXISTS walkin_enabled TINYINT(1) NOT NULL DEFAULT 0");
    } catch (PDOException) {}

    try {
        $st = $pdo->prepare("
            SELECT id, title, description, type, status, total_capacity, is_auto_approve,
                   walkin_enabled, available_from, available_until, contact_phone,
                   what_to_bring, prerequisites, max_per_user, room_id,
                   (SELECT COUNT(*) FROM camp_bookings WHERE campaign_id = camp_list.id
                      AND status IN ('booked','confirmed','completed')) AS used
            FROM camp_list
            WHERE id = :id
            LIMIT 1
        ");
        $st->execute([':id' => $campaignId]);
        $campaign = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {}

    if (!$campaign) {
        $state = 'invalid';
    } elseif ((int)($campaign['walkin_enabled'] ?? 0) !== 1) {
        $state = 'walkin_disabled';
    } elseif ($campaign['status'] !== 'active'
              || ($campaign['available_from']  && $campaign['available_from']  > date('Y-m-d'))
              || ($campaign['available_until'] && $campaign['available_until'] < date('Y-m-d'))) {
        $state = 'campaign_closed';
    } elseif ((int)$campaign['used'] >= (int)$campaign['total_capacity']) {
        $state = 'full';
    } else {
        // Check LINE session
        $lineUserId = $_SESSION['line_user_id'] ?? '';
        if ($lineUserId === '') {
            $state = 'not_logged_in';
        } else {
            // Fetch user profile
            try {
                $stu = $pdo->prepare("
                    SELECT id, full_name, prefix, first_name, last_name, citizen_id,
                           phone_number, status, student_personnel_id, email,
                           date_of_birth, gender
                    FROM sys_users
                    WHERE line_user_id = :lid OR line_user_id_new = :lid2
                    LIMIT 1
                ");
                $stu->execute([':lid' => $lineUserId, ':lid2' => $lineUserId]);
                $user = $stu->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException) {}

            // Profile completeness check (same fields validate_csrf_or_die uses in save_profile.php)
            $profileComplete = $user
                && !empty($user['full_name'])
                && !empty($user['first_name'])
                && !empty($user['last_name'])
                && !empty($user['citizen_id'])
                && !empty($user['phone_number'])
                && !empty($user['status']);

            if (!$profileComplete) {
                $state = 'no_profile';
            } else {
                // Duplicate-booking check (this user × this campaign, any active or completed status)
                try {
                    $stx = $pdo->prepare("
                        SELECT b.id, b.status, b.attended_at, b.created_at,
                               s.slot_date, s.start_time, s.end_time
                        FROM camp_bookings b
                        LEFT JOIN camp_slots s ON b.slot_id = s.id
                        WHERE b.student_id  = :uid
                          AND b.campaign_id = :cid
                          AND b.status IN ('booked','confirmed','completed')
                        ORDER BY b.created_at DESC
                        LIMIT 1
                    ");
                    $stx->execute([':uid' => (int)$user['id'], ':cid' => $campaignId]);
                    $dup = $stx->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException) { $dup = null; }

                if ($dup) {
                    $booking = $dup;
                    $state   = 'already';
                } elseif ($success) {
                    // Edge case: success param but no booking → render generic success
                    $state = 'success';
                } else {
                    // Find today's available slots (capacity left + time not passed)
                    try {
                        $sts = $pdo->prepare("
                            SELECT s.id, s.slot_date, s.start_time, s.end_time, s.max_capacity,
                                   (SELECT COUNT(*) FROM camp_bookings b WHERE b.slot_id = s.id
                                      AND b.status IN ('booked','confirmed','completed')) AS used
                            FROM camp_slots s
                            WHERE s.campaign_id = :cid
                              AND s.slot_date   = CURDATE()
                              AND s.end_time    >= TIME(NOW())
                            ORDER BY s.start_time ASC
                        ");
                        $sts->execute([':cid' => $campaignId]);
                        foreach ($sts->fetchAll(PDO::FETCH_ASSOC) as $sl) {
                            if ((int)$sl['used'] < (int)$sl['max_capacity']) {
                                $slotsAvailable[] = $sl;
                            }
                        }
                    } catch (PDOException) {}

                    if (empty($slotsAvailable)) {
                        $state = 'no_slot';
                    } else {
                        // Default: pick the earliest still-available slot (FIFO by start_time)
                        $slot  = $slotsAvailable[0];
                        $state = 'confirm';
                    }
                }
            }
        }
    }
}

// ── Login redirect setup ─────────────────────────────────────────────────────
$returnUrl = walkin_base_url() . '/user/walkin.php?cid=' . $campaignId . '&t=' . urlencode($token);
if ($state === 'not_logged_in') {
    $_SESSION['checkin_return'] = $returnUrl;
}

// ── Profile redirect URL ─────────────────────────────────────────────────────
$profileRedirect = 'profile.php?redirect_back=' . urlencode('walkin.php?cid=' . $campaignId . '&t=' . $token);

// ── Helpers ──────────────────────────────────────────────────────────────────
function walkin_fmt_date(string $d): string {
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $parts  = explode('-', $d);
    if (count($parts) !== 3) return $d;
    return (int)$parts[2] . ' ' . ($months[(int)$parts[1]] ?? '') . ' ' . ((int)$parts[0] + 543);
}
function walkin_type_label(string $t): array {
    return match ($t) {
        'vaccine'      => ['ฉีดวัคซีน', '#16a34a', 'fa-syringe'],
        'training'     => ['อบรม/สัมมนา', '#7c3aed', 'fa-chalkboard-user'],
        'health_check' => ['ตรวจสุขภาพ', '#059669', 'fa-stethoscope'],
        default        => ['กิจกรรม', '#0ea5e9', 'fa-calendar-check'],
    };
}
$typeInfo = walkin_type_label((string)($campaign['type'] ?? ''));
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Walk-in Registration<?= $campaign ? ' · ' . htmlspecialchars($campaign['title']) : '' ?></title>
<link rel="icon" href="<?= defined('SITE_LOGO') && SITE_LOGO !== '' ? '../' . htmlspecialchars(SITE_LOGO, ENT_QUOTES, 'UTF-8') : '../favicon.ico' ?>">
<link rel="stylesheet" href="../assets/css/tailwind.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/css/rsufont.css">
<style>
  * { font-family: 'Sarabun', sans-serif; }
  body {
    background: linear-gradient(135deg,#fef3c7 0%,#fde68a 30%,#fef3c7 60%,#fff7ed 100%);
    min-height: 100vh;
  }
  @keyframes popIn {
    from { opacity: 0; transform: scale(.88) translateY(24px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
  }
  .pop-in { animation: popIn .45s cubic-bezier(.16,1,.3,1) both; }
  @keyframes pulseDot {
    0%, 100% { opacity: 1; }
    50%      { opacity: .4; }
  }
  .live-dot { animation: pulseDot 1.8s ease-in-out infinite; }
  .slot-card {
    transition: all .2s ease;
    cursor: pointer;
  }
  .slot-card:hover {
    border-color: #f59e0b;
    background: #fffbeb;
  }
  .slot-card.is-selected {
    border-color: #d97706;
    background: #fef3c7;
    box-shadow: 0 4px 16px rgba(217,119,6,.18);
  }
</style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">

<div class="w-full max-w-sm pop-in">

  <div class="text-center mb-5">
    <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl shadow-lg mb-2"
         style="background:linear-gradient(135deg,#d97706,#f59e0b)">
      <i class="fa-solid fa-person-walking text-white text-2xl"></i>
    </div>
    <p class="text-xs font-black uppercase tracking-widest text-amber-700">RSU Medical Clinic</p>
    <p class="text-[11px] text-amber-600 mt-0.5">
      <span class="inline-block w-1.5 h-1.5 bg-amber-500 rounded-full live-dot mr-1"></span>
      ลงทะเบียน Walk-in
    </p>
  </div>

  <div class="bg-white rounded-3xl shadow-xl overflow-hidden border border-amber-50">

  <?php if ($state === 'confirm'): ?>
    <!-- ✅ CONFIRM STEP — show user info + selected slot, allow slot change -->
    <div class="p-6">
      <div class="text-center mb-4">
        <div class="w-16 h-16 bg-amber-50 rounded-full flex items-center justify-center mx-auto mb-3 border-4 border-amber-100">
          <i class="fa-solid fa-clipboard-check text-3xl text-amber-500"></i>
        </div>
        <h2 class="text-xl font-black text-gray-900 mb-1">ยืนยันเข้าร่วม</h2>
        <p class="text-xs text-gray-500">ตรวจข้อมูลก่อนกดยืนยัน · ระบบจะบันทึก check-in ทันที</p>
      </div>

      <!-- Campaign info -->
      <div class="bg-gray-50 rounded-2xl p-3 border border-gray-100 mb-3">
        <div class="flex items-start gap-3">
          <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
               style="background:<?= $typeInfo[1] ?>1a;color:<?= $typeInfo[1] ?>">
            <i class="fa-solid <?= $typeInfo[2] ?>"></i>
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= $typeInfo[0] ?></p>
            <p class="font-black text-gray-900 text-sm leading-snug"><?= htmlspecialchars($campaign['title']) ?></p>
            <?php if (!empty($campaign['what_to_bring'])): ?>
            <p class="text-[11px] text-gray-500 mt-1">
              <i class="fa-solid fa-suitcase-medical mr-1 text-amber-500"></i>
              สิ่งที่ต้องเตรียม: <?= htmlspecialchars($campaign['what_to_bring']) ?>
            </p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- User info -->
      <div class="bg-emerald-50 rounded-2xl p-3 border border-emerald-100 mb-3">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-white rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-user-check text-emerald-600"></i>
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-[10px] font-bold uppercase tracking-wider text-emerald-700">ผู้เข้าร่วม</p>
            <p class="font-black text-gray-900 text-sm truncate"><?= htmlspecialchars($user['full_name']) ?></p>
            <?php if (!empty($user['student_personnel_id'])): ?>
            <p class="text-[11px] text-gray-500"><?= htmlspecialchars($user['student_personnel_id']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Slot picker (only if >1 slot) -->
      <?php if (count($slotsAvailable) > 1): ?>
      <div class="mb-3">
        <p class="text-[11px] font-bold uppercase tracking-wider text-gray-500 mb-2">เลือกรอบเวลา</p>
        <div class="space-y-2 max-h-60 overflow-y-auto">
          <?php foreach ($slotsAvailable as $i => $sl):
            $remain = max(0, (int)$sl['max_capacity'] - (int)$sl['used']);
          ?>
          <label class="slot-card flex items-center gap-3 p-3 rounded-xl border-2 border-gray-100 <?= $i === 0 ? 'is-selected' : '' ?>">
            <input type="radio" name="slot_id_picker" value="<?= (int)$sl['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> class="hidden">
            <div class="w-8 h-8 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fa-solid fa-clock text-amber-600 text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
              <p class="font-black text-gray-900 text-sm">
                <?= substr($sl['start_time'],0,5) ?> – <?= substr($sl['end_time'],0,5) ?> น.
              </p>
              <p class="text-[11px] text-gray-500">ว่าง <?= $remain ?> ที่นั่ง</p>
            </div>
            <i class="fa-solid fa-check text-amber-600 hidden check-mark"></i>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php else: ?>
      <!-- Single slot — auto-selected -->
      <div class="bg-amber-50 rounded-2xl p-3 border border-amber-100 mb-3">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-white rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-clock text-amber-600"></i>
          </div>
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-amber-700">รอบเวลา</p>
            <p class="font-black text-gray-900 text-sm">
              <?= walkin_fmt_date($slot['slot_date']) ?>
              · <?= substr($slot['start_time'],0,5) ?> – <?= substr($slot['end_time'],0,5) ?> น.
            </p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Submit form -->
      <form id="walkinForm" method="POST" action="ajax_walkin_submit.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
        <input type="hidden" name="cid"  value="<?= (int)$campaignId ?>">
        <input type="hidden" name="t"    value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="slot_id" id="walkinSlotId" value="<?= (int)$slot['id'] ?>">

        <button type="submit" id="walkinSubmitBtn"
                class="w-full py-3.5 rounded-2xl font-black text-sm text-white transition-all active:scale-95 shadow-lg"
                style="background:linear-gradient(135deg,#d97706,#f59e0b)">
          <i class="fa-solid fa-check-double mr-2"></i> ยืนยันลงทะเบียน
        </button>
      </form>
    </div>

  <?php elseif ($state === 'success'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-emerald-100">
        <i class="fa-solid fa-circle-check text-4xl text-emerald-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">ลงทะเบียนสำเร็จ!</h2>
      <p class="text-sm text-gray-500 mb-5">ระบบบันทึกการเข้าร่วมเรียบร้อย</p>
      <a href="my_bookings.php"
         class="block w-full py-3 rounded-2xl font-black text-sm text-white text-center"
         style="background:linear-gradient(135deg,#2e9e63,#4ade80)">
        ดูการจองของฉัน
      </a>
    </div>

  <?php elseif ($state === 'already'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-blue-100">
        <i class="fa-solid fa-clock-rotate-left text-4xl text-blue-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">ลงทะเบียนไว้แล้ว</h2>
      <p class="text-sm text-gray-500 mb-4">
        คุณมีการจองในกิจกรรมนี้แล้ว · สถานะ:
        <span class="font-bold text-blue-700">
          <?= match ($booking['status']) {
              'booked'    => 'รออนุมัติ',
              'confirmed' => 'ยืนยันแล้ว',
              'completed' => 'เข้าร่วมแล้ว',
              default     => htmlspecialchars($booking['status']),
          } ?>
        </span>
      </p>
      <?php if (!empty($booking['slot_date'])): ?>
      <div class="bg-blue-50 rounded-2xl p-4 border border-blue-100 mb-5 text-sm text-blue-800 font-bold text-left">
        <i class="fa-solid fa-calendar mr-2"></i>
        <?= walkin_fmt_date($booking['slot_date']) ?>
        <?php if (!empty($booking['start_time'])): ?>
          · <?= substr($booking['start_time'],0,5) ?>–<?= substr($booking['end_time'],0,5) ?> น.
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <a href="my_bookings.php"
         class="block w-full py-3 rounded-2xl font-black text-sm text-white text-center"
         style="background:#3b82f6">
        <i class="fa-solid fa-list-check mr-1"></i> ดูการจองของฉัน
      </a>
    </div>

  <?php elseif ($state === 'no_slot'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-orange-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-orange-100">
        <i class="fa-solid fa-calendar-xmark text-4xl text-orange-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">ไม่มีรอบเวลาวันนี้</h2>
      <p class="text-sm text-gray-500 mb-5">
        ขณะนี้รอบของวันนี้เต็มหมดหรือเลยเวลาแล้ว<br>
        คุณยังจองล่วงหน้าวันอื่นได้
      </p>
      <a href="booking_campaign.php?campaign=<?= $campaignId ?>"
         class="block w-full py-3 rounded-2xl font-black text-sm text-white text-center"
         style="background:linear-gradient(135deg,#f59e0b,#fbbf24)">
        <i class="fa-solid fa-calendar-plus mr-1"></i> จองล่วงหน้า
      </a>
    </div>

  <?php elseif ($state === 'full'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-red-100">
        <i class="fa-solid fa-users-slash text-4xl text-red-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">เต็มแล้ว</h2>
      <p class="text-sm text-gray-500 mb-5">
        กิจกรรมนี้มีผู้ลงทะเบียนเต็มโควต้าแล้ว<br>
        กรุณาติดต่อเจ้าหน้าที่หรือดูกิจกรรมอื่น
      </p>
      <a href="booking_campaign.php"
         class="block w-full py-3 rounded-2xl font-black text-sm text-white text-center"
         style="background:#ef4444">
        <i class="fa-solid fa-list mr-1"></i> ดูกิจกรรมอื่น
      </a>
    </div>

  <?php elseif ($state === 'no_profile'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-purple-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-purple-100">
        <i class="fa-solid fa-id-card-clip text-4xl text-purple-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">กรอกข้อมูลก่อน</h2>
      <p class="text-sm text-gray-500 mb-5">
        การลงทะเบียนครั้งแรก ต้องกรอกข้อมูลส่วนตัวก่อน<br>
        <span class="text-xs text-gray-400">(ใช้เวลาประมาณ 1 นาที)</span>
      </p>
      <a href="<?= htmlspecialchars($profileRedirect) ?>"
         class="block w-full py-3 rounded-2xl font-black text-sm text-white text-center"
         style="background:linear-gradient(135deg,#7c3aed,#a855f7)">
        <i class="fa-solid fa-arrow-right mr-1"></i> กรอกข้อมูลและลงทะเบียนต่อ
      </a>
    </div>

  <?php elseif ($state === 'not_logged_in'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-green-100">
        <i class="fa-brands fa-line text-4xl text-green-500"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">เข้าสู่ระบบก่อน</h2>
      <p class="text-sm text-gray-500 mb-5">
        ยืนยันตัวตนผ่าน LINE เพื่อความปลอดภัย
      </p>
      <?php if ($campaign): ?>
      <div class="bg-gray-50 rounded-2xl p-3 border border-gray-100 text-xs text-gray-500 mb-5">
        จะลงทะเบียนเข้า: <span class="font-bold text-gray-700"><?= htmlspecialchars($campaign['title']) ?></span>
      </div>
      <?php endif; ?>
      <a href="line_login.php"
         class="flex items-center justify-center gap-2 w-full py-3 rounded-2xl font-black text-sm text-white transition-all"
         style="background:#06c755">
        <i class="fa-brands fa-line text-lg"></i> เข้าสู่ระบบด้วย LINE
      </a>
    </div>

  <?php elseif ($state === 'walkin_disabled'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-gray-200">
        <i class="fa-solid fa-circle-pause text-4xl text-gray-400"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">ยังไม่เปิด Walk-in</h2>
      <p class="text-sm text-gray-500">
        ขณะนี้ยังไม่ได้เปิดรับ Walk-in สำหรับกิจกรรมนี้<br>
        กรุณาติดต่อเจ้าหน้าที่หรือจองล่วงหน้า
      </p>
    </div>

  <?php elseif ($state === 'campaign_closed'): ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-gray-200">
        <i class="fa-solid fa-calendar-xmark text-4xl text-gray-400"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">กิจกรรมหมดเขต</h2>
      <p class="text-sm text-gray-500">
        กิจกรรมนี้ปิดรับสมัครหรือยังไม่ถึงวันเริ่มลงทะเบียน
      </p>
    </div>

  <?php else: ?>
    <div class="p-8 text-center">
      <div class="w-20 h-20 bg-orange-50 rounded-full flex items-center justify-center mx-auto mb-4 border-4 border-orange-100">
        <i class="fa-solid fa-triangle-exclamation text-4xl text-orange-400"></i>
      </div>
      <h2 class="text-2xl font-black text-gray-900 mb-1">QR ไม่ถูกต้อง</h2>
      <p class="text-sm text-gray-500">
        QR Code นี้ไม่ถูกต้องหรือหมดอายุ<br>
        กรุณาสแกน QR Code ใหม่จากโปสเตอร์
      </p>
    </div>
  <?php endif; ?>

  </div>

  <p class="text-center text-xs text-gray-400 mt-4">
    RSU Medical Clinic · ลงทะเบียน Walk-in อัตโนมัติ
  </p>
</div>

<?php if ($state === 'confirm' && count($slotsAvailable) > 1): ?>
<script>
  // Slot picker
  document.querySelectorAll('.slot-card').forEach(card => {
    card.addEventListener('click', () => {
      document.querySelectorAll('.slot-card').forEach(c => {
        c.classList.remove('is-selected');
        c.querySelector('.check-mark')?.classList.add('hidden');
      });
      card.classList.add('is-selected');
      card.querySelector('.check-mark')?.classList.remove('hidden');
      const radio = card.querySelector('input[type="radio"]');
      if (radio) {
        radio.checked = true;
        document.getElementById('walkinSlotId').value = radio.value;
      }
    });
  });
</script>
<?php endif; ?>

<?php if ($state === 'confirm'): ?>
<script>
  // Submit handler — show loading state, then submit
  document.getElementById('walkinForm').addEventListener('submit', function(e) {
    const btn = document.getElementById('walkinSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> กำลังบันทึก...';
  });
</script>
<?php endif; ?>

</body>
</html>
