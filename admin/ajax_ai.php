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

// ── Quick Suggestions Mode ────────────────────────────────────────────────────
if (($_POST['mode'] ?? '') === 'suggestions') {
    $force = !empty($_POST['force']);
    // Return cached suggestions unless forced regeneration
    if (!$force && !empty($_SESSION['_ai_suggestions'])) {
        echo json_encode(['ok' => true, 'suggestions' => $_SESSION['_ai_suggestions']]);
        exit;
    }

    // Fetch minimal context for prompt generation
    $pdoS = db();
    try {
        $ovS  = $pdoS->query("SELECT COUNT(*) AS t, COALESCE(SUM(status='active'),0) AS a FROM camp_list")->fetch(PDO::FETCH_ASSOC);
        $top3 = $pdoS->query("
            SELECT c.title FROM camp_list c
            LEFT JOIN camp_bookings b ON b.campaign_id = c.id
            GROUP BY c.id ORDER BY COUNT(b.id) DESC LIMIT 3
        ")->fetchAll(PDO::FETCH_COLUMN);
        $sugCtx = "แคมเปญทั้งหมด {$ovS['t']} รายการ (เปิดอยู่ {$ovS['a']})\n"
                . "Top 3 ที่มีผู้จองมากสุด: " . implode(', ', $top3);
    } catch (PDOException $e) {
        $sugCtx = 'ไม่สามารถดึงข้อมูลได้';
    }

    $sugPrompt = "คุณเป็น AI วิเคราะห์ข้อมูลแคมเปญสุขภาพ RSU Healthcare\n"
        . "ข้อมูลปัจจุบัน: {$sugCtx}\n\n"
        . "สร้างคำถามที่น่าสนใจ 5 ข้อ ที่แอดมินควรถามคุณ ให้หลากหลายประเด็น "
        . "(เช่น วิเคราะห์ยอดนิยม, แนวโน้ม, โควต้า, การยกเลิก, ข้อเสนอแนะ)\n"
        . "ตอบเป็น JSON array ภาษาไทยเท่านั้น ห้ามมีข้อความอื่น:\n"
        . "[\"คำถาม 1\",\"คำถาม 2\",\"คำถาม 3\",\"คำถาม 4\",\"คำถาม 5\"]";

    $sugModel = $_SESSION['_gemini_model'] ?? 'gemini-2.0-flash';
    $sugUrl   = "https://generativelanguage.googleapis.com/v1beta/models/{$sugModel}:generateContent?key={$apiKey}";
    $sugBody  = json_encode([
        'contents'         => [['role' => 'user', 'parts' => [['text' => $sugPrompt]]]],
        'generationConfig' => ['temperature' => 0.9, 'maxOutputTokens' => 400],
    ]);

    $ch = curl_init($sugUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $sugBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $sugRaw  = curl_exec($ch);
    $sugCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($sugCode === 200) {
        $sugResult = json_decode($sugRaw, true);
        $sugText   = $sugResult['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (preg_match('/\[[\s\S]*?\]/', $sugText, $m)) {
            $sugg = json_decode($m[0], true);
            if (is_array($sugg) && count($sugg) >= 3) {
                $sugg = array_slice(array_values(array_filter($sugg, 'is_string')), 0, 5);
                $_SESSION['_ai_suggestions'] = $sugg;
                echo json_encode(['ok' => true, 'suggestions' => $sugg]);
                exit;
            }
        }
    }

    echo json_encode(['ok' => false, 'error' => 'ไม่สามารถสร้างคำถามได้']);
    exit;
}

// ── Rate Limiting (server-side) ───────────────────────────────────────────────
const AI_RATE_LIMIT  = 10;  // สูงสุด 10 ครั้ง/นาที (Gemini free tier = 15 RPM)
const AI_RATE_WINDOW = 60;  // หน้าต่างเวลา (วินาที)
const AI_COOLDOWN    = 4;   // หน่วงเวลาขั้นต่ำระหว่างแต่ละ request (วินาที)

$now = time();

// ล้าง timestamps ที่เกิน window ออก
$_SESSION['_ai_ts'] = array_values(array_filter(
    $_SESSION['_ai_ts'] ?? [],
    fn($t) => $now - $t < AI_RATE_WINDOW
));

// ตรวจ cooldown ระหว่าง request
if (!empty($_SESSION['_ai_ts'])) {
    $lastTs  = end($_SESSION['_ai_ts']);
    $elapsed = $now - $lastTs;
    if ($elapsed < AI_COOLDOWN) {
        $wait = AI_COOLDOWN - $elapsed;
        http_response_code(429);
        echo json_encode([
            'ok'       => false,
            'cooldown' => $wait,
            'error'    => "กรุณารอ {$wait} วินาที ก่อนส่งคำถามถัดไป",
        ]);
        exit;
    }
}

// ตรวจ rate limit รายนาที
$used      = count($_SESSION['_ai_ts']);
$remaining = AI_RATE_LIMIT - $used - 1; // -1 สำหรับ request นี้
if ($used >= AI_RATE_LIMIT) {
    $oldest  = reset($_SESSION['_ai_ts']);
    $resetIn = AI_RATE_WINDOW - ($now - $oldest);
    http_response_code(429);
    echo json_encode([
        'ok'          => false,
        'rate_limited' => true,
        'reset_in'    => max(1, $resetIn),
        'error'       => "ถึงขีดจำกัด " . AI_RATE_LIMIT . " ครั้ง/นาที — รีเซ็ตใน {$resetIn} วินาที",
    ]);
    exit;
}

// บันทึก timestamp ของ request นี้
$_SESSION['_ai_ts'][] = $now;

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

// ── Build Structured JSON Data ────────────────────────────────────────────────
$dbData = [
    'ภาพรวมระบบ' => [
        'แคมเปญทั้งหมด'    => (int)$overall['total_campaigns'],
        'แคมเปญที่เปิดอยู่' => (int)$overall['active_campaigns'],
        'โควต้ารวม'         => (int)$overall['total_capacity'],
        'การจองทั้งหมด'     => (int)$overall['total_bookings'],
        'ยืนยันแล้ว'        => (int)$overall['confirmed'],
        'รอยืนยัน'          => (int)$overall['pending'],
        'ยกเลิก'            => (int)$overall['cancelled'],
    ],
    'top10_แคมเปญจองมากสุด' => array_map(fn($c) => [
        'ชื่อแคมเปญ'          => $c['title'],
        'สถานะ'               => $c['status'],
        'โควต้า'              => (int)$c['total_capacity'],
        'จองทั้งหมด'          => (int)$c['total_bookings'],
        'ยืนยันแล้ว'          => (int)$c['confirmed'],
        'รอยืนยัน'            => (int)$c['pending'],
        'ยกเลิก'              => (int)$c['cancelled'],
        'อัตราเติมโควต้า_pct' => (float)($c['fill_pct'] ?? 0),
    ], $top10),
    'แนวโน้ม_7_วันล่าสุด' => array_map(fn($t) => [
        'วันที่'       => $t['day'],
        'จำนวนการจอง' => (int)$t['cnt'],
    ], $trend7),
];

$dbJson = json_encode($dbData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ── Build Prompt (template-based) ────────────────────────────────────────────
$systemPrompt = <<<PROMPT
คุณคือ AI ผู้เชี่ยวชาญด้านการวิเคราะห์ข้อมูล (Data Analyst Assistant) ประจำระบบ RSU Healthcare
หน้าที่ของคุณคือการวิเคราะห์ สรุปผล หรือตอบคำถามของแอดมิน โดยยึดกฎเหล็กดังต่อไปนี้อย่างเคร่งครัด:

[เงื่อนไขการทำงาน]
1. ห้ามจินตนาการหรือสร้างข้อมูลขึ้นมาเองเด็ดขาด คุณต้องอ้างอิงคำตอบจาก "ข้อมูลจากฐานข้อมูล" ที่ระบบแนบมาให้ด้านล่างนี้เท่านั้น
2. หากคำถามของแอดมินไม่สามารถหาคำตอบได้จากข้อมูลที่แนบมา ให้ตอบตรงๆ ว่า "ไม่สามารถวิเคราะห์ได้ เนื่องจากข้อมูลที่ระบบส่งมาไม่เพียงพอ"
3. จัดรูปแบบคำตอบให้อ่านง่าย สบายตา หากมีการเปรียบเทียบหรือสรุปตัวเลข แนะนำให้ใช้ตาราง (Markdown Table) หรือ Bullet points
4. ตอบคำถามด้วยความเป็นมืออาชีพ กระชับ และตรงประเด็น ตอบเป็นภาษาไทยเสมอ

[ข้อมูลจากฐานข้อมูล]
{$dbJson}

[คำสั่ง/คำถามจากแอดมิน]
{$query}
PROMPT;

// ── Auto-discover best available Gemini model ─────────────────────────────────
function gemini_pick_model(string $apiKey): string {
    // Cache per-session so we don't call ListModels on every chat message
    if (!empty($_SESSION['_gemini_model'])) {
        return $_SESSION['_gemini_model'];
    }

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}&pageSize=100");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $data = json_decode(curl_exec($ch) ?: '{}', true);
    curl_close($ch);

    $candidates = [];
    foreach ($data['models'] ?? [] as $m) {
        $name = $m['name'] ?? '';
        // Must support generateContent and be a Gemini text model
        if (!in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) continue;
        if (!preg_match('/gemini/i', $name)) continue;
        if (preg_match('/embed|vision|aqa|imagen/i', $name)) continue;
        $candidates[] = $name; // format: "models/gemini-X.Y-flash-..."
    }

    if (empty($candidates)) {
        return 'gemini-2.0-flash'; // last-resort fallback
    }

    // Score: higher version wins; flash > pro (speed/cost); stable > preview > exp
    $scored = [];
    foreach ($candidates as $c) {
        $score = 0;
        if (preg_match('/gemini-(\d+)\.(\d+)/i', $c, $mv)) {
            $score += (int)$mv[1] * 100 + (int)$mv[2] * 10;
        }
        if (stripos($c, 'flash') !== false) $score += 5;
        if (stripos($c, 'preview')  !== false) $score -= 1;
        if (stripos($c, '-exp')     !== false) $score -= 2;
        $scored[$c] = $score;
    }
    arsort($scored);

    $best = str_replace('models/', '', (string)array_key_first($scored));
    $_SESSION['_gemini_model'] = $best;
    error_log("Gemini: selected model = {$best}");
    return $best;
}

// ── Call Gemini API ───────────────────────────────────────────────────────────
$model = gemini_pick_model($apiKey);
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
    // แปล error ให้เข้าใจง่าย
    if ($httpCode === 429 || stripos($errMsg, 'quota') !== false || stripos($errMsg, 'RESOURCE_EXHAUSTED') !== false) {
        $userMsg = "API Key นี้ไม่มี free tier quota (limit = 0)\n\nวิธีแก้: สร้าง API Key ใหม่จาก Google AI Studio โดยตรง\n→ https://aistudio.google.com/apikey\nแล้วนำไปใส่ใน config/secrets.php แทน key เดิม";
    } elseif (stripos($errMsg, 'not found') !== false || stripos($errMsg, 'no longer available') !== false) {
        $userMsg = "โมเดล AI ที่ตั้งค่าไว้ไม่พร้อมใช้งาน กรุณาแจ้งผู้ดูแลระบบ";
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

echo json_encode([
    'ok'        => true,
    'reply'     => $text,
    'remaining' => max(0, $remaining),
    'limit'     => AI_RATE_LIMIT,
    'cooldown'  => AI_COOLDOWN,
]);
