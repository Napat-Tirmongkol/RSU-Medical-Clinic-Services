<?php
/**
 * includes/clinic_status_helper.php
 *
 * ช่วยตอบคำถามพื้นฐานในไลน์ที่เกี่ยวกับสถานะคลินิก
 *  - "วันนี้คลินิกเปิดไหม"
 *  - "เปิดกี่โมง" / "ปิดกี่โมง"
 *  - "พรุ่งนี้คลินิกเปิดไหม"
 *  - "วันนี้มีหมอออกตรวจไหม" / "ตารางแพทย์วันนี้"
 *
 * ดึงข้อมูลจาก:
 *  - sys_clinic_hours      (เวลาเปิด-ปิดประจำสัปดาห์ + วันหยุดพิเศษ)
 *  - sys_doctor_schedule   (ตารางแพทย์ออกตรวจ)
 *  - sys_medical_staff     (ข้อมูลแพทย์/บุคลากร)
 *  - sys_clinic_rooms      (ห้องตรวจ)
 *  - sys_clinic_profile    (ข้อมูลคลินิก เช่น เบอร์โทร)
 */

declare(strict_types=1);

const CLINIC_TZ_NAME = 'Asia/Bangkok';

const CLINIC_WEEKDAY_TH_FULL = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
const CLINIC_MONTH_TH_FULL   = ['','มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

const CLINIC_FAQ_DEFAULTS = [
    'enabled'                => 1,
    'only_when_closed'       => 0,
    'rate_limit_hours'       => 24,
    'msg_open_now_title'     => 'เปิดอยู่ในขณะนี้',
    'msg_open_now_sub'       => 'ปิด {close_time} น. (อีก {time_left})',
    'msg_before_open_title'  => 'ยังไม่เปิดให้บริการ',
    'msg_before_open_sub'    => 'จะเปิด {open_time} น. (อีก {time_left})',
    'msg_after_close_title'  => 'ขณะนี้นอกเวลาทำการ',
    'msg_after_close_sub'    => 'จะเปิด{next_label} เวลา {next_time} น.',
    'msg_closed_today_title' => 'วันนี้คลินิกหยุด',
    'msg_closed_today_sub'   => 'จะเปิด{next_label} เวลา {next_time} น.',
];

/**
 * สร้างตาราง settings + log ของ FAQ ถ้ายังไม่มี (idempotent)
 */
function ensure_clinic_faq_tables(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_line_faq_settings (
            id INT PRIMARY KEY DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            only_when_closed TINYINT(1) NOT NULL DEFAULT 0,
            rate_limit_hours INT NOT NULL DEFAULT 24,
            msg_open_now_title     VARCHAR(160) NOT NULL DEFAULT 'เปิดอยู่ในขณะนี้',
            msg_open_now_sub       VARCHAR(255) NOT NULL DEFAULT 'ปิด {close_time} น. (อีก {time_left})',
            msg_before_open_title  VARCHAR(160) NOT NULL DEFAULT 'ยังไม่เปิดให้บริการ',
            msg_before_open_sub    VARCHAR(255) NOT NULL DEFAULT 'จะเปิด {open_time} น. (อีก {time_left})',
            msg_after_close_title  VARCHAR(160) NOT NULL DEFAULT 'ขณะนี้นอกเวลาทำการ',
            msg_after_close_sub    VARCHAR(255) NOT NULL DEFAULT 'จะเปิด{next_label} เวลา {next_time} น.',
            msg_closed_today_title VARCHAR(160) NOT NULL DEFAULT 'วันนี้คลินิกหยุด',
            msg_closed_today_sub   VARCHAR(255) NOT NULL DEFAULT 'จะเปิด{next_label} เวลา {next_time} น.',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Migration for existing tables that predate this column
        try {
            $pdo->exec("ALTER TABLE sys_line_faq_settings ADD COLUMN only_when_closed TINYINT(1) NOT NULL DEFAULT 0 AFTER enabled");
        } catch (PDOException) {}  // column already exists → ignore

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_line_faq_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            line_user_id VARCHAR(80) NOT NULL,
            intent_type  VARCHAR(20) NOT NULL,
            replied_at   DATETIME    NOT NULL,
            INDEX idx_user_intent_time (line_user_id, intent_type, replied_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException) {
        // ใช้ default ใน memory ถ้า DB ไม่พร้อม
    }
}

/**
 * อ่าน settings ของ FAQ — ถ้ายังไม่มี row ใช้ default จาก CLINIC_FAQ_DEFAULTS
 */
function get_clinic_faq_settings(PDO $pdo): array
{
    ensure_clinic_faq_tables($pdo);
    try {
        $row = $pdo->query("SELECT * FROM sys_line_faq_settings WHERE id = 1 LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        $row = null;
    }
    if (!$row) return CLINIC_FAQ_DEFAULTS;

    $out = CLINIC_FAQ_DEFAULTS;
    foreach ($out as $k => $_) {
        if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
            $out[$k] = $row[$k];
        }
    }
    $out['enabled']          = (int)$out['enabled'];
    $out['only_when_closed'] = (int)$out['only_when_closed'];
    $out['rate_limit_hours'] = (int)$out['rate_limit_hours'];
    return $out;
}

/**
 * บันทึก settings (singleton row id=1) — เฉพาะ key ใน CLINIC_FAQ_DEFAULTS
 *
 * @param array<string,mixed> $data
 */
function save_clinic_faq_settings(PDO $pdo, array $data): bool
{
    ensure_clinic_faq_tables($pdo);

    $values = [];
    foreach (CLINIC_FAQ_DEFAULTS as $k => $default) {
        if (!array_key_exists($k, $data)) {
            $values[$k] = $default;
            continue;
        }
        if ($k === 'enabled' || $k === 'only_when_closed') {
            $values[$k] = !empty($data[$k]) ? 1 : 0;
        } elseif ($k === 'rate_limit_hours') {
            $values[$k] = max(0, min(720, (int)$data[$k]));   // 0..720 ชั่วโมง (30 วัน)
        } else {
            $v = trim((string)$data[$k]);
            $values[$k] = $v !== '' ? mb_substr($v, 0, 255) : $default;
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO sys_line_faq_settings
                (id, enabled, only_when_closed, rate_limit_hours,
                 msg_open_now_title, msg_open_now_sub,
                 msg_before_open_title, msg_before_open_sub,
                 msg_after_close_title, msg_after_close_sub,
                 msg_closed_today_title, msg_closed_today_sub)
            VALUES
                (1, :enabled, :only_when_closed, :rate_limit_hours,
                 :msg_open_now_title, :msg_open_now_sub,
                 :msg_before_open_title, :msg_before_open_sub,
                 :msg_after_close_title, :msg_after_close_sub,
                 :msg_closed_today_title, :msg_closed_today_sub)
            ON DUPLICATE KEY UPDATE
                enabled                = VALUES(enabled),
                only_when_closed       = VALUES(only_when_closed),
                rate_limit_hours       = VALUES(rate_limit_hours),
                msg_open_now_title     = VALUES(msg_open_now_title),
                msg_open_now_sub       = VALUES(msg_open_now_sub),
                msg_before_open_title  = VALUES(msg_before_open_title),
                msg_before_open_sub    = VALUES(msg_before_open_sub),
                msg_after_close_title  = VALUES(msg_after_close_title),
                msg_after_close_sub    = VALUES(msg_after_close_sub),
                msg_closed_today_title = VALUES(msg_closed_today_title),
                msg_closed_today_sub   = VALUES(msg_closed_today_sub)
        ");
        $params = [];
        foreach ($values as $k => $v) {
            $params[':' . $k] = $v;
        }
        return $stmt->execute($params);
    } catch (PDOException) {
        return false;
    }
}

/**
 * เช็คว่า user คนนี้ยังตอบ FAQ ชนิดนี้ได้หรือไม่ (ภายใน N ชั่วโมงล่าสุด)
 *
 * @return bool true = อนุญาต, false = ติด rate limit
 */
function check_clinic_faq_rate_limit(PDO $pdo, string $lineUserId, string $intentType, int $windowHours): bool
{
    if ($lineUserId === '' || $windowHours <= 0) return true;
    ensure_clinic_faq_tables($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM sys_line_faq_log
            WHERE line_user_id = :uid
              AND intent_type  = :it
              AND replied_at   >= (NOW() - INTERVAL :hh HOUR)
            LIMIT 1
        ");
        $stmt->bindValue(':uid', $lineUserId);
        $stmt->bindValue(':it',  $intentType);
        $stmt->bindValue(':hh',  $windowHours, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() === false;   // ไม่มี row = ยังไม่ติด limit
    } catch (PDOException) {
        return true;   // fail-open: ถ้า DB error ให้ตอบไปก่อน
    }
}

/**
 * บันทึกว่า user ได้รับการตอบ FAQ ชนิดนี้แล้ว
 */
function log_clinic_faq_reply(PDO $pdo, string $lineUserId, string $intentType): void
{
    if ($lineUserId === '') return;
    ensure_clinic_faq_tables($pdo);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sys_line_faq_log (line_user_id, intent_type, replied_at)
            VALUES (:uid, :it, NOW())
        ");
        $stmt->execute([':uid' => $lineUserId, ':it' => $intentType]);
    } catch (PDOException) {}
}

/**
 * ลบ log เก่ากว่า 30 วัน — เรียกเป็นครั้งคราวเพื่อกัน table โต
 */
function purge_clinic_faq_log(PDO $pdo, int $keepDays = 30): void
{
    try {
        $pdo->prepare("DELETE FROM sys_line_faq_log WHERE replied_at < (NOW() - INTERVAL :d DAY)")
            ->execute([':d' => max(1, $keepDays)]);
    } catch (PDOException) {}
}

/**
 * Substitute placeholders {key} ในเทมเพลต เช่น "ปิด {close_time} น."
 */
function clinic_render_template(string $template, array $vars): string
{
    return preg_replace_callback('/\{(\w+)\}/u', function ($m) use ($vars) {
        return (string)($vars[$m[1]] ?? '');
    }, $template) ?? $template;
}

/**
 * ตรวจว่าข้อความ user เป็นคำถามเกี่ยวกับสถานะคลินิกหรือไม่
 *
 * @return array{type: 'status'|'doctors', date: string, date_label: string, offset: int}|null
 */
function detect_clinic_status_intent(string $text): ?array
{
    $t = trim($text);
    if ($t === '') return null;

    // ─── หาช่วงวัน: วันนี้ (default), พรุ่งนี้, มะรืน ───
    $offset = 0;
    $dateLabel = 'วันนี้';
    if (mb_strpos($t, 'มะรืน') !== false) {
        $offset = 2;
        $dateLabel = 'มะรืนนี้';
    } elseif (mb_strpos($t, 'พรุ่งนี้') !== false) {
        $offset = 1;
        $dateLabel = 'พรุ่งนี้';
    }

    $tz = new DateTimeZone(CLINIC_TZ_NAME);
    $dt = (new DateTimeImmutable('today', $tz))->modify("+{$offset} day");
    $date = $dt->format('Y-m-d');

    // ─── ถ้าถามเรื่องตารางแพทย์/หมอ → intent = doctors ───
    $doctorPhrases = [
        'ตารางแพทย์', 'ตารางหมอ', 'ตารางออกตรวจ',
        'แพทย์ออกตรวจ', 'หมอออกตรวจ',
        'มีหมอ', 'มีแพทย์',
        'หมอไหน', 'แพทย์ไหน',
        'หมอใคร', 'แพทย์ใคร',
        'หมอท่านไหน', 'แพทย์ท่านไหน',
        'หมอคนไหน', 'แพทย์คนไหน',
    ];
    foreach ($doctorPhrases as $p) {
        if (mb_strpos($t, $p) !== false) {
            return ['type' => 'doctors', 'date' => $date, 'date_label' => $dateLabel, 'offset' => $offset];
        }
    }

    // ─── ถ้าถามเรื่องเปิด/ปิด/เวลาทำการ → intent = status ───
    $statusPhrases = [
        'เปิดไหม', 'เปิดมั้ย', 'เปิดป่ะ', 'เปิดมัย',
        'เปิดอยู่ไหม', 'เปิดอยู่มั้ย', 'เปิดอยู่ป่ะ',
        'เปิดรึยัง', 'เปิดยัง',
        'ปิดไหม', 'ปิดมั้ย', 'ปิดยัง', 'ปิดรึยัง',
        'หยุดไหม', 'หยุดมั้ย', 'หยุดป่ะ', 'หยุดทำการ',
        'เปิดกี่โมง', 'ปิดกี่โมง',
        'เวลาทำการ', 'เวลาเปิด', 'เวลาปิด', 'เวลาเปิด-ปิด', 'เวลาเปิดปิด',
        'ทำการไหม', 'ทำการมั้ย',
        'เปิดทำการ', 'ปิดทำการ',
        'เปิดบริการ',
    ];
    foreach ($statusPhrases as $p) {
        if (mb_strpos($t, $p) !== false) {
            return ['type' => 'status', 'date' => $date, 'date_label' => $dateLabel, 'offset' => $offset];
        }
    }

    // คำถาม "กี่โมง" ลอย ๆ → ต้องมี context ของคลินิกด้วย ป้องกัน false positive
    if (mb_strpos($t, 'กี่โมง') !== false &&
        (mb_strpos($t, 'คลินิก') !== false
            || mb_strpos($t, 'ห้องพยาบาล') !== false
            || mb_strpos($t, 'พยาบาล') !== false
            || mb_strpos($t, 'หมอ') !== false
            || mb_strpos($t, 'แพทย์') !== false)) {
        return ['type' => 'status', 'date' => $date, 'date_label' => $dateLabel, 'offset' => $offset];
    }

    return null;
}

/**
 * ดูว่าวันที่นี้คลินิกเปิดทำการหรือไม่
 *
 * @return array{
 *   closed: bool,
 *   open_time: ?string,
 *   close_time: ?string,
 *   note: string,
 *   source: 'holiday'|'special'|'regular'|'no_regular'
 * }
 */
function get_clinic_hours_for_date(PDO $pdo, string $date): array
{
    $weekday = (int)(new DateTimeImmutable($date, new DateTimeZone(CLINIC_TZ_NAME)))->format('w');

    // 1) override ของวันนั้น (holiday/special) มาก่อน
    try {
        $stmt = $pdo->prepare(
            "SELECT type, open_time, close_time, is_closed, note
             FROM sys_clinic_hours
             WHERE type IN ('holiday','special') AND specific_date = :d
             ORDER BY (type='special') DESC
             LIMIT 1"
        );
        $stmt->execute([':d' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        $row = null;
    }

    if ($row) {
        $isClosed = (int)($row['is_closed'] ?? 0) === 1;
        return [
            'closed'     => $isClosed,
            'open_time'  => $isClosed ? null : (($row['open_time']  ?? null) ? substr((string)$row['open_time'], 0, 5)  : null),
            'close_time' => $isClosed ? null : (($row['close_time'] ?? null) ? substr((string)$row['close_time'], 0, 5) : null),
            'note'       => trim((string)($row['note'] ?? '')),
            'source'     => $row['type'] === 'special' ? 'special' : 'holiday',
        ];
    }

    // 2) regular weekly
    try {
        $stmt = $pdo->prepare(
            "SELECT open_time, close_time, is_closed, note
             FROM sys_clinic_hours
             WHERE type='regular' AND weekday = :wd
             ORDER BY open_time ASC
             LIMIT 1"
        );
        $stmt->execute([':wd' => $weekday]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        $row = null;
    }

    if ($row) {
        $isClosed = (int)($row['is_closed'] ?? 0) === 1;
        return [
            'closed'     => $isClosed,
            'open_time'  => $isClosed ? null : (($row['open_time']  ?? null) ? substr((string)$row['open_time'], 0, 5)  : null),
            'close_time' => $isClosed ? null : (($row['close_time'] ?? null) ? substr((string)$row['close_time'], 0, 5) : null),
            'note'       => trim((string)($row['note'] ?? '')),
            'source'     => 'regular',
        ];
    }

    // 3) ไม่มีตั้งค่าสำหรับวันนี้
    return [
        'closed'     => true,
        'open_time'  => null,
        'close_time' => null,
        'note'       => '',
        'source'     => 'no_regular',
    ];
}

/**
 * ดึงรายชื่อแพทย์/บุคลากรที่ออกตรวจในวันที่กำหนด
 *
 * @return list<array<string,mixed>>
 */
function get_clinic_doctors_for_date(PDO $pdo, string $date): array
{
    $weekday = (int)(new DateTimeImmutable($date, new DateTimeZone(CLINIC_TZ_NAME)))->format('w');

    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.staff_id, s.type, s.specific_date, s.weekday,
                   s.start_time, s.end_time, s.service_type, s.notes,
                   ms.title  AS doc_title,
                   ms.full_name AS doc_name,
                   ms.role,
                   cr.name   AS room_name,
                   cr.code   AS room_code
            FROM sys_doctor_schedule s
            JOIN sys_medical_staff ms ON s.staff_id = ms.id
            LEFT JOIN sys_clinic_rooms cr ON s.room_id = cr.id
            WHERE s.is_active = 1 AND ms.is_active = 1
              AND (
                  s.specific_date = :d
                  OR (
                      (s.specific_date IS NULL OR s.specific_date = '')
                      AND s.type = 'regular'
                      AND s.weekday = :wd
                      AND (s.recur_end_date IS NULL OR s.recur_end_date >= :d)
                  )
              )
        ");
        $stmt->execute([':d' => $date, ':wd' => $weekday]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException) {
        return [];
    }

    // Override logic: ถ้า staff คนใดมี shift เฉพาะวัน (specific_date = วันนี้) — ไม่ต้องเอา regular ของคนนั้นมาแสดง
    $hasOverride = [];
    foreach ($rows as $r) {
        if (!empty($r['specific_date']) && $r['specific_date'] === $date) {
            $hasOverride[(int)$r['staff_id']] = true;
        }
    }

    $shifts = [];
    foreach ($rows as $r) {
        if (!empty($r['specific_date'])) {
            // เฉพาะวันนี้ และไม่ใช่ off
            if ($r['specific_date'] === $date && ($r['type'] ?? '') !== 'off') {
                $shifts[] = $r;
            }
        } else {
            if ((int)$r['weekday'] === $weekday && empty($hasOverride[(int)$r['staff_id']])) {
                $shifts[] = $r;
            }
        }
    }

    usort($shifts, fn($a, $b) => strcmp((string)($a['start_time'] ?? ''), (string)($b['start_time'] ?? '')));
    return $shifts;
}

/**
 * ดึงข้อมูลคลินิก (เบอร์โทร, ชื่อ) จาก sys_clinic_profile
 *
 * @return array{name: string, phone: string}
 */
function get_clinic_profile_brief(PDO $pdo): array
{
    try {
        $row = $pdo->query("SELECT name_th, phone FROM sys_clinic_profile WHERE id = 1 LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        $row = null;
    }
    return [
        'name'  => trim((string)($row['name_th'] ?? '')),
        'phone' => trim((string)($row['phone']   ?? '')),
    ];
}

/**
 * แปลง YYYY-MM-DD เป็นข้อความไทย "พุธ 7 พ.ค. 2569"
 */
function clinic_format_thai_date(string $date): string
{
    $tz = new DateTimeZone(CLINIC_TZ_NAME);
    try {
        $dt = new DateTimeImmutable($date, $tz);
    } catch (Exception) {
        return $date;
    }
    $wd  = (int)$dt->format('w');
    $d   = (int)$dt->format('j');
    $m   = (int)$dt->format('n');
    $yBe = (int)$dt->format('Y') + 543;
    $monthShort = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return sprintf('%s %d %s %d', CLINIC_WEEKDAY_TH_FULL[$wd], $d, $monthShort[$m], $yBe);
}

/**
 * คำนวณสถานะคลินิก ณ "เวลาขณะนี้" จริง ๆ
 * (ใช้กับคำถามที่หมายถึง "ตอนนี้/วันนี้" — เช่น "เปิดไหม", "เปิดกี่โมง")
 *
 * @return array{
 *   is_open_now: bool,
 *   state: 'open_now'|'before_open'|'after_close'|'closed_today',
 *   today_open: ?string,
 *   today_close: ?string,
 *   minutes_until_close: ?int,
 *   minutes_until_open: ?int,
 *   next_open_date: ?string,
 *   next_open_time: ?string,
 *   next_open_label: ?string,
 *   today_note: string
 * }
 */
function get_clinic_current_status(PDO $pdo, ?DateTimeImmutable $now = null): array
{
    $tz  = new DateTimeZone(CLINIC_TZ_NAME);
    $now = $now ?? new DateTimeImmutable('now', $tz);
    $today      = $now->format('Y-m-d');
    $todayHours = get_clinic_hours_for_date($pdo, $today);
    $nowHm      = $now->format('H:i');

    if (!$todayHours['closed'] && $todayHours['open_time'] && $todayHours['close_time']) {
        if ($nowHm < $todayHours['open_time']) {
            return [
                'is_open_now' => false,
                'state' => 'before_open',
                'today_open'  => $todayHours['open_time'],
                'today_close' => $todayHours['close_time'],
                'minutes_until_close' => null,
                'minutes_until_open'  => clinic_minutes_diff($nowHm, $todayHours['open_time']),
                'next_open_date'  => $today,
                'next_open_time'  => $todayHours['open_time'],
                'next_open_label' => 'วันนี้',
                'today_note'  => $todayHours['note'],
            ];
        }
        if ($nowHm < $todayHours['close_time']) {
            return [
                'is_open_now' => true,
                'state' => 'open_now',
                'today_open'  => $todayHours['open_time'],
                'today_close' => $todayHours['close_time'],
                'minutes_until_close' => clinic_minutes_diff($nowHm, $todayHours['close_time']),
                'minutes_until_open'  => null,
                'next_open_date'  => null,
                'next_open_time'  => null,
                'next_open_label' => null,
                'today_note'  => $todayHours['note'],
            ];
        }
        // ผ่านเวลาปิดของวันนี้แล้ว
        $next = find_next_clinic_opening($pdo, $now->modify('+1 day'));
        return [
            'is_open_now' => false,
            'state' => 'after_close',
            'today_open'  => $todayHours['open_time'],
            'today_close' => $todayHours['close_time'],
            'minutes_until_close' => null,
            'minutes_until_open'  => null,
            'next_open_date'  => $next['date'],
            'next_open_time'  => $next['open_time'],
            'next_open_label' => $next['label'],
            'today_note'  => $todayHours['note'],
        ];
    }

    // วันนี้คลินิกหยุดทั้งวัน
    $next = find_next_clinic_opening($pdo, $now->modify('+1 day'));
    return [
        'is_open_now' => false,
        'state' => 'closed_today',
        'today_open'  => null,
        'today_close' => null,
        'minutes_until_close' => null,
        'minutes_until_open'  => null,
        'next_open_date'  => $next['date'],
        'next_open_time'  => $next['open_time'],
        'next_open_label' => $next['label'],
        'today_note'  => $todayHours['note'],
    ];
}

/**
 * หาวันเปิดทำการถัดไป (เริ่มหาจาก $startFrom 00:00 — มองไปข้างหน้า max 14 วัน)
 *
 * @return array{date: ?string, open_time: ?string, label: ?string}
 */
function find_next_clinic_opening(PDO $pdo, DateTimeImmutable $startFrom, int $maxDays = 14): array
{
    $cur = $startFrom->setTime(0, 0);
    for ($i = 0; $i < $maxDays; $i++) {
        $date = $cur->format('Y-m-d');
        $h    = get_clinic_hours_for_date($pdo, $date);
        if (!$h['closed'] && $h['open_time']) {
            return [
                'date'      => $date,
                'open_time' => $h['open_time'],
                'label'     => clinic_relative_date_label($cur),
            ];
        }
        $cur = $cur->modify('+1 day');
    }
    return ['date' => null, 'open_time' => null, 'label' => null];
}

/**
 * คืน label สัมพัทธ์ของวันที่: "วันนี้" / "พรุ่งนี้" / "มะรืน" / "วันจันทร์ที่ 12 พ.ค."
 */
function clinic_relative_date_label(DateTimeImmutable $date): string
{
    $tz    = new DateTimeZone(CLINIC_TZ_NAME);
    $today = new DateTimeImmutable('today', $tz);
    $diff  = (int)$today->diff($date->setTime(0, 0))->format('%r%a');
    if ($diff === 0) return 'วันนี้';
    if ($diff === 1) return 'พรุ่งนี้';
    if ($diff === 2) return 'มะรืน';
    $wd = (int)$date->format('w');
    return 'วัน' . CLINIC_WEEKDAY_TH_FULL[$wd] . 'ที่ ' . (int)$date->format('j') . ' '
        . ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][(int)$date->format('n')];
}

/**
 * ความต่างนาทีระหว่าง HH:MM สองค่า (b - a)
 */
function clinic_minutes_diff(string $a, string $b): int
{
    [$ah, $am] = array_pad(explode(':', $a), 2, '0');
    [$bh, $bm] = array_pad(explode(':', $b), 2, '0');
    return ((int)$bh * 60 + (int)$bm) - ((int)$ah * 60 + (int)$am);
}

/**
 * แปลงนาทีเป็น "X ชม. Y นาที" / "Y นาที"
 */
function clinic_format_minutes(int $minutes): string
{
    if ($minutes < 0) $minutes = 0;
    if ($minutes < 60) return $minutes . ' นาที';
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $m === 0 ? $h . ' ชม.' : $h . ' ชม. ' . $m . ' นาที';
}

/**
 * สร้าง URL ฐาน (https://host/path) สำหรับลิงก์ในข้อความ
 */
function clinic_app_base_url(): string
{
    $proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
        ? $_SERVER['HTTP_X_FORWARDED_PROTO']
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $dir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/line_webhook.php'));
    $basePath = preg_replace('#/api$#', '', rtrim($dir, '/')) ?: '';
    return rtrim($proto . '://' . $host . $basePath, '/');
}

/**
 * สร้าง LINE Flex bubble ตอบสถานะคลินิกของวันที่ระบุ
 *
 * @return array<string,mixed>  Flex message payload
 */
function build_clinic_status_flex(PDO $pdo, string $date, string $dateLabel, bool $considerNow = false, ?array $forceStatus = null, ?array $settingsOverride = null): array
{
    $hours    = get_clinic_hours_for_date($pdo, $date);
    $doctors  = get_clinic_doctors_for_date($pdo, $date);
    $profile  = get_clinic_profile_brief($pdo);
    $thaiDate = clinic_format_thai_date($date);
    $baseUrl  = clinic_app_base_url();

    $isOpenToday = !$hours['closed'];

    // Real-time status — เฉพาะคำถามที่หมายถึง "ตอนนี้/วันนี้"
    // $forceStatus ใช้สำหรับ preview/test ใน admin settings
    $now = $forceStatus ?? ($considerNow ? get_clinic_current_status($pdo) : null);

    if ($now !== null) {
        $settings = $settingsOverride
            ? array_merge(get_clinic_faq_settings($pdo), $settingsOverride)
            : get_clinic_faq_settings($pdo);
        $vars = [
            'open_time'   => (string)($now['today_open'] ?? ''),
            'close_time'  => (string)($now['today_close'] ?? ''),
            'time_left'   => clinic_format_minutes((int)($now['minutes_until_close'] ?? $now['minutes_until_open'] ?? 0)),
            'next_label'  => (string)($now['next_open_label'] ?? ''),
            'next_time'   => (string)($now['next_open_time'] ?? ''),
        ];
        switch ($now['state']) {
            case 'open_now':
                $statusText  = clinic_render_template($settings['msg_open_now_title'], $vars);
                $subText     = clinic_render_template($settings['msg_open_now_sub'],   $vars);
                $statusColor = '#059669';
                $statusBg    = '#ECFDF5';
                break;
            case 'before_open':
                $statusText  = clinic_render_template($settings['msg_before_open_title'], $vars);
                $subText     = clinic_render_template($settings['msg_before_open_sub'],   $vars);
                $statusColor = '#D97706';
                $statusBg    = '#FFFBEB';
                break;
            case 'after_close':
                $statusText  = clinic_render_template($settings['msg_after_close_title'], $vars);
                $subText     = $now['next_open_date']
                    ? clinic_render_template($settings['msg_after_close_sub'], $vars)
                    : 'โปรดตรวจสอบเวลาทำการอีกครั้ง';
                $statusColor = '#DC2626';
                $statusBg    = '#FEF2F2';
                break;
            case 'closed_today':
            default:
                $statusText  = clinic_render_template($settings['msg_closed_today_title'], $vars);
                $subText     = $now['next_open_date']
                    ? clinic_render_template($settings['msg_closed_today_sub'], $vars)
                    : '';
                $statusColor = '#DC2626';
                $statusBg    = '#FEF2F2';
                break;
        }
    } else {
        $statusText  = $isOpenToday ? 'เปิดทำการ' : 'หยุดทำการ';
        $subText     = '';
        $statusColor = $isOpenToday ? '#059669' : '#DC2626';
        $statusBg    = $isOpenToday ? '#ECFDF5' : '#FEF2F2';
    }

    // ── header ──
    $headerContents = [
        [
            'type' => 'text',
            'text' => 'สถานะห้องพยาบาล',
            'weight' => 'bold',
            'size' => 'xs',
            'color' => '#0EA5E9',
        ],
        [
            'type' => 'text',
            'text' => $dateLabel . ' · ' . $thaiDate,
            'weight' => 'bold',
            'size' => 'lg',
            'color' => '#0F172A',
            'margin' => 'sm',
            'wrap' => true,
        ],
        [
            'type' => 'text',
            'text' => $statusText,
            'weight' => 'bold',
            'size' => 'xl',
            'color' => $statusColor,
            'margin' => 'md',
        ],
    ];
    if ($subText !== '') {
        $headerContents[] = [
            'type' => 'text',
            'text' => $subText,
            'size' => 'sm',
            'weight' => 'bold',
            'color' => '#475569',
            'margin' => 'sm',
            'wrap' => true,
        ];
    }

    // ── body rows ──
    $rows = [];
    if ($isOpenToday && $hours['open_time'] && $hours['close_time']) {
        $rows[] = clinic_flex_row('เวลาทำการ', $hours['open_time'] . ' - ' . $hours['close_time'] . ' น.');
    }
    if ($hours['note'] !== '') {
        $rows[] = clinic_flex_row($hours['source'] === 'holiday' ? 'วันหยุด' : 'หมายเหตุ', $hours['note']);
    } elseif ($hours['source'] === 'no_regular' && !$isOpenToday) {
        $rows[] = clinic_flex_row('หมายเหตุ', 'ไม่ได้กำหนดเวลาทำการของวันนี้');
    }

    if ($isOpenToday) {
        $rows[] = clinic_flex_row(
            'แพทย์ออกตรวจ',
            count($doctors) > 0 ? (count($doctors) . ' ท่าน') : 'ยังไม่มีตารางแพทย์'
        );
        // โชว์ชื่อแพทย์ 3 คนแรก
        $previewLines = [];
        foreach (array_slice($doctors, 0, 3) as $d) {
            $name = trim(((string)($d['doc_title'] ?? '')) . ' ' . ((string)($d['doc_name'] ?? '-')));
            $time = substr((string)($d['start_time'] ?? ''), 0, 5) . '-' . substr((string)($d['end_time'] ?? ''), 0, 5);
            $svc  = trim((string)($d['service_type'] ?? ''));
            $line = $name . ' · ' . $time . ($svc !== '' ? ' · ' . $svc : '');
            $previewLines[] = mb_strlen($line) > 60 ? mb_substr($line, 0, 57) . '…' : $line;
        }
        if (count($doctors) > 3) {
            $previewLines[] = 'และอีก ' . (count($doctors) - 3) . ' ท่าน';
        }
        foreach ($previewLines as $line) {
            $rows[] = [
                'type' => 'text',
                'text' => '• ' . $line,
                'size' => 'xs',
                'color' => '#475569',
                'margin' => 'sm',
                'wrap' => true,
            ];
        }
    }

    if ($profile['phone'] !== '') {
        $rows[] = clinic_flex_row('โทร', $profile['phone']);
    }

    // ── footer buttons ──
    $footerButtons = [
        [
            'type' => 'button',
            'style' => 'primary',
            'color' => '#0EA5E9',
            'height' => 'sm',
            'action' => [
                'type' => 'uri',
                'label' => 'ดูตารางแพทย์เต็ม',
                'uri'   => $baseUrl . '/user/clinic_schedule.php',
            ],
        ],
    ];
    if ($profile['phone'] !== '') {
        $footerButtons[] = [
            'type' => 'button',
            'style' => 'secondary',
            'height' => 'sm',
            'action' => [
                'type' => 'uri',
                'label' => 'โทร ' . $profile['phone'],
                'uri'   => 'tel:' . preg_replace('/[^0-9+]/', '', $profile['phone']),
            ],
        ];
    }

    return [
        'type' => 'flex',
        'altText' => $dateLabel . ' ห้องพยาบาล: ' . $statusText
            . ($subText !== '' ? ' · ' . $subText : ''),
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '20px',
                'backgroundColor' => $statusBg,
                'contents' => array_merge($headerContents, [
                    ['type' => 'separator', 'margin' => 'lg', 'color' => '#E2E8F0'],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'md',
                        'spacing' => 'sm',
                        'contents' => $rows ?: [[
                            'type' => 'text',
                            'text' => 'ไม่มีข้อมูลเพิ่มเติม',
                            'size' => 'sm',
                            'color' => '#94A3B8',
                        ]],
                    ],
                ]),
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => $footerButtons,
            ],
        ],
    ];
}

/**
 * สร้าง LINE Flex bubble แสดง "ตารางแพทย์ของวันที่ระบุ"
 */
function build_clinic_doctors_flex(PDO $pdo, string $date, string $dateLabel, bool $considerNow = false): array
{
    $hours    = get_clinic_hours_for_date($pdo, $date);
    $doctors  = get_clinic_doctors_for_date($pdo, $date);
    $profile  = get_clinic_profile_brief($pdo);
    $thaiDate = clinic_format_thai_date($date);
    $baseUrl  = clinic_app_base_url();

    // ถ้าคลินิกหยุด หรือถามวันนี้ตอนนอกเวลา → ตอบ status flex แทน (มี out-of-hours notice)
    if ($hours['closed']) {
        return build_clinic_status_flex($pdo, $date, $dateLabel, $considerNow);
    }
    if ($considerNow) {
        $cur = get_clinic_current_status($pdo);
        if (in_array($cur['state'], ['after_close', 'before_open'], true)) {
            return build_clinic_status_flex($pdo, $date, $dateLabel, true);
        }
    }

    $listContents = [];
    if (count($doctors) === 0) {
        $listContents[] = [
            'type' => 'text',
            'text' => 'ยังไม่มีแพทย์ออกตรวจในวันนี้ในระบบ — โปรดติดต่อห้องพยาบาลเพื่อยืนยัน',
            'size' => 'sm',
            'color' => '#475569',
            'wrap' => true,
        ];
    } else {
        foreach (array_slice($doctors, 0, 8) as $d) {
            $name = trim(((string)($d['doc_title'] ?? '')) . ' ' . ((string)($d['doc_name'] ?? '-')));
            $time = substr((string)($d['start_time'] ?? ''), 0, 5) . ' - ' . substr((string)($d['end_time'] ?? ''), 0, 5);
            $svc  = trim((string)($d['service_type'] ?? ''));
            $room = trim((string)($d['room_name'] ?? ''));
            $sub  = $time . ($svc !== '' ? ' · ' . $svc : '') . ($room !== '' ? ' · ' . $room : '');

            $listContents[] = [
                'type' => 'box',
                'layout' => 'vertical',
                'margin' => 'md',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $name,
                        'size' => 'sm',
                        'weight' => 'bold',
                        'color' => '#0F172A',
                        'wrap' => true,
                    ],
                    [
                        'type' => 'text',
                        'text' => $sub,
                        'size' => 'xs',
                        'color' => '#64748B',
                        'wrap' => true,
                    ],
                ],
            ];
        }
        if (count($doctors) > 8) {
            $listContents[] = [
                'type' => 'text',
                'text' => 'และอีก ' . (count($doctors) - 8) . ' ท่าน',
                'size' => 'xs',
                'color' => '#0EA5E9',
                'margin' => 'md',
                'weight' => 'bold',
            ];
        }
    }

    $headerLine = $hours['open_time'] && $hours['close_time']
        ? ('เปิด ' . $hours['open_time'] . '-' . $hours['close_time'] . ' น.')
        : 'เปิดทำการ';

    return [
        'type' => 'flex',
        'altText' => $dateLabel . ' ตารางแพทย์ออกตรวจ ' . count($doctors) . ' ท่าน',
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '20px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'ตารางแพทย์ออกตรวจ',
                        'size' => 'xs',
                        'weight' => 'bold',
                        'color' => '#0EA5E9',
                    ],
                    [
                        'type' => 'text',
                        'text' => $dateLabel . ' · ' . $thaiDate,
                        'size' => 'lg',
                        'weight' => 'bold',
                        'color' => '#0F172A',
                        'margin' => 'sm',
                        'wrap' => true,
                    ],
                    [
                        'type' => 'text',
                        'text' => $headerLine . ' · แพทย์ ' . count($doctors) . ' ท่าน',
                        'size' => 'xs',
                        'color' => '#059669',
                        'margin' => 'sm',
                        'weight' => 'bold',
                    ],
                    ['type' => 'separator', 'margin' => 'lg', 'color' => '#E2E8F0'],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'margin' => 'sm',
                        'spacing' => 'sm',
                        'contents' => $listContents,
                    ],
                ],
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#0EA5E9',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => 'ดูปฏิทินแบบเต็ม',
                            'uri'   => $baseUrl . '/user/clinic_schedule.php',
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function clinic_flex_row(string $label, string $value): array
{
    return [
        'type' => 'box',
        'layout' => 'baseline',
        'spacing' => 'sm',
        'contents' => [
            [
                'type' => 'text',
                'text' => $label,
                'size' => 'sm',
                'color' => '#64748B',
                'flex' => 2,
            ],
            [
                'type' => 'text',
                'text' => $value,
                'size' => 'sm',
                'weight' => 'bold',
                'color' => '#0F172A',
                'flex' => 4,
                'wrap' => true,
            ],
        ],
    ];
}

