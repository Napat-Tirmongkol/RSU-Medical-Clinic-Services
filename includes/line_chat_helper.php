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
// CONFIG — load LINE token from config/secrets.php
// ──────────────────────────────────────────────────────────────────
function line_chat_load_access_token(): string
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = '';
    $secretsPath = __DIR__ . '/../config/secrets.php';
    if (is_file($secretsPath)) {
        try {
            $secrets = require $secretsPath;
            if (is_array($secrets)) {
                $cached = (string)($secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '');
            }
        } catch (Throwable $e) {
            error_log('[line_chat] load_access_token failed: ' . $e->getMessage());
        }
    }
    return $cached;
}

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
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sys_line_profiles (
                line_user_id VARCHAR(64) PRIMARY KEY,
                display_name VARCHAR(120) NULL,
                picture_url VARCHAR(500) NULL,
                status_message TEXT NULL,
                last_fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_last_fetched (last_fetched_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Per-conversation state (resolved/tags/internal note) — joined into list query
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sys_line_conversations (
                line_user_id VARCHAR(64) PRIMARY KEY,
                is_resolved TINYINT(1) NOT NULL DEFAULT 0,
                resolved_at TIMESTAMP NULL,
                resolved_by INT NULL,
                tags VARCHAR(500) NULL,
                internal_note MEDIUMTEXT NULL,
                note_updated_at TIMESTAMP NULL,
                note_updated_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_resolved (is_resolved, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Saved quick-reply templates
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sys_line_reply_templates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(120) NOT NULL,
                body MEDIUMTEXT NOT NULL,
                category VARCHAR(60) NOT NULL DEFAULT 'ทั่วไป',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                use_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active_sort (is_active, sort_order),
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    } catch (PDOException $e) {
        error_log('[line_chat] ensure_schema failed: ' . $e->getMessage());
    }
}

// ──────────────────────────────────────────────────────────────────
// PROFILE — cache + LINE API fetch + system user match
// ──────────────────────────────────────────────────────────────────

/** ดึง profile จาก LINE Messaging API (live call — ไม่ cache) */
function line_chat_fetch_profile_from_line(string $lineUserId): ?array
{
    $token = line_chat_load_access_token();
    if ($token === '' || $lineUserId === '') return null;

    $url = "https://api.line.me/v2/bot/profile/" . urlencode($lineUserId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log("[line_chat] profile API HTTP $code for " . substr($lineUserId, 0, 12));
        return null;
    }
    $data = json_decode((string)$resp, true);
    if (!is_array($data)) return null;
    return [
        'displayName'   => $data['displayName'] ?? null,
        'pictureUrl'    => $data['pictureUrl']  ?? null,
        'statusMessage' => $data['statusMessage'] ?? null,
    ];
}

/**
 * คืน profile ของ LINE user — cache-aware
 * @param int $staleSeconds  default 7 วัน — refresh ถ้า cache เก่ากว่านี้
 */
function line_chat_get_profile(PDO $pdo, string $lineUserId, int $staleSeconds = 604800): ?array
{
    if ($lineUserId === '') return null;
    line_chat_ensure_schema($pdo);

    $cached = null;
    try {
        $stmt = $pdo->prepare("
            SELECT display_name, picture_url, status_message,
                   UNIX_TIMESTAMP(last_fetched_at) AS fetched_ts
            FROM sys_line_profiles WHERE line_user_id = ?
        ");
        $stmt->execute([$lineUserId]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('[line_chat] profile cache read failed: ' . $e->getMessage());
    }

    $now = time();
    if ($cached && ($now - (int)$cached['fetched_ts']) < $staleSeconds) {
        return [
            'display_name'   => $cached['display_name'],
            'picture_url'    => $cached['picture_url'],
            'status_message' => $cached['status_message'],
        ];
    }

    // Stale or missing — try LINE API
    $fresh = line_chat_fetch_profile_from_line($lineUserId);
    if (!$fresh) {
        // API failed — fallback to stale cache if any
        return $cached ? [
            'display_name'   => $cached['display_name'],
            'picture_url'    => $cached['picture_url'],
            'status_message' => $cached['status_message'],
        ] : null;
    }

    // Upsert cache
    try {
        $up = $pdo->prepare("
            INSERT INTO sys_line_profiles (line_user_id, display_name, picture_url, status_message)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                display_name   = VALUES(display_name),
                picture_url    = VALUES(picture_url),
                status_message = VALUES(status_message),
                last_fetched_at = CURRENT_TIMESTAMP
        ");
        $up->execute([$lineUserId, $fresh['displayName'], $fresh['pictureUrl'], $fresh['statusMessage']]);
    } catch (PDOException $e) {
        error_log('[line_chat] profile cache upsert failed: ' . $e->getMessage());
    }

    return [
        'display_name'   => $fresh['displayName'],
        'picture_url'    => $fresh['pictureUrl'],
        'status_message' => $fresh['statusMessage'],
    ];
}

/**
 * Match LINE UID → sys_users row (returns prefix, full_name, status, student_personnel_id)
 * ใช้ helper เดิม find_user_by_line_uid() (handle line_user_id_new fallback)
 */
function line_chat_match_system_user(PDO $pdo, string $lineUserId): ?array
{
    if ($lineUserId === '') return null;
    require_once __DIR__ . '/line_helper.php';
    if (!function_exists('find_user_by_line_uid')) return null;
    try {
        return find_user_by_line_uid($pdo, $lineUserId,
            'id, prefix, full_name, status, student_personnel_id, picture_url'
        );
    } catch (Throwable $e) {
        error_log('[line_chat] match_system_user failed: ' . $e->getMessage());
        return null;
    }
}

/** Map status code → Thai label + tone for badge */
function line_chat_status_label(?string $status): array
{
    $map = [
        'student' => ['label' => 'นักศึกษา',      'tone' => 'info'],
        'faculty' => ['label' => 'อาจารย์',        'tone' => 'accent'],
        'staff'   => ['label' => 'เจ้าหน้าที่',     'tone' => 'amber'],
        'other'   => ['label' => 'บุคคลทั่วไป',    'tone' => 'slate'],
    ];
    return $map[$status ?? ''] ?? ['label' => 'ไม่ระบุ', 'tone' => 'slate'];
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
 * @param string $filter  'all' | 'needs_reply' | 'today' | 'resolved' | 'unresolved'
 * @param string $search  free-text search ใน display_name / line_user_id / last_msg_text
 */
function line_chat_list_conversations(PDO $pdo, string $filter = 'all', int $page = 1, int $perPage = 20, string $search = ''): array
{
    line_chat_ensure_schema($pdo);
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;
    $search = trim($search);

    // Per-user aggregation — uses MAX(id) self-join for "last message" preview
    // (one index seek per user instead of 3 correlated subqueries — see PR review notes)
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
            MAX(t.id) AS last_msg_id
        FROM sys_line_chat_messages t
        GROUP BY t.line_user_id
    ";

    $whereParts = ["1=1"];
    $params = [];

    if ($filter === 'needs_reply') {
        $whereParts[] = "(c.last_outbound_at IS NULL OR c.last_inbound_at > c.last_outbound_at)";
        $whereParts[] = "COALESCE(s.is_resolved, 0) = 0";
    } elseif ($filter === 'today') {
        $whereParts[] = "DATE(c.last_msg_at) = CURDATE()";
    } elseif ($filter === 'resolved') {
        $whereParts[] = "COALESCE(s.is_resolved, 0) = 1";
    } elseif ($filter === 'unresolved') {
        $whereParts[] = "COALESCE(s.is_resolved, 0) = 0";
    }

    if ($search !== '') {
        $whereParts[] = "(p.display_name LIKE :kw OR c.line_user_id LIKE :kw OR lm.message_text LIKE :kw OR COALESCE(s.tags,'') LIKE :kw)";
        $params[':kw'] = '%' . $search . '%';
    }

    $where = implode(' AND ', $whereParts);
    $needsLm = ($search !== '');

    // Count joins lm only when search references message_text — saves an index seek otherwise.
    $countSql = "
        SELECT COUNT(*)
        FROM ($base) AS c
        LEFT JOIN sys_line_profiles p ON p.line_user_id = c.line_user_id
        LEFT JOIN sys_line_conversations s ON s.line_user_id = c.line_user_id
        " . ($needsLm ? "LEFT JOIN sys_line_chat_messages lm ON lm.id = c.last_msg_id" : "") . "
        WHERE $where
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // Listing JOINs back to the messages table by MAX(id) for last_msg_* fields
    // — single PK seek per row instead of 3 correlated LIMIT-1 subqueries.
    $listSql = "
        SELECT c.*,
               p.display_name AS profile_display_name,
               p.picture_url  AS profile_picture_url,
               s.is_resolved  AS is_resolved,
               s.tags         AS tags,
               lm.message_text AS last_msg_text,
               lm.message_type AS last_msg_type,
               lm.direction    AS last_msg_direction
        FROM ($base) AS c
        LEFT JOIN sys_line_profiles p ON p.line_user_id = c.line_user_id
        LEFT JOIN sys_line_conversations s ON s.line_user_id = c.line_user_id
        LEFT JOIN sys_line_chat_messages lm ON lm.id = c.last_msg_id
        WHERE $where
        ORDER BY c.last_msg_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($listSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['needs_reply'] = (empty($r['last_outbound_at']) || ($r['last_inbound_at'] && $r['last_inbound_at'] > $r['last_outbound_at'])) ? 1 : 0;
        $r['is_resolved'] = (int)($r['is_resolved'] ?? 0);
        $r['tags_list'] = $r['tags'] ? array_values(array_filter(array_map('trim', explode(',', $r['tags'])))) : [];
        // System user match — adds prefix/full_name/status if user registered in portal
        $sysUser = line_chat_match_system_user($pdo, (string)$r['line_user_id']);
        if ($sysUser) {
            $statusInfo = line_chat_status_label($sysUser['status'] ?? null);
            $r['system_user'] = [
                'prefix'                => $sysUser['prefix'] ?? null,
                'full_name'             => $sysUser['full_name'] ?? null,
                'status'                => $sysUser['status'] ?? null,
                'status_label'          => $statusInfo['label'],
                'status_tone'           => $statusInfo['tone'],
                'student_personnel_id'  => $sysUser['student_personnel_id'] ?? null,
            ];
        } else {
            $r['system_user'] = null;
        }
    }
    unset($r);

    return [
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / $perPage),
        'filter'   => $filter,
        'search'   => $search,
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

    // Profile lookup (refreshes cache if stale — admin opening = good trigger)
    $profile = line_chat_get_profile($pdo, $lineUserId);
    $sysUser = line_chat_match_system_user($pdo, $lineUserId);
    $systemBundle = null;
    if ($sysUser) {
        $statusInfo = line_chat_status_label($sysUser['status'] ?? null);
        $systemBundle = [
            'prefix'                => $sysUser['prefix'] ?? null,
            'full_name'             => $sysUser['full_name'] ?? null,
            'status'                => $sysUser['status'] ?? null,
            'status_label'          => $statusInfo['label'],
            'status_tone'           => $statusInfo['tone'],
            'student_personnel_id'  => $sysUser['student_personnel_id'] ?? null,
        ];
    }

    // Fallback display name: snapshot in messages → profile → null
    $displayName = $profile['display_name'] ?? null;
    if (!$displayName) {
        foreach ($messages as $m) {
            if (!empty($m['line_display_name'])) { $displayName = $m['line_display_name']; break; }
        }
    }

    return [
        'line_user_id'      => $lineUserId,
        'line_display_name' => $displayName,
        'line_picture_url'  => $profile['picture_url'] ?? null,
        'line_status_msg'   => $profile['status_message'] ?? null,
        'system_user'       => $systemBundle,
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

    // Load access token from config/secrets.php (same pattern as api/line_webhook.php)
    $token = line_chat_load_access_token();
    if ($token === '') throw new RuntimeException('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ยังไม่ตั้งค่าใน config/secrets.php');

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

    // Successful admin reply implicitly un-resolves the conversation (new activity)
    if ($pushOk) {
        try {
            $pdo->prepare("
                INSERT INTO sys_line_conversations (line_user_id, is_resolved)
                VALUES (?, 0)
                ON DUPLICATE KEY UPDATE is_resolved = 0, resolved_at = NULL, resolved_by = NULL
            ")->execute([$lineUserId]);
        } catch (PDOException $e) {
            error_log('[line_chat] auto-unresolve failed: ' . $e->getMessage());
        }
    }

    return [
        'ok'      => $pushOk,
        'push_ok' => $pushOk,
        'message' => $messageText,
        'error'   => $errorMsg,
    ];
}

// ──────────────────────────────────────────────────────────────────
// CONVERSATION STATE — resolved / tags / internal note
// ──────────────────────────────────────────────────────────────────

/**
 * ดึง state ของ 1 conversation (resolved / tags / note)
 */
function line_chat_get_convo_state(PDO $pdo, string $lineUserId): array
{
    line_chat_ensure_schema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, a.username AS resolved_by_name, b.username AS note_updated_by_name
            FROM sys_line_conversations s
            LEFT JOIN sys_admins a ON a.id = s.resolved_by
            LEFT JOIN sys_admins b ON b.id = s.note_updated_by
            WHERE s.line_user_id = ?
        ");
        $stmt->execute([$lineUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[line_chat] get_convo_state failed: ' . $e->getMessage());
        $row = false;
    }
    if (!$row) {
        return [
            'line_user_id' => $lineUserId,
            'is_resolved'  => 0,
            'resolved_at'  => null,
            'resolved_by'  => null,
            'resolved_by_name' => null,
            'tags'         => '',
            'tags_list'    => [],
            'internal_note'    => '',
            'note_updated_at'  => null,
            'note_updated_by'  => null,
            'note_updated_by_name' => null,
        ];
    }
    $row['is_resolved'] = (int)$row['is_resolved'];
    $row['tags_list'] = $row['tags'] ? array_values(array_filter(array_map('trim', explode(',', $row['tags'])))) : [];
    return $row;
}

/** Upsert resolved flag (true = ปิดเคส, false = เปิดอีกครั้ง) */
function line_chat_set_resolved(PDO $pdo, string $lineUserId, bool $resolved, int $adminId): bool
{
    if ($lineUserId === '') return false;
    line_chat_ensure_schema($pdo);
    try {
        $sql = $resolved
            ? "INSERT INTO sys_line_conversations (line_user_id, is_resolved, resolved_at, resolved_by)
                VALUES (?, 1, NOW(), ?)
                ON DUPLICATE KEY UPDATE is_resolved = 1, resolved_at = NOW(), resolved_by = VALUES(resolved_by)"
            : "INSERT INTO sys_line_conversations (line_user_id, is_resolved)
                VALUES (?, 0)
                ON DUPLICATE KEY UPDATE is_resolved = 0, resolved_at = NULL, resolved_by = NULL";
        $params = $resolved ? [$lineUserId, $adminId] : [$lineUserId];
        return $pdo->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        error_log('[line_chat] set_resolved failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Set tags — comma-separated list (จำกัด 10 tag ต่อ convo, แต่ละ tag ≤30 char)
 */
function line_chat_set_tags(PDO $pdo, string $lineUserId, array $tags, int $adminId): bool
{
    if ($lineUserId === '') return false;
    line_chat_ensure_schema($pdo);
    $clean = [];
    foreach ($tags as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        if (mb_strlen($t) > 30) $t = mb_substr($t, 0, 30);
        // Strip comma + control chars (tag separator)
        $t = preg_replace('/[,\\x00-\\x1f]/u', '', $t);
        if ($t !== '' && !in_array($t, $clean, true)) $clean[] = $t;
        if (count($clean) >= 10) break;
    }
    $joined = implode(',', $clean);
    if (strlen($joined) > 500) {
        $joined = substr($joined, 0, 500);
    }
    try {
        return $pdo->prepare("
            INSERT INTO sys_line_conversations (line_user_id, tags) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE tags = VALUES(tags)
        ")->execute([$lineUserId, $joined]);
    } catch (PDOException $e) {
        error_log('[line_chat] set_tags failed: ' . $e->getMessage());
        return false;
    }
}

/** Set internal note (admin-only — ไม่ส่งให้ LINE user) */
function line_chat_set_note(PDO $pdo, string $lineUserId, string $note, int $adminId): bool
{
    if ($lineUserId === '') return false;
    line_chat_ensure_schema($pdo);
    $note = trim($note);
    if (mb_strlen($note) > 5000) {
        $note = mb_substr($note, 0, 5000);
    }
    try {
        return $pdo->prepare("
            INSERT INTO sys_line_conversations (line_user_id, internal_note, note_updated_at, note_updated_by)
            VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE internal_note = VALUES(internal_note),
                                    note_updated_at = NOW(),
                                    note_updated_by = VALUES(note_updated_by)
        ")->execute([$lineUserId, $note, $adminId]);
    } catch (PDOException $e) {
        error_log('[line_chat] set_note failed: ' . $e->getMessage());
        return false;
    }
}

// ──────────────────────────────────────────────────────────────────
// REPLY TEMPLATES — quick canned responses
// ──────────────────────────────────────────────────────────────────

/** List templates — grouped by category for UI dropdown */
function line_chat_template_list(PDO $pdo, bool $activeOnly = true): array
{
    line_chat_ensure_schema($pdo);
    $where = $activeOnly ? "WHERE is_active = 1" : "";
    try {
        $stmt = $pdo->query("
            SELECT id, title, body, category, sort_order, is_active, use_count, updated_at
            FROM sys_line_reply_templates
            $where
            ORDER BY category ASC, sort_order ASC, id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('[line_chat] template_list failed: ' . $e->getMessage());
        return [];
    }
}

function line_chat_template_create(PDO $pdo, string $title, string $body, string $category, int $adminId): int
{
    line_chat_ensure_schema($pdo);
    $title = trim($title); $body = trim($body); $category = trim($category) ?: 'ทั่วไป';
    if ($title === '' || $body === '') throw new RuntimeException('ชื่อและเนื้อหา template ห้ามว่าง');
    if (mb_strlen($title) > 120) $title = mb_substr($title, 0, 120);
    if (mb_strlen($category) > 60) $category = mb_substr($category, 0, 60);
    if (mb_strlen($body) > 4000) throw new RuntimeException('Template เนื้อหายาวเกิน 4,000 ตัวอักษร');
    $stmt = $pdo->prepare("
        INSERT INTO sys_line_reply_templates (title, body, category, created_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$title, $body, $category, $adminId]);
    return (int)$pdo->lastInsertId();
}

function line_chat_template_update(PDO $pdo, int $id, string $title, string $body, string $category, ?int $sortOrder = null): bool
{
    line_chat_ensure_schema($pdo);
    $title = trim($title); $body = trim($body); $category = trim($category) ?: 'ทั่วไป';
    if ($title === '' || $body === '') throw new RuntimeException('ชื่อและเนื้อหา template ห้ามว่าง');
    if (mb_strlen($title) > 120) $title = mb_substr($title, 0, 120);
    if (mb_strlen($category) > 60) $category = mb_substr($category, 0, 60);
    if (mb_strlen($body) > 4000) throw new RuntimeException('Template เนื้อหายาวเกิน 4,000 ตัวอักษร');
    if ($sortOrder !== null) {
        return $pdo->prepare("UPDATE sys_line_reply_templates SET title=?, body=?, category=?, sort_order=? WHERE id=?")
            ->execute([$title, $body, $category, $sortOrder, $id]);
    }
    return $pdo->prepare("UPDATE sys_line_reply_templates SET title=?, body=?, category=? WHERE id=?")
        ->execute([$title, $body, $category, $id]);
}

function line_chat_template_delete(PDO $pdo, int $id): bool
{
    line_chat_ensure_schema($pdo);
    return $pdo->prepare("DELETE FROM sys_line_reply_templates WHERE id=?")->execute([$id]);
}

function line_chat_template_toggle(PDO $pdo, int $id): bool
{
    line_chat_ensure_schema($pdo);
    return $pdo->prepare("UPDATE sys_line_reply_templates SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
}

function line_chat_template_bump_use(PDO $pdo, int $id): void
{
    line_chat_ensure_schema($pdo);
    try {
        $pdo->prepare("UPDATE sys_line_reply_templates SET use_count = use_count + 1 WHERE id=?")->execute([$id]);
    } catch (PDOException $e) {
        error_log('[line_chat] template_bump_use failed: ' . $e->getMessage());
    }
}

// ──────────────────────────────────────────────────────────────────
// AI SUGGESTED REPLY — call Gemini with conversation context
// ──────────────────────────────────────────────────────────────────

/**
 * Suggest a reply for the admin based on last N messages of the conversation.
 * Uses Gemini 2.5 Flash via ai_qa_load_gemini_key() pattern.
 *
 * @param string $hint  ข้อความใบ้จาก admin (optional) — เช่น "ตอบเรื่องนัดหมาย"
 * @return array{answer:string, model:string, elapsed_ms:int}
 */
function line_chat_suggest_reply(PDO $pdo, string $lineUserId, string $hint = ''): array
{
    if ($lineUserId === '') throw new RuntimeException('line_user_id ว่าง');
    require_once __DIR__ . '/ai_qa_helper.php';
    $apiKey = ai_qa_load_gemini_key();
    if (!$apiKey) throw new RuntimeException('ยังไม่ได้ตั้งค่า GEMINI_API_KEY');

    // Pull last 20 messages (oldest first) for context — bounded to keep tokens low
    $convo = line_chat_get_conversation($pdo, $lineUserId, 20);
    $messages = $convo['messages'] ?? [];
    if (empty($messages)) throw new RuntimeException('ไม่พบประวัติบทสนทนา');

    $sysUser = $convo['system_user'] ?? null;
    $displayName = '';
    if ($sysUser && !empty($sysUser['full_name'])) {
        $displayName = ($sysUser['prefix'] ?? '') . $sysUser['full_name'];
    } elseif (!empty($convo['line_display_name'])) {
        $displayName = $convo['line_display_name'];
    }

    $systemPrompt = "คุณเป็นผู้ช่วยร่างข้อความตอบกลับให้แอดมินคลินิกสุขภาพ (RSU Medical Clinic). " .
        "ภาษาที่ใช้: ไทย, สุภาพแต่ไม่ยืดยาด, ตอบตรงประเด็น. " .
        "ห้ามแต่งข้อมูลที่ไม่รู้ (เวลาเปิด-ปิด/ราคา/ยา) — ถ้าไม่มีข้อมูลให้บอกตรงๆ ว่า 'ขออนุญาตตรวจสอบและแจ้งกลับนะคะ'. " .
        ($displayName !== '' ? "ชื่อผู้ใช้: {$displayName}. " : '') .
        "อ่านบทสนทนา 20 ข้อความล่าสุดด้านล่าง แล้วร่าง 'ข้อความเดียว' ที่จะส่งให้ผู้ใช้ (ไม่ต้องมีคำว่า 'ตอบ:' นำหน้า, ไม่ต้องอธิบายเหตุผล, ส่งข้อความสุภาพพร้อมส่งทันที).";
    if ($hint !== '') {
        $systemPrompt .= " คำแนะนำเพิ่มเติมจากแอดมิน: " . $hint;
    }

    // Build conversation history
    $contents = [
        ['role' => 'user',  'parts' => [['text' => $systemPrompt]]],
        ['role' => 'model', 'parts' => [['text' => 'รับทราบ พร้อมร่างข้อความตอบกลับ']]],
    ];
    foreach ($messages as $m) {
        $role = ($m['direction'] === 'inbound') ? 'user' : 'model';
        $text = (string)($m['message_text'] ?? '');
        // For non-text messages, describe in brackets so Gemini still gets context
        $mtype = (string)($m['message_type'] ?? 'text');
        if ($mtype !== 'text' && $mtype !== '') {
            $text = "[ผู้ใช้ส่ง: {$mtype}]";
        }
        if ($text === '') continue;
        $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
    }
    // Final nudge
    $contents[] = ['role' => 'user', 'parts' => [['text' => 'ร่างข้อความตอบกลับสำหรับข้อความล่าสุด:']]];

    $body = json_encode([
        'contents' => $contents,
        'generationConfig' => [
            'temperature'     => 0.4,
            'maxOutputTokens' => 600,
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $models = ['gemini-2.5-flash', 'gemini-2.0-flash'];
    $answer = '';
    $usedModel = '';
    $start = microtime(true);

    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 400) {
            error_log("[line_chat] suggest_reply $model HTTP $code");
            continue;
        }
        $json = json_decode((string)$resp, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') continue;
        $answer = trim($text);
        $usedModel = $model;
        break;
    }

    if ($answer === '') throw new RuntimeException('Gemini ไม่ตอบ — ลองอีกครั้ง');

    return [
        'answer'     => $answer,
        'model'      => $usedModel,
        'elapsed_ms' => (int)round((microtime(true) - $start) * 1000),
    ];
}
