<?php
/**
 * cron/daily_report_to_line.php
 * ──────────────────────────────────────────────────────────────────────────────
 * ส่งสรุป "รายงานรายวัน" (admin/daily_report.php) เข้ากลุ่ม LINE ทุกวัน
 *
 * ── วิธีตั้งค่า cron-job.org ──────────────────────────────────────────────────
 *  URL     : https://healthycampus.rsu.ac.th/rsu-clinic/cron/daily_report_to_line.php?token=YOUR_SECRET_TOKEN
 *  Schedule: ทุกวัน เวลา 17:00 (Asia/Bangkok)
 *  Timeout : 30 วินาที
 *
 *  Optional query params:
 *    ?date=2026-05-13    ทดสอบกับวันที่ระบุ (default: วันนี้)
 *    ?dryrun=1           แสดงข้อความที่จะส่งโดยไม่ยิงเข้า LINE
 *    ?force=1            บังคับส่งแม้วันที่นั้นไม่มีนัด/แคมเปญเลย (default: skip)
 *    ?group=Cxxxx        override groupId เฉพาะรอบนี้ (default: ค่าจาก sys_site_settings)
 *
 *  Default behavior:
 *    - ถ้าวันนั้นไม่มี booking/check-in/cancel เลย → SKIP (ไม่ส่ง, ไม่กิน LINE quota)
 *    - ใช้ ?force=1 ถ้าอยากบังคับส่งทุกครั้ง (เช่น admin กดทดสอบ)
 *
 *  Response: text/plain log — HTTP 200 ตลอด เว้นแต่ token ผิด
 *
 * ── เงื่อนไขที่ต้องมีก่อนใช้ ──────────────────────────────────────────────────
 *  1. LINE OA ของระบบต้องเข้าไปอยู่ในกลุ่มแชทที่ต้องการให้รับรายงาน
 *  2. ต้องตั้ง default group ผ่าน portal/_partials/line_settings.php
 *     (sys_site_settings.line.group.default_id) หรือใส่ ?group=... ตอนเรียก
 *  3. ต้องมี LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ตั้งค่าไว้
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

define('CRON_SECRET_TOKEN', 'rsu_daily_report_m4k8h3w7p');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(CRON_SECRET_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Bangkok');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/line_helper.php';

// ── Params ────────────────────────────────────────────────────────────────
$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo "ERROR: invalid date param\n";
    exit;
}
$dryrun = !empty($_GET['dryrun']);
$force  = !empty($_GET['force']);
$overrideGroup = trim((string)($_GET['group'] ?? ''));

$yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
$startedAt = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] daily_report_to_line started" . ($dryrun ? ' (DRY RUN)' : '') . "\n";
echo "report date: $date (yesterday: $yesterday)\n";

// ── Fetch stats ───────────────────────────────────────────────────────────
function dr_fetch_stats(PDO $pdo, string $date): array
{
    $sql = "
        SELECT
            SUM(CASE WHEN s.slot_date = :d1 AND DATE(b.attended_at) = :d2
                          AND b.status NOT IN ('cancelled','cancelled_by_admin') THEN 1 ELSE 0 END) AS on_schedule,
            SUM(CASE WHEN b.attended_at IS NOT NULL AND DATE(b.attended_at) < s.slot_date
                          AND (s.slot_date = :d3 OR DATE(b.attended_at) = :d4)
                          AND b.status NOT IN ('cancelled','cancelled_by_admin')
                                                                                THEN 1 ELSE 0 END) AS early_arrival,
            SUM(CASE WHEN s.slot_date = :d5 AND b.attended_at IS NULL
                          AND b.status NOT IN ('cancelled','cancelled_by_admin') THEN 1 ELSE 0 END) AS no_show,
            SUM(CASE WHEN s.slot_date = :d6 AND b.status IN ('cancelled','cancelled_by_admin')
                                                                                THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN s.slot_date = :d7 AND b.status NOT IN ('cancelled','cancelled_by_admin')
                                                                                THEN 1 ELSE 0 END) AS total_scheduled
        FROM camp_bookings b
        JOIN camp_slots s ON b.slot_id = s.id
        WHERE (s.slot_date = :d8 OR (DATE(b.attended_at) = :d9 AND s.slot_date > :d10))
    ";
    $p = [':d1'=>$date,':d2'=>$date,':d3'=>$date,':d4'=>$date,':d5'=>$date,
          ':d6'=>$date,':d7'=>$date,':d8'=>$date,':d9'=>$date,':d10'=>$date];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($p);
    $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'on_schedule'     => (int)($r['on_schedule']     ?? 0),
        'early_arrival'   => (int)($r['early_arrival']   ?? 0),
        'no_show'         => (int)($r['no_show']         ?? 0),
        'cancelled_count' => (int)($r['cancelled_count'] ?? 0),
        'total_scheduled' => (int)($r['total_scheduled'] ?? 0),
    ];
}

function dr_format_thai_date(string $date): string
{
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $days   = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
    $ts = strtotime($date);
    return $days[date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . (date('Y', $ts) + 543);
}

function dr_delta_str(int $today, int $yest): string
{
    $diff = $today - $yest;
    if ($diff === 0) return '→ เท่าเดิม';
    return $diff > 0 ? "↑ +{$diff}" : "↓ {$diff}";
}

function dr_delta_color(int $today, int $yest, bool $higherIsBetter): string
{
    $diff = $today - $yest;
    if ($diff === 0) return '#94a3b8';
    if ($higherIsBetter)  return $diff > 0 ? '#16a34a' : '#dc2626';
    return $diff > 0 ? '#dc2626' : '#16a34a';
}

try {
    $pdo = db();
    $today_stats = dr_fetch_stats($pdo, $date);
    $yest_stats  = dr_fetch_stats($pdo, $yesterday);
} catch (Throwable $e) {
    echo "ERROR: query failed — " . $e->getMessage() . "\n";
    exit;
}

$total_came   = $today_stats['on_schedule'] + $today_stats['early_arrival'];
$no_show_rate = $today_stats['total_scheduled'] > 0
    ? round($today_stats['no_show'] / $today_stats['total_scheduled'] * 100, 1) : 0.0;

echo "stats: on={$today_stats['on_schedule']} early={$today_stats['early_arrival']} "
   . "no_show={$today_stats['no_show']} cancel={$today_stats['cancelled_count']} "
   . "total={$today_stats['total_scheduled']} no_show_rate={$no_show_rate}%\n";

// ── Skip when there's no activity at all (use ?force=1 to override) ──────
$activity_sum = $today_stats['on_schedule']
              + $today_stats['early_arrival']
              + $today_stats['no_show']
              + $today_stats['cancelled_count']
              + $today_stats['total_scheduled'];
if ($activity_sum === 0 && !$force) {
    echo "SKIPPED: ไม่มีแคมเปญ/นัดหมายในวันนี้ — ไม่ส่งเข้ากลุ่ม (ใช้ ?force=1 เพื่อบังคับส่ง)\n";
    if (function_exists('log_activity')) {
        @log_activity('Daily Report Push', "Skipped (no activity) date=$date");
    }
    $elapsed = round(microtime(true) - $startedAt, 2);
    echo "DONE in {$elapsed}s\n";
    exit;
}

// ── Top 3 campaigns of the day (สำหรับใส่ใน body) ────────────────────────
$topCampaigns = [];
try {
    $cstmt = $pdo->prepare("
        SELECT cl.title,
               COUNT(b.id) AS booked,
               SUM(CASE WHEN b.attended_at IS NOT NULL
                         AND b.status NOT IN ('cancelled','cancelled_by_admin') THEN 1 ELSE 0 END) AS attended
        FROM camp_bookings b
        JOIN camp_slots s  ON b.slot_id = s.id
        JOIN camp_list  cl ON b.campaign_id = cl.id
        WHERE s.slot_date = :d
        GROUP BY cl.id, cl.title
        ORDER BY booked DESC
        LIMIT 3
    ");
    $cstmt->execute([':d' => $date]);
    $topCampaigns = $cstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* non-critical */ }

