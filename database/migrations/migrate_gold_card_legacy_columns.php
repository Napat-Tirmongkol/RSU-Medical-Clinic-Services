<?php
/**
 * database/migrations/migrate_gold_card_legacy_columns.php
 *
 * เพิ่มคอลัมน์รองรับ legacy data migration จากระบบ welfarecard เก่า
 * ต้องรันไฟล์นี้ก่อน migrate_welfarecard_legacy_data.php
 *
 * เพิ่มใน gold_card_members:
 *  - legacy_id      INT UNSIGNED NULL  (อ้างอิง welfarecard.id เดิม — resume + audit)
 *  - gender         ENUM('male','female','other') NULL
 *  - date_of_birth  DATE NULL
 *  - migrated_at    DATETIME NULL  (timestamp ตอน import)
 *
 * แก้ใน gold_card_documents:
 *  - doc_type ENUM เพิ่ม 'signature'
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ → ตรวจ output → ลบไฟล์ทิ้งหลัง legacy migration เสร็จ
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── gold_card_members: เพิ่ม legacy columns ──────────────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM gold_card_members")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('legacy_id', $cols, true)) {
        $pdo->exec("ALTER TABLE gold_card_members
                    ADD COLUMN legacy_id INT UNSIGNED NULL,
                    ADD INDEX idx_legacy_id (legacy_id)");
        $results[] = '✅ เพิ่ม legacy_id (อ้างอิง welfarecard.id เดิม)';
    } else {
        $results[] = 'ℹ️ legacy_id มีอยู่แล้ว';
    }

    if (!in_array('gender', $cols, true)) {
        $pdo->exec("ALTER TABLE gold_card_members
                    ADD COLUMN gender ENUM('male','female','other') NULL AFTER full_name");
        $results[] = '✅ เพิ่ม gender';
    } else {
        $results[] = 'ℹ️ gender มีอยู่แล้ว';
    }

    if (!in_array('date_of_birth', $cols, true)) {
        $pdo->exec("ALTER TABLE gold_card_members
                    ADD COLUMN date_of_birth DATE NULL AFTER gender");
        $results[] = '✅ เพิ่ม date_of_birth';
    } else {
        $results[] = 'ℹ️ date_of_birth มีอยู่แล้ว';
    }

    if (!in_array('migrated_at', $cols, true)) {
        $pdo->exec("ALTER TABLE gold_card_members
                    ADD COLUMN migrated_at DATETIME NULL,
                    ADD INDEX idx_migrated_at (migrated_at)");
        $results[] = '✅ เพิ่ม migrated_at';
    } else {
        $results[] = 'ℹ️ migrated_at มีอยู่แล้ว';
    }
} catch (PDOException $e) {
    $results[] = '❌ ALTER gold_card_members: ' . $e->getMessage();
}

// ── gold_card_documents: เพิ่ม 'signature' ใน doc_type ENUM ──────────────────
try {
    $col = $pdo->query("SHOW COLUMNS FROM gold_card_documents WHERE Field = 'doc_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'signature'") === false) {
        $pdo->exec("ALTER TABLE gold_card_documents
                    MODIFY COLUMN doc_type
                    ENUM('id_copy','house_reg','application','photo','medical','signature','other')
                    NOT NULL DEFAULT 'other'");
        $results[] = '✅ เพิ่ม signature ใน gold_card_documents.doc_type ENUM';
    } else {
        $results[] = 'ℹ️ signature มีใน doc_type ENUM แล้ว';
    }
} catch (PDOException $e) {
    $results[] = '❌ ALTER gold_card_documents.doc_type: ' . $e->getMessage();
}

// ── Output ────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>Migration: Gold Card Legacy Columns</title>
    <style>
        body { font-family: 'Sarabun', -apple-system, sans-serif; max-width: 760px; margin: 40px auto; padding: 0 20px; color: #1e293b; }
        h2 { margin-bottom: 8px; }
        .subtitle { color: #64748b; margin-bottom: 24px; }
        ul.results { background: #f8fafc; border-left: 4px solid #6366f1; padding: 16px 16px 16px 36px; border-radius: 8px; list-style: none; }
        ul.results li { margin: 6px 0; font-family: 'SF Mono', monospace; font-size: 14px; }
        .next { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px 20px; margin-top: 24px; border-radius: 8px; }
        .next strong { display: block; margin-bottom: 12px; }
        .next ol { padding-left: 20px; }
        .next li { margin: 8px 0; line-height: 1.6; }
        code { background: #1e293b; color: #fde68a; padding: 2px 8px; border-radius: 4px; font-family: 'SF Mono', monospace; font-size: 13px; }
    </style>
</head>
<body>
    <h2>🔧 Migration: Gold Card Legacy Columns</h2>
    <p class="subtitle">เตรียม schema สำหรับ migrate ข้อมูล welfarecard เก่า</p>

    <ul class="results">
    <?php foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>"; ?>
    </ul>

    <div class="next">
        <strong>📋 ขั้นตอนถัดไป:</strong>
        <ol>
            <li>Import legacy SQL: <code>welfarecard.sql</code> + <code>welfarelog.sql</code> + <code>welfareuser.sql</code> เข้า DB เดียวกันกับระบบใหม่</li>
            <li>ตรวจว่า uploads folder อยู่ที่: <code>/var/www/html/e-campaignv2/welfarecard_old/uploads/</code></li>
            <li>เปิด <code>migrate_welfarecard_legacy_data.php?dry=1</code> เพื่อ dry-run</li>
            <li>ตรวจ output → ถ้า OK รันจริง <code>migrate_welfarecard_legacy_data.php</code></li>
            <li>รัน <code>migrate_welfarelog_history.php</code> เพื่อ migrate audit log</li>
            <li>หลังเสร็จทั้งหมด — <strong>ลบ migration files + welfarecard_old folder</strong> ออก</li>
        </ol>
    </div>
</body>
</html>
