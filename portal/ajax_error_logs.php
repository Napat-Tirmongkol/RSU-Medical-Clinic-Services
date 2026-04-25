<?php
// portal/ajax_error_logs.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

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
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid Action']);
