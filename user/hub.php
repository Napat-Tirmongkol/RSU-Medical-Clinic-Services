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

<div style="display:flex;flex-direction:column;min-height:100%;">

  <!-- ── Header / Greeting ─────────────────────────────────────────────── -->
  <div style="background:linear-gradient(135deg,#0052CC 0%,#0070f3 100%);padding:24px 20px 44px;flex-shrink:0;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
      <span style="font-size:11px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.65);">RSU Medical Hub</span>
      <a href="logout.php" style="display:flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:rgba(255,255,255,.65);text-decoration:none;padding:5px 10px;background:rgba(255,255,255,.12);border-radius:20px;">
        <i class="fa-solid fa-right-from-bracket" style="font-size:11px;"></i> ออก
      </a>
    </div>
    <div style="display:flex;align-items:center;gap:14px;">
      <div style="width:50px;height:50px;border-radius:16px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;border:2px solid rgba(255,255,255,.3);">
        <i class="fa-solid fa-user" style="font-size:20px;color:#fff;"></i>
      </div>
      <div>
        <div style="color:#fff;font-size:17px;font-weight:800;line-height:1.25;">
          สวัสดี, <?= htmlspecialchars($displayName) ?> 👋
        </div>
        <div style="color:rgba(255,255,255,.7);font-size:12px;margin-top:4px;">
          <?= htmlspecialchars($statusLabel) ?>
          <?php if (!empty($user['student_personnel_id'])): ?>
            <span style="margin:0 5px;opacity:.5;">·</span>รหัส <?= htmlspecialchars($user['student_personnel_id']) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Cards (pulls up over header gradient) ─────────────────────────── -->
  <div style="flex:1;padding:0 14px;margin-top:-22px;">

    <!-- นัดหมายที่กำลังมา -->
    <div style="background:#fff;border-radius:20px;padding:18px;margin-bottom:14px;box-shadow:0 4px 24px rgba(0,0,0,.07);">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:9px;">
          <div style="width:30px;height:30px;background:#eff6ff;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-calendar-check" style="font-size:12px;color:#0052CC;"></i>
          </div>
          <span style="font-size:14px;font-weight:800;color:#0f172a;">นัดหมายที่กำลังมา</span>
        </div>
        <a href="my_bookings.php" style="font-size:11px;font-weight:700;color:#0052CC;text-decoration:none;">ดูทั้งหมด →</a>
      </div>

      <?php if (empty($upcomingBookings)): ?>
        <div style="text-align:center;padding:16px 0 8px;">
          <i class="fa-regular fa-calendar" style="font-size:32px;color:#e2e8f0;display:block;margin-bottom:8px;"></i>
          <p style="font-size:13px;color:#94a3b8;margin:0 0 14px;">ยังไม่มีนัดหมายที่กำลังมา</p>
          <a href="booking_campaign.php" style="display:inline-block;background:#0052CC;color:#fff;font-size:12px;font-weight:700;padding:9px 22px;border-radius:12px;text-decoration:none;">
            + จองนัดหมายใหม่
          </a>
        </div>
      <?php else: ?>
        <?php foreach ($upcomingBookings as $appt): ?>
          <a href="my_bookings.php" style="display:block;padding:11px 13px;background:#f8faff;border-radius:13px;margin-bottom:8px;text-decoration:none;border:1px solid #e8f0ff;">
            <div style="font-size:13px;font-weight:700;color:#1e293b;margin-bottom:4px;"><?= htmlspecialchars($appt['title']) ?></div>
            <div style="font-size:11px;color:#64748b;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
              <i class="fa-regular fa-clock"></i>
              <?= hub_fmt_date($appt['slot_date'], $thaiMonths) ?>
              &nbsp;·&nbsp;<?= substr($appt['start_time'],0,5) ?>–<?= substr($appt['end_time'],0,5) ?> น.
              <?php if ($appt['status'] === 'confirmed'): ?>
                <span style="margin-left:auto;background:#dcfce7;color:#16a34a;font-size:10px;font-weight:800;padding:2px 9px;border-radius:6px;">ยืนยันแล้ว</span>
              <?php else: ?>
                <span style="margin-left:auto;background:#fef9c3;color:#ca8a04;font-size:10px;font-weight:800;padding:2px 9px;border-radius:6px;">รอยืนยัน</span>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- อุปกรณ์ที่ยืมอยู่ -->
    <div style="background:#fff;border-radius:20px;padding:18px;margin-bottom:14px;box-shadow:0 4px 24px rgba(0,0,0,.07);">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:9px;">
          <div style="width:30px;height:30px;background:#fff7ed;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-box-open" style="font-size:12px;color:#f97316;"></i>
          </div>
          <span style="font-size:14px;font-weight:800;color:#0f172a;">อุปกรณ์ที่ยืมอยู่</span>
        </div>
        <a href="../e_Borrow/auth_bridge.php?to=history.php" style="font-size:11px;font-weight:700;color:#f97316;text-decoration:none;">ดูทั้งหมด →</a>
      </div>

      <?php if (empty($activeBorrows)): ?>
        <div style="text-align:center;padding:16px 0 8px;">
          <i class="fa-solid fa-box-open" style="font-size:32px;color:#e2e8f0;display:block;margin-bottom:8px;"></i>
          <p style="font-size:13px;color:#94a3b8;margin:0 0 14px;">ไม่มีรายการยืมอุปกรณ์</p>
          <a href="../e_Borrow/auth_bridge.php" style="display:inline-block;background:#f97316;color:#fff;font-size:12px;font-weight:700;padding:9px 22px;border-radius:12px;text-decoration:none;">
            ยืมอุปกรณ์
          </a>
        </div>
      <?php else: ?>
        <?php foreach ($activeBorrows as $borrow): ?>
          <?php
            $daysLeft = (int)ceil((strtotime($borrow['due_date']) - time()) / 86400);
            $urgColor = $daysLeft <= 2 ? '#ef4444' : ($daysLeft <= 5 ? '#f97316' : '#16a34a');
          ?>
          <div style="padding:11px 13px;background:#fff8f5;border-radius:13px;margin-bottom:8px;border:1px solid #ffe4cc;">
            <div style="font-size:13px;font-weight:700;color:#1e293b;margin-bottom:4px;"><?= htmlspecialchars($borrow['item_name']) ?></div>
            <div style="font-size:11px;color:#64748b;display:flex;align-items:center;justify-content:space-between;">
              <span><?= htmlspecialchars($borrow['category_name']) ?></span>
              <span style="color:<?= $urgColor ?>;font-weight:800;">คืนภายใน <?= $daysLeft ?> วัน</span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Quick Access -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
      <a href="booking_campaign.php" style="background:#fff;border-radius:20px;padding:18px 14px;text-decoration:none;box-shadow:0 4px 20px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:10px;">
        <div style="width:44px;height:44px;background:linear-gradient(135deg,#0052CC,#0070f3);border-radius:14px;display:flex;align-items:center;justify-content:center;">
          <i class="fa-solid fa-syringe" style="font-size:17px;color:#fff;"></i>
        </div>
        <div>
          <div style="font-size:13px;font-weight:800;color:#1e293b;">นัดหมายสุขภาพ</div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">จอง / ดูประวัติ</div>
        </div>
      </a>
      <a href="../e_Borrow/auth_bridge.php" style="background:#fff;border-radius:20px;padding:18px 14px;text-decoration:none;box-shadow:0 4px 20px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:10px;">
        <div style="width:44px;height:44px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:14px;display:flex;align-items:center;justify-content:center;">
          <i class="fa-solid fa-box-open" style="font-size:17px;color:#fff;"></i>
        </div>
        <div>
          <div style="font-size:13px;font-weight:800;color:#1e293b;">ยืมอุปกรณ์</div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;">e-Borrow</div>
        </div>
      </a>
    </div>

  </div><!-- /cards -->
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
render_footer();
?>
