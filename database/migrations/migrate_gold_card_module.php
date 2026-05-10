<?php
/**
 * database/migrations/migrate_gold_card_module.php
 *
 * Module: Gold Card (สิทธิบัตรทอง / UC)
 * - gold_card_members      : สมาชิกผู้มีสิทธิ์บัตรทอง (1 row / คน)
 * - gold_card_documents    : เอกสารแนบ (multiple files per member)
 * - gold_card_history      : audit log
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้ทิ้ง
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── gold_card_members ─────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gold_card_members (
        id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        citizen_id        VARCHAR(13)              NOT NULL,
        full_name         VARCHAR(255)             NOT NULL DEFAULT '',
        member_type       VARCHAR(50)              NOT NULL DEFAULT 'บุคคลทั่วไป',
        position          VARCHAR(100)             NOT NULL DEFAULT '',
        phone             VARCHAR(20)              NOT NULL DEFAULT '',
        hospital_main     VARCHAR(150)             NOT NULL DEFAULT '',
        hospital_sub      VARCHAR(150)             NOT NULL DEFAULT '',
        application_date  DATE                     NULL,
        coverage_start    DATE                     NULL,
        coverage_end      DATE                     NULL,
        status            ENUM('pending','submitted','approved','active','rejected','expired')
                                                   NOT NULL DEFAULT 'pending',
        remarks           TEXT                     NULL,
        created_by        INT UNSIGNED             NULL,
        created_at        DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at        DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_citizen_id (citizen_id),
        INDEX idx_status (status),
        INDEX idx_member_type (member_type),
        INDEX idx_hospital_main (hospital_main),
        INDEX idx_coverage_end (coverage_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ gold_card_members — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ gold_card_members: ' . $e->getMessage();
}

// ── gold_card_documents ───────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gold_card_documents (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        member_id     INT UNSIGNED             NOT NULL,
        doc_type      ENUM('id_copy','house_reg','application','photo','medical','other')
                                               NOT NULL DEFAULT 'other',
        file_name     VARCHAR(255)             NOT NULL,
        stored_path   VARCHAR(500)             NOT NULL,
        mime_type     VARCHAR(100)             NULL,
        file_size     INT UNSIGNED             NOT NULL DEFAULT 0,
        sha1_hash     VARCHAR(40)              NULL,
        uploaded_by   INT UNSIGNED             NULL,
        uploaded_at   DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_member (member_id),
        INDEX idx_doc_type (doc_type),
        CONSTRAINT fk_gold_doc_member
            FOREIGN KEY (member_id) REFERENCES gold_card_members(id)
            ON UPDATE CASCADE ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ gold_card_documents — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ gold_card_documents: ' . $e->getMessage();
}

// ── gold_card_history (audit) ─────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gold_card_history (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        member_id   INT UNSIGNED             NULL,
        action      VARCHAR(50)              NOT NULL,
        old_value   TEXT                     NULL,
        new_value   TEXT                     NULL,
        changed_by  INT UNSIGNED             NULL,
        changed_at  DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ip_address  VARCHAR(45)              NULL,
        INDEX idx_member (member_id),
        INDEX idx_action (action),
        INDEX idx_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ gold_card_history — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ gold_card_history: ' . $e->getMessage();
}

// ── Output ────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration: Gold Card Module</h2><ul>";
foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>";
echo "</ul><p><strong>เสร็จสิ้น</strong> — กรุณาลบไฟล์นี้ออกหลังรันสำเร็จ</p>";
