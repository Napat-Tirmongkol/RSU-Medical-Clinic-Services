<?php
// admin/includes/error_log_helper.php — Error log handling utilities
declare(strict_types=1);

const ERROR_LOGS_DIR = __DIR__ . '/../../logs/ErrorLogs';
const RETENTION_DAYS = 30;
const MAX_UPLOAD_SIZE = 10 * 1024 * 1024; // 10MB

/**
 * Get all error log files in directory
 */
function get_error_log_files(): array {
    if (!is_dir(ERROR_LOGS_DIR)) return [];

    $files = [];
    foreach (scandir(ERROR_LOGS_DIR) as $file) {
        if ($file === '.' || $file === '..') continue;

        $path = ERROR_LOGS_DIR . '/' . $file;
        if (is_file($path)) {
            $files[] = [
                'name'       => $file,
                'path'       => $path,
                'size'       => filesize($path),
                'created_at' => filectime($path),
                'modified'   => filemtime($path)
            ];
        }
    }

    // Sort by date descending
    usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
    return $files;
}

/**
 * Read error log file and parse JSON/CSV
 */
function parse_error_log_file(string $filePath): array {
    if (!file_exists($filePath)) {
        return [];
    }

    $content = file_get_contents($filePath);
    if (!$content) return [];

    // Try JSON first
    if (str_ends_with($filePath, '.json')) {
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    // Handle CSV
    if (str_ends_with($filePath, '.csv')) {
        $lines = explode("\n", $content);
        $headers = str_getcsv(array_shift($lines));
        $data = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $data[] = array_combine($headers, str_getcsv($line));
        }
        return $data;
    }

    // Plain text — split by lines or errors
    $lines = explode("\n", $content);
    return array_filter(array_map('trim', $lines));
}

/**
 * Export error logs from sys_email_logs to JSON
 */
function export_error_logs_to_file(): ?string {
    try {
        $pdo = db();

        // Get logs from last 90 days
        $stmt = $pdo->prepare("
            SELECT id, recipient, subject, type, status, error_msg, sent_at
            FROM sys_email_logs
            WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ORDER BY sent_at DESC
        ");
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($logs)) {
            return null;
        }

        // Create directory if not exists
        if (!is_dir(ERROR_LOGS_DIR)) {
            mkdir(ERROR_LOGS_DIR, 0755, true);
        }

        // Generate filename with timestamp
        $date = date('Y-m-d-His');
        $filename = "error_logs_auto_{$date}.json";
        $filepath = ERROR_LOGS_DIR . '/' . $filename;

        // Write JSON file
        $json = json_encode([
            'exported_at' => date('Y-m-d H:i:s'),
            'total_logs'  => count($logs),
            'logs'        => $logs
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (file_put_contents($filepath, $json) === false) {
            error_log("Failed to export error logs to {$filepath}");
            return null;
        }

        return $filename;

    } catch (Exception $e) {
        error_log("Error log export failed: " . $e->getMessage());
        return null;
    }
}

/**
 * Delete error log files older than RETENTION_DAYS
 */
function cleanup_old_error_logs(): int {
    if (!is_dir(ERROR_LOGS_DIR)) {
        return 0;
    }

    $cutoff_time = time() - (RETENTION_DAYS * 24 * 60 * 60);
    $deleted = 0;

    foreach (scandir(ERROR_LOGS_DIR) as $file) {
        if ($file === '.' || $file === '..') continue;

        $path = ERROR_LOGS_DIR . '/' . $file;
        if (is_file($path) && filemtime($path) < $cutoff_time) {
            if (unlink($path)) {
                $deleted++;
                error_log("Deleted old error log: {$file}");
            }
        }
    }

    return $deleted;
}

/**
 * Validate uploaded file
 */
function validate_error_log_upload(array $file): array {
    $errors = [];

    // Check size
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        $errors[] = 'File size exceeds 10MB limit';
    }

    // Check extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['json', 'csv', 'txt', 'log'])) {
        $errors[] = 'Only JSON, CSV, TXT, LOG files allowed';
    }

    // Check MIME type (basic)
    $allowed_types = [
        'application/json',
        'text/csv',
        'text/plain',
        'application/octet-stream'
    ];
    if (!in_array($file['type'], $allowed_types)) {
        $errors[] = 'Invalid file type';
    }

    return $errors;
}

/**
 * Save uploaded file with timestamp
 */
function save_uploaded_error_log(array $file): ?string {
    // Validate
    $errors = validate_error_log_upload($file);
    if (!empty($errors)) {
        return null;
    }

    // Create directory
    if (!is_dir(ERROR_LOGS_DIR)) {
        mkdir(ERROR_LOGS_DIR, 0755, true);
    }

    // Generate safe filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $timestamp = date('Y-m-d-His');
    $safe_name = "error_logs_upload_{$timestamp}.{$ext}";
    $filepath = ERROR_LOGS_DIR . '/' . $safe_name;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        error_log("Error log uploaded: {$safe_name}");
        return $safe_name;
    }

    error_log("Failed to save uploaded error log: {$file['name']}");
    return null;
}

/**
 * Analyze error logs for root causes
 */
function analyze_error_logs(array $logs): array {
    if (empty($logs)) {
        return ['summary' => 'No logs to analyze'];
    }

    $analysis = [
        'total_errors'    => count($logs),
        'error_types'     => [],
        'top_messages'    => [],
        'timeline'        => [],
        'failed_recipients' => []
    ];

    // Group by error type/status
    foreach ($logs as $log) {
        $type = $log['type'] ?? 'unknown';
        $analysis['error_types'][$type] = ($analysis['error_types'][$type] ?? 0) + 1;

        // Track failed emails
        if (($log['status'] ?? '') === 'failed') {
            $analysis['failed_recipients'][] = $log['recipient'] ?? 'unknown';
            $msg = $log['error_msg'] ?? 'Unknown error';
            $analysis['top_messages'][$msg] = ($analysis['top_messages'][$msg] ?? 0) + 1;
        }

        // Timeline
        $date = substr($log['sent_at'] ?? '', 0, 10);
        $analysis['timeline'][$date] = ($analysis['timeline'][$date] ?? 0) + 1;
    }

    // Sort
    arsort($analysis['error_types']);
    arsort($analysis['top_messages']);
    ksort($analysis['timeline']);

    return $analysis;
}
