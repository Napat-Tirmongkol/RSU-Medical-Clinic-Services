<?php
/**
 * includes/line_chat_helper.php
 * Backend สำหรับ LINE Admin Chat (admin ↔ LINE user)
 *
 * - Auto-migrate sys_line_chat_messages
 * - Log inbound (webhook → user message) + outbound (admin/ai → user)
 * - Group messages by line_user_id เป็น conversation
 * - Helper สำหรับ admin reply → push ผ่าน send_line_push() + log
 */

declare(strict_types=1);

require_once __DIR__ . '/line_helper.php';

// ──────────────────────────────────────────────────────────────────
// SCHEMA — auto-migrate (idempotent)
// ──────────────────────────────────────────────────────────────────
function line_chat_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sys_line_chat_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                line_user_id VARCHAR(64) NOT NULL,
                line_display_name VARCHAR(120) NULL,
                direction ENUM('inbound','outbound') NOT NULL,
                sender_type ENUM('user','ai','admin','system') NOT NULL,
                message_text MEDIUMTEXT NOT NULL,
                message_type VARCHAR(20) NOT NULL DEFAULT 'text',
                line_message_id VARCHAR(64) NULL,
                admin_id INT NULL,
                push_ok TINYINT(1) NULL,
                error TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_time (line_user_id, created_at),
                INDEX idx_direction (direction, created_at),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    } catch (PDOException $e) {
        error_log('[line_chat] ensure_schema failed: ' . $e->getMessage());
    }
}

// ──────────────────────────────────────────────────────────────────
// LOG — best-effort, never throw (don't block webhook on log failure)
// ──────────────────────────────────────────────────────────────────

/**
 * Log incoming LINE message — called from webhook
 * Returns true if logged, false on error (logged but swallowed)
 */
function line_chat_log_inbound(PDO $pdo, string $lineUserId, string $messageText, ?string $lineMsgId = null, ?string $displayName = null, string $messageType = 'text'): bool
{
    if ($lineUserId === '' || $messageText === '') return false;
    line_chat_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sys_line_chat_messages
                (line_user_id, line_display_name, direction, sender_type, message_text, message_type, line_message_id)
            VALUES (?, ?, 'inbound', 'user', ?, ?, ?)
        ");
        return $stmt->execute([$lineUserId, $displayName, $messageText, $messageType, $lineMsgId]);
    } catch (PDOException $e) {
        error_log('[line_chat] log_inbound failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Log outbound LINE message (from AI, admin, or system)
 * @param string $senderType  'ai' | 'admin' | 'system'
 * @param ?int   $adminId     admin id ถ้าเป็น admin reply
 * @param ?bool  $pushOk      true/false หลังเรียก LINE push
 */
function line_chat_log_outbound(PDO $pdo, string $lineUserId, string $messageText, string $senderType, ?int $adminId = null, ?bool $pushOk = null, ?string $error = null): bool
{
    if ($lineUserId === '' || $messageText === '') return false;
    if (!in_array($senderType, ['ai', 'admin', 'system'], true)) $senderType = 'system';
    line_chat_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sys_line_chat_messages
                (line_user_id, direction, sender_type, message_text, admin_id, push_ok, error)
            VALUES (?, 'outbound', ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $lineUserId,
            $senderType,
            $messageText,
            $adminId,
            $pushOk === null ? null : ($pushOk ? 1 : 0),
            $error,
        ]);
    } catch (PDOException $e) {
        error_log('[line_chat] log_outbound failed: ' . $e->getMessage());
        return false;
    }
}

// ──────────────────────────────────────────────────────────────────
// CONVERSATION QUERIES
// ──────────────────────────────────────────────────────────────────

/**
 * รายการ conversation ล่าสุด — group by line_user_id
 * "needs_reply" คือ inbound ล่าสุด > outbound ล่าสุด (admin ยังไม่ตอบ)
 *
 * @param string $filter  'all' | 'needs_reply' | 'today'
 */
