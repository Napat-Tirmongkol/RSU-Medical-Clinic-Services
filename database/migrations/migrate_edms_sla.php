<?php
/**
 * database/migrations/migrate_edms_sla.php
 * EDMS SLA System — Sprint 1 Foundation
 *
 * สร้าง:
 *   - sys_doc_sla_policies        : matrix นโยบาย SLA (doc_type × priority)
 *   - sys_doc_sla_calendar        : เวลาทำการ + วันหยุด (seed Mon-Fri 08:00-16:00)
 *   - sys_doc_sla_events          : audit trail event ต่างๆ (started/warned/breached/met...)
 *
 * ALTER:
 *   - sys_doc_routings  +policy_id, ack_deadline_at, resolve_deadline_at,
 *                        sla_state, acknowledged_at, warned_at, breached_at,
 *                        paused_at, paused_total_secs, met_at, sla_reason
 *   - sys_staff         +access_edms_sla_admin, notify_sla_via_line
 *   - sys_staff_positions  +is_head (สำหรับ escalation ไป "หัวหน้าฝ่าย")
 *
 * Seed:
 *   - 16 SLA policies (4 doc_type × 4 priority levels) — ack ขั้นต่ำ 2h
 *     (รองรับ cron 1h interval — warning window อย่างน้อย 24 min)
 *   - business_hours Mon-Fri 08:00-16:00
 *
 * Backfill:
 *   - routing เก่าที่ยังไม่ done → attach policy + คำนวณ deadline จาก created_at
 *   - ถ้าเกิน deadline แล้ว → mark sla_state='breached', breached_at=NOW()
 *   - log event 'backfilled'
 *
 * รันครั้งเดียวผ่าน CLI หรือเปิด browser:
 *   php database/migrations/migrate_edms_sla.php
 *
 * ทุก statement idempotent — รันซ้ำได้ปลอดภัย
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

function _log(array &$results, bool $ok, string $msg): void {
    $results[] = ['ok' => $ok, 'msg' => $msg];
}

// ── 1) sys_doc_sla_policies ──────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_sla_policies (
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_type            VARCHAR(30) NOT NULL COMMENT 'FK logical -> sys_doc_types.code',
        priority_id         INT UNSIGNED NULL COMMENT 'FK -> sys_doc_categories.id (kind=priority); NULL = match any priority',
        name                VARCHAR(120) NOT NULL,
        ack_hours           DECIMAL(6,2) NOT NULL DEFAULT 4.00,
        resolve_hours       DECIMAL(6,2) NOT NULL DEFAULT 48.00,
        warn_at_pct         TINYINT UNSIGNED NOT NULL DEFAULT 20 COMMENT '0-100',
        business_hours_only TINYINT(1) NOT NULL DEFAULT 1,
        escalate_to_role    VARCHAR(60) NULL COMMENT 'superadmin+dept_head | superadmin | dept_head',
        is_active           TINYINT(1) NOT NULL DEFAULT 1,
        sort_order          SMALLINT NOT NULL DEFAULT 0,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_doctype_priority (doc_type, priority_id),
        INDEX idx_active (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    _log($results, true, 'สร้างตาราง sys_doc_sla_policies');
} catch (PDOException $e) {
    _log($results, false, 'sys_doc_sla_policies: ' . $e->getMessage());
}

// ── 2) Business calendar: ใช้ sys_clinic_hours (ปฏิทินคลินิก) ───────────
// SLA module ไม่สร้างตารางปฏิทินของตัวเอง — อ้างอิงเวลาทำการจาก
// sys_clinic_hours ผ่าน get_clinic_hours_for_date() ใน clinic_status_helper.php
// (ตั้งค่าได้ที่ portal/_partials/clinic_data/hours.php)
_log($results, true, 'Business calendar: อ้างอิง sys_clinic_hours (ปฏิทินคลินิก) — ตั้งค่าที่ ?section=clinic_data&cd_view=hours');

// ── 3) sys_doc_sla_events ────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doc_sla_events (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        routing_id      INT UNSIGNED NOT NULL,
        doc_id          INT UNSIGNED NOT NULL,
        event_type      ENUM('started','warned','paused','resumed','acknowledged','breached','met','extended','shortened','cancelled','escalated','backfilled','override')
                        NOT NULL,
        actor_user_id   INT UNSIGNED NULL COMMENT 'NULL = system/cron',
        reason          VARCHAR(255) NULL,
        metadata_json   JSON NULL COMMENT 'extension data',
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_routing (routing_id, created_at),
        INDEX idx_doc (doc_id, created_at),
        INDEX idx_event_type (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    _log($results, true, 'สร้างตาราง sys_doc_sla_events');
} catch (PDOException $e) {
    _log($results, false, 'sys_doc_sla_events: ' . $e->getMessage());
}

// ── 4) ALTER sys_doc_routings — เพิ่ม SLA columns ────────────────────────
$routingCols = [];
try {
    $routingCols = $pdo->query("DESCRIBE sys_doc_routings")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    _log($results, false, 'sys_doc_routings ไม่มี — ต้องรัน migrate_edms_module.php ก่อน');
}

$routingAlters = [
    'policy_id'           => "ALTER TABLE sys_doc_routings ADD COLUMN policy_id INT UNSIGNED NULL AFTER due_date",
    'ack_deadline_at'     => "ALTER TABLE sys_doc_routings ADD COLUMN ack_deadline_at DATETIME NULL AFTER policy_id",
    'resolve_deadline_at' => "ALTER TABLE sys_doc_routings ADD COLUMN resolve_deadline_at DATETIME NULL AFTER ack_deadline_at",
    'sla_state'           => "ALTER TABLE sys_doc_routings ADD COLUMN sla_state ENUM('on_track','warning','breached','met','paused','cancelled','none') NOT NULL DEFAULT 'none' AFTER resolve_deadline_at",
    'acknowledged_at'     => "ALTER TABLE sys_doc_routings ADD COLUMN acknowledged_at DATETIME NULL AFTER sla_state",
    'warned_at'           => "ALTER TABLE sys_doc_routings ADD COLUMN warned_at DATETIME NULL AFTER acknowledged_at",
    'breached_at'         => "ALTER TABLE sys_doc_routings ADD COLUMN breached_at DATETIME NULL AFTER warned_at",
    'met_at'              => "ALTER TABLE sys_doc_routings ADD COLUMN met_at DATETIME NULL AFTER breached_at",
    'paused_at'           => "ALTER TABLE sys_doc_routings ADD COLUMN paused_at DATETIME NULL AFTER met_at",
    'paused_total_secs'   => "ALTER TABLE sys_doc_routings ADD COLUMN paused_total_secs INT UNSIGNED NOT NULL DEFAULT 0 AFTER paused_at",
    'sla_reason'          => "ALTER TABLE sys_doc_routings ADD COLUMN sla_reason VARCHAR(255) NULL AFTER paused_total_secs",
];

if (!empty($routingCols)) {
    foreach ($routingAlters as $col => $sql) {
        if (in_array($col, $routingCols, true)) {
            _log($results, true, "sys_doc_routings.{$col} มีอยู่แล้ว");
            continue;
        }
        try {
            $pdo->exec($sql);
            _log($results, true, "เพิ่มคอลัมน์ sys_doc_routings.{$col}");
        } catch (PDOException $e) {
            _log($results, false, "ALTER {$col}: " . $e->getMessage());
        }
    }

    // index สำหรับ cron query
    try {
        $pdo->exec("ALTER TABLE sys_doc_routings ADD INDEX idx_sla_state (sla_state, resolve_deadline_at)");
        _log($results, true, 'เพิ่ม index idx_sla_state');
    } catch (PDOException) { /* ignore — มีอยู่แล้ว */ }
}

