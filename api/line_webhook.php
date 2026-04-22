<?php
/**
 * api/line_webhook.php
 * Endpoint สำหรับรับ Webhook จาก LINE Messaging API
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/line_helper.php';

// 1. รับข้อมูลจาก LINE
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

// 2. โหลด Config
$secrets = require __DIR__ . '/../config/secrets.php';
$channelSecret = $secrets['LINE_MESSAGING_CHANNEL_SECRET'] ?? '';
$accessToken   = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';

// 3. ยืนยัน Signature (สำคัญมากเพื่อความปลอดภัย)
if (!verify_line_signature($payload, $signature, $channelSecret)) {
    http_response_code(400);
    error_log("LINE Webhook: Invalid Signature");
    exit("Invalid Signature");
}

// 4. แปลงข้อมูล
$data = json_decode($payload, true);
if (empty($data['events'])) {
    http_response_code(200);
    echo "OK (No events)";
    exit;
}

// 5. วนลูปจัดการแต่ละ Event
foreach ($data['events'] as $event) {
    $type = $event['type'] ?? '';
    $replyToken = $event['replyToken'] ?? null;
    $userId = $event['source']['userId'] ?? null;

    switch ($type) {
        case 'follow':
            // ส่งข้อความต้อนรับเมื่อผู้ใช้แอดเพื่อน
            if ($replyToken) {
                $messages = [
                    [
                        'type' => 'text',
                        'text' => "ยินดีต้อนรับสู่ระบบ " . SITE_NAME . " ค่ะ! 😊\n\nขอบคุณที่ติดตามเรานะคะ คุณสามารถจองคิวรับบริการได้ผ่านเมนูในระบบได้เลยค่ะ"
                    ]
                ];
                send_line_reply($replyToken, $messages, $accessToken);
            }
            break;

        case 'message':
            // ตอบกลับแบบง่ายถ้าเป็นข้อความตัวอักษร
            if ($replyToken && $event['message']['type'] === 'text') {
                $userText = $event['message']['text'];
                $messages = [
                    [
                        'type' => 'text',
                        'text' => "เราได้รับข้อความของคุณแล้ว: \"$userText\"\n\nหากต้องการความช่วยเหลือเพิ่มเติม สามารถติดต่อเจ้าหน้าที่ได้โดยตรงค่ะ"
                    ]
                ];
                send_line_reply($replyToken, $messages, $accessToken);
            }
            break;

        case 'unfollow':
            // ผู้ใช้บล็อกบอท
            error_log("LINE Webhook: User $userId unfollowed");
            break;

        default:
            // Event อื่นๆ เช่น postback (กดปุ่ม) สามารถเพิ่มภายหลังได้
            break;
    }
}

// 6. ตอบกลับ LINE ว่าได้รับข้อมูลแล้ว
http_response_code(200);
echo "OK";
