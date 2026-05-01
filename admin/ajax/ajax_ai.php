<?php
// admin/ajax_ai.php — Gemini AI Assistant backend
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

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
$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';

if (!$apiKey) {
    echo json_encode(['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า GEMINI_API_KEY กรุณาตั้งค่าในหน้า Settings']);
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

    $sugPrompt = "คุณเป็น AI วิเคราะห์ข้อมูลแคมเปญสุขภาพ RSU Medical Clinic\n"
        . "ข้อมูลปัจจุบัน: {$sugCtx}\n\n"
        . "สร้างคำถามที่น่าสนใจ 5 ข้อ ที่แอดมินควรถามคุณ ให้หลากหลายประเด็น "
        . "(เช่น วิเคราะห์ยอดนิยม, แนวโน้ม, โควต้า, การยกเลิก, ข้อเสนอแนะ)\n"
        . "ตอบเป็น JSON array ภาษาไทยเท่านั้น ห้ามมีข้อความอื่น:\n"
        . "[\"คำถาม 1\",\"คำถาม 2\",\"คำถาม 3\",\"คำถาม 4\",\"คำถาม 5\"]";

$sugModel = (($_SESSION['_gemini_model'] ?? '') === 'gemini-2.0-flash')
    ? 'gemini-2.5-flash'
    : ($_SESSION['_gemini_model'] ?? 'gemini-2.5-flash');
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

$pdo = db();

// ── Safe DB tool functions (PHP เป็นคนรันเท่านั้น) ───────────────────────────
function fetchToolData(PDO $pdo, string $name, array $args): array {
    switch ($name) {

        case 'get_system_overview':
            // ภาพรวมตัวเลขสรุปทั้งหมดในระบบ
            return $pdo->query("
                SELECT
                    COUNT(DISTINCT c.id)                                    AS แคมเปญทั้งหมด,
                    COUNT(DISTINCT CASE WHEN c.status='active' THEN c.id END) AS แคมเปญที่เปิดอยู่,
                    COALESCE(SUM(c.total_capacity), 0)                      AS โควต้ารวมทุกแคมเปญ,
                    COUNT(b.id)                                             AS การจองทั้งหมด,
                    COALESCE(SUM(b.status='confirmed'), 0)                  AS ยืนยันแล้ว,
                    COALESCE(SUM(b.status='booked'), 0)                     AS รอยืนยัน,
                    COALESCE(SUM(b.status LIKE 'cancelled%'), 0)            AS ยกเลิก
                FROM camp_list c
                LEFT JOIN camp_bookings b ON b.campaign_id = c.id
            ")->fetch(PDO::FETCH_ASSOC) ?: [];

        case 'get_all_campaigns':
            // รายชื่อแคมเปญทั้งหมดพร้อมสถิติ (กรองตามสถานะได้)
            $status = $args['status'] ?? 'all';
            $where  = match ($status) {
                'active'   => "WHERE c.status = 'active'",
                'inactive' => "WHERE c.status = 'inactive'",
                default    => '',
            };
            return $pdo->query("
                SELECT
                    c.id                                                    AS campaign_id,
                    c.title                                                 AS ชื่อแคมเปญ,
                    c.status                                                AS สถานะ,
                    c.total_capacity                                        AS โควต้า,
                    COALESCE(COUNT(b.id), 0)                               AS จองทั้งหมด,
                    COALESCE(SUM(b.status='confirmed'), 0)                  AS ยืนยันแล้ว,
                    COALESCE(SUM(b.status='booked'), 0)                     AS รอยืนยัน,
                    COALESCE(SUM(b.status LIKE 'cancelled%'), 0)            AS ยกเลิก,
                    ROUND(
                        COALESCE(SUM(b.status IN ('booked','confirmed')), 0)
                        / NULLIF(c.total_capacity, 0) * 100, 1
                    )                                                       AS อัตราเติมโควต้า_pct
                FROM camp_list c
                LEFT JOIN camp_bookings b ON b.campaign_id = c.id
                {$where}
                GROUP BY c.id
                ORDER BY จองทั้งหมด DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

        case 'get_booking_trend':
            // แนวโน้มการจองรายวัน ย้อนหลัง N วัน
            $days = (int) min(max((int)($args['days'] ?? 7), 1), 365);
            return $pdo->query("
                SELECT DATE(created_at) AS วันที่, COUNT(*) AS จำนวนการจอง
                FROM camp_bookings
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
                GROUP BY DATE(created_at)
                ORDER BY วันที่ ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

        case 'get_cancellation_analysis':
            // วิเคราะห์การยกเลิกแยกตามแคมเปญ เรียงจากอัตราสูงสุด
            return $pdo->query("
                SELECT
                    c.title                                                  AS ชื่อแคมเปญ,
                    COALESCE(COUNT(b.id), 0)                                AS จองทั้งหมด,
                    COALESCE(SUM(b.status LIKE 'cancelled%'), 0)             AS ยกเลิก,
                    ROUND(
                        COALESCE(SUM(b.status LIKE 'cancelled%'), 0)
                        / NULLIF(COUNT(b.id), 0) * 100, 1
                    )                                                        AS อัตราการยกเลิก_pct
                FROM camp_list c
                LEFT JOIN camp_bookings b ON b.campaign_id = c.id
                GROUP BY c.id
                HAVING จองทั้งหมด > 0
                ORDER BY อัตราการยกเลิก_pct DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

        case 'get_capacity_report':
            // รายงานอัตราการเติมโควต้าทุกแคมเปญ เรียงจากเต็มมากสุด
            return $pdo->query("
                SELECT
                    c.title                                                  AS ชื่อแคมเปญ,
                    c.status                                                 AS สถานะ,
                    c.total_capacity                                         AS โควต้าทั้งหมด,
                    COALESCE(SUM(b.status IN ('booked','confirmed')), 0)     AS จองที่ใช้งานอยู่,
                    (c.total_capacity - COALESCE(SUM(b.status IN ('booked','confirmed')), 0))
                                                                             AS ที่ว่างเหลือ,
                    ROUND(
                        COALESCE(SUM(b.status IN ('booked','confirmed')), 0)
                        / NULLIF(c.total_capacity, 0) * 100, 1
                    )                                                        AS อัตราเติมโควต้า_pct
                FROM camp_list c
                LEFT JOIN camp_bookings b ON b.campaign_id = c.id
                GROUP BY c.id
                ORDER BY อัตราเติมโควต้า_pct DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

        case 'get_appointment_distribution':
            // วันนัดหมายไหนมีผู้เข้าร่วมมากที่สุด (appointment_date) ในแคมเปญที่ระบุ
            $campId = (int)($args['campaign_id'] ?? 0);
            if ($campId <= 0) return ['error' => 'กรุณาระบุ campaign_id ที่ถูกต้อง (ดึงได้จาก get_all_campaigns)'];
            $stmt = $pdo->prepare("
                SELECT
                    appointment_date                AS วันนัดหมาย,
                    COUNT(id)                       AS จำนวนผู้เข้าร่วม
                FROM camp_bookings
                WHERE campaign_id = ?
                GROUP BY appointment_date
                ORDER BY จำนวนผู้เข้าร่วม DESC
            ");
            $stmt->execute([$campId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) return ['info' => "ไม่พบข้อมูล appointment_date สำหรับ campaign_id {$campId}"];
            return $rows;

        case 'get_recent_errors':
            // ดึง Error Logs ล่าสุด N รายการ
            $limit = (int) min(max((int)($args['limit'] ?? 10), 1), 100);
            return $pdo->query("
                SELECT level, source, message, created_at, ip_address 
                FROM sys_error_logs 
                ORDER BY created_at DESC 
                LIMIT {$limit}
            ")->fetchAll(PDO::FETCH_ASSOC);

        default:
            return ['error' => "ไม่รู้จักฟังก์ชัน: {$name}"];
    }
}

// ── Gemini Tool Declarations (บอก AI ว่ามีเครื่องมืออะไรให้เรียกใช้) ──────────
$toolDeclarations = [[
    'function_declarations' => [
        [
            'name'        => 'get_system_overview',
            'description' => 'ดึงภาพรวมตัวเลขสรุปของระบบทั้งหมด (จำนวนแคมเปญ, การจอง, สถานะ)',
            'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name'        => 'get_all_campaigns',
            'description' => 'ดึงรายชื่อและสถิติการจองของแคมเปญทั้งหมด สามารถกรองตามสถานะได้',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'status' => [
                        'type'        => 'string',
                        'description' => 'กรองตามสถานะ: active=เปิดรับจอง, inactive=ปิด, all=ทั้งหมด',
                        'enum'        => ['active', 'inactive', 'all'],
                    ],
                ],
            ],
        ],
        [
            'name'        => 'get_booking_trend',
            'description' => 'ดึงแนวโน้มจำนวนการจองรายวัน ใช้วิเคราะห์ทิศทางและความนิยม',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'days' => [
                        'type'        => 'integer',
                        'description' => 'จำนวนวันย้อนหลังที่ต้องการ เช่น 7, 14, 30',
                    ],
                ],
                'required' => ['days'],
            ],
        ],
        [
            'name'        => 'get_cancellation_analysis',
            'description' => 'ดึงอัตราการยกเลิกการจองแยกตามแคมเปญ เรียงจากอัตราสูงสุด',
            'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name'        => 'get_capacity_report',
            'description' => 'ดึงรายงานอัตราการเติมโควต้าของทุกแคมเปญ บอกว่าแคมเปญไหนเต็มหรือยังว่างอยู่',
            'parameters'  => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name'        => 'get_appointment_distribution',
            'description' => 'ดูการกระจายตัวของผู้เข้าร่วมแยกตามวันนัดหมาย (appointment_date) ของแคมเปญที่ระบุ — ใช้ตอบคำถาม "วันไหนคนมากที่สุด/น้อยที่สุด", "วันที่ผู้เข้าร่วมกระจุกตัว" หรือ "ภาพรวม schedule ของแคมเปญ" ต้องระบุ campaign_id (ดึงได้จาก get_all_campaigns)',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'campaign_id' => [
                        'type'        => 'integer',
                        'description' => 'ID ของแคมเปญที่ต้องการดู (ได้จาก campaign_id ใน get_all_campaigns)',
                    ],
                ],
                'required' => ['campaign_id'],
            ],
        ],
        [
            'name'        => 'get_recent_errors',
            'description' => 'ดึงรายการข้อผิดพลาดล่าสุด (Error Logs) จากระบบ เพื่อใช้ในการวิเคราะห์สาเหตุของปัญหา',
            'parameters'  => [
                'type'       => 'object',
                'properties' => [
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'จำนวนรายการที่ต้องการดึง (สูงสุด 100)',
                    ],
                ],
            ],
        ],
    ],
]];

// ── Auto-discover best available Gemini model ─────────────────────────────────
function gemini_pick_model(string $apiKey): string {
    if (!empty($_SESSION['_gemini_model'])) {
        if ($_SESSION['_gemini_model'] === 'gemini-2.0-flash') {
            unset($_SESSION['_gemini_model']);
        } else {
            return $_SESSION['_gemini_model'];
        }
    }
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}&pageSize=100");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
    $data = json_decode(curl_exec($ch) ?: '{}', true);
    curl_close($ch);
    $candidates = [];
    foreach ($data['models'] ?? [] as $m) {
        $name = $m['name'] ?? '';
        if (!in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) continue;
        if (!preg_match('/gemini/i', $name)) continue;
        if (preg_match('/embed|vision|aqa|imagen/i', $name)) continue;
        $candidates[] = $name;
    }
    if (empty($candidates)) return 'gemini-2.5-flash';
    $scored = [];
    foreach ($candidates as $c) {
        $score = 0;
        if (preg_match('/gemini-(\d+)\.(\d+)/i', $c, $mv)) $score += (int)$mv[1] * 100 + (int)$mv[2] * 10;
        if (stripos($c, 'flash')   !== false) $score += 5;
        if (stripos($c, 'preview') !== false) $score -= 1;
        if (stripos($c, '-exp')    !== false) $score -= 2;
        $scored[$c] = $score;
    }
    arsort($scored);
    $best = str_replace('models/', '', (string)array_key_first($scored));
    $_SESSION['_gemini_model'] = $best;
    error_log("Gemini: selected model = {$best}");
    return $best;
}

// ── Helper: call Gemini API once ──────────────────────────────────────────────
function callGemini(string $apiKey, string $model, array $contents, array $tools, string $systemPrompt): array {
    $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    $body = json_encode([
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents'           => $contents,
        'tools'              => $tools,
        'generationConfig'   => ['temperature' => 0.3, 'maxOutputTokens' => 2048],
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    return ['raw' => $raw, 'httpCode' => $httpCode, 'curlErr' => $curlErr];
}

function geminiError(string $raw, int $httpCode): string {
    $errMsg = json_decode($raw, true)['error']['message'] ?? "HTTP {$httpCode}";
    if ($httpCode === 429 || stripos($errMsg, 'quota') !== false || stripos($errMsg, 'RESOURCE_EXHAUSTED') !== false) {
        return "API Key นี้ไม่มี free tier quota — สร้าง key ใหม่ที่ https://aistudio.google.com/apikey";
    }
    if (stripos($errMsg, 'not found') !== false || stripos($errMsg, 'no longer available') !== false) {
        return "โมเดล AI ไม่พร้อมใช้งาน กรุณาแจ้งผู้ดูแลระบบ";
    }
    return "Gemini ตอบกลับ: {$errMsg}";
}

// ── System prompt (role + rules เท่านั้น ไม่มีข้อมูล hardcode) ────────────────
$systemPrompt = <<<PROMPT
คุณคือ AI ผู้เชี่ยวชาญด้านการวิเคราะห์ข้อมูล (Data Analyst Assistant) ประจำระบบ RSU Medical Clinic
หน้าที่ของคุณคือวิเคราะห์ สรุปผล หรือตอบคำถามของแอดมิน โดยยึดกฎเหล็กดังนี้:

[เงื่อนไขการทำงาน]
1. ก่อนตอบคำถามใดๆ ให้เรียกใช้ฟังก์ชันที่เกี่ยวข้องเพื่อดึงข้อมูลจากฐานข้อมูลจริงก่อนเสมอ
2. ห้ามจินตนาการหรือสร้างตัวเลขขึ้นมาเอง อ้างอิงจากข้อมูลที่ดึงมาจากฟังก์ชันเท่านั้น
3. หากข้อมูลที่ดึงมาไม่เพียงพอ ให้บอกตรงๆ ว่า "ไม่สามารถวิเคราะห์ได้ เนื่องจากข้อมูลไม่เพียงพอ"
4. จัดรูปแบบคำตอบด้วย Markdown: ตาราง (|col|col|) สำหรับตัวเลข, bullet points สำหรับรายการ
5. ตอบเป็นภาษาไทย กระชับ มืออาชีพ ตรงประเด็น
PROMPT;

// ── Multi-turn: AI เรียก function → PHP ดึงข้อมูล → AI ตอบ ────────────────────
$model    = gemini_pick_model($apiKey);
$contents = [['role' => 'user', 'parts' => [['text' => $query]]]];
$finalText = '';
$maxIter   = 4; // ป้องกัน infinite loop

for ($iter = 0; $iter < $maxIter; $iter++) {

    $resp = callGemini($apiKey, $model, $contents, $toolDeclarations, $systemPrompt);

    if ($resp['curlErr']) {
        error_log("Gemini cURL error: " . $resp['curlErr']);
        echo json_encode(['ok' => false, 'error' => 'ไม่สามารถเชื่อมต่อ Gemini API: ' . $resp['curlErr']]);
        exit;
    }
    if ($resp['httpCode'] !== 200) {
        error_log("Gemini HTTP {$resp['httpCode']}: " . $resp['raw']);
        echo json_encode(['ok' => false, 'error' => geminiError($resp['raw'], $resp['httpCode'])]);
        exit;
    }

    $geminiResp = json_decode($resp['raw'], true);
    $parts      = $geminiResp['candidates'][0]['content']['parts'] ?? [];
    $role       = $geminiResp['candidates'][0]['content']['role'] ?? 'model';

    // ── Fix PHP json_decode issue ──────────────────────────────────────────────
    // json_decode(..., true) แปลง JSON {} → PHP [] (array)
    // เมื่อ json_encode กลับ [] กลายเป็น JSON array แทน object
    // Gemini proto ต้องการ args เป็น object {} ไม่ใช่ array []
    $normalizedParts = array_map(function ($p) {
        if (isset($p['functionCall'])) {
            $p['functionCall']['args'] = (object)($p['functionCall']['args'] ?? []);
        }
        return $p;
    }, $parts);

    // เพิ่มคำตอบของ model เข้า conversation
    $contents[] = ['role' => $role, 'parts' => $normalizedParts];

    // แยก functionCall vs text
    $funcCalls = array_filter($normalizedParts, fn($p) => isset($p['functionCall']));
    $textParts  = array_filter($normalizedParts, fn($p) => isset($p['text']));

    if (empty($funcCalls)) {
        // AI ตอบกลับเป็น text แล้ว → จบ
        foreach ($textParts as $p) $finalText .= $p['text'];
        break;
    }

    // AI ต้องการข้อมูล → PHP ดึงจาก DB แล้วส่งกลับ
    $funcResponses = [];
    foreach ($funcCalls as $p) {
        $fc   = $p['functionCall'];
        $name = $fc['name'];
        $args = (array)($fc['args'] ?? []);
        try {
            $data = fetchToolData($pdo, $name, $args);
            error_log("AI called tool: {$name}(" . json_encode($args) . ") → " . count($data) . " rows");
        } catch (PDOException $e) {
            error_log("Tool DB error [{$name}]: " . $e->getMessage());
            $data = ['error' => 'ดึงข้อมูลจาก DB ไม่สำเร็จ'];
        }
        $funcResponses[] = [
            'functionResponse' => [
                'name'     => $name,
                'response' => ['result' => $data],
            ],
        ];
    }

    // ส่งผลลัพธ์จาก PHP กลับไปให้ AI
    $contents[] = ['role' => 'user', 'parts' => $funcResponses];
}

if (!$finalText) {
    echo json_encode(['ok' => false, 'error' => 'Gemini ไม่ส่งคำตอบกลับมา (อาจถูก safety filter บล็อก)']);
    exit;
}

echo json_encode([
    'ok'        => true,
    'reply'     => $finalText,
    'remaining' => max(0, $remaining),
    'limit'     => AI_RATE_LIMIT,
    'cooldown'  => AI_COOLDOWN,
]);
