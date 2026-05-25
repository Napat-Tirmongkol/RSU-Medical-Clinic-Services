<?php
// portal/ajax_line_chat.php — LINE Admin Chat AJAX
// Entities:
//   conversation  → list / get / set_resolved / set_tags / set_note / get_state
//   message       → send_reply
//   template      → list / create / update / delete / toggle / bump_use
//   ai            → suggest_reply
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
                if (!in_array($filter, ['all', 'needs_reply', 'today', 'resolved', 'unresolved'], true)) $filter = 'all';
                $page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
                $perPage = max(1, min(100, (int)($_GET['per_page'] ?? $_POST['per_page'] ?? 20)));
                $search = (string)($_GET['q'] ?? $_POST['q'] ?? '');
                $result = line_chat_list_conversations($pdo, $filter, $page, $perPage, $search);
                echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'get': {
                $lineUserId = (string)($_GET['line_user_id'] ?? '');
                if ($lineUserId === '') { echo json_encode(['ok' => false, 'message' => 'line_user_id ว่าง']); exit; }
                $limit = max(1, min(1000, (int)($_GET['limit'] ?? 200)));
                $convo = line_chat_get_conversation($pdo, $lineUserId, $limit);
                $convo['state'] = line_chat_get_convo_state($pdo, $lineUserId);
                echo json_encode(['ok' => true, 'data' => $convo], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'set_resolved': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $lineUserId = (string)($_POST['line_user_id'] ?? '');
                $resolved = !empty($_POST['resolved']) && $_POST['resolved'] !== '0';
                if ($lineUserId === '') { echo json_encode(['ok' => false, 'message' => 'line_user_id ว่าง']); exit; }
                $ok = line_chat_set_resolved($pdo, $lineUserId, $resolved, $adminId);
                echo json_encode(['ok' => $ok, 'data' => ['is_resolved' => $resolved ? 1 : 0]], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'set_tags': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $lineUserId = (string)($_POST['line_user_id'] ?? '');
                $tagsRaw = (string)($_POST['tags'] ?? '');
                if ($lineUserId === '') { echo json_encode(['ok' => false, 'message' => 'line_user_id ว่าง']); exit; }
                $tags = array_filter(array_map('trim', explode(',', $tagsRaw)));
                $ok = line_chat_set_tags($pdo, $lineUserId, $tags, $adminId);
                $state = line_chat_get_convo_state($pdo, $lineUserId);
                echo json_encode(['ok' => $ok, 'data' => ['tags' => $state['tags_list']]], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'set_note': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $lineUserId = (string)($_POST['line_user_id'] ?? '');
                $note = (string)($_POST['note'] ?? '');
                if ($lineUserId === '') { echo json_encode(['ok' => false, 'message' => 'line_user_id ว่าง']); exit; }
                $ok = line_chat_set_note($pdo, $lineUserId, $note, $adminId);
                $state = line_chat_get_convo_state($pdo, $lineUserId);
                echo json_encode(['ok' => $ok, 'data' => $state], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'get_state': {
                $lineUserId = (string)($_GET['line_user_id'] ?? '');
                if ($lineUserId === '') { echo json_encode(['ok' => false, 'message' => 'line_user_id ว่าง']); exit; }
                $state = line_chat_get_convo_state($pdo, $lineUserId);
                echo json_encode(['ok' => true, 'data' => $state], JSON_UNESCAPED_UNICODE);
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
                $templateId = (int)($_POST['template_id'] ?? 0);
                $result = line_chat_send_admin_reply($pdo, $lineUserId, $text, $adminId);
                if (!$result['ok']) {
                    echo json_encode([
                        'ok' => false,
                        'message' => 'ส่ง LINE ไม่สำเร็จ' . (!empty($result['error']) ? ': ' . $result['error'] : ''),
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                if ($templateId > 0) line_chat_template_bump_use($pdo, $templateId);
                echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    if ($entity === 'template') {
        switch ($action) {
            case 'list': {
                $activeOnly = !empty($_GET['active_only']) || !empty($_POST['active_only']);
                $items = line_chat_template_list($pdo, $activeOnly);
                echo json_encode(['ok' => true, 'data' => ['items' => $items]], JSON_UNESCAPED_UNICODE);
                exit;
            }
            case 'create': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $id = line_chat_template_create(
                    $pdo,
                    (string)($_POST['title'] ?? ''),
                    (string)($_POST['body'] ?? ''),
                    (string)($_POST['category'] ?? 'ทั่วไป'),
                    $adminId
                );
                echo json_encode(['ok' => true, 'data' => ['id' => $id]], JSON_UNESCAPED_UNICODE);
                exit;
            }
            case 'update': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ว่าง']); exit; }
                $sort = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : null;
                $ok = line_chat_template_update(
                    $pdo, $id,
                    (string)($_POST['title'] ?? ''),
                    (string)($_POST['body'] ?? ''),
                    (string)($_POST['category'] ?? 'ทั่วไป'),
                    $sort
                );
                echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
                exit;
            }
            case 'delete': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ว่าง']); exit; }
                $ok = line_chat_template_delete($pdo, $id);
                echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
                exit;
            }
            case 'toggle': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ว่าง']); exit; }
                $ok = line_chat_template_toggle($pdo, $id);
                echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    if ($entity === 'ai') {
        switch ($action) {
            case 'suggest_reply': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $lineUserId = (string)($_POST['line_user_id'] ?? '');
                $hint = (string)($_POST['hint'] ?? '');
                $result = line_chat_suggest_reply($pdo, $lineUserId, $hint);
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
