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

/**
 * Helper สำหรับ log debug ของ AI QA matcher → ไปที่ sys_error_logs ที่ portal viewer อ่านได้
 * เปิด/ปิดที่นี่จุดเดียว — ใช้ source 'ai_qa_helper.php' เพื่อให้กรองง่าย
 */
function ai_qa_debug_log(string $message, array $context = [], string $level = 'info'): void
{
    $ctxJson = $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    error_log('[AI QA] ' . $message . ($ctxJson ? ' ' . $ctxJson : ''));
    if (function_exists('log_error_to_db')) {
        log_error_to_db($message, $level, 'ai_qa_helper.php', $ctxJson ?: '');
    }
}

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
function ai_qa_build_clinic_context(PDO $pdo, ?DateTimeImmutable $asOf = null, string $query = ''): string
{
    require_once __DIR__ . '/clinic_status_helper.php';
    require_once __DIR__ . '/ai_knowledge_helper.php';
    require_once __DIR__ . '/ai_chunk_helper.php';

    $tz  = new DateTimeZone(CLINIC_TZ_NAME);
    // $asOf = "now" by default; for retrospective FAQ generation (Captured Questions),
    // callers pass the question's created_at so [สถานะ] / [วันนี้] match user's actual moment.
    $now = $asOf ? $asOf->setTimezone($tz) : new DateTimeImmutable('now', $tz);

    // 31-day window (today + next 30 days) — ครอบคลุม update cycle รายเดือน
    // admin อัปเดตตารางเดือนละครั้ง ดังนั้น context ต้องเห็นข้อมูลทั้งเดือน
    // เพื่อตอบคำถามเช่น "วันที่ 25 เปิดไหม", "วันอาทิตย์หน้าหมอใครออกตรวจ"
    $days = [];
    for ($i = 0; $i < 31; $i++) {
        $days[] = $now->modify("+{$i} day")->format('Y-m-d');
    }

    // ── Clinic profile ────────────────────────────────────────────────────
    $profile = get_clinic_profile_brief($pdo);
    $name    = $profile['name']  !== '' ? $profile['name']  : '(ไม่มีข้อมูล)';
    $phone   = $profile['phone'] !== '' ? $profile['phone'] : '(ไม่มีข้อมูล)';

    // ── Status ─────────────────────────────────────────────────────────────
    $status = get_clinic_current_status($pdo, $now);
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

    // ── Custom notes (services / pricing / policies / etc.) ───────────────
    $notesText = render_clinic_notes_block($pdo);
    $notesSection = $notesText !== ''
        ? "\n\n[ข้อมูลเพิ่มเติม / บริการ / ราคา / นโยบาย]\n{$notesText}"
        : '';

    // ── Knowledge Chunks (RAG: semantic search ถ้ามี query) ───────────────
    // ถ้ามี query: เลือก top-5 chunks ที่ใกล้เคียง → ฉีดเข้า context
    // ถ้าไม่มี query: skip (กัน context ใหญ่เกินไป) — caller ทั่วไป (preview/diagnose)
    // ไม่ต้องการ chunks เพราะจะใหญ่และไม่เกี่ยว
    $chunksSection = '';
    if ($query !== '') {
        try {
            $chunksText = render_chunks_context_block($pdo, $query, 5);
            if ($chunksText !== '') {
                $chunksSection = "\n\n[ข้อมูลที่เกี่ยวข้องจาก Knowledge Base]\n{$chunksText}";
            }
        } catch (Throwable $e) {
            error_log('[chunks context] ' . $e->getMessage());
        }
    }

    $todayLabel = clinic_format_thai_date($days[0]);

    return <<<CTX
═══ ข้อมูลคลินิก (ใช้ตอบคำถาม) ═══

[ข้อมูลทั่วไป]
ชื่อคลินิก: {$name}
เบอร์ติดต่อ: {$phone}
วันนี้: {$todayLabel}

[สถานะปัจจุบัน]
{$statusText}

[เวลาทำการ — 31 วันข้างหน้า (ครอบคลุมทั้งเดือน — ใช้หาคำตอบสำหรับวันที่หรือ weekday ใด ๆ)]
{$hoursText}

[หมอออกตรวจ — 31 วันข้างหน้า]
{$doctorsText}

[FAQ Knowledge Base]
{$faqText}{$notesSection}{$chunksSection}
CTX;
}

