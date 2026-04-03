<?php
/**
 * cron/run_backup.php
 * HTTP endpoint สำหรับ cron-job.org เรียกทำ DB Backup
 *
 * URL: https://healthycampus.rsu.ac.th/e-campaignv2/cron/run_backup.php?token=YOUR_SECRET_TOKEN
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────
 *  URL    : https://healthycampus.rsu.ac.th/e-campaignv2/cron/run_backup.php?token=YOUR_SECRET_TOKEN
 *  Schedule: Every day at 2:00 (Asia/Bangkok)
 * ──────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

// ── Secret Token (เปลี่ยนเป็นรหัสของคุณ) ─────────────────────────────────────
define('BACKUP_SECRET_TOKEN', 'rsu_purge_a8f3k2m9x');

// ── ตรวจสอบ token ─────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(BACKUP_SECRET_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

// ── โหลด DB credentials ────────────────────────────────────────────────────────
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db_connect.php';

// ดึงค่าจาก variables ที่ db_connect.php ประกาศไว้
$dbHost = $db_host ?? 'localhost';
$dbUser = $db_user ?? '';
$dbPass = $db_pass ?? '';
$dbName = $db_name ?? '';
$dbPort = $db_port ?? 3306;

$backupDir = __DIR__ . '/backups';
$logDir    = __DIR__ . '/logs';
$timestamp = date('Ymd_His');
$backupFile = "{$backupDir}/{$dbName}_{$timestamp}.sql.gz";
$logFile    = "{$logDir}/backup.log";
$now        = date('Y-m-d H:i:s');

@mkdir($backupDir, 0750, true);
@mkdir($logDir,    0750, true);

// ── ฟังก์ชัน log ──────────────────────────────────────────────────────────────
function log_msg(string $msg, string $logFile): void {
    $line = "[{$msg}]\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

log_msg("{$now} Starting backup: {$dbName}", $logFile);

// ── ลองใช้ mysqldump (วิธีที่ดีที่สุด) ───────────────────────────────────────
$success = false;

if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
    $cmd = sprintf(
        'mysqldump --host=%s --port=%d --user=%s --password=%s --single-transaction --routines --triggers --add-drop-table %s 2>&1 | gzip > %s',
        escapeshellarg($dbHost),
        $dbPort,
        escapeshellarg($dbUser),
        escapeshellarg($dbPass),
        escapeshellarg($dbName),
        escapeshellarg($backupFile)
    );
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 0) {
        $size = round(filesize($backupFile) / 1024, 1);
        log_msg("{$now} SUCCESS via mysqldump: " . basename($backupFile) . " ({$size} KB)", $logFile);
        $success = true;
    } else {
        log_msg("{$now} mysqldump failed (code {$returnCode}), falling back to PHP export", $logFile);
    }
}

// ── Fallback: Pure PHP export ด้วย PDO ────────────────────────────────────────
if (!$success) {
    try {
        $pdo = db();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $sql  = "-- RSU Healthcare DB Backup\n";
        $sql .= "-- Generated: {$now}\n";
        $sql .= "-- Database: {$dbName}\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // DROP + CREATE
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $createStmt[1] . ";\n\n";

            // INSERT rows
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `{$table}` ({$cols}) VALUES\n";
                $vals = [];
                foreach ($rows as $row) {
                    $escaped = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                    $vals[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= implode(",\n", $vals) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        // บีบอัดและเขียนไฟล์
        $gz = gzopen($backupFile, 'wb9');
        gzwrite($gz, $sql);
        gzclose($gz);

        $size = round(filesize($backupFile) / 1024, 1);
        log_msg("{$now} SUCCESS via PHP export: " . basename($backupFile) . " ({$size} KB)", $logFile);
        $success = true;

    } catch (Throwable $e) {
        log_msg("{$now} ERROR: " . $e->getMessage(), $logFile);
        http_response_code(500);
        exit('Backup failed');
    }
}

// ── ลบ backup เก่ากว่า 14 วัน ────────────────────────────────────────────────
$deleted = 0;
foreach (glob("{$backupDir}/*.sql.gz") ?: [] as $f) {
    if (filemtime($f) < time() - (14 * 86400)) {
        unlink($f);
        $deleted++;
    }
}
if ($deleted > 0) {
    log_msg("{$now} Cleaned up {$deleted} old backup(s)", $logFile);
}

log_msg("{$now} Done.", $logFile);
http_response_code(200);
echo "OK";
