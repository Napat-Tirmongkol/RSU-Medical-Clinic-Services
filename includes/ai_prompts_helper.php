<?php
/**
 * includes/ai_prompts_helper.php
 *
 * Editable AI prompts — เก็บใน DB ให้ admin แก้ผ่าน portal ได้
 * ถ้า DB ยังไม่มี/error → fallback ไปใช้ default ที่ hard-code ไว้
 *
 * Pipeline:
 *   1. คน/PHP เรียก get_ai_prompt(KEY, $vars)
 *   2. helper อ่าน sys_ai_prompts → ถ้ามี content ใช้ตัวนั้น ไม่งั้น default
 *   3. แทนที่ {placeholder} ด้วย $vars[name]
 *   4. คืน prompt ที่ resolve แล้ว
 */
declare(strict_types=1);

const AI_PROMPT_DEFAULTS = [
    'matcher' => [
        'label'        => 'Matcher — จับคู่คำถามกับคำตอบที่อนุมัติแล้ว',
        'description'  => 'LINE webhook ใช้ prompt นี้ส่งให้ Gemini เลือกว่าคำถามของ user ตรงกับ FAQ/approved answer ตัวไหนใน pool. ถ้าไม่ตรงพอ (confidence < 0.7) → ตกไป default reply',
        'placeholders' => [
            'question'        => 'คำถามที่ user ส่งมา',
            'candidates_list' => 'รายการคำถาม index + ข้อความ คั่นด้วย newline',
        ],
        'content' => <<<'TXT'
คุณกำลังจับคู่คำถามจาก user กับคำถามที่ห้องพยาบาลคลินิก RSU Medical มีคำตอบไว้แล้ว

คำถามจาก user:
{question}

รายการคำถามที่มีคำตอบ:
{candidates_list}

ตอบเป็น JSON ตาม schema นี้เท่านั้น:
{"match_index": <-1 หรือเลขในรายการ>, "confidence": <0.0-1.0>}

กฎ:
- ตอบ -1 ถ้าไม่มีคำถามไหนใกล้เคียงพอ (ความมั่นใจต่ำกว่า 0.7)
- ตอบเลข index ถ้าเจอคำถามที่ความหมายเดียวกัน — แม้พิมพ์ต่าง สะกดผิด ภาษาต่าง
- คำถามต่างหัวข้อกัน → -1
TXT,
    ],

    'generator' => [
        'label'        => 'Generator — สร้าง draft คำตอบจาก clinic context',
        'description'  => 'AI QA Lab ใช้ prompt นี้เมื่อ admin กด Generate. AI ดึง context (เวลาเปิด-ปิด/ตารางหมอ/FAQ) มาประกอบคำตอบ + จัดหมวดหมู่ + ให้ confidence',
        'placeholders' => [
            'question'        => 'คำถามที่ admin จะให้ AI ร่างคำตอบ',
            'clinic_context'  => 'ข้อมูลคลินิกจริง (build_clinic_context) — เวลา/ตารางหมอ/FAQ',
            'categories_list' => 'รายชื่อหมวดที่อนุญาต คั่นด้วย " | "',
        ],
        'content' => <<<'TXT'
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

{clinic_context}

═══════════════════

หมวดหมู่ที่อนุญาต (เลือก 1 หมวดเท่านั้น): {categories_list}
หมายเหตุ: category ใช้ภาษาไทยตาม list เสมอ — แม้คำตอบจะเป็นภาษาอังกฤษ

ตอบกลับเป็น JSON ตาม schema นี้เท่านั้น:
{
  "category": "ชื่อหมวดจาก list ด้านบน (ภาษาไทย)",
  "answer": "คำตอบในภาษาเดียวกับคำถาม สุภาพ กระชับ 2-4 ประโยค (อิงจาก context จริง)",
  "confidence": 0.0 ถึง 1.0
}

คำถามจากผู้ใช้:
{question}
TXT,
    ],
];

function ensure_ai_prompts_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_prompts (
            prompt_key VARCHAR(64) PRIMARY KEY,
            content TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // ประวัติทุกครั้งที่ save — เก็บล่าสุด 50 versions / key
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_prompts_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prompt_key VARCHAR(64) NOT NULL,
            content TEXT NOT NULL,
            saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            saved_by INT UNSIGNED NULL,
            INDEX idx_key_time (prompt_key, saved_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Throwable $e) {
        error_log('ensure_ai_prompts_schema failed: ' . $e->getMessage());
    }
}

