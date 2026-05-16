<?php
/**
 * portal/actions/identity_actions.php
 * POST Handlers for Identity & Governance (Users, Admins, Staff)
 */

$idSaved = isset($_GET['saved']) && $_GET['saved'] === '1';
$idError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // (0a) IDENTITY SECTION — USER ACTIONS
    if ($action === 'portal_edit_user') {
        // ... [Existing User Edit Logic] ...
        $userId = (int) ($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $studentId = trim($_POST['student_personnel_id'] ?? '');
        $citizenId = trim($_POST['citizen_id'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $statusOther = trim($_POST['status_other'] ?? '');
        if ($userId > 0 && $fullName !== '') {
            try {
                $pdo->prepare("UPDATE sys_users SET full_name=:n, student_personnel_id=:s, citizen_id=:c, phone_number=:p, email=:email, department=:dept, gender=:gender, status=:st, status_other=:sother WHERE id=:id")
                    ->execute([
                        ':n' => $fullName, ':s' => $studentId, ':c' => $citizenId, ':p' => $phone,
                        ':email' => $email, ':dept' => $department ?: null, ':gender' => $gender ?: null,
                        ':st' => $status, ':sother' => $statusOther ?: null, ':id' => $userId
                    ]);
                header('Location: index.php?section=identity&tab=users&saved=1'); exit;
            } catch (PDOException $e) { $idError = 'บันทึกไม่สำเร็จ'; }
        }
    }

    // (0b) NEW: UNIFIED IDENTITY GOVERNANCE HANDLER (ISO 27001)
    if (($action === 'add_identity_gov' || $action === 'save_identity_gov') && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
        
        $type      = $_POST['target_type'] ?? ''; // 'admin' or 'staff'
        $targetId  = (int)($_POST['target_id'] ?? 0);
        $fullName  = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $status    = $_POST['status'] ?? 'active';
        $reason    = trim($_POST['justification'] ?? '');

        if ($fullName && $username && $reason) {
            try {
                // Ensure Audit Table Exists
                $pdo->exec("CREATE TABLE IF NOT EXISTS sys_access_audit_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    target_id INT NOT NULL,
                    target_type VARCHAR(20) NOT NULL,
                    changed_by INT NOT NULL,
                    justification TEXT NOT NULL,
                    change_snapshot JSON NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                if ($type === 'admin') {
                    // Ensure status column exists in sys_admins for consistency
                    try { $pdo->exec("ALTER TABLE sys_admins ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER role"); } catch(PDOException $e) {}

                    $role = $_POST['admin_role'] ?? 'admin';
                    // Whitelist admin role (ป้องกัน privilege escalation จาก POST)
                    $allowedAdminRoles = ['admin', 'editor', 'superadmin'];
                    if (!in_array($role, $allowedAdminRoles, true)) $role = 'admin';
                    if ($action === 'add_identity_gov') {
                        $hashed = password_hash($password ?: bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO sys_admins (full_name, username, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$fullName, $username, $email, $hashed, $role, $status]);
                        $targetId = (int)$pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE sys_admins SET full_name=?, username=?, email=?, role=?, status=? WHERE id=?")->execute([$fullName, $username, $email, $role, $status, $targetId]);
                        if (!empty($password)) $pdo->prepare("UPDATE sys_admins SET password=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
                    }
                } elseif ($type === 'staff') {
                    // Ensure columns exist
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_eborrow TINYINT(1) DEFAULT 1 AFTER role"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN email VARCHAR(255) NULL AFTER full_name"); } catch(PDOException $e) {}

                    $ebAccess = (int)($_POST['eb_access'] ?? 0);
                    $ebRole   = $_POST['eb_role'] ?? 'employee';
                    $ecAccess = (int)($_POST['ec_access'] ?? 0);
                    $ecRole   = $_POST['ec_role'] ?? 'editor';
                    $insAccess = (int)($_POST['ins_access'] ?? 0);
                    $logsAccess = (int)($_POST['logs_access'] ?? 0);
                    $settAccess = (int)($_POST['sett_access'] ?? 0);
                    $regAccess = (int)($_POST['reg_access'] ?? 0);
                    $edmsAccess = (int)($_POST['edms_access'] ?? 0);
                    $aiAccess = (int)($_POST['ai_access'] ?? 0);
                    $consumablesAccess = (int)($_POST['consumables_access'] ?? 0);
                    $assetAccess = (int)($_POST['asset_access'] ?? 0);
                    $financeAccess = (int)($_POST['finance_access'] ?? 0);
                    $scholarshipAccess = (int)($_POST['scholarship_access'] ?? 0);
                    $dashboardAccess   = (int)($_POST['dashboard_admin_access'] ?? 0);
                    $monthlyReportAccess = (int)($_POST['monthly_report_access'] ?? 0);
                    $nurseProductivityAccess = (int)($_POST['nurse_productivity_access'] ?? 0);
                    $directorViewAccess  = (int)($_POST['director_view_access'] ?? 0);
                    $identityAccess      = (int)($_POST['identity_access'] ?? 0);
                    $departmentId      = isset($_POST['department_id']) && $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;

                    // Whitelist e-Borrow role (ป้องกัน privilege escalation จาก POST)
                    $allowedEbRoles = ['admin', 'librarian', 'employee'];
                    if (!in_array($ebRole, $allowedEbRoles, true)) $ebRole = 'employee';

                    // Whitelist e-Campaign role
                    $allowedEcRoles = ['superadmin', 'admin', 'editor'];
                    if (!in_array($ecRole, $allowedEcRoles, true)) $ecRole = 'editor';

                    // Ensure new flag columns exist (for existing installs)
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_registry TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_edms TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_ai TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_consumables TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_asset TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_finance TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_scholarship TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_dashboard_admin TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_monthly_report TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_nurse_productivity TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_director_view TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_identity TINYINT(1) DEFAULT 0"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN department_id INT UNSIGNED NULL AFTER role"); } catch(PDOException $e) {}

                    // Position (NULL = Custom). ถ้าผูก position จะ override flag ตอน login (live link)
                    $positionId = null;
                    if (isset($_POST['position_id']) && $_POST['position_id'] !== '' && $_POST['position_id'] !== '0') {
                        $positionId = (int)$_POST['position_id'];
                        // Validate ว่า position มีอยู่จริง
                        $exists = $pdo->prepare("SELECT 1 FROM sys_staff_positions WHERE id = ? LIMIT 1");
                        $exists->execute([$positionId]);
                        if (!$exists->fetchColumn()) $positionId = null;
                    }

                    // Ensure position_id + job_title columns exist
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN position_id INT UNSIGNED NULL AFTER role"); } catch(PDOException $e) {}
                    try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN job_title VARCHAR(120) NOT NULL DEFAULT '' AFTER position_id"); } catch(PDOException $e) {}

                    // Job title (free text — เช่น พยาบาล / ธุรการ / แพทย์)
                    $jobTitle = mb_substr(trim((string)($_POST['job_title'] ?? '')), 0, 120);

                    if ($action === 'add_identity_gov') {
                        $hashed = password_hash($password ?: bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                        $pdo->prepare("INSERT INTO sys_staff (full_name, username, email, password_hash, role, position_id, job_title, department_id, access_eborrow, account_status, access_ecampaign, ecampaign_role, access_insurance, access_system_logs, access_site_settings, access_registry, access_edms, access_ai, access_consumables, access_asset, access_finance, access_scholarship, access_dashboard_admin, access_monthly_report, access_nurse_productivity, access_director_view, access_identity) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                            ->execute([$fullName, $username, $email, $hashed, $ebRole, $positionId, $jobTitle, $departmentId, $ebAccess, $status, $ecAccess, $ecRole, $insAccess, $logsAccess, $settAccess, $regAccess, $edmsAccess, $aiAccess, $consumablesAccess, $assetAccess, $financeAccess, $scholarshipAccess, $dashboardAccess, $monthlyReportAccess, $nurseProductivityAccess, $directorViewAccess, $identityAccess]);
                        $targetId = (int)$pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE sys_staff SET full_name=?, username=?, email=?, role=?, position_id=?, job_title=?, department_id=?, access_eborrow=?, account_status=?, access_ecampaign=?, ecampaign_role=?, access_insurance=?, access_system_logs=?, access_site_settings=?, access_registry=?, access_edms=?, access_ai=?, access_consumables=?, access_asset=?, access_finance=?, access_scholarship=?, access_dashboard_admin=?, access_monthly_report=?, access_nurse_productivity=?, access_director_view=?, access_identity=? WHERE id=?")
                            ->execute([$fullName, $username, $email, $ebRole, $positionId, $jobTitle, $departmentId, $ebAccess, $status, $ecAccess, $ecRole, $insAccess, $logsAccess, $settAccess, $regAccess, $edmsAccess, $aiAccess, $consumablesAccess, $assetAccess, $financeAccess, $scholarshipAccess, $dashboardAccess, $monthlyReportAccess, $nurseProductivityAccess, $directorViewAccess, $identityAccess, $targetId]);
                        if (!empty($password)) $pdo->prepare("UPDATE sys_staff SET password_hash=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
                    }
                }

                // Record Audit Log — strip secrets before persisting (passwords must never be stored in plaintext)
                $auditPayload = $_POST;
                foreach (['password', 'new_password', 'confirm_password', 'csrf_token'] as $secretKey) {
                    if (array_key_exists($secretKey, $auditPayload)) {
                        $auditPayload[$secretKey] = !empty($auditPayload[$secretKey]) ? '[REDACTED]' : '';
                    }
                }
                $snapshot = json_encode($auditPayload, JSON_UNESCAPED_UNICODE);
                $pdo->prepare("INSERT INTO sys_access_audit_logs (target_id, target_type, changed_by, justification, change_snapshot) VALUES (?,?,?,?,?)")
                    ->execute([$targetId, $type, $_SESSION['admin_id'], $reason, $snapshot]);

                log_activity("Identity Governance", "Updated $type: $fullName (Reason: $reason)");
                header("Location: index.php?section=identity&tab=" . ($type === 'admin' ? 'admins' : 'staff') . "&saved=1");
                exit;
            } catch (PDOException $e) {
                error_log('[identity_actions] save_identity_gov failed: ' . $e->getMessage());
                $idError = "บันทึกไม่สำเร็จ กรุณาตรวจสอบข้อมูลและลองใหม่";
            }
        } else { $idError = "กรุณากรอกข้อมูลให้ครบถ้วนและระบุเหตุผลความจำเป็น"; }
    }

    // (0c) STAFF POSITION CRUD (superadmin only) — ตำแหน่งงาน + flag preset
    if (in_array($action, ['add_position', 'edit_position', 'delete_position'], true) && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();

        // Whitelist flag keys ที่ระบบรู้จัก (ป้องกันยัด key แปลกปลอม)
        $allowedFlagKeys = [
            'access_ecampaign','access_eborrow','access_insurance','access_system_logs',
            'access_site_settings','access_registry','access_edms',
            'access_ai','access_consumables','access_asset','access_finance','access_scholarship',
            'access_dashboard_admin','access_monthly_report','access_nurse_productivity','access_director_view',
            'access_identity',
        ];

        // Ensure table exists
        try {
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
        } catch (PDOException $e) { /* silent */ }

        if ($action === 'delete_position') {
            $posId = (int)($_POST['position_id'] ?? 0);
            if ($posId > 0) {
                try {
                    // Unlink staff ที่ผูกกับ position นี้ (ทำให้กลายเป็น Custom)
                    $pdo->prepare("UPDATE sys_staff SET position_id = NULL WHERE position_id = ?")->execute([$posId]);
                    $pdo->prepare("DELETE FROM sys_staff_positions WHERE id = ?")->execute([$posId]);
                    log_activity("Deleted Position", "ลบตำแหน่งงาน ID: $posId");
                    header('Location: index.php?section=identity&tab=positions&saved=1'); exit;
                } catch (PDOException $e) {
                    error_log('[identity_actions] delete_position failed: ' . $e->getMessage());
                    $idError = "ลบตำแหน่งไม่สำเร็จ — มี staff ผูกอยู่หรือไม่?";
                }
            }
        } else {
            // add_position / edit_position
            $posId   = (int)($_POST['position_id'] ?? 0);
            $name    = trim($_POST['position_name'] ?? '');
            $desc    = trim($_POST['position_description'] ?? '');

            // กรอง flag จาก POST ตาม whitelist
            $flagsObj = [];
            foreach ($allowedFlagKeys as $k) {
                $flagsObj[$k] = isset($_POST['flag_' . $k]) ? 1 : 0;
            }
            $flagsJson = json_encode($flagsObj, JSON_UNESCAPED_UNICODE);

            if ($name === '') {
                $idError = "กรุณาระบุชื่อตำแหน่ง";
            } else {
                try {
                    if ($action === 'add_position') {
                        $pdo->prepare("INSERT INTO sys_staff_positions (name, description, flags, created_by) VALUES (?,?,?,?)")
                            ->execute([$name, $desc ?: null, $flagsJson, $_SESSION['admin_id'] ?? null]);
                        log_activity("Added Position", "เพิ่มตำแหน่งงาน: $name");
                    } else {
                        $pdo->prepare("UPDATE sys_staff_positions SET name=?, description=?, flags=? WHERE id=?")
                            ->execute([$name, $desc ?: null, $flagsJson, $posId]);
                        log_activity("Updated Position", "แก้ไขตำแหน่งงาน ID: $posId ($name)");
                    }
                    header('Location: index.php?section=identity&tab=positions&saved=1'); exit;
                } catch (PDOException $e) {
                    error_log('[identity_actions] save_position failed: ' . $e->getMessage());
                    $idError = (str_contains($e->getMessage(), 'Duplicate'))
                        ? "ชื่อตำแหน่งนี้มีอยู่แล้ว"
                        : "บันทึกตำแหน่งไม่สำเร็จ";
                }
            }
        }
    }

    // Keep old delete handlers for now
    if ($action === 'delete_admin' && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
        $adminId = $_POST['admin_id'] ?? null;
        if ($adminId != $_SESSION['admin_id']) {
            $pdo->prepare("DELETE FROM sys_admins WHERE id = ?")->execute([$adminId]);
            log_activity("Deleted Admin", "ลบเจ้าหน้าที่ ID: $adminId");
            header('Location: index.php?section=identity&tab=admins&saved=1'); exit;
        }
    }
    if ($action === 'delete_staff' && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
        $staffId = (int)($_POST['sf_id'] ?? 0);
        if ($staffId > 0) {
            $pdo->prepare("DELETE FROM sys_staff WHERE id = ?")->execute([$staffId]);
            log_activity("Deleted Staff", "ลบเจ้าหน้าที่ ID: $staffId");
            header('Location: index.php?section=identity&tab=staff&saved=1'); exit;
        }
    }
}
