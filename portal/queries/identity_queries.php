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

// (0c) IDENTITY SECTION — ADMINS & STAFF QUERY (Superadmin Only)
$lineMigration = [
    'has_old_column' => false,
    'has_new_column' => false,
    'old_uid_count' => 0,
    'migrated_count' => 0,
    'pending_count' => 0,
    'covered_old_uid_count' => 0,
    'coverage' => 0.0,
];

try {
    $userColumns = $pdo->query("SHOW COLUMNS FROM sys_users")->fetchAll(PDO::FETCH_COLUMN);
    $lineMigration['has_old_column'] = in_array('line_user_id', $userColumns, true);
    $lineMigration['has_new_column'] = in_array('line_user_id_new', $userColumns, true);

    if ($lineMigration['has_old_column']) {
        if ($lineMigration['has_new_column']) {
            $lineMigrationRow = $pdo->query("
                SELECT
                    SUM(CASE WHEN line_user_id IS NOT NULL AND line_user_id <> '' THEN 1 ELSE 0 END) AS old_uid_count,
                    SUM(CASE WHEN line_user_id_new IS NOT NULL AND line_user_id_new <> '' THEN 1 ELSE 0 END) AS migrated_count,
                    SUM(CASE WHEN line_user_id IS NOT NULL AND line_user_id <> '' AND line_user_id_new IS NOT NULL AND line_user_id_new <> '' THEN 1 ELSE 0 END) AS covered_old_uid_count,
                    SUM(CASE WHEN line_user_id IS NOT NULL AND line_user_id <> '' AND (line_user_id_new IS NULL OR line_user_id_new = '') THEN 1 ELSE 0 END) AS pending_count
                FROM sys_users
            ")->fetch(PDO::FETCH_ASSOC);
        } else {
            $lineMigrationRow = $pdo->query("
                SELECT
                    SUM(CASE WHEN line_user_id IS NOT NULL AND line_user_id <> '' THEN 1 ELSE 0 END) AS old_uid_count,
                    0 AS migrated_count,
                    0 AS covered_old_uid_count,
                    SUM(CASE WHEN line_user_id IS NOT NULL AND line_user_id <> '' THEN 1 ELSE 0 END) AS pending_count
                FROM sys_users
            ")->fetch(PDO::FETCH_ASSOC);
        }

        $lineMigration['old_uid_count'] = (int)($lineMigrationRow['old_uid_count'] ?? 0);
        $lineMigration['migrated_count'] = (int)($lineMigrationRow['migrated_count'] ?? 0);
        $lineMigration['pending_count'] = (int)($lineMigrationRow['pending_count'] ?? 0);
        $lineMigration['covered_old_uid_count'] = (int)($lineMigrationRow['covered_old_uid_count'] ?? 0);
        $lineMigration['coverage'] = $lineMigration['old_uid_count'] > 0
            ? round(($lineMigration['covered_old_uid_count'] / $lineMigration['old_uid_count']) * 100, 1)
            : 0.0;
    }
} catch (PDOException $e) {
    // Leave zeroed fallback values; the identity dashboard should stay usable.
}

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