// ── Build Flex Message ───────────────────────────────────────────────────
$siteName = defined('SITE_NAME') ? SITE_NAME : 'RSU Medical Clinic';
$reportUrl = 'https://healthycampus.rsu.ac.th/rsu-clinic/admin/daily_report.php?date=' . urlencode($date);

function dr_kpi_box(string $label, int $value, int $yestValue, bool $higherIsBetter, string $unit = ''): array
{
    $color = dr_delta_color($value, $yestValue, $higherIsBetter);
    $delta = dr_delta_str($value, $yestValue);
    return [
        'type' => 'box', 'layout' => 'vertical', 'flex' => 1,
        'spacing' => 'xs',
        'contents' => [
            ['type' => 'text', 'text' => $label,
             'size' => 'xxs', 'color' => '#64748b', 'weight' => 'bold', 'wrap' => true],
            ['type' => 'text', 'text' => number_format($value) . $unit,
             'size' => 'xl', 'color' => '#0f172a', 'weight' => 'bold'],
            ['type' => 'text', 'text' => $delta,
             'size' => 'xxs', 'color' => $color, 'weight' => 'bold'],
        ],
    ];
}

$bodyContents = [
    // Title row
    ['type' => 'text', 'text' => '📊 รายงานสรุปประจำวัน',
     'weight' => 'bold', 'size' => 'lg', 'color' => '#0f172a'],
    ['type' => 'text', 'text' => dr_format_thai_date($date),
     'size' => 'sm', 'color' => '#64748b', 'weight' => 'bold', 'margin' => 'xs'],
    ['type' => 'separator', 'margin' => 'md'],

    // KPI row 1: on_schedule, early_arrival
    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'spacing' => 'md',
     'contents' => [
        dr_kpi_box('✅ มาตรงนัด',    $today_stats['on_schedule'],   $yest_stats['on_schedule'],   true),
        dr_kpi_box('⏰ มาก่อนนัด',    $today_stats['early_arrival'], $yest_stats['early_arrival'], true),
     ]],

    // KPI row 2: no_show, cancelled
    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'spacing' => 'md',
     'contents' => [
        dr_kpi_box('❌ ไม่มาตามนัด',  $today_stats['no_show'],         $yest_stats['no_show'],         false),
        dr_kpi_box('🚫 ยกเลิก',       $today_stats['cancelled_count'], $yest_stats['cancelled_count'], false),
     ]],

    ['type' => 'separator', 'margin' => 'md'],

    // Bottom summary line
    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md', 'spacing' => 'sm',
     'contents' => [
        ['type' => 'text', 'text' => 'รวมที่นัดวันนี้', 'size' => 'sm', 'color' => '#64748b', 'flex' => 5, 'weight' => 'bold'],
        ['type' => 'text', 'text' => number_format($today_stats['total_scheduled']) . ' คน',
         'size' => 'sm', 'color' => '#0f172a', 'weight' => 'bold', 'align' => 'end', 'flex' => 3],
     ]],
    ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'spacing' => 'sm',
     'contents' => [
        ['type' => 'text', 'text' => 'อัตรา No-show', 'size' => 'sm', 'color' => '#64748b', 'flex' => 5, 'weight' => 'bold'],
        ['type' => 'text', 'text' => number_format($no_show_rate, 1) . ' %',
         'size' => 'sm',
         'color' => $no_show_rate >= 20 ? '#dc2626' : ($no_show_rate >= 10 ? '#f59e0b' : '#16a34a'),
         'weight' => 'bold', 'align' => 'end', 'flex' => 3],
     ]],
];

