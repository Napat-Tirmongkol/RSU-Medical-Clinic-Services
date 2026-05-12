<?php
// portal/ajax_nurse_schedule.php — Phase 2 backend สำหรับระบบจัดตารางเวรพยาบาล
// load + save (multi-user แชร์ข้อมูลร่วมกัน)
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/clinic_status_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo = db();

// Self-heal schema (เผื่อยังไม่ได้รัน migration)
function ensure_nurse_schedule_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_nurse_schedule_global (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            nurses_json LONGTEXT NULL,
            requirements_json LONGTEXT NULL,
            ot_settings_json LONGTEXT NULL,
            custom_holidays_json LONGTEXT NULL,
            removed_holidays_json LONGTEXT NULL,
            shift_types_json LONGTEXT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Self-heal: เพิ่ม column ถ้าโครงสร้างเดิมยังไม่มี
        try { $pdo->exec("ALTER TABLE sys_nurse_schedule_global ADD COLUMN shift_types_json LONGTEXT NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_nurse_schedule_global ADD COLUMN custom_positions_json LONGTEXT NULL"); } catch (PDOException) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_nurse_schedule_monthly (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            year_be SMALLINT UNSIGNED NOT NULL,
            month TINYINT UNSIGNED NOT NULL,
            schedule_json LONGTEXT NULL,
            leaves_json LONGTEXT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ym (year_be, month),
            KEY idx_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("INSERT IGNORE INTO sys_nurse_schedule_global (id) VALUES (1)");
    } catch (PDOException $e) {
        error_log('[nurse_schedule] schema: ' . $e->getMessage());
    }
    $done = true;
}
ensure_nurse_schedule_schema($pdo);

$action = $_REQUEST['action'] ?? '';
$adminId = (int)($_SESSION['admin_id'] ?? 0);

