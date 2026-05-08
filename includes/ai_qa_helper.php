<?php
/**
 * includes/ai_qa_helper.php
 * AI QA Lab — เก็บคำถามจากผู้ใช้, จัดหมวด, ให้ AI ร่างคำตอบ (sandbox/manual mode)
 *
 * ใช้ร่วมกับ:
 * - user/ajax_chat.php   (capture in-app chat)
 * - api/line_webhook.php (capture LINE text message)
 * - portal/ajax_ai_qa.php + portal/_partials/ai_qa_lab.php (review dashboard)
 *
 * AI ไม่ตอบกลับผู้ใช้โดยตรง — ทุกคำตอบรอ admin review เท่านั้น
 */
declare(strict_types=1);

/** Taxonomy ตั้งต้น — ขยายได้ภายหลังจาก outliers */
const AI_QA_CATEGORIES = [
    'ประกัน',
    'ตารางหมอ',
    'เวลาเปิด-ปิด',
    'ขอใบรับรอง',
    'อาการ/ปรึกษา',
    'อื่นๆ',
];

/** สถานะ workflow ของ QA log */
const AI_QA_STATUSES = ['pending', 'generated', 'approved', 'rejected', 'needs_edit'];

function ensure_ai_qa_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_qa_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source ENUM('chat','line') NOT NULL,
            source_ref_id VARCHAR(64) NULL,
            user_id INT UNSIGNED NULL,
            line_user_id VARCHAR(64) NULL,
            question TEXT NOT NULL,
            category VARCHAR(64) NULL,
            ai_answer MEDIUMTEXT NULL,
            ai_model VARCHAR(64) NULL,
            ai_confidence DECIMAL(3,2) NULL,
            status ENUM('pending','generated','approved','rejected','needs_edit') NOT NULL DEFAULT 'pending',
            is_question ENUM('unknown','yes','no') NOT NULL DEFAULT 'unknown',
            question_check_at DATETIME NULL,
            reviewer_note TEXT NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status_created (status, created_at),
            INDEX idx_category (category),
            INDEX idx_source_created (source, created_at),
            INDEX idx_is_question (is_question)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}

    // Migration for existing installs — ignore "duplicate column" errors
    try { $pdo->exec("ALTER TABLE sys_ai_qa_log ADD COLUMN is_question ENUM('unknown','yes','no') NOT NULL DEFAULT 'unknown' AFTER status"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE sys_ai_qa_log ADD COLUMN question_check_at DATETIME NULL AFTER is_question"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE sys_ai_qa_log ADD INDEX idx_is_question (is_question)"); } catch (PDOException) {}
}

/**
 * Capture คำถามจาก channel ใดก็ได้ — wrap ใน try/catch ภายใน,
 * caller ไม่ต้องห่วงว่า DB ล่ม จะไปทำให้ flow หลักพัง
 *
 * @param string      $source        'chat' | 'line'
 * @param string      $question      ข้อความที่ user พิมพ์
 * @param int|null    $userId        sys_users.id (ถ้ารู้)
 * @param string|null $lineUserId    LINE userId (ถ้ามี)
 * @param string|null $sourceRefId   id ของข้อความต้นทาง (chat msg id, line message id)
 */
