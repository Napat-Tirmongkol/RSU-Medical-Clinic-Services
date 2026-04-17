<?php
// admin/ajax_force_cancel.php
session_start();
header('Content-Type: application/json');

// Check admin auth
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';

validate_csrf_or_die();

$appointmentId = isset($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : 0;

if ($appointmentId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Appointment ID']);
    exit;
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    // 1. Check if the appointment exists and get info
    $stmt = $pdo->prepare("
        SELECT 
            a.status,
            a.slot_id,
            s.line_user_id,
            s.full_name,
            s.email,
            c.title AS campaign_title,
            t.slot_date,
            t.start_time,
            t.end_time
        FROM camp_bookings a
        JOIN sys_users s ON a.student_id = s.id
        JOIN camp_list c ON a.campaign_id = c.id
        JOIN camp_slots t ON a.slot_id = t.id
        WHERE a.id = :id
    ");
    $stmt->execute([':id' => $appointmentId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลคิวนี้']);
        exit;
    }

    if ($booking['status'] === 'cancelled' || $booking['status'] === 'cancelled_by_admin') {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'คิวนี้ถูกยกเลิกไปแล้ว']);
        exit;
    }

    // 2. Change status to 'cancelled_by_admin'
    $updateStmt = $pdo->prepare("UPDATE camp_bookings SET status = 'cancelled_by_admin' WHERE id = :id");
    $updateStmt->execute([':id' => $appointmentId]);

    // 3. ส่งอีเมลแจ้งเตือน (ถ้ามีอีเมล)
    if (!empty($booking['email'])) {
        try {
            require_once __DIR__ . '/../includes/mail_helper.php';
            notify_booking_status($booking['email'], 'cancelled_by_admin', [
                'campaign_title' => $booking['campaign_title'],
                'date' => date('d/m/Y', strtotime($booking['slot_date'])),
                'time' => substr($booking['start_time'], 0, 5) . ' - ' . substr($booking['end_time'], 0, 5)
            ]);
        } catch (Exception $e) {
            error_log("Force Cancel Email Error: " . $e->getMessage());
        }
    }

    // 4. Send LINE Message (Messaging API)
    if (!empty($booking['line_user_id'])) {
        $lineUserId = $booking['line_user_id'];
        $campaignTitle = $booking['campaign_title'];
        $dateLabel = date('d/m/Y', strtotime($booking['slot_date']));
        $timeLabel = substr($booking['start_time'], 0, 5);
        
        $messageText = "ขออภัยค่ะ เนื่องจากวันนี้มีผู้เข้าร่วมกิจกรรม {$campaignTitle} เกินจำนวนที่รองรับได้ หรือมีเหตุจำเป็น ระบบจึงขออนุญาตยกเลิกคิวของคุณในวันที่ {$dateLabel} เวลา {$timeLabel} น. \n\nกรุณากดปุ่มด้านล่างเพื่อทำการจองรอบเวลาใหม่ค่ะ";
        
        // Load LINE config and LIFF URL from secrets
        $lineSecrets = file_exists(__DIR__ . '/../config/secrets.php') ? require __DIR__ . '/../config/secrets.php' : [];
        $liffId  = $lineSecrets['LINE_LIFF_ID'] ?? '';
        $lineToken = $lineSecrets['EBORROW_LINE_MESSAGE_TOKEN'] ?? $lineSecrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';
        $liffUrl = $liffId ? "https://liff.line.me/{$liffId}" : "https://healthycampus.rsu.ac.th/e-campaignv2/user/index.php";

        $lineMessages = [
            [
                'type' => 'flex',
                'altText' => "แจ้งเตือนการยกเลิกคิว (เพื่อเลื่อนวัน) - กิจกรรม {$campaignTitle}",
                'contents' => [
                    'type' => 'bubble',
                    'size' => 'giga',
                    'header' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'backgroundColor' => '#EF4444',
                        'contents' => [[
                            'type' => 'text',
                            'text' => '[แจ้งเตือน] ยกเลิกคิว (คิวเต็ม)',
                            'color' => '#FFFFFF',
                            'weight' => 'bold',
                            'size' => 'lg',
                            'align' => 'center',
                        ]]
                    ],
                    'body' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [[
                            'type' => 'text',
                            'text' => $messageText,
                            'wrap' => true,
                            'size' => 'sm',
                            'color' => '#666666',
                        ]]
                    ],
                    'footer' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [[
                            'type' => 'button',
                            'style' => 'primary',
                            'color' => '#0052CC',
                            'action' => ['type' => 'uri', 'label' => 'จองรอบเวลาใหม่', 'uri' => $liffUrl],
                        ]]
                    ]
                ]
            ]
        ];

        if (!empty($lineToken)) {
            try {
                $ch = curl_init('https://api.line.me/v2/bot/message/push');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(['to' => $lineUserId, 'messages' => $lineMessages]),
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $lineToken,
                    ],
                    CURLOPT_TIMEOUT        => 10,
                ]);
                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode !== 200) {
                    error_log("Force Cancel LINE push failed (HTTP {$httpCode}) for user {$lineUserId}");
                }
            } catch (Exception $e) {
                error_log("Force Cancel LINE Error: " . $e->getMessage());
            }
        }
    }
    
    $pdo->commit();

    echo json_encode(['status' => 'success']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