// ── 5) ALTER sys_staff — flag ใหม่ ────────────────────────────────────────
$staffCols = [];
try {
    $staffCols = $pdo->query("DESCRIBE sys_staff")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException) {}

foreach ([
    'access_edms_sla_admin' => "ALTER TABLE sys_staff ADD COLUMN access_edms_sla_admin TINYINT(1) NOT NULL DEFAULT 0",
    'notify_sla_via_line'   => "ALTER TABLE sys_staff ADD COLUMN notify_sla_via_line TINYINT(1) NOT NULL DEFAULT 1",
] as $col => $sql) {
    if (in_array($col, $staffCols, true)) {
        _log($results, true, "sys_staff.{$col} มีอยู่แล้ว");
        continue;
    }
    try {
        $pdo->exec($sql);
        _log($results, true, "เพิ่ม sys_staff.{$col}");
    } catch (PDOException $e) {
        _log($results, false, "sys_staff.{$col}: " . $e->getMessage());
    }
}

// ── 6) ALTER sys_staff_positions — is_head flag ──────────────────────────
$posCols = [];
try {
    $posCols = $pdo->query("DESCRIBE sys_staff_positions")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException) {}

if (!empty($posCols)) {
    if (!in_array('is_head', $posCols, true)) {
        try {
            $pdo->exec("ALTER TABLE sys_staff_positions ADD COLUMN is_head TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'หัวหน้าฝ่าย (สำหรับ SLA escalation)'");
            _log($results, true, 'เพิ่ม sys_staff_positions.is_head');
        } catch (PDOException $e) {
            _log($results, false, 'sys_staff_positions.is_head: ' . $e->getMessage());
        }
    } else {
        _log($results, true, 'sys_staff_positions.is_head มีอยู่แล้ว');
    }
}

