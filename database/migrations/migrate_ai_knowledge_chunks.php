<?php
/**
 * database/migrations/migrate_ai_knowledge_chunks.php
 * สร้างตาราง sys_ai_knowledge_chunks — ชุดข้อมูลชิ้นย่อย (RAG chunks)
 * พร้อม embedding JSON สำหรับ semantic search
 *
 * รัน: php database/migrations/migrate_ai_knowledge_chunks.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_knowledge_chunks (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title           VARCHAR(200) NOT NULL,
        content         TEXT NOT NULL,
        tags            VARCHAR(500) NOT NULL DEFAULT '',
        source_label    VARCHAR(100) NOT NULL DEFAULT 'manual',
        embedding_model VARCHAR(100) NULL,
        embedding_json  MEDIUMTEXT NULL COMMENT 'JSON array of float32 values from Gemini text-embedding-004',
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

    echo "✅ [Success] สร้าง sys_ai_knowledge_chunks เรียบร้อยแล้ว\n";
} catch (Throwable $e) {
    echo "❌ [Error] " . $e->getMessage() . "\n";
    exit(1);
}
