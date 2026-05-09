<?php
/**
 * portal/ajax_ai_knowledge.php
 * Custom clinic notes CRUD + preview ของ ai_qa_build_clinic_context()
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ai_knowledge_helper.php';

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

function _csrf_or_die(): void {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        throw new RuntimeException('Invalid CSRF token');
    }
}

try {
    switch ($action) {
        case 'list': {
            echo json_encode([
                'ok'    => true,
                'notes' => list_clinic_notes($pdo),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'preview': {
            require_once __DIR__ . '/../includes/ai_qa_helper.php';
            $context = ai_qa_build_clinic_context($pdo);
            echo json_encode([
                'ok'      => true,
                'context' => $context,
                'length'  => mb_strlen($context),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'create': {
            _csrf_or_die();
            $label = (string)($_POST['label'] ?? '');
            $content = (string)($_POST['content'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            if (trim($label) === '' || trim($content) === '') {
                throw new RuntimeException('label/content ห้ามว่าง');
            }
            $id = create_clinic_note($pdo, $label, $content, $sortOrder, $adminId);
            echo json_encode(['ok' => true, 'id' => $id, 'message' => 'เพิ่ม note แล้ว'], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'update': {
            _csrf_or_die();
            $id = (int)($_POST['id'] ?? 0);
            $label = (string)($_POST['label'] ?? '');
            $content = (string)($_POST['content'] ?? '');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Missing id');
            if (trim($label) === '' || trim($content) === '') {
                throw new RuntimeException('label/content ห้ามว่าง');
            }
            $ok = update_clinic_note($pdo, $id, $label, $content, $sortOrder, $adminId);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'อัปเดตแล้ว' : 'ไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'toggle': {
            _csrf_or_die();
            $id = (int)($_POST['id'] ?? 0);
            $isActive = !empty($_POST['is_active']) && $_POST['is_active'] !== '0';
            if ($id <= 0) throw new RuntimeException('Missing id');
            $ok = toggle_clinic_note($pdo, $id, $isActive);
            echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'delete': {
            _csrf_or_die();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Missing id');
            $ok = delete_clinic_note($pdo, $id);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'ลบแล้ว' : 'ไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
            return;
        }

        default:
            throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
