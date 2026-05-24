<?php
// admin/ajax/ajax_add_walkin.php
// Add a walk-in booking (status=completed, attended_at=NOW, is_walk_in=1)
// Two modes:
//   mode=search    → search sys_users for autocomplete
//   mode=create    → create user (if needed) + booking
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    exit;
}
validate_csrf_or_die();

$pdo  = db();
$mode = $_POST['mode'] ?? '';

// ── Mode: list slots for a campaign ─────────────────────────────────
if ($mode === 'slots') {
    $cid = (int)($_POST['campaign_id'] ?? 0);
    if ($cid <= 0) { echo json_encode(['status'=>'ok','results'=>[]]); exit; }
    $stmt = $pdo->prepare("
        SELECT s.id, s.slot_date, s.start_time, s.end_time, s.max_capacity,
               (SELECT COUNT(*) FROM camp_bookings b
                  WHERE b.slot_id = s.id
                    AND b.status IN ('booked','confirmed','completed')) AS used
        FROM camp_slots s
        WHERE s.campaign_id = :cid
          AND s.slot_date >= CURDATE() - INTERVAL 1 DAY
        ORDER BY s.slot_date ASC, s.start_time ASC
        LIMIT 50
    ");
    $stmt->execute([':cid'=>$cid]);
    echo json_encode(['status'=>'ok','results'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── Mode: search existing sys_users ─────────────────────────────────
if ($mode === 'search') {
    $q = trim((string)($_POST['q'] ?? ''));
    if (mb_strlen($q) < 2) { echo json_encode(['status'=>'ok','results'=>[]]); exit; }
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT id, student_personnel_id, full_name, phone_number
        FROM sys_users
        WHERE student_personnel_id LIKE :q1
           OR full_name LIKE :q2
           OR phone_number LIKE :q3
        ORDER BY (student_personnel_id = :exact) DESC, full_name ASC
        LIMIT 20
    ");
    $stmt->execute([':q1'=>$like, ':q2'=>$like, ':q3'=>$like, ':exact'=>$q]);
    echo json_encode(['status'=>'ok','results'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ── Mode: create walk-in booking ────────────────────────────────────
// Ensure column exists (self-heal)
try { $pdo->exec("ALTER TABLE camp_bookings ADD COLUMN is_walk_in TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException) {}
try { $pdo->exec("ALTER TABLE camp_bookings ADD INDEX idx_walk_in (is_walk_in)"); } catch (PDOException) {}

$campaignId = (int)($_POST['campaign_id'] ?? 0);
$slotId     = (int)($_POST['slot_id'] ?? 0);
$userId     = (int)($_POST['user_id'] ?? 0);  // 0 → create new
$forceOver  = !empty($_POST['force_over_capacity']);

if ($campaignId <= 0 || $slotId <= 0) {
    echo json_encode(['status'=>'error','message'=>'campaign_id และ slot_id ต้องระบุ']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Validate campaign + slot
    $cs = $pdo->prepare("SELECT c.id AS cid, c.title, c.total_capacity, s.id AS sid, s.max_capacity, s.slot_date
                          FROM camp_list c
                          JOIN camp_slots s ON s.campaign_id = c.id
                          WHERE c.id = :c AND s.id = :s LIMIT 1");
    $cs->execute([':c'=>$campaignId, ':s'=>$slotId]);
    $info = $cs->fetch(PDO::FETCH_ASSOC);
    if (!$info) {
        $pdo->rollBack();
        echo json_encode(['status'=>'error','message'=>'แคมเปญหรือ slot ไม่ถูกต้อง']);
        exit;
    }

    // Get-or-create user
    if ($userId > 0) {
        $u = $pdo->prepare("SELECT id, student_personnel_id, full_name FROM sys_users WHERE id = :id");
        $u->execute([':id'=>$userId]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $pdo->rollBack();
            echo json_encode(['status'=>'error','message'=>'ไม่พบ user ที่เลือก']);
            exit;
        }
    } else {
        // Create new
        $sid   = trim((string)($_POST['student_personnel_id'] ?? ''));
        $name  = trim((string)($_POST['full_name'] ?? ''));
        $phone = trim((string)($_POST['phone_number'] ?? ''));
        if ($sid === '' || $name === '') {
            $pdo->rollBack();
            echo json_encode(['status'=>'error','message'=>'รหัสและชื่อต้องระบุ']);
            exit;
        }
        // Idempotent — if a user with this sid exists already, reuse
        $chk = $pdo->prepare("SELECT id, full_name FROM sys_users WHERE student_personnel_id = :sid LIMIT 1");
        $chk->execute([':sid'=>$sid]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $user = $existing + ['student_personnel_id' => $sid];
        } else {
            $ins = $pdo->prepare("INSERT INTO sys_users
                (student_personnel_id, full_name, phone_number, status, created_at)
                VALUES (:sid, :name, :phone, 'active', NOW())");
            $ins->execute([':sid'=>$sid, ':name'=>$name, ':phone'=>$phone ?: null]);
            $newId = (int)$pdo->lastInsertId();
            $user = ['id'=>$newId, 'student_personnel_id'=>$sid, 'full_name'=>$name];
        }
    }

    // Capacity check
    $cap = $pdo->prepare("SELECT COUNT(*) FROM camp_bookings
                          WHERE slot_id = :s
                            AND status IN ('booked','confirmed','completed')");
    $cap->execute([':s'=>$slotId]);
    $used = (int)$cap->fetchColumn();
    if ($used >= (int)$info['max_capacity'] && !$forceOver) {
        $pdo->rollBack();
        echo json_encode([
            'status'         => 'over_capacity',
            'message'        => "Slot เต็มแล้ว ({$used}/{$info['max_capacity']}) — ต้องการเพิ่มเกินโควต้าหรือไม่?",
            'used'           => $used,
            'max'            => (int)$info['max_capacity'],
        ]);
        exit;
    }

    // Prevent double-booking of same user in same campaign (active or attended)
    $dup = $pdo->prepare("SELECT id, status FROM camp_bookings
                           WHERE student_id = :uid AND campaign_id = :cid
                             AND status IN ('booked','confirmed','completed')
                           LIMIT 1");
    $dup->execute([':uid'=>$user['id'], ':cid'=>$campaignId]);
    if ($exists = $dup->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        echo json_encode([
            'status'  => 'error',
            'message' => "user นี้มี booking ในแคมเปญแล้ว (status: {$exists['status']})",
        ]);
        exit;
    }

    // Insert as completed walk-in
    $ins = $pdo->prepare("INSERT INTO camp_bookings
        (student_id, campaign_id, slot_id, status, attended_at, is_walk_in, created_at)
        VALUES (:uid, :cid, :sid, 'completed', NOW(), 1, NOW())");
    $ins->execute([':uid'=>$user['id'], ':cid'=>$campaignId, ':sid'=>$slotId]);
    $bookingId = (int)$pdo->lastInsertId();

    if (function_exists('log_activity')) {
        log_activity('add_walkin',
            "Walk-in: {$user['full_name']} ({$user['student_personnel_id']}) → " . ($info['title'] ?? "campaign #$campaignId")
        );
    }

    $pdo->commit();
    echo json_encode([
        'status'     => 'success',
        'message'    => "เพิ่ม walk-in เรียบร้อย",
        'booking_id' => $bookingId,
        'user'       => $user,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status'=>'error','message'=>'DB error: ' . $e->getMessage()]);
}
