<?php
/**
 * portal/ajax_ai_prompts.php
 * อ่าน/เขียน AI prompts ที่ใช้ใน matcher + generator
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_prompts_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
// Authorization: ตรงกับ section gate ใน portal/index.php — superadmin หรือมี access_ai
$_role = $_SESSION['admin_role'] ?? '';
if ($_role !== 'superadmin' && empty($_SESSION['access_ai'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied (access_ai required)']);
    exit;
}

$pdo = db();
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

try {
    switch ($action) {
        case 'list': {
            echo json_encode([
                'ok'      => true,
                'prompts' => list_ai_prompts($pdo),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'save': {
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new RuntimeException('Invalid CSRF token');
            }
            $key = trim((string)($_POST['key'] ?? ''));
            $content = (string)($_POST['content'] ?? '');
            if ($key === '' || trim($content) === '') {
                throw new RuntimeException('Missing key or content');
            }
            $ok = save_ai_prompt($pdo, $key, $content, $adminId);
            echo json_encode([
                'ok'      => $ok,
                'message' => $ok ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'reset': {
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new RuntimeException('Invalid CSRF token');
            }
            $key = trim((string)($_POST['key'] ?? ''));
            if ($key === '') throw new RuntimeException('Missing key');
            $ok = reset_ai_prompt($pdo, $key);
            echo json_encode([
                'ok'      => $ok,
                'message' => $ok ? 'รีเซ็ตเป็น default แล้ว' : 'รีเซ็ตไม่สำเร็จ',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'history': {
            $key = trim((string)($_GET['key'] ?? $_POST['key'] ?? ''));
            if ($key === '') throw new RuntimeException('Missing key');
            echo json_encode([
                'ok'      => true,
                'history' => list_ai_prompt_history($pdo, $key, 50),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'rollback': {
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new RuntimeException('Invalid CSRF token');
            }
            $historyId = (int)($_POST['history_id'] ?? 0);
            if ($historyId <= 0) throw new RuntimeException('Missing history_id');
            $ok = rollback_ai_prompt($pdo, $historyId, $adminId);
            echo json_encode([
                'ok'      => $ok,
                'message' => $ok ? 'Rollback สำเร็จ' : 'Rollback ไม่สำเร็จ',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'test': {
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new RuntimeException('Invalid CSRF token');
            }
            $key = trim((string)($_POST['key'] ?? ''));
            $content = (string)($_POST['content'] ?? '');
            $varsJson = (string)($_POST['vars'] ?? '{}');
            $vars = json_decode($varsJson, true);
            if (!is_array($vars)) $vars = [];
            if ($key === '' || trim($content) === '') {
                throw new RuntimeException('Missing key or content');
            }
            $result = test_ai_prompt($key, $content, $vars);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        default:
            throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    error_log('[ajax_ai_prompts] ' . $e->getMessage());
    http_response_code(400);
    $isValidation = $e instanceof RuntimeException || $e instanceof InvalidArgumentException;
    echo json_encode([
        'ok'    => false,
        'error' => $isValidation ? $e->getMessage() : 'Server error',
    ], JSON_UNESCAPED_UNICODE);
}
