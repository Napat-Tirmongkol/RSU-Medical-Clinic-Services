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
$allPositions = [];
$allDepartments = [];

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
        if (!in_array('access_registry', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_registry TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_edms', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_edms TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_ai', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_ai TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_consumables', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_consumables TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_asset', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_asset TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_finance', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_finance TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_scholarship', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_scholarship TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_dashboard_admin', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_dashboard_admin TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_monthly_report', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_monthly_report TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_nurse_productivity', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_nurse_productivity TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_daily_summary', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_daily_summary TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_director_view', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_director_view TINYINT(1) DEFAULT 0");
        }
        if (!in_array('access_identity', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_identity TINYINT(1) DEFAULT 0");
        }
        if (!in_array('department_id', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN department_id INT UNSIGNED NULL AFTER role");
        }
        if (!in_array('position_id', $cols)) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN position_id INT UNSIGNED NULL AFTER role");
            try { $pdo->exec("ALTER TABLE sys_staff ADD INDEX idx_position (position_id)"); } catch (PDOException $e) {}
        }
        if (!in_array('job_title', $cols)) {
            // Job title แบบ free-text (เช่น "พยาบาล", "ธุรการ", "แพทย์") — ไม่เกี่ยวกับ permission
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN job_title VARCHAR(120) NOT NULL DEFAULT '' AFTER position_id");
        }

        // Auto-create sys_staff_positions if missing
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sys_staff_positions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                description VARCHAR(500) NULL,
                flags JSON NOT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $allStaff = $pdo->query("
            SELECT s.id, s.username, s.full_name, s.email, s.role, s.account_status, s.linked_line_user_id,
                   s.position_id,
                   p.name AS position_name,
                   p.flags AS position_flags,
                   IFNULL(s.job_title, '') AS job_title,
                   (SELECT op.title FROM sys_org_members om
                      INNER JOIN sys_org_positions op ON op.id = om.position_id
                      WHERE om.staff_id = s.id AND om.is_active = 1
                      ORDER BY om.display_order ASC, om.id ASC LIMIT 1) AS org_position_title,
                   IFNULL(s.access_eborrow, 1) AS access_eborrow,
                   IFNULL(s.access_ecampaign, 0) AS access_ecampaign,
                   IFNULL(s.ecampaign_role, 'admin') AS ecampaign_role,
                   IFNULL(s.access_insurance, 0) AS access_insurance,
                   IFNULL(s.access_system_logs, 0) AS access_system_logs,
                   IFNULL(s.access_site_settings, 0) AS access_site_settings,
                   IFNULL(s.access_registry, 0) AS access_registry,
                   IFNULL(s.access_edms, 0) AS access_edms,
                   IFNULL(s.access_ai, 0) AS access_ai,
                   IFNULL(s.access_consumables, 0) AS access_consumables,
                   IFNULL(s.access_asset, 0) AS access_asset,
                   IFNULL(s.access_finance, 0) AS access_finance,
                   IFNULL(s.access_scholarship, 0) AS access_scholarship,
                   IFNULL(s.access_dashboard_admin, 0) AS access_dashboard_admin,
                   IFNULL(s.access_monthly_report, 0) AS access_monthly_report,
                   IFNULL(s.access_nurse_productivity, 0) AS access_nurse_productivity,
                   IFNULL(s.access_daily_summary, 0) AS access_daily_summary,
                   IFNULL(s.access_director_view, 0) AS access_director_view,
                   IFNULL(s.access_identity, 0) AS access_identity,
                   s.department_id
            FROM sys_staff s
            LEFT JOIN sys_staff_positions p ON p.id = s.position_id
            ORDER BY s.role ASC, s.full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // นับจำนวน staff ที่ผูกอยู่แต่ละ position (สำหรับ UI)
        $allPositions = $pdo->query("
            SELECT p.id, p.name, p.description, p.flags, p.created_at, p.updated_at,
                   (SELECT COUNT(*) FROM sys_staff WHERE position_id = p.id) AS staff_count
            FROM sys_staff_positions p
            ORDER BY p.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // ฝ่าย/หน่วยงาน + count staff/reports ที่ผูก
        try {
            $allDepartments = $pdo->query("
                SELECT d.id, d.name, d.description, d.sort_order, d.active, d.created_at,
                       (SELECT COUNT(*) FROM sys_staff WHERE department_id = d.id) AS staff_count,
                       (SELECT COUNT(*) FROM sys_monthly_reports WHERE department_id = d.id) AS report_count
                FROM sys_departments d
                ORDER BY d.sort_order, d.name
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $allDepartments = []; }
    } catch (PDOException $e) {
        // Fallback for safety: if something still fails, try query without new columns
        try {
            $allStaff = $pdo->query("SELECT id, username, full_name, role, account_status FROM sys_staff ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) { /* silent */ }
    }
}
