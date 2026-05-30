<?php
/**
 * portal/ajax_identity_line_bulk.php
 * Superadmin-only — เชื่อม LINE ให้ staff "ทั้งหมด" โดยอ้างอิงผังองค์กร (sys_org_members)
 *
 * ผังองค์กรลิงก์สมาชิกกับ "บัญชี staff" (m.staff_id = badge "Linked") ส่วน LINE อยู่ที่ user
 * จึง bridge:  m.staff_id → sys_staff s (username) → sys_users u (student_personnel_id = s.username)
 *               → u.line_user_id
 * เชื่อมเฉพาะ staff ที่ "ยังไม่ผูก" (ไม่ทับของเดิม) + dedupe UID
 *
 * Actions (POST, CSRF, superadmin):
 *   mode=preview — ไม่เขียน DB · summary + ตัวอย่าง
 *   mode=commit  — เชื่อมจริงใน transaction + audit ต่อราย
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'admin');

if ($adminRole !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'superadmin เท่านั้น']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST only']);
    exit;
}
validate_csrf_or_die();

$mode = ($_POST['mode'] ?? 'preview') === 'commit' ? 'commit' : 'preview';
$pdo  = db();

function bulk_mask(string $uid): string
{
    return strlen($uid) > 8 ? substr($uid, 0, 3) . '…' . substr($uid, -4) : $uid;
}

try {
    // ── 1) org members ที่ "Linked" (staff_id set) → bridge staff→user ด้วย personnel-id ──
    //     LEFT JOIN user เพื่อให้ staff ที่ "ไม่มี user/LINE ตรง" ยังโผล่มา (จัดเป็น no_line)
    $sql = "SELECT s.id AS staff_id, s.full_name AS staff_name,
                   IFNULL(s.linked_line_user_id, '') AS cur_uid,
                   IFNULL(u.full_name, '') AS user_name,
                   IFNULL(u.line_user_id, '') AS uid,
                   COALESCE(p.title, '') AS org_position
            FROM sys_org_members m
            JOIN sys_staff s ON s.id = m.staff_id
            LEFT JOIN sys_users u
                   ON s.username <> '' AND u.student_personnel_id = s.username
                  AND u.line_user_id IS NOT NULL AND u.line_user_id <> ''
            LEFT JOIN sys_org_positions p ON p.id = m.position_id
            WHERE m.is_active = 1 AND m.staff_id IS NOT NULL
            ORDER BY s.full_name ASC, m.display_order ASC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // ── 2) UID ที่ถูกผูกอยู่แล้ว (uid → staff_id) สำหรับเช็ค conflict ──
    $linkMap = [];
    foreach ($pdo->query("SELECT id, linked_line_user_id FROM sys_staff
                          WHERE linked_line_user_id IS NOT NULL AND linked_line_user_id <> ''")
                  ->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $linkMap[(string)$l['linked_line_user_id']] = (int)$l['id'];
    }

    // ── 3) classify (staff คนหนึ่ง = ตัดสินใจครั้งเดียว) ──
    $eligible = [];
    $sample   = [];
    $cnt = ['eligible' => 0, 'already' => 0, 'has_other' => 0, 'conflict' => 0, 'invalid' => 0, 'no_line' => 0];
    $claimed   = [];
    $seenStaff = [];

    foreach ($rows as $r) {
        $staffId = (int)$r['staff_id'];
        if (isset($seenStaff[$staffId])) continue;
        $seenStaff[$staffId] = 1;
        $uid    = (string)$r['uid'];
        $curUid = (string)$r['cur_uid'];

        if ($uid === '')                            { $cnt['no_line']++;  continue; } // ไม่มี user (ตามรหัส) ที่มี LINE
        if (!preg_match('/^U[0-9a-f]{32}$/', $uid)) { $cnt['invalid']++;  continue; }
        if ($curUid !== '' && $curUid === $uid)     { $cnt['already']++;  continue; }

        $ownerInDb    = $linkMap[$uid] ?? 0;
        $ownerInBatch = $claimed[$uid] ?? 0;
        if (($ownerInDb && $ownerInDb !== $staffId) || ($ownerInBatch && $ownerInBatch !== $staffId)) {
            $cnt['conflict']++; continue;
        }
        if ($curUid !== '' && $curUid !== $uid)     { $cnt['has_other']++; continue; }

        $eligible[] = ['staff_id' => $staffId, 'uid' => $uid];
        $claimed[$uid] = $staffId;
        $cnt['eligible']++;
        if (count($sample) < 50) {
            $sample[] = [
                'staff_name'   => (string)$r['staff_name'],
                'user_name'    => (string)$r['user_name'],
                'org_position' => (string)$r['org_position'],
                'line_masked'  => bulk_mask($uid),
            ];
        }
    }

    if ($mode === 'preview') {
        echo json_encode([
            'status'  => 'success',
            'summary' => array_merge($cnt, ['staff_total' => count($seenStaff)]),
            'sample'  => $sample,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 4) commit ──
    $justification = 'Bulk link LINE จากผังองค์กร โดย ' . $adminName;
    $linkedCount = 0;
    $failed = 0;

    $upd   = $pdo->prepare("UPDATE sys_staff SET linked_line_user_id = :uid
                            WHERE id = :sid AND (linked_line_user_id IS NULL OR linked_line_user_id = '')");
    $audit = $pdo->prepare("INSERT INTO sys_access_audit_logs (target_id, target_type, changed_by, justification, change_snapshot)
                            VALUES (?, 'staff_line_link_bulk', ?, ?, ?)");

    $pdo->beginTransaction();
    foreach ($eligible as $e) {
        $upd->execute([':uid' => $e['uid'], ':sid' => $e['staff_id']]);
        if ($upd->rowCount() > 0) {
            $linkedCount++;
            try {
                $audit->execute([
                    $e['staff_id'], $adminId, $justification,
                    json_encode([
                        'linked_line_user_id' => $e['uid'],
                        'source'     => 'org_chart_bulk',
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (PDOException) { /* audit table may not exist */ }
        } else {
            $failed++;
        }
    }
    $pdo->commit();

    if (function_exists('log_activity')) {
        log_activity('identity_line_bulk_link',
            "linked={$linkedCount} eligible={$cnt['eligible']} conflict={$cnt['conflict']} has_other={$cnt['has_other']} no_line={$cnt['no_line']}", $adminId);
    }

    echo json_encode([
        'status'  => 'success',
        'summary' => array_merge($cnt, ['linked' => $linkedCount, 'failed' => $failed]),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ajax_identity_line_bulk] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'ระบบขัดข้อง: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
