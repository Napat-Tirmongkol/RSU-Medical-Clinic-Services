<?php
/**
 * includes/edms_sla_notify.php
 * EDMS SLA notification + escalation
 *
 *   sla_notify_assignee($routingId, 'warning' | 'breached')
 *   sla_escalate($routingId)  // เรียกเมื่อ breach → notify superadmin + dept head
 *
 * Channels:
 *   - In-app notification → INSERT row ใน sys_doc_logs (action='sla_notify') — บน edms detail UI จะแสดง
 *   - LINE push (best-effort) → ผ่าน send_line_push() ถ้า user link LINE และ notify_sla_via_line=1
 *
 * Notification table: ใช้ sys_doc_logs ที่มีอยู่แล้ว (action='sla_warning' / 'sla_breached' / 'sla_escalated')
 * + log ลง sys_doc_sla_events ผ่าน sla_event_log()
 */
declare(strict_types=1);

require_once __DIR__ . '/edms_sla_helper.php';
require_once __DIR__ . '/line_helper.php';

if (!function_exists('sla_load_line_token')) {
    function sla_load_line_token(): string {
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
                error_log('[sla_notify] line token: ' . $e->getMessage());
            }
        }
        return $cached;
    }
}

if (!function_exists('sla_get_routing_context')) {
    /**
     * คืน routing + doc info (subject, doc_number, assignee details)
     */
    function sla_get_routing_context(PDO $pdo, int $routingId): ?array {
        try {
            // Prefer linked_line_user_id_new (new LINE Login provider) → fallback linked_line_user_id
            // sys_staff ไม่มี s.line_user_id (อันนั้นอยู่ใน sys_users) — ห้ามใช้
            $st = $pdo->prepare("SELECT r.id AS routing_id, r.doc_id, r.to_user_id, r.to_dept,
                r.sla_state, r.resolve_deadline_at, r.ack_deadline_at, r.policy_id,
                d.doc_number, d.subject, d.doc_type, d.priority_id,
                s.id AS assignee_id, s.full_name AS assignee_name,
                COALESCE(NULLIF(s.linked_line_user_id_new, ''), s.linked_line_user_id) AS line_user_id,
                s.department_id, COALESCE(s.notify_sla_via_line, 1) AS notify_sla_via_line,
                p.escalate_to_role
                FROM sys_doc_routings r
                JOIN sys_doc_documents d ON d.id = r.doc_id
                LEFT JOIN sys_staff s ON s.id = r.to_user_id
                LEFT JOIN sys_doc_sla_policies p ON p.id = r.policy_id
                WHERE r.id = ? LIMIT 1");
            $st->execute([$routingId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log('[sla_get_routing_context] ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('sla_format_minutes_left')) {
    function sla_format_minutes_left(?string $deadlineIso): string {
        if (!$deadlineIso) return '-';
        try {
            $dl = new DateTimeImmutable($deadlineIso, sla_tz());
            $now = new DateTimeImmutable('now', sla_tz());
            $secs = $dl->getTimestamp() - $now->getTimestamp();
            $abs = abs($secs);
            $h = intdiv($abs, 3600);
            $m = intdiv($abs % 3600, 60);
            $str = ($h > 0 ? "{$h} ชม. " : '') . "{$m} นาที";
            return $secs < 0 ? "เลย {$str}" : $str;
        } catch (Throwable) {
            return '-';
        }
    }
}

if (!function_exists('sla_send_in_app_log')) {
    /**
     * In-app notification: เขียนลง sys_doc_logs (action='sla_*')
     * EDMS detail.php มี timeline ที่ render จาก sys_doc_logs อยู่แล้ว
     */
    function sla_send_in_app_log(PDO $pdo, int $docId, ?int $userId, string $action, string $message): void {
        try {
            $st = $pdo->prepare("INSERT INTO sys_doc_logs (doc_id, user_id, action, detail, ip_address)
                VALUES (?, ?, ?, ?, ?)");
            $st->execute([
                $docId,
                $userId ?: null,
                $action,
                json_encode(['message' => $message], JSON_UNESCAPED_UNICODE),
                'system-cron',
            ]);
        } catch (PDOException $e) {
            error_log('[sla_send_in_app_log] ' . $e->getMessage());
        }
    }
}

if (!function_exists('sla_send_line_push_safe')) {
    /**
     * Wrapper รอบ send_line_push — best-effort, ไม่ throw
     */
    function sla_send_line_push_safe(string $lineUserId, string $text): bool {
        if (empty($lineUserId)) return false;
        $token = sla_load_line_token();
        if ($token === '') return false;
        try {
            return send_line_push($lineUserId, [['type' => 'text', 'text' => $text]], $token);
        } catch (Throwable $e) {
            error_log('[sla_send_line_push_safe] ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('sla_get_dept_heads')) {
    /**
     * คืน list ของหัวหน้าฝ่าย ตาม department_id ของ assignee
     * Logic: sys_staff_positions มี is_head=1 → match assignee.position_id
     *   หรือ ใช้ภาษาไทยถ้ายังไม่มี is_head (fallback)
     *
     * @return array<int, array{id:int, full_name:string, line_user_id:?string, notify_sla_via_line:int}>
     */
    function sla_get_dept_heads(PDO $pdo, ?int $departmentId, ?int $excludeUserId): array {
        if ($departmentId === null) return [];
        try {
            // Try is_head flag first (after Sprint 1 migration)
            $st = $pdo->prepare("SELECT s.id, s.full_name,
                COALESCE(NULLIF(s.linked_line_user_id_new, ''), s.linked_line_user_id) AS line_user_id,
                COALESCE(s.notify_sla_via_line, 1) AS notify_sla_via_line
                FROM sys_staff s
                JOIN sys_staff_positions p ON p.id = s.position_id AND p.is_head = 1
                WHERE s.department_id = ?
                  AND s.account_status = 'active'
                  AND (? IS NULL OR s.id != ?)");
            $st->execute([$departmentId, $excludeUserId, $excludeUserId ?: 0]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('[sla_get_dept_heads] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('sla_get_superadmins')) {
    /**
     * @return array<int, array{id:int, full_name:string, line_user_id:?string, notify_sla_via_line:int}>
     */
    function sla_get_superadmins(PDO $pdo): array {
        try {
            // sys_admins.role = 'superadmin' → join sys_staff (ผ่าน username เพราะ id pool ต่างกัน) สำหรับ LINE info
            $rows = $pdo->query("SELECT a.id, COALESCE(s.full_name, a.username) AS full_name,
                COALESCE(NULLIF(s.linked_line_user_id_new, ''), s.linked_line_user_id) AS line_user_id,
                COALESCE(s.notify_sla_via_line, 1) AS notify_sla_via_line
                FROM sys_admins a
                LEFT JOIN sys_staff s ON s.username = a.username
                WHERE a.role = 'superadmin'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $rows;
        } catch (PDOException $e) {
            error_log('[sla_get_superadmins] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('sla_notify_assignee')) {
    /**
     * แจ้งเตือน assignee (warning หรือ breached)
     * @param string $kind 'warning' | 'breached'
     */
    function sla_notify_assignee(PDO $pdo, int $routingId, string $kind): bool {
        $ctx = sla_get_routing_context($pdo, $routingId);
        if (!$ctx) return false;

        $docNo = $ctx['doc_number'] ?: '#' . $ctx['doc_id'];
        $subject = mb_substr($ctx['subject'] ?? '', 0, 100, 'UTF-8');
        $left = sla_format_minutes_left($ctx['resolve_deadline_at']);
        $deadline = $ctx['resolve_deadline_at']
            ? (new DateTimeImmutable($ctx['resolve_deadline_at'], sla_tz()))->format('d/m H:i')
            : '-';

        if ($kind === 'warning') {
            $title = '[SLA Warning]';
            $text = "{$title} เอกสาร {$docNo} \"{$subject}\"\n"
                . "จะหมดเวลาในอีก {$left} (deadline: {$deadline})\n"
                . "กรุณาเข้าระบบเพื่อดำเนินการ";
            $action = 'sla_warning';
        } else { // breached
            $title = '[SLA Breached]';
            $text = "{$title} เอกสาร {$docNo} \"{$subject}\" เลย deadline แล้ว\n"
                . "ผู้รับผิดชอบ: " . ($ctx['assignee_name'] ?? '(ไม่ระบุ)') . "\n"
                . "กรุณาดำเนินการด่วน";
            $action = 'sla_breached';
        }

        // In-app log
        sla_send_in_app_log($pdo, (int)$ctx['doc_id'], (int)($ctx['assignee_id'] ?? 0), $action, $text);

        // LINE push (ถ้า opt-in)
        $sentLine = false;
        if (!empty($ctx['line_user_id']) && (int)($ctx['notify_sla_via_line'] ?? 1) === 1) {
            $sentLine = sla_send_line_push_safe((string)$ctx['line_user_id'], $text);
        }

        return true;
    }
}

if (!function_exists('sla_escalate')) {
    /**
     * Escalate เมื่อ breach → notify superadmin + dept head ตาม policy
     * - policy.escalate_to_role: 'superadmin' | 'dept_head' | 'superadmin+dept_head'
     */
    function sla_escalate(PDO $pdo, int $routingId): bool {
        $ctx = sla_get_routing_context($pdo, $routingId);
        if (!$ctx) return false;

        $role = $ctx['escalate_to_role'] ?? 'superadmin+dept_head';
        $docNo = $ctx['doc_number'] ?: '#' . $ctx['doc_id'];
        $subject = mb_substr($ctx['subject'] ?? '', 0, 100, 'UTF-8');

        $targets = []; // [['id', 'name', 'line_user_id', 'notify_sla_via_line']]

        if ($role === 'superadmin' || $role === 'superadmin+dept_head') {
            foreach (sla_get_superadmins($pdo) as $sa) {
                $targets[(int)$sa['id']] = $sa;
            }
        }
        if ($role === 'dept_head' || $role === 'superadmin+dept_head') {
            foreach (sla_get_dept_heads($pdo, $ctx['department_id'] ? (int)$ctx['department_id'] : null, (int)($ctx['assignee_id'] ?? 0)) as $h) {
                if (!isset($targets[(int)$h['id']])) {
                    $targets[(int)$h['id']] = $h;
                }
            }
        }

        if (empty($targets)) {
            // ไม่มีคนที่จะ escalate ไป → log warning
            sla_event_log($pdo, $routingId, (int)$ctx['doc_id'], 'escalated', null, 'no escalation target found', [
                'role' => $role,
                'department_id' => $ctx['department_id'],
            ]);
            return false;
        }

        $text = "[SLA Escalation] เอกสาร {$docNo} \"{$subject}\" เลย deadline\n"
            . "ผู้รับผิดชอบ: " . ($ctx['assignee_name'] ?? '(ไม่ระบุ)') . "\n"
            . "เลย deadline: " . sla_format_minutes_left($ctx['resolve_deadline_at']) . "\n"
            . "กรุณาตรวจสอบและดำเนินการ";

        $lineSent = 0;
        foreach ($targets as $t) {
            // In-app log
            sla_send_in_app_log($pdo, (int)$ctx['doc_id'], (int)$t['id'], 'sla_escalated', $text);
            // LINE push
            if (!empty($t['line_user_id']) && (int)($t['notify_sla_via_line'] ?? 1) === 1) {
                if (sla_send_line_push_safe((string)$t['line_user_id'], $text)) {
                    $lineSent++;
                }
            }
        }

        sla_event_log($pdo, $routingId, (int)$ctx['doc_id'], 'escalated', null, "escalated to {$role}", [
            'targets_count' => count($targets),
            'line_sent'     => $lineSent,
            'role'          => $role,
        ]);
        return true;
    }
}
