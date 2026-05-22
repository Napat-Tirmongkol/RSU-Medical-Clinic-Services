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

// ── เพิ่ม time limit และ memory เผื่อ DB ใหญ่ ─────────────────────────────────
set_time_limit(0);
ini_set('memory_limit', '512M');

// ── Secret Token (เปลี่ยนเป็นรหัสของคุณ) ─────────────────────────────────────
define('BACKUP_SECRET_TOKEN', 'rsu_purge_a8f3k2m9x');

// ── ตรวจสอบ token ─────────────────────────────────────────────────────────────
$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(BACKUP_SECRET_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

// ── โหลด DB credentials จาก secrets.php ──────────────────────────────────────
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config/db_connect.php';

$secretsPath = $projectRoot . '/config/secrets.php';
$secrets = file_exists($secretsPath) ? (require $secretsPath) : [];

$dbHost = $secrets['DB_HOST'] ?? '127.0.0.1';
$dbPort = (int)($secrets['DB_PORT'] ?? 3306);
$dbUser = $secrets['DB_USER'] ?? '';
$dbPass = $secrets['DB_PASS'] ?? '';
$dbName = $secrets['DB_NAME'] ?? '';

$now = date('Y-m-d H:i:s');

// หา directory ที่เขียนได้: ลอง cron/backups/ ก่อน → fallback sys_get_temp_dir()
function find_writable_dir(string $preferred): string {
    if (!is_dir($preferred)) @mkdir($preferred, 0750, true);
    if (is_writable($preferred)) return $preferred;
    // fallback: ใช้ temp dir
    $tmp = sys_get_temp_dir() . '/rsu_backup';
    if (!is_dir($tmp)) @mkdir($tmp, 0750, true);
    return $tmp;
}

$backupDir  = find_writable_dir(__DIR__ . '/backups');
$logDir     = find_writable_dir(__DIR__ . '/logs');
$timestamp  = date('Ymd_His');
$backupFile = "{$backupDir}/{$dbName}_{$timestamp}.sql.gz";
$logFile    = "{$logDir}/backup.log";

echo "INFO: backupDir = {$backupDir}\n";
echo "INFO: logDir    = {$logDir}\n";

// ── ฟังก์ชัน log ──────────────────────────────────────────────────────────────
function log_msg(string $msg, string $logFile): void {
    $line = "[{$msg}]\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

log_msg("{$now} Starting backup: {$dbName}", $logFile);

// ── ลองใช้ mysqldump (วิธีที่ดีที่สุด) ───────────────────────────────────────
$success = false;

$disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions')));
$execAvailable = function_exists('exec') && !in_array('exec', $disabledFunctions);

if ($execAvailable && $dbUser !== '' && $dbName !== '') {
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
        $errDetail = implode(' | ', array_filter($output));
        log_msg("{$now} mysqldump failed (code {$returnCode}): {$errDetail} — falling back to PHP export", $logFile);
    }
} else {
    log_msg("{$now} exec() ไม่พร้อมใช้งาน — ใช้ PHP export แทน", $logFile);
}

// ── Fallback: Pure PHP export ด้วย PDO (streaming — ไม่สร้าง string ใหญ่ใน memory) ──
if (!$success) {
    try {
        $pdo = db();
        // ใช้ unbuffered query เพื่อลด memory ในการดึงข้อมูล
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $gz = gzopen($backupFile, 'wb6');   // compression 6 = balance speed/size
        if ($gz === false) {
            throw new RuntimeException("gzopen ล้มเหลว: ไม่สามารถเขียนไฟล์ {$backupFile}");
        }

        gzwrite($gz, "-- RSU Medical Clinic DB Backup\n");
        gzwrite($gz, "-- Generated: {$now}\n");
        gzwrite($gz, "-- Database: {$dbName}\n\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            // DROP + CREATE
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
            gzwrite($gz, $createStmt[1] . ";\n\n");

            // Stream rows ทีละ chunk (500 rows) — ไม่โหลดทั้ง table ขึ้น memory
            $rowStmt = $pdo->query("SELECT * FROM `{$table}`");
            $cols = null;
            $chunkVals = [];
            $chunkSize = 500;
            $rowCount  = 0;

            while ($row = $rowStmt->fetch(PDO::FETCH_ASSOC)) {
                if ($cols === null) {
                    $cols = '`' . implode('`, `', array_keys($row)) . '`';
                }
                $escaped     = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                $chunkVals[] = '(' . implode(', ', $escaped) . ')';
                $rowCount++;

                if (count($chunkVals) >= $chunkSize) {
                    gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n");
                    gzwrite($gz, implode(",\n", $chunkVals) . ";\n");
                    $chunkVals = [];
                }
            }
            // flush rows ที่เหลือ
            if ($chunkVals) {
                gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n");
                gzwrite($gz, implode(",\n", $chunkVals) . ";\n");
            }
            if ($rowCount > 0) gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); // restore

        $size = round(filesize($backupFile) / 1024, 1);
        log_msg("{$now} SUCCESS via PHP export: " . basename($backupFile) . " ({$size} KB)", $logFile);
        $success = true;

    } catch (Throwable $e) {
        $errMsg = $e->getMessage();
        log_msg("{$now} ERROR: {$errMsg}", $logFile);
        http_response_code(500);
        exit("Backup failed: {$errMsg}");
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
