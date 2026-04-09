<?php
declare(strict_types=1);

// ปิด display_errors ใน production — error ไป error_log แทน
ini_set('display_errors', '0');
error_reporting(0);

$appId = isset($_GET['id']) ? (string)$_GET['id'] : '';
if ($appId === '') {
    exit;
}

// 2. เช็คว่าไฟล์ qrlib.php อยู่ที่นี่จริงไหม
$libPath = __DIR__ . '/../assets/phpqrcode/qrlib.php';
if (!file_exists($libPath)) {
    http_response_code(500);
    exit;
}

require_once $libPath;

// 3. ป้องกันปัญหาเรื่อง Permission ของโฟลเดอร์ Cache
// ไลบรารีนี้ปกติจะสร้างไฟล์ Temp ถ้าเราไม่กำหนดมันจะพยายามสร้างในโฟลเดอร์เดิม
if (!defined('QR_CACHEABLE')) define('QR_CACHEABLE', false); 

// 4. สร้างข้อมูล QR
$qrContent = "BOOKING-ID:" . $appId;

// 5. ส่งค่าออกเป็นรูปภาพ
header('Content-Type: image/png');
// ใช้ค่าพารามิเตอร์ที่ 2 เป็น false เพื่อให้พ่นรูปออก Browser ทันทีโดยไม่บันทึกลงเครื่อง
QRcode::png($qrContent, false, QR_ECLEVEL_L, 10, 2);
exit;