function capture_ai_qa(
    PDO $pdo,
    string $source,
    string $question,
    ?int $userId = null,
    ?string $lineUserId = null,
    ?string $sourceRefId = null
): void {
    $question = trim($question);
    if ($question === '' || mb_strlen($question) > 4000) return;
    if (!in_array($source, ['chat', 'line'], true)) return;

    try {
        ensure_ai_qa_schema($pdo);
        $stmt = $pdo->prepare("
            INSERT INTO sys_ai_qa_log
                (source, source_ref_id, user_id, line_user_id, question, status)
            VALUES
                (:source, :ref, :uid, :lid, :q, 'pending')
        ");
        $stmt->execute([
            ':source' => $source,
            ':ref'    => $sourceRefId,
            ':uid'    => $userId,
            ':lid'    => $lineUserId,
            ':q'      => $question,
        ]);
    } catch (Throwable $e) {
        error_log('capture_ai_qa failed: ' . $e->getMessage());
    }
}

/**
 * โหลด Gemini API key — ลำดับเดียวกับ portal/ajax_schedule_import.php
 */
function ai_qa_load_gemini_key(): string
{
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if (!$apiKey) {
        $secretsFile = __DIR__ . '/../config/secrets.php';
        if (is_file($secretsFile)) {
            $secrets = require $secretsFile;
            $apiKey = (string)($secrets['GEMINI_API_KEY'] ?? '');
        }
    }
    return $apiKey;
}

/**
 * สร้าง "clinic context" ให้ AI ใช้ตอบคำถามจากข้อมูลจริงของคลินิก
 * รวม: ชื่อ/เบอร์คลินิก, สถานะตอนนี้ (เปิด/ปิด), เวลาวันนี้+พรุ่งนี้,
 *      หมอที่ออกตรวจวันนี้+พรุ่งนี้, FAQ ที่ admin curate ไว้
 *
 * ค่าที่ขาดให้แทนด้วย "(ไม่มีข้อมูล)" — AI จะรู้ตัวว่าต้อง fallback ไปเป็น placeholder
 */
function ai_qa_build_clinic_context(PDO $pdo): string
{
    require_once __DIR__ . '/clinic_status_helper.php';

    $tz  = new DateTimeZone(CLINIC_TZ_NAME);
    $now = new DateTimeImmutable('now', $tz);

    // 7-day window (today + next 6 days) — ครอบคลุมทุก weekday
    // เพื่อตอบคำถามเช่น "วันอาทิตย์เปิดไหม" ที่อาจอยู่อีกหลายวันข้างหน้า
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = $now->modify("+{$i} day")->format('Y-m-d');
    }

    // ── Clinic profile ────────────────────────────────────────────────────
    $profile = get_clinic_profile_brief($pdo);
    $name    = $profile['name']  !== '' ? $profile['name']  : '(ไม่มีข้อมูล)';
    $phone   = $profile['phone'] !== '' ? $profile['phone'] : '(ไม่มีข้อมูล)';

    // ── Status ─────────────────────────────────────────────────────────────
    $status = get_clinic_current_status($pdo);
    $statusText = match ($status['state'] ?? '') {
        'open_now'    => 'เปิดอยู่ตอนนี้',
        'before_open' => 'ยังไม่เปิด (จะเปิดวันนี้เวลา ' . ($status['today_open'] ?? '') . ')',
        'after_close' => 'ปิดแล้วสำหรับวันนี้',
        'closed'      => 'วันนี้ปิดทำการ',
        default       => '(ไม่ทราบสถานะ)',
    };

    // ── Formatters ────────────────────────────────────────────────────────
    $fmtHours = function (array $h, string $date): string {
        $label = clinic_format_thai_date($date);
        if (!empty($h['closed'])) {
            $note = !empty($h['note']) ? ' — ' . $h['note'] : '';
            return "{$label}: ปิดทำการ{$note}";
        }
        if (empty($h['open_time']) || empty($h['close_time'])) {
            return "{$label}: (ไม่มีข้อมูลเวลาเปิด-ปิด)";
        }
        $note = !empty($h['note']) ? ' — ' . $h['note'] : '';
        return "{$label}: {$h['open_time']}–{$h['close_time']}{$note}";
    };
    $fmtDoctors = function (array $shifts): string {
        if (empty($shifts)) return '(ไม่มีหมอออกตรวจ)';
        $lines = [];
        foreach ($shifts as $s) {
            $title = trim((string)($s['doc_title'] ?? ''));
            $dname = trim((string)($s['doc_name']  ?? ''));
            $start = (string)($s['start_time'] ?? '');
            $end   = (string)($s['end_time']   ?? '');
            $room  = trim((string)($s['room_name'] ?? ''));
            $svc   = trim((string)($s['service_type'] ?? ''));
            $time  = ($start && $end) ? substr($start, 0, 5) . '–' . substr($end, 0, 5) : '';
            $extras = array_filter([$svc, $room ? 'ห้อง ' . $room : '']);
            $lines[] = '• ' . trim("{$title} {$dname}") . ($time ? " ({$time})" : '') .
                       (!empty($extras) ? ' — ' . implode(', ', $extras) : '');
        }
        return implode("\n", $lines);
    };

    // ── Build 7-day forecast ──────────────────────────────────────────────
    $hoursBlock   = [];
    $doctorsBlock = [];
    foreach ($days as $i => $d) {
        $tag = $i === 0 ? ' [วันนี้]' : ($i === 1 ? ' [พรุ่งนี้]' : '');
        $hoursBlock[] = $fmtHours(get_clinic_hours_for_date($pdo, $d), $d) . $tag;

        $shifts = get_clinic_doctors_for_date($pdo, $d);
        $docText = $fmtDoctors($shifts);
        $dayLabel = clinic_format_thai_date($d);
        $doctorsBlock[] = "● {$dayLabel}{$tag}\n" . preg_replace('/^/m', '  ', $docText);
    }
    $hoursText   = implode("\n", $hoursBlock);
    $doctorsText = implode("\n\n", $doctorsBlock);

    // ── FAQ Knowledge Base (admin-curated) ────────────────────────────────
    $faqLines = [];
    try {
        ensure_ai_faq_schema($pdo);
        $rs = $pdo->query("
            SELECT category, canonical_question, answer
              FROM sys_ai_faq
             WHERE is_active = 1
             ORDER BY updated_at DESC
             LIMIT 30
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs as $f) {
            $q = trim((string)$f['canonical_question']);
            $a = trim((string)$f['answer']);
            $c = trim((string)$f['category']);
            if ($q === '' || $a === '') continue;
            $faqLines[] = "[{$c}] ถาม: {$q}\n      ตอบ: {$a}";
        }
    } catch (PDOException) {}
    $faqText = empty($faqLines) ? '(ยังไม่มี FAQ ใน knowledge base)' : implode("\n\n", $faqLines);

    $todayLabel = clinic_format_thai_date($days[0]);

    return <<<CTX
═══ ข้อมูลคลินิก (ใช้ตอบคำถาม) ═══

[ข้อมูลทั่วไป]
ชื่อคลินิก: {$name}
เบอร์ติดต่อ: {$phone}
วันนี้: {$todayLabel}

[สถานะปัจจุบัน]
{$statusText}

[เวลาทำการ — 7 วันข้างหน้า ครอบคลุมทุก weekday]
{$hoursText}

[หมอออกตรวจ — 7 วันข้างหน้า]
{$doctorsText}

[FAQ Knowledge Base]
{$faqText}
CTX;
}

