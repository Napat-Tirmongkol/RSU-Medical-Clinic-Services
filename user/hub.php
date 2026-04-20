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
$user             = null;
$upcomingBookings = [];
$activeBorrows    = [];

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

    // Active borrows (optional — ถ้าตารางยังไม่มีจะ skip)
    try {
        $s = $pdo->prepare("
            SELECT br.id, bc.name AS category_name, bi.name AS item_name, br.due_date
            FROM borrow_records br
            JOIN borrow_items      bi ON br.item_id = bi.id
            JOIN borrow_categories bc ON bi.type_id = bc.id
            WHERE br.borrower_student_id = :id
              AND br.status IN ('borrowed','approved')
            ORDER BY br.due_date ASC
            LIMIT 3
        ");
        $s->execute([':id' => $userId]);
        $activeBorrows = $s->fetchAll();
    } catch (PDOException) {}

} catch (PDOException $e) {
    error_log('Hub DB error: ' . $e->getMessage());
}

$statusMap   = ['student' => 'นักศึกษา', 'faculty' => 'อาจารย์', 'staff' => 'เจ้าหน้าที่', 'other' => 'บุคคลทั่วไป'];
$statusLabel = $statusMap[$user['status'] ?? ''] ?? ($user['status'] ?? 'ผู้ใช้');
$displayName = trim(($user['prefix'] ?? '') . ' ' . ($user['full_name'] ?? 'ผู้ใช้'));

$hour     = (int)date('H');
$greeting = $hour < 12 ? 'อรุณสวัสดิ์' : ($hour < 17 ? 'สวัสดีตอนบ่าย' : 'สวัสดีตอนเย็น');

$linePicture = $_SESSION['line_picture'] ?? $_SESSION['line_picture_url'] ?? '';

require_once __DIR__ . '/../includes/header.php';
render_header('RSU Medical Hub');
?>

