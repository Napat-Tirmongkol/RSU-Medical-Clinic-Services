<?php
// asset/ajax/delete_attachment.php — ลบเอกสารแนบ
require_once __DIR__ . '/../includes/check_session_ajax.php';
require_once __DIR__ . '/../includes/helpers.php';

asset_require_manage_ajax();
asset_validate_csrf_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'method_not_allowed']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'ไม่พบรหัส']);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT file_path FROM asset_attachments WHERE id = ?");
    $stmt->execute([$id]);
    $path = $stmt->fetchColumn();
    if ($path === false) {
        echo json_encode(['ok' => false, 'message' => 'ไม่พบรายการ']);
        exit;
    }

    $pdo->prepare("DELETE FROM asset_attachments WHERE id = ?")->execute([$id]);

    if ($path && str_starts_with((string)$path, 'uploads/')) {
        $abs = __DIR__ . '/../' . $path;
        if (is_file($abs)) @unlink($abs);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'ระบบขัดข้อง กรุณาลองอีกครั้ง']);
}
