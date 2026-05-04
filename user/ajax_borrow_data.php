<?php
// user/ajax_borrow_data.php — combined read endpoint for the in-hub borrow modal
// Returns: categories (browse list), staff (lending approvers), active borrows, fines.
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

    // Resolve internal student id from LINE user id
    $stmt = $pdo->prepare("SELECT id FROM sys_users WHERE line_user_id = :lid LIMIT 1");
    $stmt->execute([':lid' => $lineUserId]);
    $studentId = (int) ($stmt->fetchColumn() ?: 0);
    if ($studentId === 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'profile_not_found']);
        exit;
    }

    // Bridge for downstream e_Borrow process scripts (submit/cancel)
    $_SESSION['student_id'] = $studentId;

    // ── Categories (browse) ──────────────────────────────────────────────
    $catStmt = $pdo->query("
        SELECT id, name, description, image_url, available_quantity
        FROM borrow_categories
        WHERE available_quantity > 0
        ORDER BY name ASC
    ");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Staff list (lending approvers) ───────────────────────────────────
    // Mirrors e_Borrow/ajax/get_staff_list.php
    $staff = [];
    try {
        $sStmt = $pdo->query("
            SELECT id, full_name
            FROM sys_staff
            WHERE account_status = 'active'
            ORDER BY full_name ASC
        ");
        $staff = $sStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) { /* table may not exist yet */ }

    // ── Active borrows (for refresh after submit/cancel) ─────────────────
    $aStmt = $pdo->prepare("
        SELECT t.id AS transaction_id, t.borrow_date, t.due_date,
               t.approval_status, t.status,
               ei.name AS equipment_name,
               et.image_url, et.name AS type_name
        FROM borrow_records t
        JOIN borrow_items ei      ON t.item_id = ei.id
        JOIN borrow_categories et ON t.type_id = et.id
        WHERE t.borrower_student_id = :sid
          AND t.status = 'borrowed'
          AND t.approval_status IN ('approved','pending')
        ORDER BY t.borrow_date DESC
    ");
    $aStmt->execute([':sid' => $studentId]);
    $active = $aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ── Pending fines total ──────────────────────────────────────────────
    $fStmt = $pdo->prepare("
        SELECT COALESCE(SUM(f.amount), 0) AS total
        FROM borrow_fines f
        JOIN borrow_records t ON f.transaction_id = t.id
        WHERE t.borrower_student_id = :sid AND f.status = 'pending'
    ");
    $fStmt->execute([':sid' => $studentId]);
    $totalFine = (float) $fStmt->fetchColumn();

    echo json_encode([
        'ok'          => true,
        'categories'  => $categories,
        'staff'       => $staff,
        'active'      => $active,
        'total_fine'  => $totalFine,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('ajax_borrow_data error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
