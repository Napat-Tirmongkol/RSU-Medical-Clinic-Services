<?php
/**
 * database/migrations/migrate_insurance_batch_workflow.php
 *
 * Module: Insurance Batch Workflow Tracking
 *
 * - insurance_batch: 1 row per upload (sync_id), tracks workflow state
 *   Stages: uploaded → pending_review → approved → downloaded → in_progress
 *           → partial → completed | rejected | cancelled
 *
 * - insurance_batch_event: append-only audit timeline of state changes
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้ทิ้ง
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── insurance_batch ───────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_batch (
        id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sync_id                  INT UNSIGNED NOT NULL,
        batch_code               VARCHAR(50)  NOT NULL,
        upload_mode              VARCHAR(20)  NOT NULL DEFAULT 'full_sync',
        source_type              VARCHAR(30)  NOT NULL DEFAULT 'clinic_manual',
        insurance_company        VARCHAR(20)  NOT NULL DEFAULT 'MTI',

        status ENUM(
            'uploaded',
            'pending_review',
            'approved',
            'rejected',
            'downloaded',
            'in_progress',
            'partial',
            'completed',
            'cancelled'
        ) NOT NULL DEFAULT 'pending_review',

        total_members            INT UNSIGNED NOT NULL DEFAULT 0,
        members_inserted         INT UNSIGNED NOT NULL DEFAULT 0,
        members_updated          INT UNSIGNED NOT NULL DEFAULT 0,
        members_inactivated      INT UNSIGNED NOT NULL DEFAULT 0,
        members_with_policy      INT UNSIGNED NOT NULL DEFAULT 0,

        uploaded_by              INT UNSIGNED NULL,
        uploaded_by_name         VARCHAR(150) NULL,
        uploaded_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

        reviewed_by              INT UNSIGNED NULL,
        reviewed_by_name         VARCHAR(150) NULL,
        reviewed_at              DATETIME     NULL,
        review_note              TEXT         NULL,

        first_downloaded_at      DATETIME     NULL,
        last_downloaded_at       DATETIME     NULL,
        download_count           INT UNSIGNED NOT NULL DEFAULT 0,

        first_policy_returned_at DATETIME     NULL,
        last_policy_returned_at  DATETIME     NULL,
        completed_at             DATETIME     NULL,

        notes                    TEXT         NULL,
        created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uniq_batch_code (batch_code),
        UNIQUE KEY uniq_sync_id (sync_id),
        INDEX idx_status (status),
        INDEX idx_company (insurance_company),
        INDEX idx_uploaded_by (uploaded_by),
        INDEX idx_uploaded_at (uploaded_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ insurance_batch — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ insurance_batch: ' . $e->getMessage();
}

// ── insurance_batch_event ─────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS insurance_batch_event (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        batch_id     INT UNSIGNED NOT NULL,
        event_type   VARCHAR(50)  NOT NULL,
        from_status  VARCHAR(30)  NULL,
        to_status    VARCHAR(30)  NULL,
        actor_type   VARCHAR(20)  NOT NULL DEFAULT 'system',
        actor_id     INT UNSIGNED NULL,
        actor_name   VARCHAR(150) NULL,
        details      TEXT         NULL,
        ip_address   VARCHAR(45)  NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch (batch_id),
        INDEX idx_event_type (event_type),
        INDEX idx_created (created_at),
        CONSTRAINT fk_event_batch
            FOREIGN KEY (batch_id) REFERENCES insurance_batch(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ insurance_batch_event — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ insurance_batch_event: ' . $e->getMessage();
}

// ── Output ────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration: Insurance Batch Workflow</h2><ul>";
foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>";
echo "</ul><p><strong>เสร็จสิ้น</strong> — กรุณาลบไฟล์นี้ออกหลังรันสำเร็จ</p>";
