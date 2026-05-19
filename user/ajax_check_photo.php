<?php
/**
 * user/ajax_check_photo.php
 * Pre-submission Gemini Vision check for gold card selfie photo.
 *
 * Verifies before user commits the form:
 *   - face_visible       (human face clearly shown)
 *   - id_card_visible    (Thai national ID card in same frame)
 *   - wearing_glasses    (any eyeglasses)
 *   - dark_glasses       (sunglasses / tinted — subset of wearing_glasses)
 *   - wearing_mask       (mouth/nose covering)
 *   - wearing_hat        (cap, hat, headband — not religious head covering)
 *
 * POST inputs:
 *   csrf_token, photo (multipart file)
 *
 * Output JSON:
 *   { ok: true, check: { ... }, blockers: [...], passed: bool }
 *   or { ok: false, message }
 *
 * Security controls:
 *   - CSRF + session-bound to line_user_id
 *   - 5 req/minute session throttle + 30 req/day DB throttle (Gemini cost guard)
 *   - getimagesize() + GD re-encode strips metadata before sending upstream
 *     (defeats EXIF prompt-injection / polyglot payloads)
 *   - API key sent as x-goog-api-key header, not URL query (proxy/log safe)
 *   - cURL + Gemini error detail is logged but not echoed to the client
 *   - PDPA Art. 24/26: every Gemini call is logged in sys_pdpa_ai_log with
 *     line_user_id + IP + UA so audits can reconstruct who sent what abroad
 */
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}
validate_csrf_or_die();

$lineUserId = $_SESSION['line_user_id'] ?? '';
if ($lineUserId === '') {
    json_err('Session หมดอายุ กรุณาเข้าสู่ระบบใหม่', 401);
}

$pdo = db();

// ── Self-healing: PDPA + AI vendor outbound-call log ────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_pdpa_ai_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        line_user_id VARCHAR(64) NOT NULL,
        action VARCHAR(64) NOT NULL,
        vendor VARCHAR(32) NOT NULL DEFAULT 'google_gemini',
        purpose VARCHAR(255) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        result_summary VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (line_user_id),
        KEY idx_action_time (action, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { error_log('[sys_pdpa_ai_log schema] ' . $e->getMessage()); }

// ── Rate limit: 5 req/minute per session + 30 req/day per LINE user ─────────
$now      = time();
$rlKey    = 'photo_check_rl_' . md5($lineUserId);
$bucket   = $_SESSION[$rlKey] ?? ['count' => 0, 'reset' => $now + 60];
if ($now > $bucket['reset']) $bucket = ['count' => 0, 'reset' => $now + 60];
if (++$bucket['count'] > 5) {
    $_SESSION[$rlKey] = $bucket;
    json_err('คุณตรวจสอบรูปบ่อยเกินไป กรุณาลองใหม่ใน 1 นาที', 429);
}
$_SESSION[$rlKey] = $bucket;

try {
    $dayStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_pdpa_ai_log
        WHERE line_user_id = ? AND action = 'gold_card_photo_check'
          AND created_at >= (NOW() - INTERVAL 1 DAY)");
    $dayStmt->execute([$lineUserId]);
    if ((int)$dayStmt->fetchColumn() >= 30) {
        json_err('ตรวจสอบรูปครบโควต้าวันนี้แล้ว (30 ครั้ง/วัน) — กรุณาลองใหม่พรุ่งนี้', 429);
    }
} catch (PDOException $e) { /* fall through if table not ready */ }

// ── Load API key (constant first, then secrets.php fallback) ────────────────
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
if (!$apiKey) {
    $secretsPath = __DIR__ . '/../config/secrets.php';
    if (is_file($secretsPath)) {
        $__s    = require $secretsPath;
        $apiKey = is_array($__s) ? ($__s['GEMINI_API_KEY'] ?? '') : '';
    }
}
if (!$apiKey) {
    // Soft-fail: advisory layer, not a hard gate. Admin still reviews the photo.
    echo json_encode([
        'ok'      => true,
        'skipped' => true,
        'message' => 'ระบบตรวจสอบรูปยังไม่ได้ตั้งค่า — กรุณาตรวจสอบรูปด้วยตนเอง',
    ]);
    exit;
}

// ── Validate uploaded photo ─────────────────────────────────────────────────
$file = $_FILES['photo'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_err('ไม่ได้รับไฟล์รูปภาพ');
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']);
$allowed  = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowed, true)) {
    json_err('รองรับเฉพาะ JPEG, PNG, WEBP');
}
if ($file['size'] > 10 * 1024 * 1024) {
    json_err('ไฟล์ใหญ่เกิน 10MB');
}

