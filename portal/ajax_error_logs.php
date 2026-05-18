<?php
// portal/ajax_error_logs.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Mutations must be POST + CSRF-protected.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
validate_csrf_or_die();

// System error log management is superadmin/access_system_logs only.
$adminRole = $_SESSION['admin_role'] ?? '';
$hasAccess = $adminRole === 'superadmin' || !empty($_SESSION['access_system_logs']);
if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$pdo = db();
$action = $_POST['action'] ?? '';

if ($action === 'update_status') {
    $lid = (int)($_POST['log_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $comment = $_POST['resolve_comment'] ?? '';

    if ($lid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid Log ID']);
        exit;
    }

    if (!in_array($status, ['New', 'Active', 'Resolved'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid Status']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE sys_error_logs SET status = ?, resolve_comment = ? WHERE id = ?");
        $stmt->execute([$status, $comment, $lid]);

        echo json_encode(['ok' => true, 'message' => 'Status updated successfully']);
    } catch (PDOException $e) {
        error_log('[ajax_error_logs] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'ไม่สามารถอัปเดตสถานะได้']);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid Action']);
