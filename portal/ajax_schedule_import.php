<?php
// portal/ajax_schedule_import.php — Gemini Vision import for doctor schedule
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}

validate_csrf_or_die();

// Load API key: constant from site settings first, then secrets directly
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (!$apiKey) {
    $__s = require __DIR__ . '/../config/secrets.php';
    $apiKey = $__s['GEMINI_API_KEY'] ?? '';
}
if (!$apiKey) {
    echo json_encode(['ok' => false, 'message' => 'ยังไม่ได้ตั้งค่า GEMINI_API_KEY กรุณาตั้งค่าในหน้า Settings']);
    exit;
}

$pdo = db();

// ── Image upload validation ──────────────────────────────────────────────────
$file = $_FILES['image'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'ไฟล์ใหญ่เกิน upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'ไฟล์ใหญ่เกิน MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'อัปโหลดไม่สมบูรณ์',
        UPLOAD_ERR_NO_FILE    => 'ไม่ได้รับไฟล์',
        UPLOAD_ERR_NO_TMP_DIR => 'ไม่มี temp directory',
        UPLOAD_ERR_CANT_WRITE => 'เขียนไฟล์ไม่ได้',
    ];
    $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['ok' => false, 'message' => $uploadErrors[$errCode] ?? 'อัปโหลดล้มเหลว']);
    exit;
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mimeType, $allowed, true)) {
    echo json_encode(['ok' => false, 'message' => 'รองรับเฉพาะ JPEG, PNG, WEBP, GIF']);
    exit;
}
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'message' => 'ไฟล์ใหญ่เกิน 10MB']);
    exit;
}

$imageData = base64_encode((string)file_get_contents($file['tmp_name']));
if (!$imageData) {
    echo json_encode(['ok' => false, 'message' => 'อ่านไฟล์รูปภาพไม่ได้']);
    exit;
}

