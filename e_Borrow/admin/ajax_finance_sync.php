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

// Role gate — mirror manage_fines.php's $_eb_hasFinance check exactly,
// so any user who sees the "ส่งเข้าระบบการเงิน" button can actually use it.
// (page gate: role admin/superadmin OR access_finance flag — also librarian
//  if they manage fines)
$_eb_role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? '';
$_canFinance = in_array($_eb_role, ['admin', 'superadmin', 'librarian'], true)
            || !empty($_SESSION['access_finance']);
if (!$_canFinance) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ส่งเข้าระบบการเงิน (role=' . htmlspecialchars($_eb_role) . ')']);
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
    echo json_encode(['ok' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
    exit;
}

$payload = [
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
];

error_log('[eborrow finance_sync] in: ' . json_encode([
    'src' => $payload['source_module'] . ':' . $payload['source_id'],
    'amount' => $payload['amount'],
    'date' => $payload['txn_date'],
    'role' => $_eb_role,
], JSON_UNESCAPED_UNICODE));

try {
    $pdo = db();
    $result = finance_sync_upsert($pdo, $payload);

    if (!empty($result['ok'])) {
        // Verify the row landed in the DB and read back the txn_date so the
        // frontend can show "บันทึกแล้วสำหรับวันที่ ..." — important for re-sync
        // of old payments where date stays in the original (often previous) month.
        $rec = $pdo->prepare("SELECT id, txn_date, amount FROM sys_finance_transactions WHERE id=?");
        $rec->execute([(int)($result['id'] ?? 0)]);
        $row = $rec->fetch(PDO::FETCH_ASSOC) ?: [];

        error_log('[eborrow finance_sync] ok id=' . ($result['id'] ?? '?') . ' mode=' . ($result['mode'] ?? '?'));
        echo json_encode([
            'ok'       => true,
            'id'       => (int)($result['id'] ?? 0),
            'mode'     => $result['mode'] ?? 'created',
            'txn_date' => $row['txn_date'] ?? $payload['txn_date'],
            'amount'   => isset($row['amount']) ? (float)$row['amount'] : $payload['amount'],
        ]);
    } else {
        error_log('[eborrow finance_sync] fail: ' . ($result['error'] ?? 'unknown'));
        echo json_encode(['ok' => false, 'message' => $result['error'] ?? 'บันทึกไม่สำเร็จ']);
    }
} catch (Throwable $e) {
    error_log('[eborrow finance_sync] throw: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาดในระบบ']);
}