function line_chat_list_conversations(PDO $pdo, string $filter = 'all', int $page = 1, int $perPage = 20): array
{
    line_chat_ensure_schema($pdo);
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    // Per-user aggregation with correlated subqueries for "last message" preview
    $base = "
        SELECT
            t.line_user_id,
            MAX(t.line_display_name) AS line_display_name,
            COUNT(*) AS total_msgs,
            SUM(CASE WHEN t.direction='inbound' THEN 1 ELSE 0 END) AS inbound_count,
            SUM(CASE WHEN t.direction='outbound' THEN 1 ELSE 0 END) AS outbound_count,
            MAX(CASE WHEN t.direction='inbound'  THEN t.created_at END) AS last_inbound_at,
            MAX(CASE WHEN t.direction='outbound' THEN t.created_at END) AS last_outbound_at,
            MAX(t.created_at) AS last_msg_at,
            (SELECT message_text FROM sys_line_chat_messages
              WHERE line_user_id = t.line_user_id ORDER BY id DESC LIMIT 1) AS last_msg_text,
            (SELECT direction FROM sys_line_chat_messages
              WHERE line_user_id = t.line_user_id ORDER BY id DESC LIMIT 1) AS last_msg_direction
        FROM sys_line_chat_messages t
        GROUP BY t.line_user_id
    ";

    $where = "1=1";
    $params = [];
    if ($filter === 'needs_reply') {
        // Latest inbound newer than latest outbound (admin/ai/system) — or no outbound at all
        $where = "(last_outbound_at IS NULL OR last_inbound_at > last_outbound_at)";
    } elseif ($filter === 'today') {
        $where = "DATE(last_msg_at) = CURDATE()";
    }

    $countSql = "SELECT COUNT(*) FROM ($base) AS c WHERE $where";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listSql = "
        SELECT * FROM ($base) AS c
        WHERE $where
        ORDER BY last_msg_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($listSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['needs_reply'] = (empty($r['last_outbound_at']) || ($r['last_inbound_at'] && $r['last_inbound_at'] > $r['last_outbound_at'])) ? 1 : 0;
    }
    unset($r);

    return [
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
        'filter'   => $filter,
    ];
}

/**
 * ดึง message history ของ 1 line_user_id (ล่าสุด N ข้อความ)
 */
