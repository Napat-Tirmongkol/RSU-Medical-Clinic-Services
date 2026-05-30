<?php
/**
 * Morning Brief — delivery helpers (LINE Flex builder · Email HTML builder · senders).
 *
 * แยกออกมาจาก cron/morning_brief.php เพื่อให้ AJAX endpoint (test_send / preview)
 * ใช้ฟังก์ชันเดียวกันได้ — output ในทุก channel ตรงกัน ไม่หลุด pattern
 *
 * Pure functions: ไม่มี top-level execution. ใช้ได้ทั้ง cron และ portal scope.
 */
declare(strict_types=1);

require_once __DIR__ . '/line_helper.php';
require_once __DIR__ . '/mail_helper.php';

function mb_already_delivered(PDO $pdo, int $briefId, int $sid, string $stype, string $chan): bool {
    $st = $pdo->prepare("SELECT 1 FROM sys_morning_brief_delivery
        WHERE brief_id=:b AND staff_id=:s AND staff_type=:t AND channel=:c AND status='sent' LIMIT 1");
    $st->execute([':b'=>$briefId, ':s'=>$sid, ':t'=>$stype, ':c'=>$chan]);
    return (bool)$st->fetchColumn();
}

function mb_log_delivery(PDO $pdo, int $briefId, int $sid, string $stype, string $chan, string $status, ?string $err): void {
    try {
        $st = $pdo->prepare("INSERT INTO sys_morning_brief_delivery
            (brief_id, staff_id, staff_type, channel, status, error_msg, sent_at)
            VALUES (:b, :s, :t, :c, :st, :e, NOW())
            ON DUPLICATE KEY UPDATE status=:st2, error_msg=:e2, sent_at=NOW()");
        $st->execute([':b'=>$briefId, ':s'=>$sid, ':t'=>$stype, ':c'=>$chan,
            ':st'=>$status, ':e'=>$err, ':st2'=>$status, ':e2'=>$err]);
    } catch (PDOException) {}
}

function mb_build_line_flex(array $brief, array $priorities, bool $isTest = false): array {
    $data = $brief['data'] ?? [];
    $clinic = $data['clinic'] ?? [];
    $camp = $data['campaign'] ?? [];
    $sch = $data['scholarship'] ?? [];

    $urgencyColor = [
        'critical' => '#dc2626', 'high' => '#ea580c',
        'normal' => '#0891b2',   'low' => '#16a34a',
    ];
    $urgency = $brief['urgency_level'] ?? 'normal';
    $color = $urgencyColor[$urgency] ?? '#0891b2';

    $bodyContents = [];

    if ($isTest) {
        $bodyContents[] = [
            'type' => 'text', 'text' => '🧪 ข้อความทดสอบ — ส่งจากปุ่ม "ทดสอบส่ง" ในหน้าตั้งค่า',
            'size' => 'xxs', 'color' => '#dc2626', 'weight' => 'bold', 'wrap' => true,
        ];
        $bodyContents[] = ['type' => 'separator', 'margin' => 'sm'];
    }

    $narrative = $brief['ai_narrative'] ?: 'ยังไม่มีข้อมูลสรุป';
    $bodyContents[] = [
        'type' => 'text', 'text' => $narrative,
        'size' => 'sm', 'color' => '#475569', 'wrap' => true, 'margin' => $isTest ? 'sm' : 'none',
    ];

    // Doctor schedule block (ถ้ามี)
    if (!empty($clinic['doctors_today_list'])) {
        $bodyContents[] = ['type' => 'separator', 'margin' => 'md'];
        $bodyContents[] = [
            'type' => 'text',
            'text' => 'แพทย์ออกตรวจวันนี้ (' . count($clinic['doctors_today_list']) . ' ท่าน)',
            'size' => 'xs', 'weight' => 'bold', 'color' => '#3730a3', 'margin' => 'md',
        ];
        foreach (array_slice($clinic['doctors_today_list'], 0, 6) as $d) {
            $line = ($d['time'] ?? '') . ' ' . ($d['name'] ?? '');
            if (!empty($d['room'])) $line .= ' @' . $d['room'];
            $bodyContents[] = [
                'type' => 'text', 'text' => $line,
                'size' => 'xs', 'color' => '#475569', 'wrap' => true, 'margin' => 'sm',
            ];
        }
        if (count($clinic['doctors_today_list']) > 6) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => '… และอีก ' . (count($clinic['doctors_today_list']) - 6) . ' ท่าน',
                'size' => 'xxs', 'color' => '#94a3b8', 'margin' => 'xs',
            ];
        }
    }

    // Top campaigns block (ถ้ามี)
    if (!empty($camp['top_campaigns'])) {
        $bodyContents[] = ['type' => 'separator', 'margin' => 'md'];
        $bodyContents[] = [
            'type' => 'text', 'text' => 'แคมเปญวันนี้ (Top ' . count($camp['top_campaigns']) . ')',
            'size' => 'xs', 'weight' => 'bold', 'color' => '#9a3412', 'margin' => 'md',
        ];
        foreach ($camp['top_campaigns'] as $tc) {
            $bodyContents[] = [
                'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
                'contents' => [
                    ['type' => 'text', 'text' => '· ' . ($tc['title'] ?? ''),
                     'size' => 'xs', 'color' => '#0f172a', 'wrap' => true, 'flex' => 5],
                    ['type' => 'text', 'text' => ($tc['booked'] ?? 0) . ' คน',
                     'size' => 'xs', 'color' => '#64748b', 'align' => 'end', 'flex' => 2],
                ],
            ];
        }
        if (!empty($camp['yesterday_no_show_rate']) && $camp['yesterday_no_show_rate'] > 0) {
            $bodyContents[] = [
                'type' => 'text',
                'text' => 'ขาดนัดเมื่อวาน ' . $camp['yesterday_no_show'] . '/' . $camp['yesterday_scheduled']
                          . ' (' . $camp['yesterday_no_show_rate'] . '%)',
                'size' => 'xxs',
                'color' => ($camp['yesterday_no_show_rate'] > 15 ? '#dc2626' : '#94a3b8'),
                'margin' => 'sm',
            ];
        }
    }

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

    // KPI footer — prefer campaign today_scheduled if > 0
    $apptToday = (int)($camp['today_scheduled'] ?? 0) > 0
        ? (int)$camp['today_scheduled']
        : (int)($clinic['appointments_today'] ?? 0);

    $bodyContents[] = ['type' => 'separator', 'margin' => 'lg'];
    $bodyContents[] = [
        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
        'contents' => [
            _mb_flex_kpi_box('รออนุมัติ', (string)(int)($sch['pending_approvals'] ?? 0)),
            _mb_flex_kpi_box('กะวันนี้', (string)(int)($sch['today_shifts'] ?? 0)),
            _mb_flex_kpi_box('นัดแคมเปญ', (string)$apptToday),
        ],
    ];

    $title = ($isTest ? '[ทดสอบ] ' : '') . 'Morning Brief';
    return [
        'type' => 'flex',
        'altText' => $title . ' — ' . ($clinic['date_thai'] ?? date('Y-m-d')),
        'contents' => [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg',
                'backgroundColor' => $color,
                'contents' => [
                    ['type' => 'text', 'text' => $title,
                     'color' => '#ffffff', 'size' => 'lg', 'weight' => 'bold'],
                    ['type' => 'text',
                     'text' => ($clinic['date_thai'] ?? '') . ' · วัน' . ($clinic['weekday_thai'] ?? ''),
                     'color' => '#ffffff', 'size' => 'xs', 'margin' => 'xs'],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg', 'spacing' => 'sm',
                'contents' => $bodyContents,
            ],
        ],
    ];
}

