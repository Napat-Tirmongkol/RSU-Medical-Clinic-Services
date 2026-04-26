<?php
// portal/ajax_pins.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json');

try {
    $pdo = db();
    $userId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    $userType = isset($_SESSION['admin_id']) ? 'admin' : 'staff';

    if (!$userId) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }

    // Auto-migration (Ensures the table exists)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_portal_pins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_type VARCHAR(20) NOT NULL,
        project_id VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY user_proj (user_id, user_type, project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $action = $_GET['action'] ?? '';

    if ($action === 'get') {
        $stmt = $pdo->prepare("SELECT project_id FROM sys_portal_pins WHERE user_id = ? AND user_type = ?");
        $stmt->execute([$userId, $userType]);
        $pins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['status' => 'success', 'pins' => $pins]);
        exit;
    }

    if ($action === 'toggle') {
        $projId = $_POST['project_id'] ?? '';
        if (!$projId) {
            echo json_encode(['status' => 'error', 'message' => 'Missing project_id']);
            exit;
        }

        // Check if exists
        $stmt = $pdo->prepare("SELECT id FROM sys_portal_pins WHERE user_id = ? AND user_type = ? AND project_id = ?");
        $stmt->execute([$userId, $userType, $projId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $stmt = $pdo->prepare("DELETE FROM sys_portal_pins WHERE id = ?");
            $stmt->execute([$exists['id']]);
            echo json_encode(['status' => 'success', 'action' => 'removed']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO sys_portal_pins (user_id, user_type, project_id) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $userType, $projId]);
            echo json_encode(['status' => 'success', 'action' => 'added']);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
