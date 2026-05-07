<?php
// portal/ajax_clinic_master.php — unified AJAX for clinic_data master entities
// Entities: profile (singleton), staff, rooms, hours
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/survey_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}
validate_csrf_or_die();

$pdo = db();

// ── Auto-migrate all clinic master tables ────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_profile (
        id INT PRIMARY KEY DEFAULT 1,
        name_th VARCHAR(255) NOT NULL DEFAULT '',
        name_en VARCHAR(255) NOT NULL DEFAULT '',
        address_th TEXT NULL,
        address_en TEXT NULL,
        phone VARCHAR(50) NOT NULL DEFAULT '',
        email VARCHAR(150) NOT NULL DEFAULT '',
        line_id VARCHAR(100) NOT NULL DEFAULT '',
        facebook VARCHAR(255) NOT NULL DEFAULT '',
        license_no VARCHAR(100) NOT NULL DEFAULT '',
        operating_hours TEXT NULL,
        notes TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_medical_staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(50) NOT NULL DEFAULT '',
        full_name VARCHAR(255) NOT NULL,
        license_no VARCHAR(100) NULL,
        role ENUM('doctor','nurse','pharmacist','dentist','other') NOT NULL DEFAULT 'doctor',
        department VARCHAR(255) NULL,
        phone VARCHAR(50) NULL,
        email VARCHAR(150) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role (role), INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(150) NOT NULL,
        type ENUM('exam','vaccination','lab','consult','other') NOT NULL DEFAULT 'exam',
        capacity INT NOT NULL DEFAULT 1,
        floor VARCHAR(20) NULL,
        notes TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_code (code), INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_clinic_hours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('regular','holiday','special') NOT NULL DEFAULT 'regular',
        weekday TINYINT NULL COMMENT '0=Sun..6=Sat',
        specific_date DATE NULL,
        open_time TIME NULL,
        close_time TIME NULL,
        is_closed TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_type (type), INDEX idx_date (specific_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'message' => 'Migration failed: ' . $e->getMessage()]);
    exit;
}

$entity = $_POST['entity'] ?? '';
$action = $_POST['action'] ?? '';