// Top campaigns (ถ้ามีหลาย campaign)
if (count($topCampaigns) >= 2) {
    $bodyContents[] = ['type' => 'separator', 'margin' => 'md'];
    $bodyContents[] = ['type' => 'text', 'text' => 'Top Campaigns วันนี้',
                       'size' => 'xs', 'color' => '#64748b', 'weight' => 'bold', 'margin' => 'md'];
    foreach ($topCampaigns as $c) {
        $title    = mb_substr((string)$c['title'], 0, 28);
        $attended = (int)$c['attended'];
        $booked   = (int)$c['booked'];
        $bodyContents[] = [
            'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm', 'spacing' => 'sm',
            'contents' => [
                ['type' => 'text', 'text' => '· ' . $title, 'size' => 'xs', 'color' => '#1e293b',
                 'flex' => 6, 'weight' => 'regular', 'wrap' => false],
                ['type' => 'text', 'text' => $attended . '/' . $booked,
                 'size' => 'xs', 'color' => '#475569', 'weight' => 'bold', 'align' => 'end', 'flex' => 2],
            ],
        ];
    }
}

$flex = [
    'type'    => 'flex',
    'altText' => "รายงานสรุป $date · มา $total_came · ไม่มา {$today_stats['no_show']} · ยกเลิก {$today_stats['cancelled_count']}",
    'contents' => [
        'type' => 'bubble', 'size' => 'mega',
        'body' => [
            'type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm',
            'paddingAll' => 'lg',
            'contents' => $bodyContents,
        ],
        'footer' => [
            'type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm',
            'paddingAll' => 'md',
            'contents' => [[
                'type' => 'button', 'style' => 'primary', 'height' => 'sm',
                'color' => '#2e9e63',
                'action' => [
                    'type' => 'uri',
                    'label' => '🔍 ดูรายงานเต็ม',
                    'uri'   => $reportUrl,
                ],
            ]],
        ],
        'styles' => ['footer' => ['separator' => true]],
    ],
];

