<?php
/**
 * cron/purge_error_logs.php
 * ลบ error log เก่ากว่า 30 วัน และ activity log เก่ากว่า 90 วัน
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────
 *  URL     : https://healthycampus.rsu.ac.th/e-campaignv2/cron/purge_error_logs.php?token=YOUR_SECRET_TOKEN
 *  Schedule: Every day at 2:00 (Asia/Bangkok)
 * ──────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

// ── Secret Token (ต้องตรงกันกับที่ใส่ใน URL ของ cron-job.org) ──────────────
define('PURGE_SECRET_TOKEN', 'rsu_purge_a8f3k2m9x');

// ── ตรวจสอบ token (รองรับทั้ง HTTP และ CLI) ──────────────────────────────────
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $token = $_GET['token'] ?? '';
    if (!hash_equals(PURGE_SECRET_TOKEN, $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ── โหลด config (DB + constants + helpers) ───────────────────────────────────
require_once __DIR__ . '/../config.php';
// constants ถูก define ใน config.php แล้ว — fallback ไว้กันกรณีรัน standalone
defined('ERROR_LOG_RETENTION_DAYS')    || define('ERROR_LOG_RETENTION_DAYS',    30);
defined('ACTIVITY_LOG_RETENTION_DAYS') || define('ACTIVITY_LOG_RETENTION_DAYS', 90);

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

// ── 2. ลบ activity logs เก่ากว่า 90 วัน ──────────────────────────────────────
try {
    $stmt = $pdo->prepare("DELETE FROM sys_activity_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)");
    $stmt->execute([':days' => ACTIVITY_LOG_RETENTION_DAYS]);
    $deleted = $stmt->rowCount();
    echo "[{$now}] sys_activity_logs: deleted {$deleted} rows older than " . ACTIVITY_LOG_RETENTION_DAYS . " days\n";
} catch (PDOException $e) {
    echo "[{$now}] SKIP (sys_activity_logs): " . $e->getMessage() . "\n";
}

echo "[{$now}] Purge complete.\n";