<div style="display:flex;flex-direction:column;min-height:100%;padding-bottom:80px">

  <!-- ── Hero Header ───────────────────────────────────────────────────────── -->
  <div style="background:linear-gradient(135deg,#003fa3 0%,#0052CC 50%,#1a6de0 100%);padding:48px 20px 24px;position:relative;overflow:hidden">

    <!-- decorative blobs -->
    <div style="position:absolute;top:-50px;right:-50px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,.06);pointer-events:none"></div>
    <div style="position:absolute;bottom:-30px;left:-30px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none"></div>

    <!-- brand row -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;position:relative">
      <div style="display:flex;align-items:center;gap:6px">
        <i class="fa-solid fa-heart-pulse" style="color:rgba(255,255,255,.7);font-size:12px"></i>
        <span style="color:rgba(255,255,255,.7);font-size:11px;font-weight:800;letter-spacing:.12em;text-transform:uppercase">RSU Medical Hub</span>
      </div>
      <a href="logout.php" style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;text-decoration:none">
        <i class="fa-solid fa-power-off" style="color:#fff;font-size:12px"></i>
      </a>
    </div>

    <!-- profile row -->
    <div style="display:flex;align-items:center;gap:14px;position:relative">
      <!-- avatar -->
      <div style="width:58px;height:58px;border-radius:18px;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.35);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center">
        <?php if ($linePicture !== ''): ?>
          <img src="<?= htmlspecialchars($linePicture) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
          <i class="fa-solid fa-user" style="color:rgba(255,255,255,.7);font-size:22px"></i>
        <?php endif; ?>
      </div>
      <!-- name/status -->
      <div style="flex:1;min-width:0">
        <p style="color:rgba(255,255,255,.65);font-size:11px;font-weight:600;margin:0 0 3px"><?= htmlspecialchars($greeting) ?></p>
        <h1 style="color:#fff;font-size:18px;font-weight:900;margin:0 0 8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2"><?= htmlspecialchars($displayName) ?></h1>
        <span style="background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.28);color:#fff;font-size:10px;font-weight:700;padding:3px 11px;border-radius:20px;display:inline-block">
          <?= htmlspecialchars($statusLabel) ?>
        </span>
      </div>
    </div>

    <!-- stats strip -->
    <div style="background:rgba(255,255,255,.13);border-radius:14px;display:flex;margin-top:20px;position:relative">
      <div style="flex:1;text-align:center;padding:12px 0;border-right:1px solid rgba(255,255,255,.15)">
        <div style="color:#fff;font-size:22px;font-weight:900;line-height:1"><?= count($upcomingBookings) ?></div>
        <div style="color:rgba(255,255,255,.65);font-size:10px;font-weight:600;margin-top:3px">นัดหมายที่รอ</div>
      </div>
      <div style="flex:1;text-align:center;padding:12px 0">
        <div style="color:#fff;font-size:22px;font-weight:900;line-height:1"><?= count($activeBorrows) ?></div>
        <div style="color:rgba(255,255,255,.65);font-size:10px;font-weight:600;margin-top:3px">รายการยืม</div>
      </div>
    </div>
  </div>

  <!-- ── Content ──────────────────────────────────────────────────────────── -->
  <div style="flex:1;padding:16px;display:flex;flex-direction:column;gap:14px">

    <!-- ── นัดหมายที่กำลังมา ─────────────────────────────────────────────── -->
    <div style="background:#fff;border-radius:20px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid #f1f5f9">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:30px;height:30px;border-radius:9px;background:#EFF6FF;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fa-solid fa-calendar-check" style="color:#3B82F6;font-size:11px"></i>
          </div>
          <span style="font-size:13px;font-weight:800;color:#0f172a">นัดหมายที่กำลังมา</span>
        </div>
        <a href="my_bookings.php" style="color:#3B82F6;font-size:11px;font-weight:700;text-decoration:none">ดูทั้งหมด →</a>
      </div>

      <div style="padding:12px 16px">
        <?php if (empty($upcomingBookings)): ?>
          <div style="display:flex;flex-direction:column;align-items:center;padding:20px 0;gap:8px">
            <i class="fa-regular fa-calendar" style="font-size:32px;color:#e2e8f0"></i>
            <p style="font-size:12px;color:#94a3b8;font-weight:600;margin:0">ยังไม่มีนัดหมายที่กำลังมา</p>
            <a href="booking_campaign.php" style="background:#3B82F6;color:#fff;font-size:11px;font-weight:700;padding:8px 20px;border-radius:10px;text-decoration:none;margin-top:4px">
              + จองนัดหมายใหม่
            </a>
          </div>
        <?php else: ?>
          <?php foreach ($upcomingBookings as $appt): ?>
            <a href="my_bookings.php" style="text-decoration:none;display:block;margin-bottom:8px">
              <div style="background:#F8FAFF;border:1px solid #DBEAFE;border-radius:13px;padding:12px 14px">
                <div style="font-size:13px;font-weight:700;color:#1e293b;margin-bottom:7px;line-height:1.35">
                  <?= htmlspecialchars($appt['title']) ?>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;flex-wrap:wrap">
                  <div style="display:flex;align-items:center;gap:5px;color:#64748b;font-size:11px">
                    <i class="fa-regular fa-clock"></i>
                    <?= hub_fmt_date($appt['slot_date'], $thaiMonths) ?>
                    &middot;
                    <?= substr($appt['start_time'],0,5) ?>–<?= substr($appt['end_time'],0,5) ?> น.
                  </div>
                  <?php if ($appt['status'] === 'confirmed'): ?>
                    <span style="background:#DCFCE7;color:#16A34A;font-size:10px;font-weight:800;padding:3px 9px;border-radius:6px;white-space:nowrap">ยืนยันแล้ว</span>
                  <?php else: ?>
                    <span style="background:#FEF9C3;color:#A16207;font-size:10px;font-weight:800;padding:3px 9px;border-radius:6px;white-space:nowrap">รอยืนยัน</span>
                  <?php endif; ?>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── อุปกรณ์ที่ยืมอยู่ ──────────────────────────────────────────────── -->
    <div style="background:#fff;border-radius:20px;box-shadow:0 2px 16px rgba(0,0,0,.06);overflow:hidden">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px 12px;border-bottom:1px solid #f1f5f9">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:30px;height:30px;border-radius:9px;background:#FFF7ED;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fa-solid fa-boxes-stacked" style="color:#F97316;font-size:11px"></i>
          </div>
          <span style="font-size:13px;font-weight:800;color:#0f172a">อุปกรณ์ที่ยืมอยู่</span>
        </div>
        <a href="../archive/e_Borrow/" style="color:#F97316;font-size:11px;font-weight:700;text-decoration:none">ดูทั้งหมด →</a>
      </div>

      <div style="padding:12px 16px">
        <?php if (empty($activeBorrows)): ?>
          <div style="display:flex;flex-direction:column;align-items:center;padding:20px 0;gap:8px">
            <i class="fa-solid fa-box-open" style="font-size:32px;color:#e2e8f0"></i>
            <p style="font-size:12px;color:#94a3b8;font-weight:600;margin:0">ไม่มีรายการยืมอุปกรณ์</p>
            <a href="../archive/e_Borrow/" style="background:#F97316;color:#fff;font-size:11px;font-weight:700;padding:8px 20px;border-radius:10px;text-decoration:none;margin-top:4px">
              ยืมอุปกรณ์
            </a>
          </div>
        <?php else: ?>
          <?php foreach ($activeBorrows as $borrow): ?>
            <?php
              $daysLeft = (int)ceil((strtotime($borrow['due_date']) - time()) / 86400);
              if ($daysLeft < 0) {
                  $bg = '#FEF2F2'; $border = '#FECACA'; $textColor = '#DC2626';
                  $dueText = 'เกินกำหนด ' . abs($daysLeft) . ' วัน';
              } elseif ($daysLeft <= 2) {
                  $bg = '#FFF7ED'; $border = '#FED7AA'; $textColor = '#EA580C';
                  $dueText = 'คืนภายใน ' . $daysLeft . ' วัน';
              } else {
                  $bg = '#F0FDF4'; $border = '#BBF7D0'; $textColor = '#16A34A';
                  $dueText = 'คืนภายใน ' . $daysLeft . ' วัน';
              }
            ?>
            <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:13px;padding:12px 14px;margin-bottom:8px;display:flex;align-items:center;gap:12px">
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:700;color:#1e293b;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= htmlspecialchars($borrow['item_name']) ?>
                </div>
                <div style="font-size:11px;color:#94a3b8"><?= htmlspecialchars($borrow['category_name']) ?></div>
              </div>
              <span style="color:<?= $textColor ?>;font-size:11px;font-weight:800;white-space:nowrap;flex-shrink:0"><?= $dueText ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── บริการด่วน ─────────────────────────────────────────────────────── -->
    <div>
      <p style="font-size:11px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin:0 0 10px">บริการด่วน</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">

        <a href="booking_campaign.php" style="text-decoration:none;background:#fff;border-radius:18px;padding:16px 14px;box-shadow:0 2px 16px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:12px">
          <div style="width:44px;height:44px;background:linear-gradient(135deg,#0052CC,#338ef7);border-radius:14px;display:flex;align-items:center;justify-content:center">
            <i class="fa-solid fa-syringe" style="color:#fff;font-size:17px"></i>
          </div>
          <div>
            <div style="font-size:13px;font-weight:800;color:#1e293b">นัดหมายสุขภาพ</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px">จอง / ดูประวัติ</div>
          </div>
        </a>

        <a href="../archive/e_Borrow/" style="text-decoration:none;background:#fff;border-radius:18px;padding:16px 14px;box-shadow:0 2px 16px rgba(0,0,0,.06);display:flex;flex-direction:column;gap:12px">
          <div style="width:44px;height:44px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:14px;display:flex;align-items:center;justify-content:center">
            <i class="fa-solid fa-boxes-stacked" style="color:#fff;font-size:16px"></i>
          </div>
          <div>
            <div style="font-size:13px;font-weight:800;color:#1e293b">ยืมอุปกรณ์</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px">e-Borrow</div>
          </div>
        </a>

      </div>
    </div>

  </div><!-- /content -->
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
render_footer();
?>
