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
                    $role = $_POST['admin_role'] ?? 'admin';
                    if ($action === 'add_identity_gov') {
                        $hashed = password_hash($password ?: bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO sys_admins (full_name, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$fullName, $username, $email, $hashed, $role]);
                        $targetId = (int)$pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE sys_admins SET full_name=?, username=?, email=?, role=? WHERE id=?")->execute([$fullName, $username, $email, $role, $targetId]);
                        if (!empty($password)) $pdo->prepare("UPDATE sys_admins SET password=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
                    }
                } elseif ($type === 'staff') {
                    $ebRole = $_POST['eb_role'] ?? 'employee';
                    $ecAccess = (int)($_POST['ec_access'] ?? 0);
                    $ecRole = $_POST['ec_role'] ?? 'editor';
                    
                    if ($action === 'add_identity_gov') {
                        $hashed = password_hash($password ?: bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
                        $pdo->prepare("INSERT INTO sys_staff (full_name, username, password_hash, role, account_status, access_ecampaign, ecampaign_role) VALUES (?,?,?,?,?,?,?)")
                            ->execute([$fullName, $username, $hashed, $ebRole, $status, $ecAccess, $ecRole]);
                        $targetId = (int)$pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE sys_staff SET full_name=?, username=?, role=?, account_status=?, access_ecampaign=?, ecampaign_role=? WHERE id=?")
                            ->execute([$fullName, $username, $ebRole, $status, $ecAccess, $ecRole, $targetId]);
                        if (!empty($password)) $pdo->prepare("UPDATE sys_staff SET password_hash=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
                    }
                }

                // Record Audit Log
                $snapshot = json_encode($_POST);
                $pdo->prepare("INSERT INTO sys_access_audit_logs (target_id, target_type, changed_by, justification, change_snapshot) VALUES (?,?,?,?,?)")
                    ->execute([$targetId, $type, $_SESSION['admin_id'], $reason, $snapshot]);

                log_activity("Identity Governance", "Updated $type: $fullName (Reason: $reason)");
                header("Location: index.php?section=identity&tab=" . ($type === 'admin' ? 'admins' : 'staff') . "&saved=1");
                exit;
            } catch (PDOException $e) { $idError = "Error: " . $e->getMessage(); }
        } else { $idError = "กรุณากรอกข้อมูลให้ครบถ้วนและระบุเหตุผลความจำเป็น"; }
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
}
