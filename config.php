<?php
// config.php — จุดเข้าหลักข���งระบบทุกไฟล์ (canonical entry point)
// โหลด: DB connection, CSRF, Error Logger และ helper functions
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/error_logger.php';
require_once __DIR__ . '/config/sentry.php'; // โหลดหลัง error_logger — Sentry wraps handler chain

// ── Log Retention Settings ────────────────────────────────────────────────────
defined('ERROR_LOG_RETENTION_DAYS')    || define('ERROR_LOG_RETENTION_DAYS',    30);  // วัน
defined('ACTIVITY_LOG_RETENTION_DAYS') || define('ACTIVITY_LOG_RETENTION_DAYS', 90);  // วัน

// ── Finance Settings ──────────────────────────────────────────────────────────
defined('FINE_RATE_PER_DAY') || define('FINE_RATE_PER_DAY', 10); // ค่าปรับวันละ 10 บาท

// ── Site Settings ─────────────────────────────────────────────────────────────
$__siteSettingsFile = __DIR__ . '/config/site_settings.json';
$__siteSettings = file_exists($__siteSettingsFile) ? json_decode(file_get_contents($__siteSettingsFile), true) : [];
defined('SITE_NAME') || define('SITE_NAME', $__siteSettings['site_name'] ?? 'e-Campaign V2');
defined('SITE_LOGO') || define('SITE_LOGO', $__siteSettings['site_logo'] ?? ''); // Empty means use default icon
defined('GEMINI_API_KEY') || define('GEMINI_API_KEY', $__siteSettings['gemini_api_key'] ?? '');


/**
 * ฟังก์ชันกลางสำหรับบันทึกกิจกรรมในระบบ (Activity Logging)
 */
if (!function_exists('log_activity')) {
    function log_activity(string $action, string $description = '', ?int $user_id = null): bool {
        static $activityTableReady = false;
        try {
            if (session_status() === PHP_SESSION_NONE) session_start();
            
            $pdo = db();

            // Auto-create table if not exists (runs once per request)
            if (!$activityTableReady) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS sys_activity_logs (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id     INT UNSIGNED NULL,
                    action      VARCHAR(100) NOT NULL,
                    description TEXT,
                    ip_address  VARCHAR(45),
                    user_agent  TEXT,
                    timestamp   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_action (action),
                    INDEX idx_timestamp (timestamp)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $activityTableReady = true;
            }

            if ($user_id === null) {
                $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
                if ($user_id !== null) $user_id = (int)$user_id;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

            $stmt = $pdo->prepare("
                INSERT INTO sys_activity_logs (user_id, action, description, ip_address, user_agent) 
                VALUES (:uid, :act, :desc, :ip, :ua)
            ");
            return $stmt->execute([
                ':uid'  => $user_id,
                ':act'  => mb_substr($action, 0, 100),
                ':desc' => $description,
                ':ip'   => $ip,
                ':ua'   => mb_substr($ua, 0, 1000)
            ]);
        } catch (Exception $e) {
            error_log("Logging Error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * ตรวจสอบว่าผู้ใช้งานกรอกข้อมูลส่วนตัวครบถ้วนหรือไม่
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
/**
 * ตรวจสอบสถานะการปิดปรับปรุงระบบ (Maintenance Mode)
 */
if (!function_exists('check_maintenance')) {
    function check_maintenance(string $project_key): void {
        // ถ้าเป็น Admin ให้เข้าได้ปกติเสมอ
        if (isset($_SESSION['admin_id'])) return;

        // ตรวจสอบชื่อไฟล์ปัจจุบัน - ถ้าเป็นหน้า Login ให้ผ่านได้เสมอเพื่อให้กด Log in มาเช็ค Whitelist ได้
        $currentScript = basename($_SERVER['SCRIPT_NAME']);
        if ($currentScript === 'index.php' && strpos($_SERVER['REQUEST_URI'], '/user/') !== false) return;
        if ($currentScript === 'line_login.php' || $currentScript === 'google_callback.php') return;

        $mFile = __DIR__ . '/config/maintenance.json';
        if (file_exists($mFile)) {
            $mData = json_decode(file_get_contents($mFile), true);
            
            // ตรวจสอบ Whitelist (LINE ID หรือ Student ID)
            $whitelist = $mData['whitelist'] ?? [];
            if (!empty($_SESSION['line_user_id']) && in_array($_SESSION['line_user_id'], $whitelist)) return;
            if (!empty($_SESSION['evax_student_id']) && in_array($_SESSION['evax_student_id'], $whitelist)) return;
            
            $isActive = $mData[$project_key] ?? true;
            
            if (!$isActive) {
                // ถ้าปิดระบบ ให้แสดงหน้า Maintenance
                // ค้นหา path ของหน้า maintenance
                $root = (strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/portal/') !== false) ? '../' : '';
                
                // ถ้าอยู่ใน subdir ของโปรเจกต์ เช่น e_Borrow/index.php
                // เราต้องหาทางออกไปที่ root/errors/maintenance.php
                // เพื่อความง่าย เราจะแสดงข้อความง่ายๆ หรือ redirect
                http_response_code(503);
                ?>
                <!DOCTYPE html>
                <html lang="th">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>ปิดปรับปรุงระบบ - System Maintenance</title>
                    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;700&display=swap" rel="stylesheet">
                    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
                    <style>
                        body { font-family: 'Prompt', sans-serif; background: #f4f7fa; color: #334155; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; text-align: center; padding: 20px; }
                        .card { background: white; padding: 40px; border-radius: 30px; shadow: 0 10px 30px rgba(0,0,0,0.05); max-width: 500px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,0.05); }
                        .icon { font-size: 60px; color: #f59e0b; margin-bottom: 20px; }
                        h1 { font-size: 24px; font-weight: 700; margin: 0 0 10px; color: #1e293b; }
                        p { font-size: 15px; color: #64748b; line-height: 1.6; margin-bottom: 25px; }
                        .timer { font-size: 13px; font-weight: 700; color: #2563eb; background: #eff6ff; padding: 8px 16px; border-radius: 99px; display: inline-block; }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <div class="icon">🚧</div>
                        <h1>ขออภัย ระบบปิดปรับปรุงชั่วคราว</h1>
                        <p>ขณะนี้ระบบ <strong><?= htmlspecialchars($project_key === 'e_borrow' ? 'e-Borrow & Inventory' : 'e-Campaign') ?></strong> กำลังอยู่ระหว่างการเพิ่มประสิทธิภาพและปรับปรุงข้อมูล <br>กรุณากลับมาใช้งานใหม่อีกครั้งในภายหลัง</p>
                        <div class="timer">ขอบคุณที่ท่านให้ความร่วมมือ</div>
                    </div>
                </body>
                </html>
                <?php
                exit;
            }
        }
    }
}
?>