try {
    switch ("$entity:$action") {
        // ── Clinic Profile ────────────────────────────────────────────────
        case 'profile:save':
            // VALUES(col) so each named placeholder appears only once —
            // PDO with EMULATE_PREPARES=false rejects reused names.
            $stmt = $pdo->prepare("INSERT INTO sys_clinic_profile
                (id, name_th, name_en, address_th, address_en, phone, email, line_id, facebook, license_no, operating_hours, notes)
                VALUES (1, :nt, :ne, :at, :ae, :ph, :em, :lid, :fb, :lic, :oh, :no)
                ON DUPLICATE KEY UPDATE
                    name_th = VALUES(name_th),
                    name_en = VALUES(name_en),
                    address_th = VALUES(address_th),
                    address_en = VALUES(address_en),
                    phone = VALUES(phone),
                    email = VALUES(email),
                    line_id = VALUES(line_id),
                    facebook = VALUES(facebook),
                    license_no = VALUES(license_no),
                    operating_hours = VALUES(operating_hours),
                    notes = VALUES(notes)");
            $stmt->execute([
                ':nt'  => trim((string)($_POST['name_th'] ?? '')),
                ':ne'  => trim((string)($_POST['name_en'] ?? '')),
                ':at'  => trim((string)($_POST['address_th'] ?? '')) ?: null,
                ':ae'  => trim((string)($_POST['address_en'] ?? '')) ?: null,
                ':ph'  => trim((string)($_POST['phone'] ?? '')),
                ':em'  => trim((string)($_POST['email'] ?? '')),
                ':lid' => trim((string)($_POST['line_id'] ?? '')),
                ':fb'  => trim((string)($_POST['facebook'] ?? '')),
                ':lic' => trim((string)($_POST['license_no'] ?? '')),
                ':oh'  => trim((string)($_POST['operating_hours'] ?? '')) ?: null,
                ':no'  => trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'message' => 'บันทึกข้อมูลคลินิกแล้ว']);
            return;

        // ── Medical Staff ────────────────────────────────────────────────
        case 'staff:add':
            $stmt = $pdo->prepare("INSERT INTO sys_medical_staff (title, full_name, license_no, role, department, phone, email)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim((string)($_POST['title'] ?? '')),
                trim((string)($_POST['full_name'] ?? '')),
                trim((string)($_POST['license_no'] ?? '')) ?: null,
                $_POST['role'] ?? 'doctor',
                trim((string)($_POST['department'] ?? '')) ?: null,
                trim((string)($_POST['phone'] ?? '')) ?: null,
                trim((string)($_POST['email'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'เพิ่มบุคลากรแล้ว']);
            return;

        case 'staff:edit':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'message' => 'invalid id']);
                return;
            }
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            if ($fullName === '') {
                echo json_encode(['ok' => false, 'message' => 'กรุณากรอกชื่อ-นามสกุล']);
                return;
            }
            $allowedRoles = ['doctor', 'nurse', 'pharmacist', 'dentist', 'other'];
            $role = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'doctor';

            $stmt = $pdo->prepare("UPDATE sys_medical_staff
                SET title       = :title,
                    full_name   = :full_name,
                    license_no  = :license_no,
                    role        = :role,
                    department  = :department,
                    phone       = :phone,
                    email       = :email
                WHERE id = :id");
            $stmt->execute([
                ':title'      => trim((string)($_POST['title'] ?? '')),
                ':full_name'  => $fullName,
                ':license_no' => trim((string)($_POST['license_no'] ?? '')) ?: null,
                ':role'       => $role,
                ':department' => trim((string)($_POST['department'] ?? '')) ?: null,
                ':phone'      => trim((string)($_POST['phone'] ?? '')) ?: null,
                ':email'      => trim((string)($_POST['email'] ?? '')) ?: null,
                ':id'         => $id,
            ]);
            echo json_encode(['ok' => true, 'message' => 'อัปเดตข้อมูลแล้ว']);
            return;

        case 'staff:delete':
            $pdo->prepare("DELETE FROM sys_medical_staff WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            return;

        case 'staff:toggle':
            $pdo->prepare("UPDATE sys_medical_staff SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true]);
            return;

        // ── Rooms ────────────────────────────────────────────────────────
        case 'rooms:add':
            $stmt = $pdo->prepare("INSERT INTO sys_clinic_rooms (code, name, type, capacity, floor, notes)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim((string)($_POST['code'] ?? '')),
                trim((string)($_POST['name'] ?? '')),
                $_POST['type'] ?? 'exam',
                max(1, (int)($_POST['capacity'] ?? 1)),
                trim((string)($_POST['floor'] ?? '')) ?: null,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'message' => 'เพิ่มห้องแล้ว']);
            return;

        case 'rooms:delete':
            $pdo->prepare("DELETE FROM sys_clinic_rooms WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            return;

        case 'rooms:toggle':
            $pdo->prepare("UPDATE sys_clinic_rooms SET is_active = 1 - is_active WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true]);
            return;

        // ── Hours / Holidays ─────────────────────────────────────────────
        case 'hours:add':
            $type = $_POST['type'] ?? 'regular';
            $stmt = $pdo->prepare("INSERT INTO sys_clinic_hours (type, weekday, specific_date, open_time, close_time, is_closed, note)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $type,
                $type === 'regular' ? (int)($_POST['weekday'] ?? 0) : null,
                $type !== 'regular' ? ($_POST['specific_date'] ?? null) : null,
                ($_POST['is_closed'] ?? '0') === '1' ? null : ($_POST['open_time'] ?? null),
                ($_POST['is_closed'] ?? '0') === '1' ? null : ($_POST['close_time'] ?? null),
                ($_POST['is_closed'] ?? '0') === '1' ? 1 : 0,
                trim((string)($_POST['note'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'message' => 'เพิ่มแล้ว']);
            return;

        case 'hours:add_bulk': {
            $dates = array_values(array_filter(array_map('trim', (array)($_POST['dates'] ?? []))));
            $note  = trim((string)($_POST['note'] ?? '')) ?: null;
            if (empty($dates)) {
                echo json_encode(['ok' => false, 'message' => 'ไม่มีวันที่ที่เลือก']);
                return;
            }
            $check  = $pdo->prepare("SELECT 1 FROM sys_clinic_hours WHERE type IN ('holiday','special') AND specific_date = :d LIMIT 1");
            $insert = $pdo->prepare("INSERT INTO sys_clinic_hours (type, specific_date, is_closed, note) VALUES ('holiday', :d, 1, :n)");
            $added = 0; $skipped = 0;
            foreach ($dates as $date) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
                $check->execute([':d' => $date]);
                if ($check->fetch()) { $skipped++; continue; }
                $insert->execute([':d' => $date, ':n' => $note]);
                $added++;
            }
            $msg = "เพิ่ม {$added} วัน" . ($skipped ? " (ข้าม {$skipped} วันที่มีอยู่แล้ว)" : '');
            echo json_encode(['ok' => true, 'message' => $msg, 'added' => $added]);
            return;
        }

        case 'hours:delete':
            $pdo->prepare("DELETE FROM sys_clinic_hours WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            return;

        case 'hours:edit': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'message' => 'invalid id']);
                return;
            }
            $cur = $pdo->prepare("SELECT type FROM sys_clinic_hours WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['ok' => false, 'message' => 'not found']);
                return;
            }
            $type     = $row['type'];
            $isClosed = (($_POST['is_closed'] ?? '0') === '1') ? 1 : 0;

            $stmt = $pdo->prepare("UPDATE sys_clinic_hours SET
                weekday       = :weekday,
                specific_date = :date,
                open_time     = :open_time,
                close_time    = :close_time,
                is_closed     = :is_closed,
                note          = :note
                WHERE id = :id");
            $stmt->execute([
                ':weekday'    => $type === 'regular' ? (int)($_POST['weekday'] ?? 0) : null,
                ':date'       => $type !== 'regular' ? ($_POST['specific_date'] ?? null) : null,
                ':open_time'  => $isClosed ? null : (trim((string)($_POST['open_time'] ?? '')) ?: null),
                ':close_time' => $isClosed ? null : (trim((string)($_POST['close_time'] ?? '')) ?: null),
                ':is_closed'  => $isClosed,
                ':note'       => trim((string)($_POST['note'] ?? '')) ?: null,
                ':id'         => $id,
            ]);
            echo json_encode(['ok' => true, 'message' => 'อัปเดตแล้ว']);
            return;
        }

        // ── Thai Public Holidays — ดึงจาก myhora.com (ICS format / RFC 5545)
        // วันหยุดราชการครบ + วันหยุดชดเชย, อัปเดตตามประกาศจริง
        case 'hours:fetch_thai_holidays': {
            $year = (int)($_POST['year'] ?? date('Y'));
            if ($year < 2020 || $year > 2050) {
                echo json_encode(['ok' => false, 'message' => 'ปีต้องอยู่ระหว่าง 2020–2050']);
                return;
            }

            $url = 'https://myhora.com/calendar/ical/holiday.aspx?latest.ics';
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; RSU-Clinic/1.0)',
                CURLOPT_HTTPHEADER     => ['Accept: text/calendar, text/plain, */*'],
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($resp === false || $code !== 200) {
                echo json_encode(['ok' => false, 'message' => "เชื่อมต่อ myhora.com ไม่ได้ (HTTP $code) $err"]);
                return;
            }

            // Strip BOM + ensure UTF-8
            $raw = ltrim($resp, "\xEF\xBB\xBF");
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $converted = @mb_convert_encoding($raw, 'UTF-8', 'TIS-620,Windows-874,UTF-8');
                if ($converted !== false) $raw = $converted;
            }

            // Quick sanity check — should contain BEGIN:VCALENDAR
            if (stripos($raw, 'BEGIN:VCALENDAR') === false) {
                $head = mb_substr(trim($raw), 0, 200);
                echo json_encode(['ok' => false, 'message' => "ไม่ใช่ไฟล์ ICS ที่ถูกต้อง — head: $head"]);
                return;
            }

            // ── ICS Parser ──────────────────────────────────────────────
            // 1) Split into lines (handle CRLF, CR, LF)
            $lines = preg_split('/\r\n|\r|\n/', $raw);

            // 2) Unfold: RFC 5545 — lines starting with space/tab continue previous
            $unfolded = [];
            foreach ($lines as $line) {
                if ($line === '') continue;
                if ($line[0] === ' ' || $line[0] === "\t") {
                    if (!empty($unfolded)) $unfolded[count($unfolded) - 1] .= substr($line, 1);
                } else {
                    $unfolded[] = $line;
                }
            }

            // 3) Walk events, extract DTSTART + SUMMARY
            $unescape = fn(string $s) => str_replace(
                ['\\\\', '\\,', '\\;', '\\n', '\\N'],
                ['\\', ',', ';', "\n", "\n"],
                $s
            );

            $events  = [];
            $inEvent = false;
            $cur     = [];
            foreach ($unfolded as $line) {
                if ($line === 'BEGIN:VEVENT') { $inEvent = true; $cur = []; continue; }
                if ($line === 'END:VEVENT') {
                    if (!empty($cur['date']) && !empty($cur['summary'])) $events[] = $cur;
                    $inEvent = false; continue;
                }
                if (!$inEvent) continue;

                // DTSTART (with optional params: DTSTART;VALUE=DATE:20250101)
                if (preg_match('/^DTSTART(?:;[^:]*)?:(\d{4})-?(\d{2})-?(\d{2})/i', $line, $m)) {
                    $cur['date'] = "$m[1]-$m[2]-$m[3]";
                    continue;
                }
                // SUMMARY (with optional params: SUMMARY;LANGUAGE=th:...)
                if (preg_match('/^SUMMARY(?:;[^:]*)?:(.*)$/i', $line, $m)) {
                    $cur['summary'] = trim($unescape($m[1]));
                    continue;
                }
            }

            // Filter by year + dedupe by date
            $filtered = [];
            foreach ($events as $e) {
                if (substr($e['date'], 0, 4) !== (string)$year) continue;
                $filtered[$e['date']] = $e;
            }
            ksort($filtered);
            $filtered = array_values($filtered);

            if (empty($filtered)) {
                echo json_encode(['ok' => false, 'message' => "ไม่พบวันหยุดสำหรับปี $year — ลองปีอื่นดู"]);
                return;
            }

            // เช็ควันที่มีในระบบแล้ว
            $existing = [];
            $st = $pdo->prepare("SELECT specific_date FROM sys_clinic_hours
                WHERE type IN ('holiday','special') AND specific_date BETWEEN :s AND :e");
            $st->execute([':s' => "$year-01-01", ':e' => "$year-12-31"]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) $existing[$d] = true;

            $rows = [];
            foreach ($filtered as $e) {
                $rows[] = [
                    'date'    => $e['date'],
                    'name_th' => $e['summary'],
                    'name_en' => '',
                    'exists'  => isset($existing[$e['date']]),
                ];
            }
            echo json_encode(['ok' => true, 'rows' => $rows, 'year' => $year, 'source' => 'myhora.com (ICS)']);
            return;
        }

        case 'hours:import_thai_holidays': {
            $dates = $_POST['dates'] ?? [];
            $names = $_POST['names'] ?? [];
            if (!is_array($dates) || !is_array($names) || count($dates) !== count($names)) {
                echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
                return;
            }

            $check = $pdo->prepare("SELECT 1 FROM sys_clinic_hours
                WHERE type IN ('holiday','special') AND specific_date = :d LIMIT 1");
            $insert = $pdo->prepare("INSERT INTO sys_clinic_hours (type, specific_date, is_closed, note)
                VALUES ('holiday', :d, 1, :n)");

            $imported = 0; $skipped = 0;
            foreach ($dates as $i => $d) {
                $name = trim((string)($names[$i] ?? ''));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) || $name === '') {
                    $skipped++; continue;
                }
                $check->execute([':d' => $d]);
                if ($check->fetchColumn()) { $skipped++; continue; }
                try {
                    $insert->execute([':d' => $d, ':n' => $name]);
                    $imported++;
                } catch (PDOException) {
                    $skipped++;
                }
            }
            echo json_encode([
                'ok'       => true,
                'imported' => $imported,
                'skipped'  => $skipped,
                'message'  => "นำเข้า $imported รายการ" . ($skipped ? " (ข้าม $skipped รายการที่มีอยู่แล้ว)" : ''),
            ]);
            return;
        }

        // ── Doctor Schedule ──────────────────────────────────────────────
        case 'schedule:list': {
            // Auto-migrate
            try {
                // Fix rows corrupted by the old update bug: regular shifts with specific_date='' or '0000-00-00'
                $pdo->exec("UPDATE sys_doctor_schedule SET specific_date = NULL
                    WHERE type = 'regular' AND (specific_date = '' OR specific_date = '0000-00-00')");
            } catch (PDOException) {}
            try {
                $pdo->exec("ALTER TABLE sys_doctor_schedule ADD COLUMN recur_end_date DATE NULL DEFAULT NULL AFTER weekday");
            } catch (PDOException) {}  // column already exists → ignore
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS sys_doctor_schedule (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    staff_id INT NOT NULL,
                    type ENUM('regular','override','off') NOT NULL DEFAULT 'regular',
                    weekday TINYINT NULL,
                    specific_date DATE NULL,
                    start_time TIME NULL,
                    end_time TIME NULL,
                    room_id INT NULL,
                    service_type VARCHAR(100) NULL,
                    notes VARCHAR(255) NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_staff (staff_id),
                    INDEX idx_weekday (weekday),
                    INDEX idx_date (specific_date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (PDOException) {}

            $start = $_POST['start'] ?? date('Y-m-01');
            $end   = $_POST['end']   ?? date('Y-m-t');

            $stmt = $pdo->prepare("
                SELECT s.*, ms.title AS doc_title, ms.full_name AS doc_name,
                       cr.name AS room_name, cr.code AS room_code
                FROM sys_doctor_schedule s
                LEFT JOIN sys_medical_staff ms ON s.staff_id = ms.id
                LEFT JOIN sys_clinic_rooms  cr ON s.room_id  = cr.id
                WHERE s.is_active = 1
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Pull closed-day holidays so the UI can display them and block scheduling
            $holidays = [];
            try {
                $hStmt = $pdo->query("SELECT specific_date, note, is_closed
                    FROM sys_clinic_hours
                    WHERE type IN ('holiday','special') AND is_closed = 1
                    ORDER BY specific_date");
                $holidays = $hStmt ? $hStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (PDOException) {}

            echo json_encode(['ok' => true, 'rows' => $rows, 'holidays' => $holidays]);
            return;
        }

        case 'schedule:add': {
            $type = $_POST['type'] ?? 'regular';
            // Block override shifts on closed-day holidays
            if ($type === 'override') {
                $sd = trim((string)($_POST['specific_date'] ?? ''));
                if ($sd !== '') {
                    $hCheck = $pdo->prepare("SELECT note FROM sys_clinic_hours
                        WHERE type IN ('holiday','special') AND is_closed = 1 AND specific_date = :d LIMIT 1");
                    $hCheck->execute([':d' => $sd]);
                    if ($holRow = $hCheck->fetch(PDO::FETCH_ASSOC)) {
                        echo json_encode([
                            'ok' => false,
                            'message' => 'ไม่สามารถลงตารางแพทย์ในวันหยุด: ' . ($holRow['note'] ?: 'วันหยุด'),
                        ]);
                        return;
                    }
                }
            }
            $recurEnd = ($type === 'regular' && !empty($_POST['recur_end_date']))
                ? trim((string)$_POST['recur_end_date']) : null;
            $stmt = $pdo->prepare("INSERT INTO sys_doctor_schedule
                (staff_id, type, weekday, recur_end_date, specific_date, start_time, end_time, room_id, service_type, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                (int)$_POST['staff_id'],
                $type,
                $type === 'regular' ? (int)$_POST['weekday'] : null,
                $recurEnd,
                $type !== 'regular' ? ($_POST['specific_date'] ?? null) : null,
                $type === 'off' ? null : ($_POST['start_time'] ?? null),
                $type === 'off' ? null : ($_POST['end_time']   ?? null),
                !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null,
                trim((string)($_POST['service_type'] ?? '')) ?: null,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'เพิ่ม shift แล้ว']);
            return;
        }

        case 'schedule:update': {
            // Used by drag-drop/resize AND modal edit.
            // When 'type' is present → full modal save; recompute weekday/specific_date from type.
            // When 'type' is absent  → partial drag-drop update; preserve existing type fields via COALESCE.
            $id   = (int)$_POST['id'];
            $type = isset($_POST['type']) && $_POST['type'] !== '' ? $_POST['type'] : null;

            // Block override shifts on closed-day holidays (covers both modal save and drag-drop)
            $effectiveDate = null;
            if ($type === 'override') {
                $effectiveDate = trim((string)($_POST['specific_date'] ?? '')) ?: null;
            } elseif ($type === null && !empty($_POST['specific_date'])) {
                // Partial drag-drop — only block if the existing row is an override
                $row = $pdo->prepare("SELECT type FROM sys_doctor_schedule WHERE id = :id");
                $row->execute([':id' => $id]);
                if ($row->fetchColumn() === 'override') {
                    $effectiveDate = $_POST['specific_date'];
                }
            }
            if ($effectiveDate) {
                $hCheck = $pdo->prepare("SELECT note FROM sys_clinic_hours
                    WHERE type IN ('holiday','special') AND is_closed = 1 AND specific_date = :d LIMIT 1");
                $hCheck->execute([':d' => $effectiveDate]);
                if ($holRow = $hCheck->fetch(PDO::FETCH_ASSOC)) {
                    echo json_encode([
                        'ok' => false,
                        'message' => 'ไม่สามารถลงตารางแพทย์ในวันหยุด: ' . ($holRow['note'] ?: 'วันหยุด'),
                    ]);
                    return;
                }
            }

            if ($type !== null) {
                // Full modal edit — derive nullable fields strictly from type to avoid '' overwriting NULL
                $weekday      = ($type === 'regular') ? (int)$_POST['weekday'] : null;
                $recurEnd     = ($type === 'regular' && !empty($_POST['recur_end_date']))
                    ? trim((string)$_POST['recur_end_date']) : null;
                $specificDate = ($type !== 'regular')
                    ? (trim((string)($_POST['specific_date'] ?? '')) ?: null)
                    : null;
                $startTime = ($type === 'off') ? null : ($_POST['start_time'] ?? null);
                $endTime   = ($type === 'off') ? null : ($_POST['end_time']   ?? null);
                $staffId   = !empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null;

                $stmt = $pdo->prepare("UPDATE sys_doctor_schedule SET
                    type           = :type,
                    weekday        = :weekday,
                    recur_end_date = :recur_end,
                    specific_date  = :date,
                    start_time     = :st,
                    end_time       = :et,
                    staff_id       = COALESCE(:staff, staff_id),
                    room_id        = :room,
                    service_type   = :svc,
                    notes          = :notes
                    WHERE id = :id");
                $stmt->execute([
                    ':type'      => $type,
                    ':weekday'   => $weekday,
                    ':recur_end' => $recurEnd,
                    ':date'      => $specificDate,
                    ':st'        => $startTime,
                    ':et'        => $endTime,
                    ':staff'     => $staffId,
                    ':room'      => !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null,
                    ':svc'       => trim((string)($_POST['service_type'] ?? '')) ?: null,
                    ':notes'     => trim((string)($_POST['notes'] ?? '')) ?: null,
                    ':id'        => $id,
                ]);
            } else {
                // Partial update from drag-drop / resize — preserve type-related fields with COALESCE
                $stmt = $pdo->prepare("UPDATE sys_doctor_schedule SET
                    weekday       = COALESCE(:weekday, weekday),
                    specific_date = COALESCE(:date, specific_date),
                    start_time    = :st,
                    end_time      = :et,
                    staff_id      = COALESCE(:staff, staff_id),
                    room_id       = :room,
                    service_type  = :svc,
                    notes         = :notes
                    WHERE id = :id");
                $stmt->execute([
                    ':weekday' => isset($_POST['weekday']) && $_POST['weekday'] !== '' ? (int)$_POST['weekday'] : null,
                    ':date'    => !empty($_POST['specific_date']) ? $_POST['specific_date'] : null,
                    ':st'      => $_POST['start_time'] ?? null,
                    ':et'      => $_POST['end_time']   ?? null,
                    ':staff'   => isset($_POST['staff_id']) && (int)$_POST['staff_id'] > 0 ? (int)$_POST['staff_id'] : null,
                    ':room'    => !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null,
                    ':svc'     => trim((string)($_POST['service_type'] ?? '')) ?: null,
                    ':notes'   => trim((string)($_POST['notes'] ?? '')) ?: null,
                    ':id'      => $id,
                ]);
            }
            echo json_encode(['ok' => true, 'message' => 'อัปเดตแล้ว']);
            return;
        }

        case 'schedule:delete':
            $pdo->prepare("DELETE FROM sys_doctor_schedule WHERE id = ?")->execute([(int)$_POST['id']]);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            return;

        // ── Survey Questions (configurable post-checkin survey) ────────────
        case 'survey_q:list': {
            ensure_survey_schema($pdo);
            $type = trim((string)($_POST['survey_type'] ?? 'post_checkin'));
            $stmt = $pdo->prepare("SELECT * FROM sys_survey_questions
                WHERE survey_type = :t ORDER BY sort_order ASC, id ASC");
            $stmt->execute([':t' => $type]);
            echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            return;
        }

        case 'survey_q:add': {
            ensure_survey_schema($pdo);
            $type = trim((string)($_POST['survey_type'] ?? 'post_checkin'));
            $text = trim((string)($_POST['question_text'] ?? ''));
            $atype = $_POST['answer_type'] ?? 'rating';
            if ($text === '') { echo json_encode(['ok'=>false,'message'=>'กรุณาใส่ข้อความคำถาม']); return; }
            if (!in_array($atype, ['rating','text','single_choice'], true)) $atype = 'rating';

            $optsJson = null;
            if ($atype === 'single_choice') {
                $opts = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['options'] ?? '')))));
                if (count($opts) < 2) { echo json_encode(['ok'=>false,'message'=>'choice ต้องมีอย่างน้อย 2 ตัวเลือก (บรรทัดละ 1)']); return; }
                $optsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
            }

            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM sys_survey_questions")->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO sys_survey_questions
                (survey_type, question_text, answer_type, options_json, is_required, sort_order, is_active)
                VALUES (:t, :q, :at, :o, :r, :so, 1)");
            $stmt->execute([
                ':t'  => $type,
                ':q'  => mb_substr($text, 0, 255),
                ':at' => $atype,
                ':o'  => $optsJson,
                ':r'  => !empty($_POST['is_required']) ? 1 : 0,
                ':so' => $maxOrder + 1,
            ]);
            echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'message'=>'เพิ่มคำถามแล้ว']);
            return;
        }

        case 'survey_q:update': {
            ensure_survey_schema($pdo);
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok'=>false,'message'=>'invalid id']); return; }
            $text = trim((string)($_POST['question_text'] ?? ''));
            $atype = $_POST['answer_type'] ?? 'rating';
            if ($text === '') { echo json_encode(['ok'=>false,'message'=>'กรุณาใส่ข้อความคำถาม']); return; }
            if (!in_array($atype, ['rating','text','single_choice'], true)) $atype = 'rating';

            $optsJson = null;
            if ($atype === 'single_choice') {
                $opts = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['options'] ?? '')))));
                if (count($opts) < 2) { echo json_encode(['ok'=>false,'message'=>'choice ต้องมีอย่างน้อย 2 ตัวเลือก']); return; }
                $optsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
            }

            $stmt = $pdo->prepare("UPDATE sys_survey_questions SET
                question_text = :q, answer_type = :at, options_json = :o, is_required = :r
                WHERE id = :id");
            $stmt->execute([
                ':q'  => mb_substr($text, 0, 255),
                ':at' => $atype,
                ':o'  => $optsJson,
                ':r'  => !empty($_POST['is_required']) ? 1 : 0,
                ':id' => $id,
            ]);
            echo json_encode(['ok'=>true, 'message'=>'อัปเดตแล้ว']);
            return;
        }

        case 'survey_q:toggle': {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE sys_survey_questions SET is_active = 1 - is_active WHERE id = :id")
                ->execute([':id' => $id]);
            echo json_encode(['ok'=>true, 'message'=>'สลับสถานะแล้ว']);
            return;
        }

        case 'survey_q:delete': {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM sys_survey_questions WHERE id = :id")->execute([':id' => $id]);
            echo json_encode(['ok'=>true, 'message'=>'ลบแล้ว']);
            return;
        }

        case 'survey_q:reorder': {
            $ids = $_POST['ids'] ?? '';
            $idList = array_values(array_filter(array_map('intval', explode(',', (string)$ids))));
            if (empty($idList)) { echo json_encode(['ok'=>false,'message'=>'no ids']); return; }
            $stmt = $pdo->prepare("UPDATE sys_survey_questions SET sort_order = :so WHERE id = :id");
            foreach ($idList as $i => $id) {
                $stmt->execute([':so' => $i + 1, ':id' => $id]);
            }
            echo json_encode(['ok'=>true, 'message'=>'จัดลำดับใหม่แล้ว']);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'message' => "Unknown action: $entity:$action"]);
            return;
    }
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
