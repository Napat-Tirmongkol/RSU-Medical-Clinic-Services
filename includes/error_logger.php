<?php
/**
 * includes/error_logger.php
 * Central error logging to sys_error_logs table.
 * Safe to include from any subfolder — loads its own DB connection if db() is unavailable.
 */
declare(strict_types=1);

// ─── Core logging function ────────────────────────────────────────────────────

function log_error_to_db(
    string $message,
    string $level   = 'error',
    string $source  = '',
    string $context = ''
): void {
    static $tableReady = false;
    static $logPdo     = null;

    try {
        // ใช้ db() ถ้ามี ไม่งั้นสร้าง connection ไปยัง main DB เอง
        if ($logPdo === null) {
            if (function_exists('db')) {
                $logPdo = db();
            } else {
                $configPath = __DIR__ . '/../config/db_connect.php';
                if (!file_exists($configPath)) return;
                require_once $configPath;
                $logPdo = db();
            }
        }

        if (!$tableReady) {
            $logPdo->exec("CREATE TABLE IF NOT EXISTS sys_error_logs (
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

        $message = mb_substr($message, 0, 5000);
        $context = mb_substr($context, 0, 2000);
        $source  = mb_substr($source,  0, 300);

        $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
        $userId = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $userId = $_SESSION['admin_id']        ??
                      $_SESSION['evax_student_id']  ??
                      $_SESSION['user_id']           ??
                      $_SESSION['student_id']        ?? null;
        }

        $logPdo->prepare(
            "INSERT INTO sys_error_logs (level, source, message, context, ip_address, user_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$level, $source, $message, $context, $ip, $userId]);

    } catch (Throwable) {
        // ไม่ทำอะไร — ป้องกัน infinite loop ถ้า DB เองมีปัญหา
    }
}

// ─── ติดตั้ง handlers เพียงครั้งเดียว ────────────────────────────────────────
if (!defined('_ERROR_LOGGER_HANDLERS_SET')) {
    define('_ERROR_LOGGER_HANDLERS_SET', true);

    set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) return false;

        $levelMap = [
            E_ERROR             => 'error',   E_WARNING           => 'warning',
            E_NOTICE            => 'info',    E_USER_ERROR        => 'error',
            E_USER_WARNING      => 'warning', E_USER_NOTICE       => 'info',
            E_DEPRECATED        => 'info',    E_USER_DEPRECATED   => 'info',
            E_RECOVERABLE_ERROR => 'error',
        ];

        log_error_to_db(
            $errstr,
            $levelMap[$errno] ?? 'warning',
            basename($errfile) . ':' . $errline,
            $errfile . ':' . $errline
        );
        return false;
    });

    set_exception_handler(function (Throwable $e): void {
        log_error_to_db(
            get_class($e) . ': ' . $e->getMessage(),
            'error',
            basename($e->getFile()) . ':' . $e->getLine(),
            $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
        );
        if (!headers_sent()) http_response_code(500);
        echo '<p style="font-family:sans-serif;color:#c00;padding:2rem">เกิดข้อผิดพลาดภายในระบบ กรุณาลองใหม่อีกครั้ง</p>';
    });

    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            log_error_to_db(
                $err['message'], 'error',
                basename($err['file']) . ':' . $err['line'],
                $err['file'] . ':' . $err['line']
            );
        }
    });
}


