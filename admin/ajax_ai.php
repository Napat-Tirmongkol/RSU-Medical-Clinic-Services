<?php
// admin/ajax_ai.php — Gemini AI Assistant backend
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

validate_csrf_or_die();

$query = trim($_POST['query'] ?? '');
if (!$query) {
    echo json_encode(['ok' => false, 'error' => 'กรุณาพิมพ์คำถาม']);
    exit;
}
if (mb_strlen($query) > 2000) {
    echo json_encode(['ok' => false, 'error' => 'คำถามยาวเกินไป (สูงสุด 2000 ตัวอักษร)']);
    exit;
}

// ── Load API Key ──────────────────────────────────────────────────────────────
$secretsPath = __DIR__ . '/../config/secrets.php';
$secrets = file_exists($secretsPath) ? require $secretsPath : [];
$apiKey  = $secrets['GEMINI_API_KEY'] ?? '';

if (!$apiKey) {
    echo json_encode(['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า GEMINI_API_KEY ใน config/secrets.php']);
    exit;
}

// ── Fetch Campaign Data from DB ───────────────────────────────────────────────
$pdo = db();

try {
    // ภาพรวมระบบ
    $overall = $pdo->query("
        SELECT
            COUNT(DISTINCT c.id)                                                        AS total_campaigns,
            COUNT(DISTINCT CASE WHEN c.status='active' THEN c.id END)                  AS active_campaigns,
            COALESCE(SUM(c.total_capacity), 0)                                          AS total_capacity,
            COUNT(b.id)                                                                 AS total_bookings,
            COALESCE(SUM(b.status='confirmed'), 0)                                      AS confirmed,
            COALESCE(SUM(b.status='booked'), 0)                                         AS pending,
            COALESCE(SUM(b.status LIKE 'cancelled%'), 0)                                AS cancelled
        FROM camp_list c
        LEFT JOIN camp_bookings b ON b.campaign_id = c.id
    ")->fetch(PDO::FETCH_ASSOC);

    // Top 10 แคมเปญที่มีการจองมากที่สุด
    $top10 = $pdo->query("
        SELECT
            c.title,
            c.status,
            c.total_capacity,
            COUNT(b.id)                                                                 AS total_bookings,
            COALESCE(SUM(b.status='confirmed'), 0)                                      AS confirmed,
            COALESCE(SUM(b.status='booked'), 0)                                         AS pending,
            COALESCE(SUM(b.status LIKE 'cancelled%'), 0)                                AS cancelled,
            ROUND(
                COALESCE(SUM(b.status IN ('booked','confirmed')), 0)
                / NULLIF(c.total_capacity, 0) * 100, 1
            )                                                                           AS fill_pct
        FROM camp_list c
        LEFT JOIN camp_bookings b ON b.campaign_id = c.id
        GROUP BY c.id
        ORDER BY total_bookings DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // แนวโน้ม 7 วันล่าสุด
    $trend7 = $pdo->query("
        SELECT DATE(created_at) AS day, COUNT(*) AS cnt
        FROM camp_bookings
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("AI DB error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'ดึงข้อมูลจาก DB ไม่สำเร็จ']);
    exit;
}

// ── Build Data Context ────────────────────────────────────────────────────────
$lines = [];
$lines[] = "=== ภาพรวมระบบ RSU Healthcare Campaign ===";
$lines[] = "แคมเปญทั้งหมด     : {$overall['total_campaigns']} รายการ";
$lines[] = "แคมเปญที่เปิดอยู่  : {$overall['active_campaigns']} รายการ";
$lines[] = "โควต้ารวม          : {$overall['total_capacity']} ที่นั่ง";
$lines[] = "การจองทั้งหมด      : {$overall['total_bookings']} รายการ";
$lines[] = "  ยืนยันแล้ว      : {$overall['confirmed']}";
$lines[] = "  รอยืนยัน        : {$overall['pending']}";
$lines[] = "  ยกเลิก          : {$overall['cancelled']}";
$lines[] = "";
$lines[] = "=== 10 อันดับแคมเปญที่มีผู้จองมากที่สุด ===";

foreach ($top10 as $i => $c) {
    $rank  = $i + 1;
    $fill  = $c['fill_pct'] ?? 0;
    $lines[] = "อันดับ {$rank}: {$c['title']}";
    $lines[] = "  สถานะ: {$c['status']} | โควต้า: {$c['total_capacity']}";
    $lines[] = "  จองทั้งหมด: {$c['total_bookings']} | ยืนยัน: {$c['confirmed']} | รอยืนยัน: {$c['pending']} | ยกเลิก: {$c['cancelled']}";
    $lines[] = "  อัตราการเติมโควต้า: {$fill}%";
    $lines[] = "";
}

if (!empty($trend7)) {
    $lines[] = "=== แนวโน้มการจอง 7 วันล่าสุด ===";
    foreach ($trend7 as $t) {
        $lines[] = "  {$t['day']}: {$t['cnt']} การจอง";
    }
}

$dataContext = implode("\n", $lines);

// ── Build Prompt ──────────────────────────────────────────────────────────────
$systemPrompt = <<<PROMPT
คุณคือ AI Assistant ผู้เชี่ยวชาญวิเคราะห์ข้อมูลแคมเปญสุขภาพของ RSU Healthcare Services
ตอบเป็นภาษาไทยเสมอ กระชับ ชัดเจน มีประโยชน์
ใช้ Markdown ในการจัดรูปแบบ: **ตัวหนา**, รายการ (- bullet), ตาราง (|col|col|)
ห้ามประดิษฐ์ข้อมูลที่ไม่มีใน context ด้านล่าง

---
{$dataContext}
---

คำถามจากแอดมิน: {$query}
PROMPT;

// ── Call Gemini API ───────────────────────────────────────────────────────────
// gemini-1.5-flash มี free tier ที่กว้างกว่าและรองรับทุก region
$model = 'gemini-1.5-flash';
$url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

$body = json_encode([
    'contents'         => [
        ['role' => 'user', 'parts' => [['text' => $systemPrompt]]]
    ],
    'generationConfig' => [
        'temperature'     => 0.7,
        'maxOutputTokens' => 2048,
    ],
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$raw      = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log("Gemini cURL error: $curlErr");
    echo json_encode(['ok' => false, 'error' => 'ไม่สามารถเชื่อมต่อ Gemini API ได้: ' . $curlErr]);
    exit;
}

if ($httpCode !== 200) {
    error_log("Gemini API HTTP {$httpCode}: {$raw}");
    $errData = json_decode($raw, true);
    $errMsg  = $errData['error']['message'] ?? "HTTP {$httpCode}";
    // แปล quota error ให้เข้าใจง่าย
    if ($httpCode === 429 || stripos($errMsg, 'quota') !== false || stripos($errMsg, 'RESOURCE_EXHAUSTED') !== false) {
        $userMsg = 'API Key หมด quota หรือ free tier ไม่รองรับ — กรุณา:\n1. ตรวจสอบ quota ที่ https://ai.dev/rate-limit\n2. เปิด billing บน Google Cloud project\n3. หรือสร้าง API Key ใหม่จาก https://aistudio.google.com/apikey';
    } else {
        $userMsg = "Gemini ตอบกลับ: {$errMsg}";
    }
    echo json_encode(['ok' => false, 'error' => $userMsg]);
    exit;
}

$result = json_decode($raw, true);
$text   = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

if (!$text) {
    echo json_encode(['ok' => false, 'error' => 'Gemini ไม่ส่งคำตอบกลับมา (อาจถูก safety filter บล็อก)']);
    exit;
}

echo json_encode(['ok' => true, 'reply' => $text]);
