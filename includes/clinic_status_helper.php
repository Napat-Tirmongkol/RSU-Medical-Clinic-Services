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
                  OR (s.specific_date IS NULL AND s.type = 'regular' AND s.weekday = :wd)
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
function build_clinic_status_flex(PDO $pdo, string $date, string $dateLabel): array
{
    $hours    = get_clinic_hours_for_date($pdo, $date);
    $doctors  = get_clinic_doctors_for_date($pdo, $date);
    $profile  = get_clinic_profile_brief($pdo);
    $thaiDate = clinic_format_thai_date($date);
    $baseUrl  = clinic_app_base_url();

    $isOpen = !$hours['closed'];
    $statusText  = $isOpen ? 'เปิดทำการ' : 'หยุดทำการ';
    $statusColor = $isOpen ? '#059669' : '#DC2626';
    $statusBg    = $isOpen ? '#ECFDF5' : '#FEF2F2';

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
            'type' => 'box',
            'layout' => 'baseline',
            'margin' => 'md',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => $statusText,
                    'weight' => 'bold',
                    'size' => 'xl',
                    'color' => $statusColor,
                ],
            ],
        ],
    ];

    // ── body rows ──
    $rows = [];
    if ($isOpen && $hours['open_time'] && $hours['close_time']) {
        $rows[] = clinic_flex_row('เวลาทำการ', $hours['open_time'] . ' - ' . $hours['close_time'] . ' น.');
    }
    if ($hours['note'] !== '') {
        $rows[] = clinic_flex_row($hours['source'] === 'holiday' ? 'วันหยุด' : 'หมายเหตุ', $hours['note']);
    } elseif ($hours['source'] === 'no_regular' && !$isOpen) {
        $rows[] = clinic_flex_row('หมายเหตุ', 'ไม่ได้กำหนดเวลาทำการของวันนี้');
    }

    if ($isOpen) {
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
        'altText' => $dateLabel . ' ห้องพยาบาล' . $statusText
            . ($isOpen && $hours['open_time'] ? ' ' . $hours['open_time'] . '-' . $hours['close_time'] : ''),
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
function build_clinic_doctors_flex(PDO $pdo, string $date, string $dateLabel): array
{
    $hours    = get_clinic_hours_for_date($pdo, $date);
    $doctors  = get_clinic_doctors_for_date($pdo, $date);
    $profile  = get_clinic_profile_brief($pdo);
    $thaiDate = clinic_format_thai_date($date);
    $baseUrl  = clinic_app_base_url();

    // ถ้าคลินิกหยุด ตอบ status flex แทน
    if ($hours['closed']) {
        return build_clinic_status_flex($pdo, $date, $dateLabel);
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
 * สร้าง LINE messages array สำหรับ intent ที่ตรวจจับได้
 *
 * @return array<int, array<string,mixed>>
 */
function build_clinic_status_messages(PDO $pdo, array $intent): array
{
    $date  = (string)$intent['date'];
    $label = (string)$intent['date_label'];
    $type  = (string)$intent['type'];

    if ($type === 'doctors') {
        return [build_clinic_doctors_flex($pdo, $date, $label)];
    }
    return [build_clinic_status_flex($pdo, $date, $label)];
}
