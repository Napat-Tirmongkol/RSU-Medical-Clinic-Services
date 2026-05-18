<?php
/**
 * portal/ajax_ai_chunks.php
 * CRUD + embedding + semantic search สำหรับ sys_ai_knowledge_chunks
 *
 * Actions (GET): list, get
 * Actions (POST): create, update, delete, toggle, embed, embed_all, search
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ai_chunk_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

$_role = $_SESSION['admin_role'] ?? '';
if ($_role !== 'superadmin' && empty($_SESSION['access_ai'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied (access_ai required)']);
    exit;
}

$pdo     = db();
$action  = $_POST['action'] ?? $_GET['action'] ?? 'list';
$adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;

function _csrf(): void {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        throw new RuntimeException('Invalid CSRF token');
    }
}

try {
    switch ($action) {

        // ── Read ───────────────────────────────────────────────────────────
        case 'list': {
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = max(1, min(100, (int)($_GET['limit'] ?? 20)));
            $search = trim((string)($_GET['q'] ?? ''));
            $source = trim((string)($_GET['source'] ?? ''));

            $result = chunk_list($pdo, $page, $limit, $search, $source);
            echo json_encode([
                'ok'     => true,
                'chunks' => $result['chunks'],
                'total'  => $result['total'],
                'page'   => $page,
                'limit'  => $limit,
                'pages'  => (int)ceil($result['total'] / $limit),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'get': {
            $id  = (int)($_GET['id'] ?? 0);
            $row = chunk_get($pdo, $id);
            if (!$row) {
                echo json_encode(['ok' => false, 'error' => 'ไม่พบ chunk']);
                return;
            }
            unset($row['embedding_json']); // ไม่ส่ง vector ออกมา (ใหญ่เกิน)
            echo json_encode(['ok' => true, 'chunk' => $row], JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── Write ──────────────────────────────────────────────────────────
        case 'create': {
            _csrf();
            $title  = trim((string)($_POST['title']  ?? ''));
            $content= trim((string)($_POST['content'] ?? ''));
            $tags   = trim((string)($_POST['tags']    ?? ''));
            $source = trim((string)($_POST['source_label'] ?? 'manual'));
            $sort   = (int)($_POST['sort_order'] ?? 0);

            if ($title === '' || $content === '') {
                echo json_encode(['ok' => false, 'error' => 'กรอกหัวข้อ + เนื้อหา']);
                return;
            }

            $id = chunk_create($pdo, $title, $content, $tags, $source, $sort, $adminId);
            echo json_encode(['ok' => true, 'id' => $id, 'message' => 'เพิ่ม chunk แล้ว'], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'update': {
            _csrf();
            $id     = (int)($_POST['id'] ?? 0);
            $title  = trim((string)($_POST['title']  ?? ''));
            $content= trim((string)($_POST['content'] ?? ''));
            $tags   = trim((string)($_POST['tags']    ?? ''));
            $source = trim((string)($_POST['source_label'] ?? 'manual'));
            $sort   = (int)($_POST['sort_order'] ?? 0);

            if ($id <= 0 || $title === '' || $content === '') {
                echo json_encode(['ok' => false, 'error' => 'ข้อมูลไม่ครบ']);
                return;
            }

            chunk_update($pdo, $id, $title, $content, $tags, $source, $sort, $adminId);
            echo json_encode(['ok' => true, 'message' => 'อัปเดตแล้ว (embedding ถูกล้าง — กรุณา embed ใหม่)'], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'delete': {
            _csrf();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'id ไม่ถูกต้อง']);
                return;
            }
            chunk_delete($pdo, $id);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว'], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'toggle': {
            _csrf();
            $id       = (int)($_POST['id'] ?? 0);
            $isActive = (bool)(int)($_POST['is_active'] ?? 0);
            chunk_toggle($pdo, $id, $isActive);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── Embedding ──────────────────────────────────────────────────────
        case 'embed': {
            _csrf();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'id ไม่ถูกต้อง']);
                return;
            }
            chunk_embed_and_save($pdo, $id);
            echo json_encode(['ok' => true, 'message' => 'สร้าง embedding สำเร็จ'], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'embed_all': {
            _csrf();
            $max  = min(50, max(1, (int)($_POST['max'] ?? 20)));
            $done = chunk_embed_pending($pdo, $max);
            echo json_encode(['ok' => true, 'embedded' => $done, 'message' => "embed $done chunks สำเร็จ"], JSON_UNESCAPED_UNICODE);
            return;
        }

        // ── Semantic Search ────────────────────────────────────────────────
        case 'search': {
            _csrf();
            $query = trim((string)($_POST['query'] ?? ''));
            $topK  = min(10, max(1, (int)($_POST['top_k'] ?? 5)));
            if ($query === '') {
                echo json_encode(['ok' => false, 'error' => 'กรอก query ก่อน']);
                return;
            }
            $results = chunk_semantic_search($pdo, $query, $topK);
            echo json_encode(['ok' => true, 'results' => $results, 'count' => count($results)], JSON_UNESCAPED_UNICODE);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('[ajax_ai_chunks] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
