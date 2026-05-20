<?php
// portal/ajax_vaccinations.php — Vaccination management (Phase 1)
// Actions:
//   stats   GET  → KPI counts + 12-month trend + breakdown by vaccine type
//   list    GET  → paginated records with filters (date range, vaccine, search)
//   detail  GET  → single record full state
//   update  POST → admin edit lot/manufacturer/dose/cert + reason + audit log
//   types   GET  → vaccine catalog list (read-only in Phase 1)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$canVacc   = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_identity']);
if (!$canVacc) {
    echo json_encode(['ok' => false, 'message' => 'ต้องมีสิทธิ์ admin หรือ access_identity']);
    exit;
}

$pdo    = db();
$action = (string)($_GET['action'] ?? '');

// ── Self-healing migrations ─────────────────────────────────────────────────
// All schema deltas live here so a fresh deploy doesn't need a manual run.
// Idempotent (CREATE IF NOT EXISTS + ALTER … IF NOT EXISTS).
try {
    // 1. Vaccine catalog — master types (Influenza, COVID-19, HepB, …)
    //    Phase 1 reads this; Phase 2 will add CRUD UI.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sys_vaccine_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            name_th VARCHAR(200) NOT NULL,
            name_en VARCHAR(200) NOT NULL DEFAULT '',
            default_doses TINYINT UNSIGNED NOT NULL DEFAULT 1,
            interval_days INT NULL DEFAULT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'routine',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_code (code),
            INDEX idx_active_sort (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // 2. Audit log — every admin edit captured with before/after for forensics
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sys_vaccine_audit (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            record_id INT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            changes_json LONGTEXT NULL DEFAULT NULL,
            reason VARCHAR(500) NULL DEFAULT NULL,
            performed_by_id INT UNSIGNED NULL DEFAULT NULL,
            performed_by_name VARCHAR(150) NULL DEFAULT NULL,
            ip_addr VARCHAR(45) NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_record (record_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    // 3. Link existing user_vaccination_records → catalog (NULL for legacy rows)
    try { $pdo->exec("ALTER TABLE user_vaccination_records ADD COLUMN IF NOT EXISTS vaccine_type_id INT UNSIGNED NULL DEFAULT NULL"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE user_vaccination_records ADD INDEX IF NOT EXISTS idx_vaccine_type_id (vaccine_type_id)"); } catch (PDOException) {}

    // 4. Seed common Thai-context vaccines if catalog is empty. Insert IGNORE
    //    so a re-run on a partially-edited catalog doesn't overwrite changes.
    $haveAny = (int)$pdo->query("SELECT COUNT(*) FROM sys_vaccine_types")->fetchColumn();
    if ($haveAny === 0) {
        $seed = [
            ['INFLU',     'ไข้หวัดใหญ่ตามฤดูกาล',  'Seasonal Influenza',         1, 365, 'routine',  10],
            ['COVID19',   'COVID-19',               'COVID-19',                   2, 180, 'routine',  20],
            ['HEPB',      'ไวรัสตับอักเสบ B',       'Hepatitis B',                3, 180, 'routine',  30],
            ['TET',       'บาดทะยัก',                'Tetanus',                    1, 3650,'routine',  40],
            ['MMR',       'หัด-คางทูม-หัดเยอรมัน',   'Measles-Mumps-Rubella (MMR)',2, 28,  'routine',  50],
            ['HPV',       'HPV',                     'Human Papillomavirus',       2, 180, 'routine',  60],
            ['PNEUMO',    'นิวโมคอคคัส',             'Pneumococcal',               1, 1825,'routine',  70],
            ['RABIES',    'พิษสุนัขบ้า (post-exposure)','Rabies (post-exposure)',   5, 28,  'on-demand',80],
            ['JE',        'ไข้สมองอักเสบ JE',        'Japanese Encephalitis',      2, 28,  'routine',  90],
            ['TDAP',      'Td/Tdap',                 'Tetanus-Diphtheria-Pertussis',1,3650,'routine', 100],
        ];
        $ins = $pdo->prepare("INSERT INTO sys_vaccine_types
            (code, name_th, name_en, default_doses, interval_days, category, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        foreach ($seed as $row) $ins->execute($row);
    }
} catch (Throwable $e) {
    error_log('[vaccinations] migration: ' . $e->getMessage());
}

function vacc_clamp_int($v, int $lo, int $hi, int $default): int {
    $n = is_numeric($v) ? (int)$v : $default;
    return max($lo, min($hi, $n));
}

// Shared filter builder — same WHERE for list + export
function vacc_build_filter(array $query): array {
    // Bind the "entered_in_error" exclusion as a parameter instead of an
    // inline string. The earlier form `v.status <> "entered_in_error"`
    // worked only because MariaDB defaults to treating double quotes as
    // string literals — flip ANSI_QUOTES on (or migrate to a hosting that
    // does) and the same SQL would parse the double-quoted token as a
    // column identifier, fail silently on JOIN, and the dashboard ends up
    // showing a single stray row instead of the real list.
    $where = ['v.status <> :exclude_status'];
    $bind  = [':exclude_status' => 'entered_in_error'];

    $q = trim((string)($query['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(u.full_name LIKE :q1 OR v.vaccine_name LIKE :q2 OR v.lot_number LIKE :q3 OR v.certificate_no LIKE :q4)';
        $like = '%' . $q . '%';
        $bind[':q1'] = $like; $bind[':q2'] = $like; $bind[':q3'] = $like; $bind[':q4'] = $like;
    }
    $from = trim((string)($query['from'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'v.vaccinated_at >= :from';
        $bind[':from'] = $from . ' 00:00:00';
    }
    $to = trim((string)($query['to'] ?? ''));
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'v.vaccinated_at <= :to';
        $bind[':to'] = $to . ' 23:59:59';
    }
    $typeId = (int)($query['type_id'] ?? 0);
    if ($typeId > 0) {
        $where[] = 'v.vaccine_type_id = :tid';
        $bind[':tid'] = $typeId;
    }
    $status = (string)($query['status'] ?? '');
    if (in_array($status, ['completed', 'cancelled'], true)) {
        $where[] = 'v.status = :st';
        $bind[':st'] = $status;
    }
    return [' WHERE ' . implode(' AND ', $where), $bind];
}

if ($action === 'stats') {
    try {
        $monthStart = date('Y-m-01 00:00:00');
        $yearStart  = date('Y-01-01 00:00:00');

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_vaccination_records WHERE status='completed' AND vaccinated_at >= :s");
        $stmt->execute([':s' => $monthStart]);
        $thisMonth = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_vaccination_records WHERE status='completed' AND vaccinated_at >= :s");
        $stmt->execute([':s' => $yearStart]);
        $thisYear = (int)$stmt->fetchColumn();

        $total = (int)$pdo->query("SELECT COUNT(*) FROM user_vaccination_records WHERE status='completed'")->fetchColumn();

        // Top vaccine this month — group by vaccine_name (catalog may be NULL on legacy rows)
        $stmt = $pdo->prepare("
            SELECT vaccine_name, COUNT(*) AS n
            FROM user_vaccination_records
            WHERE status='completed' AND vaccinated_at >= :s
            GROUP BY vaccine_name ORDER BY n DESC LIMIT 1
        ");
        $stmt->execute([':s' => $monthStart]);
        $topThisMonth = $stmt->fetch(PDO::FETCH_ASSOC);

        // Next-due alerts: completed rows where next_due_date is in next 7-30 days
        $upcoming = (int)$pdo->query("
            SELECT COUNT(*) FROM user_vaccination_records
            WHERE status='completed' AND next_due_date IS NOT NULL
              AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ")->fetchColumn();
        $overdue = (int)$pdo->query("
            SELECT COUNT(*) FROM user_vaccination_records
            WHERE status='completed' AND next_due_date IS NOT NULL
              AND next_due_date < CURDATE()
        ")->fetchColumn();

        // 12-month trend (last 12 calendar months including current)
        $trend = $pdo->query("
            SELECT DATE_FORMAT(vaccinated_at, '%Y-%m') AS ym, COUNT(*) AS n
            FROM user_vaccination_records
            WHERE status='completed' AND vaccinated_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY ym ORDER BY ym ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Breakdown by vaccine (this year)
        $byVaccine = $pdo->query("
            SELECT vaccine_name, COUNT(*) AS n
            FROM user_vaccination_records
            WHERE status='completed' AND vaccinated_at >= DATE_FORMAT(CURDATE(), '%Y-01-01')
            GROUP BY vaccine_name ORDER BY n DESC LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'kpi' => [
                'this_month'    => $thisMonth,
                'this_year'     => $thisYear,
                'total'         => $total,
                'top_month'     => $topThisMonth ?: null,
                'upcoming_30d'  => $upcoming,
                'overdue'       => $overdue,
            ],
            'trend'      => $trend,
            'by_vaccine' => $byVaccine,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[vaccinations] stats: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'ดึงสถิติไม่สำเร็จ — โปรดลองอีกครั้ง']);
    }
    exit;
}

if ($action === 'list') {
    try {
        $page    = vacc_clamp_int($_GET['page'] ?? 1, 1, 100000, 1);
        $perPage = vacc_clamp_int($_GET['per_page'] ?? 20, 5, 100, 20);
        $offset  = ($page - 1) * $perPage;

        [$whereSql, $bind] = vacc_build_filter($_GET);

        $countSql = "SELECT COUNT(*) FROM user_vaccination_records v
                     LEFT JOIN sys_users u ON u.id = v.user_id
                     {$whereSql}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($bind);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT v.id, v.user_id, u.full_name, u.citizen_id, u.line_user_id,
                       v.vaccine_type_id, v.vaccine_name, v.dose_number,
                       v.lot_number, v.manufacturer, v.vaccinated_at,
                       v.injection_site, v.provider_name, v.location,
                       v.next_due_date, v.certificate_no, v.certificate_file,
                       v.status, v.notes, v.campaign_booking_id,
                       v.created_at, v.updated_at
                FROM user_vaccination_records v
                LEFT JOIN sys_users u ON u.id = v.user_id
                {$whereSql}
                ORDER BY v.vaccinated_at DESC, v.id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($bind as $k => $val) $stmt->bindValue($k, $val);
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Mask citizen_id to last-4 — superadmin sees raw, others see masked
        foreach ($rows as &$r) {
            $cid = (string)($r['citizen_id'] ?? '');
            if ($cid !== '' && !$isSuper && strlen($cid) >= 4) {
                $r['citizen_id'] = str_repeat('•', max(0, strlen($cid) - 4)) . substr($cid, -4);
            }
            // LINE ID: hide entirely from non-superadmin (not needed for vaccine tracking)
            if (!$isSuper) $r['line_user_id'] = null;
        }
        unset($r);

        echo json_encode([
            'ok'         => true,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'page_count' => max(1, (int)ceil($total / $perPage)),
            'rows'       => $rows,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[vaccinations] list: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'ดึงรายการไม่สำเร็จ — โปรดลองอีกครั้ง']);
    }
    exit;
}

if ($action === 'detail') {
    try {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) throw new LogicException('id ไม่ถูกต้อง');

        $stmt = $pdo->prepare("
            SELECT v.*, u.full_name, u.citizen_id, u.line_user_id, u.email, u.phone_number, u.status AS user_status,
                   t.code AS type_code, t.name_th AS type_name_th, t.name_en AS type_name_en,
                   t.default_doses, t.interval_days
            FROM user_vaccination_records v
            LEFT JOIN sys_users u ON u.id = v.user_id
            LEFT JOIN sys_vaccine_types t ON t.id = v.vaccine_type_id
            WHERE v.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new LogicException('ไม่พบบันทึก');

        // Most recent 20 audit entries for this record
        $stmt = $pdo->prepare("SELECT id, action, changes_json, reason, performed_by_name, ip_addr, created_at
                               FROM sys_vaccine_audit
                               WHERE record_id = :id ORDER BY created_at DESC, id DESC LIMIT 20");
        $stmt->execute([':id' => $id]);
        $audit = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($audit as &$a) {
            $a['changes'] = $a['changes_json'] ? json_decode($a['changes_json'], true) : null;
            unset($a['changes_json']);
        }
        unset($a);

        if (!$isSuper && !empty($row['citizen_id']) && strlen($row['citizen_id']) >= 4) {
            $row['citizen_id'] = str_repeat('•', strlen($row['citizen_id']) - 4) . substr($row['citizen_id'], -4);
        }
        if (!$isSuper) $row['line_user_id'] = null;

        echo json_encode(['ok' => true, 'row' => $row, 'audit' => $audit], JSON_UNESCAPED_UNICODE);
    } catch (LogicException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[vaccinations] detail: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'ดึงรายละเอียดไม่สำเร็จ']);
    }
    exit;
}

if ($action === 'update') {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new LogicException('ต้องเป็น POST');
        validate_csrf_or_die();

        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($id <= 0) throw new LogicException('id ไม่ถูกต้อง');
        if (mb_strlen($reason) < 5)   throw new LogicException('กรุณาระบุเหตุผลอย่างน้อย 5 ตัวอักษร');
        if (mb_strlen($reason) > 500) throw new LogicException('เหตุผลต้องไม่เกิน 500 ตัวอักษร');

        // Snapshot before-state for the audit row
        $stmt = $pdo->prepare("SELECT * FROM user_vaccination_records WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new LogicException('ไม่พบบันทึก');

        // Whitelisted editable fields. Each value validated/cast to safe type.
        $editable = [];
        if (isset($_POST['vaccine_type_id'])) {
            $tid = (int)$_POST['vaccine_type_id'];
            $editable['vaccine_type_id'] = $tid > 0 ? $tid : null;
        }
        if (isset($_POST['vaccine_name'])) {
            $v = trim((string)$_POST['vaccine_name']);
            if ($v === '' || mb_strlen($v) > 200) throw new LogicException('ชื่อวัคซีนไม่ถูกต้อง');
            $editable['vaccine_name'] = $v;
        }
        if (isset($_POST['dose_number'])) {
            $d = $_POST['dose_number'];
            $editable['dose_number'] = ($d === '' || $d === null) ? null : max(1, min(20, (int)$d));
        }
        if (isset($_POST['lot_number'])) {
            $v = trim((string)$_POST['lot_number']);
            if (mb_strlen($v) > 100) throw new LogicException('lot_number เกิน 100 ตัวอักษร');
            $editable['lot_number'] = $v !== '' ? $v : null;
        }
        if (isset($_POST['manufacturer'])) {
            $v = trim((string)$_POST['manufacturer']);
            if (mb_strlen($v) > 150) throw new LogicException('manufacturer เกิน 150 ตัวอักษร');
            $editable['manufacturer'] = $v !== '' ? $v : null;
        }
        if (isset($_POST['injection_site'])) {
            $v = trim((string)$_POST['injection_site']);
            if (mb_strlen($v) > 100) throw new LogicException('injection_site เกิน 100 ตัวอักษร');
            $editable['injection_site'] = $v !== '' ? $v : null;
        }
        if (isset($_POST['provider_name'])) {
            $v = trim((string)$_POST['provider_name']);
            if (mb_strlen($v) > 255) throw new LogicException('provider_name เกิน 255 ตัวอักษร');
            $editable['provider_name'] = $v !== '' ? $v : null;
        }
        if (isset($_POST['location'])) {
            $v = trim((string)$_POST['location']);
            if (mb_strlen($v) > 255) throw new LogicException('location เกิน 255 ตัวอักษร');
            $editable['location'] = $v !== '' ? $v : null;
        }
        if (isset($_POST['next_due_date'])) {
            $v = trim((string)$_POST['next_due_date']);
            if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new LogicException('next_due_date ไม่ถูกต้อง');
            $editable['next_due_date'] = $v !== '' ? $v : null;
        }
        if (isset($_POST['certificate_no'])) {
            $v = trim((string)$_POST['certificate_no']);
            if (mb_strlen($v) > 100) throw new LogicException('certificate_no เกิน 100 ตัวอักษร');
            $editable['certificate_no'] = $v !== '' ? $v : null;
        }
        if (isset($_POST['notes'])) {
            $v = trim((string)$_POST['notes']);
            if (mb_strlen($v) > 2000) throw new LogicException('notes เกิน 2000 ตัวอักษร');
            $editable['notes'] = $v !== '' ? $v : null;
        }
        if (isset($_POST['status'])) {
            $s = (string)$_POST['status'];
            if (!in_array($s, ['completed', 'cancelled', 'entered_in_error'], true)) {
                throw new LogicException('status ไม่ถูกต้อง');
            }
            // Only superadmin can mark a record as 'entered_in_error' (data deletion equivalent)
            if ($s === 'entered_in_error' && !$isSuper) {
                throw new LogicException('การยกเลิกบันทึก (entered_in_error) ต้องใช้ superadmin');
            }
            $editable['status'] = $s;
        }

        if (!$editable) throw new LogicException('ไม่มีฟิลด์ที่จะแก้ไข');

        // Build SET clause from whitelisted fields only
        $sets = [];
        $bind = [':id' => $id];
        foreach ($editable as $col => $val) {
            $sets[] = "`{$col}` = :{$col}";
            $bind[":{$col}"] = $val;
        }
        $sets[] = 'updated_by = :uby';
        $bind[':uby'] = (int)($_SESSION['admin_id'] ?? 0) ?: null;

        $sql = "UPDATE user_vaccination_records SET " . implode(', ', $sets) . " WHERE id = :id";
        $upd = $pdo->prepare($sql);
        $upd->execute($bind);

        // Compute changeset diff for audit log — only fields that actually changed
        $changeset = [];
        foreach ($editable as $col => $newVal) {
            $oldVal = $before[$col] ?? null;
            if ((string)$oldVal !== (string)$newVal) {
                $changeset[$col] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        $adminId   = (int)($_SESSION['admin_id'] ?? 0);
        $adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'admin');
        $stmt = $pdo->prepare("INSERT INTO sys_vaccine_audit
            (record_id, action, changes_json, reason, performed_by_id, performed_by_name, ip_addr)
            VALUES (?, 'update', ?, ?, ?, ?, ?)");
        $stmt->execute([
            $id,
            json_encode($changeset, JSON_UNESCAPED_UNICODE),
            mb_substr($reason, 0, 500),
            $adminId ?: null,
            $adminName,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        // Mirror summary into sys_activity_logs for the cross-module audit trail.
        // No PII in the description — just record_id + op + diff keys.
        try {
            log_activity(
                'Vaccine Record Edit',
                'record_id=' . $id . ' fields=' . implode(',', array_keys($changeset)) . ' reason=' . mb_substr($reason, 0, 200),
                $adminId ?: null
            );
        } catch (Throwable $e) {
            error_log('[vaccinations] log_activity: ' . $e->getMessage());
        }

        echo json_encode(['ok' => true, 'message' => 'บันทึกเรียบร้อย', 'changeset' => $changeset], JSON_UNESCAPED_UNICODE);
    } catch (LogicException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[vaccinations] update: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'บันทึกไม่สำเร็จ — โปรดลองอีกครั้ง']);
    }
    exit;
}

if ($action === 'types') {
    try {
        $rows = $pdo->query("SELECT id, code, name_th, name_en, default_doses, interval_days, category, sort_order, is_active
                             FROM sys_vaccine_types WHERE is_active = 1
                             ORDER BY sort_order ASC, name_th ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'types' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[vaccinations] types: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'ดึง catalog ไม่สำเร็จ']);
    }
    exit;
}

echo json_encode(['ok' => false, 'message' => 'unknown action']);
