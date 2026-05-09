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
            status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
            created_by INT UNSIGNED NULL,
            notes VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_student_date (student_id, shift_date),
            KEY idx_date (shift_date),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_clock_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            student_id INT UNSIGNED NOT NULL,
            shift_id INT UNSIGNED NULL,
            action ENUM('clock_in','clock_out') NOT NULL,
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_scholarship_settings (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            clinic_lat DECIMAL(10,7) NULL,
            clinic_lng DECIMAL(10,7) NULL,
            radius_m INT UNSIGNED NOT NULL DEFAULT " . SCHOLARSHIP_DEFAULT_RADIUS_M . ",
            grace_before_min INT UNSIGNED NOT NULL DEFAULT " . SCHOLARSHIP_GRACE_BEFORE_MIN . ",
            require_approval TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seed singleton row
        $pdo->exec("INSERT IGNORE INTO sys_scholarship_settings (id) VALUES (1)");
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
        ];
    }
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
        require_approval = :req
        WHERE id = 1");
    return $stmt->execute([
        ':lat' => $data['clinic_lat'] !== '' && $data['clinic_lat'] !== null ? (float)$data['clinic_lat'] : null,
        ':lng' => $data['clinic_lng'] !== '' && $data['clinic_lng'] !== null ? (float)$data['clinic_lng'] : null,
        ':radius' => max(10, (int)($data['radius_m'] ?? SCHOLARSHIP_DEFAULT_RADIUS_M)),
        ':grace' => max(0, (int)($data['grace_before_min'] ?? SCHOLARSHIP_GRACE_BEFORE_MIN)),
        ':req' => !empty($data['require_approval']) ? 1 : 0,
    ]);
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
 */
function sum_scholarship_hours(PDO $pdo, int $studentId, ?string $fromDate = null, ?string $toDate = null): float
{
    ensure_scholarship_schema($pdo);
    $sql = "SELECT action, event_at FROM sys_scholarship_clock_logs
        WHERE student_id = :sid AND status = 'approved'";
    $params = [':sid' => $studentId];
    if ($fromDate) { $sql .= " AND event_at >= :from"; $params[':from'] = $fromDate . ' 00:00:00'; }
    if ($toDate)   { $sql .= " AND event_at <= :to";   $params[':to']   = $toDate . ' 23:59:59'; }
    $sql .= " ORDER BY event_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalSec = 0;
    $openIn = null;
    foreach ($rows as $r) {
        if ($r['action'] === 'clock_in') {
            $openIn = strtotime($r['event_at']);
        } elseif ($r['action'] === 'clock_out' && $openIn !== null) {
            $totalSec += max(0, strtotime($r['event_at']) - $openIn);
            $openIn = null;
        }
    }
    return round($totalSec / 3600, 2);
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