/**
 * เรียก Gemini เพื่อจัดหมวด + ร่างคำตอบ — คืน array หรือ throw
 *
 * @return array{category:string, answer:string, confidence:float, model:string}
 */
function ai_qa_generate_answer(string $question, ?PDO $pdo = null, ?DateTimeImmutable $askedAt = null): array
{
    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า GEMINI_API_KEY');
    }

    $categoriesList = implode(' | ', AI_QA_CATEGORIES);
    $clinicContext  = $pdo
        ? ai_qa_build_clinic_context($pdo, $askedAt, $question)
        : '(ไม่ได้ส่ง PDO เข้ามา — ไม่มี clinic context)';

    require_once __DIR__ . '/ai_prompts_helper.php';
    $systemPrompt = get_ai_prompt('generator', [
        'question'        => $question,
        'clinic_context'  => $clinicContext,
        'categories_list' => $categoriesList,
    ]);

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

    // Inner closure: 1 attempt at given temperature. Returns [clean, rawText, parsedCount].
    $callOnce = function (float $temperature) use ($apiKey, $question): array {
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
                'temperature'      => $temperature,
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
        return [$clean, $rawText, count($variants)];
    };

    // First attempt — default temperature
    [$clean, $raw1, $parsed1] = $callOnce(0.7);
    if (!empty($clean)) return $clean;

    // Retry once with higher temperature — handles intermittent empty/dup-only responses
    [$clean, $raw2, $parsed2] = $callOnce(1.0);
    if (!empty($clean)) return $clean;

    // Both attempts produced nothing usable — log raw responses for debugging
    error_log(sprintf(
        'ai_faq_generate_variants exhausted retries. q=%s parsed1=%d parsed2=%d raw1=%s raw2=%s',
        $question,
        $parsed1,
        $parsed2,
        substr($raw1, 0, 500),
        substr($raw2, 0, 500)
    ));

    if ($parsed1 === 0 && $parsed2 === 0) {
        throw new RuntimeException('AI ไม่ได้ส่ง variant กลับมา (response ว่าง 2 ครั้ง) — ลองพิมพ์คำถามให้ยาวขึ้น');
    }
    throw new RuntimeException('AI ส่ง variant แต่ซ้ำกับต้นฉบับทั้งหมด — ลองปรับคำถามต้นฉบับให้กว้างขึ้น');
}

/**
 * Hybrid FAQ matcher — ใช้ใน LINE webhook เพื่อตอบกลับ user อัตโนมัติ
 *
 * Phase 1 (เร็ว, ฟรี): exact match ตามลำดับความน่าเชื่อถือ
 *   1a. sys_ai_faq.canonical_question  (admin curate โดยตรง)
 *   1b. sys_ai_faq_variants            (admin/AI generate variant)
 *   1c. sys_ai_qa_log status='approved' (admin approve answer ของ AI)
 *
 * Phase 2 (กิน API): Gemini semantic match ถ้า phase 1 ไม่เจอ
 *   ส่งคำถาม + list ของ candidate ให้ Gemini เลือก best match
 *   threshold confidence >= 0.7 (ถ้าต่ำกว่า return null)
 *
 * @param callable|null $onSemanticPhase callback ก่อนเข้า Phase 2 (Gemini)
 *        ใช้สำหรับ side effects เช่น แสดง loading indicator ใน LINE
 * @return array{answer:string, matched_via:string, source_id:int, confidence?:float}|null
 */
/**
 * Detect time-relative phrases that age out of a stored FAQ answer.
 * Triggers a re-generate so we don't ship a "พรุ่งนี้ = อาทิตย์ที่ 17 พ.ค."
 * answer to users who ask the same question weeks later.
 */
function ai_qa_answer_has_stale_dates(string $answer): bool
{
    $patterns = [
        // Thai relative-day words
        '/(วันนี้|พรุ่งนี้|มะรืน|เมื่อวาน)/u',
        // English relative-day words (word-bounded so e.g. "todayish" doesn't match)
        '/\b(today|tomorrow|yesterday)\b/i',
        // "วันอาทิตย์ที่ 17" — weekday + "ที่" + specific date
        '/วัน(อาทิตย์|จันทร์|อังคาร|พุธ|พฤหัสบดี|พฤหัส|ศุกร์|เสาร์)ที่/u',
        // Buddhist/Christian year that pins answer to a specific calendar year
        '/(พ\.ศ\.|ค\.ศ\.)\s*2\d{3}/u',
        // Full Thai month names — generic answers should describe weekly
        // patterns, not month-specific events
        '/(มกราคม|กุมภาพันธ์|มีนาคม|เมษายน|พฤษภาคม|มิถุนายน|กรกฎาคม|สิงหาคม|กันยายน|ตุลาคม|พฤศจิกายน|ธันวาคม)/u',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $answer)) return true;
    }
    return false;
}