/**
 * อ่าน prompt content (string) จาก DB ถ้าไม่มีใช้ default
 * แทนที่ {placeholder} ด้วยค่าใน $vars
 */
function get_ai_prompt(string $key, array $vars = []): string
{
    static $cache = [];

    if (!isset(AI_PROMPT_DEFAULTS[$key])) {
        throw new InvalidArgumentException("Unknown prompt key: {$key}");
    }

    if (!isset($cache[$key])) {
        $content = AI_PROMPT_DEFAULTS[$key]['content'];
        try {
            $pdo = function_exists('db') ? db() : null;
            if ($pdo) {
                ensure_ai_prompts_schema($pdo);
                $stmt = $pdo->prepare("SELECT content FROM sys_ai_prompts WHERE prompt_key = :k LIMIT 1");
                $stmt->execute([':k' => $key]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && trim((string)$row['content']) !== '') {
                    $content = (string)$row['content'];
                }
            }
        } catch (Throwable $e) {
            error_log("get_ai_prompt fetch failed for {$key}: " . $e->getMessage());
        }
        $cache[$key] = $content;
    }

    $resolved = $cache[$key];
    foreach ($vars as $name => $value) {
        $resolved = str_replace('{' . $name . '}', (string)$value, $resolved);
    }
    return $resolved;
}

/** อ่าน prompts ทั้งหมด พร้อม metadata + ค่า DB ปัจจุบัน — ใช้ใน portal page */
function list_ai_prompts(PDO $pdo): array
{
    ensure_ai_prompts_schema($pdo);
    $rows = [];
    try {
        $stmt = $pdo->query("SELECT prompt_key, content, updated_at FROM sys_ai_prompts");
        foreach ($stmt as $r) {
            $rows[$r['prompt_key']] = $r;
        }
    } catch (Throwable $e) {
        error_log('list_ai_prompts failed: ' . $e->getMessage());
    }

    $out = [];
    foreach (AI_PROMPT_DEFAULTS as $key => $meta) {
        $dbRow = $rows[$key] ?? null;
        $isCustom = $dbRow && trim((string)$dbRow['content']) !== '';
        $out[] = [
            'key'          => $key,
            'label'        => $meta['label'],
            'description'  => $meta['description'],
            'placeholders' => $meta['placeholders'],
            'default'      => $meta['content'],
            'content'      => $isCustom ? (string)$dbRow['content'] : $meta['content'],
            'is_custom'    => (bool)$isCustom,
            'updated_at'   => $dbRow['updated_at'] ?? null,
            'samples'      => AI_PROMPT_TEST_SAMPLES[$key] ?? [],
        ];
    }
    return $out;
}

