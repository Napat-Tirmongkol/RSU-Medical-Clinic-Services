<?php
/**
 * portal/ajax_ai_qa.php — AI QA Lab AJAX endpoint
 *
 * Actions:
 * - generate     : เรียก Gemini ร่างคำตอบ + จัดหมวด (1 record)
 * - bulk_generate: เรียก Gemini สำหรับหลาย record ที่ status='pending'
 * - update       : บันทึก review (status, edited answer, category, note)
 * - delete       : ลบ record
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
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'message' => 'invalid id']);
            exit;
        }

        $row = $pdo->prepare("SELECT id, question FROM sys_ai_qa_log WHERE id = :id LIMIT 1");
        $row->execute([':id' => $id]);
        $rec = $row->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            echo json_encode(['ok' => false, 'message' => 'record not found']);
            exit;
        }

        $result = ai_qa_generate_answer((string)$rec['question']);

        $upd = $pdo->prepare("
            UPDATE sys_ai_qa_log
               SET category = :cat,
                   ai_answer = :ans,
                   ai_model = :model,
                   ai_confidence = :conf,
                   status = 'generated'
             WHERE id = :id
        ");
        $upd->execute([
            ':cat'   => $result['category'],
            ':ans'   => $result['answer'],
            ':model' => $result['model'],
            ':conf'  => $result['confidence'],
            ':id'    => $id,
        ]);

        echo json_encode([
            'ok'     => true,
            'result' => $result,
        ]);
        exit;
    }

    if ($action === 'bulk_generate') {
        $limit = max(1, min(20, (int)($_POST['limit'] ?? 10)));

        $rows = $pdo->prepare("
            SELECT id, question
              FROM sys_ai_qa_log
             WHERE status = 'pending'
             ORDER BY created_at ASC
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
             WHERE id = :id
        ");

        $ok = 0; $fail = 0; $errors = [];
        foreach ($list as $rec) {
            try {
                $result = ai_qa_generate_answer((string)$rec['question']);
                $upd->execute([
                    ':cat'   => $result['category'],
                    ':ans'   => $result['answer'],
                    ':model' => $result['model'],
                    ':conf'  => $result['confidence'],
                    ':id'    => (int)$rec['id'],
                ]);
                $ok++;
            } catch (Throwable $e) {
                $fail++;
                $errors[] = '#' . $rec['id'] . ': ' . $e->getMessage();
            }
        }

        echo json_encode([
            'ok'        => true,
            'processed' => count($list),
            'success'   => $ok,
            'failed'    => $fail,
            'errors'    => $errors,
        ]);
        exit;
    }

    if ($action === 'update') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($id <= 0 || !in_array($status, AI_QA_STATUSES, true)) {
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
             WHERE id = :id
        ");
        $upd->execute([
            ':st'   => $status,
            ':cat'  => $category,
            ':ans'  => $answer,
            ':note' => $note,
            ':rid'  => $reviewerId ?: null,
            ':id'   => $id,
        ]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'message' => 'invalid id']);
            exit;
        }
        $del = $pdo->prepare("DELETE FROM sys_ai_qa_log WHERE id = :id");
        $del->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
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
