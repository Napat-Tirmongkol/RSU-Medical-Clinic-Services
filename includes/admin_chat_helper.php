<?php
/**
 * includes/admin_chat_helper.php
 * Backend สำหรับ AI Admin Chat (RAG-style MVP)
 *
 * - Auto-migrate 2 ตาราง: sys_admin_chat_threads + sys_admin_chat_messages
 * - Build read-only context จาก dashboard_resolve_data() + clinic_status_helper
 * - PHI scrub layer ก่อนใส่ Gemini context
 * - Wrap Gemini call + save message + telemetry
 *
 * ดู AI/knowledge/admin-bot-data-sources.md สำหรับรายการ data source ที่ใช้
 */

declare(strict_types=1);

require_once __DIR__ . '/ai_qa_helper.php';       // ai_qa_load_gemini_key()
require_once __DIR__ . '/ai_telemetry_helper.php';
require_once __DIR__ . '/dashboard_data_sources.php';
require_once __DIR__ . '/clinic_status_helper.php';

// ──────────────────────────────────────────────────────────────────
// SCHEMA — auto-migrate (idempotent)
// ──────────────────────────────────────────────────────────────────
function admin_chat_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sys_admin_chat_threads (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                title VARCHAR(255) NULL,
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                message_count INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_admin (admin_id, is_archived, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sys_admin_chat_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                thread_id INT UNSIGNED NOT NULL,
                role ENUM('admin','assistant','system') NOT NULL,
                content MEDIUMTEXT NOT NULL,
                context_keys_json JSON NULL,
                model VARCHAR(50) NULL,
                elapsed_ms INT NULL,
                error TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_thread (thread_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    } catch (PDOException $e) {
        error_log('[admin_chat] ensure_schema failed: ' . $e->getMessage());
    }
}

// ──────────────────────────────────────────────────────────────────
// THREADS — CRUD
// ──────────────────────────────────────────────────────────────────
function admin_chat_create_thread(PDO $pdo, int $adminId, ?string $title = null): int
{
    admin_chat_ensure_schema($pdo);
    $stmt = $pdo->prepare("INSERT INTO sys_admin_chat_threads (admin_id, title) VALUES (?, ?)");
    $stmt->execute([$adminId, $title]);
    return (int)$pdo->lastInsertId();
}

function admin_chat_list_threads(PDO $pdo, int $adminId, int $page = 1, int $perPage = 20, bool $includeArchived = false): array
{
    admin_chat_ensure_schema($pdo);
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    $where = "admin_id = ?";
    $params = [$adminId];
    if (!$includeArchived) {
        $where .= " AND is_archived = 0";
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_admin_chat_threads WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, title, is_archived, message_count, created_at, updated_at
        FROM sys_admin_chat_threads
        WHERE $where
        ORDER BY updated_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ];
}

function admin_chat_get_thread(PDO $pdo, int $threadId, int $adminId): ?array
{
    admin_chat_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM sys_admin_chat_threads WHERE id = ? AND admin_id = ?");
    $stmt->execute([$threadId, $adminId]);
    $thread = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$thread) return null;

    $msgStmt = $pdo->prepare("
        SELECT id, role, content, context_keys_json, model, elapsed_ms, error, created_at
        FROM sys_admin_chat_messages
        WHERE thread_id = ?
        ORDER BY created_at ASC, id ASC
    ");
    $msgStmt->execute([$threadId]);
    $thread['messages'] = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
    return $thread;
}

function admin_chat_archive_thread(PDO $pdo, int $threadId, int $adminId, bool $archive = true): bool
{
    admin_chat_ensure_schema($pdo);
    $stmt = $pdo->prepare("UPDATE sys_admin_chat_threads SET is_archived = ? WHERE id = ? AND admin_id = ?");
    return $stmt->execute([$archive ? 1 : 0, $threadId, $adminId]);
}

function admin_chat_delete_thread(PDO $pdo, int $threadId, int $adminId): bool
{
    admin_chat_ensure_schema($pdo);
    // Verify ownership before delete
    $check = $pdo->prepare("SELECT id FROM sys_admin_chat_threads WHERE id = ? AND admin_id = ?");
    $check->execute([$threadId, $adminId]);
    if (!$check->fetchColumn()) return false;

    $pdo->prepare("DELETE FROM sys_admin_chat_messages WHERE thread_id = ?")->execute([$threadId]);
    $pdo->prepare("DELETE FROM sys_admin_chat_threads WHERE id = ?")->execute([$threadId]);
    return true;
}

// ──────────────────────────────────────────────────────────────────
// CONTEXT BUILDER — RAG snapshot
// ──────────────────────────────────────────────────────────────────

/**
 * รายการ key จาก dashboard_resolve_data() ที่ปลอดภัยและให้ insight สูง
 * Verified against includes/dashboard_data_sources.php switch cases
 * ดู AI/knowledge/admin-bot-data-sources.md
 */
const ADMIN_CHAT_DASHBOARD_KEYS = [
    // Insurance MTI
    'mti_total_active', 'mti_expiring_30d', 'mti_breakdown_type', 'mti_breakdown_status',
    // Gold Card
    'gold_total', 'gold_approved', 'gold_pending_docs', 'gold_by_status',
    // Combined coverage
    'coverage_total',
    // Finance
    'finance_income_total', 'finance_expense_total', 'finance_balance',
    // Asset / Consumables
    'asset_total', 'asset_warranty_expiring_90d',
    'consumable_total', 'consumable_low_stock',
    // Scholarship
    'scholarship_bookings_total', 'scholarship_slots_open',
    // Campaigns / satisfaction
    'campaign_active', 'campaign_bookings_total', 'campaign_booking_rate',
    'satisfaction_avg_rating',
];

/**
 * Build context snapshot ที่ bot จะ "เห็น" — ส่งกลับเป็น array
 * พร้อม list ของ key ที่ใช้ (เพื่อ save ลง message log)
 */
function admin_chat_build_context(PDO $pdo): array
{
    $context = [];
    $keysUsed = [];

    // Clinic status (always include — high signal, low cost)
    try {
        $status = get_clinic_current_status($pdo);
        $context['clinic_status'] = $status;
        $keysUsed[] = 'clinic_status';
    } catch (Throwable $e) {
        error_log('[admin_chat] clinic_status fetch failed: ' . $e->getMessage());
    }

    // Today's hours
    try {
        $today = date('Y-m-d');
        $hours = get_clinic_hours_for_date($pdo, $today);
        if ($hours) {
            $context['hours_today'] = $hours;
            $keysUsed[] = 'hours_today';
        }
    } catch (Throwable $e) {
        error_log('[admin_chat] hours_today fetch failed: ' . $e->getMessage());
    }

    // Dashboard resolvers (whitelisted keys)
    foreach (ADMIN_CHAT_DASHBOARD_KEYS as $key) {
        try {
            $data = dashboard_resolve_data($pdo, $key, []);
            if ($data) {
                $context['dashboard'][$key] = $data;
                $keysUsed[] = $key;
            }
        } catch (Throwable $e) {
            error_log("[admin_chat] resolver '$key' failed: " . $e->getMessage());
        }
    }

    return [
        'context' => admin_chat_phi_scrub($context),
        'keys'    => $keysUsed,
        'built_at' => date('c'),
    ];
}

/**
 * PHI scrub — drop fields that name individuals
 * ปลอดภัยกับ aggregate keys อื่นๆ เช่น category_name, dept_name
 */
function admin_chat_phi_scrub(array $data): array
{
    // ห้ามรวม 'name' เพราะ aggregate breakdown ใช้ key 'name' เป็น label
    // (เช่น category_name สำหรับหมวดบัญชี, dept name) — drop เฉพาะ person-specific
    $dropExact = [
        // person names
        'full_name', 'first_name', 'last_name', 'patient_name', 'student_name',
        'staff_name', 'nurse_name', 'doctor_name', 'customer_name',
        'username', 'staff_username',
        'actor', 'actor_name', 'performed_by_name', 'created_by_name',
        'created_by', 'updated_by',
        // identifiers
        'citizen_id', 'hn', 'national_id', 'id_card', 'passport',
        'student_code', 'line_user_id', 'line_user_hash',
        // contact
        'phone', 'mobile', 'tel', 'email', 'address',
        'birthdate', 'dob',
        // tracking metadata
        'ip_addr', 'ip_address', 'user_agent', 'ua',
    ];
    $walker = function ($node) use (&$walker, $dropExact) {
        if (!is_array($node)) return $node;
        $out = [];
        foreach ($node as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), $dropExact, true)) continue;
            $out[$k] = is_array($v) ? $walker($v) : $v;
        }
        return $out;
    };
    return $walker($data);
}

