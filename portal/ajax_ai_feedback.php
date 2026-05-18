<?php
/**
 * portal/ajax_ai_feedback.php
 * Actions (POST): save_rating, delete
 * Actions (GET):  list, summary
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ai_feedback_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

// save_rating เปิดให้ portal admin ทุกคนทำ; list/delete ต้องมี access_ai
$_role   = $_SESSION['admin_role'] ?? '';
$isAdmin = ($_role === 'superadmin') || !empty($_SESSION['access_ai']);

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
$pdo     = db();

try {
    switch ($action) {

        case 'save_rating': {
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
                return;
            }
            $rating  = (int)($_POST['rating'] ?? 0);
            if (!in_array($rating, [1, -1], true)) {
                echo json_encode(['ok' => false, 'error' => 'rating ต้องเป็น 1 หรือ -1']);
                return;
            }
            $msgId   = trim((string)($_POST['msg_id']   ?? ''));
            $question= trim((string)($_POST['question'] ?? ''));
            $answer  = trim((string)($_POST['answer']   ?? ''));
            $comment = trim((string)($_POST['comment']  ?? ''));
            $source  = trim((string)($_POST['source']   ?? 'portal_chat'));

            if ($question === '' || $answer === '') {
                echo json_encode(['ok' => false, 'error' => 'ข้อมูลไม่ครบ']);
                return;
            }

            $id = feedback_save($pdo, $source, $msgId, $question, $answer, $rating, $comment, $adminId);
            echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'list': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Permission denied']); return; }
            $page   = max(1, (int)($_GET['page']   ?? 1));
            $limit  = max(1, min(50, (int)($_GET['limit'] ?? 20)));
            $rating = (int)($_GET['rating'] ?? 0);
            $source = trim((string)($_GET['source'] ?? ''));

            $result = feedback_list($pdo, $page, $limit, $rating, $source);
            echo json_encode([
                'ok'    => true,
                'rows'  => $result['rows'],
                'total' => $result['total'],
                'page'  => $page,
                'pages' => (int)ceil($result['total'] / $limit),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'summary': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Permission denied']); return; }
            echo json_encode(['ok' => true] + feedback_summary($pdo), JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'delete': {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Permission denied']); return; }
            if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
                return;
            }
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'id ไม่ถูกต้อง']); return; }
            feedback_delete($pdo, $id);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('[ajax_ai_feedback] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
