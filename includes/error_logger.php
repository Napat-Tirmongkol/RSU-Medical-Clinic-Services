<?php
/**
 * includes/error_logger.php
 * Central error logging to sys_error_logs table.
 * Include this file once (via config.php) to auto-capture PHP errors/exceptions.
 */
declare(strict_types=1);

// ─── Core logging function ────────────────────────────────────────────────────

function log_error_to_db(
    string $message,
    string $level   = 'error',   // error | warning | info
    string $source  = '',
    string $context = ''
): void {
    static $tableReady = false;

    try {
        $pdo = db();

        if (!$tableReady) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_error_logs (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                level      ENUM('error','warning','info') NOT NULL DEFAULT 'error',
                source     VARCHAR(300)  NOT NULL DEFAULT '',
                message    TEXT          NOT NULL,
                context    TEXT          NOT NULL DEFAULT '',
                ip_address VARCHAR(45)   NOT NULL DEFAULT '',
                user_id    INT UNSIGNED  NULL,
                created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_level      (level),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $tableReady = true;
        }

        // ตัด message ยาวเกินไป
        $message = mb_substr($message, 0, 5000);
        $context = mb_substr($context, 0, 2000);
        $source  = mb_substr($source,  0, 300);

        $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
        $userId  = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $userId = $_SESSION['admin_id']      ??
                      $_SESSION['evax_student_id'] ??
                      $_SESSION['user_id']          ?? null;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO sys_error_logs (level, source, message, context, ip_address, user_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$level, $source, $message, $context, $ip, $userId]);

    } catch (Throwable) {
        // ไม่ทำอะไร — ป้องกัน infinite loop ถ้า DB เองมีปัญหา
    }
}

// ─── PHP error handler ────────────────────────────────────────────────────────

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // ละเว้น error ที่ถูก suppress ด้วย @ operator
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $levelMap = [
        E_ERROR             => 'error',
        E_WARNING           => 'warning',
        E_NOTICE            => 'info',
        E_USER_ERROR        => 'error',
        E_USER_WARNING      => 'warning',
        E_USER_NOTICE       => 'info',
        E_DEPRECATED        => 'info',
        E_USER_DEPRECATED   => 'info',
        E_RECOVERABLE_ERROR => 'error',
    ];

    $level  = $levelMap[$errno] ?? 'warning';
    $source = basename($errfile) . ':' . $errline;

    log_error_to_db($errstr, $level, $source, $errfile . ':' . $errline);

    // คืน false = ให้ PHP จัดการ error ปกติด้วย (แสดงบน screen ถ้า display_errors=On)
    return false;
});

// ─── Uncaught exception handler ───────────────────────────────────────────────

set_exception_handler(function (Throwable $e): void {
    $source  = basename($e->getFile()) . ':' . $e->getLine();
    $context = $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
    log_error_to_db(get_class($e) . ': ' . $e->getMessage(), 'error', $source, $context);

    // แสดง generic error แทน stack trace
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<p style="font-family:sans-serif;color:#c00;padding:2rem">เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่อีกครั้ง</p>';
});

// ─── Fatal error catcher (shutdown) ──────────────────────────────────────────

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $source  = basename($err['file']) . ':' . $err['line'];
        $context = $err['file'] . ':' . $err['line'];
        log_error_to_db($err['message'], 'error', $source, $context);
    }
});