function save_ai_prompt(PDO $pdo, string $key, string $content, ?int $adminId = null): bool
{
    if (!isset(AI_PROMPT_DEFAULTS[$key])) return false;
    ensure_ai_prompts_schema($pdo);
    try {
        // 1) snapshot ค่าปัจจุบัน (ถ้ามี) เข้า history ก่อน — ให้ rollback ได้
        $cur = $pdo->prepare("SELECT content, updated_by FROM sys_ai_prompts WHERE prompt_key = :k LIMIT 1");
        $cur->execute([':k' => $key]);
        $curRow = $cur->fetch(PDO::FETCH_ASSOC);
        if ($curRow && trim((string)$curRow['content']) !== '' && (string)$curRow['content'] !== $content) {
            $hist = $pdo->prepare("
                INSERT INTO sys_ai_prompts_history (prompt_key, content, saved_by)
                VALUES (:k, :c, :u)
            ");
            $hist->execute([
                ':k' => $key,
                ':c' => (string)$curRow['content'],
                ':u' => $curRow['updated_by'] !== null ? (int)$curRow['updated_by'] : null,
            ]);

            // 2) auto-prune — เก็บล่าสุด 50 versions / key
            $pdo->prepare("
                DELETE FROM sys_ai_prompts_history
                WHERE prompt_key = :k
                  AND id NOT IN (
                    SELECT id FROM (
                      SELECT id FROM sys_ai_prompts_history
                      WHERE prompt_key = :k2
                      ORDER BY saved_at DESC LIMIT 50
                    ) AS keep_set
                  )
            ")->execute([':k' => $key, ':k2' => $key]);
        }

        // 3) upsert ค่าใหม่
        $stmt = $pdo->prepare("
            INSERT INTO sys_ai_prompts (prompt_key, content, updated_by)
            VALUES (:k, :c, :u)
            ON DUPLICATE KEY UPDATE content = VALUES(content), updated_by = VALUES(updated_by)
        ");
        return $stmt->execute([':k' => $key, ':c' => $content, ':u' => $adminId]);
    } catch (Throwable $e) {
        error_log("save_ai_prompt failed for {$key}: " . $e->getMessage());
        return false;
    }
}

/** ดู history versions ของ prompt key (สูงสุด 50 ล่าสุด) */
function list_ai_prompt_history(PDO $pdo, string $key, int $limit = 50): array
{
    if (!isset(AI_PROMPT_DEFAULTS[$key])) return [];
    ensure_ai_prompts_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT h.id, h.content, h.saved_at, h.saved_by, u.full_name AS saved_by_name
              FROM sys_ai_prompts_history h
              LEFT JOIN sys_users u ON u.id = h.saved_by
             WHERE h.prompt_key = :k
             ORDER BY h.saved_at DESC
             LIMIT " . max(1, min(100, $limit))
        );
        $stmt->execute([':k' => $key]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // ลอง fallback ถ้า join sys_users fail (ไม่มี table หรือไม่มี column)
        try {
            $stmt = $pdo->prepare("
                SELECT id, content, saved_at, saved_by
                  FROM sys_ai_prompts_history
                 WHERE prompt_key = :k
                 ORDER BY saved_at DESC
                 LIMIT " . max(1, min(100, $limit))
            );
            $stmt->execute([':k' => $key]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) $r['saved_by_name'] = null;
            return $rows;
        } catch (Throwable $e2) {
            error_log("list_ai_prompt_history failed for {$key}: " . $e2->getMessage());
            return [];
        }
    }
}

/** rollback prompt ไป version ที่ระบุ — สร้าง history snapshot ของค่าปัจจุบันด้วย */
function rollback_ai_prompt(PDO $pdo, int $historyId, ?int $adminId = null): bool
{
    ensure_ai_prompts_schema($pdo);
    try {
        $stmt = $pdo->prepare("SELECT prompt_key, content FROM sys_ai_prompts_history WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $historyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        // save_ai_prompt() snapshot current ลง history แล้วเขียนค่าใหม่
        return save_ai_prompt($pdo, (string)$row['prompt_key'], (string)$row['content'], $adminId);
    } catch (Throwable $e) {
        error_log("rollback_ai_prompt failed for id={$historyId}: " . $e->getMessage());
        return false;
    }
}

/** ค่า sample สำหรับ test sandbox — pre-fill ตอนกด ทดสอบ ในแต่ละ prompt */
const AI_PROMPT_TEST_SAMPLES = [
    'matcher' => [
        'question'        => 'วันอาทิตย์เปิดไหมครับ',
        'candidates_list' => "[0] วันอาทิตย์คลินิกเปิดไหม\n[1] ตารางออกตรวจของหมอ\n[2] ฉีดวัคซีน HPV ราคาเท่าไหร่",
    ],
    'generator' => [
        'question'        => 'วันอาทิตย์เปิดไหมครับ',
        'clinic_context'  => "ข้อมูลคลินิก:\n- จันทร์-ศุกร์ 08:00-20:00\n- เสาร์ 09:00-15:00\n- อาทิตย์ ปิด\n(วันนี้เป็นวันเสาร์)",
        'categories_list' => 'เวลาเปิด-ปิด | ตารางหมอ | บริการ | ราคา | อื่นๆ',
    ],
];

/**
 * Gemini generationConfig ของแต่ละ prompt key — ต้องตรงกับ production
 * ที่ใช้ใน ai_qa_helper.php (matcher = strict schema, generator = JSON mime)
 */
function get_test_generation_config_for(string $key): array
{
    return match ($key) {
        'matcher' => [
            'temperature'      => 0.2,
            'maxOutputTokens'  => 1024,
            'responseMimeType' => 'application/json',
            'responseSchema'   => [
                'type'       => 'OBJECT',
                'properties' => [
                    'match_index' => ['type' => 'INTEGER'],
                    'confidence'  => ['type' => 'NUMBER'],
                ],
                'required'   => ['match_index', 'confidence'],
            ],
            'thinkingConfig'   => ['thinkingBudget' => 0],
        ],
        'generator' => [
            'temperature'      => 0.3,
            'maxOutputTokens'  => 1024,
            'responseMimeType' => 'application/json',
        ],
        default => [
            'temperature'     => 0.3,
            'maxOutputTokens' => 1024,
        ],
    };
}

/**
 * ทดสอบ prompt — resolve placeholders + ยิง Gemini จริง + คืน response
 * + parse JSON + meta (model, elapsed_ms, finishReason, usage)
 *
 * ไม่ save อะไรลง DB — ใช้ดู output ก่อน admin กด save จริง
 */
function test_ai_prompt(string $key, string $content, array $vars): array
{
    if (!isset(AI_PROMPT_DEFAULTS[$key])) {
        throw new InvalidArgumentException("Unknown prompt key: {$key}");
    }
    require_once __DIR__ . '/ai_qa_helper.php';

    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) {
        return ['ok' => false, 'error' => 'GEMINI_API_KEY ยังไม่ได้ตั้งค่า'];
    }

    // Resolve placeholders (เหมือน get_ai_prompt)
    $resolved = $content;
    foreach ($vars as $name => $value) {
        $resolved = str_replace('{' . $name . '}', (string)$value, $resolved);
    }

    $body = json_encode([
        'contents' => [['role' => 'user', 'parts' => [['text' => $resolved]]]],
        'generationConfig' => get_test_generation_config_for($key),
    ], JSON_UNESCAPED_UNICODE);

    $model = 'gemini-2.5-flash';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $tStart   = microtime(true);
    $raw      = (string)curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    $elapsedMs = (int)round((microtime(true) - $tStart) * 1000);
    curl_close($ch);

    if ($curlErr) {
        return [
            'ok'              => false,
            'error'           => 'curl: ' . $curlErr,
            'resolved_prompt' => $resolved,
            'elapsed_ms'      => $elapsedMs,
        ];
    }
    if ($httpCode !== 200) {
        return [
            'ok'              => false,
            'error'           => "HTTP {$httpCode}",
            'response_raw'    => mb_substr($raw, 0, 2000),
            'resolved_prompt' => $resolved,
            'elapsed_ms'      => $elapsedMs,
        ];
    }

    $resp = json_decode($raw, true);
    $text = (string)($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $finishReason = (string)($resp['candidates'][0]['finishReason'] ?? '');

    // ลอง parse JSON (matcher/generator คาดว่า output เป็น JSON)
    $parsed = null;
    if ($text !== '') {
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);
        $tryParsed = json_decode($cleaned, true);
        if (is_array($tryParsed)) {
            $parsed = $tryParsed;
        } else {
            $start = strpos($cleaned, '{');
            $end   = strrpos($cleaned, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $maybe = json_decode(substr($cleaned, $start, $end - $start + 1), true);
                if (is_array($maybe)) $parsed = $maybe;
            }
        }
    }

    return [
        'ok'              => true,
        'resolved_prompt' => $resolved,
        'response_text'   => $text,
        'parsed'          => $parsed,
        'finish_reason'   => $finishReason,
        'usage'           => $resp['usageMetadata'] ?? [],
        'elapsed_ms'      => $elapsedMs,
        'model'           => $model,
    ];
}

function reset_ai_prompt(PDO $pdo, string $key): bool
{
    if (!isset(AI_PROMPT_DEFAULTS[$key])) return false;
    ensure_ai_prompts_schema($pdo);
    try {
        $stmt = $pdo->prepare("DELETE FROM sys_ai_prompts WHERE prompt_key = :k");
        return $stmt->execute([':k' => $key]);
    } catch (Throwable $e) {
        error_log("reset_ai_prompt failed for {$key}: " . $e->getMessage());
        return false;
    }
}
