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
// แยก context ตาม login source:
//   - Portal admin (sys_admins) → admin_id = sys_admins.id, is_portal_admin = true
//   - e-Campaign Staff (sys_staff) → admin_id = sys_staff.id, is_ecampaign_staff = true
//
// Trigger sync เมื่อ admin_logged_in=true และ:
//   1. ไม่มี user_id เลย (login ครั้งแรก) — หรือ
//   2. user_id อยู่แต่ is_portal_admin / is_ecampaign_staff ยังไม่ตั้ง
//      (session ปนเก่าจาก staff login → admin login ทับ → ต้อง re-sync
//      ไม่งั้น gate จะใช้ role ของ session เก่า)
$_needSso = isset($_SESSION['admin_logged_in'], $_SESSION['admin_id'])
            && $_SESSION['admin_logged_in'] === true
            && (
                !isset($_SESSION['user_id'])
                || (empty($_SESSION['is_portal_admin']) && empty($_SESSION['is_ecampaign_staff']))
                || (int)$_SESSION['user_id'] !== (int)$_SESSION['admin_id']
            );

if ($_needSso) {
    try {
        require_once __DIR__ . '/db_connect.php';
        $p = db();
        $isPortalAdmin = empty($_SESSION['is_ecampaign_staff']);

        if ($isPortalAdmin) {
            $sa = $p->prepare("SELECT id, full_name FROM sys_admins WHERE id = :id LIMIT 1");
            $sa->execute([':id' => (int)$_SESSION['admin_id']]);
            $admin = $sa->fetch();
            if ($admin) {
                $_SESSION['user_id']         = (int)$admin['id'];
                $_SESSION['full_name']       = $admin['full_name'];
                $_SESSION['role']            = 'admin';
                $_SESSION['is_portal_admin'] = true;
            } else {
                header('Location: ' . _csm_abs_url('../portal/login.php?error=no_admin_account'));
                exit;
            }
        } else {
            $s = $p->prepare("SELECT id, full_name, role FROM sys_staff WHERE id = :id AND account_status = 'active' LIMIT 1");
            $s->execute([':id' => (int)$_SESSION['admin_id']]);
            $row = $s->fetch();
            if ($row) {
                $allowedRoles = ['admin', 'editor', 'employee', 'librarian'];
                $_SESSION['user_id']   = (int)$row['id'];
                $_SESSION['full_name'] = $row['full_name'];
                $_SESSION['role']      = in_array($row['role'], $allowedRoles, true) ? $row['role'] : 'employee';
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
            require_once __DIR__ . '/../../includes/access_denied_page.php';
            render_access_denied([
                'flag'       => 'role: admin / editor',
                'module'     => 'Consumables — โหมดจัดการ (admin/editor)',
                'hub_url'    => _csm_abs_url('../portal/index.php'),
                'logout_url' => _csm_abs_url('../admin/auth/logout.php'),
                'tailwind'   => _csm_abs_url('../assets/css/tailwind.min.css'),
            ]);
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

// ── Module Access Gate (access_consumables) ──────────────────────────────
// portal admin (sys_admins) → ผ่านเสมอ
// sys_staff role 'admin'/'editor' → ผ่าน (ผู้ดูแลคลังเดิม backward compat)
// sys_staff role อื่น → ต้องมี access_consumables = 1
if (empty($_SESSION['is_portal_admin'])) {
    $stRole = $_SESSION['role'] ?? '';
    if (!in_array($stRole, ['admin', 'editor'], true)) {
        $hasFlag = $_SESSION['access_consumables'] ?? null;
        if ($hasFlag === null) {
            // fallback: re-fetch จาก DB (session เก่าก่อน migration)
            try {
                if (!isset($p)) { require_once __DIR__ . '/db_connect.php'; $p = db(); }
                $stmt = $p->prepare("SELECT IFNULL(access_consumables, 0) FROM sys_staff WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $_SESSION['user_id']]);
                $hasFlag = (int)$stmt->fetchColumn();
                $_SESSION['access_consumables'] = $hasFlag;
            } catch (Throwable $e) {
                $hasFlag = 0;
            }
        }
        if ((int)$hasFlag !== 1) {
            require_once __DIR__ . '/../../includes/access_denied_page.php';
            render_access_denied([
                'flag'       => 'access_consumables',
                'module'     => 'Consumables (สินค้าสิ้นเปลือง)',
                'hub_url'    => _csm_abs_url('../portal/index.php'),
                'logout_url' => _csm_abs_url('../admin/auth/logout.php'),
                'tailwind'   => _csm_abs_url('../assets/css/tailwind.min.css'),
            ]);
        }
    }
}