function line_chat_get_conversation(PDO $pdo, string $lineUserId, int $limit = 200): array
{
    line_chat_ensure_schema($pdo);
    $limit = max(1, min(1000, $limit));
    $stmt = $pdo->prepare("
        SELECT id, line_user_id, line_display_name, direction, sender_type,
               message_text, message_type, admin_id, push_ok, error, created_at
        FROM sys_line_chat_messages
        WHERE line_user_id = ?
        ORDER BY id DESC
        LIMIT $limit
    ");
    $stmt->execute([$lineUserId]);
    $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    // Display name = ล่าสุดที่ไม่ NULL
    $displayName = null;
    foreach ($messages as $m) {
        if (!empty($m['line_display_name'])) { $displayName = $m['line_display_name']; break; }
    }

    return [
        'line_user_id'      => $lineUserId,
        'line_display_name' => $displayName,
        'messages'          => $messages,
    ];
}

// ──────────────────────────────────────────────────────────────────
// ANTI-ABUSE — gate admin reply
// ──────────────────────────────────────────────────────────────────

/**
 * ตรวจว่า line_user_id เคยติดต่อคลินิกแล้วหรือไม่
 * กันการใช้ระบบเป็น phishing relay (admin ส่งหา userId ที่ไม่เคยทักมา)
 */
function line_chat_user_has_history(PDO $pdo, string $lineUserId): bool
{
    if ($lineUserId === '') return false;
    line_chat_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM sys_line_chat_messages WHERE line_user_id = ? AND direction='inbound' LIMIT 1");
        $stmt->execute([$lineUserId]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[line_chat] user_has_history failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * ตรวจ rate limit ของ admin ก่อนยิง push
 * Default: 30/min, 500/day per admin
 * @return ?string  null ถ้าผ่าน, string error message ถ้าเกิน
 */
function line_chat_check_rate_limit(PDO $pdo, int $adminId, int $maxPerMinute = 30, int $maxPerDay = 500): ?string
{
    line_chat_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM sys_line_chat_messages
            WHERE admin_id = ? AND direction='outbound' AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$adminId]);
        if ((int)$stmt->fetchColumn() >= $maxPerMinute) {
            return "เกินขีดจำกัด {$maxPerMinute} ข้อความ/นาที — โปรดรอสักครู่";
        }

        $stmt2 = $pdo->prepare("
            SELECT COUNT(*) FROM sys_line_chat_messages
            WHERE admin_id = ? AND direction='outbound' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $stmt2->execute([$adminId]);
        if ((int)$stmt2->fetchColumn() >= $maxPerDay) {
            return "เกินขีดจำกัด {$maxPerDay} ข้อความ/วัน — โปรดติดต่อ superadmin";
        }
    } catch (PDOException $e) {
        error_log('[line_chat] rate_limit check failed: ' . $e->getMessage());
        // Fail-open on DB error — log but don't block admin (the limits are not security-critical)
    }
    return null;
}

// ──────────────────────────────────────────────────────────────────
// ADMIN REPLY — push to LINE + log
// ──────────────────────────────────────────────────────────────────

/**
 * Admin ส่งข้อความตอบ LINE user → call send_line_push() + log
 *
 * @return array{ok:bool, push_ok:bool, message:string, error:?string}
 * @throws RuntimeException ถ้า validation/rate-limit/history fail
 */
function line_chat_send_admin_reply(PDO $pdo, string $lineUserId, string $messageText, int $adminId): array
{
    $messageText = trim($messageText);
    if ($lineUserId === '') throw new RuntimeException('line_user_id ว่าง');
    if ($messageText === '') throw new RuntimeException('ข้อความว่าง');
    if (mb_strlen($messageText) > 4000) throw new RuntimeException('ข้อความยาวเกิน 4,000 ตัวอักษร');

    // LINE user IDs are 'U' + 32 hex chars — reject malformed early
    if (!preg_match('/^U[0-9a-f]{32}$/i', $lineUserId)) {
        throw new RuntimeException('line_user_id รูปแบบไม่ถูกต้อง');
    }

    // Anti-impersonation: only allow push to LINE users who have contacted clinic before
    if (!line_chat_user_has_history($pdo, $lineUserId)) {
        throw new RuntimeException('ไม่พบ user นี้ในประวัติ — admin ตอบได้เฉพาะคนที่ทักเข้ามาก่อนเท่านั้น');
    }

    // Rate limit per-admin
    $rateMsg = line_chat_check_rate_limit($pdo, $adminId);
    if ($rateMsg !== null) throw new RuntimeException($rateMsg);

    // Load access token
    $token = '';
    if (defined('LINE_CHANNEL_ACCESS_TOKEN')) $token = (string)LINE_CHANNEL_ACCESS_TOKEN;
    if ($token === '') throw new RuntimeException('LINE_CHANNEL_ACCESS_TOKEN ยังไม่ตั้งค่า');

    // Push to LINE
    $messages = [['type' => 'text', 'text' => $messageText]];
    $pushOk = false;
    $errorMsg = null;
    try {
        $pushOk = send_line_push($lineUserId, $messages, $token);
        if (!$pushOk && function_exists('get_last_line_error')) {
            $errorMsg = get_last_line_error() ?: 'send_line_push returned false';
        }
    } catch (Throwable $e) {
        $errorMsg = $e->getMessage();
        error_log('[line_chat] push exception: ' . $errorMsg);
    }

    // Log regardless of push success — keep audit trail
    line_chat_log_outbound($pdo, $lineUserId, $messageText, 'admin', $adminId, $pushOk, $errorMsg);

    return [
        'ok'      => $pushOk,
        'push_ok' => $pushOk,
        'message' => $messageText,
        'error'   => $errorMsg,
    ];
}
