<?php
// admin/ajax/ajax_toggle_walkin.php — toggle walkin_enabled per campaign
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

validate_csrf_or_die();

$campaign_id = (int)($_POST['campaign_id'] ?? 0);
if ($campaign_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid campaign']);
    exit;
}

$pdo = db();

// Idempotent column add
try {
    $pdo->exec("ALTER TABLE camp_list ADD COLUMN walkin_enabled TINYINT(1) NOT NULL DEFAULT 0");
} catch (PDOException) {}

try {
    $row = $pdo->prepare("SELECT walkin_enabled FROM camp_list WHERE id = :id LIMIT 1");
    $row->execute([':id' => $campaign_id]);
    $current = (int)($row->fetchColumn() ?? 0);
    $new_val = $current ? 0 : 1;

    $pdo->prepare("UPDATE camp_list SET walkin_enabled = :v WHERE id = :id")
        ->execute([':v' => $new_val, ':id' => $campaign_id]);

    log_activity('toggle_campaign_walkin',
        "Campaign ID {$campaign_id}: Walk-in QR " . ($new_val ? 'เปิด' : 'ปิด'));

    echo json_encode([
        'status'         => 'success',
        'walkin_enabled' => $new_val,
        'message'        => $new_val ? 'เปิด Walk-in QR แล้ว' : 'ปิด Walk-in QR แล้ว',
    ]);
} catch (PDOException $e) {
    log_error_to_db('toggle_walkin: ' . $e->getMessage(), 'error', 'ajax_toggle_walkin.php');
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด']);
}
