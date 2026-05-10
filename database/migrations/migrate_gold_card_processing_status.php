<?php
/**
 * Migration: Gold Card — เพิ่ม `processing` status + `approval` doc_type
 * Use case: รองรับ flow การย้ายบัตรทอง 4 ขั้นตอน
 *   1. user กรอกใบสมัคร       → submitted
 *   2. admin กำลังย้ายให้      → processing  ← NEW
 *   3. ได้ PDF อนุมัติกลับ     → upload เป็น doc_type='approval'  ← NEW
 *   4. admin กดอนุมัติ         → approved (require ≥1 doc 'approval' ก่อน)
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) Expand status ENUM in gold_card_members ──────────────────────────────
try {
    $pdo->exec("ALTER TABLE gold_card_members
        MODIFY status ENUM('pending','submitted','processing','approved','active','rejected','expired')
        NOT NULL DEFAULT 'pending'");
    $results[] = '✅ gold_card_members.status — เพิ่ม `processing` แล้ว';
} catch (PDOException $e) {
    $results[] = '❌ gold_card_members.status: ' . $e->getMessage();
}

// ── 2) Expand doc_type ENUM in gold_card_documents ──────────────────────────
try {
    $pdo->exec("ALTER TABLE gold_card_documents
        MODIFY doc_type ENUM('id_copy','house_reg','application','photo','medical','approval','other')
        NOT NULL DEFAULT 'other'");
    $results[] = '✅ gold_card_documents.doc_type — เพิ่ม `approval` แล้ว';
} catch (PDOException $e) {
    $results[] = '❌ gold_card_documents.doc_type: ' . $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration: Gold Card — Processing Status + Approval Doc</h2><ul>";
foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>";
echo "</ul><p><strong>เสร็จสิ้น</strong> — กรุณาลบไฟล์นี้ออกหลังรันสำเร็จ</p>";
