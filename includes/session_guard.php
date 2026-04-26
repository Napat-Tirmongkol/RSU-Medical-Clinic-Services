<?php
/**
 * includes/session_guard.php
 * Shared session bootstrap for admin and portal auth guards.
 */
declare(strict_types=1);

const ADMIN_SESSION_TIMEOUT = 7200; // 2 hours

/**
 * Start a secure admin session (call before any output).
 */
function start_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;

    ini_set('session.gc_maxlifetime', (string)ADMIN_SESSION_TIMEOUT);
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    session_start();
}

/**
 * Check idle timeout and redirect to login if expired.
 *
 * @param string $loginUrl     URL to admin login page (relative from current file's location)
 * @param string $staffLoginUrl URL to staff login page
 */
function check_admin_session(string $loginUrl, string $staffLoginUrl): void
{
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        header('Location: ' . $loginUrl);
        exit;
    }

    // Idle timeout
    if (isset($_SESSION['_admin_last_activity'])) {
        if (time() - $_SESSION['_admin_last_activity'] > ADMIN_SESSION_TIMEOUT) {
            $isStaff = !empty($_SESSION['is_ecampaign_staff']);
            session_unset();
            session_destroy();
            header('Location: ' . ($isStaff ? $staffLoginUrl : $loginUrl) . '?reason=timeout');
            exit;
        }
    }
    $_SESSION['_admin_last_activity'] = time();

    // Tenant mismatch guard: session clinic ต้องตรงกับ CLINIC_ID ที่ resolve ได้จาก subdomain
    // (superadmin ที่ไม่มี clinic_id ใน session จะ bypass ได้ — backward-compat)
    if (
        defined('CLINIC_ID') &&
        isset($_SESSION['clinic_id']) &&
        (int)$_SESSION['clinic_id'] !== CLINIC_ID
    ) {
        $isStaff = !empty($_SESSION['is_ecampaign_staff']);
        session_unset();
        session_destroy();
        header('Location: ' . ($isStaff ? $staffLoginUrl : $loginUrl) . '?reason=clinic_mismatch');
        exit;
    }
}