/**
 * สร้างค่า "current status" จำลองสำหรับ preview/test
 * รองรับ 4 state: open_now, before_open, after_close, closed_today
 *
 * @return array<string,mixed>  รูปแบบเดียวกับ get_clinic_current_status()
 */
function build_clinic_simulated_status(string $state): array
{
    $base = [
        'is_open_now' => false,
        'state' => $state,
        'today_open'  => null,
        'today_close' => null,
        'minutes_until_close' => null,
        'minutes_until_open'  => null,
        'next_open_date'  => null,
        'next_open_time'  => null,
        'next_open_label' => null,
        'today_note'  => '',
    ];
    switch ($state) {
        case 'open_now':
            return array_merge($base, [
                'is_open_now' => true,
                'today_open'  => '08:00',
                'today_close' => '17:00',
                'minutes_until_close' => 150,   // อีก 2 ชม. 30 นาที
            ]);
        case 'before_open':
            return array_merge($base, [
                'today_open'  => '08:00',
                'today_close' => '17:00',
                'minutes_until_open' => 90,     // อีก 1 ชม. 30 นาที
                'next_open_date'  => date('Y-m-d'),
                'next_open_time'  => '08:00',
                'next_open_label' => 'วันนี้',
            ]);
        case 'after_close':
            return array_merge($base, [
                'today_open'  => '08:00',
                'today_close' => '17:00',
                'next_open_date'  => date('Y-m-d', strtotime('+1 day')),
                'next_open_time'  => '08:00',
                'next_open_label' => 'พรุ่งนี้',
            ]);
        case 'closed_today':
        default:
            return array_merge($base, [
                'state' => 'closed_today',
                'next_open_date'  => date('Y-m-d', strtotime('+1 day')),
                'next_open_time'  => '08:00',
                'next_open_label' => 'พรุ่งนี้',
                'today_note'  => 'วันหยุดราชการ',
            ]);
    }
}