function _mb_flex_kpi_box(string $label, string $value): array {
    return [
        'type' => 'box', 'layout' => 'vertical', 'flex' => 1,
        'contents' => [
            ['type' => 'text', 'text' => $label, 'size' => 'xxs', 'color' => '#94a3b8', 'align' => 'center'],
            ['type' => 'text', 'text' => $value, 'size' => 'lg', 'weight' => 'bold', 'color' => '#0f172a', 'align' => 'center', 'margin' => 'xs'],
        ],
    ];
}

function mb_build_email_html(array $brief, array $priorities, bool $isTest = false): string {
    $data = $brief['data'] ?? [];
    $clinic = $data['clinic'] ?? [];
    $camp = $data['campaign'] ?? [];
    $sch = $data['scholarship'] ?? [];
    $edms = $data['edms'] ?? [];

    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $titlePrefix = $isTest ? '[ทดสอบ] ' : '';
    $title = $titlePrefix . 'Morning Brief — ' . $h($clinic['date_thai'] ?? date('Y-m-d'));
    $narrative = $h($brief['ai_narrative'] ?? '');

    $testBanner = $isTest
        ? '<div style="margin:0 0 1rem;padding:.75rem 1rem;background:#fef2f2;border-left:3px solid #dc2626;border-radius:6px;color:#991b1b;font-size:13px"><b>🧪 ข้อความทดสอบ</b> — ส่งจากปุ่ม "ทดสอบส่ง" ในหน้าตั้งค่า</div>'
        : '';

    $priHtml = '';
    foreach ($priorities as $p) {
        $priHtml .= '<li style="margin-bottom:.75rem">'
            . '<b>' . $h($p['title'] ?? '') . '</b><br>'
            . '<span style="color:#64748b;font-size:14px">' . $h($p['detail'] ?? '') . '</span>'
            . '</li>';
    }

    // Doctor schedule block
    $docHtml = '';
    if (!empty($clinic['doctors_today_list'])) {
        $docHtml = '<div style="margin-top:1.5rem;padding:1rem;background:#eef2ff;border-left:3px solid #6366f1;border-radius:6px">'
            . '<h3 style="margin:0 0 .65rem;font-size:13px;color:#3730a3">แพทย์ออกตรวจวันนี้ (' . count($clinic['doctors_today_list']) . ' ท่าน)</h3>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        foreach ($clinic['doctors_today_list'] as $d) {
            $name = $h($d['name'] ?? '');
            if (!empty($d['is_override'])) {
                $name .= ' <span style="display:inline-block;padding:0 4px;background:#fed7aa;color:#9a3412;font-size:10px;border-radius:3px">พิเศษ</span>';
            }
            $room = !empty($d['room']) ? '<span style="color:#64748b">@ ' . $h($d['room']) . '</span>' : '';
            $docHtml .= '<tr>'
                . '<td style="padding:.2rem 0;color:#3730a3;font-variant-numeric:tabular-nums;width:100px;white-space:nowrap">' . $h($d['time'] ?? '') . '</td>'
                . '<td style="padding:.2rem 0;color:#0f172a">' . $name . ' ' . $room . '</td>'
                . '</tr>';
        }
        $docHtml .= '</table></div>';
    }

    // Campaign block (Top 3 + no-show)
    $campHtml = '';
    if (!empty($camp['top_campaigns'])) {
        $campHtml = '<div style="margin-top:1.5rem;padding:1rem;background:#fff7ed;border-left:3px solid #ea580c;border-radius:6px">'
            . '<h3 style="margin:0 0 .65rem;font-size:13px;color:#9a3412">แคมเปญวันนี้</h3>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        foreach ($camp['top_campaigns'] as $tc) {
            $campHtml .= '<tr><td style="padding:.2rem 0;color:#0f172a">· ' . $h($tc['title'] ?? '') . '</td>'
                . '<td style="padding:.2rem 0;text-align:right;color:#64748b;white-space:nowrap">' . (int)($tc['booked'] ?? 0) . ' คน</td></tr>';
        }
        $campHtml .= '</table>';
        if (!empty($camp['yesterday_no_show_rate']) && $camp['yesterday_no_show_rate'] > 0) {
            $nsColor = $camp['yesterday_no_show_rate'] > 15 ? '#dc2626' : '#94a3b8';
            $campHtml .= '<p style="margin:.6rem 0 0;font-size:11px;color:' . $nsColor . '">'
                . 'ขาดนัดเมื่อวาน ' . (int)$camp['yesterday_no_show'] . '/' . (int)$camp['yesterday_scheduled']
                . ' (' . $camp['yesterday_no_show_rate'] . '%)</p>';
        }
        $campHtml .= '</div>';
    }

    // KPI footer — prefer campaign data
    $apptToday = (int)($camp['today_scheduled'] ?? 0) > 0
        ? (int)$camp['today_scheduled']
        : (int)($clinic['appointments_today'] ?? 0);

    $statsHtml = '<table style="width:100%;border-collapse:collapse;margin-top:1rem">'
        . '<tr>'
        . '<td style="padding:.5rem;background:#f8fafc;text-align:center"><div style="font-size:11px;color:#64748b">รออนุมัติ</div><div style="font-size:18px;font-weight:bold">' . (int)($sch['pending_approvals'] ?? 0) . '</div></td>'
        . '<td style="padding:.5rem;background:#f8fafc;text-align:center"><div style="font-size:11px;color:#64748b">กะวันนี้</div><div style="font-size:18px;font-weight:bold">' . (int)($sch['today_shifts'] ?? 0) . '</div></td>'
        . '<td style="padding:.5rem;background:#f8fafc;text-align:center"><div style="font-size:11px;color:#64748b">งาน EDMS</div><div style="font-size:18px;font-weight:bold">' . (int)($edms['tasks_due_today'] ?? 0) . '</div></td>'
        . '<td style="padding:.5rem;background:#f8fafc;text-align:center"><div style="font-size:11px;color:#64748b">นัดแคมเปญ</div><div style="font-size:18px;font-weight:bold">' . $apptToday . '</div></td>'
        . '</tr></table>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $title . '</title></head>'
        . '<body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#f8fafc;padding:1rem;margin:0">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;padding:2rem;border:1px solid #e2e8f0">'
        . $testBanner
        . '<h1 style="margin:0 0 .5rem;color:#0f172a;font-size:20px">' . $title . '</h1>'
        . '<p style="margin:0 0 1rem;color:#64748b;font-size:13px">' . $h($clinic['weekday_thai'] ?? '') . '</p>'
        . '<p style="line-height:1.6;color:#334155">' . $narrative . '</p>'
        . ($priHtml ? '<h3 style="font-size:14px;color:#0f172a;margin:1.5rem 0 .5rem">สิ่งที่ต้องทำ</h3><ul style="padding-left:1.25rem">' . $priHtml . '</ul>' : '')
        . $docHtml
        . $campHtml
        . $statsHtml
        . '<p style="margin-top:1.5rem;font-size:11px;color:#94a3b8;text-align:center">RSU Medical Clinic Services · ' . $h($brief['ai_model'] ?? '') . '</p>'
        . '</div></body></html>';
}

