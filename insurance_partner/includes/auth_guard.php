<?php
/**
 * insurance_partner/includes/auth_guard.php
 *
 * Session guard สำหรับ Insurance Partner Portal (เจ้าหน้าที่บริษัทประกัน external)
 * แยก namespace session ออกจาก staff portal เพื่อกัน privilege escalation
 *
 * Session keys:
 *   ins_partner_logged_in   bool
 *   ins_partner_id          int
 *   ins_partner_username    string
 *   ins_partner_full_name   string
 *   ins_partner_company     string  (company_code)
 *   ins_partner_company_name string
 *   _ins_partner_last_activity int   (epoch)
 */
declare(strict_types=1);

const INS_PARTNER_SESSION_TIMEOUT = 1800; // 30 นาที (สั้นกว่า staff เพราะเป็น external)

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string)INS_PARTNER_SESSION_TIMEOUT);
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    session_start();
}

require_once __DIR__ . '/../../config.php';

/**
 * บันทึก audit log ของฝั่ง partner (ISO 27001)
 */
function ins_partner_log(string $action, string $details = ''): void
{
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO insurance_partner_activity_log
                (partner_user_id, company_code, username, action, details, ip_address, user_agent)
            VALUES (:uid, :cc, :un, :act, :det, :ip, :ua)
        ");
        $stmt->execute([
            ':uid' => $_SESSION['ins_partner_id'] ?? null,
            ':cc'  => $_SESSION['ins_partner_company'] ?? null,
            ':un'  => $_SESSION['ins_partner_username'] ?? null,
            ':act' => mb_substr($action, 0, 50),
            ':det' => mb_substr($details, 0, 5000),
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Exception $e) {
        error_log('ins_partner_log: ' . $e->getMessage());
    }
}

/**
 * ตรวจสอบ session — redirect ไป login ถ้าไม่ผ่าน
 */
function require_ins_partner_login(): void
{
    if (empty($_SESSION['ins_partner_logged_in']) || $_SESSION['ins_partner_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }

    // Idle timeout
    if (isset($_SESSION['_ins_partner_last_activity'])) {
        if (time() - $_SESSION['_ins_partner_last_activity'] > INS_PARTNER_SESSION_TIMEOUT) {
            ins_partner_log('session_timeout', 'idle > ' . INS_PARTNER_SESSION_TIMEOUT . 's');
            // ล้างเฉพาะ key ของ partner — ไม่แตะ session ของ staff (ถ้ามี)
            foreach ($_SESSION as $k => $_) {
                if (strpos($k, 'ins_partner_') === 0 || $k === '_ins_partner_last_activity') {
                    unset($_SESSION[$k]);
                }
            }
            header('Location: login.php?reason=timeout');
            exit;
        }
    }
    $_SESSION['_ins_partner_last_activity'] = time();
}

/**
 * ดึงข้อมูล partner user ปัจจุบัน
 */
function current_ins_partner(): array
{
    return [
        'id'           => (int)($_SESSION['ins_partner_id'] ?? 0),
        'username'     => $_SESSION['ins_partner_username'] ?? '',
        'full_name'    => $_SESSION['ins_partner_full_name'] ?? '',
        'company_code' => $_SESSION['ins_partner_company'] ?? '',
        'company_name' => $_SESSION['ins_partner_company_name'] ?? '',
    ];
}
