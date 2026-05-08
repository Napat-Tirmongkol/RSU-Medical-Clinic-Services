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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}

validate_csrf_or_die();

$pdo = db();
ensure_ai_qa_schema($pdo);

$action     = (string)($_POST['action'] ?? '');
$reviewerId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);

try {
    if ($action === 'generate') {
        // group_key = ค่าที่ trim ของ question; UI ส่งมาจาก data-group-key
        $groupKey = trim((string)($_POST['group_key'] ?? ''));
        if ($groupKey === '') {
            echo json_encode(['ok' => false, 'message' => 'invalid group_key']);
            exit;
        }

        $result = ai_qa_generate_answer($groupKey);

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

        $ok = 0; $fail = 0; $rowsUpdated = 0; $errors = [];
        foreach ($list as $rec) {
            $gk = (string)$rec['group_key'];
            try {
                $result = ai_qa_generate_answer($gk);
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

        $ins = $pdo->prepare("
            INSERT INTO sys_ai_faq (category, canonical_question, answer, source_qa_id, created_by, updated_by)
            VALUES (:cat, :q, :a, :src, :created_by, :updated_by)
        ");
        $ins->execute([
            ':cat'        => $cat,
            ':q'          => $q,
            ':a'          => $a,
            ':src'        => $src,
            ':created_by' => $reviewerId ?: null,
            ':updated_by' => $reviewerId ?: null,
        ]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
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
        $upd = $pdo->prepare("
            UPDATE sys_ai_faq
               SET category = :cat,
                   canonical_question = :q,
                   answer = :a,
                   updated_by = :uid
             WHERE id = :id
        ");
        $upd->execute([
            ':cat' => $cat,
            ':q'   => $q,
            ':a'   => $a,
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
        // FK ON DELETE CASCADE จัดการ variants ให้
        $pdo->prepare("DELETE FROM sys_ai_faq WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
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

    echo json_encode(['ok' => false, 'message' => 'unknown action']);
} catch (Throwable $e) {
    error_log('ajax_ai_qa error (' . $action . '): ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
