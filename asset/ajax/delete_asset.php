<?php
// asset/ajax/delete_asset.php — ลบครุภัณฑ์ (admin/editor only)
require_once __DIR__ . '/../includes/check_session_ajax.php';
require_once __DIR__ . '/../includes/helpers.php';

asset_require_manage_ajax();
asset_validate_csrf_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'method_not_allowed']);
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'ไม่พบรหัสครุภัณฑ์']);
    exit;
}

try {
    $pdo = db();

    // ลบไฟล์รูป (best-effort)
    $stmt = $pdo->prepare("SELECT image FROM assets WHERE id = ?");
    $stmt->execute([$id]);
    $img = $stmt->fetchColumn();

    asset_log_movement($pdo, $id, 'delete', null, null, null, null, 'ลบจากระบบ');

    $del = $pdo->prepare("DELETE FROM assets WHERE id = ?");
    $del->execute([$id]);

    if ($img && str_starts_with((string)$img, 'uploads/')) {
        $abs = __DIR__ . '/../' . $img;
        if (is_file($abs)) @unlink($abs);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
