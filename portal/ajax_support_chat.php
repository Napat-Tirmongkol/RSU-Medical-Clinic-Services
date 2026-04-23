<?php
// portal/ajax_support_chat.php — Staff Chat Controller
declare(strict_types=1);
// NOTE: session_start() is handled by auth.php below
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Load auth — but catch redirects gracefully for AJAX context
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$staffId = $_SESSION['admin_id'] ?? null;
if (!$staffId || empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized — not logged in as admin']);
    exit;
}

$action = $_GET['action'] ?? 'list_users';
$pdo = db();

// Auto-create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS sys_chat_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_type ENUM('user', 'staff') NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    staff_id INT UNSIGNED NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    if ($action === 'list_users') {
        // แสดงเฉพาะผู้ใช้งานที่ส่งข้อความมาแล้ว
        $stmt = $pdo->query("
            SELECT 
                u.id, 
                u.full_name, 
                u.picture_url,
                m.message as last_message,
                m.created_at,
                COALESCE(unread.cnt, 0) as unread_count
            FROM sys_users u
            JOIN (
                SELECT user_id, MAX(id) as max_id
                FROM sys_chat_messages
                GROUP BY user_id
            ) latest ON u.id = latest.user_id
            JOIN sys_chat_messages m ON latest.max_id = m.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) as cnt
                FROM sys_chat_messages
                WHERE is_read = 0 AND sender_type = 'user'
                GROUP BY user_id
            ) unread ON u.id = unread.user_id
            ORDER BY m.created_at DESC
        ");
        $users = $stmt->fetchAll();

        if (empty($users)) {
            echo json_encode(['success' => true, 'users' => [], 'empty_message' => 'ยังไม่มีผู้ใช้งานส่งข้อความเข้ามา']);
        } else {
            echo json_encode(['success' => true, 'users' => $users]);
        }
        exit;
    }

    if ($action === 'get_messages') {
        $targetUserId = (int)($_GET['user_id'] ?? 0);
        if (!$targetUserId) exit;

        // Mark as read
        $pdo->prepare("UPDATE sys_chat_messages SET is_read = 1 WHERE user_id = :uid AND sender_type = 'user'")->execute([':uid' => $targetUserId]);

        $stmt = $pdo->prepare("
            SELECT m.*, a.full_name as staff_name 
            FROM sys_chat_messages m
            LEFT JOIN sys_admins a ON m.staff_id = a.id
            WHERE m.user_id = :uid 
            ORDER BY m.id ASC
        ");
        $stmt->execute([':uid' => $targetUserId]);
        $messages = $stmt->fetchAll();

        foreach ($messages as &$m) {
            $m['time'] = date('H:i', strtotime($m['created_at']));
        }

        echo json_encode(['success' => true, 'messages' => $messages]);
        exit;
    }

    if ($action === 'send_reply') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');

        if (!$targetUserId || empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO sys_chat_messages (sender_type, user_id, staff_id, message) VALUES ('staff', :uid, :sid, :msg)");
        $stmt->execute([':uid' => $targetUserId, ':sid' => $staffId, ':msg' => $message]);

        echo json_encode(['success' => true]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
