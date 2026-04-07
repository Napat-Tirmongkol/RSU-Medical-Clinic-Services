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
        // __DIR__ = .../archive/e_Borrow/includes
        $adminDir = rtrim(str_replace(
            str_replace('/', DIRECTORY_SEPARATOR, $_SERVER['DOCUMENT_ROOT']),
            '',
            realpath(__DIR__ . '/../admin')
        ), DIRECTORY_SEPARATOR);
        $adminUrl = $proto . '://' . $host . '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $adminDir), '/');
        return $adminUrl . '/' . ltrim($relativePath, '/');
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
        require_once __DIR__ . '/../../../config/db_connect.php';
        $p = db();
        $uname = $_SESSION['admin_username'] ?? '';

        // ค้นหาใน sys_staff — ต้องมีบัญชีจริงและสถานะ active
        $s = $p->prepare("SELECT id, full_name, role FROM sys_staff WHERE username = :u AND account_status = 'active' LIMIT 1");
        $s->execute([':u' => $uname]);
        $row = $s->fetch();

        if ($row) {
            // Whitelist role ก่อน set session
            $allowedRoles = ['admin', 'editor', 'employee', 'librarian'];
            $_SESSION['user_id']   = $row['id'];
            $_SESSION['full_name'] = $row['full_name'];
            $_SESSION['role']      = in_array($row['role'], $allowedRoles, true) ? $row['role'] : 'employee';
        } else {
            // ไม่มีบัญชี staff → ไม่อนุญาต (แทนที่จะ grant admin โดยอัตโนมัติ)
            header('Location: ' . _eborrow_abs_url('login.php?error=no_staff_account'));
            exit;
        }
    } catch (Exception $e) {
        // DB ไม่พร้อม → ปฏิเสธการเข้าถึงแบบ fail-secure
        header('Location: ' . _eborrow_abs_url('login.php?error=sso_failed'));
        exit;
    }
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
}
?>