/**
 * เรียก Gemini เพื่อจัดหมวด + ร่างคำตอบ — คืน array หรือ throw
 *
 * @return array{category:string, answer:string, confidence:float, model:string}
 */
function ai_qa_generate_answer(string $question, ?PDO $pdo = null): array
{
    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า GEMINI_API_KEY');
    }

    $categoriesList = implode(' | ', AI_QA_CATEGORIES);
    $clinicContext  = $pdo ? ai_qa_build_clinic_context($pdo) : '(ไม่ได้ส่ง PDO เข้ามา — ไม่มี clinic context)';

    $systemPrompt = <<<PROMPT
คุณคือผู้ช่วยตอบคำถามของห้องพยาบาลคลินิก RSU Medical
หน้าที่: รับคำถามจากผู้ใช้ → จัดหมวดหมู่ + ร่างคำตอบเบื้องต้น โดย**ใช้ข้อมูลจริงของคลินิก**ที่ให้ไว้ด้านล่าง

กฎสำคัญ:
1. **ตอบเป็นภาษาเดียวกับที่ผู้ใช้ถาม** — ถ้า user ถามภาษาอังกฤษ ตอบภาษาอังกฤษ, ถามภาษาไทย ตอบภาษาไทย, ถามผสม → ใช้ภาษาที่เป็นภาษาหลักของคำถาม
2. ใช้ข้อมูลจาก "ข้อมูลคลินิก" ด้านล่างเป็นแหล่งข้อมูลหลัก — เวลาเปิด-ปิด, สถานะ, ชื่อหมอ, ตารางออกตรวจ, FAQ
3. ถ้าคำถามตรง/ใกล้เคียงกับ FAQ — ให้ตอบโดยอิงคำตอบใน FAQ (ปรับสำนวนให้ลื่น และแปลเป็นภาษาของผู้ถามถ้าจำเป็น)
4. ถ้าข้อมูลที่จำเป็นไม่อยู่ใน context — ใช้ placeholder ในภาษาที่เหมาะสม: ไทย "(ขอข้อมูลจากเจ้าหน้าที่)" / English "(please contact staff)"
5. ห้ามแต่งตัวเลข/วันที่/ชื่อหมอเอง — ถ้าไม่มีใน context ให้ใส่ placeholder เท่านั้น
6. คำตอบนี้จะถูก admin ตรวจสอบก่อนส่งให้ user จริง — ดังนั้นเขียนสุภาพ กระชับ ตรงประเด็น
7. confidence: สูง (0.8+) ถ้าตอบจากข้อมูลที่ชัดใน context, ต่ำ (0.3-) ถ้าต้องใช้ placeholder หลายจุด