try {
    if ($action === 'load') {
        $year  = (int)($_GET['year']  ?? 0);
        $month = (int)($_GET['month'] ?? 0);
        if ($year < 2500 || $year > 2700 || $month < 1 || $month > 12) {
            echo json_encode(['ok' => false, 'error' => 'Invalid year/month']);
            exit;
        }

        $g = $pdo->query("SELECT * FROM sys_nurse_schedule_global WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT schedule_json, leaves_json, updated_at, updated_by
            FROM sys_nurse_schedule_monthly WHERE year_be = :y AND month = :m");
        $stmt->execute([':y' => $year, ':m' => $month]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $decode = fn($s, $default) => is_string($s) && $s !== '' ? (json_decode($s, true) ?? $default) : $default;
        // สำหรับ map/object key-value (เช่น schedule, leaves) — empty array จะถูก JSON.encode เป็น "[]"
        // ทำให้ JS รับเป็น Array แทน Object → setShift() จะเพิ่ม string property บน Array
        // ซึ่ง JSON.stringify ไม่ serialize ออกมา → save ส่ง "[]" กลับไป → ข้อมูลหายตอน reload
        // วิธีแก้: empty → คืน stdClass() เสมอ → encode เป็น "{}"
        $decodeObj = function($s) {
            if (!is_string($s) || $s === '') return new stdClass();
            $d = json_decode($s, true);
            if (!is_array($d) || empty($d)) return new stdClass();
            return $d;
        };

        // ดึงวันที่คลินิกปิด (จาก sys_clinic_hours) สำหรับเดือนนี้
        // คีย์รูปแบบ "YBE-M-D" (Buddhist year)
        $clinicHolidays = new stdClass();
        try {
            $yCE = $year - 543;
            $daysInMonth = (int)(new DateTimeImmutable("$yCE-$month-01"))->format('t');
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $ceDate = sprintf('%04d-%02d-%02d', $yCE, $month, $d);
                $hours = get_clinic_hours_for_date($pdo, $ceDate);
                if (!empty($hours['closed']) && (($hours['source'] ?? '') !== 'no_regular')) {
                    $note = trim((string)($hours['note'] ?? ''));
                    if ($note === '') $note = 'คลินิกปิด';
                    $clinicHolidays->{"$year-$month-$d"} = $note;
                }
            }
        } catch (Throwable $e) { /* clinic_hours อาจยังไม่มี */ }

        echo json_encode([
            'ok' => true,
            'data' => [
                'nurses'          => $decode($g['nurses_json'] ?? null, null),
                'requirements'    => $decode($g['requirements_json'] ?? null, null),
                'otSettings'      => $decode($g['ot_settings_json'] ?? null, null),
                'customHolidays'  => $decodeObj($g['custom_holidays_json'] ?? null),
                'removedHolidays' => $decodeObj($g['removed_holidays_json'] ?? null),
                'shiftTypes'      => $decodeObj($g['shift_types_json'] ?? null),
                'customPositions' => $decodeObj($g['custom_positions_json'] ?? null),
                'schedule'        => $decodeObj($m['schedule_json'] ?? null),
                'leaves'          => $decodeObj($m['leaves_json'] ?? null),
                'clinicHolidays'  => $clinicHolidays,
            ],
            'global_updated_at'  => $g['updated_at'] ?? null,
            'monthly_updated_at' => $m['updated_at'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST only']); exit;
        }
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']); exit;
        }

        $year  = (int)($_POST['year']  ?? 0);
        $month = (int)($_POST['month'] ?? 0);
        $payload = json_decode((string)($_POST['payload'] ?? ''), true);
        if (!is_array($payload) || $year < 2500 || $year > 2700 || $month < 1 || $month > 12) {
            echo json_encode(['ok' => false, 'error' => 'Invalid payload']); exit;
        }

        $enc = fn($v) => json_encode($v ?? new stdClass(), JSON_UNESCAPED_UNICODE);

        $pdo->beginTransaction();
        try {
            // Upsert global
            $pdo->prepare("INSERT INTO sys_nurse_schedule_global
                (id, nurses_json, requirements_json, ot_settings_json,
                 custom_holidays_json, removed_holidays_json, shift_types_json,
                 custom_positions_json, updated_by)
                VALUES (1, :n, :r, :o, :c, :rm, :st, :cp, :by)
                ON DUPLICATE KEY UPDATE
                nurses_json = VALUES(nurses_json),
                requirements_json = VALUES(requirements_json),
                ot_settings_json = VALUES(ot_settings_json),
                custom_holidays_json = VALUES(custom_holidays_json),
                removed_holidays_json = VALUES(removed_holidays_json),
                shift_types_json = VALUES(shift_types_json),
                custom_positions_json = VALUES(custom_positions_json),
                updated_by = VALUES(updated_by)")
                ->execute([
                    ':n'  => $enc($payload['nurses']          ?? []),
                    ':r'  => $enc($payload['requirements']    ?? null),
                    ':o'  => $enc($payload['otSettings']      ?? null),
                    ':c'  => $enc($payload['customHolidays']  ?? null),
                    ':rm' => $enc($payload['removedHolidays'] ?? null),
                    ':cp' => $enc($payload['customPositions'] ?? null),
                    ':st' => $enc($payload['shiftTypes']      ?? null),
                    ':by' => $adminId ?: null,
                ]);

            // Upsert monthly
            $pdo->prepare("INSERT INTO sys_nurse_schedule_monthly
                (year_be, month, schedule_json, leaves_json, updated_by)
                VALUES (:y, :m, :s, :l, :by)
                ON DUPLICATE KEY UPDATE
                schedule_json = VALUES(schedule_json),
                leaves_json   = VALUES(leaves_json),
                updated_by    = VALUES(updated_by)")
                ->execute([
                    ':y'  => $year, ':m' => $month,
                    ':s'  => $enc($payload['schedule'] ?? null),
                    ':l'  => $enc($payload['leaves']   ?? null),
                    ':by' => $adminId ?: null,
                ]);

            $pdo->commit();
            echo json_encode(['ok' => true, 'saved_at' => date('c')]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[nurse_schedule save] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('[nurse_schedule] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
