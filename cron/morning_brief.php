<?php
/**
 * cron/morning_brief.php
 * ──────────────────────────────────────────────────────────────────────────────
 * รัน Morning Brief delivery ทุกชั่วโมง — สำหรับ user ที่ตั้ง delivery_hour ตรงกับ
 * ชั่วโมงปัจจุบัน + มี channel เปิด (LINE หรือ Email) จะถูกส่ง brief ของวันนั้น
 *
 * Pattern: zhao + idempotent (ส่งครั้งเดียวต่อ user/วัน/channel ผ่าน
 * sys_morning_brief_delivery UNIQUE KEY uniq_brief_staff_chan)
 *
 * ── การตั้งค่า cron ──────────────────────────────────────────────────────────
 *  URL     : https://<host>/portal/../cron/morning_brief.php?token=YOUR_SECRET
 *  Schedule: ทุกชั่วโมง (0 * * * *)  — สคริปต์จะ filter user ที่ตั้ง delivery_hour
 *            ตรงกับชั่วโมงนั้น ๆ
 *
 *  Optional params:
 *    ?hour=8       ── ทดสอบ ส่งเฉพาะ user ที่ตั้ง 08:00
 *    ?dryrun=1     ── ไม่ยิงจริง · พิมพ์ payload ที่จะส่ง
 *    ?force=1      ── ส่งซ้ำได้แม้เคยส่งแล้ว
 *
 * ── เงื่อนไขก่อนใช้ ──────────────────────────────────────────────────────────
 *  1. LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ตั้งค่าไว้ (เหมือนกับ daily_report)
 *  2. user ใน sys_morning_brief_prefs ต้องมี line_user_id ที่ถูกต้อง
 *     (รูปแบบ Uxxxxxxxx) สำหรับ channel_line
 *  3. user ต้องมี email สำหรับ channel_email · ระบบใช้ PHP mail() เป็น fallback
 *     เริ่มต้น — ถ้ามี SMTP config แยกค่อยปรับตอนหลัง
 * ─────────────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

define('CRON_SECRET_TOKEN', 'rsu_morning_brief_h9k3m2p4w');

$token = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
if (!hash_equals(CRON_SECRET_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Bangkok');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/line_helper.php';
require_once __DIR__ . '/../portal/queries/morning_brief_queries.php';
require_once __DIR__ . '/../portal/services/morning_brief_ai.php';

// ─── Params ─────────────────────────────────────────────────────────────────
$dryrun = !empty($_GET['dryrun']);
$force  = !empty($_GET['force']);
$hour   = isset($_GET['hour']) ? (int)$_GET['hour'] : (int)date('G');

$today = date('Y-m-d');
$startedAt = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] morning_brief cron — hour={$hour}" . ($dryrun ? ' (DRY RUN)' : '') . "\n";

$pdo = db();
ensure_morning_brief_schema($pdo);

// ─── Resolve LINE token ─────────────────────────────────────────────────────
$lineToken = defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ? (string)LINE_MESSAGING_CHANNEL_ACCESS_TOKEN : '';
if ($lineToken === '') {
    $secretsFile = __DIR__ . '/../config/secrets.php';
    if (is_file($secretsFile)) {
        $s = require $secretsFile;
        if (is_array($s)) $lineToken = (string)($s['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? $s['EBORROW_LINE_MESSAGE_TOKEN'] ?? '');
    }
}

// ─── Ensure brief exists for today ──────────────────────────────────────────
$brief = morning_brief_get_for_date($pdo, $today);
if (!$brief) {
    echo "  → brief สำหรับ {$today} ยังไม่มี · กำลังสร้าง...\n";
    $data = morning_brief_collect_all($pdo, $today);
    $narrative = morning_brief_generate_narrative($data);
    $data['_ai_priorities'] = $narrative['priorities'] ?? [];
    morning_brief_save($pdo, $today, $data,
        $narrative['narrative'] ?? null, $narrative['model'] ?? null,
        'cron', $narrative['urgency'] ?? 'normal');
    $brief = morning_brief_get_for_date($pdo, $today);
    echo "  → สร้างแล้ว · model=" . ($narrative['model'] ?? 'none') . "\n";
}
$briefId = (int)$brief['id'];
$briefData = $brief['data'] ?? [];
$priorities = $briefData['_ai_priorities'] ?? [];

// ─── Find users to deliver to ───────────────────────────────────────────────
$st = $pdo->prepare("SELECT * FROM sys_morning_brief_prefs
    WHERE delivery_hour = :h AND (channel_line = 1 OR channel_email = 1)");
$st->execute([':h' => $hour]);
$prefs = $st->fetchAll(PDO::FETCH_ASSOC);
echo "พบ " . count($prefs) . " user(s) ที่ตั้ง delivery_hour=" . sprintf('%02d:00', $hour) . "\n\n";

$sent = ['line' => 0, 'email' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($prefs as $pref) {
    $sid = (int)$pref['staff_id'];
    $stype = $pref['staff_type'];
    echo "[staff_id={$sid} type={$stype}]\n";

    // ── LINE channel ─────────────────────────────────────────────────────
    if (!empty($pref['channel_line']) && !empty($pref['line_user_id'])) {
        if (!$force && _mb_already_delivered($pdo, $briefId, $sid, $stype, 'line')) {
            echo "  LINE → SKIPPED (already delivered)\n";
            $sent['skipped']++;
        } elseif (!$lineToken) {
            echo "  LINE → FAILED (no token)\n";
            $sent['failed']++;
            _mb_log_delivery($pdo, $briefId, $sid, $stype, 'line', 'failed', 'no_token');
        } else {
            $flex = _mb_build_line_flex($brief, $priorities);
            if ($dryrun) {
                echo "  LINE → DRY RUN (would push to {$pref['line_user_id']})\n";
                echo "    payload: " . json_encode($flex, JSON_UNESCAPED_UNICODE) . "\n";
            } else {
                $ok = send_line_push($pref['line_user_id'], [$flex], $lineToken);
                if ($ok) {
                    echo "  LINE → SENT to {$pref['line_user_id']}\n";
                    $sent['line']++;
                    _mb_log_delivery($pdo, $briefId, $sid, $stype, 'line', 'sent', null);
                } else {
                    echo "  LINE → FAILED · " . get_last_line_error() . "\n";
                    $sent['failed']++;
                    _mb_log_delivery($pdo, $briefId, $sid, $stype, 'line', 'failed', get_last_line_error());
                }
            }
        }
    }

    // ── Email channel ────────────────────────────────────────────────────
    if (!empty($pref['channel_email']) && !empty($pref['email'])) {
        if (!$force && _mb_already_delivered($pdo, $briefId, $sid, $stype, 'email')) {
            echo "  EMAIL → SKIPPED (already delivered)\n";
            $sent['skipped']++;
        } else {
            $subject = 'Morning Brief — ' . ($briefData['clinic']['date_thai'] ?? $today);
            $body = _mb_build_email_html($brief, $priorities);
            if ($dryrun) {
                echo "  EMAIL → DRY RUN (would send to {$pref['email']})\n";
            } else {
                $ok = _mb_send_email($pref['email'], $subject, $body);
                if ($ok) {
                    echo "  EMAIL → SENT to {$pref['email']}\n";
                    $sent['email']++;
                    _mb_log_delivery($pdo, $briefId, $sid, $stype, 'email', 'sent', null);
                } else {
                    echo "  EMAIL → FAILED · mail() returned false\n";
                    $sent['failed']++;
                    _mb_log_delivery($pdo, $briefId, $sid, $stype, 'email', 'failed', 'mail_failed');
                }
            }
        }
    }
}

$elapsed = number_format(microtime(true) - $startedAt, 2);
echo "\n=== สรุป ===\n";
echo "LINE sent  : {$sent['line']}\n";
echo "Email sent : {$sent['email']}\n";
echo "Skipped    : {$sent['skipped']}\n";
echo "Failed     : {$sent['failed']}\n";
echo "เวลา       : {$elapsed}s\n";

// ─── Helpers ────────────────────────────────────────────────────────────────

function _mb_already_delivered(PDO $pdo, int $briefId, int $sid, string $stype, string $chan): bool {
    $st = $pdo->prepare("SELECT 1 FROM sys_morning_brief_delivery
        WHERE brief_id=:b AND staff_id=:s AND staff_type=:t AND channel=:c AND status='sent' LIMIT 1");
    $st->execute([':b'=>$briefId, ':s'=>$sid, ':t'=>$stype, ':c'=>$chan]);
    return (bool)$st->fetchColumn();
}

function _mb_log_delivery(PDO $pdo, int $briefId, int $sid, string $stype, string $chan, string $status, ?string $err): void {
    try {
        $st = $pdo->prepare("INSERT INTO sys_morning_brief_delivery
            (brief_id, staff_id, staff_type, channel, status, error_msg, sent_at)
            VALUES (:b, :s, :t, :c, :st, :e, NOW())
            ON DUPLICATE KEY UPDATE status=:st2, error_msg=:e2, sent_at=NOW()");
        $st->execute([':b'=>$briefId, ':s'=>$sid, ':t'=>$stype, ':c'=>$chan,
            ':st'=>$status, ':e'=>$err, ':st2'=>$status, ':e2'=>$err]);
    } catch (PDOException) {}
}

function _mb_build_line_flex(array $brief, array $priorities): array {
    $data = $brief['data'] ?? [];
    $clinic = $data['clinic'] ?? [];
    $sch = $data['scholarship'] ?? [];

    $urgencyColor = [
        'critical' => '#dc2626', 'high' => '#ea580c',
        'normal' => '#0891b2',   'low' => '#16a34a',
    ];
    $urgency = $brief['urgency_level'] ?? 'normal';
    $color = $urgencyColor[$urgency] ?? '#0891b2';

    $bodyContents = [];

    $narrative = $brief['ai_narrative'] ?: 'ยังไม่มีข้อมูลสรุป';
    $bodyContents[] = [
        'type' => 'text', 'text' => $narrative,
        'size' => 'sm', 'color' => '#475569', 'wrap' => true,
    ];

    if ($priorities) {
        $bodyContents[] = ['type' => 'separator', 'margin' => 'md'];
        foreach (array_slice($priorities, 0, 4) as $p) {
            $bodyContents[] = [
                'type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'xs',
                'contents' => [
                    ['type' => 'text', 'text' => '• ' . ($p['title'] ?? ''),
                     'size' => 'sm', 'weight' => 'bold', 'color' => '#0f172a', 'wrap' => true],
                    ['type' => 'text', 'text' => $p['detail'] ?? '',
                     'size' => 'xs', 'color' => '#64748b', 'wrap' => true],
                ],
            ];
        }
    }

    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
    $bodyContents[] = [
        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
        'contents' => [
            _mb_flex_kpi('รออนุมัติ', (string)(int)($sch['pending_approvals'] ?? 0)),
            _mb_flex_kpi('กะวันนี้', (string)(int)($sch['today_shifts'] ?? 0)),
            _mb_flex_kpi('นัดวันนี้', (string)(int)($clinic['appointments_today'] ?? 0)),
        ],
    ];

    return [
        'type' => 'flex',
        'altText' => 'Morning Brief — ' . ($clinic['date_thai'] ?? date('Y-m-d')),
        'contents' => [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg',
                'backgroundColor' => $color,
                'contents' => [
                    ['type' => 'text', 'text' => 'Morning Brief',
                     'color' => '#fff', 'size' => 'lg', 'weight' => 'bold'],
                    ['type' => 'text',
                     'text' => ($clinic['date_thai'] ?? '') . ' · วัน' . ($clinic['weekday_thai'] ?? ''),
                     'color' => '#fff', 'size' => 'xs', 'margin' => 'xs'],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg', 'spacing' => 'sm',
                'contents' => $bodyContents,
            ],
        ],
    ];
}

function _mb_flex_kpi(string $label, string $value): array {
    return [
        'type' => 'box', 'layout' => 'vertical', 'flex' => 1,
        'contents' => [
            ['type' => 'text', 'text' => $label, 'size' => 'xxs', 'color' => '#94a3b8', 'align' => 'center'],
            ['type' => 'text', 'text' => $value, 'size' => 'lg', 'weight' => 'bold', 'color' => '#0f172a', 'align' => 'center', 'margin' => 'xs'],
        ],
    ];
}

function _mb_build_email_html(array $brief, array $priorities): string {
    $data = $brief['data'] ?? [];
    $clinic = $data['clinic'] ?? [];
    $sch = $data['scholarship'] ?? [];
    $edms = $data['edms'] ?? [];

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $title = 'Morning Brief — ' . $h($clinic['date_thai'] ?? date('Y-m-d'));
    $narrative = $h($brief['ai_narrative'] ?? '');

    $priHtml = '';
    foreach ($priorities as $p) {
        $priHtml .= '<li style="margin-bottom:.75rem">'
            . '<b>' . $h($p['title'] ?? '') . '</b><br>'
            . '<span style="color:#64748b;font-size:14px">' . $h($p['detail'] ?? '') . '</span>'
            . '</li>';
    }

    $statsHtml = '<table style="width:100%;border-collapse:collapse;margin-top:1rem">'
        . '<tr>'
        . '<td style="padding:.5rem;background:#f8fafc;text-align:center"><div style="font-size:11px;color:#64748b">รออนุมัติ</div><div style="font-size:18px;font-weight:bold">' . (int)($sch['pending_approvals'] ?? 0) . '</div></td>'
        . '<td style="padding:.5rem;background:#f8fafc;text-align:center"><div style="font-size:11px;color:#64748b">กะวันนี้</div><div style="font-size:18px;font-weight:bold">' . (int)($sch['today_shifts'] ?? 0) . '</div></td>'
        . '<td style="padding:.5rem;background:#f8fafc;text-align:center"><div style="font-size:11px;color:#64748b">งาน EDMS</div><div style="font-size:18px;font-weight:bold">' . (int)($edms['tasks_due_today'] ?? 0) . '</div></td>'
        . '<td style="padding:.5rem;background:#f8fafc;text-align:center"><div style="font-size:11px;color:#64748b">นัดวันนี้</div><div style="font-size:18px;font-weight:bold">' . (int)($clinic['appointments_today'] ?? 0) . '</div></td>'
        . '</tr></table>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title></head>'
        . '<body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f8fafc;padding:1rem">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;padding:2rem;border:1px solid #e2e8f0">'
        . '<h1 style="margin:0 0 .5rem;color:#0f172a;font-size:20px">' . $title . '</h1>'
        . '<p style="margin:0 0 1rem;color:#64748b;font-size:13px">' . $h($clinic['weekday_thai'] ?? '') . '</p>'
        . '<p style="line-height:1.6;color:#334155">' . $narrative . '</p>'
        . ($priHtml ? '<h3 style="font-size:14px;color:#0f172a;margin:1.5rem 0 .5rem">สิ่งที่ต้องทำ</h3><ul style="padding-left:1.25rem">' . $priHtml . '</ul>' : '')
        . $statsHtml
        . '<p style="margin-top:1.5rem;font-size:11px;color:#94a3b8;text-align:center">RSU Medical Clinic Services · ' . $h($brief['ai_model'] ?? '') . '</p>'
        . '</div></body></html>';
}

function _mb_send_email(string $to, string $subject, string $htmlBody): bool {
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: RSU Medical Clinic <noreply@rsu.ac.th>',
    ];
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, implode("\r\n", $headers));
}
