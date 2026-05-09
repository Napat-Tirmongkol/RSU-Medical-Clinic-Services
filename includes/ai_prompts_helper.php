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
        ];
    }
    return $out;
}

function save_ai_prompt(PDO $pdo, string $key, string $content, ?int $adminId = null): bool
{
    if (!isset(AI_PROMPT_DEFAULTS[$key])) return false;
    ensure_ai_prompts_schema($pdo);
    try {
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
