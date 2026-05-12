<?php
/**
 * includes/ai_feedback_helper.php
 * Schema + CRUD สำหรับ sys_ai_feedback — บันทึก 👍/👎 จากผู้ใช้
 */
declare(strict_types=1);

function ensure_ai_feedback_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_feedback (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source     VARCHAR(50)  NOT NULL DEFAULT 'portal_chat',
            msg_id     VARCHAR(64)  NULL,
            question   TEXT         NOT NULL,
            answer     TEXT         NOT NULL,
            rating     TINYINT      NOT NULL DEFAULT 0  COMMENT '1=thumbs_up, -1=thumbs_down',
            comment    VARCHAR(500) NOT NULL DEFAULT '',
            admin_id   INT UNSIGNED NULL,
            created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rating  (rating),
            INDEX idx_source  (source),
            INDEX idx_created (created_at),
            INDEX idx_msg     (msg_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) {
        error_log('ensure_ai_feedback_schema: ' . $e->getMessage());
    }
}

function feedback_save(PDO $pdo, string $source, string $msgId, string $question,
                       string $answer, int $rating, string $comment, ?int $adminId): int
{
    ensure_ai_feedback_schema($pdo);
    // upsert ถ้ามี msg_id เดิมอยู่แล้ว (user เปลี่ยนใจ กด 👍 → 👎)
    if ($msgId !== '') {
        $existing = $pdo->prepare("SELECT id FROM sys_ai_feedback WHERE msg_id = :m LIMIT 1");
        $existing->execute([':m' => $msgId]);
        if ($row = $existing->fetch()) {
            $upd = $pdo->prepare("UPDATE sys_ai_feedback SET rating = :r, comment = :c WHERE id = :id");
            $upd->execute([':r' => $rating, ':c' => mb_substr($comment, 0, 500), ':id' => (int)$row['id']]);
            return (int)$row['id'];
        }
    }
    $stmt = $pdo->prepare("
        INSERT INTO sys_ai_feedback (source, msg_id, question, answer, rating, comment, admin_id)
        VALUES (:src, :mid, :q, :a, :r, :c, :uid)
    ");
    $stmt->execute([
        ':src' => mb_substr($source,  0, 50),
        ':mid' => $msgId !== '' ? mb_substr($msgId, 0, 64) : null,
        ':q'   => mb_substr(trim($question), 0, 2000),
        ':a'   => mb_substr(trim($answer),   0, 8000),
        ':r'   => $rating,
        ':c'   => mb_substr($comment, 0, 500),
        ':uid' => $adminId,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * @return array{rows: array, total: int}
 */
function feedback_list(PDO $pdo, int $page = 1, int $limit = 20,
                       int $rating = 0, string $source = ''): array
{
    ensure_ai_feedback_schema($pdo);
    $where  = [];
    $params = [];
    if ($rating !== 0) {
        $where[]      = 'rating = :r';
        $params[':r'] = $rating;
    }
    if ($source !== '') {
        $where[]      = 'source = :src';
        $params[':src'] = $source;
    }
    $wsql   = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $offset = ($page - 1) * $limit;

    $c = $pdo->prepare("SELECT COUNT(*) FROM sys_ai_feedback $wsql");
    $c->execute($params);
    $total = (int)$c->fetchColumn();

    $params[':lim'] = $limit;
    $params[':off'] = $offset;
    $stmt = $pdo->prepare("
        SELECT id, source, msg_id,
               LEFT(question, 200) AS question_short,
               LEFT(answer,   300) AS answer_short,
               rating, comment, admin_id, created_at
          FROM sys_ai_feedback
         $wsql
         ORDER BY created_at DESC
         LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        if ($k === ':lim' || $k === ':off') continue;
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
}

function feedback_summary(PDO $pdo): array
{
    ensure_ai_feedback_schema($pdo);
    try {
        $row = $pdo->query("
            SELECT
                COUNT(*)                                     AS total,
                SUM(CASE WHEN rating =  1 THEN 1 ELSE 0 END) AS thumbs_up,
                SUM(CASE WHEN rating = -1 THEN 1 ELSE 0 END) AS thumbs_down,
                SUM(CASE WHEN rating =  0 THEN 1 ELSE 0 END) AS neutral
            FROM sys_ai_feedback
        ")->fetch(PDO::FETCH_ASSOC);
        $total = (int)($row['total'] ?? 0);
        $up    = (int)($row['thumbs_up'] ?? 0);
        return [
            'total'      => $total,
            'thumbs_up'  => $up,
            'thumbs_down'=> (int)($row['thumbs_down'] ?? 0),
            'neutral'    => (int)($row['neutral'] ?? 0),
            'pct_positive'=> $total > 0 ? round($up / $total * 100, 1) : 0,
        ];
    } catch (Throwable) {
        return ['total'=>0,'thumbs_up'=>0,'thumbs_down'=>0,'neutral'=>0,'pct_positive'=>0];
    }
}

function feedback_delete(PDO $pdo, int $id): bool
{
    ensure_ai_feedback_schema($pdo);
    return $pdo->prepare("DELETE FROM sys_ai_feedback WHERE id = :id")->execute([':id' => $id]);
}
