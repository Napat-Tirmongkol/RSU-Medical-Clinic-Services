<?php
/**
 * Morning Brief — schema setup + cross-module data collectors.
 *
 * พฤติกรรม:
 *  - ensure_morning_brief_schema()  ── สร้างตาราง 3 ตัวที่ใช้ระบบ (auto-migrate, idempotent)
 *  - morning_brief_collect_all()    ── ดึงข้อมูลจากทุกโมดูล (snapshot ของวันนี้) คืนเป็น array
 *  - morning_brief_save()           ── บันทึก/อัปเดต brief ของวันนั้น (idempotent ตาม brief_date)
 *  - morning_brief_get_for_date()   ── อ่าน brief ของวันนั้นพร้อม AI narrative (ถ้ามี)
 *  - morning_brief_get_or_create_pref() ── สร้าง default pref ให้ staff อัตโนมัติเมื่อยังไม่มี
 *
 * ตารางทั้งหมดถูกห่อด้วย IF NOT EXISTS — ปลอดภัยต่อการ re-deploy
 */
declare(strict_types=1);

function ensure_morning_brief_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_morning_brief (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        brief_date    DATE NOT NULL,
        data_json     LONGTEXT NOT NULL,
        ai_narrative  LONGTEXT NULL,
        ai_model      VARCHAR(64) NULL,
        urgency_level ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
        generated_at  DATETIME NOT NULL,
        generated_by  VARCHAR(120) NULL,
        UNIQUE KEY uniq_date (brief_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_morning_brief_prefs (
        staff_id        INT UNSIGNED NOT NULL,
        staff_type      ENUM('admin','staff') NOT NULL DEFAULT 'admin',
        channel_portal  TINYINT(1) NOT NULL DEFAULT 1,
        channel_line    TINYINT(1) NOT NULL DEFAULT 0,
        channel_email   TINYINT(1) NOT NULL DEFAULT 0,
        delivery_hour   TINYINT NOT NULL DEFAULT 8,
        modules_json    TEXT NULL,
        line_user_id    VARCHAR(80) NULL,
        email           VARCHAR(180) NULL,
        last_read_date  DATE NULL,
        updated_at      DATETIME NOT NULL,
        PRIMARY KEY (staff_id, staff_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_morning_brief_delivery (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        brief_id    INT UNSIGNED NOT NULL,
        staff_id    INT UNSIGNED NOT NULL,
        staff_type  ENUM('admin','staff') NOT NULL DEFAULT 'admin',
        channel     ENUM('portal','line','email') NOT NULL,
        status      ENUM('queued','sent','failed','read') NOT NULL DEFAULT 'queued',
        error_msg   TEXT NULL,
        sent_at     DATETIME NULL,
        INDEX idx_brief (brief_id),
        INDEX idx_staff (staff_id, staff_type),
        UNIQUE KEY uniq_brief_staff_chan (brief_id, staff_id, staff_type, channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $checked = true;
}

// ─── Per-module data collectors ────────────────────────────────────────────

function _safe_int($pdo, string $sql, array $params = []): int {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (PDOException) { return 0; }
}

function _safe_rows($pdo, string $sql, array $params = []): array {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException) { return []; }
}

function morning_brief_collect_scholarship(PDO $pdo, string $today): array {
    return [
        'pending_approvals' => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_scholarship_logs WHERE status='pending'"),
        'today_shifts' => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_scholarship_shifts WHERE shift_date = :d",
            [':d' => $today]),
        'today_shift_students' => _safe_rows($pdo,
            "SELECT DISTINCT s.full_name, s.student_code, sh.start_time, sh.end_time
             FROM sys_scholarship_shifts sh
             JOIN sys_scholarship_students s ON s.id = sh.student_id
             WHERE sh.shift_date = :d ORDER BY sh.start_time LIMIT 8",
            [':d' => $today]),
        'late_clockins_yesterday' => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_scholarship_logs
             WHERE DATE(action_time) = DATE_SUB(:d, INTERVAL 1 DAY)
             AND status='approved' AND within_radius = 0",
            [':d' => $today]),
        'payouts_pending_count' => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_scholarship_payouts
             WHERE period_ym = :ym AND status = 'pending'",
            [':ym' => date('Y-m', strtotime($today))]),
        'payouts_pending_total' => (float)_safe_int($pdo,
            "SELECT COALESCE(SUM(amount),0) FROM sys_scholarship_payouts
             WHERE period_ym = :ym AND status = 'pending'",
            [':ym' => date('Y-m', strtotime($today))]),
    ];
}

function morning_brief_collect_finance(PDO $pdo, string $today): array {
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $income = _safe_int($pdo,
        "SELECT COALESCE(SUM(amount),0) FROM sys_finance_transactions
         WHERE txn_date = :d AND kind='income'", [':d' => $yesterday]);
    $expense = _safe_int($pdo,
        "SELECT COALESCE(SUM(amount),0) FROM sys_finance_transactions
         WHERE txn_date = :d AND kind='expense'", [':d' => $yesterday]);
    return [
        'yesterday_income'  => $income,
        'yesterday_expense' => $expense,
        'yesterday_net'     => $income - $expense,
        'recurring_due_today' => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_finance_recurring
             WHERE active=1 AND day_of_month = DAY(:d)
             AND (last_generated_ym IS NULL OR last_generated_ym <> DATE_FORMAT(:d, '%Y-%m'))",
            [':d' => $today]),
    ];
}

function morning_brief_collect_edms(PDO $pdo, string $today): array {
    return [
        'tasks_due_today' => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_edms_tasks
             WHERE due_date = :d AND status IN ('pending','in_progress')",
            [':d' => $today]),
        'tasks_overdue' => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_edms_tasks
             WHERE due_date < :d AND status IN ('pending','in_progress')",
            [':d' => $today]),
        'sla_breached' => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_edms_tasks
             WHERE status IN ('pending','in_progress')
             AND sla_due_at IS NOT NULL AND sla_due_at < NOW()"),
    ];
}

