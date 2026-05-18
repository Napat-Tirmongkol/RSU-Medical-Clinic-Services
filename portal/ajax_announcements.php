<?php
// portal/ajax_announcements.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_guard.php';

start_secure_session();
header('Content-Type: application/json; charset=utf-8');

// Mutations must be POST + CSRF-protected.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}
validate_csrf_or_die();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    $pdo = db();

    if ($action === 'mark_read') {
        $annId = (int)($_POST['ann_id'] ?? 0);
        if ($annId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO sys_announcement_reads (announcement_id, user_id) VALUES (?, ?)");
        $stmt->execute([$annId, $userId]);

        if ($stmt->rowCount() > 0) {
            $pdo->prepare("UPDATE sys_announcements SET read_count = read_count + 1 WHERE id = ?")->execute([$annId]);
        }

        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);

} catch (Throwable $e) {
    error_log('[ajax_announcements] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'ระบบขัดข้อง กรุณาลองใหม่']);
}
