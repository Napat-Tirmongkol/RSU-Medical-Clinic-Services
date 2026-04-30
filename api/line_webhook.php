<?php
/**
 * api/line_webhook.php
 * Endpoint สำหรับรับ Webhook จาก LINE Messaging API
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/line_helper.php';

// 1. รับข้อมูลจาก LINE
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

// 2. โหลด Config
$secrets = require __DIR__ . '/../config/secrets.php';
$channelSecret = $secrets['LINE_MESSAGING_CHANNEL_SECRET'] ?? '';
$accessToken   = $secrets['EBORROW_LINE_MESSAGE_TOKEN'] ?? $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';

function line_mask_uid(?string $uid): string
{
    if (!$uid) return '';
    if (strlen($uid) <= 12) return $uid;
    return substr($uid, 0, 8) . '...' . substr($uid, -6);
}

function line_webhook_log(string $message, array $context = [], string $level = 'info'): void
{
    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[LINE Webhook] ' . $message . ($contextJson ? ' ' . $contextJson : ''));

    if (function_exists('log_error_to_db')) {
        log_error_to_db($message, $level, 'api/line_webhook.php', $contextJson ?: '');
    }
}

function line_app_base_url(): string
{
    $proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
        ? $_SERVER['HTTP_X_FORWARDED_PROTO']
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/line_webhook.php'));
    $basePath = preg_replace('#/api$#', '', rtrim($dir, '/')) ?: '';
    return rtrim($proto . '://' . $host . $basePath, '/');
}

function is_insurance_request(array $event): bool
{
    if (($event['type'] ?? '') === 'postback') {
        $data = strtolower((string)($event['postback']['data'] ?? ''));
        parse_str($data, $params);
        return str_contains($data, 'insurance')
            || str_contains($data, 'ประกัน')
            || (($params['action'] ?? '') === 'insurance')
            || (($params['menu'] ?? '') === 'insurance');
    }

    if (($event['type'] ?? '') === 'message' && (($event['message']['type'] ?? '') === 'text')) {
        $text = mb_strtolower(trim((string)($event['message']['text'] ?? '')), 'UTF-8');
        return str_contains($text, 'insurance')
            || str_contains($text, 'ประกัน')
            || str_contains($text, 'อุบัติเหตุ');
    }

    return false;
}

function format_line_date(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

function reply_text_message(string $text): array
{
    return ['type' => 'text', 'text' => $text];
}

function build_insurance_flex_message(array $user, array $insurance): array
{
    $fullName = trim((string)($insurance['full_name'] ?? '')) ?: (string)($user['full_name'] ?? '-');
    $policyNo = trim((string)($insurance['policy_number'] ?? '')) ?: '-';
    $memberId = trim((string)($insurance['member_id'] ?? '')) ?: (string)($user['student_personnel_id'] ?? '-');
    $status = (string)($insurance['insurance_status'] ?? 'Inactive');
    $isActive = $status === 'Active';
    $coverageStart = format_line_date($insurance['coverage_start'] ?? null);
    $coverageEnd = format_line_date($insurance['coverage_end'] ?? null);
    $medicalLimit = '40,000';
    $baseUrl = line_app_base_url();

    return [
        'type' => 'flex',
        'altText' => 'ข้อมูลประกันอุบัติเหตุของคุณ',
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '20px',
                'backgroundColor' => '#FFF8FB',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'บัตรประกันอุบัติเหตุ', 'weight' => 'bold', 'size' => 'lg', 'color' => '#E11D48'],
                                    ['type' => 'text', 'text' => 'ส่วนบุคคล', 'weight' => 'bold', 'size' => 'md', 'color' => '#E11D48'],
                                ],
                                'flex' => 1,
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'contents' => [
                                    ['type' => 'text', 'text' => 'mtl', 'weight' => 'bold', 'size' => 'xxl', 'color' => '#F43F5E', 'align' => 'end'],
                                    ['type' => 'text', 'text' => 'MUANG THAI LIFE', 'weight' => 'bold', 'size' => 'xxs', 'color' => '#F43F5E', 'align' => 'end'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $isActive ? 'ACTIVE' : 'INACTIVE',
                        'size' => 'xxs',
                        'weight' => 'bold',
                        'color' => $isActive ? '#059669' : '#DC2626',
                        'align' => 'end',
                        'margin' => 'sm',
                    ],
                    ['type' => 'separator', 'margin' => 'lg', 'color' => '#FBCFE8'],
                    insurance_flex_row('เลขที่กรมธรรม์', $policyNo),
                    insurance_flex_row('ผู้เอาประกันภัย', $fullName),
                    insurance_flex_row('รหัสสมาชิก', $memberId),
                    insurance_flex_row('วันเริ่มคุ้มครอง', $coverageStart),
                    insurance_flex_row('วันสิ้นสุดคุ้มครอง', $coverageEnd),
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'margin' => 'lg',
                        'contents' => [
                            ['type' => 'text', 'text' => 'วงเงินค่ารักษาพยาบาล', 'size' => 'sm', 'color' => '#475569', 'flex' => 2],
                            ['type' => 'text', 'text' => $medicalLimit . ' บาท', 'size' => 'lg', 'weight' => 'bold', 'color' => '#E11D48', 'align' => 'end', 'flex' => 1],
                        ],
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
                        'color' => '#0F766E',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => 'เปิด Medical Hub',
                            'uri' => $baseUrl . '/user/hub.php',
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function insurance_flex_row(string $label, string $value): array
{
    return [
        'type' => 'box',
        'layout' => 'horizontal',
        'margin' => 'md',
        'contents' => [
            ['type' => 'text', 'text' => $label, 'size' => 'sm', 'color' => '#64748B', 'flex' => 2],
            ['type' => 'text', 'text' => $value, 'size' => 'sm', 'weight' => 'bold', 'color' => '#0F172A', 'align' => 'end', 'wrap' => true, 'flex' => 3],
        ],
    ];
}

function build_insurance_reply(PDO $pdo, string $lineUserId): array
{
    if (defined('SITE_SHOW_INSURANCE') && !SITE_SHOW_INSURANCE) {
        line_webhook_log('Insurance menu disabled', ['line_user_id' => line_mask_uid($lineUserId)]);
        return [reply_text_message('เมนูประกันอุบัติเหตุยังไม่เปิดให้ใช้งานในขณะนี้')];
    }

    $user = find_user_by_line_uid($pdo, $lineUserId, 'id, full_name, student_personnel_id, line_user_id, line_user_id_new');
    if (!$user) {
        line_webhook_log('Insurance lookup user not found', ['line_user_id' => line_mask_uid($lineUserId)], 'warning');
        return [reply_text_message("ยังไม่พบการลงทะเบียน LINE ของคุณ\nกรุณา Login/ลงทะเบียนก่อนใช้งานเมนูประกันอุบัติเหตุ\n" . line_app_base_url() . '/line_api/line_login.php')];
    }

    $memberId = trim((string)($user['student_personnel_id'] ?? ''));
    if ($memberId === '') {
        line_webhook_log('Insurance lookup missing member id', ['user_id' => $user['id'] ?? null, 'line_user_id' => line_mask_uid($lineUserId)], 'warning');
        return [reply_text_message('ไม่พบรหัสนักศึกษา/รหัสบุคลากรของคุณ กรุณาติดต่อห้องพยาบาล')];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM insurance_members WHERE member_id = :mid LIMIT 1");
        $stmt->execute([':mid' => $memberId]);
        $insurance = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        line_webhook_log('Insurance lookup query failed', ['member_id' => $memberId, 'error' => $e->getMessage()], 'error');
        error_log('LINE insurance lookup failed: ' . $e->getMessage());
        return [reply_text_message('ไม่สามารถตรวจสอบข้อมูลประกันได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง')];
    }

    if (!$insurance) {
        line_webhook_log('Insurance record not found', ['member_id' => $memberId, 'user_id' => $user['id'] ?? null], 'warning');
        return [reply_text_message('ไม่พบข้อมูลประกันของคุณ กรุณาติดต่อห้องพยาบาล')];
    }

    line_webhook_log('Insurance flex message built', [
        'member_id' => $memberId,
        'user_id' => $user['id'] ?? null,
        'insurance_status' => $insurance['insurance_status'] ?? '',
        'has_policy_number' => trim((string)($insurance['policy_number'] ?? '')) !== '',
    ]);

    return [build_insurance_flex_message($user, $insurance)];
}

// 3. ยืนยัน Signature (สำคัญมากเพื่อความปลอดภัย)
line_webhook_log('Webhook request received', [
    'payload_bytes' => strlen((string)$payload),
    'has_signature' => $signature !== '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
]);

if (!verify_line_signature($payload, $signature, $channelSecret)) {
    line_webhook_log('Invalid LINE signature', [
        'payload_bytes' => strlen((string)$payload),
        'has_channel_secret' => $channelSecret !== '',
    ], 'warning');
    http_response_code(400);
    error_log("LINE Webhook: Invalid Signature");
    exit("Invalid Signature");
}

// 4. แปลงข้อมูล
$data = json_decode($payload, true);
if (empty($data['events'])) {
    line_webhook_log('Webhook request has no events', [
        'json_valid' => json_last_error() === JSON_ERROR_NONE,
        'json_error' => json_last_error_msg(),
    ]);
    http_response_code(200);
    echo "OK (No events)";
    exit;
}

line_webhook_log('Webhook events decoded', ['event_count' => count($data['events'])]);

// 5. วนลูปจัดการแต่ละ Event
foreach ($data['events'] as $idx => $event) {
    $type = $event['type'] ?? '';
    $replyToken = $event['replyToken'] ?? null;
    $userId = $event['source']['userId'] ?? null;
    $messageText = (($event['message']['type'] ?? '') === 'text') ? (string)($event['message']['text'] ?? '') : '';
    $postbackData = ($type === 'postback') ? (string)($event['postback']['data'] ?? '') : '';

    line_webhook_log('Webhook event received', [
        'index' => $idx,
        'type' => $type,
        'line_user_id' => line_mask_uid($userId),
        'message_type' => $event['message']['type'] ?? '',
        'message_text' => $messageText,
        'postback_data' => $postbackData,
        'has_reply_token' => !empty($replyToken),
    ]);

    if ($replyToken && $userId && is_insurance_request($event)) {
        line_webhook_log('Insurance request detected', [
            'line_user_id' => line_mask_uid($userId),
            'trigger' => $messageText !== '' ? 'message' : 'postback',
        ]);
        $replyOk = send_line_reply($replyToken, build_insurance_reply(db(), $userId), $accessToken);
        line_webhook_log('Insurance reply sent', [
            'line_user_id' => line_mask_uid($userId),
            'ok' => $replyOk,
            'line_error' => $replyOk ? '' : get_last_line_error(),
        ], $replyOk ? 'info' : 'warning');
        continue;
    }

    switch ($type) {
        case 'follow':
            // ส่งข้อความต้อนรับเมื่อผู้ใช้แอดเพื่อน
            if ($replyToken) {
                $messages = [
                    [
                        'type' => 'text',
                        'text' => "ยินดีต้อนรับสู่ระบบ " . SITE_NAME . " ค่ะ! 😊\n\nขอบคุณที่ติดตามเรานะคะ คุณสามารถจองคิวรับบริการได้ผ่านเมนูในระบบได้เลยค่ะ"
                    ]
                ];
                send_line_reply($replyToken, $messages, $accessToken);
            }
            break;

        case 'postback':
            // Unsupported postback actions are acknowledged silently.
            break;

        case 'message':
            // ตอบกลับแบบง่ายถ้าเป็นข้อความตัวอักษร
            if ($replyToken && $event['message']['type'] === 'text') {
                $userText = $event['message']['text'];
                $messages = [
                    [
                        'type' => 'text',
                        'text' => "เราได้รับข้อความของคุณแล้ว: \"$userText\"\n\nหากต้องการความช่วยเหลือเพิ่มเติม สามารถติดต่อเจ้าหน้าที่ได้โดยตรงค่ะ"
                    ]
                ];
                send_line_reply($replyToken, $messages, $accessToken);
            }
            break;

        case 'unfollow':
            // ผู้ใช้บล็อกบอท
            error_log("LINE Webhook: User $userId unfollowed");
            break;

        default:
            // Event อื่นๆ เช่น postback (กดปุ่ม) สามารถเพิ่มภายหลังได้
            break;
    }
}

// 6. ตอบกลับ LINE ว่าได้รับข้อมูลแล้ว
http_response_code(200);
echo "OK";