// ── Load staff list for name hint in prompt ──────────────────────────────────
$staffList = [];
try {
    $staffList = $pdo->query(
        "SELECT id, title, full_name, role FROM sys_medical_staff WHERE is_active = 1 ORDER BY full_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {}

$staffNamesStr = implode(', ', array_map(
    fn($s) => trim($s['title'] . ' ' . $s['full_name']),
    $staffList
));

// ── Build Gemini Vision prompt ───────────────────────────────────────────────
$prompt = <<<PROMPT
วิเคราะห์ตารางเวรแพทย์จากภาพนี้ แล้วส่งกลับมาเป็น JSON array เท่านั้น ห้ามมีข้อความอื่น ห้ามมี markdown code fence

รายชื่อแพทย์ที่มีในระบบ (ใช้จับคู่): {$staffNamesStr}

รูปแบบ JSON แต่ละ shift:
{
  "doctor_name": "ชื่อแพทย์ตามที่เห็นในรูป",
  "date": "YYYY-MM-DD หรือ null ถ้าเป็นตารางรายสัปดาห์ (จันทร์–อาทิตย์)",
  "weekday": ตัวเลข 0-6 (0=อาทิตย์,1=จันทร์,2=อังคาร,3=พุธ,4=พฤหัสบดี,5=ศุกร์,6=เสาร์) หรือ null ถ้าระบุวันที่ชัดเจน,
  "start_time": "HH:MM (24h เช่น 08:00)",
  "end_time": "HH:MM (24h เช่น 12:00)",
  "service_type": "ประเภทงาน เช่น ตรวจทั่วไป หรือ ว่างเปล่าถ้าไม่ระบุ"
}

กฎ:
- ตารางรายสัปดาห์ → date=null, weekday=ตัวเลข
- ตารางระบุวันที่ → weekday=null, date=YYYY-MM-DD
- ถ้าไม่มีเวลาสิ้นสุด ให้ประมาณ end_time = start_time + 3 ชั่วโมง
- ส่งกลับ JSON array เท่านั้น ตัวอย่าง: [{"doctor_name":"นพ.สมชาย","date":null,"weekday":1,"start_time":"09:00","end_time":"12:00","service_type":""}]
PROMPT;

$requestBody = json_encode([
    'contents' => [[
        'role'  => 'user',
        'parts' => [
            ['text'       => $prompt],
            ['inlineData' => ['mimeType' => $mimeType, 'data' => $imageData]],
        ],
    ]],
    'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 4096],
]);

// ── Call Gemini Vision API ───────────────────────────────────────────────────
// Try gemini-2.0-flash first (multimodal, fast); fallback to gemini-1.5-flash
function callGeminiVision(string $apiKey, string $model, string $body): array {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return ['raw' => $raw ?: '', 'httpCode' => $httpCode, 'curlErr' => $curlErr];
}

$models   = ['gemini-2.5-flash', 'gemini-2.0-flash'];
$raw      = '';
$httpCode = 0;

foreach ($models as $model) {
    $resp = callGeminiVision($apiKey, $model, $requestBody);
    if ($resp['curlErr']) {
        echo json_encode(['ok' => false, 'message' => 'ไม่สามารถเชื่อมต่อ Gemini API: ' . $resp['curlErr']]);
        exit;
    }
    $raw      = $resp['raw'];
    $httpCode = $resp['httpCode'];
    if ($httpCode === 200) break;
    // 404 = model not available, try next; other errors = fatal
    if ($httpCode !== 404) break;
}

if ($httpCode !== 200) {
    $errMsg = json_decode($raw, true)['error']['message'] ?? "HTTP {$httpCode}";
    if ($httpCode === 429 || stripos($errMsg, 'quota') !== false) {
        $errMsg = 'API quota หมด กรุณาลองใหม่ภายหลัง';
    }
    echo json_encode(['ok' => false, 'message' => 'Gemini ตอบกลับ: ' . $errMsg]);
    exit;
}

// ── Parse JSON from Gemini response ─────────────────────────────────────────
$geminiResp = json_decode($raw, true);
$text       = $geminiResp['candidates'][0]['content']['parts'][0]['text'] ?? '';

// Strip markdown code fences if present
$text = preg_replace('/^```(?:json)?\s*/m', '', $text);
$text = preg_replace('/```\s*$/m', '', $text);
$text = trim($text);

if (!preg_match('/\[[\s\S]*\]/u', $text, $match)) {
    echo json_encode(['ok' => false, 'message' => 'AI ไม่สามารถอ่านตารางจากรูปภาพได้ (ไม่พบ JSON array)']);
    exit;
}

$shifts = json_decode($match[0], true);
if (!is_array($shifts) || empty($shifts)) {
    echo json_encode(['ok' => false, 'message' => 'ไม่พบข้อมูล shift ในรูปภาพ']);
    exit;
}

// ── Match doctor names to staff records ──────────────────────────────────────
function matchStaffByName(string $name, array $staffList): ?array {
    $needle = mb_strtolower(trim($name));
    // Exact match on full name (with/without title)
    foreach ($staffList as $s) {
        $full = mb_strtolower(trim($s['title'] . ' ' . $s['full_name']));
        $bare = mb_strtolower(trim($s['full_name']));
        if ($full === $needle || $bare === $needle) return $s;
    }
    // Partial/substring match
    foreach ($staffList as $s) {
        $full = mb_strtolower(trim($s['title'] . ' ' . $s['full_name']));
        if (mb_strpos($full, $needle) !== false || mb_strpos($needle, $full) !== false) return $s;
    }
    // Match any space-separated token ≥ 3 chars
    foreach (explode(' ', $needle) as $token) {
        if (mb_strlen($token) < 3) continue;
        foreach ($staffList as $s) {
            $full = mb_strtolower(trim($s['title'] . ' ' . $s['full_name']));
            if (mb_strpos($full, $token) !== false) return $s;
        }
    }
    return null;
}

$result = [];
foreach ($shifts as $shift) {
    if (empty($shift['doctor_name'])) continue;

    $matched = matchStaffByName((string)$shift['doctor_name'], $staffList);

    $weekday = isset($shift['weekday']) && $shift['weekday'] !== null && $shift['weekday'] !== ''
        ? (int)$shift['weekday'] : null;
    $date    = (!empty($shift['date']) && $shift['date'] !== 'null') ? (string)$shift['date'] : null;

    // Normalise times
    $start = preg_replace('/[^0-9:]/', '', (string)($shift['start_time'] ?? '09:00'));
    $end   = preg_replace('/[^0-9:]/', '', (string)($shift['end_time']   ?? '12:00'));
    if (!preg_match('/^\d{2}:\d{2}$/', $start)) $start = '09:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $end))   $end   = '12:00';

    $result[] = [
        'doctor_name'  => (string)$shift['doctor_name'],
        'date'         => $date,
        'weekday'      => $weekday,
        'start_time'   => $start,
        'end_time'     => $end,
        'service_type' => (string)($shift['service_type'] ?? ''),
        'staff_id'     => $matched ? (int)$matched['id'] : null,
        'staff_name'   => $matched ? trim((string)$matched['title'] . ' ' . (string)$matched['full_name']) : null,
        'match_status' => $matched ? 'matched' : 'unmatched',
    ];
}

echo json_encode([
    'ok'     => true,
    'shifts' => $result,
    'staff'  => array_values(array_map(fn($s) => [
        'id'   => (int)$s['id'],
        'name' => trim((string)$s['title'] . ' ' . (string)$s['full_name']),
    ], $staffList)),
]);