function mb_send_email(string $to, string $subject, string $htmlBody): bool {
    // ใช้ send_campaign_email() จาก mail_helper — มี SMTP support + email log
    // (smtp_send ถ้ามี config, fallback ไป PHP mail() เอง)
    return send_campaign_email($to, $subject, $htmlBody, 'morning_brief');
}

/**
 * Check email pre-requisites — คืน null ถ้า OK, คืน string error ถ้าไม่ OK.
 * ใช้ใน test_send action เพื่อบอก user ก่อนยิงว่า config อะไรขาดอยู่
 */
function mb_check_email_config(): ?string {
    $secrets = function_exists('get_secrets') ? get_secrets() : [];
    $host = $secrets['SMTP_HOST'] ?? '';
    if (empty($host) || empty($secrets['SMTP_USER']) || empty($secrets['SMTP_PASS'])) {
        // ไม่มี SMTP — ดูว่า mail() ใช้งานได้ไหม
        if (!function_exists('mail')) return 'ระบบไม่มีฟังก์ชัน mail() · ต้องตั้ง SMTP ใน config/secrets.php';
        $sm = ini_get('sendmail_path');
        if (empty($sm)) return 'ระบบยังไม่ได้ตั้ง SMTP · เปิด portal/smtp_settings.php เพื่อตั้งค่า SMTP_HOST/USER/PASS';
        return null; // mail() น่าจะใช้ได้ — ลองส่งดู
    }
    return null;
}

function mb_resolve_line_token(): string {
    $token = defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ? (string)LINE_MESSAGING_CHANNEL_ACCESS_TOKEN : '';
    if ($token !== '') return $token;
    $secretsFile = __DIR__ . '/../config/secrets.php';
    if (is_file($secretsFile)) {
        $s = require $secretsFile;
        if (is_array($s)) {
            return (string)($s['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? $s['EBORROW_LINE_MESSAGE_TOKEN'] ?? '');
        }
    }
    return '';
}
