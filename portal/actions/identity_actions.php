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
                        ':n' => $fullName,
                        ':s' => $studentId,
                        ':c' => $citizenId,
                        ':p' => $phone,
                        ':email' => $email,
                        ':dept' => $department ?: null,
                        ':gender' => $gender ?: null,
                        ':st' => $status,
                        ':sother' => $statusOther ?: null,
                        ':id' => $userId
                    ]);
                header('Location: index.php?section=identity&tab=users&saved=1');
                exit;
            } catch (PDOException $e) {
                error_log("portal edit_user error: " . $e->getMessage());
                $idError = 'บันทึกไม่สำเร็จ กรุณาลองใหม่';
            }
        } else {
            $idError = 'ข้อมูลไม่ครบถ้วน';
        }
    }

    // (0b) IDENTITY SECTION — SYSTEM ADMIN ACTIONS
    if (($action === 'add_admin' || $action === 'edit_admin') && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $allowedAdminRoles = ['admin', 'editor', 'superadmin'];
        $role    = in_array($_POST['role'] ?? '', $allowedAdminRoles, true) ? $_POST['role'] : 'admin';
        $adminId = $_POST['admin_id'] ?? null;

        if ($fullName && $username && $email) {
            try {
                if ($action === 'add_admin') {
                    if (empty($password)) {
                        $idError = "กรุณาตั้งรหัสผ่านสำหรับ Admin ใหม่";
                    } elseif (strlen($password) < 8) {
                        $idError = "รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร";
                    } else {
                        $check = $pdo->prepare("SELECT id FROM sys_admins WHERE username = ? OR email = ?");
                        $check->execute([$username, $email]);
                        if ($check->fetch()) {
                            $idError = "ชื่อผู้ใช้ หรือ อีเมล นี้มีในระบบแล้ว";
                        } else {
                            $hashed = password_hash($password, PASSWORD_DEFAULT);
                            $stmt = $pdo->prepare("INSERT INTO sys_admins (full_name, username, email, password, role) VALUES (?, ?, ?, ?, ?)");
                            $stmt->execute([$fullName, $username, $email, $hashed, $role]);
                            log_activity("Added Admin", "เพิ่มเจ้าหน้าที่ใหม่: $fullName ($username) [สิทธิ์: $role]");
                            header('Location: index.php?section=identity&tab=admins&saved=1');
                            exit;
                        }
                    }
                } else {
                    $sql = "UPDATE sys_admins SET full_name = ?, username = ?, email = ?, role = ? WHERE id = ?";
                    $pdo->prepare($sql)->execute([$fullName, $username, $email, $role, $adminId]);
                    if (!empty($password)) {
                        $pdo->prepare("UPDATE sys_admins SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $adminId]);
                    }
                    log_activity("Updated Admin", "แก้ไขข้อมูลเจ้าหน้าที่: $fullName ($username)");
                    header('Location: index.php?section=identity&tab=admins&saved=1');
                    exit;
                }
            } catch (PDOException $e) { $idError = "เกิดข้อผิดพลาด: " . $e->getMessage(); }
        } else { $idError = "กรุณากรอกข้อมูลให้ครบถ้วน"; }
    }

    if ($action === 'delete_admin' && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
        $adminId = $_POST['admin_id'] ?? null;
        if ($adminId == $_SESSION['admin_id']) {
            $idError = "ไม่สามารถลบบัญชีที่กำลังใช้งานอยู่ได้";
        } else {
            $pdo->prepare("DELETE FROM sys_admins WHERE id = ?")->execute([$adminId]);
            log_activity("Deleted Admin", "ลบเจ้าหน้าที่ ID: $adminId เรียบร้อยแล้ว");
            header('Location: index.php?section=identity&tab=admins&saved=1');
            exit;
        }
    }

    // (0c) IDENTITY SECTION — STAFF ACTIONS (e-Borrow)
    if ($action === 'add_staff' && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
        $fullName = trim($_POST['sf_full_name'] ?? '');
        $username = trim($_POST['sf_username'] ?? '');
        $password = $_POST['sf_password'] ?? '';
        $role     = $_POST['sf_role'] ?? 'employee';
        $status   = $_POST['sf_status'] ?? 'active';
        $accessEcampaign = (int)($_POST['sf_access_ecampaign'] ?? 0);
        $ecRole   = $_POST['sf_ecampaign_role'] ?? 'admin';

        if ($fullName && $username && $password) {
            try {
                $check = $pdo->prepare("SELECT id FROM sys_staff WHERE username = ?");
                $check->execute([$username]);
                if ($check->fetch()) {
                    $idError = "Username '$username' มีในระบบแล้ว";
                } else {
                    $pdo->prepare("INSERT INTO sys_staff (username, password_hash, full_name, role, account_status, access_ecampaign, ecampaign_role) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $status, $accessEcampaign, $ecRole]);
                    log_activity("Added Staff", "เพิ่มเจ้าหน้าที่ใหม่: $fullName ($username)");
                    header('Location: index.php?section=identity&tab=staff&saved=1');
                    exit;
                }
            } catch (PDOException $e) { $idError = $e->getMessage(); }
        } else { $idError = "กรุณากรอกข้อมูลให้ครบถ้วน"; }
    }

    if ($action === 'edit_staff' && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
        $staffId = (int)($_POST['sf_id'] ?? 0);
        $fullName = trim($_POST['sf_full_name'] ?? '');
        $username = trim($_POST['sf_username'] ?? '');
        $password = $_POST['sf_password'] ?? '';
        $role     = $_POST['sf_role'] ?? 'employee';
        $status   = $_POST['sf_status'] ?? 'active';
        $accessEcampaign = (int)($_POST['sf_access_ecampaign'] ?? 0);
        $ecRole   = $_POST['sf_ecampaign_role'] ?? 'admin';

        if ($staffId > 0 && $fullName && $username) {
            try {
                $pdo->prepare("UPDATE sys_staff SET full_name=?, username=?, role=?, account_status=?, access_ecampaign=?, ecampaign_role=? WHERE id=?")
                    ->execute([$fullName, $username, $role, $status, $accessEcampaign, $ecRole, $staffId]);
                if (!empty($password)) {
                    $pdo->prepare("UPDATE sys_staff SET password_hash=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $staffId]);
                }
                log_activity("Updated Staff", "แก้ไขเจ้าหน้าที่: $fullName");
                header('Location: index.php?section=identity&tab=staff&saved=1');
                exit;
            } catch (PDOException $e) { $idError = $e->getMessage(); }
        }
    }

    if ($action === 'delete_staff' && $adminRole === 'superadmin') {
        if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
        $staffId = (int)($_POST['sf_id'] ?? 0);
        if ($staffId > 0) {
            $pdo->prepare("DELETE FROM sys_staff WHERE id = ?")->execute([$staffId]);
            log_activity("Deleted Staff", "ลบเจ้าหน้าที่ ID: $staffId");
            header('Location: index.php?section=identity&tab=staff&saved=1');
            exit;
        }
    }
}
