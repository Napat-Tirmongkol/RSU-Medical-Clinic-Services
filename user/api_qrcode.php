<?php
declare(strict_types=1);

// ปิด display_errors ใน production — error ไป error_log แทน
ini_set('display_errors', '0');
error_reporting(0);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['student_id']) && empty($_SESSION['line_user_id'])) {
    http_response_code(401);
    exit;
}

$appId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($appId <= 0) {
    http_response_code(400);
    exit;
}

$pdo = db();
$currentUserId = (int) ($_SESSION['user_id'] ?? $_SESSION['student_id'] ?? 0);

if ($currentUserId <= 0 && !empty($_SESSION['line_user_id'])) {
    $stmt = $pdo->prepare("
        SELECT id
        FROM sys_users
        WHERE line_user_id_new = :line_id OR line_user_id = :line_id
        LIMIT 1
    ");
    $stmt->execute([':line_id' => $_SESSION['line_user_id']]);
    $currentUserId = (int) ($stmt->fetchColumn() ?: 0);
}

if ($currentUserId <= 0) {
    http_response_code(401);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id
    FROM camp_bookings
    WHERE id = :booking_id
      AND student_id = :user_id
      AND status IN ('booked', 'confirmed')
    LIMIT 1
");
$stmt->execute([
    ':booking_id' => $appId,
    ':user_id' => $currentUserId
]);

if (!$stmt->fetchColumn()) {
    http_response_code(403);
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
