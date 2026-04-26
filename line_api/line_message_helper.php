<?php
// line_api/line_message_helper.php
declare(strict_types=1);

// พยายามโหลดถ้ายังไม่ได้ถูก require
require_once __DIR__ . '/line_config.php';

/**
 * ฟังก์ชันช่วยตรวจสอบว่าคำขอ JSON มาจาก Server LINE ของจริงหรือไม่ (ใช้คู่กับ Webhook)
 */
function verifyLineSignature($requestBody, $signature) {
    if (empty($signature) || empty($requestBody)) {
        return false;
    }
    // เข้าหรัสแบบ HMAC-SHA256 ด้วย Secret 
    $hash = hash_hmac('sha256', $requestBody, LINE_MESSAGING_CHANNEL_SECRET, true);
    // แปลงให้เป็น base64 อย่างเดียวเทียบกับลายเซ็น Header 
    $calculatedSignature = base64_encode($hash);
    return hash_equals($calculatedSignature, $signature);
}

/**
 * ส่งข้อความตอบกลับทันที (ผู้ใช้พิมพ์มา คุณตอบกลับไปใช้ได้ครั้งเดียวภายในเวลาสั้นๆ ไม่เสียเงินค่า Broadcasting)
 * 
 * @param string $replyToken Token เฉพาะตัวที่ LINE ส่งมาตอนเปิด Webhook
 * @param array  $messages   ข้อความโครงสร้าง Array JSON ตามแบบแผนของ LINE Messaging API
 */
function sendLineReplyMessage(string $replyToken, array $messages) {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $data = [
        'replyToken' => $replyToken,
        'messages' => $messages
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_MESSAGING_CHANNEL_ACCESS_TOKEN
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode;
}

/**
 * ผลักข้อความหาผู้ใช้โดยตรง (เสียโควตาข้อความ หรือเงิน ใช้ Push ได้ตลอดเวลา ไม่ต้องรอเขาทักมาก่อน)
 * 
 * @param string $userId   ไอดีของผู้ใช้แต่ละคน เช่น Uxxxxx12345
 * @param array  $messages ข้อความที่จะผลักส่งดึกๆ เช่น "คุณถูกยกเลิกคิว"
 */
function sendLinePushMessage(string $userId, array $messages) {
    $url = 'https://api.line.me/v2/bot/message/push';
    $data = [
        'to' => $userId,
        'messages' => $messages
    ];
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LINE_MESSAGING_CHANNEL_ACCESS_TOKEN
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode;
}
