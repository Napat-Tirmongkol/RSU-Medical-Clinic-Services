<?php
// portal/ajax_support_chat.php — Staff Chat Controller
declare(strict_types=1);
// NOTE: session_start() is handled by auth.php below
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/chat_helper.php';

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

// Reject mutating requests without a valid CSRF token (only fires on POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $_GET['action'] ?? 'list_users';
$pdo = db();
ensure_chat_schema($pdo);

try {
    if ($action === 'list_users') {
        // แสดงเฉพาะผู้ใช้งานที่ส่งข้อความมาแล้ว — paginate 20/page + optional search by name
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $q = trim((string)($_GET['q'] ?? ''));
        $searchClause = '';
        $params = [];
        if ($q !== '') {
            $searchClause = " AND u.full_name LIKE :q";
            $params[':q'] = '%' . $q . '%';
        }

        $countSql = "SELECT COUNT(DISTINCT u.id)
            FROM sys_users u
            JOIN sys_chat_messages m ON m.user_id = u.id
            WHERE 1=1 $searchClause";
        $cs = $pdo->prepare($countSql);
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        $listSql = "
            SELECT
                u.id,
                u.full_name,
                u.picture_url,
                m.message as last_message,
                m.created_at,
                COALESCE(unread.cnt, 0) as unread_count,
                COALESCE(cv.status, 'open') as status
            FROM sys_users u
            JOIN (
                SELECT user_id, MAX(id) as max_id
                FROM sys_chat_messages
                WHERE is_internal = 0
                GROUP BY user_id
            ) latest ON u.id = latest.user_id
            JOIN sys_chat_messages m ON latest.max_id = m.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) as cnt
                FROM sys_chat_messages
                WHERE is_read = 0 AND sender_type = 'user'
                GROUP BY user_id
            ) unread ON u.id = unread.user_id
            LEFT JOIN sys_chat_conversations cv ON cv.user_id = u.id
            WHERE 1=1 $searchClause
            ORDER BY m.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($listSql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'users'   => $users,
            'page'    => $page,
            'pages'   => max(1, (int)ceil($total / $limit)),
            'total'   => $total,
            'empty_message' => empty($users)
                ? ($q !== '' ? 'ไม่พบผู้ใช้งานที่ตรงกับคำค้น' : 'ยังไม่มีผู้ใช้งานส่งข้อความเข้ามา')
                : null,
        ]);
        exit;
    }

    if ($action === 'get_messages') {
        $targetUserId = (int)($_GET['user_id'] ?? 0);
        if (!$targetUserId) exit;

        // since_id cursor — initial load uses 0 (returns all), polls send last seen id
        $sinceId = max(0, (int)($_GET['since_id'] ?? 0));

        // Mark unread user-side messages in this conversation as read while admin is viewing.
        // No-op (rowCount=0) once everything's already read.
        $pdo->prepare("UPDATE sys_chat_messages
            SET is_read = 1
            WHERE user_id = :uid AND sender_type = 'user' AND is_read = 0")
            ->execute([':uid' => $targetUserId]);

        $stmt = $pdo->prepare("
            SELECT m.id, m.sender_type, m.user_id, m.staff_id, m.message, m.is_read,
                   m.is_internal, m.created_at,
                   a.full_name as staff_name
            FROM sys_chat_messages m
            LEFT JOIN sys_admins a ON m.staff_id = a.id
            WHERE m.user_id = :uid AND m.id > :since
            ORDER BY m.id ASC
        ");
        $stmt->execute([':uid' => $targetUserId, ':since' => $sinceId]);
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
        $isInternal = !empty($_POST['is_internal']) ? 1 : 0;

        if (!$targetUserId || empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO sys_chat_messages
            (sender_type, user_id, staff_id, message, is_internal)
            VALUES ('staff', :uid, :sid, :msg, :ii)");
        $stmt->execute([
            ':uid' => $targetUserId,
            ':sid' => $staffId,
            ':msg' => $message,
            ':ii'  => $isInternal,
        ]);

        // Re-open the conversation if a real (non-internal) reply lands and it was resolved
        if (!$isInternal) {
            $pdo->prepare("INSERT INTO sys_chat_conversations (user_id, status)
                VALUES (:uid, 'open')
                ON DUPLICATE KEY UPDATE
                    status = IF(status = 'resolved', 'open', status)")
                ->execute([':uid' => $targetUserId]);
        }

        echo json_encode(['success' => true, 'is_internal' => $isInternal]);
        exit;
    }

    if ($action === 'get_customer_profile') {
        $targetUserId = (int)($_GET['user_id'] ?? 0);
        if (!$targetUserId) { echo json_encode(['success' => false, 'error' => 'Invalid user_id']); exit; }

        $u = null;
        try {
            $st = $pdo->prepare("SELECT id, full_name, picture_url, member_id,
                                        student_personnel_id, email, phone_number, department, created_at
                FROM sys_users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $targetUserId]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException) {}
        if (!$u) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }

        $bookings = [];
        try {
            $st = $pdo->prepare("
                SELECT a.id, a.status, a.attended_at, a.created_at AS booked_at,
                       t.slot_date, t.start_time, t.end_time,
                       c.title AS campaign_title
                FROM camp_bookings a
                LEFT JOIN camp_slots t ON a.slot_id = t.id
                LEFT JOIN camp_list  c ON a.campaign_id = c.id
                WHERE a.student_id = :uid
                ORDER BY a.created_at DESC
                LIMIT 5
            ");
            $st->execute([':uid' => $targetUserId]);
            $bookings = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException) {}

        $totals = ['total' => 0, 'attended' => 0];
        try {
            $st = $pdo->prepare("SELECT
                    COUNT(*) AS total,
                    SUM(attended_at IS NOT NULL) AS attended
                FROM camp_bookings WHERE student_id = :uid");
            $st->execute([':uid' => $targetUserId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            $totals = ['total' => (int)($r['total'] ?? 0), 'attended' => (int)($r['attended'] ?? 0)];
        } catch (PDOException) {}

        echo json_encode([
            'success'  => true,
            'user'     => $u,
            'bookings' => $bookings,
            'totals'   => $totals,
        ]);
        exit;
    }

    if ($action === 'set_status') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (!$targetUserId || !in_array($status, ['open', 'pending', 'resolved'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            exit;
        }
        $resolvedAt = $status === 'resolved' ? date('Y-m-d H:i:s') : null;
        $resolvedBy = $status === 'resolved' ? $staffId : null;

        $pdo->prepare("INSERT INTO sys_chat_conversations
            (user_id, status, resolved_at, resolved_by)
            VALUES (:uid, :st, :ra, :rb)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                resolved_at = VALUES(resolved_at),
                resolved_by = VALUES(resolved_by)")
            ->execute([
                ':uid' => $targetUserId,
                ':st'  => $status,
                ':ra'  => $resolvedAt,
                ':rb'  => $resolvedBy,
            ]);

        echo json_encode(['success' => true, 'status' => $status]);
        exit;
    }

} catch (Exception $e) {
    error_log('ajax_support_chat error (' . ($action ?? '?') . '): ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'เกิดข้อผิดพลาดในระบบ']);
}