function morning_brief_collect_inventory(PDO $pdo, string $today): array {
    return [
        'consumables_low_stock' => _safe_int($pdo,
            "SELECT COUNT(*) FROM consumables_items
             WHERE current_qty <= reorder_qty AND is_active = 1"),
        'consumables_expiring_30d' => _safe_int($pdo,
            "SELECT COUNT(*) FROM consumables_items
             WHERE expiry_date IS NOT NULL
             AND expiry_date BETWEEN :d AND DATE_ADD(:d, INTERVAL 30 DAY)",
            [':d' => $today]),
    ];
}

function morning_brief_collect_clinic(PDO $pdo, string $today): array {
    $clinicOpen = null;
    $clinicHours = null;
    try {
        if (function_exists('get_clinic_hours_for_date')) {
            $hours = get_clinic_hours_for_date($pdo, $today);
            if ($hours) {
                $clinicOpen = !empty($hours['is_open']);
                $clinicHours = ($hours['open_time'] ?? '') . '–' . ($hours['close_time'] ?? '');
            }
        }
    } catch (Throwable) {}
    return [
        'date_thai'      => _format_thai_date($today),
        'weekday_thai'   => _weekday_thai($today),
        'clinic_open'    => $clinicOpen,
        'clinic_hours'   => $clinicHours,
        'nurses_today'   => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_nurse_schedule WHERE shift_date = :d",
            [':d' => $today]),
        'doctors_today'  => _safe_int($pdo,
            "SELECT COUNT(*) FROM sys_doctor_schedule
             WHERE (type='regular' AND weekday = WEEKDAY(:d))
                OR (type='override' AND specific_date = :d)",
            [':d' => $today]),
        'appointments_today' => _safe_int($pdo,
            "SELECT COUNT(*) FROM bookings
             WHERE DATE(booking_date) = :d AND status NOT IN ('cancelled','no_show')",
            [':d' => $today]),
    ];
}

function _format_thai_date(string $ymd): string {
    $months = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
               'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
    $t = strtotime($ymd);
    if (!$t) return $ymd;
    return (int)date('j', $t) . ' ' . $months[(int)date('n', $t)] . ' ' . ((int)date('Y', $t) + 543);
}

