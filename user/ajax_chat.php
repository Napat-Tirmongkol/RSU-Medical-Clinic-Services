<?php
// user/ajax_chat.php — Chat Backend Controller (Production Grade)
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// 1. Auth Check — ใช้ user_id ใน session (set จาก hub.php)
//    ถ้าไม่มี ให้ fallback หา user_id จาก line_user_id
$userId = $_SESSION['user_id'] ?? null;

if (!$userId && !empty($_SESSION['line_user_id'])) {
    try {
        $pdo_tmp = db();
        $s = $pdo_tmp->prepare("SELECT id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
        $s->execute([':lid' => $_SESSION['line_user_id']]);
        $row = $s->fetch();
        if ($row) {
            $userId = (int)$row['id'];
            $_SESSION['user_id'] = $userId; // cache ไว้
        }
    } catch (Exception $e) {}
}

if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Reject mutating requests without a valid CSRF token (only fires on POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $_GET['action'] ?? 'get';
$pdo = db();

require_once __DIR__ . '/../includes/chat_helper.php';
ensure_chat_schema($pdo);

try {
    if ($action === 'send') {
        $message = trim($_POST['message'] ?? '');
        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message is empty']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO sys_chat_messages (sender_type, user_id, message) VALUES ('user', :uid, :msg)");
        $stmt->execute([':uid' => $userId, ':msg' => $message]);
        
        $msgId = $pdo->lastInsertId();

        // ── Pusher Trigger (Optional) ──
        // If you have Pusher credentials, you would trigger an event here.
        // For now, we return success and the frontend will handle local echo.
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $msgId,
                'message' => $message,
                'time' => date('H:i')
            ]
        ]);
        exit;
    } 
    
    if ($action === 'get') {
        $lastId = (int)($_GET['last_id'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT id, sender_type, message, created_at 
            FROM sys_chat_messages 
            WHERE user_id = :uid AND id > :last 
            ORDER BY id ASC
        ");
        $stmt->execute([':uid' => $userId, ':last' => $lastId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format time for frontend
        foreach ($messages as &$m) {
            $m['time'] = date('H:i', strtotime($m['created_at']));
        }

        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);
        exit;
    }

} catch (Exception $e) {
    error_log('user/ajax_chat error (' . ($action ?? '?') . '): ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาดในระบบ']);
}
