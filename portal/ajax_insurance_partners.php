<?php
/**
 * portal/ajax_insurance_partners.php
 * จัดการบริษัทประกันและบัญชี partner — เฉพาะ superadmin
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$pdo    = db();
$action = $_REQUEST['action'] ?? '';

function jout(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    switch ($action) {
        // ─────── Companies ───────
        case 'list_companies': {
            $rows = $pdo->query("
                SELECT c.*,
                       (SELECT COUNT(*) FROM insurance_partner_users u WHERE u.company_code = c.company_code) AS user_count,
                       (SELECT COUNT(*) FROM insurance_members m WHERE m.insurance_company = c.company_code) AS member_count
                FROM insurance_companies c
                ORDER BY c.company_name
            ")->fetchAll(PDO::FETCH_ASSOC);
            jout(['ok' => true, 'data' => $rows]);
        }

        case 'add_company': {
            validate_csrf_or_die();
            $code  = strtoupper(trim($_POST['company_code'] ?? ''));
            $name  = trim($_POST['company_name'] ?? '');
            $email = trim($_POST['contact_email'] ?? '');
            $phone = trim($_POST['contact_phone'] ?? '');
            $contact = trim($_POST['contact_name'] ?? '');

            if (!preg_match('/^[A-Z0-9_]{2,20}$/', $code)) {
                jout(['ok' => false, 'error' => 'company_code ต้องเป็น A-Z, 0-9, _ ความยาว 2-20 ตัว']);
            }
            if ($name === '') jout(['ok' => false, 'error' => 'กรุณากรอกชื่อบริษัท']);

            $stmt = $pdo->prepare("INSERT INTO insurance_companies
                (company_code, company_name, contact_name, contact_email, contact_phone)
                VALUES (:c, :n, :cn, :e, :p)");
            $stmt->execute([':c' => $code, ':n' => $name, ':cn' => $contact, ':e' => $email, ':p' => $phone]);
            log_activity('insurance_partner_company_add', "code={$code}, name={$name}");
            jout(['ok' => true]);
        }

        case 'update_company': {
            validate_csrf_or_die();
            $code   = strtoupper(trim($_POST['company_code'] ?? ''));
            $name   = trim($_POST['company_name'] ?? '');
            $email  = trim($_POST['contact_email'] ?? '');
            $phone  = trim($_POST['contact_phone'] ?? '');
            $contact = trim($_POST['contact_name'] ?? '');
            $status  = in_array($_POST['status'] ?? '', ['Active', 'Inactive'], true) ? $_POST['status'] : 'Active';

            $pdo->prepare("UPDATE insurance_companies
                SET company_name = :n, contact_name = :cn, contact_email = :e,
                    contact_phone = :p, status = :s
                WHERE company_code = :c")
                ->execute([':n' => $name, ':cn' => $contact, ':e' => $email, ':p' => $phone, ':s' => $status, ':c' => $code]);
            log_activity('insurance_partner_company_update', "code={$code}, status={$status}");
            jout(['ok' => true]);
        }

        // ─────── Users ───────
        case 'list_users': {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $search = trim((string)($_GET['q'] ?? ''));
            $companyFilter = trim((string)($_GET['company'] ?? ''));

            $where = ['1=1'];
            $params = [];
            if ($search !== '') {
                $where[] = '(u.username LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q)';
                $params[':q'] = '%' . $search . '%';
            }
            if ($companyFilter !== '') {
                $where[] = 'u.company_code = :cc';
                $params[':cc'] = $companyFilter;
            }
            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM insurance_partner_users u $whereSql");
            $totalStmt->execute($params);
            $total = (int)$totalStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare("
                SELECT u.id, u.username, u.full_name, u.email, u.company_code,
                       u.account_status, u.failed_logins, u.locked_until,
                       u.last_login_at, u.last_login_ip, u.created_at,
                       c.company_name
                FROM insurance_partner_users u
                JOIN insurance_companies c ON c.company_code = u.company_code
                $whereSql
                ORDER BY u.id DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            jout([
                'ok' => true,
                'data' => $rows,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                ],
            ]);
        }

        case 'add_user': {
            validate_csrf_or_die();
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $company  = trim($_POST['company_code'] ?? '');
            $password = (string)($_POST['password'] ?? '');

            if (!preg_match('/^[a-zA-Z0-9_.\-]{3,50}$/', $username)) {
                jout(['ok' => false, 'error' => 'username ต้องเป็น a-z, 0-9, _ . - ความยาว 3-50 ตัว']);
            }
            if ($fullName === '') jout(['ok' => false, 'error' => 'กรุณากรอกชื่อ-สกุล']);
            if (strlen($password) < 8) jout(['ok' => false, 'error' => 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร']);
            if ($company === '')  jout(['ok' => false, 'error' => 'กรุณาเลือกบริษัท']);

            // verify company exists
            $exists = $pdo->prepare("SELECT 1 FROM insurance_companies WHERE company_code = :c");
            $exists->execute([':c' => $company]);
            if (!$exists->fetchColumn()) jout(['ok' => false, 'error' => 'ไม่พบบริษัทนี้']);

            $dup = $pdo->prepare("SELECT 1 FROM insurance_partner_users WHERE username = :u");
            $dup->execute([':u' => $username]);
            if ($dup->fetchColumn()) jout(['ok' => false, 'error' => 'username นี้มีในระบบแล้ว']);

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO insurance_partner_users
                (username, password_hash, full_name, email, company_code, created_by)
                VALUES (:u, :h, :n, :e, :c, :cb)")
                ->execute([
                    ':u' => $username, ':h' => $hash, ':n' => $fullName,
                    ':e' => $email, ':c' => $company,
                    ':cb' => (int)($_SESSION['admin_id'] ?? 0),
                ]);
            log_activity('insurance_partner_user_add', "username={$username}, company={$company}");
            jout(['ok' => true]);
        }

        case 'update_user': {
            validate_csrf_or_die();
            $id       = (int)($_POST['id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $status   = in_array($_POST['account_status'] ?? '', ['Active', 'Suspended'], true) ? $_POST['account_status'] : 'Active';
            $password = (string)($_POST['password'] ?? '');

            if ($id <= 0) jout(['ok' => false, 'error' => 'invalid id']);

            $pdo->prepare("UPDATE insurance_partner_users
                SET full_name = :n, email = :e, account_status = :s
                WHERE id = :id")
                ->execute([':n' => $fullName, ':e' => $email, ':s' => $status, ':id' => $id]);

            if ($password !== '') {
                if (strlen($password) < 8) jout(['ok' => false, 'error' => 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร']);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE insurance_partner_users SET password_hash = :h WHERE id = :id")
                    ->execute([':h' => $hash, ':id' => $id]);
                log_activity('insurance_partner_user_password_reset', "user_id={$id}");
            }
            log_activity('insurance_partner_user_update', "user_id={$id}, status={$status}");
            jout(['ok' => true]);
        }

        case 'unlock_user': {
            validate_csrf_or_die();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) jout(['ok' => false, 'error' => 'invalid id']);
            $pdo->prepare("UPDATE insurance_partner_users SET failed_logins = 0, locked_until = NULL WHERE id = :id")
                ->execute([':id' => $id]);
            log_activity('insurance_partner_user_unlock', "user_id={$id}");
            jout(['ok' => true]);
        }

        case 'delete_user': {
            validate_csrf_or_die();
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) jout(['ok' => false, 'error' => 'invalid id']);
            $pdo->prepare("DELETE FROM insurance_partner_users WHERE id = :id")
                ->execute([':id' => $id]);
            log_activity('insurance_partner_user_delete', "user_id={$id}");
            jout(['ok' => true]);
        }

        // ─────── Activity Log ───────
        case 'list_activity': {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = 20;
            $userId = (int)($_GET['user_id'] ?? 0);
            $company = trim((string)($_GET['company'] ?? ''));

            $where = ['1=1'];
            $params = [];
            if ($userId > 0)   { $where[] = 'partner_user_id = :uid'; $params[':uid'] = $userId; }
            if ($company !== '') { $where[] = 'company_code = :cc'; $params[':cc'] = $company; }
            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM insurance_partner_activity_log $whereSql");
            $totalStmt->execute($params);
            $total = (int)$totalStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $perPage;

            $stmt = $pdo->prepare("
                SELECT id, partner_user_id, company_code, username, action, details, ip_address, created_at
                FROM insurance_partner_activity_log
                $whereSql
                ORDER BY id DESC
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            jout([
                'ok' => true,
                'data' => $rows,
                'pagination' => [
                    'page' => $page, 'per_page' => $perPage,
                    'total' => $total, 'total_pages' => $totalPages,
                ],
            ]);
        }

        default:
            http_response_code(400);
            jout(['ok' => false, 'error' => 'unknown action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('ajax_insurance_partners: ' . $e->getMessage());
    jout(['ok' => false, 'error' => 'server error']);
}