function _weekday_thai(string $ymd): string {
    $days = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
    return $days[(int)date('w', strtotime($ymd))] ?? '';
}

function morning_brief_collect_all(PDO $pdo, ?string $date = null): array {
    $today = $date ?: date('Y-m-d');
    return [
        'brief_date'   => $today,
        'collected_at' => date('Y-m-d H:i:s'),
        'clinic'       => morning_brief_collect_clinic($pdo, $today),
        'scholarship'  => morning_brief_collect_scholarship($pdo, $today),
        'finance'      => morning_brief_collect_finance($pdo, $today),
        'edms'         => morning_brief_collect_edms($pdo, $today),
        'inventory'    => morning_brief_collect_inventory($pdo, $today),
    ];
}

// ─── Brief persistence ──────────────────────────────────────────────────────

function morning_brief_save(PDO $pdo, string $date, array $data, ?string $aiNarrative, ?string $aiModel, string $generatedBy, string $urgency = 'normal'): int {
    ensure_morning_brief_schema($pdo);
    $existing = $pdo->prepare("SELECT id FROM sys_morning_brief WHERE brief_date = :d");
    $existing->execute([':d' => $date]);
    $id = (int)$existing->fetchColumn();
    if ($id) {
        $st = $pdo->prepare("UPDATE sys_morning_brief
            SET data_json=:j, ai_narrative=:n, ai_model=:m, urgency_level=:u, generated_at=NOW(), generated_by=:b WHERE id=:id");
        $st->execute([':j'=>json_encode($data, JSON_UNESCAPED_UNICODE), ':n'=>$aiNarrative,
                     ':m'=>$aiModel, ':u'=>$urgency, ':b'=>$generatedBy, ':id'=>$id]);
        return $id;
    }
    $st = $pdo->prepare("INSERT INTO sys_morning_brief
        (brief_date, data_json, ai_narrative, ai_model, urgency_level, generated_at, generated_by)
        VALUES (:d, :j, :n, :m, :u, NOW(), :b)");
    $st->execute([':d'=>$date, ':j'=>json_encode($data, JSON_UNESCAPED_UNICODE),
                 ':n'=>$aiNarrative, ':m'=>$aiModel, ':u'=>$urgency, ':b'=>$generatedBy]);
    return (int)$pdo->lastInsertId();
}

function morning_brief_get_for_date(PDO $pdo, string $date): ?array {
    ensure_morning_brief_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM sys_morning_brief WHERE brief_date = :d LIMIT 1");
    $st->execute([':d' => $date]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['data'] = json_decode($row['data_json'] ?? '{}', true) ?: [];
    return $row;
}

// ─── Preferences ───────────────────────────────────────────────────────────

function morning_brief_get_or_create_pref(PDO $pdo, int $staffId, string $staffType = 'admin'): array {
    ensure_morning_brief_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM sys_morning_brief_prefs WHERE staff_id=:sid AND staff_type=:st");
    $st->execute([':sid'=>$staffId, ':st'=>$staffType]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;
    $defaults = ['scholarship','finance','edms','clinic','inventory'];
    $ins = $pdo->prepare("INSERT INTO sys_morning_brief_prefs
        (staff_id, staff_type, channel_portal, channel_line, channel_email, delivery_hour, modules_json, updated_at)
        VALUES (:sid, :st, 1, 0, 0, 8, :m, NOW())");
    $ins->execute([':sid'=>$staffId, ':st'=>$staffType, ':m'=>json_encode($defaults)]);
    $st->execute([':sid'=>$staffId, ':st'=>$staffType]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

function morning_brief_mark_read(PDO $pdo, int $staffId, string $staffType, string $date): void {
    ensure_morning_brief_schema($pdo);
    morning_brief_get_or_create_pref($pdo, $staffId, $staffType);
    $st = $pdo->prepare("UPDATE sys_morning_brief_prefs SET last_read_date=:d, updated_at=NOW()
        WHERE staff_id=:sid AND staff_type=:st");
    $st->execute([':d'=>$date, ':sid'=>$staffId, ':st'=>$staffType]);
}
