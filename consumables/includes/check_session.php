<?php
/**
 * consumables/includes/check_session.php
 * Session + role check สำหรับโมดูลวัสดุสิ้นเปลือง
 *
 * Roles:
 *   - admin / editor   → CRUD ทุกอย่าง รวมถึงจัดการหมวดหมู่
 *   - employee (staff) → ดู, ค้นหา, รับเข้า/เบิกออก
 */

if (!function_exists('_csm_abs_url')) {
    function _csm_abs_url(string $relativePath): string {
        $proto     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $docRoot   = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        $targetDir = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
        $relDir    = $docRoot ? str_ireplace($docRoot, '', $targetDir) : '';
        $base      = $proto . '://' . $host . '/' . ltrim($relDir, '/');
        return rtrim($base, '/') . '/' . ltrim($relativePath, '/');
    }
}

@session_start();

// ── SSO sync (เหมือน asset module) ───────────────────────────────────────
if (!isset($_SESSION['user_id'])
    && isset($_SESSION['admin_logged_in'], $_SESSION['admin_id'])
    && $_SESSION['admin_logged_in'] === true) {
    try {
        require_once __DIR__ . '/db_connect.php';
        $p     = db();
        $uname = $_SESSION['admin_username'] ?? '';

        $s = $p->prepare("SELECT id, full_name, role FROM sys_staff WHERE username = :u AND account_status = 'active' LIMIT 1");
        $s->execute([':u' => $uname]);
        $row = $s->fetch();

        if ($row) {
            $allowedRoles = ['admin', 'editor', 'employee', 'librarian'];
            $_SESSION['user_id']   = $row['id'];
            $_SESSION['full_name'] = $row['full_name'];
            $_SESSION['role']      = in_array($row['role'], $allowedRoles, true) ? $row['role'] : 'employee';
        } else {
            $sa = $p->prepare("SELECT id, full_name FROM sys_admins WHERE id = :id LIMIT 1");
            $sa->execute([':id' => $_SESSION['admin_id'] ?? null]);
            $admin = $sa->fetch();
            if ($admin) {
                $_SESSION['user_id']         = (int) $_SESSION['admin_id'];
                $_SESSION['full_name']       = $admin['full_name'];
                $_SESSION['role']            = 'admin';
                $_SESSION['is_portal_admin'] = true;
            } else {
                header('Location: ' . _csm_abs_url('../portal/login.php?error=no_staff_account'));
                exit;
            }
        }
    } catch (Exception $e) {
        header('Location: ' . _csm_abs_url('../portal/login.php?error=sso_failed'));
        exit;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('csm_user_role')) {
    function csm_user_role(): string {
        return $_SESSION['role'] ?? 'guest';
    }
}
if (!function_exists('csm_can_manage')) {
    function csm_can_manage(): bool {
        return in_array(csm_user_role(), ['admin', 'editor'], true);
    }
}
if (!function_exists('csm_require_manage')) {
    function csm_require_manage(): void {
        if (!csm_can_manage()) {
            http_response_code(403);
            exit('สิทธิ์ไม่เพียงพอ (admin/editor เท่านั้น)');
        }
    }
}

// ── Timeout ──────────────────────────────────────────────────────────────
$timeout_duration = 36000;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header('Location: ' . _csm_abs_url('../portal/login.php?timeout=1'));
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . _csm_abs_url('../portal/login.php'));
    exit;
}
