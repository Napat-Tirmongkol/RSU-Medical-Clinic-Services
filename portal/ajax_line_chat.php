<?php
// portal/ajax_line_chat.php — LINE Admin Chat AJAX
// Entities: conversation (list/get), message (send_reply)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/line_chat_helper.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper = ($adminRole === 'superadmin');
$canAccess = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_ai']);
if (!$canAccess) { echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้ LINE Chat']); exit; }

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่พบ admin id']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$entity = $_GET['entity'] ?? $_POST['entity'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($method === 'POST') {
    if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
}

try {
    if ($entity === 'conversation') {
        switch ($action) {
            case 'list': {
                $filter = (string)($_GET['filter'] ?? $_POST['filter'] ?? 'all');
                if (!in_array($filter, ['all', 'needs_reply', 'today'], true)) $filter = 'all';
                $page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
                $perPage = max(1, min(100, (int)($_GET['per_page'] ?? $_POST['per_page'] ?? 20)));
                $result = line_chat_list_conversations($pdo, $filter, $page, $perPage);
                echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'get': {
                $lineUserId = (string)($_GET['line_user_id'] ?? '');
                if ($lineUserId === '') { echo json_encode(['ok' => false, 'message' => 'line_user_id ว่าง']); exit; }
                $limit = max(1, min(1000, (int)($_GET['limit'] ?? 200)));
                $convo = line_chat_get_conversation($pdo, $lineUserId, $limit);
                echo json_encode(['ok' => true, 'data' => $convo], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    if ($entity === 'message') {
        switch ($action) {
            case 'send_reply': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $lineUserId = (string)($_POST['line_user_id'] ?? '');
                $text = (string)($_POST['message'] ?? '');
                $result = line_chat_send_admin_reply($pdo, $lineUserId, $text, $adminId);
                if (!$result['ok']) {
                    echo json_encode([
                        'ok' => false,
                        'message' => 'ส่ง LINE ไม่สำเร็จ' . (!empty($result['error']) ? ': ' . $result['error'] : ''),
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown entity/action']);
} catch (RuntimeException $e) {
    error_log('[ajax_line_chat] runtime: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ajax_line_chat] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาดในระบบ — โปรดลองอีกครั้ง'], JSON_UNESCAPED_UNICODE);
}
