<?php
// includes/check_session.php
// สำหรับ "หน้าหลัก" (HTML Pages) ของระบบยืมคืนเดิม
// ปรับปรุงใหม่: รองรับ SSO จาก Hub Portal และระบบพนักงานกลาง

// ── Absolute URL helper ──────────────────────────────────────────────────────
// ป้องกัน chrome-error:// origin mismatch เมื่อหน้าถูก embed ใน iframe
if (!function_exists('_eborrow_abs_url')) {
    function _eborrow_abs_url(string $relativePath): string {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Normalize slashes for robust replacement
        $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
        $targetDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
        
        // Use str_ireplace to handle case differences on Windows (e.g. C: vs c:)
        $relativeDir = str_ireplace($docRoot, '', $targetDir);
        
        $adminUrl = $proto . '://' . $host . '/' . ltrim($relativeDir, '/');
        return rtrim($adminUrl, '/') . '/' . ltrim($relativePath, '/');
    }
}

@session_start();

// --- [NEW] SSO Sync from Hub Portal ---
// เมื่อมีการ Login ผ่านระบบใหม่ ให้ Sync สิทธิ์เข้ามาที่ระบบยืมคืนโดยอัตโนมัติ
// SSO Sync: Portal Admin → e-Borrow (เฉพาะที่มีบัญชี sys_staff จริงเท่านั้น)
if (!isset($_SESSION['user_id'])
    && isset($_SESSION['admin_logged_in'], $_SESSION['admin_id'])
    && $_SESSION['admin_logged_in'] === true) {
    try {
        require_once __DIR__ . '/../includes/db_connect.php';
        $p = db();
        $uname = $_SESSION['admin_username'] ?? '';

        // ค้นหาใน sys_staff — ต้องมีบัญชีจริง สถานะ active และมีสิทธิ์ access_eborrow
        $s = $p->prepare("SELECT id, full_name, role, IFNULL(access_eborrow, 1) AS access_eborrow FROM sys_staff WHERE username = :u AND account_status = 'active' LIMIT 1");
        $s->execute([':u' => $uname]);
        $row = $s->fetch();

        if ($row) {
            // ตรวจสอบสิทธิ์การเข้าถึง e-Borrow รายบุคคล
            if (intval($row['access_eborrow']) === 0) {
                header('Location: ' . _eborrow_abs_url('login.php?error=access_denied_eborrow'));
                exit;
            }
            
            // พบบัญชี sys_staff → ใช้ role จาก staff record
            $allowedRoles = ['admin', 'editor', 'employee', 'librarian'];
            $_SESSION['user_id']   = $row['id'];
            $_SESSION['full_name'] = $row['full_name'];
            $_SESSION['role']      = in_array($row['role'], $allowedRoles, true) ? $row['role'] : 'employee';
        } else {
            // ไม่มีบัญชี sys_staff → fallback ตรวจสอบ sys_admins
            // Portal Admin (superadmin/admin) ให้เข้า e-Borrow ได้ในฐานะ admin โดยอัตโนมัติ
            $adminId   = $_SESSION['admin_id'] ?? null;
            $adminName = $_SESSION['admin_username'] ?? '';
            $sa = $p->prepare("SELECT id, full_name FROM sys_admins WHERE id = :id LIMIT 1");
            $sa->execute([':id' => $adminId]);
            $adminRow = $sa->fetch();

            if ($adminRow) {
                // เป็น portal admin จริง → grant e-Borrow access ในฐานะ admin
                // ใช้ admin_id จาก sys_admins โดยตรง (integer) และ flag is_portal_admin
                $_SESSION['user_id']         = (int) $adminId;
                $_SESSION['full_name']       = $adminRow['full_name'];
                $_SESSION['role']            = 'admin';
                $_SESSION['is_portal_admin'] = true;
            } else {
                // ไม่ใช่ทั้ง staff และ admin → ปฏิเสธ
                header('Location: ' . _eborrow_abs_url('login.php?error=no_staff_account'));
                exit;
            }
        }
    } catch (Exception $e) {
        // DB ไม่พร้อม → ปฏิเสธการเข้าถึงแบบ fail-secure
        header('Location: ' . _eborrow_abs_url('login.php?error=sso_failed'));
        exit;
    }
}

// ── CSRF Token Generation ──────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Centralized role helper ────────────────────────────────────────────────
// ใช้แทนการเรียก in_array ซ้ำๆ ทั่วระบบ e-Borrow
if (!function_exists('require_eborrow_role')) {
    function require_eborrow_role($roles) {
        if (!is_array($roles)) $roles = [$roles];
        if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
            header("Location: index.php");
            exit;
        }
    }
}

// 1. ระบบ Timeout (วินาที)
$timeout_duration = 36000; // เพิ่มเวลาเป็น 10 ชม. เพื่อความสะดวก

// 2. ตรวจสอบเงื่อนไข Timeout
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
        session_unset();     
        session_destroy();
        header('Location: ' . _eborrow_abs_url('login.php?timeout=1'));
        exit;
    }
}

// 3. รับรู้กิจกรรมล่าสุด
$_SESSION['LAST_ACTIVITY'] = time();

// 4. บังคับย้อนกลับไป Login หากไม่มีตัวตนในเซสชัน
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . _eborrow_abs_url('login.php'));
    exit;
} else {
    // [ISO 27001] ตรวจสอบสิทธิ์การเข้าถึงแบบ Real-time สำหรับ Staff
    // ป้องกันกรณีแอดมินปิดสิทธิ์ขณะที่เจ้าหน้าที่ล็อกอินค้างอยู่
    if (!isset($_SESSION['is_portal_admin'])) {
        try {
            require_once __DIR__ . '/../includes/db_connect.php';
            $db = db();
            $check = $db->prepare("SELECT IFNULL(access_eborrow, 1) as access FROM sys_staff WHERE id = ? AND account_status = 'active' LIMIT 1");
            $check->execute([$_SESSION['user_id']]);
            $staffRow = $check->fetch();
            if (!$staffRow || intval($staffRow['access']) === 0) {
                // ถูกถอนสิทธิ์ หรือบัญชีถูกระงับ
                session_unset();
                session_destroy();
                header('Location: ' . _eborrow_abs_url('login.php?error=access_revoked'));
                exit;
            }
        } catch (Exception $e) { /* DB error - safe fallback to existing session */ }
    }
}
?>