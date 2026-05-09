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
$_role = $_SESSION['admin_role'] ?? '';
if ($_role !== 'superadmin' && $_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
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

        default:
            throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
