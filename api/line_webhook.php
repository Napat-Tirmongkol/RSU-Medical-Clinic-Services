<?php
/**
 * api/line_webhook.php
 * Endpoint สำหรับรับ Webhook จาก LINE Messaging API
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/line_helper.php';
require_once __DIR__ . '/../includes/clinic_status_helper.php';

// 1. รับข้อมูลจาก LINE
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

// 2. โหลด Config
$secrets = require __DIR__ . '/../config/secrets.php';
$channelSecret = $secrets['LINE_MESSAGING_CHANNEL_SECRET'] ?? '';
$accessToken   = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';

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
    // Trigger phrase — single specific keyword to avoid false positives
    // (e.g. user mentioning 'ประกันสังคม' or 'อุบัติเหตุ' in casual chat).
    $TRIGGER = 'เช็คสิทธิประกัน';

    if (($event['type'] ?? '') === 'postback') {
        $data = (string)($event['postback']['data'] ?? '');
        parse_str($data, $params);
        return str_contains($data, $TRIGGER)
            || (($params['action'] ?? '') === 'insurance')
            || (($params['menu'] ?? '') === 'insurance');
    }

    if (($event['type'] ?? '') === 'message' && (($event['message']['type'] ?? '') === 'text')) {
        $text = trim((string)($event['message']['text'] ?? ''));
        return str_contains($text, $TRIGGER);
    }

    return false;
}

function format_line_date(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

function mask_citizen_id(?string $citizenId): string
{
    $digits = preg_replace('/\D+/', '', (string)$citizenId);
    if ($digits === '') return '-';
    if (strlen($digits) <= 5) return str_repeat('*', strlen($digits));
    return substr($digits, 0, 3) . str_repeat('*', max(0, strlen($digits) - 5)) . substr($digits, -2);
}

function reply_text_message(string $text): array
{
    return ['type' => 'text', 'text' => $text];
}

/**
 * Flex bubble สำหรับคำตอบที่ AI generate — มี header ระบุชัดว่าเป็น AI
 * + ปุ่ม "คุยกับเจ้าหน้าที่" ลิงก์ไปเปิด chat modal ใน hub
 *
 * Tip: LINE Flex text limit ~2000 chars; truncate ไว้กันบางคำตอบยาวมาก
 */
function build_ai_reply_flex(string $answer): array
{
    $answer = trim($answer);
    if ($answer === '') $answer = '(ไม่มีคำตอบ)';
    if (mb_strlen($answer) > 1800) {
        $answer = mb_substr($answer, 0, 1797) . '...';
    }
    $alt = mb_substr($answer, 0, 380);

    return [
        'type'    => 'flex',
        'altText' => $alt,
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type'            => 'box',
                'layout'          => 'horizontal',
                'paddingAll'      => '14px',
                'backgroundColor' => '#F5F3FF',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '🤖',
                        'size' => 'xl',
                        'flex' => 0,
                    ],
                    [
                        'type'         => 'box',
                        'layout'       => 'vertical',
                        'paddingStart' => '10px',
                        'flex'         => 1,
                        'contents' => [
                            ['type' => 'text', 'text' => 'AI ตอบให้คุณ', 'size' => 'sm', 'weight' => 'bold', 'color' => '#7C3AED'],
                            ['type' => 'text', 'text' => 'ระบบตอบอัตโนมัติ', 'size' => 'xxs', 'color' => '#6B7280', 'wrap' => true],
                        ],
                    ],
                ],
            ],
            'body' => [
                'type'       => 'box',
                'layout'     => 'vertical',
                'paddingAll' => '18px',
                'contents' => [
                    [
                        'type'  => 'text',
                        'text'  => $answer,
                        'size'  => 'md',
                        'color' => '#1F2937',
                        'wrap'  => true,
                    ],
                ],
            ],
        ],
    ];
}

