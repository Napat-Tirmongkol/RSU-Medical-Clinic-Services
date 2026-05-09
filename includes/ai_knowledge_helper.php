<?php
/**
 * includes/ai_knowledge_helper.php
 *
 * Custom notes ที่ admin เพิ่มเข้าไปให้ AI ใช้อ้างอิง — services, pricing,
 * policies, ข้อมูลพิเศษ ฯลฯ ที่ไม่อยู่ในตาราง schedule/profile/FAQ
 *
 * Notes ที่ active จะถูกฉีดเข้า ai_qa_build_clinic_context() ตอน AI gen
 * ตอบและ matcher pool
 */
declare(strict_types=1);

function ensure_ai_knowledge_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_ai_clinic_notes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(160) NOT NULL,
            content TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by INT UNSIGNED NULL,
            INDEX idx_active_sort (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    } catch (Throwable $e) {
        error_log('ensure_ai_knowledge_schema failed: ' . $e->getMessage());
    }
}

/** ดึง notes ทั้งหมด (สำหรับหน้า admin) */
function list_clinic_notes(PDO $pdo): array
{
    ensure_ai_knowledge_schema($pdo);
    try {
        $stmt = $pdo->query("
            SELECT id, label, content, sort_order, is_active, updated_at
              FROM sys_ai_clinic_notes
             ORDER BY sort_order ASC, id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('list_clinic_notes failed: ' . $e->getMessage());
        return [];
    }
}

/** ดึงเฉพาะ active notes (สำหรับ context builder) */
function get_active_clinic_notes(PDO $pdo): array
{
    ensure_ai_knowledge_schema($pdo);
    try {
        $stmt = $pdo->query("
            SELECT label, content
              FROM sys_ai_clinic_notes
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function create_clinic_note(PDO $pdo, string $label, string $content, int $sortOrder = 0, ?int $adminId = null): int
{
    ensure_ai_knowledge_schema($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO sys_ai_clinic_notes (label, content, sort_order, is_active, updated_by)
        VALUES (:l, :c, :s, 1, :u)
    ");
    $stmt->execute([
        ':l' => mb_substr(trim($label), 0, 160),
        ':c' => trim($content),
        ':s' => $sortOrder,
        ':u' => $adminId,
    ]);
    return (int)$pdo->lastInsertId();
}

function update_clinic_note(PDO $pdo, int $id, string $label, string $content, int $sortOrder, ?int $adminId = null): bool
{
    ensure_ai_knowledge_schema($pdo);
    $stmt = $pdo->prepare("
        UPDATE sys_ai_clinic_notes
           SET label = :l, content = :c, sort_order = :s, updated_by = :u
         WHERE id = :id
    ");
    return $stmt->execute([
        ':id' => $id,
        ':l'  => mb_substr(trim($label), 0, 160),
        ':c'  => trim($content),
        ':s'  => $sortOrder,
        ':u'  => $adminId,
    ]);
}

function toggle_clinic_note(PDO $pdo, int $id, bool $isActive): bool
{
    ensure_ai_knowledge_schema($pdo);
    $stmt = $pdo->prepare("UPDATE sys_ai_clinic_notes SET is_active = :a WHERE id = :id");
    return $stmt->execute([':id' => $id, ':a' => $isActive ? 1 : 0]);
}

function delete_clinic_note(PDO $pdo, int $id): bool
{
    ensure_ai_knowledge_schema($pdo);
    $stmt = $pdo->prepare("DELETE FROM sys_ai_clinic_notes WHERE id = :id");
    return $stmt->execute([':id' => $id]);
}

/** Render notes block สำหรับ context builder — คืน '' ถ้าไม่มี */
function render_clinic_notes_block(PDO $pdo): string
{
    $notes = get_active_clinic_notes($pdo);
    if (empty($notes)) return '';
    $lines = [];
    foreach ($notes as $n) {
        $label = trim((string)$n['label']);
        $content = trim((string)$n['content']);
        if ($label === '' || $content === '') continue;
        $lines[] = "● {$label}\n" . preg_replace('/^/m', '  ', $content);
    }
    return implode("\n\n", $lines);
}
