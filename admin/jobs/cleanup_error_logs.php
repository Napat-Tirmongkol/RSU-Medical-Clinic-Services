<?php
// admin/jobs/cleanup_error_logs.php — Cleanup old error log files
// Run via cron: php /path/to/admin/jobs/cleanup_error_logs.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/error_log_helper.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting error logs cleanup (retention: " . RETENTION_DAYS . " days)...\n";

try {
    $deleted = cleanup_old_error_logs();
    echo "✓ Deleted $deleted file(s)\n";
    echo "[" . date('Y-m-d H:i:s') . "] Cleanup completed\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    error_log("Cleanup error logs job failed: " . $e->getMessage());
    exit(1);
}
