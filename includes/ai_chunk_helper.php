<?php
/**
 * includes/ai_chunk_helper.php
 *
 * CRUD + Gemini text-embedding-004 + cosine similarity search
 * สำหรับ sys_ai_knowledge_chunks (RAG knowledge base)
 */
declare(strict_types=1);

// ── Schema ──────────────────────────────────────────────────────────────────

function ensure_chunks_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_knowledge_chunks (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title           VARCHAR(200) NOT NULL,
            content         TEXT NOT NULL,
            tags            VARCHAR(500) NOT NULL DEFAULT '',
            source_label    VARCHAR(100) NOT NULL DEFAULT 'manual',
            embedding_model VARCHAR(100) NULL,
            embedding_json  MEDIUMTEXT NULL,
            token_count     INT UNSIGNED NOT NULL DEFAULT 0,
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            sort_order      INT          NOT NULL DEFAULT 0,
            created_by      INT UNSIGNED NULL,
            updated_by      INT UNSIGNED NULL,
            created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active_sort (is_active, sort_order),
            INDEX idx_source (source_label),
            FULLTEXT INDEX ft_content (title, content)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) {
        error_log('ensure_chunks_schema: ' . $e->getMessage());
    }
}

// ── CRUD ─────────────────────────────────────────────────────────────────────

/**
 * ดึง chunks ทั้งหมด (paginated)
 * คืน ['chunks' => [...], 'total' => N]
 */
