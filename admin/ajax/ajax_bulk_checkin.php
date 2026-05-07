<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

validate_csrf_or_die();

$ids = json_decode($_POST['ids'] ?? '[]', true);
if (!is_array($ids) || empty($ids)) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีรายการที่เลือก']);
    exit;
}

$ids = array_values(array_filter(array_map('intval', $ids)));

try {
    $pdo          = db();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // Stamp attended_at on bulk check-in too so the post-checkin survey can detect it
    $stmt         = $pdo->prepare("
        UPDATE camp_bookings
        SET status = 'completed', attended_at = COALESCE(attended_at, NOW())
        WHERE id IN ($placeholders)
          AND status = 'confirmed'
    ");
    $stmt->execute($ids);

    echo json_encode([
        'status'   => 'success',
        'affected' => $stmt->rowCount(),
    ]);

} catch (PDOException $e) {
    log_error_to_db('PDOException: ' . $e->getMessage(), 'error', 'ajax_bulk_checkin.php', $e->getTraceAsString());
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในระบบ']);
}
