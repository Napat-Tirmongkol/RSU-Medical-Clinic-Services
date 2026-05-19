<?php
/**
 * portal/ajax_ai_sandbox.php — Sandbox ทดสอบ AI Q&A
 *
 * POST action=ask : ถามคำถาม → คืน answer + debug info (chunks, context, matched_faq)
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ai_qa_helper.php';
require_once __DIR__ . '/../includes/ai_chunk_helper.php';
require_once __DIR__ . '/../includes/clinic_status_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

$_role = $_SESSION['admin_role'] ?? '';
if ($_role !== 'superadmin' && empty($_SESSION['access_ai'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied (access_ai required)']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$question = trim((string)($_POST['question'] ?? ''));
if ($question === '') {
    echo json_encode(['ok' => false, 'error' => 'กรอกคำถามก่อน']);
    exit;
}

$pdo = db();
$tz  = new DateTimeZone(CLINIC_TZ_NAME);
$now = new DateTimeImmutable('now', $tz);

try {
    $t0 = microtime(true);

    // ── 1. ตรวจว่า match FAQ ก่อนหรือเปล่า ─────────────────────────────
    $faqMatch   = null;
    $matchedVia = null;
    try {
        $faqMatch = ai_qa_match_faq($pdo, $question);
        if ($faqMatch) {
            $matchedVia = $faqMatch['matched_via'] ?? 'faq';
        }
    } catch (Throwable $e) {
        error_log('[sandbox faq_match] ' . $e->getMessage());
    }

    // ── 2. ดึง chunks ที่เกี่ยวข้อง (semantic search debug) ─────────────
    $chunks = [];
    try {
        $chunks = chunk_semantic_search($pdo, $question, 5);
    } catch (Throwable $e) {
        // no embedding key or no chunks — ไม่ error ออก
    }

    // ── 3. Generate คำตอบ ────────────────────────────────────────────────
    // Don't blow up the whole sandbox response if Gemini errors — if we
    // matched an FAQ above, surface that so the dev still sees a useful
    // result. Generator errors are captured in `generator_error` for the UI.
    $result = ['answer' => '', 'category' => 'อื่นๆ', 'confidence' => null, 'model' => ''];
    $generatorError = null;
    try {
        $result = ai_qa_generate_answer($question, $pdo, $now);
    } catch (Throwable $e) {
        $generatorError = $e->getMessage();
        error_log('[sandbox generate] ' . $e->getMessage());
        if ($faqMatch !== null) {
            $result = [
                'answer'     => (string)($faqMatch['answer'] ?? ''),
                'category'   => (string)($faqMatch['category'] ?? 'อื่นๆ'),
                'confidence' => (float)($faqMatch['confidence'] ?? 0.8),
                'model'      => 'faq_fallback',
            ];
        }
    }
    $elapsed = round((microtime(true) - $t0) * 1000);

    // ── 4. ดึง context preview (ส่งเต็มเพื่อให้ debug schedule ได้) ───
    $contextFull = '';
    try {
        $contextFull = ai_qa_build_clinic_context($pdo, $now, $question);
    } catch (Throwable $e) {
        error_log('[sandbox build_context] ' . $e->getMessage());
        $contextFull = '(build_clinic_context error: ' . $e->getMessage() . ')';
    }
    $contextPreview = $contextFull;

    // ── 4.5. ดึงตารางหมอวันที่ใกล้เคียง (raw) เพื่อ debug ───────────────
    // Two views per day:
    //   raw      — ignore clinic closures, surface every regular shift in
    //              sys_doctor_schedule (useful when admin needs to see why
    //              a shift exists at all).
    //   effective— the value AI actually sees: zero rows on a closed day
    //              even if the recurring schedule says otherwise.
    // Surfacing both lets the operator spot "schedule says X but clinic is
    // closed Y" mismatches without us having to guess intent.
    $today = $now->format('Y-m-d');
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $debugSchedule = [];
    foreach ([$today, $tomorrow] as $d) {
        $effective = get_clinic_doctors_for_date($pdo, $d);                // honours closures
        $raw       = get_clinic_doctors_for_date($pdo, $d, false);          // ignores closures
        $hours     = get_clinic_hours_for_date($pdo, $d);
        $debugSchedule[$d] = [
            'date'    => $d,
            'weekday' => (int)(new DateTimeImmutable($d, $tz))->format('w'),
            'count'   => count($effective),
            'closed'  => !empty($hours['closed']),
            'closure_note' => !empty($hours['closed']) ? trim((string)($hours['note'] ?? '')) : '',
            'raw_count'    => count($raw),
            'rows'         => array_map(fn($r) => [
                'staff_id'   => $r['staff_id'] ?? null,
                'doc_name'   => $r['doc_name'] ?? null,
                'doc_title'  => $r['doc_title'] ?? null,
                'type'       => $r['type'] ?? null,
                'start_time' => $r['start_time'] ?? null,
                'end_time'   => $r['end_time']   ?? null,
                'service'    => $r['service_type'] ?? null,
            ], $raw),
        ];
    }

    // ── 4.6. DB inventory เพื่อหาสาเหตุที่ count = 0 ────────────────────
    $debugInventory = [
        'total'        => 0,
        'active'       => 0,
        'by_type'      => [],
        'samples'      => [],
        'today_weekday'=> (int)(new DateTimeImmutable($today, $tz))->format('w'),
    ];
    try {
        $debugInventory['total']  = (int)$pdo->query("SELECT COUNT(*) FROM sys_doctor_schedule")->fetchColumn();
        $debugInventory['active'] = (int)$pdo->query("SELECT COUNT(*) FROM sys_doctor_schedule WHERE is_active = 1")->fetchColumn();
        $byType = $pdo->query("SELECT type, COUNT(*) c FROM sys_doctor_schedule WHERE is_active = 1 GROUP BY type")->fetchAll(PDO::FETCH_KEY_PAIR);
        $debugInventory['by_type'] = $byType ?: [];
        $samples = $pdo->query("
            SELECT s.id, s.type, s.weekday, s.specific_date, s.recur_end_date,
                   s.start_time, s.end_time, s.is_active, s.staff_id,
                   ms.full_name AS doc_name
              FROM sys_doctor_schedule s
              LEFT JOIN sys_medical_staff ms ON s.staff_id = ms.id
              ORDER BY s.id DESC LIMIT 15
        ")->fetchAll(PDO::FETCH_ASSOC);
        $debugInventory['samples'] = $samples ?: [];

        // ── ทดสอบ query เดียวกับ get_clinic_doctors_for_date() แบบ raw ───
        $todayW = (int)(new DateTimeImmutable($today, $tz))->format('w');
        $rawTest = $pdo->prepare("
            SELECT COUNT(*) FROM sys_doctor_schedule
             WHERE is_active = 1
               AND (
                   specific_date = :d
                   OR (
                       type = 'regular'
                       AND weekday = :wd
                       AND (recur_end_date IS NULL OR recur_end_date = '0000-00-00' OR recur_end_date >= :d2)
                   )
               )
        ");
        $rawTest->execute([':d' => $today, ':wd' => $todayW, ':d2' => $today]);
        $debugInventory['raw_query_today_count'] = (int)$rawTest->fetchColumn();

        // เช็คเฉพาะ regular ที่ weekday ตรง (ไม่สนใจ recur_end_date)
        $weekdayOnly = $pdo->prepare("
            SELECT COUNT(*) FROM sys_doctor_schedule
             WHERE is_active = 1 AND type = 'regular' AND weekday = :wd
        ");
        $weekdayOnly->execute([':wd' => $todayW]);
        $debugInventory['regular_weekday_match_count'] = (int)$weekdayOnly->fetchColumn();

        // เช็ค type ของ weekday column
        $colInfo = $pdo->query("SHOW COLUMNS FROM sys_doctor_schedule LIKE 'weekday'")->fetch(PDO::FETCH_ASSOC);
        $debugInventory['weekday_column_type'] = $colInfo['Type'] ?? 'unknown';

        // ── ทดสอบรัน query เดียวกับ function get_clinic_doctors_for_date เปะๆ ───
        // ถ้า raw test ผ่านแต่ตัวจริงไม่ผ่าน → ปัญหาน่าจะอยู่ที่ JOIN
        $debugInventory['exact_query_test'] = ['ok' => false, 'count' => 0, 'error' => null];
        try {
            $stmt = $pdo->prepare("
                SELECT s.id, s.staff_id, s.type, s.specific_date, s.weekday,
                       s.start_time, s.end_time, s.service_type, s.notes,
                       ms.title  AS doc_title,
                       ms.full_name AS doc_name,
                       ms.role,
                       cr.name   AS room_name,
                       cr.code   AS room_code
                FROM sys_doctor_schedule s
                LEFT JOIN sys_medical_staff ms ON s.staff_id = ms.id
                LEFT JOIN sys_clinic_rooms cr ON s.room_id = cr.id
                WHERE s.is_active = 1
                  AND (
                      s.specific_date = :d
                      OR (
                          s.type = 'regular'
                          AND s.weekday = :wd
                          AND (s.recur_end_date IS NULL OR s.recur_end_date = '0000-00-00' OR s.recur_end_date >= :d2)
                      )
                  )
            ");
            $stmt->execute([':d' => $today, ':wd' => $todayW, ':d2' => $today]);
            $exactRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $debugInventory['exact_query_test'] = [
                'ok'    => true,
                'count' => count($exactRows),
                'error' => null,
            ];
        } catch (Throwable $e) {
            $debugInventory['exact_query_test'] = [
                'ok'    => false,
                'count' => 0,
                'error' => 'Server error',
            ];
        }

        // เช็ค table sys_clinic_rooms และ column room_id
        $debugInventory['has_room_id_col']     = (bool)$pdo->query("SHOW COLUMNS FROM sys_doctor_schedule LIKE 'room_id'")->fetch();
        try {
            $pdo->query("SELECT 1 FROM sys_clinic_rooms LIMIT 1");
            $debugInventory['has_clinic_rooms_table'] = true;
        } catch (Throwable) {
            $debugInventory['has_clinic_rooms_table'] = false;
        }
    } catch (Throwable $e) {
        $debugInventory['error'] = $e->getMessage();
    }

    // matched_faq is true only when the answer actually came out of the
    // FAQ pool (exact / variant / approved / Gemini semantic-pick).
    // ai_qa_match_faq() also short-circuits to a fresh Gemini call for
    // time-sensitive questions and returns matched_via='generate_fresh'
    // — that's NOT a FAQ hit and the Sandbox shouldn't render the
    // "พบคำตอบตรงจาก FAQ Knowledge Base" card for it.
    $isFaqHit = $faqMatch !== null
        && in_array(($faqMatch['matched_via'] ?? ''), [
            'exact_canonical', 'exact_variant', 'exact_approved',
            'gemini_faq', 'gemini_qa',
        ], true);

    echo json_encode([
        'ok'              => true,
        'answer'          => $result['answer'],
        'category'        => $result['category']   ?? 'อื่นๆ',
        'confidence'      => $result['confidence'] ?? null,
        'model'           => $result['model']       ?? '',
        'elapsed_ms'      => $elapsed,
        'matched_faq'     => $isFaqHit,
        'matched_via'     => $matchedVia,
        'faq_answer'      => $isFaqHit ? ($faqMatch['answer'] ?? null) : null,
        'generator_error' => $generatorError,
        'chunks'          => array_map(fn($c) => [
            'title'           => $c['title'],
            'score'           => $c['score'],
            'content_preview' => mb_substr($c['content_preview'] ?? '', 0, 200),
            'source_label'    => $c['source_label'],
        ], $chunks),
        'context_chars'   => mb_strlen($contextFull),
        'context_preview' => $contextPreview,
        'debug_schedule'  => $debugSchedule,
        'debug_inventory' => $debugInventory,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[ajax_ai_sandbox] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    // Sandbox is admin-only (access_ai gate above) — surface the real
    // exception message so the dev can actually debug. Falling back to a
    // bare "Server error" makes the playground useless.
    echo json_encode([
        'ok'    => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
