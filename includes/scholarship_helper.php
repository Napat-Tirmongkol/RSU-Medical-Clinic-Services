<?php
/**
 * includes/scholarship_helper.php
 * Schema + helper functions สำหรับระบบเก็บชั่วโมงทำงานนักศึกษาทุน
 */
declare(strict_types=1);

const SCHOLARSHIP_DEFAULT_RADIUS_M = 50;     // รัศมี GPS รอบคลินิก (เมตร)
const SCHOLARSHIP_GRACE_BEFORE_MIN = 15;     // เข้างานก่อนเริ่มกะได้กี่นาที
// ไม่จำกัดเวลาออกงานหลังจบกะ (overtime + เพิ่มไปได้)

/**
 * ติดตั้งตารางทั้งหมดของระบบนักศึกษาทุน (self-healing)
 * เรียกครั้งเดียวต่อ request (cached ผ่าน static)
 */
function ensure_scholarship_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_students (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            student_code VARCHAR(32) NOT NULL DEFAULT '',
            faculty VARCHAR(120) NOT NULL DEFAULT '',
            scholarship_type VARCHAR(80) NOT NULL DEFAULT '',
            semester VARCHAR(16) NOT NULL DEFAULT '',
            max_hours INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            notes VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user (user_id),
            KEY idx_status (status),
            KEY idx_semester (semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_shifts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            shift_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            planned_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
            comp_type ENUM('hours','paid') NOT NULL DEFAULT 'hours',
            status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
            created_by INT UNSIGNED NULL,
            notes VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_student_date (student_id, shift_date),
            KEY idx_date (shift_date),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $pdo->exec("ALTER TABLE sys_scholarship_shifts ADD COLUMN IF NOT EXISTS comp_type ENUM('hours','paid') NOT NULL DEFAULT 'hours' AFTER planned_hours"); } catch (PDOException) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_clock_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            shift_id INT UNSIGNED NULL,
            action ENUM('clock_in','clock_out') NOT NULL,
            comp_type ENUM('hours','paid') NOT NULL DEFAULT 'hours',
            event_at DATETIME NOT NULL,
            gps_lat DECIMAL(10,7) NULL,
            gps_lng DECIMAL(10,7) NULL,
            gps_accuracy DECIMAL(7,2) NULL,
            distance_m DECIMAL(8,2) NULL,
            within_radius TINYINT(1) NOT NULL DEFAULT 0,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            reject_reason VARCHAR(255) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_student_event (student_id, event_at),
            KEY idx_shift (shift_id),
            KEY idx_status (status),
            KEY idx_event_at (event_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $pdo->exec("ALTER TABLE sys_scholarship_clock_logs ADD COLUMN IF NOT EXISTS comp_type ENUM('hours','paid') NOT NULL DEFAULT 'hours' AFTER action"); } catch (PDOException) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_settings (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            clinic_lat DECIMAL(10,7) NULL,
            clinic_lng DECIMAL(10,7) NULL,
            radius_m INT UNSIGNED NOT NULL DEFAULT " . SCHOLARSHIP_DEFAULT_RADIUS_M . ",
            grace_before_min INT UNSIGNED NOT NULL DEFAULT " . SCHOLARSHIP_GRACE_BEFORE_MIN . ",
            require_approval TINYINT(1) NOT NULL DEFAULT 1,
            gps_required TINYINT(1) NOT NULL DEFAULT 1,
            pay_rate_per_hour DECIMAL(8,2) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Self-healing: ถ้าตารางมีอยู่แล้วแต่ไม่มี column ใหม่ ให้เพิ่ม
        try { $pdo->exec("ALTER TABLE sys_scholarship_settings ADD COLUMN IF NOT EXISTS gps_required TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_scholarship_settings ADD COLUMN IF NOT EXISTS pay_rate_per_hour DECIMAL(8,2) NOT NULL DEFAULT 0"); } catch (PDOException) {}

        // Seed singleton row
        $pdo->exec("INSERT IGNORE INTO sys_scholarship_settings (id) VALUES (1)");

        // ปรับชั่วโมงด้วยมือโดย admin (บวก/ลบ ได้ — ไม่แตะ clock_logs เพื่อรักษา audit trail)
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_manual_adjustments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            comp_type ENUM('hours','paid') NOT NULL DEFAULT 'hours',
            hours_delta DECIMAL(6,2) NOT NULL,
            adjusted_date DATE NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_student_date (student_id, adjusted_date),
            KEY idx_comp (comp_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ── รอบงานที่ admin เปิดให้นักศึกษาจอง (คล้าย camp_slots) ──
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_slots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slot_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            max_capacity INT UNSIGNED NOT NULL DEFAULT 1,
            comp_type ENUM('hours','paid') NOT NULL DEFAULT 'hours',
            notes VARCHAR(255) NOT NULL DEFAULT '',
            status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_date (slot_date),
            KEY idx_status (status),
            KEY idx_date_status (slot_date, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_slot_bookings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slot_id INT UNSIGNED NOT NULL,
            student_id INT UNSIGNED NOT NULL,
            shift_id INT UNSIGNED NULL,
            status ENUM('booked','cancelled') NOT NULL DEFAULT 'booked',
            booked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at DATETIME NULL,
            cancel_reason VARCHAR(255) NOT NULL DEFAULT '',
            UNIQUE KEY uniq_slot_student (slot_id, student_id),
            KEY idx_student (student_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try { $pdo->exec("ALTER TABLE sys_scholarship_settings ADD COLUMN IF NOT EXISTS cancel_cutoff_hours INT UNSIGNED NOT NULL DEFAULT 24"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_scholarship_shifts ADD COLUMN IF NOT EXISTS slot_id INT UNSIGNED NULL AFTER student_id"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_scholarship_shifts ADD INDEX idx_slot (slot_id)"); } catch (PDOException) {}
    } catch (PDOException $e) {
        error_log('[scholarship_helper] schema migration failed: ' . $e->getMessage());
    }

    $done = true;
}

function get_scholarship_settings(PDO $pdo): array
{
    ensure_scholarship_schema($pdo);
    $row = $pdo->query("SELECT * FROM sys_scholarship_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'clinic_lat' => null,
            'clinic_lng' => null,
            'radius_m' => SCHOLARSHIP_DEFAULT_RADIUS_M,
            'grace_before_min' => SCHOLARSHIP_GRACE_BEFORE_MIN,
            'require_approval' => 1,
            'gps_required' => 1,
            'pay_rate_per_hour' => 0,
        ];
    }
    // ค่า default สำหรับ row ที่สร้างก่อนเพิ่ม column
    $row['gps_required'] = isset($row['gps_required']) ? (int)$row['gps_required'] : 1;
    $row['pay_rate_per_hour'] = isset($row['pay_rate_per_hour']) ? (float)$row['pay_rate_per_hour'] : 0;
    $row['cancel_cutoff_hours'] = isset($row['cancel_cutoff_hours']) ? (int)$row['cancel_cutoff_hours'] : 24;
    return $row;
}

function save_scholarship_settings(PDO $pdo, array $data): bool
{
    ensure_scholarship_schema($pdo);
    $stmt = $pdo->prepare("UPDATE sys_scholarship_settings SET
        clinic_lat = :lat,
        clinic_lng = :lng,
        radius_m = :radius,
        grace_before_min = :grace,
        require_approval = :req,
        gps_required = :gps,
        pay_rate_per_hour = :rate,
        cancel_cutoff_hours = :cutoff
        WHERE id = 1");
    return $stmt->execute([
        ':lat' => $data['clinic_lat'] !== '' && $data['clinic_lat'] !== null ? (float)$data['clinic_lat'] : null,
        ':lng' => $data['clinic_lng'] !== '' && $data['clinic_lng'] !== null ? (float)$data['clinic_lng'] : null,
        ':radius' => max(10, (int)($data['radius_m'] ?? SCHOLARSHIP_DEFAULT_RADIUS_M)),
        ':grace' => max(0, (int)($data['grace_before_min'] ?? SCHOLARSHIP_GRACE_BEFORE_MIN)),
        ':req' => !empty($data['require_approval']) ? 1 : 0,
        ':gps' => isset($data['gps_required']) ? (!empty($data['gps_required']) ? 1 : 0) : 1,
        ':rate' => max(0, (float)($data['pay_rate_per_hour'] ?? 0)),
        ':cutoff' => max(0, (int)($data['cancel_cutoff_hours'] ?? 24)),
    ]);
}

/**
 * ดึงรายการ slot ที่ admin เปิด พร้อมจำนวนคนที่จองแล้ว
 * คืน array รวม "booked_count" และ "available" (max - booked)
 *
 * @param string|null $fromDate กรองตั้งแต่วันที่ (รวม) — null = ไม่กรอง
 * @param string|null $toDate กรองถึงวันที่ (รวม) — null = ไม่กรอง
 * @param array $statuses เฉพาะ status เหล่านี้ default ['open']
 * @param int|null $excludeBookedByStudent ถ้าระบุ student_id จะกรอง slot ที่นักศึกษาคนนี้จองอยู่แล้วออก
 */
function get_open_scholarship_slots(
    PDO $pdo,
    ?string $fromDate = null,
    ?string $toDate = null,
    array $statuses = ['open'],
    ?int $excludeBookedByStudent = null
): array {
    ensure_scholarship_schema($pdo);

    $statusList = array_values(array_filter($statuses, fn($s) => in_array($s, ['open','closed','cancelled'], true)));
    if (empty($statusList)) $statusList = ['open'];
    $placeholders = implode(',', array_fill(0, count($statusList), '?'));

    $sql = "SELECT s.*,
            COALESCE((SELECT COUNT(*) FROM sys_scholarship_slot_bookings b
                      WHERE b.slot_id = s.id AND b.status = 'booked'), 0) AS booked_count
        FROM sys_scholarship_slots s
        WHERE s.status IN ($placeholders)";
    $params = $statusList;

    if ($fromDate) { $sql .= " AND s.slot_date >= ?"; $params[] = $fromDate; }
    if ($toDate)   { $sql .= " AND s.slot_date <= ?"; $params[] = $toDate; }

    if ($excludeBookedByStudent !== null) {
        $sql .= " AND s.id NOT IN (
            SELECT slot_id FROM sys_scholarship_slot_bookings
            WHERE student_id = ? AND status = 'booked'
        )";
        $params[] = $excludeBookedByStudent;
    }

    $sql .= " ORDER BY s.slot_date ASC, s.start_time ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['booked_count'] = (int)$r['booked_count'];
        $r['max_capacity'] = (int)$r['max_capacity'];
        $r['available']    = max(0, $r['max_capacity'] - $r['booked_count']);
    }
    return $rows;
}

/**
 * ดึงรายการ slot ที่นักศึกษาคนนี้จองอยู่ (active = status='booked')
 */
function get_student_slot_bookings(PDO $pdo, int $studentId, bool $upcomingOnly = true): array
{
    ensure_scholarship_schema($pdo);
    $sql = "SELECT b.*, s.slot_date, s.start_time, s.end_time, s.comp_type, s.notes AS slot_notes, s.status AS slot_status
        FROM sys_scholarship_slot_bookings b
        INNER JOIN sys_scholarship_slots s ON s.id = b.slot_id
        WHERE b.student_id = :sid AND b.status = 'booked'";
    $params = [':sid' => $studentId];
    if ($upcomingOnly) {
        $sql .= " AND (s.slot_date > CURDATE()
                   OR (s.slot_date = CURDATE() AND s.end_time >= CURTIME()))";
    }
    $sql .= " ORDER BY s.slot_date ASC, s.start_time ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * ตรวจว่าเลย cutoff เวลายกเลิกหรือยัง
 * คืน [bool $allowed, string $reason]
 */
function can_cancel_scholarship_slot(array $slot, int $cutoffHours): array
{
    $slotStart = strtotime($slot['slot_date'] . ' ' . $slot['start_time']);
    if (!$slotStart) return [false, 'ข้อมูลเวลาผิดพลาด'];
    $deadline = $slotStart - ($cutoffHours * 3600);
    if (time() > $deadline) {
        return [false, "ยกเลิกได้ก่อนรอบเริ่ม {$cutoffHours} ชม. เท่านั้น"];
    }
    return [true, ''];
}

/**
 * คำนวณระยะ Haversine ระหว่างสองจุด (เมตร)
 */
function scholarship_distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthR = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthR * $c;
}

