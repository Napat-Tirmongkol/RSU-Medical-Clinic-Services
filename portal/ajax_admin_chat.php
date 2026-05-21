<?php
// portal/ajax_admin_chat.php — AI Admin Chat AJAX
// Entities: thread (create/list/get/archive/delete), message (send)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/admin_chat_helper.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper = ($adminRole === 'superadmin');
$canAccess = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_ai']);
if (!$canAccess) { echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้ AI Admin Chat']); exit; }

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่พบ admin id']); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$entity = $_GET['entity'] ?? $_POST['entity'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// CSRF for mutations
if ($method === 'POST') {
    if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
}

try {
    // ──────────────────────────────────────────────
    // THREAD entity
    // ──────────────────────────────────────────────
    if ($entity === 'thread') {
        switch ($action) {
            case 'list': {
                $page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
                $perPage = max(1, min(100, (int)($_GET['per_page'] ?? $_POST['per_page'] ?? 20)));
                $includeArchived = !empty($_GET['include_archived']) || !empty($_POST['include_archived']);
                $result = admin_chat_list_threads($pdo, $adminId, $page, $perPage, $includeArchived);
                echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'get': {
                $threadId = (int)($_GET['thread_id'] ?? 0);
                if ($threadId <= 0) { echo json_encode(['ok' => false, 'message' => 'thread_id ไม่ถูกต้อง']); exit; }
                $thread = admin_chat_get_thread($pdo, $threadId, $adminId);
                if (!$thread) { echo json_encode(['ok' => false, 'message' => 'ไม่พบ thread']); exit; }
                echo json_encode(['ok' => true, 'data' => $thread], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'create': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $title = trim((string)($_POST['title'] ?? ''));
                $threadId = admin_chat_create_thread($pdo, $adminId, $title ?: null);
                echo json_encode(['ok' => true, 'thread_id' => $threadId], JSON_UNESCAPED_UNICODE);
                exit;
            }

            case 'archive': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $threadId = (int)($_POST['thread_id'] ?? 0);
                $archive = !empty($_POST['archive']);
                $ok = admin_chat_archive_thread($pdo, $threadId, $adminId, $archive);
                echo json_encode(['ok' => $ok]);
                exit;
            }

            case 'delete': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $threadId = (int)($_POST['thread_id'] ?? 0);
                $ok = admin_chat_delete_thread($pdo, $threadId, $adminId);
                echo json_encode(['ok' => $ok]);
                exit;
            }
        }
    }

    // ──────────────────────────────────────────────
    // MESSAGE entity
    // ──────────────────────────────────────────────
    if ($entity === 'message') {
        switch ($action) {
            case 'send': {
                if ($method !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
                $threadId = (int)($_POST['thread_id'] ?? 0);
                $userMessage = (string)($_POST['message'] ?? '');

                // Auto-create thread if 0
                if ($threadId <= 0) {
                    $threadId = admin_chat_create_thread($pdo, $adminId, null);
                }

                $result = admin_chat_send_message($pdo, $threadId, $adminId, $userMessage);
                echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown entity/action']);
} catch (RuntimeException $e) {
    // Helper-thrown user-facing errors (safe to echo)
    error_log('[ajax_admin_chat] runtime: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // System errors — do NOT leak details to client
    error_log('[ajax_admin_chat] error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาดในระบบ — โปรดลองอีกครั้ง'], JSON_UNESCAPED_UNICODE);
}