function chunk_list(PDO $pdo, int $page = 1, int $limit = 20, string $search = '', string $source = ''): array
{
    ensure_chunks_schema($pdo);
    $page  = max(1, $page);
    $limit = max(1, min(100, $limit));
    $offset = ($page - 1) * $limit;

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[]           = '(title LIKE :q OR content LIKE :q2 OR tags LIKE :q3)';
        $like              = '%' . $search . '%';
        $params[':q']      = $like;
        $params[':q2']     = $like;
        $params[':q3']     = $like;
    }
    if ($source !== '') {
        $where[]            = 'source_label = :src';
        $params[':src']     = $source;
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_ai_knowledge_chunks $whereSQL");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[':limit']  = $limit;
    $params[':offset'] = $offset;
    $stmt = $pdo->prepare("
        SELECT id, title,
               LEFT(content, 300) AS content_preview,
               tags, source_label, embedding_model,
               (embedding_json IS NOT NULL) AS has_embedding,
               token_count, is_active, sort_order, created_at, updated_at
          FROM sys_ai_knowledge_chunks
         $whereSQL
         ORDER BY sort_order ASC, id ASC
         LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $k => $v) {
        if ($k === ':limit' || $k === ':offset') continue;
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    return [
        'chunks' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'total'  => $total,
    ];
}

/** ดึง chunk เดียวพร้อม full content (ไม่ตัด) */
function chunk_get(PDO $pdo, int $id): ?array
{
    ensure_chunks_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM sys_ai_knowledge_chunks WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function chunk_create(PDO $pdo, string $title, string $content, string $tags,
                      string $sourceLabel, int $sortOrder, ?int $adminId): int
{
    ensure_chunks_schema($pdo);
    $tokenCount = _chunk_estimate_tokens($content);
    $stmt = $pdo->prepare("
        INSERT INTO sys_ai_knowledge_chunks
               (title, content, tags, source_label, token_count, sort_order, is_active, created_by, updated_by)
        VALUES (:t, :c, :tags, :src, :tok, :s, 1, :u, :u2)
    ");
    $stmt->execute([
        ':t'   => mb_substr(trim($title),  0, 200),
        ':c'   => trim($content),
        ':tags'=> mb_substr(trim($tags),   0, 500),
        ':src' => mb_substr(trim($sourceLabel), 0, 100) ?: 'manual',
        ':tok' => $tokenCount,
        ':s'   => $sortOrder,
        ':u'   => $adminId,
        ':u2'  => $adminId,
    ]);
    return (int)$pdo->lastInsertId();
}

function chunk_update(PDO $pdo, int $id, string $title, string $content, string $tags,
                      string $sourceLabel, int $sortOrder, ?int $adminId): bool
{
    ensure_chunks_schema($pdo);
    $tokenCount = _chunk_estimate_tokens($content);
    $stmt = $pdo->prepare("
        UPDATE sys_ai_knowledge_chunks
           SET title = :t, content = :c, tags = :tags, source_label = :src,
               token_count = :tok, sort_order = :s,
               embedding_json = NULL, embedding_model = NULL,
               updated_by = :u
         WHERE id = :id
    ");
    return $stmt->execute([
        ':id'  => $id,
        ':t'   => mb_substr(trim($title),  0, 200),
        ':c'   => trim($content),
        ':tags'=> mb_substr(trim($tags),   0, 500),
        ':src' => mb_substr(trim($sourceLabel), 0, 100) ?: 'manual',
        ':tok' => $tokenCount,
        ':s'   => $sortOrder,
        ':u'   => $adminId,
    ]);
}

function chunk_toggle(PDO $pdo, int $id, bool $isActive): bool
{
    ensure_chunks_schema($pdo);
    $stmt = $pdo->prepare("UPDATE sys_ai_knowledge_chunks SET is_active = :a WHERE id = :id");
    return $stmt->execute([':id' => $id, ':a' => $isActive ? 1 : 0]);
}

function chunk_delete(PDO $pdo, int $id): bool
{
    ensure_chunks_schema($pdo);
    $stmt = $pdo->prepare("DELETE FROM sys_ai_knowledge_chunks WHERE id = :id");
    return $stmt->execute([':id' => $id]);
}

/** ประมาณ token count จากจำนวนอักขระ (rough: 1 token ≈ 3 ตัวอักษร ไทย) */
function _chunk_estimate_tokens(string $text): int
{
    return (int)ceil(mb_strlen($text) / 3.5);
}

// ── Embedding ────────────────────────────────────────────────────────────────

/**
 * สร้าง embedding ด้วย Gemini text-embedding-004
 * คืน array of float หรือ throw RuntimeException ถ้าไม่สำเร็จ
 */
function chunk_generate_embedding(string $text): array
{
    $apiKey = _chunk_load_gemini_key();
    if ($apiKey === '') {
        throw new RuntimeException('ยังไม่ได้ตั้งค่า GEMINI_API_KEY');
    }

    $model = 'text-embedding-004';
    $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key={$apiKey}";

    $payload = json_encode([
        'model'   => "models/{$model}",
        'content' => ['parts' => [['text' => mb_substr($text, 0, 8000)]]],
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('ไม่สามารถเชื่อมต่อ Gemini Embedding API');
    }

    $data = json_decode($raw, true);
    $values = $data['embedding']['values'] ?? null;
    if (!is_array($values) || count($values) === 0) {
        $errMsg = $data['error']['message'] ?? $raw;
        throw new RuntimeException('Gemini embedding error: ' . $errMsg);
    }

    return array_map('floatval', $values);
}

/** สร้างและบันทึก embedding ของ chunk ลง DB */
function chunk_embed_and_save(PDO $pdo, int $id): void
{
    ensure_chunks_schema($pdo);
    $row = chunk_get($pdo, $id);
    if (!$row) throw new RuntimeException("ไม่พบ chunk #{$id}");

    $inputText = trim($row['title']) . "\n\n" . trim($row['content']);
    $vector    = chunk_generate_embedding($inputText);

    $stmt = $pdo->prepare("
        UPDATE sys_ai_knowledge_chunks
           SET embedding_json = :emb, embedding_model = 'text-embedding-004'
         WHERE id = :id
    ");
    $stmt->execute([':emb' => json_encode($vector), ':id' => $id]);
}

/** สร้าง embedding สำหรับ chunks ที่ยังไม่มี (batch) — คืนจำนวนที่ทำ */
function chunk_embed_pending(PDO $pdo, int $max = 20): int
{
    ensure_chunks_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT id FROM sys_ai_knowledge_chunks
         WHERE embedding_json IS NULL AND is_active = 1
         ORDER BY id ASC LIMIT :max
    ");
    $stmt->bindValue(':max', $max, PDO::PARAM_INT);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $done = 0;
    foreach ($ids as $id) {
        try {
            chunk_embed_and_save($pdo, (int)$id);
            $done++;
        } catch (Throwable $e) {
            error_log("[chunk_embed_pending] id={$id}: " . $e->getMessage());
        }
    }
    return $done;
}

// ── Semantic Search ──────────────────────────────────────────────────────────

/**
 * ค้นหา chunks ที่ใกล้เคียงกับ query ที่สุด (cosine similarity)
 * คืน array ของ chunk rows เรียงตาม score DESC
 */
function chunk_semantic_search(PDO $pdo, string $query, int $topK = 5): array
{
    ensure_chunks_schema($pdo);

    $queryVec = chunk_generate_embedding($query);

    $stmt = $pdo->query("
        SELECT id, title, LEFT(content,500) AS content_preview, tags, source_label, embedding_json
          FROM sys_ai_knowledge_chunks
         WHERE is_active = 1 AND embedding_json IS NOT NULL
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $scored = [];
    foreach ($rows as $row) {
        $vec = json_decode((string)$row['embedding_json'], true);
        if (!is_array($vec)) continue;
        $score = _cosine_similarity($queryVec, $vec);
        $row['score'] = round($score, 4);
        unset($row['embedding_json']);
        $scored[] = $row;
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $topK);
}

/** Cosine similarity ระหว่าง 2 vectors */
function _cosine_similarity(array $a, array $b): float
{
    $dot = 0.0; $na = 0.0; $nb = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $na  += $a[$i] * $a[$i];
        $nb  += $b[$i] * $b[$i];
    }
    if ($na === 0.0 || $nb === 0.0) return 0.0;
    return $dot / (sqrt($na) * sqrt($nb));
}

function _chunk_load_gemini_key(): string
{
    $key = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    if ($key === '') {
        $f = __DIR__ . '/../config/secrets.php';
        if (is_file($f)) {
            $s = require $f;
            $key = (string)($s['GEMINI_API_KEY'] ?? '');
        }
    }
    return $key;
}

// ── Context builder ───────────────────────────────────────────────────────────

/**
 * คืน string block สำหรับฉีดเข้า AI prompt
 * ใช้ top-K semantic search ถ้า query ให้ หรือดึง active ทั้งหมดถ้าไม่มี
 */
function render_chunks_context_block(PDO $pdo, string $query = '', int $topK = 5): string
{
    ensure_chunks_schema($pdo);

    if ($query !== '' && _chunk_load_gemini_key() !== '') {
        try {
            $chunks = chunk_semantic_search($pdo, $query, $topK);
        } catch (Throwable $e) {
            $chunks = _chunk_get_all_active($pdo);
        }
    } else {
        $chunks = _chunk_get_all_active($pdo);
    }

    if (empty($chunks)) return '';

    $lines = [];
    foreach ($chunks as $c) {
        $title   = trim((string)($c['title']   ?? ''));
        $content = trim((string)($c['content'] ?? $c['content_preview'] ?? ''));
        if ($title === '' || $content === '') continue;
        $lines[] = "● {$title}\n" . preg_replace('/^/m', '  ', $content);
    }
    return implode("\n\n", $lines);
}

function _chunk_get_all_active(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, title, content, tags, source_label
          FROM sys_ai_knowledge_chunks
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