/**
 * สร้าง flex สำหรับ preview/test ตาม state ที่ระบุ (ไม่อิงเวลาจริง)
 *
 * @param array<string,string>|null $settingsOverride  ใส่เพื่อ preview ค่าฟอร์มที่ยังไม่ได้บันทึก
 */
function build_clinic_test_flex(PDO $pdo, string $state, ?array $settingsOverride = null): array
{
    $tz = new DateTimeZone(CLINIC_TZ_NAME);
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $forced = build_clinic_simulated_status($state);

    // Override simulated times with real DB hours so preview matches actual schedule
    $realHours = get_clinic_hours_for_date($pdo, $today);
    if (!$realHours['closed'] && $realHours['open_time'] && $realHours['close_time']) {
        $forced['today_open']  = $realHours['open_time'];
        $forced['today_close'] = $realHours['close_time'];

        // Recalculate time remaining from real current time instead of hardcoded 150 min
        $nowHm = (new DateTimeImmutable('now', $tz))->format('H:i');
        if ($state === 'open_now' && $nowHm < $realHours['close_time']) {
            $forced['minutes_until_close'] = clinic_minutes_diff($nowHm, $realHours['close_time']);
        } elseif ($state === 'before_open' && $nowHm < $realHours['open_time']) {
            $forced['minutes_until_open'] = clinic_minutes_diff($nowHm, $realHours['open_time']);
            $forced['next_open_time']     = $realHours['open_time'];
        }
    }

    return build_clinic_status_flex($pdo, $today, 'วันนี้', false, $forced, $settingsOverride);
}

/**
 * สร้าง LINE messages array สำหรับ intent ที่ตรวจจับได้
 *
 * @return array<int, array<string,mixed>>
 */
function build_clinic_status_messages(PDO $pdo, array $intent): array
{
    $date  = (string)$intent['date'];
    $label = (string)$intent['date_label'];
    $type  = (string)$intent['type'];
    $considerNow = ((int)($intent['offset'] ?? 0)) === 0;

    if ($type === 'doctors') {
        return [build_clinic_doctors_flex($pdo, $date, $label, $considerNow)];
    }
    return [build_clinic_status_flex($pdo, $date, $label, $considerNow)];
}
