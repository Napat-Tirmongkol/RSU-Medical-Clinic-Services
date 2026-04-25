<?php
// admin/ajax/ajax_bulk_cancel_bookings.php
// Bulk cancellation of campaign bookings for a specific date/time slot
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/mail_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

try {
    // ── Auth & CSRF ────────────────────────────────────────────────────────────
    validate_csrf_or_die();
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'เซสชันหมดอายุ กรุณา Login ใหม่']);
        exit;
    }

    $pdo = db();

    // ── Parse input ────────────────────────────────────────────────────────────
    $slotId   = (int)($_POST['slot_id'] ?? 0);
    $campaign = (string)($_POST['campaign'] ?? '');

    if ($slotId <= 0 || $campaign === '') {
        echo json_encode(['ok' => false, 'error' => 'ข้อมูลไม่ครบถ้วน (Missing slot_id or campaign)']);
        exit;
    }

    // ── Fetch slot details ─────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT cs.slot_date, cs.start_time, cs.end_time, cl.title as campaign_title
        FROM camp_slots cs
        JOIN camp_list cl ON cs.campaign_id = cl.id
        WHERE cs.id = :slot_id
        LIMIT 1
    ");
    $stmt->execute([':slot_id' => $slotId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        echo json_encode(['ok' => false, 'error' => 'ไม่พบข้อมูลรอบเวลาในระบบ']);
        exit;
    }

    // ── Get all active bookings for this slot ──────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            b.id as booking_id,
            b.student_id,
            u.email,
            u.full_name,
            u.line_user_id
        FROM camp_bookings b
        JOIN sys_users u ON b.student_id = u.id
        WHERE b.slot_id = :slot_id
          AND b.status IN ('booked','confirmed')
    ");
    $stmt->execute([':slot_id' => $slotId]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($bookings)) {
        echo json_encode(['ok' => true, 'cancelled_count' => 0, 'message' => 'ไม่พบผู้จองที่ต้องยกเลิกในรอบนี้']);
        exit;
    }

    // ── Bulk cancellation ──────────────────────────────────────────────────────
    $cancelledCount = 0;
    $failedCount    = 0;
    $errors         = [];

    $pdo->beginTransaction();

    foreach ($bookings as $booking) {
        try {
            // Update booking status
            $stmt = $pdo->prepare("
                UPDATE camp_bookings
                SET status = 'cancelled_by_admin'
                WHERE id = :booking_id
            ");
            $stmt->execute([':booking_id' => $booking['booking_id']]);

            // Send email notification
            $emailData = [
                'campaign_title' => $slot['campaign_title'],
                'date'           => date('j M Y', strtotime($slot['slot_date'])),
                'time'           => substr($slot['start_time'], 0, 5) . '–' . substr($slot['end_time'], 0, 5),
                'full_name'      => $booking['full_name']
            ];

            if (!empty($booking['email'])) {
                notify_booking_status($booking['email'], 'cancelled_by_admin', $emailData);
            }

            // Send LINE notification if LINE user ID exists
            if (!empty($booking['line_user_id'])) {
                send_line_cancellation($booking['line_user_id'], $emailData);
            }

            $cancelledCount++;

        } catch (Exception $e) {
            $failedCount++;
            $errors[] = "Booking #{$booking['booking_id']}: " . $e->getMessage();
        }
    }

    $pdo->commit();

    // ── Log activity ───────────────────────────────────────────────────────────
    if (function_exists('log_activity')) {
        log_activity('bulk_cancel_bookings', "ยกเลิกการจองทั้งหมด {$cancelledCount} รายการ สำหรับ {$slot['campaign_title']} ({$slot['slot_date']})");
    }

    echo json_encode([
        'ok'              => true,
        'cancelled_count' => $cancelledCount,
        'failed_count'    => $failedCount,
        'errors'          => $errors,
        'message'         => $failedCount === 0
            ? "ยกเลิกการจองเรียบร้อย {$cancelledCount} รายการ"
            : "ยกเลิกสำเร็จ {$cancelledCount} รายการ (ล้มเหลว {$failedCount} รายการ)"
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'Internal Server Error: ' . $e->getMessage()]);
} catch (Error $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'error' => 'PHP Fatal Error: ' . $e->getMessage()]);
}

// ── Helper: Send LINE cancellation message ─────────────────────────────────
function send_line_cancellation(string $lineUserId, array $data): void {
    $secrets = file_exists(__DIR__ . '/../../config/secrets.php')
        ? require __DIR__ . '/../../config/secrets.php'
        : [];

    $token = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';
    if (!$token) return;

    $message = [
        'type'     => 'template',
        'altText'  => 'การยกเลิกการจอง',
        'template' => [
            'type'     => 'buttons',
            'title'    => '⚠️ ยกเลิกการจองของท่าน',
            'text'     => "โปรแกรม: {$data['campaign_title']}\nวันที่: {$data['date']}\nเวลา: {$data['time']}",
            'actions'  => [
                [
                    'type'  => 'uri',
                    'label' => '👉 จองใหม่',
                    'uri'   => 'https://healthycampus.rsu.ac.th/e-campaignv2/user/booking_campaign.php'
                ]
            ]
        ]
    ];

    $ch = curl_init('https://api.line.biz/v3/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS    => json_encode(['to' => $lineUserId, 'messages' => [$message]]),
        CURLOPT_HTTPHEADER    => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log('LINE message failed: ' . $response);
    }
}
