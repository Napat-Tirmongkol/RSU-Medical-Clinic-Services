<?php
// portal/ajax_announcements.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

session_start();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $pdo = db();

    // Mark as read
    if ($action === 'mark_read') {
        $annId = (int)($_POST['ann_id'] ?? 0);
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($annId <= 0 || $userId <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
            exit;
        }

        // Insert record
        $stmt = $pdo->prepare("INSERT IGNORE INTO sys_announcement_reads (announcement_id, user_id) VALUES (?, ?)");
        $stmt->execute([$annId, $userId]);

        // Increment read count
        if ($stmt->rowCount() > 0) {
            $pdo->prepare("UPDATE sys_announcements SET read_count = read_count + 1 WHERE id = ?")->execute([$annId]);
        }

        echo json_encode(['status' => 'success']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
