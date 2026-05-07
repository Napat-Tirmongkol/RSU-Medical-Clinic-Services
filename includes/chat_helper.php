<?php
// includes/chat_helper.php — schema + helpers for the live support chat
declare(strict_types=1);

/**
 * สร้าง/อัปเดต schema ของระบบ chat — idempotent, เรียกได้บ่อยจาก endpoint ใดก็ได้
 *
 * - sys_chat_messages: ตารางข้อความหลัก
 * - composite indexes: รองรับ since_id cursor (user_id, id) และ unread count
 *   (user_id, is_read, sender_type) — replaces single-column idx_user เดิม
 *   ที่จะถูก optimizer ทิ้งไปใน query แบบ since_id
 */
function ensure_chat_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_chat_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_type ENUM('user', 'staff') NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            staff_id INT UNSIGNED NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}

    // ALTER ADD INDEX is not idempotent on every MySQL — wrap each in try/catch
    try { $pdo->exec("ALTER TABLE sys_chat_messages ADD INDEX idx_user_pk (user_id, id)"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE sys_chat_messages ADD INDEX idx_unread (user_id, is_read, sender_type)"); } catch (PDOException) {}

    // Internal-note flag — staff-only message that user-facing endpoint must filter out
    try { $pdo->exec("ALTER TABLE sys_chat_messages ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 AFTER message"); } catch (PDOException) {}

    // Per-conversation metadata (status workflow). One row per user_id (lazy-create on first set_status).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_chat_conversations (
            user_id          INT UNSIGNED PRIMARY KEY,
            status           ENUM('open','pending','resolved') NOT NULL DEFAULT 'open',
            resolved_at      DATETIME NULL,
            resolved_by      INT UNSIGNED NULL,
            updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {}
}