// ──────────────────────────────────────────────────────────────────
// GEMINI CALL — chat-style (multi-turn, text response)
// ──────────────────────────────────────────────────────────────────

function admin_chat_build_system_prompt(array $contextBundle): string
{
    $ctxJson = json_encode($contextBundle['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $builtAt = $contextBundle['built_at'] ?? date('c');

    return "คุณคือ AI ผู้ช่วยข้อมูลของระบบบริหารคลินิก RSU Medical Clinic Services\n"
        . "ตอบเป็นภาษาไทย กระชับ ตรงประเด็น ใช้ตัวเลขจาก context ที่ให้เท่านั้น\n"
        . "ถ้าข้อมูลไม่พอ ให้บอกว่า \"ข้อมูลไม่ครบ\" — ห้ามเดาตัวเลข\n"
        . "ใส่หน่วย (บาท / คน / ชิ้น) ทุกครั้งที่ตอบเป็นตัวเลข\n"
        . "ถ้าคำถามถามเรื่องคนหรือชื่อ-นามสกุล ให้ตอบว่า \"ไม่สามารถเปิดเผยข้อมูลส่วนบุคคล\"\n"
        . "\n"
        . "## บริบทระบบ ณ ตอนนี้ (สร้างเมื่อ $builtAt)\n"
        . "```json\n$ctxJson\n```\n";
}

/**
 * เรียก Gemini แบบ chat multi-turn
 * @param array $history [{ role: 'admin'|'assistant', content: string }, ...]
 * @return array{answer:string, model:string, elapsed_ms:int}
 * @throws RuntimeException
 */
function admin_chat_call_gemini(string $systemPrompt, array $history, string $userMessage): array
{
    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) throw new RuntimeException('ยังไม่ได้ตั้งค่า GEMINI_API_KEY');

    // Build contents: system + history + latest user message
    $contents = [
        ['role' => 'user', 'parts' => [['text' => $systemPrompt]]],
        ['role' => 'model', 'parts' => [['text' => 'รับทราบ พร้อมตอบคำถามจากข้อมูลที่ให้ไว้']]],
    ];
    foreach ($history as $msg) {
        if (!isset($msg['role'], $msg['content'])) continue;
        $role = ($msg['role'] === 'assistant') ? 'model' : 'user';
        $contents[] = ['role' => $role, 'parts' => [['text' => (string)$msg['content']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

    $body = json_encode([
        'contents' => $contents,
        'generationConfig' => [
            'temperature'     => 0.3,
            'maxOutputTokens' => 1500,
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $models = ['gemini-2.5-flash', 'gemini-2.0-flash'];
    $usedModel = '';
    $answer = '';
    $start = microtime(true);

    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            error_log("[admin_chat] gemini $model failed (HTTP $code): " . ($err ?: substr((string)$resp, 0, 200)));
            continue;
        }
        $json = json_decode((string)$resp, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            error_log("[admin_chat] gemini $model returned empty text");
            continue;
        }
        $answer = trim($text);
        $usedModel = $model;
        break;
    }

    $elapsed = (int)round((microtime(true) - $start) * 1000);

    if ($answer === '') {
        throw new RuntimeException('Gemini ทุก fallback ไม่ตอบ — ดู error log');
    }
    return ['answer' => $answer, 'model' => $usedModel, 'elapsed_ms' => $elapsed];
}

// ──────────────────────────────────────────────────────────────────
// MAIN ENTRY — send message + persist
// ──────────────────────────────────────────────────────────────────

/**
 * รับคำถามจาก admin → build context → call Gemini → save 2 messages → คืน assistant reply
 *
 * @return array{thread_id:int, admin_msg_id:int, assistant_msg_id:int, answer:string, model:string, elapsed_ms:int, error?:string}
 */
function admin_chat_send_message(PDO $pdo, int $threadId, int $adminId, string $userMessage): array
{
    admin_chat_ensure_schema($pdo);

    // Ownership check
    $own = $pdo->prepare("SELECT id FROM sys_admin_chat_threads WHERE id = ? AND admin_id = ?");
    $own->execute([$threadId, $adminId]);
    if (!$own->fetchColumn()) {
        throw new RuntimeException('Thread ไม่พบหรือไม่มีสิทธิ์');
    }

    $userMessage = trim($userMessage);
    if ($userMessage === '') throw new RuntimeException('ข้อความว่าง');
    if (mb_strlen($userMessage) > 4000) throw new RuntimeException('ข้อความยาวเกิน 4,000 ตัวอักษร');

    // Save admin message first
    $insAdmin = $pdo->prepare("INSERT INTO sys_admin_chat_messages (thread_id, role, content) VALUES (?, 'admin', ?)");
    $insAdmin->execute([$threadId, $userMessage]);
    $adminMsgId = (int)$pdo->lastInsertId();

    // Load last N messages (skip the one we just saved) as conversation history
    $histStmt = $pdo->prepare("
        SELECT role, content FROM sys_admin_chat_messages
        WHERE thread_id = ? AND id < ? AND role IN ('admin','assistant')
        ORDER BY id DESC LIMIT 10
    ");
    $histStmt->execute([$threadId, $adminMsgId]);
    $history = array_reverse($histStmt->fetchAll(PDO::FETCH_ASSOC));

    // Build fresh context
    $bundle = admin_chat_build_context($pdo);
    $systemPrompt = admin_chat_build_system_prompt($bundle);

    // Call Gemini
    $answer = '';
    $model = '';
    $elapsed = 0;
    $errorMsg = null;
    try {
        ai_telemetry_log($pdo, 'gemini_call', [
            'source' => 'admin_chat',
            'meta'   => ['thread_id' => $threadId, 'question_len' => mb_strlen($userMessage)],
        ]);
        $res = admin_chat_call_gemini($systemPrompt, $history, $userMessage);
        $answer = $res['answer'];
        $model = $res['model'];
        $elapsed = $res['elapsed_ms'];
        ai_telemetry_log($pdo, 'gemini_success', [
            'source'     => 'admin_chat',
            'model'      => $model,
            'elapsed_ms' => $elapsed,
        ]);
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        ai_telemetry_log($pdo, 'gemini_fail', [
            'source'    => 'admin_chat',
            'error_msg' => $errorMsg,
        ]);
        $answer = "เกิดข้อผิดพลาดในการเรียก AI: " . $errorMsg;
    }

    // Save assistant message
    $insAss = $pdo->prepare("
        INSERT INTO sys_admin_chat_messages (thread_id, role, content, context_keys_json, model, elapsed_ms, error)
        VALUES (?, 'assistant', ?, ?, ?, ?, ?)
    ");
    $insAss->execute([
        $threadId,
        $answer,
        json_encode($bundle['keys'], JSON_UNESCAPED_UNICODE),
        $model ?: null,
        $elapsed ?: null,
        $errorMsg,
    ]);
    $assistantMsgId = (int)$pdo->lastInsertId();

    // Bump thread counters + auto-title from first user message
    $pdo->prepare("
        UPDATE sys_admin_chat_threads
        SET message_count = (SELECT COUNT(*) FROM sys_admin_chat_messages WHERE thread_id = ?),
            title = COALESCE(NULLIF(title,''), ?)
        WHERE id = ?
    ")->execute([
        $threadId,
        mb_substr($userMessage, 0, 80),
        $threadId,
    ]);

    return [
        'thread_id'         => $threadId,
        'admin_msg_id'      => $adminMsgId,
        'assistant_msg_id'  => $assistantMsgId,
        'answer'            => $answer,
        'model'             => $model,
        'elapsed_ms'        => $elapsed,
        'error'             => $errorMsg,
    ];
}
