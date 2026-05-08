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

    echo json_encode(['ok' => false, 'message' => 'unknown action']);
} catch (Throwable $e) {
    error_log('ajax_ai_qa error (' . $action . '): ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
