<?php
// user/ajax_walkin_submit.php — Walk-in registration POST handler
//
// Auth chain: CSRF + LINE session + HMAC token + walkin_enabled + slot capacity + duplicate
// Concurrency: DB transaction with re-check inside (capacity + duplicate) → safe under race
// Side effects: INSERT camp_bookings(status='completed', attended_at=NOW()) + vaccination record + activity log + email
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/vaccination_helper.php';

// Fail-fast guards
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php', true, 303);
    exit;
}
validate_csrf_or_die();

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    header('Location: line_login.php', true, 303);
    exit;
}

$campaignId = (int)($_POST['cid']     ?? 0);
$slotId     = (int)($_POST['slot_id'] ?? 0);
$token      = trim((string)($_POST['t'] ?? ''));

// HMAC token verification — prevents direct POST without scanning QR
$validToken = hash_hmac('sha256', "qr:walkin:{$campaignId}", QR_SLOT_SECRET);
if (!$campaignId || !$slotId || $token === '' || !hash_equals($validToken, $token)) {
    walkin_redirect_err($campaignId, $token, 'invalid');
}

$pdo = db();

// Auto-migrate columns (idempotent, safe to call every request)
try {
    $pdo->exec("ALTER TABLE camp_list ADD COLUMN IF NOT EXISTS walkin_enabled TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException) {}
// is_walk_in flag matches admin/ajax/ajax_add_walkin.php — bookings UI uses it
// to render the "Walk-in" badge in admin/bookings.php
try { $pdo->exec("ALTER TABLE camp_bookings ADD COLUMN IF NOT EXISTS is_walk_in TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException) {}
try { $pdo->exec("ALTER TABLE camp_bookings ADD INDEX idx_walk_in (is_walk_in)"); } catch (PDOException) {}

try {
    // ── Lookup user (require complete profile) ─────────────────────────────
    $stu = $pdo->prepare("
        SELECT id, full_name, first_name, last_name, citizen_id, phone_number,
               status, student_personnel_id, email
        FROM sys_users
        WHERE line_user_id = :lid OR line_user_id_new = :lid2
        LIMIT 1
    ");
    $stu->execute([':lid' => $lineUserId, ':lid2' => $lineUserId]);
    $user = $stu->fetch(PDO::FETCH_ASSOC);

    if (!$user
        || empty($user['full_name'])
        || empty($user['first_name'])
        || empty($user['last_name'])
        || empty($user['citizen_id'])
        || empty($user['phone_number'])
        || empty($user['status'])) {
        // Profile incomplete — redirect to profile page
        header('Location: walkin.php?cid=' . $campaignId . '&t=' . urlencode($token), true, 303);
        exit;
    }
    $userId = (int)$user['id'];

    // ── Pre-check campaign (cheap, before transaction) ─────────────────────
    $stc = $pdo->prepare("
        SELECT id, title, type, status, total_capacity, walkin_enabled,
               available_from, available_until
        FROM camp_list
        WHERE id = :id
        LIMIT 1
    ");
    $stc->execute([':id' => $campaignId]);
    $campaign = $stc->fetch(PDO::FETCH_ASSOC);

    if (!$campaign)                              walkin_redirect_err($campaignId, $token, 'invalid');
    if ((int)$campaign['walkin_enabled'] !== 1)  walkin_redirect_err($campaignId, $token, 'walkin_disabled');
    // 'active' + 'full' both pass — walk-in is the overflow lane when status='full'
    if (!in_array($campaign['status'], ['active', 'full'], true))
                                                 walkin_redirect_err($campaignId, $token, 'campaign_closed');
    if ($campaign['available_from']  && $campaign['available_from']  > date('Y-m-d'))
                                                 walkin_redirect_err($campaignId, $token, 'campaign_closed');
    if ($campaign['available_until'] && $campaign['available_until'] < date('Y-m-d'))
                                                 walkin_redirect_err($campaignId, $token, 'campaign_closed');

    // ── Transaction: re-check capacity + duplicate, then INSERT ─────────────
    $pdo->beginTransaction();
    try {
        // Re-check duplicate inside transaction (race-safe)
        $stdp = $pdo->prepare("
            SELECT id FROM camp_bookings
            WHERE student_id = :uid AND campaign_id = :cid
              AND status IN ('booked','confirmed','completed')
            LIMIT 1
            FOR UPDATE
        ");
        $stdp->execute([':uid' => $userId, ':cid' => $campaignId]);
        if ($stdp->fetchColumn()) {
            $pdo->rollBack();
            walkin_redirect_err($campaignId, $token, 'already');
        }

        // Campaign-level total_capacity is intentionally NOT enforced here.
        // Walk-in is designed to handle overflow beyond the planned quota —
        // slot-level capacity (below) is the only hard gate.

        // Re-check slot validity + capacity (must be today, time not passed, capacity left)
        $sts = $pdo->prepare("
            SELECT s.id, s.slot_date, s.start_time, s.end_time, s.max_capacity,
                   (SELECT COUNT(*) FROM camp_bookings b WHERE b.slot_id = s.id
                      AND b.status IN ('booked','confirmed','completed')) AS used
            FROM camp_slots s
            WHERE s.id = :sid AND s.campaign_id = :cid
              AND s.slot_date = CURDATE()
              AND s.end_time  >= TIME(NOW())
            LIMIT 1
            FOR UPDATE
        ");
        $sts->execute([':sid' => $slotId, ':cid' => $campaignId]);
        $slot = $sts->fetch(PDO::FETCH_ASSOC);

        if (!$slot) {
            $pdo->rollBack();
            walkin_redirect_err($campaignId, $token, 'no_slot');
        }
        if ((int)$slot['used'] >= (int)$slot['max_capacity']) {
            $pdo->rollBack();
            walkin_redirect_err($campaignId, $token, 'no_slot');
        }

        // INSERT booking — auto-approve + attended (walk-in = on-site completed)
        // is_walk_in=1 so the booking displays the "Walk-in" badge in admin/bookings.php
        $ins = $pdo->prepare("
            INSERT INTO camp_bookings (student_id, campaign_id, slot_id, status, attended_at, is_walk_in, created_at)
            VALUES (:uid, :cid, :sid, 'completed', NOW(), 1, NOW())
        ");
        $ins->execute([
            ':uid' => $userId,
            ':cid' => $campaignId,
            ':sid' => $slotId,
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // ── Post-commit side effects (best-effort, don't fail user) ────────────
    try {
        record_vaccination_from_booking($pdo, $bookingId);
    } catch (Throwable $e) {
        error_log('[walkin] vaccination record: ' . $e->getMessage());
    }

    try {
        log_activity('Walk-in Registration',
            "Walk-in: ผู้ป่วยลงทะเบียนเข้าร่วม '{$campaign['title']}' (slot #{$slotId})",
            $userId);
    } catch (Throwable $e) {
        error_log('[walkin] activity log: ' . $e->getMessage());
    }

    // Send confirmation email (best-effort)
    if (!empty($user['email'])) {
        try {
            require_once __DIR__ . '/../includes/mail_helper.php';
            $slotDate = date('d M Y', strtotime((string)$slot['slot_date']));
            $slotTime = substr((string)$slot['start_time'], 0, 5) . ' - ' . substr((string)$slot['end_time'], 0, 5);
            notify_booking_status((string)$user['email'], 'confirmation', [
                'campaign_title' => (string)$campaign['title'],
                'full_name'      => (string)$user['full_name'],
                'date'           => $slotDate,
                'time'           => $slotTime,
            ]);
        } catch (Throwable $e) {
            error_log('[walkin] email: ' . $e->getMessage());
        }
    }

    // Success → redirect to post-checkin survey (consistent with checkin_campaign.php)
    header('Location: post_checkin_survey.php?booking=' . $bookingId, true, 303);
    exit;

} catch (Throwable $e) {
    error_log('[walkin] ' . $e->getMessage());
    walkin_redirect_err($campaignId, $token, 'invalid');
}

function walkin_redirect_err(int $cid, string $token, string $err): never {
    if ($cid > 0 && $token !== '') {
        // Re-render walkin.php — page will detect the actual state again (idempotent)
        // The err param is unused server-side but useful for client-side debugging
        header('Location: walkin.php?cid=' . $cid . '&t=' . urlencode($token) . '&err=' . urlencode($err), true, 303);
    } else {
        header('Location: index.php', true, 303);
    }
    exit;
}