{$clinicContext}

═══════════════════

หมวดหมู่ที่อนุญาต (เลือก 1 หมวดเท่านั้น): {$categoriesList}
หมายเหตุ: category ใช้ภาษาไทยตาม list เสมอ — แม้คำตอบจะเป็นภาษาอังกฤษ

ตอบกลับเป็น JSON ตาม schema นี้เท่านั้น:
{
  "category": "ชื่อหมวดจาก list ด้านบน (ภาษาไทย)",
  "answer": "คำตอบในภาษาเดียวกับคำถาม สุภาพ กระชับ 2-4 ประโยค (อิงจาก context จริง)",
  "confidence": 0.0 ถึง 1.0
}

คำถามจากผู้ใช้:
{$question}
PROMPT;

    $body = json_encode([
        'contents' => [[
            'role'  => 'user',
            'parts' => [['text' => $systemPrompt]],
        ]],
        'generationConfig' => [
            'temperature'      => 0.3,
            'maxOutputTokens'  => 1024,
            'responseMimeType' => 'application/json',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $models = ['gemini-2.5-flash', 'gemini-2.0-flash'];
    $usedModel = '';
    $rawText   = '';

    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw      = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('Gemini connection error: ' . $curlErr);
        }
        if ($httpCode === 200) {
            $usedModel = $model;
            $rawText   = $raw;
            break;
        }
        // 404 = model not found, ลอง fallback; อย่างอื่น throw ทันที
        if ($httpCode !== 404) {
            $errMsg = json_decode($raw, true)['error']['message'] ?? "HTTP {$httpCode}";
            throw new RuntimeException('Gemini error: ' . $errMsg);
        }
    }

    if ($usedModel === '') {
        throw new RuntimeException('ไม่พบ model ที่ใช้งานได้');
    }

    $resp = json_decode($rawText, true);
    $text = (string)($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/```\s*$/m', '', $text);
    $text = trim($text);

    $parsed = json_decode($text, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('AI ตอบกลับในรูปแบบที่ไม่ถูกต้อง');
    }

    $category = (string)($parsed['category'] ?? 'อื่นๆ');
    if (!in_array($category, AI_QA_CATEGORIES, true)) {
        $category = 'อื่นๆ';
    }
    $answer     = trim((string)($parsed['answer'] ?? ''));
    $confidence = (float)($parsed['confidence'] ?? 0);
    if ($confidence < 0) $confidence = 0.0;
    if ($confidence > 1) $confidence = 1.0;

    if ($answer === '') {
        throw new RuntimeException('AI ไม่ได้ส่งคำตอบกลับมา');
    }

    return [
        'category'   => $category,
        'answer'     => $answer,
        'confidence' => $confidence,
        'model'      => $usedModel,
    ];
}

/**
 * ให้ Gemini ตัดสินทีเดียวว่าข้อความใดเป็น "คำถามจริง" และข้อความใดไม่ใช่
 * รับ array ของคำถาม (1..N) → คืน array ของ 'yes'|'no' (index ตรงกับ input)
 *
 * คำถามจริง = ผู้ใช้ต้องการข้อมูล/คำตอบ (เช่น "เปิดกี่โมง", "หมอชื่ออะไรบ้าง")
 * ไม่ใช่คำถาม = greeting, ack, statement, สติกเกอร์ข้อความ, สแปม, ข้อความสั้นๆ ไร้ความหมาย
 *
 * @param string[] $questions
 * @return string[]  array ของ 'yes' หรือ 'no' (ความยาวเท่า input — fallback 'unknown' ถ้า AI ตอบไม่ครบ)
 */
function ai_qa_classify_questions(array $questions): array
{
    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า GEMINI_API_KEY');
    }
    if (empty($questions)) return [];

    // สร้าง numbered list ให้ AI mapping กลับได้แน่นอน
    $lines = [];
    foreach ($questions as $i => $q) {
        $lines[] = ($i + 1) . '. ' . trim((string)$q);
    }
    $listText = implode("\n", $lines);
    $count = count($questions);

    $prompt = <<<PROMPT
คุณกำลังช่วยคัดกรองข้อความที่ผู้ใช้พิมพ์เข้ามาในช่องแชทของคลินิกพยาบาล RSU Medical
หน้าที่: ตัดสินว่าข้อความแต่ละชิ้นเป็น "คำถามจริง" หรือไม่

นิยาม:
- "yes" = ข้อความที่ผู้ใช้ต้องการได้ข้อมูล / คำตอบ / ความช่วยเหลือ
  ตัวอย่าง: "เปิดกี่โมง", "หมอชื่ออะไร", "ขอใบรับรองยังไง", "วันนี้มีคิวว่างไหม", "ปวดท้องควรทำยังไง"
  รวมถึงประโยคบอกเล่าที่บ่งบอกการขอความช่วยเหลือโดยนัย เช่น "อยากนัดหมอ", "ต้องการใบรับรอง"
- "no" = ทุกอย่างที่ไม่ใช่คำถาม:
  ทักทาย ("สวัสดี", "หวัดดี", "hi"), ตอบรับ/ขอบคุณ ("ครับ", "ค่ะ", "ok", "ขอบคุณ"),
  ข้อความสั้นไร้ความหมาย, สแปม, สติกเกอร์ที่กลายเป็น text, ตัวเลขล้วน, อิโมจิล้วน,
  ข้อความบอกเล่าทั่วไปที่ไม่ได้ขออะไร

ข้อความ ({$count} รายการ):
{$listText}

ตอบกลับเป็น JSON ตาม schema นี้เท่านั้น (array ความยาว {$count} ตามลำดับเดียวกับ input):
{"verdicts": ["yes" หรือ "no", ...]}
PROMPT;

    $body = json_encode([
        'contents' => [[
            'role'  => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'temperature'      => 0.1,
            'maxOutputTokens'  => 2048,
            'responseMimeType' => 'application/json',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $models  = ['gemini-2.5-flash', 'gemini-2.0-flash'];
    $rawText = '';
    foreach ($models as $model) {
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
        $raw      = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('Gemini connection error: ' . $curlErr);
        }
        if ($httpCode === 200) {
            $rawText = $raw;
            break;
        }
        if ($httpCode !== 404) {
            $errMsg = json_decode($raw, true)['error']['message'] ?? "HTTP {$httpCode}";
            throw new RuntimeException('Gemini error: ' . $errMsg);
        }
    }
    if ($rawText === '') {
        throw new RuntimeException('ไม่พบ model ที่ใช้งานได้');
    }

    $resp = json_decode($rawText, true);
    $text = (string)($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/```\s*$/m', '', $text);
    $text = trim($text);

    $parsed   = json_decode($text, true);
    $verdicts = is_array($parsed['verdicts'] ?? null) ? $parsed['verdicts'] : [];

    // Normalize → length = count, แต่ละค่าเป็น 'yes'|'no' (อะไรก็ตามที่ AI ส่งกลับมาแปลกๆ → 'unknown')
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $v = strtolower(trim((string)($verdicts[$i] ?? '')));
        $out[] = ($v === 'yes' || $v === 'no') ? $v : 'unknown';
    }
    return $out;
}