// ── 7) Seed business_hours: ข้าม — ใช้ sys_clinic_hours แทน ─────────────
_log($results, true, 'ข้าม seed business_hours — ใช้ sys_clinic_hours (ปฏิทินคลินิก) แทน');

// ── 8) Seed 20 SLA policies (5 doc_type × 4 priority) ────────────────────
// Idempotent — UNIQUE (doc_type, priority_id) constraint → INSERT IGNORE skip ของเดิม
// รันซ้ำได้ → backfill เฉพาะที่ยังไม่มี (เช่น เพิ่ม task type ทีหลัง)
try {
    // ดึง priority categories (ปกติ/ด่วน/ด่วนมาก/ด่วนที่สุด)
    $priorities = $pdo->query("
        SELECT id, code, name FROM sys_doc_categories
        WHERE kind='priority' AND is_active=1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (count($priorities) === 0) {
        _log($results, false, 'ยังไม่มี priority categories — รัน migrate_edms_module.php ก่อน');
    } else {
        // Map by code → id (fallback by name)
        $pmap = [];
        foreach ($priorities as $p) {
            $pmap[$p['code']] = (int)$p['id'];
            $pmap[$p['name']] = (int)$p['id'];
        }

        // Matrix: [doc_type][priority_key] = [ack_h, resolve_h]
        // ack ขั้นต่ำ 2h เพื่อให้ warning window (20% ของ ack) ≥ 24 min กว่า cron tick (60 min)
        $matrix = [
            'incoming' => [
                'normal'      => [8.0, 72.0],
                'urgent'      => [4.0, 24.0],
                'very_urgent' => [2.0, 8.0],
                'most_urgent' => [2.0, 4.0],
            ],
            'outgoing' => [
                'normal'      => [8.0, 72.0],
                'urgent'      => [4.0, 48.0],
                'very_urgent' => [2.0, 16.0],
                'most_urgent' => [2.0, 8.0],
            ],
            'internal' => [
                'normal'      => [6.0, 48.0],
                'urgent'      => [4.0, 24.0],
                'very_urgent' => [2.0, 12.0],
                'most_urgent' => [2.0, 6.0],
            ],
            'circular' => [
                'normal'      => [24.0, 168.0],
                'urgent'      => [8.0, 48.0],
                'very_urgent' => [4.0, 24.0],
                'most_urgent' => [2.0, 8.0],
            ],
            'task' => [  // งาน — flexible deadline กว่าหนังสือ
                'normal'      => [8.0, 40.0],   // ack 1 วัน, resolve 1 สัปดาห์ทำการ
                'urgent'      => [4.0, 16.0],   // ack ครึ่งวัน, resolve 2 วันทำการ
                'very_urgent' => [2.0, 8.0],    // ack 2 ชม., resolve 1 วันทำการ
                'most_urgent' => [2.0, 4.0],    // ack 2 ชม., resolve ครึ่งวัน
            ],
        ];

        // Aliases for priority codes/names (fallback ตามตำแหน่ง)
        $priorityKeys = ['normal', 'urgent', 'very_urgent', 'most_urgent'];
        $thaiNames    = ['ปกติ', 'ด่วน', 'ด่วนมาก', 'ด่วนที่สุด'];

        // INSERT IGNORE → idempotent rerun, ไม่ overwrite policy ที่ admin แก้แล้ว
        $ins = $pdo->prepare("INSERT IGNORE INTO sys_doc_sla_policies
            (doc_type, priority_id, name, ack_hours, resolve_hours, warn_at_pct, business_hours_only, escalate_to_role, sort_order)
            VALUES (?, ?, ?, ?, ?, 20, 1, 'superadmin+dept_head', ?)");

        $created = 0;
        $skipped = 0;
        $order = 0;
        foreach ($matrix as $docType => $rows) {
            $i = 0;
            foreach ($rows as $pkey => $hours) {
                // หา priority_id จาก code/name หรือใช้ตำแหน่ง $i
                $pid = $pmap[$pkey] ?? $pmap[$thaiNames[$i] ?? ''] ?? null;
                if ($pid === null && isset($priorities[$i])) {
                    $pid = (int)$priorities[$i]['id'];
                }
                if ($pid === null) {
                    $i++;
                    $order++;
                    continue;
                }
                $name = "SLA · {$docType} · " . ($thaiNames[$i] ?? $pkey);
                $ins->execute([$docType, $pid, $name, $hours[0], $hours[1], $order]);
                if ($ins->rowCount() > 0) $created++;
                else $skipped++;
                $i++;
                $order++;
            }
        }
        _log($results, true, "SLA policies: +{$created} ใหม่, {$skipped} มีอยู่แล้ว (ไม่ overwrite)");
    }
} catch (PDOException $e) {
    _log($results, false, 'seed policies: ' . $e->getMessage());
}

// ── 9) Backfill: routing เก่าที่ยังไม่ done ──────────────────────────────
try {
    require_once __DIR__ . '/../../includes/edms_sla_helper.php';

    // ดึง routing ที่ยังไม่มี policy_id และยัง pending/acknowledged
    $rs = $pdo->query("
        SELECT r.id, r.doc_id, r.created_at, d.doc_type, d.priority_id
        FROM sys_doc_routings r
        JOIN sys_doc_documents d ON d.id = r.doc_id
        WHERE r.policy_id IS NULL
          AND r.status IN ('pending', 'acknowledged')
        LIMIT 5000
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $backfilled = 0;
    $breached = 0;
    foreach ($rs as $r) {
        $policy = sla_get_policy($pdo, $r['doc_type'], (int)$r['priority_id'] ?: null);
        if (!$policy) continue;

        $start = new DateTimeImmutable($r['created_at'], new DateTimeZone('Asia/Bangkok'));
        $ackDl = sla_compute_deadline($pdo, $start, (float)$policy['ack_hours'], (bool)$policy['business_hours_only']);
        $resDl = sla_compute_deadline($pdo, $start, (float)$policy['resolve_hours'], (bool)$policy['business_hours_only']);

        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
        $state = ($resDl < $now) ? 'breached' : 'on_track';
        $breachedAt = ($state === 'breached') ? $now->format('Y-m-d H:i:s') : null;
        if ($state === 'breached') $breached++;

        $upd = $pdo->prepare("UPDATE sys_doc_routings
            SET policy_id = ?, ack_deadline_at = ?, resolve_deadline_at = ?, sla_state = ?, breached_at = ?
            WHERE id = ?");
        $upd->execute([
            $policy['id'],
            $ackDl->format('Y-m-d H:i:s'),
            $resDl->format('Y-m-d H:i:s'),
            $state,
            $breachedAt,
            $r['id'],
        ]);

        // log event 'backfilled'
        sla_event_log($pdo, (int)$r['id'], (int)$r['doc_id'], 'backfilled', null, 'migration backfill', [
            'policy_id' => $policy['id'],
            'ack_deadline' => $ackDl->format('c'),
            'resolve_deadline' => $resDl->format('c'),
            'state' => $state,
        ]);

        $backfilled++;
    }

    _log($results, true, "Backfilled {$backfilled} routings (breached: {$breached})");
} catch (Throwable $e) {
    _log($results, false, 'backfill: ' . $e->getMessage());
}

// ── Output ────────────────────────────────────────────────────────────────
$isCli = (php_sapi_name() === 'cli');
if ($isCli) {
    foreach ($results as $r) {
        echo ($r['ok'] ? '✅ ' : '❌ ') . $r['msg'] . PHP_EOL;
    }
    $okCount = count(array_filter($results, fn($r) => $r['ok']));
    echo PHP_EOL . "✨ เสร็จสิ้น: {$okCount}/" . count($results) . " ขั้นตอน" . PHP_EOL;
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<style>body{font-family:sans-serif;padding:20px;background:#f8fafc} .ok{color:#15803d} .err{color:#b91c1c}</style>";
    echo "<h2>EDMS SLA Migration</h2><ul>";
    foreach ($results as $r) {
        $cls = $r['ok'] ? 'ok' : 'err';
        $icon = $r['ok'] ? '✅' : '❌';
        echo "<li class='{$cls}'>{$icon} " . htmlspecialchars($r['msg']) . "</li>";
    }
    echo "</ul>";
    $okCount = count(array_filter($results, fn($r) => $r['ok']));
    echo "<p><b>เสร็จสิ้น: {$okCount}/" . count($results) . " ขั้นตอน</b></p>";
    echo "<p style='color:#64748b;font-size:13px'>⚠️ ลบไฟล์นี้หลังรันเสร็จ — หรือเก็บไว้สำหรับ deploy ใหม่ (idempotent)</p>";
}
