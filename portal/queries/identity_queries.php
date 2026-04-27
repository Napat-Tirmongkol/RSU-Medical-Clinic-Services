<?php
/**
 * portal/queries/identity_queries.php
 * Fetches data for Identity & Governance (Users, Admins, Staff)
 */

$idSearch = $_GET['id_search'] ?? '';

// (0b) IDENTITY SECTION — USER QUERY
// [REFACTORED] Now handled via AJAX in portal/ajax_identity_users.php to prevent performance issues
$idUsers = []; 
$totalIdUsers = (int) $pdo->query("SELECT COUNT(*) FROM sys_users")->fetchColumn();

// Fetch stats for the distribution bar (optimized SQL)
$statsUserType = ['student' => 0, 'staff' => 0, 'other' => 0];
$typeRows = $pdo->query("SELECT status, COUNT(*) as cnt FROM sys_users GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($typeRows as $row) {
    if ($row['status'] === 'student') $statsUserType['student'] = (int)$row['cnt'];
    elseif ($row['status'] === 'staff') $statsUserType['staff'] = (int)$row['cnt'];
    else $statsUserType['other'] += (int)$row['cnt'];
}

$idActiveCount = (int) $pdo->query("
    SELECT COUNT(DISTINCT id) FROM sys_users
    WHERE id IN (SELECT student_id FROM camp_bookings WHERE student_id IS NOT NULL)
")->fetchColumn();

// (0d) LINE PROVIDER MIGRATION STATS — for Identity dashboard
$lineMigration = [
    'column_ready' => false,
    'total_linked' => 0,
    'migrated' => 0,
    'pending' => 0,
    'coverage_pct' => 0,
];
try {
    $hasNewUidCol = (bool) $pdo->query("SHOW COLUMNS FROM sys_users LIKE 'line_user_id_new'")->fetch();
    $lineMigration['column_ready'] = $hasNewUidCol;

    if ($hasNewUidCol) {
        $row = $pdo->query("
            SELECT
                SUM(CASE WHEN line_user_id IS NOT NULL AND line_user_id <> '' THEN 1 ELSE 0 END) AS total_linked,
                SUM(CASE WHEN line_user_id_new IS NOT NULL AND line_user_id_new <> '' THEN 1 ELSE 0 END) AS migrated
            FROM sys_users
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        $lineMigration['total_linked'] = (int) ($row['total_linked'] ?? 0);
        $lineMigration['migrated'] = (int) ($row['migrated'] ?? 0);
        $lineMigration['pending'] = max(0, $lineMigration['total_linked'] - $lineMigration['migrated']);
        $lineMigration['coverage_pct'] = $lineMigration['total_linked'] > 0
            ? (int) round(($lineMigration['migrated'] / $lineMigration['total_linked']) * 100)
            : 0;
    }
} catch (PDOException $e) {
    // silent fallback (dashboard should remain usable)
}

// (0c) IDENTITY SECTION — ADMINS & STAFF QUERY (Superadmin Only)
$allAdmins = [];
$allStaff  = [];

if ($adminRole === 'superadmin') {
    $allAdmins = $pdo->query("SELECT * FROM sys_admins ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Auto-migrate sys_staff columns if missing (only check once per load)
    try {
        $cols = $pdo->query("DESCRIBE sys_staff")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('access_insurance', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_insurance TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_system_logs', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_system_logs TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_site_settings', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_site_settings TINYINT(1) DEFAULT 0");
        }
        
        $allStaff = $pdo->query("
            SELECT id, username, full_name, email, role, account_status, linked_line_user_id,
                   IFNULL(access_eborrow, 1) AS access_eborrow,
                   IFNULL(access_ecampaign, 0) AS access_ecampaign,
                   IFNULL(ecampaign_role, 'admin') AS ecampaign_role,
                   IFNULL(access_insurance, 0) AS access_insurance,
                   IFNULL(access_system_logs, 0) AS access_system_logs,
                   IFNULL(access_site_settings, 0) AS access_site_settings
            FROM sys_staff ORDER BY role ASC, full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback for safety: if something still fails, try query without new columns
        try {
            $allStaff = $pdo->query("SELECT id, username, full_name, role, account_status FROM sys_staff ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) { /* silent */ }
    }
}
