<?php
// admin/ajax/ajax_analyze_error_logs.php — Analyze error logs with Claude
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/error_log_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

validate_csrf_or_die();
if (empty($_SESSION['admin_role'])) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}

$filename = trim($_POST['filename'] ?? '');
if ($filename === '') {
    echo json_encode(['ok' => false, 'error' => 'Filename required']);
    exit;
}

// Sanitize filename (no path traversal)
$filename = basename($filename);
$filepath = ERROR_LOGS_DIR . '/' . $filename;

if (!file_exists($filepath)) {
    echo json_encode(['ok' => false, 'error' => 'File not found']);
    exit;
}

// Parse error logs
$logs = parse_error_log_file($filepath);
if (empty($logs)) {
    echo json_encode(['ok' => false, 'error' => 'No logs found in file']);
    exit;
}

// Basic analysis
$analysis = analyze_error_logs($logs);

// Prepare Claude prompt
$prompt = <<<PROMPT
Analyze these error logs and identify root causes. Be concise and actionable.

Error Summary:
- Total Errors: {$analysis['total_errors']}
- Error Types: {json_encode($analysis['error_types'], JSON_UNESCAPED_UNICODE)}
- Failed Recipients: {count($analysis['failed_recipients'])} unique recipients
- Timeline (daily): {json_encode($analysis['timeline'], JSON_UNESCAPED_UNICODE)}

Top Error Messages:
PROMPT;

foreach (array_slice($analysis['top_messages'], 0, 5) as $msg => $count) {
    $prompt .= "\n- ($count) " . substr($msg, 0, 100);
}

$prompt .= <<<PROMPT

Provide analysis in this format:
1. Root Causes (3-5 main causes)
2. Severity Assessment (low/medium/high)
3. Recommended Actions (3-4 specific actions)
4. Prevention Tips

Be Thai-friendly but precise.
PROMPT;

// Call Claude via Gemini API (if configured)
$secrets = file_exists(__DIR__ . '/../../config/secrets.php')
    ? require __DIR__ . '/../../config/secrets.php'
    : [];
$apiKey = $secrets['GEMINI_API_KEY'] ?? '';

if (!$apiKey) {
    echo json_encode([
        'ok' => true,
        'analysis' => $analysis,
        'claude_analysis' => null,
        'message' => 'Basic analysis available (Gemini API not configured for Claude analysis)'
    ]);
    exit;
}

// Call Gemini API
$model = $_SESSION['_gemini_model'] ?? 'gemini-2.0-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

$body = json_encode([
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 1024],
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$claudeAnalysis = null;
if ($httpCode === 200 && $raw) {
    $resp = json_decode($raw, true);
    $claudeAnalysis = $resp['candidates'][0]['content']['parts'][0]['text'] ?? null;
}

echo json_encode([
    'ok' => true,
    'analysis' => $analysis,
    'claude_analysis' => $claudeAnalysis,
    'message' => 'Analysis complete'
]);
