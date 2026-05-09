<?php
/**
 * asset/includes/check_session.php
 * ตรวจสอบ session + role สำหรับโมดูลครุภัณฑ์สำนักงาน
 *
 * Roles ที่ใช้ในโมดูลนี้:
 *   - admin / editor   → ทำได้ทุกอย่าง (CRUD, ลบ, จำหน่าย, จัดการหมวด/จุดใช้งาน)
 *   - employee (staff/พยาบาล) → ดู, ค้นหา, เปลี่ยนสถานะ (in_use ↔ repair), บันทึกย้ายห้อง
 *
 * Re-use SSO sync ของ e_Borrow ผ่าน sys_staff / sys_admins ที่มีอยู่แล้ว
 */

if (!function_exists('_asset_abs_url')) {
    function _asset_abs_url(string $relativePath): string {
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

// ── SSO sync จาก Hub Portal (เหมือน e_Borrow) ────────────────────────────
// แยก context ตาม login source:
//   - Portal admin (sys_admins) → admin_id = sys_admins.id, is_portal_admin = true
//   - e-Campaign Staff (sys_staff) → admin_id = sys_staff.id, is_ecampaign_staff = true
if (!isset($_SESSION['user_id'])
    && isset($_SESSION['admin_logged_in'], $_SESSION['admin_id'])
    && $_SESSION['admin_logged_in'] === true) {
    try {
        require_once __DIR__ . '/db_connect.php';
        $p = db();
        $isPortalAdmin = empty($_SESSION['is_ecampaign_staff']);

        if ($isPortalAdmin) {
            // Portal admin path — ตรวจ sys_admins ตรงๆ ผ่าน id (ไม่ใช้ username เพราะอาจชน sys_staff)
            $sa = $p->prepare("SELECT id, full_name FROM sys_admins WHERE id = :id LIMIT 1");
            $sa->execute([':id' => (int)$_SESSION['admin_id']]);
            $admin = $sa->fetch();
            if ($admin) {
                $_SESSION['user_id']         = (int)$admin['id'];
                $_SESSION['full_name']       = $admin['full_name'];
                $_SESSION['role']            = 'admin';
                $_SESSION['is_portal_admin'] = true;
            } else {
                header('Location: ' . _asset_abs_url('../portal/login.php?error=no_admin_account'));
                exit;
            }
        } else {
            // Staff path — admin_id คือ sys_staff.id (set ที่ admin/auth/staff_login.php)
            $s = $p->prepare("SELECT id, full_name, role FROM sys_staff WHERE id = :id AND account_status = 'active' LIMIT 1");
            $s->execute([':id' => (int)$_SESSION['admin_id']]);
            $row = $s->fetch();
            if ($row) {
                $allowedRoles = ['admin', 'editor', 'employee', 'librarian'];
                $_SESSION['user_id']   = (int)$row['id'];
                $_SESSION['full_name'] = $row['full_name'];
                $_SESSION['role']      = in_array($row['role'], $allowedRoles, true) ? $row['role'] : 'employee';
            } else {
                header('Location: ' . _asset_abs_url('../portal/login.php?error=no_staff_account'));
                exit;
            }
        }
    } catch (Exception $e) {
        header('Location: ' . _asset_abs_url('../portal/login.php?error=sso_failed'));
        exit;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Helpers สิทธิ์ ────────────────────────────────────────────────────────
if (!function_exists('asset_user_role')) {
    function asset_user_role(): string {
        return $_SESSION['role'] ?? 'guest';
    }
}
if (!function_exists('asset_can_manage')) {
    /** admin/editor เท่านั้น — เพิ่ม/แก้/ลบ/จัดการหมวด/จุดใช้งาน */
    function asset_can_manage(): bool {
        return in_array(asset_user_role(), ['admin', 'editor'], true);
    }
}
if (!function_exists('asset_require_manage')) {
    function asset_require_manage(): void {
        if (!asset_can_manage()) {
            require_once __DIR__ . '/../../includes/access_denied_page.php';
            render_access_denied([
                'flag'       => 'role: admin / editor',
                'module'     => 'Asset Inventory — โหมดจัดการ (admin/editor)',
                'hub_url'    => _asset_abs_url('../portal/index.php'),
                'logout_url' => _asset_abs_url('../admin/auth/logout.php'),
                'tailwind'   => _asset_abs_url('../assets/css/tailwind.min.css'),
            ]);
        }
    }
}

// ── Timeout ──────────────────────────────────────────────────────────────
$timeout_duration = 36000; // 10 ชม.
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header('Location: ' . _asset_abs_url('../portal/login.php?timeout=1'));
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

// ── บังคับ login ─────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . _asset_abs_url('../portal/login.php'));
    exit;
}

// ── Module Access Gate (access_asset) ────────────────────────────────────
// portal admin (sys_admins) → ผ่านเสมอ
// sys_staff role 'admin'/'editor' → ผ่าน (ผู้ดูแลคลังเดิม backward compat)
// sys_staff role อื่น → ต้องมี access_asset = 1
if (empty($_SESSION['is_portal_admin'])) {
    $stRole = $_SESSION['role'] ?? '';
    if (!in_array($stRole, ['admin', 'editor'], true)) {
        $hasFlag = $_SESSION['access_asset'] ?? null;
        if ($hasFlag === null) {
            try {
                if (!isset($p)) { require_once __DIR__ . '/db_connect.php'; $p = db(); }
                $stmt = $p->prepare("SELECT IFNULL(access_asset, 0) FROM sys_staff WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $_SESSION['user_id']]);
                $hasFlag = (int)$stmt->fetchColumn();
                $_SESSION['access_asset'] = $hasFlag;
            } catch (Throwable $e) {
                $hasFlag = 0;
            }
        }
        if ((int)$hasFlag !== 1) {
            require_once __DIR__ . '/../../includes/access_denied_page.php';
            render_access_denied([
                'flag'       => 'access_asset',
                'module'     => 'Asset Inventory (ครุภัณฑ์สำนักงาน)',
                'hub_url'    => _asset_abs_url('../portal/index.php'),
                'logout_url' => _asset_abs_url('../admin/auth/logout.php'),
                'tailwind'   => _asset_abs_url('../assets/css/tailwind.min.css'),
            ]);
        }
    }
}
