<?php
// user/api_get_slots.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (empty($_SESSION['evax_student_id']) && empty($_SESSION['line_user_id'])) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$date = $_GET['date'] ?? '';
$campaignId = (int)($_GET['campaign_id'] ?? 0);

if (!$date || !$campaignId) {
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

// ตรวจสอบ format วันที่
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'รูปแบบวันที่ไม่ถูกต้อง']));
}

try {
    $pdo = db();
    $sql = "
        SELECT 
            t.id, t.max_capacity,
            (SELECT COUNT(*) FROM camp_bookings a WHERE a.slot_id = t.id AND a.status IN ('booked', 'confirmed')) as booked_count
        FROM camp_slots t
        WHERE t.slot_date = :date AND t.campaign_id = :cid
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date' => $date, ':cid' => $campaignId]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($slots as $slot) {
        $remaining = (int)$slot['max_capacity'] - (int)$slot['booked_count'];
        $result[$slot['id']] = max(0, $remaining); // ส่งกลับเป็น ID => จำนวนที่เหลือ
    }
    
    echo json_encode(['status' => 'success', 'data' => $result]);
} catch (PDOException $e) {
    error_log("api_get_slots error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดของระบบ']);
}
