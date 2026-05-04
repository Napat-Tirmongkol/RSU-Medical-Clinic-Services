<?php
// user/ajax_borrow_history.php — paginated borrow history for in-hub modal
declare(strict_types=1);
@session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (is_under_maintenance('e_borrow')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'maintenance', 'message' => 'ระบบ e-Borrow ปิดปรับปรุงชั่วคราว']);
    exit;
}

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
    $stmt->execute([':lid' => $lineUserId]);
    $studentId = (int) ($stmt->fetchColumn() ?: 0);
    if ($studentId === 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'profile_not_found']);
        exit;
    }

    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;

    $where = "WHERE t.borrower_student_id = :sid
              AND (t.status IN ('returned','cancelled')
                   OR t.approval_status IN ('pending','rejected'))";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_records t {$where}");
    $countStmt->execute([':sid' => $studentId]);
    $total      = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    $listStmt = $pdo->prepare("
        SELECT t.id, t.borrow_date, t.due_date, t.return_date,
               t.status, t.approval_status,
               et.name AS type_name, et.image_url,
               ei.name AS eq_name
        FROM borrow_records t
        JOIN borrow_categories et ON t.type_id = et.id
        LEFT JOIN borrow_items ei ON t.item_id = ei.id
        {$where}
        ORDER BY t.borrow_date DESC, t.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $listStmt->execute([':sid' => $studentId]);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'   => true,
        'rows' => $rows,
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('ajax_borrow_history error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
