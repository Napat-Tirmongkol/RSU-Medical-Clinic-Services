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
    $result = ai_qa_generate_answer($question, $pdo, $now);
    $elapsed = round((microtime(true) - $t0) * 1000);

    // ── 4. ดึง context preview (truncate 1500 chars) ─────────────────────
    $contextFull = ai_qa_build_clinic_context($pdo, $now, $question);
    $contextPreview = mb_substr($contextFull, 0, 1500) . (mb_strlen($contextFull) > 1500 ? "\n…(ตัดแสดง)" : '');

    echo json_encode([
        'ok'          => true,
        'answer'      => $result['answer'],
        'category'    => $result['category']   ?? 'อื่นๆ',
        'confidence'  => $result['confidence'] ?? null,
        'model'       => $result['model']       ?? '',
        'elapsed_ms'  => $elapsed,
        'matched_faq' => $faqMatch !== null,
        'matched_via' => $matchedVia,
        'faq_answer'  => $faqMatch ? ($faqMatch['answer'] ?? null) : null,
        'chunks'      => array_map(fn($c) => [
            'title'           => $c['title'],
            'score'           => $c['score'],
            'content_preview' => mb_substr($c['content_preview'] ?? '', 0, 200),
            'source_label'    => $c['source_label'],
        ], $chunks),
        'context_chars'   => mb_strlen($contextFull),
        'context_preview' => $contextPreview,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[ajax_ai_sandbox] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
