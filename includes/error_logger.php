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
    string $context = '',
    bool   $skipSentry = false
): void {
    static $tableReady = false;
    static $logPdo     = null;
    static $loggedHashes = []; // เก็บ hash ของ error ที่บันทึกไปแล้วใน request นี้

    // 1. ป้องกัน Spam: ถ้าเป็น Error เดิมที่เพิ่งบันทึกไปในรอบนี้ ให้ข้ามเลย
    $errorHash = md5($level . $source . $message);
    if (isset($loggedHashes[$errorHash])) return;
    $loggedHashes[$errorHash] = true;

    // 2. ป้องกัน Spam: ข้ามข้อความขยะที่พบบ่อยและไม่สำคัญ
    $ignoredMessages = [
        'session_start(): session_regenerate_id()',
        'Undefined index: invite_token',
        'Undefined index: admin_id',
        'Creation of dynamic property',
        'Constant _ERROR_LOGGER_HANDLERS_SET already defined'
    ];
    foreach ($ignoredMessages as $ignored) {
        if (str_contains($message, $ignored)) return;
    }

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
                      $_SESSION['student_id']  ??
                      $_SESSION['user_id']           ??
                      $_SESSION['student_id']        ?? null;
        }

        $logPdo->prepare(
            "INSERT INTO sys_error_logs (level, source, message, context, ip_address, user_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$level, $source, $message, $context, $ip, $userId]);

        // ── Auto-purge: ลบ log เก่ากว่า 30 วัน (ทำงานแบบ probabilistic ~1% ของ request)
        if (mt_rand(1, 100) === 1) {
            $logPdo->exec("DELETE FROM sys_error_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }

    } catch (Throwable) {
        // ไม่ทำอะไร — ป้องกัน infinite loop ถ้า DB เองมีปัญหา
    }

    // ── Mirror ไป Sentry — เฉพาะ manual report จาก catch block ──────────────
    // Auto error handler ส่ง $skipSentry=true เพราะ Sentry's built-in handler
    // capture เองอยู่แล้วผ่าน chain ของ set_error_handler — กัน double-capture
    // ข้าม level=info เพราะเป็น operational log ปกติ (เช่น "AI QA matcher start")
    // ไม่ใช่ error ที่ต้อง alert
    if (!$skipSentry && $level !== 'info' && class_exists('\\Sentry\\SentrySdk', false)) {
        try {
            $hub = \Sentry\SentrySdk::getCurrentHub();
            if ($hub->getClient() !== null) {
                $sev = match ($level) {
                    'error'   => \Sentry\Severity::error(),
                    'warning' => \Sentry\Severity::warning(),
                    default   => \Sentry\Severity::info(),
                };
                \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($message, $sev, $source, $context) {
                    $scope->setLevel($sev);
                    if ($source !== '')  $scope->setTag('error_source', $source);
                    if ($context !== '') $scope->setExtra('context', $context);
                    \Sentry\captureMessage($message);
                });
            }
        } catch (Throwable) { /* never crash on Sentry failure */ }
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

        $level = $levelMap[$errno] ?? 'warning';

        // กรองเพิ่ม: ถ้าเป็นระดับ 'info' (Notice/Deprecated) จะไม่บันทึกลง DB เพื่อลด Spam
        // (แต่ยังคงปล่อยให้ PHP จัดการตามปกติ เช่น แสดงผลถ้าเปิด display_errors)
        if ($level === 'info') return false;

        log_error_to_db(
            $errstr,
            $level,
            basename($errfile) . ':' . $errline,
            $errfile . ':' . $errline,
            true   // Sentry's chained error handler already captured this
        );
        return false;
    });

    set_exception_handler(function (Throwable $e): void {
        // Sentry's exception handler was overwritten by this one (set_exception_handler
        // ไม่ chain — ทับ handler เดิม) — capture ตรงๆ ก่อน เพื่อให้ uncaught exception
        // ถึง Sentry ก่อนทำต่อ
        if (class_exists('\\Sentry\\SentrySdk', false)) {
            try {
                $hub = \Sentry\SentrySdk::getCurrentHub();
                if ($hub->getClient() !== null) {
                    \Sentry\captureException($e);
                }
            } catch (Throwable) { /* swallow */ }
        }
        log_error_to_db(
            get_class($e) . ': ' . $e->getMessage(),
            'error',
            basename($e->getFile()) . ':' . $e->getLine(),
            $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString(),
            true   // already captured to Sentry above
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
                $err['file'] . ':' . $err['line'],
                true   // Sentry registered its own shutdown via init() — already captured
            );
        }
    });
}