// Deep validation — confirm the file is a real raster image, not a polyglot
$info = @getimagesize($file['tmp_name']);
$validTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
if (!$info || !in_array($info[2], $validTypes, true)) {
    json_err('ไฟล์ไม่ใช่รูปภาพที่ถูกต้อง');
}

// Re-encode through GD to strip EXIF/metadata before sending to Google.
// Mitigates prompt-injection via image metadata and removes incidental PII
// (GPS, camera serial, etc.) that user didn't intend to share.
$gd = null;
switch ($info[2]) {
    case IMAGETYPE_JPEG: $gd = @imagecreatefromjpeg($file['tmp_name']); break;
    case IMAGETYPE_PNG:  $gd = @imagecreatefrompng($file['tmp_name']);  break;
    case IMAGETYPE_WEBP: $gd = @imagecreatefromwebp($file['tmp_name']); break;
}
if (!$gd) {
    json_err('อ่านไฟล์รูปภาพไม่ได้');
}

// Cap dimensions before re-encode to keep API payload small
$srcW = imagesx($gd); $srcH = imagesy($gd);
$maxDim = 1280;
$scale  = min(1.0, $maxDim / max($srcW, $srcH));
if ($scale < 1.0) {
    $dstW  = (int)round($srcW * $scale);
    $dstH  = (int)round($srcH * $scale);
    $dst   = imagecreatetruecolor($dstW, $dstH);
    imagecopyresampled($dst, $gd, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($gd);
    $gd = $dst;
}

ob_start();
imagejpeg($gd, null, 82);
$cleanJpeg = ob_get_clean();
imagedestroy($gd);
if (!$cleanJpeg) {
    json_err('เข้ารหัสรูปไม่ได้');
}
$imageData = base64_encode($cleanJpeg);
$uploadMime = 'image/jpeg';

// ── Build prompt ────────────────────────────────────────────────────────────
$prompt = <<<PROMPT
You are reviewing a selfie photo submitted for Thailand Gold Card (Universal Health Coverage / UC) registration.

The applicant should be holding their Thai national ID card next to their own face. Both the face and the ID card need to be clearly visible in a single photo for identity verification.

Analyse the image and return ONLY a JSON object with these exact keys:
- "face_visible": boolean — true if a human face is clearly shown (eyes, nose, mouth visible)
- "id_card_visible": boolean — true if a Thai national ID card (or any photo-ID card) is clearly visible in the same photo
- "wearing_glasses": boolean — true if the person is wearing any kind of eyeglasses (including clear/reading glasses)
- "dark_glasses": boolean — true ONLY if the glasses are sunglasses or heavily tinted and the eyes are obscured
- "wearing_mask": boolean — true if a face mask, surgical mask, or any cloth is covering the nose or mouth
- "wearing_hat": boolean — true if the person is wearing a hat, cap, beanie, or non-religious head covering (a hijab or religious headwear is NOT a hat — return false)
- "confidence": number between 0 and 1 — your overall confidence in the assessment
- "issues": array of short Thai strings — list each specific issue found (e.g. "ใส่แว่นกันแดด", "ใส่หน้ากากอนามัย", "ใส่หมวก", "มองไม่เห็นบัตรประชาชน", "ใบหน้าไม่ชัด"). Empty array if everything is fine.
- "summary": short Thai sentence summarising the result for the user.

Rules:
- If you are uncertain, lean towards false for the wearing_* flags.
- A face shield is NOT a mask unless it covers the mouth.
- Reading glasses with clear lenses → wearing_glasses=true, dark_glasses=false.
- Output ONLY the JSON object. No markdown, no explanation.
PROMPT;

$requestBody = json_encode([
    'contents' => [[
        'role'  => 'user',
        'parts' => [
            ['text'       => $prompt],
            ['inlineData' => ['mimeType' => $uploadMime, 'data' => $imageData]],
        ],
    ]],
    'generationConfig' => [
        'temperature'      => 0.1,
        'maxOutputTokens'  => 512,
        'responseMimeType' => 'application/json',
    ],
]);

function callGeminiVision(string $apiKey, string $model, string $body): array {
    // Key sent via header (not URL) — keeps it out of access logs / proxy caches
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return ['raw' => $raw ?: '', 'httpCode' => $httpCode, 'curlErr' => $curlErr];
}

// Audit log helper — write one row per outbound call, with a short summary
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
$logCall = function(string $summary) use ($pdo, $lineUserId, $ip, $ua): void {
    try {
        $pdo->prepare("INSERT INTO sys_pdpa_ai_log
            (line_user_id, action, vendor, purpose, ip_address, user_agent, result_summary)
            VALUES (?, 'gold_card_photo_check', 'google_gemini', 'verify gold card selfie quality', ?, ?, ?)")
            ->execute([$lineUserId, $ip, $ua, mb_substr($summary, 0, 255)]);
    } catch (PDOException $e) { error_log('[pdpa_ai_log insert] ' . $e->getMessage()); }
};

$models   = ['gemini-2.5-flash', 'gemini-2.0-flash'];
$raw      = '';
$httpCode = 0;
foreach ($models as $model) {
    $resp = callGeminiVision($apiKey, $model, $requestBody);
    if ($resp['curlErr']) {
        error_log('[ajax_check_photo] cURL error: ' . $resp['curlErr']);
        $logCall('curl_error');
        json_err('เชื่อมต่อ AI ไม่ได้ กรุณาลองใหม่', 502);
    }
    $raw      = $resp['raw'];
    $httpCode = $resp['httpCode'];
    if ($httpCode === 200) break;
    if ($httpCode !== 404) break;
}

if ($httpCode !== 200) {
    $detail = json_decode($raw, true)['error']['message'] ?? "HTTP {$httpCode}";
    error_log('[ajax_check_photo] Gemini error: ' . $detail);
    $publicMsg = 'AI ตอบกลับผิดพลาด กรุณาลองใหม่';
    if ($httpCode === 429 || stripos($detail, 'quota') !== false) {
        $publicMsg = 'ระบบ AI มีผู้ใช้จำนวนมาก กรุณาลองใหม่ภายหลัง';
    }
    $logCall('error_http_' . $httpCode);
    json_err($publicMsg);
}

$geminiResp = json_decode($raw, true);
$text       = $geminiResp['candidates'][0]['content']['parts'][0]['text'] ?? '';

$text = preg_replace('/^```(?:json)?\s*/m', '', (string)$text);
$text = preg_replace('/```\s*$/m', '', $text);
$text = trim($text);

$parsed = json_decode($text, true);
if (!is_array($parsed)) {
    if (preg_match('/\{[\s\S]*\}/u', $text, $m)) {
        $parsed = json_decode($m[0], true);
    }
}
if (!is_array($parsed)) {
    error_log('[ajax_check_photo] Unparseable response: ' . mb_substr($text, 0, 500));
    $logCall('parse_failed');
    json_err('AI ตอบกลับไม่อยู่ในรูปแบบที่อ่านได้ — กรุณาลองใหม่');
}

$b = fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
$check = [
    'face_visible'    => $b($parsed['face_visible']    ?? false),
    'id_card_visible' => $b($parsed['id_card_visible'] ?? false),
    'wearing_glasses' => $b($parsed['wearing_glasses'] ?? false),
    'dark_glasses'    => $b($parsed['dark_glasses']    ?? false),
    'wearing_mask'    => $b($parsed['wearing_mask']    ?? false),
    'wearing_hat'     => $b($parsed['wearing_hat']     ?? false),
    'confidence'      => max(0.0, min(1.0, (float)($parsed['confidence'] ?? 0))),
    'issues'          => array_values(array_filter(array_map(
        fn($s) => mb_substr(trim((string)$s), 0, 100),
        (array)($parsed['issues'] ?? [])
    ))),
    'summary'         => mb_substr(trim((string)($parsed['summary'] ?? '')), 0, 240),
];

// Server-side blocker count — strict issues that should block submission.
// Regular clear-lens glasses are informational only, not a blocker.
$blockers = [];
if (!$check['face_visible'])    $blockers[] = 'ใบหน้าไม่ชัด';
if (!$check['id_card_visible']) $blockers[] = 'มองไม่เห็นบัตรประชาชน';
if ($check['dark_glasses'])     $blockers[] = 'ใส่แว่นกันแดด';
if ($check['wearing_mask'])     $blockers[] = 'ใส่หน้ากากอนามัย';
if ($check['wearing_hat'])      $blockers[] = 'ใส่หมวก';

$logCall(empty($blockers) ? 'passed' : ('blocked:' . implode(',', $blockers)));

echo json_encode([
    'ok'       => true,
    'check'    => $check,
    'blockers' => $blockers,
    'passed'   => empty($blockers),
], JSON_UNESCAPED_UNICODE);
