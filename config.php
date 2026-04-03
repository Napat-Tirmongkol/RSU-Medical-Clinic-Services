<?php
// Bridge file: ป้องกันอดีตไฟล์ที่อ้างอิงถึง config.php เดิมพัง
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/error_logger.php';

// ── Log Retention Settings ────────────────────────────────────────────────────
defined('ERROR_LOG_RETENTION_DAYS')    || define('ERROR_LOG_RETENTION_DAYS',    30);  // วัน
defined('ACTIVITY_LOG_RETENTION_DAYS') || define('ACTIVITY_LOG_RETENTION_DAYS', 90);  // วัน

/**
 * 🛰️ ฟังก์ชันกลางสำหรับบันทึกกิจกรรมในระบบ (Activity Logging)
 * ย้ายมาไว้ที่ config.php เพื่อเป็นประตูที่เข้าถึงได้จากทุกทิศทาง
 */
if (!function_exists('log_activity')) {
    function log_activity(string $action, string $description = '', ?int $user_id = null): bool {
        try {
            if (session_status() === PHP_SESSION_NONE) session_start();
            
            if ($user_id === null && isset($_SESSION['admin_id'])) {
                $user_id = (int)$_SESSION['admin_id'];
            }
            if ($user_id === null) return false;

            $pdo = db();
            $stmt = $pdo->prepare("INSERT INTO sys_activity_logs (user_id, action, description) VALUES (:uid, :act, :desc)");
            return $stmt->execute([
                ':uid'  => $user_id,
                ':act'  => $action,
                ':desc' => $description
            ]);
        } catch (Exception $e) {
            error_log("Logging Error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * 👤 ตรวจสอบว่าผู้ใช้งานกรอกข้อมูลส่วนตัวครบถ้วนหรือไม่ (โดยเฉพาะอีเมล)
 */
if (!function_exists('check_user_profile')) {
    function check_user_profile(int $studentId): void {
        if ($studentId <= 0) {
            header('Location: index.php');
            exit;
        }

        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT full_name, student_personnel_id, phone_number, status, email FROM sys_users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $studentId]);
            $u = $stmt->fetch();

            if (!$u || empty($u['full_name']) || empty($u['phone_number']) || empty($u['status']) || ($u['status'] !== 'other' && empty($u['student_personnel_id']))) {
                header('Location: profile.php');
                exit;
            }
        } catch (PDOException $e) {
            // Silently fail or log
        }
    }
}
?>
