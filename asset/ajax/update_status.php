<?php
// asset/ajax/update_status.php — เปลี่ยนสถานะครุภัณฑ์ (admin/editor + staff)
require_once __DIR__ . '/../includes/check_session_ajax.php';
require_once __DIR__ . '/../includes/helpers.php';

asset_validate_csrf_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'method_not_allowed']);
    exit;
}

$id     = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');

if ($id <= 0 || !array_key_exists($status, asset_status_options())) {
    echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}

// staff (employee) เปลี่ยนได้เฉพาะ in_use ↔ repair เท่านั้น
$role = asset_user_role();
if (!asset_can_manage()) {
    if (!in_array($status, ['in_use', 'repair'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'staff อนุญาตเฉพาะสถานะ ใช้งาน/ซ่อม']);
        exit;
    }
}

try {
    $pdo = db();
    $cur = $pdo->prepare("SELECT status FROM assets WHERE id = ?");
    $cur->execute([$id]);
    $oldStatus = $cur->fetchColumn();
    if ($oldStatus === false) {
        echo json_encode(['ok' => false, 'message' => 'ไม่พบรายการ']);
        exit;
    }

    $upd = $pdo->prepare("UPDATE assets SET status=?, updated_by=? WHERE id=?");
    $upd->execute([$status, $_SESSION['user_id'] ?? null, $id]);

    asset_log_movement($pdo, $id, 'status_change', null, null, $oldStatus, $status, "เปลี่ยนสถานะโดย {$role}");

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
