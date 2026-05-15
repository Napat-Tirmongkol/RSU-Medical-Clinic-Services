<?php
/**
 * e_Borrow/admin/ajax_finance_sync.php
 *
 * Bridges e_Borrow → Cash Book without requiring a portal admin session.
 *
 * Why this exists: portal/ajax_finance.php is gated by
 * check_admin_session() which only passes for users logged in via
 * the portal admin login. Users who logged in directly through
 * e_Borrow have $_SESSION['user_id'] but NOT $_SESSION['admin_logged_in'],
 * so calling the portal endpoint redirects them to the login page —
 * the AJAX client then receives HTML and throws "Unexpected token '<'".
 *
 * This endpoint validates the e_Borrow session (user_id + role) and
 * delegates to finance_sync_upsert() in includes/finance_sync_helper.php,
 * which is the canonical idempotent upsert for cross-module finance sync.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/finance_sync_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

// Role check — only admin / librarian may post to Cash Book from e_Borrow
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'librarian'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ส่งเข้าระบบการเงิน']);
    exit;
}

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'CSRF token ไม่ถูกต้อง — กรุณารีเฟรชหน้าจอ']);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action !== 'txn:upsert_from_source') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Unknown action']);
    exit;
}

try {
    $pdo = db();
    $result = finance_sync_upsert($pdo, [
        'source_module' => (string)($_POST['source_module'] ?? ''),
        'source_id'     => (string)($_POST['source_id']     ?? ''),
        'kind'          => (string)($_POST['kind']          ?? 'income'),
        'amount'        => (float) ($_POST['amount']        ?? 0),
        'txn_date'      => (string)($_POST['txn_date']      ?? date('Y-m-d')),
        'description'   => (string)($_POST['description']   ?? ''),
        'category_name' => (string)($_POST['category_name'] ?? ''),
        'reference'     => (string)($_POST['reference']     ?? ''),
        'note'          => (string)($_POST['note']          ?? ''),
        'admin_id'      => (int)($_SESSION['user_id']       ?? 0) ?: null,
    ]);

    if (!empty($result['ok'])) {
        echo json_encode(['ok' => true, 'id' => $result['id'] ?? 0, 'mode' => $result['mode'] ?? 'created']);
    } else {
        echo json_encode(['ok' => false, 'message' => $result['error'] ?? 'บันทึกไม่สำเร็จ']);
    }
} catch (Throwable $e) {
    error_log('[eborrow ajax_finance_sync] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาดในระบบ — ลองอีกครั้ง']);
}
