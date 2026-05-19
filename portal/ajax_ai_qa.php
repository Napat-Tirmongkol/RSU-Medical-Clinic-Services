<?php
/**
 * portal/ajax_ai_qa.php — AI QA Lab AJAX endpoint
 *
 * Actions:
 * - generate           : เรียก Gemini ร่างคำตอบ + จัดหมวด (1 record)
 * - bulk_generate      : เรียก Gemini สำหรับหลาย record ที่ status='pending'
 * - update             : บันทึก review (status, edited answer, category, note)
 * - delete             : ลบ record
 * - classify_questions : ให้ AI คัดกรอง "ใช่/ไม่ใช่คำถามจริง" (batch บน rows is_question='unknown')
 * - mark_question      : admin override ผลคัดกรองรายกลุ่ม (yes/no/unknown)
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/ai_qa_helper.php';
require_once __DIR__ . '/../includes/clinic_status_helper.php'; // CLINIC_TZ_NAME for backdated context

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Authorization: ตรงกับ section gate ใน portal/index.php — superadmin หรือมี access_ai
$_role = $_SESSION['admin_role'] ?? '';
if ($_role !== 'superadmin' && empty($_SESSION['access_ai'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Permission denied (access_ai required)']);
    exit;
}

$pdo = db();
ensure_ai_qa_schema($pdo);

// Method-aware action lookup: read-only actions can come in via GET so
// the Overview dashboard can fetch them without CSRF. Mutating actions
// (everything else) still require POST + a valid CSRF token below.
$action     = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$reviewerId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);

// Read-only Phase C endpoints — return-and-exit before the POST/CSRF gate
if ($action === 'health_summary' || $action === 'telemetry_recent') {
    try {
        require_once __DIR__ . '/../includes/ai_telemetry_helper.php';
        require_once __DIR__ . '/../includes/ai_cache_helper.php';
        if ($action === 'health_summary') {
            $windowHours = max(1, min(168, (int)($_REQUEST['window_hours'] ?? 24)));
            echo json_encode([
                'ok'        => true,
                'telemetry' => ai_telemetry_summary($pdo, $windowHours),
                'cache'     => ai_cache_stats($pdo, 10),
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $limit = max(1, min(200, (int)($_REQUEST['limit'] ?? 50)));
            echo json_encode([
                'ok'   => true,
                'rows' => ai_telemetry_recent($pdo, $limit),
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        error_log('ajax_ai_qa (read) ' . $action . ': ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'Server error']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}

validate_csrf_or_die();

try {
    if ($action === 'generate') {
        // group_key = ค่าที่ trim ของ question; UI ส่งมาจาก data-group-key
        $groupKey = trim((string)($_POST['group_key'] ?? ''));
        if ($groupKey === '') {
            echo json_encode(['ok' => false, 'message' => 'invalid group_key']);
            exit;
        }

        // Reuse-before-regenerate: เช็คว่ามีคำตอบที่ approve แล้ว/อยู่ใน FAQ
        // ที่ใกล้เคียงกันไหม (exact + Gemini semantic ≥0.7) — ถ้ามีก็ใช้ซ้ำ
        // ไม่ต้อง gen ใหม่ ประหยัด API + admin approve ได้เร็ว
        $matched = ai_qa_match_faq($pdo, $groupKey);
        $reused = $matched !== null;

        if ($reused) {
            $result = [
                'category'   => (string)($matched['category'] ?? 'อื่นๆ'),
                'answer'     => (string)$matched['answer'],
                'model'      => 'reused:' . $matched['matched_via'],
                'confidence' => (float)($matched['confidence'] ?? 1.0),
            ];
        } else {
            // Backdate context to the moment the user actually asked, so AI's status/today
            // labels match user's situation (not admin's review time, which can be hours later).
            $askedAtRow = $pdo->prepare("
                SELECT MIN(created_at) AS earliest
                  FROM sys_ai_qa_log
                 WHERE TRIM(question) = :q
            ");
            $askedAtRow->execute([':q' => $groupKey]);
            $earliest = (string)($askedAtRow->fetchColumn() ?: '');
            $askedAt = null;
            if ($earliest !== '') {
                try {
                    $askedAt = new DateTimeImmutable($earliest, new DateTimeZone(CLINIC_TZ_NAME));
                } catch (Exception) { /* keep null → falls back to "now" */ }
            }
            $result = ai_qa_generate_answer($groupKey, $pdo, $askedAt);
        }

        $upd = $pdo->prepare("
            UPDATE sys_ai_qa_log
               SET category = :cat,
                   ai_answer = :ans,
                   ai_model = :model,
                   ai_confidence = :conf,
                   status = 'generated'
             WHERE TRIM(question) = :gk
        ");
        $upd->execute([
            ':cat'   => $result['category'],
            ':ans'   => $result['answer'],
            ':model' => $result['model'],
            ':conf'  => $result['confidence'],
            ':gk'    => $groupKey,
        ]);

        echo json_encode([
            'ok'      => true,
            'result'  => $result,
            'updated' => $upd->rowCount(),
            'reused'  => $reused,
            'matched_via' => $reused ? $matched['matched_via'] : null,
        ]);
        exit;
    }

    if ($action === 'bulk_generate') {
        $limit = max(1, min(20, (int)($_POST['limit'] ?? 10)));

        // dedupe ก่อน — เลือก distinct question ที่ยังมี row pending,
        // ประหยัด API call (ก่อนหน้านี้ Gemini ถูกเรียกซ้ำต่อ row ที่ซ้ำกัน)
        $rows = $pdo->prepare("
            SELECT TRIM(question) AS group_key, MIN(created_at) AS earliest
              FROM sys_ai_qa_log
             WHERE status = 'pending'
             GROUP BY TRIM(question)
             ORDER BY earliest ASC
             LIMIT {$limit}
        ");
        $rows->execute();
        $list = $rows->fetchAll(PDO::FETCH_ASSOC);

        $upd = $pdo->prepare("
            UPDATE sys_ai_qa_log
               SET category = :cat,
                   ai_answer = :ans,
                   ai_model = :model,
                   ai_confidence = :conf,
                   status = 'generated'
             WHERE TRIM(question) = :gk
               AND status = 'pending'
        ");

        $ok = 0; $fail = 0; $rowsUpdated = 0; $reusedCnt = 0; $errors = [];
        foreach ($list as $rec) {
            $gk = (string)$rec['group_key'];
            try {
                // Reuse-before-regenerate (เหมือน single generate)
                $matched = ai_qa_match_faq($pdo, $gk);
                if ($matched !== null) {
                    $result = [
                        'category'   => (string)($matched['category'] ?? 'อื่นๆ'),
                        'answer'     => (string)$matched['answer'],
                        'model'      => 'reused:' . $matched['matched_via'],
                        'confidence' => (float)($matched['confidence'] ?? 1.0),
                    ];
                    $reusedCnt++;
                } else {
                    // Backdate context — same rationale as single generate above
                    $askedAt = null;
                    $earliest = (string)($rec['earliest'] ?? '');
                    if ($earliest !== '') {
                        try {
                            $askedAt = new DateTimeImmutable($earliest, new DateTimeZone(CLINIC_TZ_NAME));
                        } catch (Exception) { /* keep null */ }
                    }
                    $result = ai_qa_generate_answer($gk, $pdo, $askedAt);
                }
                $upd->execute([
                    ':cat'   => $result['category'],
                    ':ans'   => $result['answer'],
                    ':model' => $result['model'],
                    ':conf'  => $result['confidence'],
                    ':gk'    => $gk,
                ]);
                $rowsUpdated += $upd->rowCount();
                $ok++;
            } catch (Throwable $e) {
                $fail++;
                $errors[] = mb_substr($gk, 0, 30) . ': ' . $e->getMessage();
            }
        }

        echo json_encode([
            'ok'           => true,
            'processed'    => count($list),
            'success'      => $ok,
            'reused'       => $reusedCnt,
            'failed'       => $fail,
            'rows_updated' => $rowsUpdated,
            'errors'       => $errors,
        ]);
        exit;
    }

    if ($action === 'update') {
        $groupKey = trim((string)($_POST['group_key'] ?? ''));
        $status   = (string)($_POST['status'] ?? '');
        if ($groupKey === '' || !in_array($status, AI_QA_STATUSES, true)) {
            echo json_encode(['ok' => false, 'message' => 'invalid input']);
            exit;
        }

        $category = (string)($_POST['category'] ?? '');
        if ($category !== '' && !in_array($category, AI_QA_CATEGORIES, true)) {
            $category = 'อื่นๆ';
        }

        $answer = (string)($_POST['answer'] ?? '');
        $note   = (string)($_POST['reviewer_note'] ?? '');

        $upd = $pdo->prepare("
            UPDATE sys_ai_qa_log
               SET status = :st,
                   category = COALESCE(NULLIF(:cat, ''), category),
                   ai_answer = COALESCE(NULLIF(:ans, ''), ai_answer),
                   reviewer_note = NULLIF(:note, ''),
                   reviewed_by = :rid,
                   reviewed_at = NOW()
             WHERE TRIM(question) = :gk
        ");
        $upd->execute([
            ':st'   => $status,
            ':cat'  => $category,
            ':ans'  => $answer,
            ':note' => $note,
            ':rid'  => $reviewerId ?: null,
            ':gk'   => $groupKey,
        ]);

        echo json_encode(['ok' => true, 'updated' => $upd->rowCount()]);
        exit;
    }

    if ($action === 'delete') {
        $groupKey = trim((string)($_POST['group_key'] ?? ''));
        if ($groupKey === '') {
            echo json_encode(['ok' => false, 'message' => 'invalid group_key']);
            exit;
        }
        $del = $pdo->prepare("DELETE FROM sys_ai_qa_log WHERE TRIM(question) = :gk");
        $del->execute([':gk' => $groupKey]);
        echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]);
        exit;
    }

    // ─── Question classification (filter "ไม่ใช่คำถาม" ออกจาก default view) ─
    if ($action === 'classify_questions') {
        $limit = max(1, min(100, (int)($_POST['limit'] ?? 50)));

        // dedupe: ส่งคำถาม distinct ที่ยัง is_question='unknown' ให้ AI ตัดสินทีเดียว
        $rows = $pdo->prepare("
            SELECT TRIM(question) AS group_key, MIN(created_at) AS earliest
              FROM sys_ai_qa_log
             WHERE is_question = 'unknown'
             GROUP BY TRIM(question)
             ORDER BY earliest ASC
             LIMIT {$limit}
        ");
        $rows->execute();
        $groups = $rows->fetchAll(PDO::FETCH_ASSOC);

        if (empty($groups)) {
            echo json_encode(['ok' => true, 'processed' => 0, 'yes' => 0, 'no' => 0, 'message' => 'ไม่มีข้อความที่ต้องคัดกรอง']);
            exit;
        }

        $questions = array_map(fn($r) => (string)$r['group_key'], $groups);
        $verdicts  = ai_qa_classify_questions($questions);

        $upd = $pdo->prepare("
            UPDATE sys_ai_qa_log
               SET is_question = :v,
                   question_check_at = NOW()
             WHERE TRIM(question) = :gk
               AND is_question = 'unknown'
        ");

        $yes = 0; $no = 0; $skipped = 0;
        foreach ($groups as $i => $rec) {
            $v = (string)($verdicts[$i] ?? 'unknown');
            if ($v !== 'yes' && $v !== 'no') { $skipped++; continue; }
            $upd->execute([':v' => $v, ':gk' => (string)$rec['group_key']]);
            $v === 'yes' ? $yes++ : $no++;
        }

        echo json_encode([
            'ok'        => true,
            'processed' => count($groups),
            'yes'       => $yes,
            'no'        => $no,
            'skipped'   => $skipped,
        ]);
        exit;
    }

    // Admin override AI's classification per group
    if ($action === 'mark_question') {
        $groupKey = trim((string)($_POST['group_key'] ?? ''));
        $verdict  = (string)($_POST['verdict'] ?? '');
        if ($groupKey === '' || !in_array($verdict, ['yes', 'no', 'unknown'], true)) {
            echo json_encode(['ok' => false, 'message' => 'invalid input']);
            exit;
        }
        $upd = $pdo->prepare("
            UPDATE sys_ai_qa_log
               SET is_question = :v,
                   question_check_at = NOW()
             WHERE TRIM(question) = :gk
        ");
        $upd->execute([':v' => $verdict, ':gk' => $groupKey]);
        echo json_encode(['ok' => true, 'updated' => $upd->rowCount()]);
        exit;
    }

    // ─── FAQ Knowledge Base actions ─────────────────────────────────────
    ensure_ai_faq_schema($pdo);

    if ($action === 'faq_create') {
        $q   = trim((string)($_POST['question'] ?? ''));
        $a   = trim((string)($_POST['answer']   ?? ''));
        $cat = (string)($_POST['category'] ?? 'อื่นๆ');
        $src = (int)($_POST['source_qa_id'] ?? 0) ?: null;
        if (!in_array($cat, AI_QA_CATEGORIES, true)) $cat = 'อื่นๆ';
        if ($q === '' || $a === '') {
            echo json_encode(['ok' => false, 'message' => 'กรอกคำถามและคำตอบ']);
            exit;
        }
        // Admin override wins, but if they didn't tick the box and the
        // question pattern looks time-sensitive, default to on so we
        // don't accept a brand new stale-prone row by mistake.
        $isTimeSensitive = isset($_POST['is_time_sensitive']) && $_POST['is_time_sensitive'] === '1';
        if (!$isTimeSensitive && ai_qa_is_time_sensitive_question($q)) {
            $isTimeSensitive = true;
        }

        $ins = $pdo->prepare("
            INSERT INTO sys_ai_faq (category, canonical_question, answer, source_qa_id, is_time_sensitive, created_by, updated_by)
            VALUES (:cat, :q, :a, :src, :ts, :created_by, :updated_by)
        ");
        $ins->execute([
            ':cat'        => $cat,
            ':q'          => $q,
            ':a'          => $a,
            ':src'        => $src,
            ':ts'         => $isTimeSensitive ? 1 : 0,
            ':created_by' => $reviewerId ?: null,
            ':updated_by' => $reviewerId ?: null,
        ]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'is_time_sensitive' => $isTimeSensitive]);
        exit;
    }

    if ($action === 'faq_update') {
        $id  = (int)($_POST['id'] ?? 0);
        $q   = trim((string)($_POST['question'] ?? ''));
        $a   = trim((string)($_POST['answer']   ?? ''));
        $cat = (string)($_POST['category'] ?? 'อื่นๆ');
        if (!in_array($cat, AI_QA_CATEGORIES, true)) $cat = 'อื่นๆ';
        if ($id <= 0 || $q === '' || $a === '') {
            echo json_encode(['ok' => false, 'message' => 'invalid input']);
            exit;
        }
        // On update we respect whatever the admin ticked — they can turn
        // the flag off explicitly to opt a row back into the FAQ cache.
        $isTimeSensitive = isset($_POST['is_time_sensitive']) && $_POST['is_time_sensitive'] === '1';
        $upd = $pdo->prepare("
            UPDATE sys_ai_faq
               SET category = :cat,
                   canonical_question = :q,
                   answer = :a,
                   is_time_sensitive = :ts,
                   updated_by = :uid
             WHERE id = :id
        ");
        $upd->execute([
            ':cat' => $cat,
            ':q'   => $q,
            ':a'   => $a,
            ':ts'  => $isTimeSensitive ? 1 : 0,
            ':uid' => $reviewerId ?: null,
            ':id'  => $id,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'faq_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'message' => 'invalid id']);
            exit;
        }
        // Two-step delete: the first call (confirm != "1") only counts
        // collateral approved rows in sys_ai_qa_log so the JS layer can
        // warn the admin before they nuke a FAQ that has approved
        // captured replies riding on top of it. Step two (confirm = "1")
        // does the actual delete + cascade.
        $confirm = (string)($_POST['confirm'] ?? '0') === '1';

        // Build the cascade target list. matched_faq_id only covers
        // captures recorded after the trail was added — to cascade old
        // approvals too we also match by question text against the
        // FAQ's canonical_question + every variant_question.
        $qStrings = [];
        $cq = $pdo->prepare("SELECT TRIM(canonical_question) FROM sys_ai_faq WHERE id = :id");
        $cq->execute([':id' => $id]);
        $canon = (string)($cq->fetchColumn() ?: '');
        if ($canon !== '') $qStrings[] = $canon;
        $vq = $pdo->prepare("SELECT TRIM(variant_question) FROM sys_ai_faq_variants WHERE faq_id = :id");
        $vq->execute([':id' => $id]);
        foreach ($vq->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = (string)$v;
            if ($v !== '') $qStrings[] = $v;
        }
        $qStrings = array_values(array_unique($qStrings));

        $cascadeWhere = "(matched_faq_id = :id";
        $cascadeBind  = [':id' => $id];
        if (!empty($qStrings)) {
            $place = [];
            foreach ($qStrings as $i => $qs) {
                $k = ":q$i";
                $place[] = $k;
                $cascadeBind[$k] = $qs;
            }
            $cascadeWhere .= " OR TRIM(question) IN (" . implode(',', $place) . ")";
        }
        $cascadeWhere .= ")";

        $cntStmt = $pdo->prepare("
            SELECT COUNT(*) FROM sys_ai_qa_log
             WHERE $cascadeWhere AND status = 'approved'
        ");
        $cntStmt->execute($cascadeBind);
        $cascadeCount = (int)$cntStmt->fetchColumn();

        if (!$confirm) {
            echo json_encode([
                'ok' => true,
                'preview' => true,
                'cascade_approved' => $cascadeCount,
            ]);
            exit;
        }

        // Collect questions we're about to orphan so we can purge their
        // cache entries — otherwise the next ask hits the cached gemini
        // answer instead of regenerating against the now-deleted FAQ.
        $orphanedQuestions = [];
        $qStmt = $pdo->prepare("
            SELECT DISTINCT TRIM(question) AS q
              FROM sys_ai_qa_log
             WHERE $cascadeWhere
        ");
        $qStmt->execute($cascadeBind);
        $orphanedQuestions = $qStmt->fetchAll(PDO::FETCH_COLUMN);
        // Also seed the canonical / variant strings themselves — those
        // questions may have been answered through cache without ever
        // landing in qa_log (e.g. served from sys_ai_answer_cache before
        // the row was approved).
        foreach ($qStrings as $qs) $orphanedQuestions[] = $qs;
        $orphanedQuestions = array_values(array_unique(array_filter($orphanedQuestions)));

        // FK ON DELETE CASCADE handles sys_ai_faq_variants for us.
        $pdo->prepare("DELETE FROM sys_ai_faq WHERE id = :id")->execute([':id' => $id]);

        // Flip any approved captured replies that referenced this FAQ
        // back to 'rejected' so Phase 1c won't keep serving the answer.
        $rejected = 0;
        if ($cascadeCount > 0) {
            $upd = $pdo->prepare("
                UPDATE sys_ai_qa_log
                   SET status = 'rejected',
                       reviewed_at = NOW(),
                       reviewed_by = :rb
                 WHERE $cascadeWhere AND status = 'approved'
            ");
            $upd->execute(array_merge($cascadeBind, [':rb' => $reviewerId ?: null]));
            $rejected = $upd->rowCount();
        }

        require_once __DIR__ . '/../includes/ai_cache_helper.php';
        $cacheCleared = 0;
        foreach ($orphanedQuestions as $q) {
            $q = (string)$q;
            if ($q === '') continue;
            $cacheCleared += ai_cache_invalidate_question($pdo, $q);
        }

        echo json_encode([
            'ok'            => true,
            'rejected'      => $rejected,
            'cache_cleared' => $cacheCleared,
        ]);
        exit;
    }

    if ($action === 'faq_get') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'message' => 'invalid id']);
            exit;
        }
        $row = $pdo->prepare("SELECT * FROM sys_ai_faq WHERE id = :id LIMIT 1");
        $row->execute([':id' => $id]);
        $faq = $row->fetch(PDO::FETCH_ASSOC);
        if (!$faq) {
            echo json_encode(['ok' => false, 'message' => 'not found']);
            exit;
        }
        $vs = $pdo->prepare("
            SELECT id, variant_question, source, created_at
              FROM sys_ai_faq_variants
             WHERE faq_id = :id
             ORDER BY id ASC
        ");
        $vs->execute([':id' => $id]);
        $variants = $vs->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'faq' => $faq, 'variants' => $variants]);
        exit;
    }

    if ($action === 'faq_generate_variants') {
        // คืน array ของ string ให้ admin เลือกก่อน save (ไม่ insert ทันที)
        $question = trim((string)($_POST['question'] ?? ''));
        if ($question === '') {
            echo json_encode(['ok' => false, 'message' => 'no question']);
            exit;
        }
        $variants = ai_faq_generate_variants($question);
        echo json_encode(['ok' => true, 'variants' => $variants]);
        exit;
    }

    if ($action === 'faq_save_variants') {
        $faqId = (int)($_POST['faq_id'] ?? 0);
        $list  = json_decode((string)($_POST['variants'] ?? '[]'), true);
        $src   = ($_POST['source'] ?? 'manual') === 'ai_generated' ? 'ai_generated' : 'manual';
        if ($faqId <= 0 || !is_array($list)) {
            echo json_encode(['ok' => false, 'message' => 'invalid input']);
            exit;
        }
        $ins = $pdo->prepare("
            INSERT INTO sys_ai_faq_variants (faq_id, variant_question, source)
            VALUES (:fid, :q, :src)
        ");
        $saved = 0;
        foreach ($list as $v) {
            $v = trim((string)$v);
            if ($v === '' || mb_strlen($v) > 500) continue;
            $ins->execute([':fid' => $faqId, ':q' => $v, ':src' => $src]);
            $saved++;
        }
        echo json_encode(['ok' => true, 'saved' => $saved]);
        exit;
    }

    if ($action === 'faq_delete_variant') {
        $vid = (int)($_POST['id'] ?? 0);
        if ($vid <= 0) {
            echo json_encode(['ok' => false, 'message' => 'invalid id']);
            exit;
        }
        $pdo->prepare("DELETE FROM sys_ai_faq_variants WHERE id = :id")->execute([':id' => $vid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ─── Phase C: cache purge (POST + CSRF protected) ────────────────────
    // health_summary / telemetry_recent are read-only and handled above
    // before the POST/CSRF gate so the Overview dashboard can poll them.
    if ($action === 'cache_purge') {
        require_once __DIR__ . '/../includes/ai_cache_helper.php';
        $n = ai_cache_purge_all($pdo);
        echo json_encode(['ok' => true, 'purged' => $n]);
        exit;
    }

    // ─── Promote a captured question → FAQ variant ────────────────────────
    // The "B→A migration" path: a user asked something Phase 2 (Gemini
    // semantic match) handled, the captured row now carries matched_faq_id,
    // and the admin wants subsequent identical phrasings to hit Phase 1
    // exact-match (50ms) instead of paying for Gemini every time (~2s + quota).
    //
    // Accepts either a single qa_log_id or a JSON array (bulk promote).
    // faq_id is optional — if omitted, uses the matched_faq_id stamped at
    // capture time. Pass faq_id explicitly to redirect to a different FAQ
    // (e.g. when Gemini matched the wrong one).
    if ($action === 'variant:promote') {
        $ids = [];
        if (!empty($_POST['ids'])) {
            $decoded = json_decode((string)$_POST['ids'], true);
            if (is_array($decoded)) $ids = array_map('intval', $decoded);
        } elseif (!empty($_POST['qa_log_id'])) {
            $ids = [(int)$_POST['qa_log_id']];
        }
        $ids = array_values(array_filter($ids, fn($n) => $n > 0));
        if (empty($ids)) {
            echo json_encode(['ok' => false, 'message' => 'no qa_log_id supplied']);
            exit;
        }
        $faqIdOverride = (int)($_POST['faq_id'] ?? 0);

        // Fetch the captured rows we're about to promote, in one query
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $pdo->prepare("
            SELECT id, question, matched_faq_id, status
              FROM sys_ai_qa_log
             WHERE id IN ($placeholders)
        ");
        $rows->execute($ids);
        $captured = $rows->fetchAll(PDO::FETCH_ASSOC);
        if (empty($captured)) {
            echo json_encode(['ok' => false, 'message' => 'rows not found']);
            exit;
        }

        require_once __DIR__ . '/../includes/ai_cache_helper.php';
        $insVariant = $pdo->prepare("
            INSERT INTO sys_ai_faq_variants (faq_id, variant_question, source)
            VALUES (:fid, :q, 'ai_generated')
        ");
        $updLog = $pdo->prepare("
            UPDATE sys_ai_qa_log
               SET status = 'approved',
                   reviewed_at = NOW(),
                   reviewed_by = :rb
             WHERE id = :id
        ");

        $promoted = 0;
        $skipped  = [];
        foreach ($captured as $r) {
            $targetFaqId = $faqIdOverride > 0 ? $faqIdOverride : (int)($r['matched_faq_id'] ?? 0);
            if ($targetFaqId <= 0) {
                $skipped[] = ['id' => (int)$r['id'], 'reason' => 'no_matched_faq'];
                continue;
            }
            $q = trim((string)$r['question']);
            if ($q === '' || mb_strlen($q) > 500) {
                $skipped[] = ['id' => (int)$r['id'], 'reason' => 'bad_question_length'];
                continue;
            }
            try {
                $insVariant->execute([':fid' => $targetFaqId, ':q' => $q]);
                $updLog->execute([':rb' => $reviewerId ?: null, ':id' => (int)$r['id']]);
                // Invalidate any cached answer for this exact question so
                // the next user sees the new exact-match path immediately.
                ai_cache_invalidate_question($pdo, $q);
                $promoted++;
            } catch (Throwable $e) {
                $skipped[] = ['id' => (int)$r['id'], 'reason' => 'db_error'];
                error_log('variant:promote failed for qa_log#' . $r['id'] . ': ' . $e->getMessage());
            }
        }

        echo json_encode([
            'ok'       => true,
            'promoted' => $promoted,
            'skipped'  => $skipped,
        ]);
        exit;
    }

    // ─── Bulk mark FAQ rows as time-sensitive ────────────────────────────
    // Pair to the stale scanner: instead of *deleting* stale rows, the
    // admin can flip is_time_sensitive=1 on them so the matcher ignores
    // the cached answer and goes through ai_qa_generate_answer() (which
    // produces a fresh reply against the live clinic context).
    //
    // Accepts either:
    //   id=<single faq id>
    //   ids=<JSON array of faq ids>
    // Only applies to sys_ai_faq rows — sys_ai_qa_log has no such column.
    if ($action === 'faq_mark_time_sensitive') {
        $ids = [];
        if (isset($_POST['ids'])) {
            $decoded = json_decode((string)$_POST['ids'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $v) {
                    $iv = (int)$v;
                    if ($iv > 0) $ids[] = $iv;
                }
            }
        } elseif (isset($_POST['id'])) {
            $iv = (int)$_POST['id'];
            if ($iv > 0) $ids[] = $iv;
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            echo json_encode(['ok' => false, 'message' => 'no valid ids']);
            exit;
        }
        // Cap to defend against accidental "mark literally everything"
        if (count($ids) > 500) {
            echo json_encode(['ok' => false, 'message' => 'มาก์ครั้งละไม่เกิน 500 รายการ']);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare("UPDATE sys_ai_faq
            SET is_time_sensitive = 1, updated_by = ?
            WHERE id IN ({$placeholders})");
        $upd->execute(array_merge([$reviewerId ?: null], $ids));
        echo json_encode(['ok' => true, 'updated' => $upd->rowCount()]);
        exit;
    }

    // ─── Stale FAQ scanner ────────────────────────────────────────────────
    // Scan sys_ai_faq + sys_ai_qa_log (approved) for answers that trip the
    // stale-date regex (วันนี้ / พรุ่งนี้ / Thai month / พ.ศ. NNNN / etc).
    // Returns a list the admin can act on; per-row delete uses the existing
    // faq_delete / delete actions so we don't duplicate destroy logic.
    if ($action === 'faq_scan_stale') {
        $items = [];

        // sys_ai_faq (active)
        $rows = $pdo->query("SELECT id, category, canonical_question, answer, updated_at
            FROM sys_ai_faq WHERE is_active = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $ans = (string)($r['answer'] ?? '');
            if ($ans === '' || !ai_qa_answer_has_stale_dates($ans)) continue;
            $items[] = [
                'source'     => 'faq',
                'id'         => (int)$r['id'],
                'category'   => (string)($r['category'] ?? ''),
                'question'   => (string)($r['canonical_question'] ?? ''),
                'answer'     => $ans,
                'updated_at' => (string)($r['updated_at'] ?? ''),
            ];
        }

        // sys_ai_qa_log (approved captures — matcher Phase 1c reads these)
        try {
            $rows = $pdo->query("SELECT id, category, question, ai_answer, reviewed_at
                FROM sys_ai_qa_log
                WHERE status = 'approved' AND ai_answer IS NOT NULL
                ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $ans = (string)($r['ai_answer'] ?? '');
                if ($ans === '' || !ai_qa_answer_has_stale_dates($ans)) continue;
                $items[] = [
                    'source'     => 'qa_log',
                    'id'         => (int)$r['id'],
                    'group_key'  => (string)($r['question'] ?? ''),
                    'category'   => (string)($r['category'] ?? ''),
                    'question'   => (string)($r['question'] ?? ''),
                    'answer'     => $ans,
                    'updated_at' => (string)($r['reviewed_at'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            // sys_ai_qa_log may not exist on a fresh install — skip silently
        }

        echo json_encode(['ok' => true, 'count' => count($items), 'items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'unknown action']);
} catch (Throwable $e) {
    error_log('ajax_ai_qa error (' . $action . '): ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