/**
 * ดึง record นักศึกษาทุนจาก user_id (ถ้าไม่ใช่ → null)
 */
function get_scholarship_student_by_user(PDO $pdo, int $userId): ?array
{
    ensure_scholarship_schema($pdo);
    $stmt = $pdo->prepare("SELECT s.*, u.full_name, u.first_name, u.last_name, u.picture_url
        FROM sys_scholarship_students s
        INNER JOIN sys_users u ON u.id = s.user_id
        WHERE s.user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * หา shift ของวัน (current date) สำหรับนักศึกษา
 */
function get_scholarship_shifts_for_date(PDO $pdo, int $studentId, string $date): array
{
    ensure_scholarship_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM sys_scholarship_shifts
        WHERE student_id = :sid AND shift_date = :d AND status != 'cancelled'
        ORDER BY start_time ASC");
    $stmt->execute([':sid' => $studentId, ':d' => $date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * หา clock log ล่าสุดของนักศึกษา (เพื่อรู้ว่ากำลัง clock in อยู่หรือ out แล้ว)
 */
function get_latest_scholarship_log(PDO $pdo, int $studentId): ?array
{
    ensure_scholarship_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM sys_scholarship_clock_logs
        WHERE student_id = :sid
        ORDER BY event_at DESC, id DESC LIMIT 1");
    $stmt->execute([':sid' => $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * รวมชั่วโมง approved ของนักศึกษาในช่วง date range
 * คู่ของ clock_in/clock_out ที่ approved ทั้งคู่จะถูกนับ
 * นโยบาย: ปัด "ลง" เป็นชั่วโมงเต็มต่อ session (1ชม.30นาที = 1ชม., 2ชม.59นาที = 2ชม.)
 * @param string|null $compType 'hours' | 'paid' | null (=ทุกประเภท)
 */
function sum_scholarship_hours(PDO $pdo, int $studentId, ?string $fromDate = null, ?string $toDate = null, ?string $compType = null): float
{
    ensure_scholarship_schema($pdo);
    $sql = "SELECT action, comp_type, event_at FROM sys_scholarship_clock_logs
        WHERE student_id = :sid AND status = 'approved'";
    $params = [':sid' => $studentId];
    if ($fromDate) { $sql .= " AND event_at >= :from"; $params[':from'] = $fromDate . ' 00:00:00'; }
    if ($toDate)   { $sql .= " AND event_at <= :to";   $params[':to']   = $toDate . ' 23:59:59'; }
    $sql .= " ORDER BY event_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalHours = 0;
    $openIn = null;
    $openType = null;
    foreach ($rows as $r) {
        if ($r['action'] === 'clock_in') {
            $openIn = strtotime($r['event_at']);
            $openType = $r['comp_type'] ?? 'hours';
        } elseif ($r['action'] === 'clock_out' && $openIn !== null) {
            if ($compType === null || $compType === $openType) {
                $sec = max(0, strtotime($r['event_at']) - $openIn);
                $totalHours += (int)floor($sec / 3600); // ปัดลงเป็นชั่วโมงเต็มต่อ session
            }
            $openIn = null;
            $openType = null;
        }
    }
    // บวก/ลบ adjustment ที่ admin ปรับด้วยมือ
    $totalHours += sum_scholarship_adjustments($pdo, $studentId, $fromDate, $toDate, $compType);

    return (float)$totalHours;
}

/**
 * รวม manual adjustment ในช่วง date range
 */
function sum_scholarship_adjustments(PDO $pdo, int $studentId, ?string $fromDate = null, ?string $toDate = null, ?string $compType = null): float
{
    ensure_scholarship_schema($pdo);
    $sql = "SELECT COALESCE(SUM(hours_delta), 0) FROM sys_scholarship_manual_adjustments
        WHERE student_id = :sid";
    $params = [':sid' => $studentId];
    if ($fromDate) { $sql .= " AND adjusted_date >= :from"; $params[':from'] = $fromDate; }
    if ($toDate)   { $sql .= " AND adjusted_date <= :to";   $params[':to']   = $toDate; }
    if ($compType) { $sql .= " AND comp_type = :ct";        $params[':ct']   = $compType; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

/**
 * แยกชั่วโมงตามประเภท → ['hours' => X, 'paid' => Y]
 */
function sum_scholarship_hours_split(PDO $pdo, int $studentId, ?string $fromDate = null, ?string $toDate = null): array
{
    return [
        'hours' => sum_scholarship_hours($pdo, $studentId, $fromDate, $toDate, 'hours'),
        'paid'  => sum_scholarship_hours($pdo, $studentId, $fromDate, $toDate, 'paid'),
    ];
}

/**
 * ตรวจว่าเวลา now อยู่ในช่วง shift ไหน (รวม grace before + ไม่จำกัด after)
 * คืน shift array หรือ null
 */
function find_active_scholarship_shift(PDO $pdo, int $studentId, string $now = 'now'): ?array
{
    ensure_scholarship_schema($pdo);
    $settings = get_scholarship_settings($pdo);
    $graceMin = (int)$settings['grace_before_min'];

    $ts = strtotime($now);
    $today = date('Y-m-d', $ts);

    $shifts = get_scholarship_shifts_for_date($pdo, $studentId, $today);
    foreach ($shifts as $s) {
        $shiftStart = strtotime($today . ' ' . $s['start_time']) - ($graceMin * 60);
        // หลังจบกะไม่จำกัด — แต่ถ้ามี shift ถัดไป ให้หยุดที่ก่อน shift ถัดไป
        if ($ts >= $shiftStart) {
            // ตรวจว่ามี shift ถัดไปในวันนี้ไหม
            $nextStart = null;
            foreach ($shifts as $next) {
                if ($next['id'] === $s['id']) continue;
                $nStart = strtotime($today . ' ' . $next['start_time']);
                if ($nStart > strtotime($today . ' ' . $s['start_time'])
                    && ($nextStart === null || $nStart < $nextStart)) {
                    $nextStart = $nStart;
                }
            }
            if ($nextStart === null || $ts < $nextStart - ($graceMin * 60)) {
                return $s;
            }
        }
    }
    return null;
}

function format_scholarship_thai_date(string $date): string
{
    $ts = strtotime($date);
    if (!$ts) return $date;
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
        'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $d = (int)date('j', $ts);
    $m = (int)date('n', $ts);
    $y = (int)date('Y', $ts) + 543;
    return $d . ' ' . $months[$m] . ' ' . $y;
}
