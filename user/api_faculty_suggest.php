<?php
// user/api_faculty_suggest.php — Faculty/department autocomplete & Gemini normalization
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['student_id']) && empty($_SESSION['line_user_id'])) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$pdo = db();

// ── GET: return all names for datalist autocomplete ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $rows = $pdo->query(
            "SELECT name_th, name_en, type FROM sys_faculties ORDER BY type, name_th"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'ok', 'data' => $rows]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'data' => []]);
    }
    exit;
}

// ── POST: normalize user input via Gemini ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = trim($_POST['input'] ?? '');
if ($input === '') {
    echo json_encode(['status' => 'ok', 'matched' => null]);
    exit;
}

// Load Gemini API key
$secretsPath = __DIR__ . '/../config/secrets.php';
$secrets     = file_exists($secretsPath) ? require $secretsPath : [];
$apiKey      = $secrets['GEMINI_API_KEY'] ?? '';

// Fetch faculty list from DB
try {
    $rows = $pdo->query(
        "SELECT name_th, name_en, type FROM sys_faculties ORDER BY type, name_th"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {
    echo json_encode(['status' => 'ok', 'matched' => null]);
    exit;
}

// If no API key or empty list, return unmatched
if (!$apiKey || empty($rows)) {
    echo json_encode(['status' => 'ok', 'matched' => null]);
    exit;
}

// Build list for prompt
$listLines = [];
foreach ($rows as $r) {
    $line = "- {$r['name_th']}";
    if (!empty($r['name_en'])) $line .= " ({$r['name_en']})";
    $listLines[] = $line;
}
$facultyList = implode("\n", $listLines);

$prompt = <<<PROMPT
คุณมีรายชื่อคณะและหน่วยงานของมหาวิทยาลัยดังนี้:
{$facultyList}

ผู้ใช้พิมพ์: "{$input}"

ถ้าสิ่งที่ผู้ใช้พิมพ์ตรงกับหรือคล้ายกับรายการข้างต้น (แม้จะสะกดผิดเล็กน้อย ใช้คำย่อ หรือใช้ชื่อภาษาอังกฤษ) ให้ตอบ JSON ดังนี้:
{"matched":"ชื่อที่ถูกต้องจากรายการ (name_th เท่านั้น)"}

ถ้าไม่แน่ใจ หรือไม่ตรงกับรายการใดเลย ให้ตอบ:
{"matched":null}

ตอบเป็น JSON เท่านั้น ห้ามมีข้อความอื่น
PROMPT;

$model = (isset($_SESSION['_gemini_model']) && is_string($_SESSION['_gemini_model']) && $_SESSION['_gemini_model'] !== 'gemini-2.0-flash')
    ? $_SESSION['_gemini_model']
    : 'gemini-2.5-flash';
$url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
$body  = json_encode([
    'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 120],
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$raw) {
    echo json_encode(['status' => 'ok', 'matched' => null]);
    exit;
}

$resp = json_decode($raw, true);
$text = trim($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');

// Strip markdown code fences if Gemini wraps response
$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
$text = preg_replace('/\s*```$/i', '', trim($text));

$result  = json_decode($text, true);
$matched = $result['matched'] ?? null;

// Validate matched value exists in DB list to prevent hallucination
if ($matched !== null) {
    $validNames = array_column($rows, 'name_th');
    if (!in_array($matched, $validNames, true)) {
        $matched = null;
    }
}

echo json_encode(['status' => 'ok', 'matched' => $matched]);
