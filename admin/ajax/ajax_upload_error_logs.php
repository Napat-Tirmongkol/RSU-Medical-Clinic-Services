<?php
// admin/ajax/ajax_upload_error_logs.php — Manual error log upload
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/error_log_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

// Auth check
validate_csrf_or_die();
if (empty($_SESSION['admin_role'])) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}

// Check file upload
if (!isset($_FILES['error_log_file']) || $_FILES['error_log_file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['error_log_file']['error'] ?? -1;
    echo json_encode(['ok' => false, 'error' => "Upload failed (code: $errCode)"]);
    exit;
}

$file = $_FILES['error_log_file'];

// Validate file
$errors = validate_error_log_upload($file);
if (!empty($errors)) {
    echo json_encode(['ok' => false, 'error' => implode(', ', $errors)]);
    exit;
}

// Save file
$filename = save_uploaded_error_log($file);
if (!$filename) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save file']);
    exit;
}

// Log activity
if (function_exists('log_activity')) {
    log_activity('upload_error_logs', "Upload error log file: {$filename} ({$file['size']} bytes)");
}

// Parse and analyze
$filepath = ERROR_LOGS_DIR . '/' . $filename;
$logs = parse_error_log_file($filepath);
$analysis = analyze_error_logs($logs);

echo json_encode([
    'ok'       => true,
    'filename' => $filename,
    'size'     => $file['size'],
    'uploaded_at' => date('Y-m-d H:i:s'),
    'total_logs' => count($logs),
    'analysis' => $analysis,
    'message'  => "File uploaded successfully. Detected " . count($logs) . " errors."
]);
