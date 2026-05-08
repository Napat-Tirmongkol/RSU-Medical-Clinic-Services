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
            reviewer_note TEXT NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status_created (status, created_at),
            INDEX idx_category (category),
            INDEX idx_source_created (source, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}
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
 * เรียก Gemini เพื่อจัดหมวด + ร่างคำตอบ — คืน array หรือ throw
 *
 * @return array{category:string, answer:string, confidence:float, model:string}
 */
function ai_qa_generate_answer(string $question): array
{
    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า GEMINI_API_KEY');
    }

    $categoriesList = implode(' | ', AI_QA_CATEGORIES);

    $systemPrompt = <<<PROMPT
คุณคือผู้ช่วยตอบคำถามของห้องพยาบาลคลินิก RSU Medical
หน้าที่: รับคำถามจากผู้ใช้ → จัดหมวดหมู่ + ร่างคำตอบเบื้องต้นเป็นภาษาไทย
สำคัญ: คำตอบนี้จะถูก admin ตรวจสอบก่อนส่ง — อย่าใส่ข้อมูลเฉพาะเจาะจง (ตัวเลข, วันที่, ชื่อหมอ) ที่คุณไม่แน่ใจ ให้ใช้ placeholder เช่น "(ขอข้อมูลจากเจ้าหน้าที่)" แทน

หมวดหมู่ที่อนุญาต (เลือก 1 หมวดเท่านั้น): {$categoriesList}

ตอบกลับเป็น JSON ตาม schema นี้เท่านั้น:
{
  "category": "ชื่อหมวดจาก list ด้านบน",
  "answer": "คำตอบภาษาไทย สุภาพ กระชับ 2-4 ประโยค",
  "confidence": 0.0 ถึง 1.0 (ความมั่นใจในคำตอบ — ถ้าคำถามคลุมเครือให้ต่ำลง)
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
- ภาษาไทยเท่านั้น
- ความหมายเดิม แต่รูปประโยคต่างกัน — เช่น ทางการ vs ไม่ทางการ, สั้น vs ยาว, ถามตรง vs อ้อม
- ห้ามซ้ำกับต้นฉบับ และห้ามซ้ำกันเอง
- เป็นคำถามที่ผู้ใช้น่าจะพิมพ์จริงในแชท

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