/**
 * If a matched FAQ answer contains time-relative phrasing, re-generate
 * the answer against the current clinic context via Gemini so users
 * never see a stale "พรุ่งนี้" pinned to last week's date. Falls back
 * to the original match silently when the refresh fails or also
 * comes back stale.
 */
function ai_qa_refresh_if_stale(array $match, PDO $pdo, string $question): array
{
    if (!ai_qa_answer_has_stale_dates($match['answer'])) return $match;
    try {
        $fresh = ai_qa_generate_answer($question, $pdo);
        if (!empty($fresh['answer']) && !ai_qa_answer_has_stale_dates($fresh['answer'])) {
            ai_qa_debug_log('AI QA stale FAQ refreshed', [
                'orig_via' => $match['matched_via'] ?? '',
                'orig_id'  => $match['source_id'] ?? null,
                'model'    => $fresh['model'] ?? '',
            ]);
            $match['answer']      = $fresh['answer'];
            $match['matched_via'] = ($match['matched_via'] ?? 'unknown') . '+refreshed';
            return $match;
        }
        ai_qa_debug_log('AI QA stale refresh dropped (regenerated answer also stale)', [
            'orig_via' => $match['matched_via'] ?? '',
        ], 'warning');
    } catch (Throwable $e) {
        ai_qa_debug_log('AI QA stale refresh failed — falling back to original', [
            'error' => $e->getMessage(),
        ], 'warning');
    }
    return $match;
}

function ai_qa_match_faq(PDO $pdo, string $question, ?callable $onSemanticPhase = null): ?array
{
    $q = trim($question);
    $qSnippet = mb_substr($q, 0, 120);

    // ข้ามข้อความสั้น ๆ (ack, สวัสดี ฯลฯ) เพื่อไม่ false-match
    if ($q === '' || mb_strlen($q) < 4) {
        ai_qa_debug_log('AI QA matcher skipped — question too short', [
            'len' => mb_strlen($q),
            'question_snippet' => $qSnippet,
        ]);
        return null;
    }

    ai_qa_debug_log('AI QA matcher start', ['question_snippet' => $qSnippet]);

    ensure_ai_faq_schema($pdo);
    ensure_ai_qa_schema($pdo);

    // ── Phase 1a: canonical question ──────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT id, answer, category
          FROM sys_ai_faq
         WHERE is_active = 1 AND TRIM(canonical_question) = :q
         LIMIT 1
    ");
    $stmt->execute([':q' => $q]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        ai_qa_debug_log('AI QA Phase 1a HIT (canonical)', ['faq_id' => (int)$row['id']]);
        return ai_qa_refresh_if_stale([
            'answer'      => (string)$row['answer'],
            'category'    => (string)($row['category'] ?? 'อื่นๆ'),
            'matched_via' => 'exact_canonical',
            'source_id'   => (int)$row['id'],
        ], $pdo, $q);
    }

    // ── Phase 1b: FAQ variants ────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT f.id, f.answer, f.category
          FROM sys_ai_faq f
          JOIN sys_ai_faq_variants v ON v.faq_id = f.id
         WHERE f.is_active = 1 AND TRIM(v.variant_question) = :q
         LIMIT 1
    ");
    $stmt->execute([':q' => $q]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        ai_qa_debug_log('AI QA Phase 1b HIT (variant)', ['faq_id' => (int)$row['id']]);
        return ai_qa_refresh_if_stale([
            'answer'      => (string)$row['answer'],
            'category'    => (string)($row['category'] ?? 'อื่นๆ'),
            'matched_via' => 'exact_variant',
            'source_id'   => (int)$row['id'],
        ], $pdo, $q);
    }

    // ── Phase 1c: approved Captured ───────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT id, ai_answer, category
          FROM sys_ai_qa_log
         WHERE status = 'approved' AND ai_answer IS NOT NULL
           AND TRIM(question) = :q
         ORDER BY reviewed_at DESC
         LIMIT 1
    ");
    $stmt->execute([':q' => $q]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        ai_qa_debug_log('AI QA Phase 1c HIT (approved)', ['qa_log_id' => (int)$row['id']]);
        return ai_qa_refresh_if_stale([
            'answer'      => (string)$row['ai_answer'],
            'category'    => (string)($row['category'] ?? 'อื่นๆ'),
            'matched_via' => 'exact_approved',
            'source_id'   => (int)$row['id'],
        ], $pdo, $q);
    }

    ai_qa_debug_log('AI QA Phase 1 MISS — proceed to Gemini', ['question_snippet' => $qSnippet]);

    // ── Phase 2: Gemini semantic match ────────────────────────────────────
    // เรียก callback ให้ caller ทำ side effect (เช่น loading indicator ใน LINE)
    // ก่อน Gemini call ที่อาจใช้เวลา 1-3 วิ
    if ($onSemanticPhase !== null) {
        try { $onSemanticPhase(); } catch (Throwable $e) {
            ai_qa_debug_log('AI QA onSemanticPhase callback failed', ['error' => $e->getMessage()], 'warning');
        }
    }
    return ai_qa_match_via_gemini($pdo, $q);
}

