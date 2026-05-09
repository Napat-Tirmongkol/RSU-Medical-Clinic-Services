<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../includes/vaccination_helper.php';

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

    // Snapshot ids that were 'confirmed' before the update so we know which ones
    // actually transitioned to 'completed' (for vaccine record auto-sync).
    $sel = $pdo->prepare("
        SELECT id FROM camp_bookings
        WHERE id IN ($placeholders) AND status = 'confirmed'
    ");
    $sel->execute($ids);
    $transitioned = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));

    // Stamp attended_at on bulk check-in too so the post-checkin survey can detect it
    $stmt         = $pdo->prepare("
        UPDATE camp_bookings
        SET status = 'completed', attended_at = COALESCE(attended_at, NOW())
        WHERE id IN ($placeholders)
          AND status = 'confirmed'
    ");
    $stmt->execute($ids);

    foreach ($transitioned as $bid) {
        record_vaccination_from_booking($pdo, $bid);
    }

    echo json_encode([
        'status'   => 'success',
        'affected' => $stmt->rowCount(),
    ]);

} catch (PDOException $e) {
    log_error_to_db('PDOException: ' . $e->getMessage(), 'error', 'ajax_bulk_checkin.php', $e->getTraceAsString());
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในระบบ']);
}
