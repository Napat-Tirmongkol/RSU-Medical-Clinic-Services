<?php
// line_api/webhook.php
declare(strict_types=1);

// Webhook ไม่มี Session เพราะ LINE ยิงเข้ามาที่นี่แบบ Background
// ควรจะ Include DB ถ้าต้องการบันทึกแชทหรือเช็คผู้ใช้
require_once __DIR__ . '/../config/db_connect.php'; 
require_once __DIR__ . '/line_config.php';
require_once __DIR__ . '/line_message_helper.php';

// 1. รับ Data JSON แบบดิบๆ (Raw Payload) จาก LINE
$content = file_get_contents('php://input');

// 2. รับ Signature เพื่อใช้ยืนยันว่า Request มาจาก Server LINE จริงๆ
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

// 3. ยืนยัน Signature
if (!verifyLineSignature($content, $signature)) {
    http_response_code(400); // 400 Bad Request
    exit("Invalid Signature. Data may have been tampered with.");
}

// 4. Decode JSON ให้กลายเป็น Array PHP
$events = json_decode($content, true);

if (!empty($events['events'])) {
    foreach ($events['events'] as $event) {

        // แยกประเภทของ Event (เช่น ส่งความข้อความ, กดติดตาม, กดยกเลิก)
        $eventType = $event['type'] ?? '';
        
        if ($eventType === 'message' && isset($event['message'])) {
            // ดึงข้อความและ Token เพื่อพ่นตอบ
            $messageType = $event['message']['type'];
            $replyToken = $event['replyToken'];
            $userId = $event['source']['userId'] ?? null;
            
            if ($messageType === 'text') {
                $userText = $event['message']['text'];

                // ตัวอย่างระบบบอทตอบกลับแบบง่าย (Simple Reply)
                // สามารถเอาไปเชื่อมกับ Dialogflow หรือทำ Logic ของตัวเอง
                $replyData = [
                    [
                        'type' => 'text',
                        'text' => "ระบบบันทึกคิวได้รับข้อความของคุณแล้ว: '{$userText}'"
                    ]
                ];
                
                // สั่งให้ Server ตอบกลับ
                sendLineReplyMessage($replyToken, $replyData);
                
            } elseif ($messageType === 'image') {
                sendLineReplyMessage($replyToken, [['type' => 'text', 'text' => "เราได้รับรูปถ่ายของคุณแล้ว"]]);
            }

        } elseif ($eventType === 'follow') {
            // สมมติว่าต้องการทักทายตอนแอดเพื่อนครั้งแรก
            // $replyToken = $event['replyToken'];
            // $welcomeMsg = [['type' => 'text', 'text' => "ยินดีต้อนรับเข้าสู้ระบบจองคิว!"]];
            // sendLineReplyMessage($replyToken, $welcomeMsg);

        } elseif ($eventType === 'unfollow') {
            // เช็คว่าผู้ใช้ Block / ลบเพื่อน
            $userId = $event['source']['userId'];
            // สามารถไปอัปเดต Status แจ้งเตือนใน DB ว่าให้หยุดส่ง Push มาที่คนนี้
        }
    }
}

// 5. ส่งสถานะ 200 OK คืนให้ LINE (ถ้าไม่ส่ง LINE จะคิดว่า Server เราล่มและจะพยายามส่งซ้ำ!)
http_response_code(200);
echo "OK";