/**
 * Phase 2 ของ ai_qa_match_faq — ใช้ Gemini เลือกคำถามที่ใกล้เคียงที่สุด
 * จาก pool ของ FAQ + approved Captured
 * Threshold confidence ≥ 0.7 ถึงจะถือว่า match จริง
 */
function ai_qa_match_via_gemini(PDO $pdo, string $question): ?array
{
    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) {
        ai_qa_debug_log('AI QA Gemini abort — no API key', [], 'warning');
        return null;
    }

    // Build candidate pool — ทั้ง FAQ + approved Captured (dedupe)
    $candidates = [];

    try {
        $rs = $pdo->query("
            SELECT 'faq' AS src, id, canonical_question AS q, answer, category
              FROM sys_ai_faq
             WHERE is_active = 1
             ORDER BY updated_at DESC
             LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs as $r) $candidates[] = $r;

        $rs2 = $pdo->query("
            SELECT 'qa' AS src, MIN(id) AS id,
                   MAX(question) AS q, MAX(ai_answer) AS answer, MAX(category) AS category
              FROM sys_ai_qa_log
             WHERE status = 'approved' AND ai_answer IS NOT NULL
             GROUP BY TRIM(question)
             ORDER BY MAX(reviewed_at) DESC
             LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rs2 as $r) $candidates[] = $r;
    } catch (Throwable $e) {
        ai_qa_debug_log('AI QA Gemini abort — candidate pool fetch failed', [
            'error' => $e->getMessage(),
        ], 'warning');
        return null;
    }

    if (empty($candidates)) {
        ai_qa_debug_log('AI QA Gemini abort — no candidates (FAQ ว่าง + ไม่มี approved)', [], 'warning');
        return null;
    }

    ai_qa_debug_log('AI QA Gemini candidate pool ready', [
        'total'    => count($candidates),
        'faq_cnt'  => count(array_filter($candidates, fn($c) => $c['src'] === 'faq')),
        'qa_cnt'   => count(array_filter($candidates, fn($c) => $c['src'] === 'qa')),
    ]);

    // Build prompt
    $list = '';
    foreach ($candidates as $i => $c) {
        $shortQ = mb_substr(preg_replace('/\s+/', ' ', trim((string)$c['q'])), 0, 200);
        $list .= "[{$i}] {$shortQ}\n";
    }

    require_once __DIR__ . '/ai_prompts_helper.php';
    $prompt = get_ai_prompt('matcher', [
        'question'        => $question,
        'candidates_list' => $list,
    ]);

    $body = json_encode([
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature'      => 0.2,
            // Gemini 2.5-flash ใช้ "thinking tokens" กิน budget — ตั้งสูงไว้กัน MAX_TOKENS
            'maxOutputTokens'  => 1024,
            'responseMimeType' => 'application/json',
            // schema ตายตัว — Gemini จะ output ตาม schema เด็ดขาด
            // ป้องกัน preamble "Here is the JSON requested:" ที่เคยเจอ
            // หมายเหตุ: Gemini API ต้อง type ตัวพิมพ์ใหญ่ (OBJECT/INTEGER/NUMBER)
            'responseSchema'   => [
                'type' => 'OBJECT',
                'properties' => [
                    'match_index' => ['type' => 'INTEGER'],
                    'confidence'  => ['type' => 'NUMBER'],
                ],
                'required' => ['match_index', 'confidence'],
            ],
            // ปิด thinking — เราไม่ต้องการ reasoning chain แค่ classification
            // Gemini 2.5+ default thinkingBudget=dynamic อาจกิน 200+ token
            'thinkingConfig'   => [
                'thinkingBudget' => 0,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);

    foreach (['gemini-2.5-flash', 'gemini-2.0-flash'] as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            // webhook ต้องตอบกลับ LINE ภายใน ~5s — set timeout สั้นกว่า answer-gen
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $tStart   = microtime(true);
        $raw      = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        $elapsedMs = (int)round((microtime(true) - $tStart) * 1000);
        curl_close($ch);

        if ($curlErr) {
            ai_qa_debug_log('AI QA Gemini curl error', [
                'model'      => $model,
                'curl_error' => $curlErr,
                'elapsed_ms' => $elapsedMs,
            ], 'warning');
            return null;
        }
        if ($httpCode === 404) {
            ai_qa_debug_log('AI QA Gemini 404 — fallback to next model', ['model' => $model]);
            continue;
        }
        if ($httpCode !== 200) {
            ai_qa_debug_log('AI QA Gemini non-200 response', [
                'model'      => $model,
                'http_code'  => $httpCode,
                'elapsed_ms' => $elapsedMs,
                'body'       => mb_substr($raw, 0, 400),
            ], 'warning');
            return null;
        }

        $resp = json_decode($raw, true);
        $text = (string)($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/```\s*$/m', '', $text);
        $text = trim($text);
        $parsed = json_decode($text, true);
        // Fallback: ถ้า direct parse fail (เช่น Gemini ใส่ preamble "Here is..."),
        // ลองหา { ... } แรกในข้อความ
        if (!is_array($parsed)) {
            $start = strpos($text, '{');
            $end   = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $parsed = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }
        if (!is_array($parsed)) {
            // log debug ข้อมูลละเอียดเมื่อ parse fail — finishReason/safety/blockReason
            // ช่วย diagnose ทั้งกรณี text ว่าง (safety/MAX_TOKENS) และเจอ preamble
            ai_qa_debug_log('AI QA Gemini parse error — raw text not JSON', [
                'model'         => $model,
                'raw_snippet'   => mb_substr($text, 0, 200),
                'finish_reason' => (string)($resp['candidates'][0]['finishReason'] ?? ''),
                'block_reason'  => (string)($resp['promptFeedback']['blockReason'] ?? ''),
                'safety_ratings' => $resp['candidates'][0]['safetyRatings'] ?? [],
                'response_snippet' => mb_substr($raw, 0, 500),
                'elapsed_ms'    => $elapsedMs,
            ], 'warning');
            return null;
        }

        $idx  = (int)($parsed['match_index'] ?? -1);
        $conf = (float)($parsed['confidence'] ?? 0);

        if ($idx < 0 || $idx >= count($candidates) || $conf < 0.7) {
            ai_qa_debug_log('AI QA Gemini rejected — no high-confidence match', [
                'model'         => $model,
                'match_index'   => $idx,
                'confidence'    => $conf,
                'pool_size'     => count($candidates),
                'elapsed_ms'    => $elapsedMs,
            ]);
            return null;
        }

        $matched = $candidates[$idx];
        ai_qa_debug_log('AI QA Gemini MATCH', [
            'model'      => $model,
            'index'      => $idx,
            'src'        => $matched['src'],
            'source_id'  => (int)$matched['id'],
            'confidence' => $conf,
            'elapsed_ms' => $elapsedMs,
        ]);
        return ai_qa_refresh_if_stale([
            'answer'      => (string)$matched['answer'],
            'category'    => (string)($matched['category'] ?? 'อื่นๆ'),
            'matched_via' => 'gemini_' . $matched['src'],
            'source_id'   => (int)$matched['id'],
            'confidence'  => $conf,
        ], $pdo, $question);
    }
    ai_qa_debug_log('AI QA Gemini exhausted all models — no match', [], 'warning');

    return null;
}
