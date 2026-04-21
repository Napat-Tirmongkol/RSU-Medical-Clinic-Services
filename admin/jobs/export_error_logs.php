<?php
// admin/jobs/export_error_logs.php — Daily auto-export of error logs
// Run via cron: php /path/to/admin/jobs/export_error_logs.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/error_log_helper.php';

$start_time = microtime(true);

echo "[" . date('Y-m-d H:i:s') . "] Starting error logs auto-export...\n";

try {
    // Export logs
    $filename = export_error_logs_to_file();

    if ($filename) {
        echo "✓ Exported to: {$filename}\n";
    } else {
        echo "ℹ No new logs to export\n";
    }

    // Cleanup old files
    $deleted = cleanup_old_error_logs();
    echo "✓ Cleaned up $deleted old files (>30 days)\n";

    $elapsed = round(microtime(true) - $start_time, 2);
    echo "[" . date('Y-m-d H:i:s') . "] Completed in {$elapsed}s\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    error_log("Export error logs job failed: " . $e->getMessage());
    exit(1);
}
