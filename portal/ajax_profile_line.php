<?php
/**
 * portal/ajax_profile_line.php
 * Self-service LINE link management — ฝั่ง staff/admin จัดการบัญชี LINE ของตัวเอง
 *
 * Actions (POST):
 *   unlink                — ยกเลิกการเชื่อม LINE (clear linked_line_user_id)
 *   toggle_notify_sla     — เปิด/ปิดรับแจ้งเตือน SLA ผ่าน LINE
 *
 * Authentication: ต้องมี admin session ($_SESSION['admin_id'])
 * CSRF: validate_csrf_or_die()
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}

$staffId = (int)($_SESSION['admin_id'] ?? 0);
if ($staffId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Permission denied']);
    exit;
}

validate_csrf_or_die();

$pdo = db();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'unlink': {
            $cur = $pdo->prepare("SELECT linked_line_user_id, full_name FROM sys_staff WHERE id = ?");
            $cur->execute([$staffId]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['linked_line_user_id'])) {
                throw new RuntimeException('ยังไม่ได้เชื่อม LINE');
            }
            $oldUid = $row['linked_line_user_id'];

            // Auto-migrate (เผื่อยังไม่มี column)
            try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS line_display_name VARCHAR(120) NULL"); } catch (PDOException) {}
            try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS line_picture_url VARCHAR(500) NULL"); } catch (PDOException) {}

            // Clear all LINE profile data
            $pdo->prepare("UPDATE sys_staff SET linked_line_user_id = NULL, line_display_name = NULL, line_picture_url = NULL WHERE id = ?")
                ->execute([$staffId]);

            // Invalidate header avatar cache → header refresh กลับเป็น role icon
            unset($_SESSION['_line_profile_cache']);

            // Audit
            try {
                $audit = $pdo->prepare("INSERT INTO sys_access_audit_logs (target_id, target_type, changed_by, justification, change_snapshot) VALUES (?, 'staff_line_unlink', ?, ?, ?)");
                $audit->execute([
                    $staffId,
                    $staffId,
                    'Self-service LINE unlink',
                    json_encode([
                        'unlinked_line_user_id' => $oldUid,
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (PDOException) {}

            // Best-effort: ส่ง LINE notice ไปบัญชีเดิม
            try {
                require_once __DIR__ . '/../includes/line_helper.php';
                $secretsPath = __DIR__ . '/../config/secrets.php';
                $secrets = is_file($secretsPath) ? (require $secretsPath) : [];
                $msgToken = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';
                if ($msgToken !== '') {
                    send_line_push($oldUid, [[
                        'type' => 'text',
                        'text' => "🔓 ยกเลิกการเชื่อมบัญชี\n\nบัญชี LINE นี้ถูกยกเลิกการเชื่อมกับ Staff: {$row['full_name']}\n\nคุณจะไม่ได้รับการแจ้งเตือนจากระบบอีก",
                    ]], $msgToken);
                }
            } catch (Throwable) {}

            echo json_encode(['ok' => true, 'message' => 'ยกเลิกการเชื่อมแล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'toggle_notify_sla': {
            $enabled = (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0;
            // Auto-migrate กัน column ยังไม่มี
            try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS notify_sla_via_line TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException) {}
            $pdo->prepare("UPDATE sys_staff SET notify_sla_via_line = ? WHERE id = ?")->execute([$enabled, $staffId]);
            echo json_encode(['ok' => true, 'message' => $enabled ? 'เปิดการแจ้งเตือนแล้ว' : 'ปิดการแจ้งเตือนแล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'dismiss_link_prompt': {
            // user เลือก "ไม่ต้องเตือนอีก" — set permanent flag
            try { $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS dismissed_line_link_prompt TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException) {}
            $pdo->prepare("UPDATE sys_staff SET dismissed_line_link_prompt = 1 WHERE id = ?")->execute([$staffId]);
            echo json_encode(['ok' => true, 'message' => 'ปิดการเตือนถาวรแล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        default:
            throw new RuntimeException('Unknown action: ' . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
