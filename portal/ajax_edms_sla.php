<?php
/**
 * portal/ajax_edms_sla.php — central AJAX for EDMS SLA module
 *
 * Entity / Action pattern (POST):
 *
 *   policy:list           — list policies (filter active=1 by default)
 *   policy:upsert         — สร้าง/แก้ policy [requires sla_admin]
 *   policy:toggle         — toggle is_active [requires sla_admin]
 *   policy:delete         — ลบ policy (block ถ้ามี routing อ้างอิงอยู่) [requires sla_admin]
 *
 *   calendar:list         — list business_hours + holidays
 *   calendar:upsert       — แก้เวลาทำการ / เพิ่มวันหยุด [requires sla_admin]
 *   calendar:delete       — ลบ entry [requires sla_admin]
 *
 *   routing:acknowledge   — กดรับทราบ (assignee/superadmin only)
 *   routing:extend        — ขอยืด/ย่น deadline (reason required)
 *   routing:pause         — toggle pause (assignee/superadmin only)
 *   routing:resume        — resume + ชดเชย elapsed time
 *
 *   event:list            — timeline ของ routing 1 record
 *
 *   dashboard:kpi         — KPI tiles + period delta
 *   dashboard:trend       — bar chart 12 เดือน (on-time vs breached)
 *   dashboard:by_dept     — donut breakdown by dept (top 10)
 *   dashboard:overdue_list— top 10 overdue routings
 *
 * Authentication:
 *   - read endpoints: superadmin OR access_edms
 *   - sla_admin endpoints (policy/calendar write): superadmin OR access_edms_sla_admin
 *   - routing pause/resume: superadmin OR routing.to_user_id = current user
 *
 * CSRF: validate_csrf_or_die() ทุก action
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/edms_sla_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$hasEdms   = !empty($_SESSION['access_edms']);
$isSlaAdmin = $isSuper || !empty($_SESSION['access_edms_sla_admin']);

if (!$isSuper && !$hasEdms) {
    echo json_encode(['ok' => false, 'message' => 'Permission denied']);
    exit;
}

validate_csrf_or_die();

$pdo    = db();
$entity = $_POST['entity'] ?? '';
$action = $_POST['action'] ?? '';
$userId = (int)($_SESSION['admin_id'] ?? 0);

function _sla_require_admin(bool $isAdmin): void {
    if (!$isAdmin) {
        echo json_encode(['ok' => false, 'message' => 'ต้องมีสิทธิ์จัดการ SLA (sla_admin)']);
        exit;
    }
}

function _sla_log_edms(PDO $pdo, int $docId, ?int $userId, string $action, array $detail = []): void {
    try {
        $st = $pdo->prepare("INSERT INTO sys_doc_logs (doc_id, user_id, action, detail, ip_address) VALUES (?, ?, ?, ?, ?)");
        $st->execute([
            $docId,
            $userId ?: null,
            $action,
            $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException) {}
}

try {
    switch ("$entity:$action") {

        // ════════════════ POLICY ════════════════
        case 'policy:list': {
            $includeInactive = !empty($_POST['include_inactive']);
            $sql = "SELECT p.*, c.name AS priority_name, c.color AS priority_color
                FROM sys_doc_sla_policies p
                LEFT JOIN sys_doc_categories c ON c.id = p.priority_id
                " . ($includeInactive ? '' : 'WHERE p.is_active = 1') . "
                ORDER BY p.doc_type ASC, p.sort_order ASC, c.sort_order ASC";
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'policy:upsert': {
            _sla_require_admin($isSlaAdmin);
            $id          = (int)($_POST['id'] ?? 0);
            $docType     = trim($_POST['doc_type'] ?? '');
            $priorityId  = (int)($_POST['priority_id'] ?? 0) ?: null;
            $name        = trim($_POST['name'] ?? '');
            $ackHours    = (float)($_POST['ack_hours'] ?? 4);
            $resHours    = (float)($_POST['resolve_hours'] ?? 48);
            $warnPct     = (int)($_POST['warn_at_pct'] ?? 20);
            $bizOnly     = !empty($_POST['business_hours_only']) ? 1 : 0;
            $escalateRole = trim($_POST['escalate_to_role'] ?? 'superadmin+dept_head');

            if ($docType === '') throw new RuntimeException('ต้องระบุประเภทเอกสาร');
            if ($name === '')    throw new RuntimeException('ต้องระบุชื่อ policy');
            if ($ackHours < 0.5 || $ackHours > 720) throw new RuntimeException('ack_hours อยู่นอกช่วง 0.5-720');
            if ($resHours < $ackHours) throw new RuntimeException('resolve_hours ต้อง >= ack_hours');
            if ($warnPct < 0 || $warnPct > 100) throw new RuntimeException('warn_at_pct อยู่นอกช่วง 0-100');
            if (!in_array($escalateRole, ['superadmin', 'dept_head', 'superadmin+dept_head'], true)) {
                $escalateRole = 'superadmin+dept_head';
            }

            if ($id > 0) {
                $pdo->prepare("UPDATE sys_doc_sla_policies
                    SET doc_type=?, priority_id=?, name=?, ack_hours=?, resolve_hours=?,
                        warn_at_pct=?, business_hours_only=?, escalate_to_role=?
                    WHERE id=?")
                    ->execute([$docType, $priorityId, $name, $ackHours, $resHours, $warnPct, $bizOnly, $escalateRole, $id]);
                $msg = 'แก้ไข policy แล้ว';
            } else {
                try {
                    $pdo->prepare("INSERT INTO sys_doc_sla_policies
                        (doc_type, priority_id, name, ack_hours, resolve_hours, warn_at_pct, business_hours_only, escalate_to_role)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$docType, $priorityId, $name, $ackHours, $resHours, $warnPct, $bizOnly, $escalateRole]);
                } catch (PDOException $e) {
                    if (str_contains($e->getMessage(), 'Duplicate')) {
                        throw new RuntimeException('มี policy สำหรับ doc_type + priority นี้แล้ว');
                    }
                    throw $e;
                }
                $msg = 'เพิ่ม policy แล้ว';
            }

            echo json_encode(['ok' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'policy:toggle': {
            _sla_require_admin($isSlaAdmin);
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');
            $pdo->prepare("UPDATE sys_doc_sla_policies SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'เปลี่ยนสถานะแล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'policy:delete': {
            _sla_require_admin($isSlaAdmin);
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            // block ถ้ามี routing ใช้อยู่
            $used = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_routings WHERE policy_id = $id")->fetchColumn();
            if ($used > 0) {
                throw new RuntimeException("ลบไม่ได้ — มี routing ใช้ policy นี้อยู่ {$used} รายการ (ปิดการใช้งานแทนได้)");
            }
            $pdo->prepare("DELETE FROM sys_doc_sla_policies WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'ลบ policy แล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════════ CALENDAR ════════════════
        case 'calendar:list': {
            $rows = $pdo->query("SELECT * FROM sys_doc_sla_calendar
                ORDER BY kind ASC, weekday ASC, specific_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $weekdayNames = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
            foreach ($rows as &$r) {
                if ($r['kind'] === 'business_hours' && $r['weekday'] !== null) {
                    $r['weekday_name'] = $weekdayNames[(int)$r['weekday']] ?? '?';
                }
            }
            echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'calendar:upsert': {
            _sla_require_admin($isSlaAdmin);
            $id          = (int)($_POST['id'] ?? 0);
            $kind        = $_POST['kind'] ?? '';
            $weekday     = ($_POST['weekday'] ?? '') !== '' ? (int)$_POST['weekday'] : null;
            $specificDate = trim($_POST['specific_date'] ?? '') ?: null;
            $startTime   = trim($_POST['start_time'] ?? '') ?: null;
            $endTime     = trim($_POST['end_time'] ?? '') ?: null;
            $name        = trim($_POST['name'] ?? '') ?: null;
            $isActive    = isset($_POST['is_active']) ? ((int)$_POST['is_active'] ? 1 : 0) : 1;

            if (!in_array($kind, ['business_hours','holiday'], true)) throw new RuntimeException('kind ไม่ถูกต้อง');
            if ($kind === 'business_hours') {
                if ($weekday === null || $weekday < 0 || $weekday > 6) throw new RuntimeException('weekday ต้องเป็น 0-6');
                if (!$startTime || !$endTime) throw new RuntimeException('ต้องระบุเวลา start/end');
                if ($startTime >= $endTime) throw new RuntimeException('start_time ต้องน้อยกว่า end_time');
            } else { // holiday
                if (!$specificDate) throw new RuntimeException('ต้องระบุวันที่');
                $weekday = null;
                $startTime = null;
                $endTime = null;
            }

            if ($id > 0) {
                $pdo->prepare("UPDATE sys_doc_sla_calendar
                    SET kind=?, weekday=?, specific_date=?, start_time=?, end_time=?, name=?, is_active=?
                    WHERE id=?")
                    ->execute([$kind, $weekday, $specificDate, $startTime, $endTime, $name, $isActive, $id]);
                $msg = 'แก้ไขรายการแล้ว';
            } else {
                $pdo->prepare("INSERT INTO sys_doc_sla_calendar
                    (kind, weekday, specific_date, start_time, end_time, name, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$kind, $weekday, $specificDate, $startTime, $endTime, $name, $isActive]);
                $msg = 'เพิ่มรายการแล้ว';
            }
            echo json_encode(['ok' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'calendar:delete': {
            _sla_require_admin($isSlaAdmin);
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');
            $pdo->prepare("DELETE FROM sys_doc_sla_calendar WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'ลบรายการแล้ว'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════════ ROUTING SLA ACTIONS ════════════════
        case 'routing:acknowledge': {
            $rId = (int)($_POST['routing_id'] ?? 0);
            if ($rId <= 0) throw new RuntimeException('ระบุ routing_id');
            $r = $pdo->query("SELECT id, doc_id, to_user_id FROM sys_doc_routings WHERE id = $rId")->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new RuntimeException('ไม่พบ routing');
            if (!$isSuper && (int)$r['to_user_id'] !== $userId) {
                throw new RuntimeException('เฉพาะผู้รับมอบหมายเท่านั้นที่กดรับทราบได้');
            }
            $ok = sla_acknowledge($pdo, $rId, $userId);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'รับทราบแล้ว' : 'รับทราบไปแล้วหรือ routing ไม่มี SLA'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'routing:extend': {
            $rId      = (int)($_POST['routing_id'] ?? 0);
            $newAck   = trim($_POST['new_ack_deadline'] ?? '') ?: null;
            $newRes   = trim($_POST['new_resolve_deadline'] ?? '') ?: null;
            $reason   = trim($_POST['reason'] ?? '');
            if ($rId <= 0) throw new RuntimeException('ระบุ routing_id');
            if (!$newAck && !$newRes) throw new RuntimeException('ต้องระบุ deadline ใหม่อย่างน้อย 1 ค่า');
            if (mb_strlen($reason) < 3) throw new RuntimeException('ต้องระบุเหตุผล (อย่างน้อย 3 ตัวอักษร)');

            $r = $pdo->query("SELECT id, doc_id, to_user_id FROM sys_doc_routings WHERE id = $rId")->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new RuntimeException('ไม่พบ routing');
            // permission: assignee, sla_admin, หรือ superadmin
            $canExtend = $isSuper || $isSlaAdmin || (int)$r['to_user_id'] === $userId;
            if (!$canExtend) throw new RuntimeException('ไม่มีสิทธิ์ขยายเวลา');

            $ok = sla_extend($pdo, $rId, $newAck, $newRes, $reason, $userId);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'บันทึก deadline ใหม่แล้ว' : 'ไม่สามารถบันทึกได้'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'routing:pause': {
            $rId    = (int)($_POST['routing_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '') ?: null;
            if ($rId <= 0) throw new RuntimeException('ระบุ routing_id');

            $r = $pdo->query("SELECT id, doc_id, to_user_id FROM sys_doc_routings WHERE id = $rId")->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new RuntimeException('ไม่พบ routing');
            if (!$isSuper && (int)$r['to_user_id'] !== $userId) {
                throw new RuntimeException('เฉพาะ assignee หรือ superadmin เท่านั้น');
            }
            $ok = sla_pause($pdo, $rId, $userId, $reason);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'หยุดนาฬิกา SLA แล้ว' : 'ไม่สามารถ pause ได้ (อาจ pause อยู่แล้ว หรือ routing เสร็จแล้ว)'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'routing:resume': {
            $rId = (int)($_POST['routing_id'] ?? 0);
            if ($rId <= 0) throw new RuntimeException('ระบุ routing_id');

            $r = $pdo->query("SELECT id, doc_id, to_user_id FROM sys_doc_routings WHERE id = $rId")->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new RuntimeException('ไม่พบ routing');
            if (!$isSuper && (int)$r['to_user_id'] !== $userId) {
                throw new RuntimeException('เฉพาะ assignee หรือ superadmin เท่านั้น');
            }
            $ok = sla_resume($pdo, $rId, $userId);
            echo json_encode(['ok' => $ok, 'message' => $ok ? 'เริ่มนาฬิกา SLA ใหม่แล้ว' : 'routing ไม่ได้ pause อยู่'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════════ EVENT TIMELINE ════════════════
        case 'event:list': {
            $rId = (int)($_POST['routing_id'] ?? 0);
            if ($rId <= 0) throw new RuntimeException('ระบุ routing_id');
            $st = $pdo->prepare("SELECT e.*, s.full_name AS actor_name
                FROM sys_doc_sla_events e
                LEFT JOIN sys_staff s ON s.id = e.actor_user_id
                WHERE e.routing_id = ?
                ORDER BY e.created_at DESC, e.id DESC
                LIMIT 200");
            $st->execute([$rId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════════ DASHBOARD ════════════════
        case 'dashboard:kpi': {
            $period = $_POST['period'] ?? 'month'; // month | year
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
            if ($period === 'year') {
                $from = $now->modify('-1 year')->format('Y-m-d');
                $prevFrom = $now->modify('-2 year')->format('Y-m-d');
                $prevTo   = $from;
            } else {
                $from = $now->modify('-30 days')->format('Y-m-d');
                $prevFrom = $now->modify('-60 days')->format('Y-m-d');
                $prevTo   = $from;
            }
            $to = $now->format('Y-m-d') . ' 23:59:59';

            // Current period
            $st = $pdo->prepare("SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN sla_state IN ('on_track','warning') THEN 1 ELSE 0 END) AS open_cnt,
                SUM(CASE WHEN sla_state = 'warning' THEN 1 ELSE 0 END) AS warning_cnt,
                SUM(CASE WHEN sla_state = 'breached' THEN 1 ELSE 0 END) AS breached_cnt,
                SUM(CASE WHEN sla_state = 'met' THEN 1 ELSE 0 END) AS met_cnt,
                AVG(CASE WHEN met_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, created_at, met_at) ELSE NULL END) AS avg_tat_secs
                FROM sys_doc_routings
                WHERE created_at >= ? AND created_at <= ? AND sla_state != 'none'");
            $st->execute([$from, $to]);
            $cur = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            // Previous period (delta)
            $st->execute([$prevFrom, $prevTo]);
            $prev = $st->fetch(PDO::FETCH_ASSOC) ?: [];

            $total = (int)($cur['total'] ?? 0);
            $met = (int)($cur['met_cnt'] ?? 0);
            $breached = (int)($cur['breached_cnt'] ?? 0);
            $resolved = $met + $breached;
            $onTimePct = $resolved > 0 ? round($met / $resolved * 100, 1) : 0;

            $prevTotal = (int)($prev['total'] ?? 0);
            $prevMet = (int)($prev['met_cnt'] ?? 0);
            $prevBreached = (int)($prev['breached_cnt'] ?? 0);
            $prevResolved = $prevMet + $prevBreached;
            $prevOnTimePct = $prevResolved > 0 ? round($prevMet / $prevResolved * 100, 1) : 0;

            echo json_encode([
                'ok' => true,
                'kpi' => [
                    'on_time_pct'    => $onTimePct,
                    'warning_cnt'    => (int)($cur['warning_cnt'] ?? 0),
                    'breached_cnt'   => $breached,
                    'avg_tat_hours'  => $cur['avg_tat_secs'] ? round((float)$cur['avg_tat_secs'] / 3600, 1) : 0,
                    'total'          => $total,
                    'open_cnt'       => (int)($cur['open_cnt'] ?? 0),
                    'met_cnt'        => $met,
                ],
                'delta' => [
                    'on_time_pct_delta' => $onTimePct - $prevOnTimePct,
                    'breached_delta'    => $breached - $prevBreached,
                    'total_delta'       => $total - $prevTotal,
                ],
                'period' => $period,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'dashboard:trend': {
            // 12 เดือนล่าสุด — on-time count vs breached count รายเดือน
            $rows = $pdo->query("SELECT
                DATE_FORMAT(created_at, '%Y-%m') AS ym,
                SUM(CASE WHEN sla_state = 'met' THEN 1 ELSE 0 END) AS met_cnt,
                SUM(CASE WHEN sla_state = 'breached' THEN 1 ELSE 0 END) AS breached_cnt,
                COUNT(*) AS total
                FROM sys_doc_routings
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                  AND sla_state != 'none'
                GROUP BY ym
                ORDER BY ym ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // pad ให้ครบ 12 เดือน
            $byMonth = [];
            foreach ($rows as $r) $byMonth[$r['ym']] = $r;
            $labels = [];
            $met = [];
            $breached = [];
            for ($i = 11; $i >= 0; $i--) {
                $dt = new DateTimeImmutable("-{$i} months", new DateTimeZone('Asia/Bangkok'));
                $ym = $dt->format('Y-m');
                $labels[] = $dt->format('M y');
                $met[] = (int)($byMonth[$ym]['met_cnt'] ?? 0);
                $breached[] = (int)($byMonth[$ym]['breached_cnt'] ?? 0);
            }

            echo json_encode(['ok' => true, 'labels' => $labels, 'met' => $met, 'breached' => $breached], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'dashboard:by_dept': {
            // top 10 dept ที่ breach มากที่สุด (last 90 days)
            $rows = $pdo->query("SELECT
                COALESCE(d.name, r.to_dept, '(ไม่ระบุ)') AS dept_name,
                COUNT(*) AS breached_cnt
                FROM sys_doc_routings r
                LEFT JOIN sys_staff s ON s.id = r.to_user_id
                LEFT JOIN sys_departments d ON d.id = s.department_id
                WHERE r.sla_state = 'breached'
                  AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                GROUP BY dept_name
                ORDER BY breached_cnt DESC
                LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $labels = array_map(fn($r) => $r['dept_name'], $rows);
            $data = array_map(fn($r) => (int)$r['breached_cnt'], $rows);
            echo json_encode(['ok' => true, 'labels' => $labels, 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'dashboard:overdue_list': {
            $rows = $pdo->query("SELECT
                r.id AS routing_id,
                r.doc_id,
                r.resolve_deadline_at,
                r.sla_state,
                TIMESTAMPDIFF(MINUTE, NOW(), r.resolve_deadline_at) AS minutes_left,
                d.doc_number,
                d.doc_type,
                d.subject,
                COALESCE(s.full_name, r.to_dept, '(ไม่ระบุ)') AS assignee
                FROM sys_doc_routings r
                JOIN sys_doc_documents d ON d.id = r.doc_id
                LEFT JOIN sys_staff s ON s.id = r.to_user_id
                WHERE r.sla_state IN ('warning','breached')
                  AND r.status IN ('pending','acknowledged')
                ORDER BY r.resolve_deadline_at ASC
                LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        default:
            throw new RuntimeException('Unknown entity:action — ' . htmlspecialchars($entity . ':' . $action));
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