// ── Resolve target group + send ──────────────────────────────────────────
$groupId = $overrideGroup !== '' ? $overrideGroup : line_groups_get_default($pdo);

// Token: รองรับทั้ง constant (ถ้า line_config.php ถูก load) และอ่านตรงจาก secrets
$token = defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ? (string)LINE_MESSAGING_CHANNEL_ACCESS_TOKEN : '';
if ($token === '') {
    $secretsFile = __DIR__ . '/../config/secrets.php';
    if (is_file($secretsFile)) {
        $secrets = require $secretsFile;
        if (is_array($secrets)) {
            $token = (string)($secrets['EBORROW_LINE_MESSAGE_TOKEN']
                            ?? $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN']
                            ?? '');
        }
    }
}

if ($groupId === '') {
    echo "ERROR: ยังไม่ได้ตั้งค่า default groupId — ตั้งผ่าน portal → ตั้งค่า LINE → กลุ่มเริ่มต้น\n";
    exit;
}
if ($token === '') {
    echo "ERROR: LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ยังไม่ได้ตั้งค่า\n";
    exit;
}

echo "target group: " . substr($groupId, 0, 8) . "…\n";

if ($dryrun) {
    echo "─── DRY RUN — Flex payload (truncated) ───\n";
    $preview = json_encode($flex, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    echo substr((string)$preview, 0, 2000) . "\n";
    echo "─── (full size: " . strlen((string)$preview) . " bytes) ───\n";
    $elapsed = round(microtime(true) - $startedAt, 2);
    echo "DONE in {$elapsed}s\n";
    exit;
}

$ok = send_line_group_push($groupId, [$flex], $token);

$elapsed = round(microtime(true) - $startedAt, 2);
echo "─────────────────────────────────────\n";
echo "DONE in {$elapsed}s — " . ($ok ? "SUCCESS ✓" : "FAILED ✗") . "\n";

if (function_exists('log_activity')) {
    @log_activity(
        'Daily Report Push',
        sprintf(
            'Cron daily_report_to_line: date=%s on=%d early=%d no_show=%d cancel=%d total=%d (%s)',
            $date,
            $today_stats['on_schedule'],
            $today_stats['early_arrival'],
            $today_stats['no_show'],
            $today_stats['cancelled_count'],
            $today_stats['total_scheduled'],
            $ok ? 'OK' : 'FAIL'
        )
    );
}