/** จำนวน variant ที่ AI สร้างต่อครั้ง — คำสั่งใน prompt ก็ตรงกับค่านี้ */
const AI_FAQ_VARIANT_COUNT = 5;

/**
 * Schema ของ FAQ Knowledge Base — admin เขียนคำตอบเอง + AI gen variant คำถาม
 * แยกจาก sys_ai_qa_log (ซึ่งเป็น raw capture) เพื่อให้ retention/usage ต่างกันได้
 */
function ensure_ai_faq_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_faq (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(64) NOT NULL DEFAULT 'อื่นๆ',
            canonical_question TEXT NOT NULL,
            answer MEDIUMTEXT NOT NULL,
            source_qa_id INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED NULL,
            updated_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_category (category),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_faq_variants (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            faq_id INT UNSIGNED NOT NULL,
            variant_question TEXT NOT NULL,
            source ENUM('manual','ai_generated') NOT NULL DEFAULT 'manual',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_faq (faq_id),
            CONSTRAINT fk_faq_variant FOREIGN KEY (faq_id)
                REFERENCES sys_ai_faq(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}
}

/**
 * ให้ Gemini เจน paraphrase 5 รูปแบบของคำถามต้นฉบับ
 * ส่งคืน array ของ string — admin จะเลือก keep/edit/delete รายตัวก่อนบันทึก
 *
 * @return string[]
 */
function ai_faq_generate_variants(string $question): array
{
    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า GEMINI_API_KEY');
    }

    $count = AI_FAQ_VARIANT_COUNT;
    $prompt = <<<PROMPT
คุณกำลังช่วยสร้างฐาน FAQ ของห้องพยาบาลคลินิก RSU Medical
ผู้ใช้คนเดียวอาจถามเรื่องเดียวกันได้หลายแบบ — ทั้งภาษาทางการ ภาษาพูด คำสะกดผิด คำย่อ ประโยคยาว/สั้น

หน้าที่: สร้าง paraphrase {$count} รูปแบบของคำถามต้นฉบับด้านล่าง
ข้อกำหนด:
- **ใช้ภาษาเดียวกับคำถามต้นฉบับ** — ถ้าต้นฉบับภาษาไทย → variants เป็นภาษาไทย, ถ้าภาษาอังกฤษ → variants เป็นภาษาอังกฤษ
- ความหมายเดิม แต่รูปประโยคต่างกัน — เช่น ทางการ vs ไม่ทางการ, สั้น vs ยาว, ถามตรง vs อ้อม
- ห้ามซ้ำกับต้นฉบับ และห้ามซ้ำกันเอง
- เป็นคำถามที่ผู้ใช้น่าจะพิมพ์จริงในแชท (รวมคำสะกดผิดได้นิดหน่อย)

คำถามต้นฉบับ:
{$question}

ตอบกลับเป็น JSON ตาม schema นี้เท่านั้น:
{"variants": ["...", "...", "...", "...", "..."]}
PROMPT;

    $body = json_encode([
        'contents' => [[
            'role'  => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'temperature'      => 0.7,
            'maxOutputTokens'  => 1024,
            'responseMimeType' => 'application/json',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $models  = ['gemini-2.5-flash', 'gemini-2.0-flash'];
    $rawText = '';

    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw      = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('Gemini connection error: ' . $curlErr);
        }
        if ($httpCode === 200) {
            $rawText = $raw;
            break;
        }
        if ($httpCode !== 404) {
            $errMsg = json_decode($raw, true)['error']['message'] ?? "HTTP {$httpCode}";
            throw new RuntimeException('Gemini error: ' . $errMsg);
        }
    }

    if ($rawText === '') {
        throw new RuntimeException('ไม่พบ model ที่ใช้งานได้');
    }

    $resp = json_decode($rawText, true);
    $text = (string)($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/```\s*$/m', '', $text);
    $text = trim($text);

    $parsed   = json_decode($text, true);
    $variants = is_array($parsed['variants'] ?? null) ? $parsed['variants'] : [];

    $clean = [];
    foreach ($variants as $v) {
        $v = trim((string)$v);
        if ($v === '' || mb_strlen($v) > 500) continue;
        if (mb_strtolower($v) === mb_strtolower($question)) continue;
        $clean[] = $v;
    }
    if (empty($clean)) {
        throw new RuntimeException('AI ไม่ได้ส่ง variant กลับมา');
    }
    return $clean;
}
