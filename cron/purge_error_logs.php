<?php
/**
 * cron/purge_error_logs.php
 * ลบ error log เก่ากว่า 30 วัน และ activity log เก่ากว่า 90 วัน
 *
 * ติดตั้ง cron job (รันทุกวัน ตี 2):
 *   0 2 * * * php /path/to/e-campaignv2/cron/purge_error_logs.php >> /path/to/e-campaignv2/cron/logs/purge.log 2>&1
 *
 * ทดสอบรันมือ:
 *   php cron/purge_error_logs.php
 */
declare(strict_types=1);

// ป้องกันรันผ่าน web browser
if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_HOST']) === false) {
    http_response_code(403);
    exit('Access denied');
}

require_once __DIR__ . '/../config/db_connect.php';

$pdo = db();
$now = date('Y-m-d H:i:s');

echo "[{$now}] Starting log purge...\n";

// ── 1. ลบ error logs เก่ากว่า 30 วัน ─────────────────────────────────────────
try {
    $stmt = $pdo->prepare("DELETE FROM sys_error_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
    $stmt->execute([':days' => ERROR_LOG_RETENTION_DAYS]);
    $deleted = $stmt->rowCount();
    echo "[{$now}] sys_error_logs: deleted {$deleted} rows older than " . ERROR_LOG_RETENTION_DAYS . " days\n";
} catch (PDOException $e) {
    echo "[{$now}] ERROR (sys_error_logs): " . $e->getMessage() . "\n";
}

// ── 2. ลบ activity logs เก่ากว่า 90 วัน ─────────────────────────────────────
try {
    $stmt = $pdo->prepare("DELETE FROM sys_activity_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)");
    $stmt->execute([':days' => ACTIVITY_LOG_RETENTION_DAYS]);
    $deleted = $stmt->rowCount();
    echo "[{$now}] sys_activity_logs: deleted {$deleted} rows older than " . ACTIVITY_LOG_RETENTION_DAYS . " days\n";
} catch (PDOException $e) {
    // ตารางอาจไม่มี — ข้ามไป
    echo "[{$now}] SKIP (sys_activity_logs): " . $e->getMessage() . "\n";
}

echo "[{$now}] Purge complete.\n";
