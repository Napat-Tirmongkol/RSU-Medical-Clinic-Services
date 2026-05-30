<?php
/**
 * portal/ajax_identity_users.php
 * Handles server-side search and pagination for Identity & Governance Users
 */
declare(strict_types=1);

// Load configuration and authentication
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php'; // Ensure security and starts session

header('Content-Type: application/json; charset=utf-8');

// Role gate — this endpoint returns PHI (citizen_id, phone, email) so an
// "editor" admin must not reach it. Restrict to superadmin/admin or staff
// holding access_registry / access_identity flag.
$adminRole = $_SESSION['admin_role'] ?? '';
$hasAccess = ($adminRole === 'superadmin')
          || ($adminRole === 'admin')
          || !empty($_SESSION['access_registry'])
          || !empty($_SESSION['access_identity']);
if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
    exit;
}

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

    // 2. Fetch records with LIMIT/OFFSET. Pulled fields cover what the
    //    "ข้อมูลผู้ใช้งาน" modal renders end-to-end (identity, contact,
    //    demographics, faculty, emergency contact, PDPA consent state).
    //    Sensitive columns (health + raw IP/UA) are nulled out below for
    //    non-superadmin viewers — keeping the SELECT shape stable makes
    //    the frontend code branch-free.
    // Check if line_user_id_new column exists (post-migration)
    $hasNewLineCol = false;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM sys_users LIKE 'line_user_id_new'");
        $hasNewLineCol = (bool)$col->fetch();
    } catch (PDOException) {}

    $lineNewCol = $hasNewLineCol ? "line_user_id_new," : "";

    $sql = "SELECT id, prefix, full_name, first_name, last_name,
                   student_personnel_id, citizen_id, line_user_id, {$lineNewCol} member_id,
                   phone_number, email, department, gender, status, status_other,
                   date_of_birth,
                   blood_type, height_cm, weight_kg, allergies, chronic_conditions,
                   emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                   consent_general_accepted_at,   consent_general_version,
                   consent_sensitive_accepted_at, consent_sensitive_version,
                   consent_ip, consent_user_agent,
                   created_at
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

    // Mask citizen_id to last-4 digits — only superadmin sees the full value.
    // Reduces PHI exposure surface in list responses (PDPA Art. 32 data minimization).
    // Same tiered redaction applied to LINE User ID, raw IP, raw UA — these
    // are forensic-grade identifiers that an editor/access_registry viewer
    // doesn't need to see in plain form to do their job.
    // Always expose has_line flag — frontend ใช้ตัดสินใจว่าจะ enable
    // ปุ่ม "ทดสอบส่ง LINE" หรือไม่ (ไม่ leak full UID ไปฝั่ง non-superadmin)
    foreach ($users as &$u) {
        $u['has_line'] = !empty($u['line_user_id']) || !empty($u['line_user_id_new'] ?? '');
    }
    unset($u);

    if ($adminRole !== 'superadmin') {
        foreach ($users as &$u) {
            if (!empty($u['citizen_id']) && strlen((string)$u['citizen_id']) >= 4) {
                $u['citizen_id'] = str_repeat('•', max(0, strlen((string)$u['citizen_id']) - 4))
                                 . substr((string)$u['citizen_id'], -4);
            }
            // LINE User ID: keep last-6 only ("…abcdef") so support staff
            // can correlate without exposing the full opaque user key
            if (!empty($u['line_user_id'])) {
                $lid = (string)$u['line_user_id'];
                if (strlen($lid) > 6) $u['line_user_id'] = '…' . substr($lid, -6);
            }
            if (!empty($u['line_user_id_new'] ?? '')) {
                $lid = (string)$u['line_user_id_new'];
                if (strlen($lid) > 6) $u['line_user_id_new'] = '…' . substr($lid, -6);
            }
            // Drop raw forensic evidence from non-superadmin payloads
            $u['consent_ip']         = null;
            $u['consent_user_agent'] = null;
            // Health data is Sec. 26 sensitive — only superadmin sees raw
            // values in the identity console. Other admins can still see
            // "has health data on file" indirectly via consent status
            foreach (['blood_type', 'height_cm', 'weight_kg', 'allergies', 'chronic_conditions'] as $col) {
                if (!empty($u[$col])) $u[$col] = '[ข้อมูลอ่อนไหว — ดูได้เฉพาะ superadmin]';
            }
        }
        unset($u);
    }

    // Audit log — PHI read access (PDPA Art. 39 record-of-processing).
    if (function_exists('log_activity')) {
        log_activity('identity_users_search',
            "search=" . ($search !== '' ? mb_substr($search, 0, 64) : '(all)')
            . " page={$page} size={$pageSize} rows=" . count($users));
    }

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
    error_log('[ajax_identity_users] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'ระบบขัดข้อง กรุณาลองใหม่'
    ]);
}