function build_registration_required_flex(): array
{
    return [
        'type' => 'flex',
        'altText' => 'กรุณาลงทะเบียนก่อนใช้งาน',
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '22px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'RSU Medical Hub',
                        'weight' => 'bold',
                        'size' => 'xs',
                        'color' => '#2563EB',
                    ],
                    [
                        'type' => 'text',
                        'text' => 'กรุณาลงทะเบียนก่อนใช้งาน',
                        'weight' => 'bold',
                        'size' => 'xl',
                        'color' => '#0F172A',
                        'margin' => 'md',
                        'wrap' => true,
                    ],
                    [
                        'type' => 'text',
                        'text' => 'ระบบยังไม่พบข้อมูล LINE ของคุณ กรุณา Login/ลงทะเบียนเพื่อผูกบัญชีกับรหัสนักศึกษาหรือรหัสบุคลากร แล้วจึงใช้งานเมนูประกันอุบัติเหตุ',
                        'size' => 'sm',
                        'color' => '#64748B',
                        'margin' => 'md',
                        'wrap' => true,
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
                        'color' => '#2563EB',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => 'Login / ลงทะเบียน',
                            'uri' => line_app_base_url() . '/line_api/line_login.php',
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function build_insurance_flex_message(array $user, array $insurance): array
{
    $fullName = trim((string)($insurance['full_name'] ?? '')) ?: (string)($user['full_name'] ?? '-');
    $policyNo = trim((string)($insurance['policy_number'] ?? '')) ?: '-';
    $maskedCitizenId = mask_citizen_id($insurance['citizen_id'] ?? '');
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
                    insurance_flex_row('เลขบัตรประชาชน', $maskedCitizenId),
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

function build_insurance_inactive_flex(array $user, array $insurance): array
{
    $fullName = trim((string)($insurance['full_name'] ?? '')) ?: (string)($user['full_name'] ?? '-');
    $maskedCitizenId = mask_citizen_id($insurance['citizen_id'] ?? '');

    return [
        'type' => 'flex',
        'altText' => 'สิทธิ์ประกันไม่พร้อมใช้งาน',
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'paddingAll' => '22px',
                'backgroundColor' => '#FFF7ED',
                'contents' => [
                    ['type' => 'text', 'text' => 'INSURANCE STATUS', 'weight' => 'bold', 'size' => 'xs', 'color' => '#EA580C'],
                    ['type' => 'text', 'text' => 'สิทธิ์ประกันไม่พร้อมใช้งาน', 'weight' => 'bold', 'size' => 'xl', 'color' => '#9A3412', 'margin' => 'md', 'wrap' => true],
                    ['type' => 'text', 'text' => 'ข้อมูลประกันของคุณอยู่ในสถานะ Inactive กรุณาติดต่อห้องพยาบาลเพื่อตรวจสอบสิทธิ์', 'size' => 'sm', 'color' => '#78716C', 'margin' => 'md', 'wrap' => true],
                    ['type' => 'separator', 'margin' => 'lg', 'color' => '#FED7AA'],
                    insurance_flex_row('ชื่อ', $fullName),
                    insurance_flex_row('เลขบัตรประชาชน', $maskedCitizenId),
                ],
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'uri',
                            'label' => 'เปิด Medical Hub',
                            'uri' => line_app_base_url() . '/user/hub.php',
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
        return [build_registration_required_flex()];
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

    if (($insurance['insurance_status'] ?? '') !== 'Active') {
        line_webhook_log('Insurance inactive notice built', [
            'member_id' => $memberId,
            'user_id' => $user['id'] ?? null,
            'insurance_status' => $insurance['insurance_status'] ?? '',
        ]);
        return [build_insurance_inactive_flex($user, $insurance)];
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
    // LINE redelivery: ส่งซ้ำเมื่อ delivery แรก timeout — replyToken จะหายไป (ใช้แล้ว)
    // เราต้อง fallback ไป push เพราะ reply ไม่ได้
    $isRedelivery = !empty($event['deliveryContext']['isRedelivery']);

    line_webhook_log('Webhook event received', [
        'index' => $idx,
        'type' => $type,
        'line_user_id' => line_mask_uid($userId),
        'message_type' => $event['message']['type'] ?? '',
        'message_text' => $messageText,
        'postback_data' => $postbackData,
        'has_reply_token' => !empty($replyToken),
        'is_redelivery' => $isRedelivery,
    ]);

    // Mirror text questions to AI QA Lab (sandbox) — runs before any reply branch
    // so we capture every user question regardless of whether the system answers it.
    if ($type === 'message' && $messageText !== '') {
        require_once __DIR__ . '/../includes/ai_qa_helper.php';
        $lineMsgId = (string)($event['message']['id'] ?? '');
        capture_ai_qa(db(), 'line', $messageText, null, $userId, $lineMsgId !== '' ? $lineMsgId : null);
    }

    if ($userId && is_insurance_request($event)) {
        line_webhook_log('Insurance request detected', [
            'line_user_id' => line_mask_uid($userId),
            'trigger' => $messageText !== '' ? 'message' : 'postback',
            'has_reply_token' => !empty($replyToken),
        ]);
        $messages = build_insurance_reply(db(), $userId);
        $replyOk = $replyToken
            ? send_line_reply($replyToken, $messages, $accessToken)
            : send_line_push($userId, $messages, $accessToken);
        line_webhook_log($replyToken ? 'Insurance reply sent' : 'Insurance push sent', [
            'line_user_id' => line_mask_uid($userId),
            'ok' => $replyOk,
            'method' => $replyToken ? 'reply' : 'push',
            'line_error' => $replyOk ? '' : get_last_line_error(),
        ], $replyOk ? 'info' : 'warning');
        continue;
    }

    // ── Blocklist: ถ้าข้อความมี keyword ที่ admin ตั้ง blocklist ไว้
    //    → webhook ไม่ตอบ (ไม่ทั้ง matcher และ default) ปล่อยให้ LINE OA
    //    built-in keyword auto-reply ตอบเอง — กันตอบซ้ำ
    if ($type === 'message' && $messageText !== '') {
        $pdo = db();
        $faqSettingsForBlock = get_clinic_faq_settings($pdo);
        $hitKeyword = find_blocked_keyword($messageText, (string)($faqSettingsForBlock['blocked_keywords'] ?? ''));
        if ($hitKeyword !== null) {
            line_webhook_log('Webhook skipped — message hit blocked keyword (LINE OA auto-reply will handle)', [
                'line_user_id' => line_mask_uid($userId),
                'matched_keyword' => $hitKeyword,
                'message_snippet' => mb_substr($messageText, 0, 80),
            ]);
            continue;
        }
    }

    // ── AI QA Lab — match คำถามกับ FAQ Knowledge Base (admin-curated) ──
    // แทนที่ legacy clinic_status_intent — รองรับคำถามทุกแบบที่ admin ตั้งไว้
    if ($type === 'message' && $messageText !== '') {
        require_once __DIR__ . '/../includes/ai_qa_helper.php';
        $pdo = db();
        $faqSettings = get_clinic_faq_settings($pdo);

        if (!(int)$faqSettings['enabled']) {
            line_webhook_log('AI QA Lab disabled (FAQ enabled=0)', [
                'line_user_id' => line_mask_uid($userId),
            ]);
        } elseif ((int)$faqSettings['only_when_closed'] && get_clinic_current_status($pdo)['is_open_now']) {
            // admin ตั้ง only_when_closed=1 และคลินิกเปิดอยู่ → ข้าม AI reply
            // (ตกไป default reply — เพื่อบีบให้ user คุยกับเจ้าหน้าที่ตอนเปิดทำการ)
            line_webhook_log('AI QA Lab skipped (clinic is open, only_when_closed=1)', [
                'line_user_id' => line_mask_uid($userId),
            ]);
        } else {
            // Rate limit (per LINE user) ใช้ key 'ai_qa' รวมทุกประเภทคำถาม
            $allowed = $userId
                ? check_clinic_faq_rate_limit($pdo, (string)$userId, 'ai_qa', (int)$faqSettings['rate_limit_hours'])
                : true;

            if (!$allowed) {
                // ถูก rate limit — ข้าม matcher แต่ตกไป default reply ปกติ
                // (ไม่ continue เพื่อให้ user ยังได้ข้อความ "เราได้รับข้อความ...")
                line_webhook_log('AI QA Lab rate limited — fallthrough to default reply', [
                    'line_user_id'     => line_mask_uid($userId),
                    'rate_limit_hours' => $faqSettings['rate_limit_hours'],
                ]);
            } else {
                try {
                    $match = ai_qa_match_faq(
                        $pdo,
                        $messageText,
                        // Phase 1 (exact) ไม่เจอ → กำลังเข้า Gemini ที่ช้า
                        // → แสดง loading dots ในแชท user เพื่อบอกว่ากำลังคิด
                        function () use ($userId, $accessToken) {
                            if ($userId) {
                                $okIndicator = send_line_loading_indicator((string)$userId, $accessToken, 20);
                                line_webhook_log('AI QA loading indicator', [
                                    'line_user_id' => line_mask_uid($userId),
                                    'ok' => $okIndicator,
                                    'line_error' => $okIndicator ? '' : get_last_line_error(),
                                ], $okIndicator ? 'info' : 'warning');
                            }
                        }
                    );
                } catch (Throwable $e) {
                    line_webhook_log('AI QA Lab match failed', ['error' => $e->getMessage()], 'warning');
                    $match = null;
                }

                if ($match !== null) {
                    line_webhook_log('AI QA Lab match found', [
                        'line_user_id' => line_mask_uid($userId),
                        'matched_via'  => $match['matched_via'],
                        'source_id'    => $match['source_id'] ?? null,
                        'confidence'   => $match['confidence'] ?? null,
                    ]);

                    $messages = [build_ai_reply_flex((string)$match['answer'])];
                    $replyOk = $replyToken
                        ? send_line_reply($replyToken, $messages, $accessToken)
                        : ($userId ? send_line_push($userId, $messages, $accessToken) : false);

                    if ($replyOk && $userId) {
                        log_clinic_faq_reply($pdo, (string)$userId, 'ai_qa');
                    }
                    line_webhook_log($replyToken ? 'AI QA reply sent' : 'AI QA push sent', [
                        'line_user_id' => line_mask_uid($userId),
                        'ok'           => $replyOk,
                        'method'       => $replyToken ? 'reply' : 'push',
                        'line_error'   => $replyOk ? '' : get_last_line_error(),
                    ], $replyOk ? 'info' : 'warning');
                    continue;
                }

                line_webhook_log('AI QA Lab no match (fallthrough to default reply)', [
                    'line_user_id' => line_mask_uid($userId),
                ]);
            }
        }
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
            // ตอบกลับแบบง่ายถ้าเป็นข้อความตัวอักษร (default fallback หลัง matcher miss)
            if ($event['message']['type'] === 'text') {
                $userText = $event['message']['text'];
                $messages = [
                    [
                        'type' => 'text',
                        'text' => "เราได้รับข้อความของคุณแล้ว: \"$userText\"\n\nหากต้องการความช่วยเหลือเพิ่มเติม สามารถติดต่อเจ้าหน้าที่ได้โดยตรงค่ะ"
                    ]
                ];
                // Fallback to push เมื่อไม่มี replyToken (เคส LINE redelivery)
                $defaultOk = $replyToken
                    ? send_line_reply($replyToken, $messages, $accessToken)
                    : ($userId ? send_line_push($userId, $messages, $accessToken) : false);
                $method = $replyToken ? 'reply' : ($userId ? 'push' : 'none');
                line_webhook_log($defaultOk ? 'Default reply sent' : 'Default reply FAILED', [
                    'line_user_id'  => line_mask_uid($userId),
                    'ok'            => $defaultOk,
                    'method'        => $method,
                    'is_redelivery' => $isRedelivery,
                    'line_error'    => $defaultOk ? '' : get_last_line_error(),
                ], $defaultOk ? 'info' : 'warning');
            } else {
                line_webhook_log('Default reply skipped (non-text message)', [
                    'line_user_id' => line_mask_uid($userId),
                    'message_type' => $event['message']['type'] ?? 'unknown',
                ], 'warning');
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
