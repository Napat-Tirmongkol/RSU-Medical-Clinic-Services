<?php
/**
 * portal/ajax_identity_users.php
 * Handles server-side search and pagination for Identity & Governance Users
 */
declare(strict_types=1);

// Load configuration and authentication
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php'; // Ensure security and starts session

header('Content-Type: application/json');

$pdo = db();
$search   = trim($_GET['search'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = max(10, min(100, (int)($_GET['pageSize'] ?? 25)));
$offset   = ($page - 1) * $pageSize;

try {
    // 1. Count total records for pagination
    $countSql = "SELECT COUNT(*) FROM sys_users WHERE 1=1";
    $countParams = [];
    if ($search !== '') {
        $countSql .= " AND (full_name LIKE :s1 OR student_personnel_id LIKE :s2 OR citizen_id LIKE :s3 OR email LIKE :s4)";
        $like = "%$search%";
        $countParams[':s1'] = $like;
        $countParams[':s2'] = $like;
        $countParams[':s3'] = $like;
        $countParams[':s4'] = $like;
    }
    
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($countParams);
    $totalRecords = (int)$stmtCount->fetchColumn();

    // 2. Fetch records with LIMIT/OFFSET
    $sql = "SELECT id, full_name, student_personnel_id, citizen_id, phone_number, email, department, gender, status, status_other, created_at 
            FROM sys_users WHERE 1=1";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (full_name LIKE :s1 OR student_personnel_id LIKE :s2 OR citizen_id LIKE :s3 OR email LIKE :s4)";
        $like = "%$search%";
        $params[':s1'] = $like;
        $params[':s2'] = $like;
        $params[':s3'] = $like;
        $params[':s4'] = $like;
    }
    $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    // Bind search parameters if any
    if ($search !== '') {
        $stmt->bindValue(':s1', $params[':s1'], PDO::PARAM_STR);
        $stmt->bindValue(':s2', $params[':s2'], PDO::PARAM_STR);
        $stmt->bindValue(':s3', $params[':s3'], PDO::PARAM_STR);
        $stmt->bindValue(':s4', $params[':s4'], PDO::PARAM_STR);
    }
    
    // Bind pagination parameters
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Format response
    echo json_encode([
        'status' => 'success',
        'data' => $users,
        'pagination' => [
            'total' => $totalRecords,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalPages' => ceil($totalRecords / $pageSize)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
