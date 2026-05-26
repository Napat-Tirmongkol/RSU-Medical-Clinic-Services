<?php
/**
 * includes/line_helper.php
 * ฟังก์ชันช่วยจัดการเกี่ยวกับ LINE Messaging API
 */
declare(strict_types=1);

/**
 * ตรวจสอบ Signature จาก LINE Webhook
 */
function verify_line_signature(string $payload, string $signature, string $channelSecret): bool {
    if (empty($signature) || empty($payload)) return false;
    $hash = hash_hmac('sha256', $payload, $channelSecret, true);
    return hash_equals(base64_encode($hash), $signature);
}

/**
 * ส่งข้อความ Reply (ใช้ Reply Token)
 */
function send_line_reply(string $replyToken, array $messages, string $accessToken): bool {
    $url = 'https://api.line.me/v2/bot/message/reply';
    $data = [
        'replyToken' => $replyToken,
        'messages'   => $messages
    ];
    return _send_line_curl($url, $data, $accessToken);
}

/**
 * ส่งข้อความ Push (ใช้ User ID)
 */
function send_line_push(string $to, array $messages, string $accessToken): bool {
    $url = 'https://api.line.me/v2/bot/message/push';
    $data = [
        'to'       => $to,
        'messages' => $messages
    ];
    return _send_line_curl($url, $data, $accessToken);
}

/**
 * ส่งข้อความ Push ไปยังกลุ่ม (ใช้ groupId/roomId)
 *
 * Endpoint เดียวกับ send_line_push() แต่แยก helper เพื่อความชัดเจน:
 *   - groupId  : ส่งไปยังกลุ่ม LINE (OA ต้องอยู่ในกลุ่ม)
 *   - roomId   : ส่งไปยังห้องสนทนาแบบหลายคน (multi-person chat)
 *
 * Quota: 1 push call = 1 quota ไม่ว่าจะมีสมาชิกในกลุ่มกี่คน
 *
 * @see https://developers.line.biz/en/reference/messaging-api/#send-push-message
 */
function send_line_group_push(string $groupId, array $messages, string $accessToken): bool {
    return send_line_push($groupId, $messages, $accessToken);
}

/**
 * แสดง Loading Indicator (จุด ๆ "กำลังพิมพ์...") ในแชทของ user
 *
 * ใช้ตอนกำลังประมวลผลที่อาจใช้เวลา (เช่น call Gemini) เพื่อให้ user เห็นว่าระบบกำลังทำงาน
 * Auto-clear เมื่อ bot ส่ง message ถัดไป (หรือหมดเวลาตาม loadingSeconds)
 *
 * Best-effort: ถ้าฟังก์ชันนี้ล้มเหลว flow หลักไม่กระทบ
 *
 * @param string $chatId         LINE userId (1-on-1 chat เท่านั้น — ไม่รองรับ group)
 * @param int    $loadingSeconds 5/10/15/20/25/30/40/50/60 (default 20)
 * @see https://developers.line.biz/en/reference/messaging-api/#display-a-loading-indicator
 */
function send_line_loading_indicator(string $chatId, string $accessToken, int $loadingSeconds = 20): bool {
    $url = 'https://api.line.me/v2/bot/chat/loading/start';
    $data = [
        'chatId'         => $chatId,
        'loadingSeconds' => $loadingSeconds,
    ];
    // LINE API นี้ return 202 Accepted (ไม่ใช่ 200)
    return _send_line_curl($url, $data, $accessToken, [200, 202]);
}

/**
 * ฟังก์ชันกลางสำหรับยิง CURL ไปยัง LINE API
 *
 * @param array $okStatuses status codes ที่ถือว่าสำเร็จ (default [200])
 *                          บาง endpoint เช่น chat/loading/start คืน 202
 */
function _send_line_curl(string $url, array $data, string $accessToken, array $okStatuses = [200]): bool {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_TIMEOUT        => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = in_array($httpCode, $okStatuses, true);
    if (!$ok) {
        $body = $response ?: 'No response';
        $hint = '';
        if ($httpCode === 400) {
            $hint = ' — ผู้รับอาจยังไม่ได้เพิ่ม LINE OA เป็นเพื่อน, บล็อก OA แล้ว, หรือ User ID ไม่ตรงกับ Channel นี้';
        } elseif ($httpCode === 401) {
            $hint = ' — Channel Access Token ไม่ถูกต้องหรือหมดอายุ';
        } elseif ($httpCode === 403) {
            $hint = ' — ไม่มีสิทธิ์ส่งข้อความ ตรวจสอบสิทธิ์ของ Messaging API Channel';
        }
        $errorMsg = "LINE API Error ($httpCode)$hint: " . $body;
        error_log($errorMsg);
        if (function_exists('log_error_to_db')) {
            log_error_to_db($errorMsg, 'error', 'line_helper.php', json_encode($data));
        }
        $GLOBALS['LAST_LINE_ERROR'] = $body . $hint;
    }

    return $ok;
}

/**
 * ดึงข้อความตอบกลับล่าสุดจาก LINE API กรณีเกิดข้อผิดพลาด
 */
function get_last_line_error(): string {
    return (string)($GLOBALS['LAST_LINE_ERROR'] ?? '');
}

/**
 * สร้าง Flex Message สำหรับแจ้งเตือนกลุ่มเมื่อนักศึกษาทุน clock_in/clock_out
 * รวมปุ่ม "อนุมัติ" / "ปฏิเสธ" เป็น postback เพื่อให้กดได้ใน LINE โดยตรง
 *
 * @param int    $logId       sys_scholarship_clock_logs.id
 * @param string $studentName ชื่อ-นามสกุล
 * @param string $studentCode รหัสนักศึกษา
 * @param string $faculty     คณะ/สาขา
 * @param string $action      'clock_in' | 'clock_out'
 * @param string $eventTime   เช่น '09:15 น.'
 * @param bool   $withinRadius อยู่ในพื้นที่หรือไม่
 * @param string $compType    'hours' | 'paid'
 */
function build_scholarship_notify_flex(int $logId, string $studentName, string $studentCode,
    string $faculty, string $action, string $eventTime, bool $withinRadius, string $compType): array
{
    $isIn       = $action === 'clock_in';
    $actionThai = $isIn ? 'ขอเข้างาน' : 'ขอออกงาน';
    $actionIcon = $isIn ? '🟢' : '🔴';
    $gpsText    = $withinRadius ? '✅ อยู่ในพื้นที่' : '⚠️ นอกพื้นที่คลินิก';
    $gpsColor   = $withinRadius ? '#16a34a' : '#b45309';
    $compThai   = $compType === 'paid' ? 'ค่าตอบแทน (จ้าง)' : 'ชั่วโมงทำงาน';

    return [
        'type'       => 'flex',
        'altText'    => "$actionIcon นักศึกษาทุน $actionThai — $studentName",
        'contents'   => [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type'            => 'box',
                'layout'          => 'vertical',
                'backgroundColor' => $isIn ? '#7c3aed' : '#0f172a',
                'paddingAll'      => '16px',
                'contents'        => [[
                    'type'   => 'text',
                    'text'   => "$actionIcon ทุนนักศึกษา — $actionThai",
                    'color'  => '#ffffff',
                    'weight' => 'bold',
                    'size'   => 'md',
                ]],
            ],
            'body' => [
                'type'       => 'box',
                'layout'     => 'vertical',
                'spacing'    => 'sm',
                'paddingAll' => '16px',
                'contents'   => [
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'ชื่อ', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => $studentName, 'size' => 'sm', 'weight' => 'bold', 'flex' => 5, 'wrap' => true],
                    ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'รหัส', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => $studentCode ?: '—', 'size' => 'sm', 'flex' => 5],
                    ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'คณะ', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => $faculty ?: '—', 'size' => 'sm', 'flex' => 5, 'wrap' => true],
                    ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'เวลา', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => $eventTime, 'size' => 'sm', 'flex' => 5],
                    ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'GPS', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => $gpsText, 'size' => 'sm', 'color' => $gpsColor, 'flex' => 5],
                    ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'ประเภท', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => $compThai, 'size' => 'sm', 'flex' => 5],
                    ]],
                    ['type' => 'separator', 'margin' => 'md'],
                    ['type' => 'text', 'text' => "Log ID: #$logId", 'size' => 'xs', 'color' => '#94a3b8', 'margin' => 'sm'],
                ],
            ],
            'footer' => [
                'type'     => 'box',
                'layout'   => 'horizontal',
                'spacing'  => 'md',
                'contents' => [
                    [
                        'type'   => 'button',
                        'style'  => 'primary',
                        'color'  => '#16a34a',
                        'height' => 'sm',
                        'action' => [
                            'type'        => 'postback',
                            'label'       => '✅ อนุมัติ',
                            'data'        => "scholarship_approve:$logId",
                            'displayText' => "อนุมัติ #$logId",
                        ],
                    ],
                    [
                        'type'   => 'button',
                        'style'  => 'primary',
                        'color'  => '#dc2626',
                        'height' => 'sm',
                        'action' => [
                            'type'        => 'postback',
                            'label'       => '❌ ปฏิเสธ',
                            'data'        => "scholarship_reject:$logId",
                            'displayText' => "ปฏิเสธ #$logId",
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Flex message แจ้งนักศึกษาว่า "ค่าตอบแทนพร้อมรับที่ฝ่ายการเงิน" หลังการเงินอนุมัติ
 */
function build_scholarship_payout_approved_flex(string $studentName, string $periodLabel,
    float $hoursPaid, float $amount): array
{
    $amountTxt = number_format($amount, 2);
    $hoursTxt  = number_format($hoursPaid, 2);
    return [
        'type'    => 'flex',
        'altText' => "💰 ค่าตอบแทนพร้อมรับ — $studentName ($periodLabel)",
        'contents' => [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type'            => 'box',
                'layout'          => 'vertical',
                'backgroundColor' => '#059669',
                'paddingAll'      => '16px',
                'contents'        => [[
                    'type'   => 'text',
                    'text'   => '💰 ค่าตอบแทนพร้อมรับ',
                    'color'  => '#ffffff',
                    'weight' => 'bold',
                    'size'   => 'md',
                ]],
            ],
            'body' => [
                'type'       => 'box',
                'layout'     => 'vertical',
                'spacing'    => 'sm',
                'paddingAll' => '16px',
                'contents'   => [
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'ชื่อ', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => $studentName, 'size' => 'sm', 'weight' => 'bold', 'flex' => 5, 'wrap' => true],
                    ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'เดือน', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => $periodLabel, 'size' => 'sm', 'flex' => 5, 'wrap' => true],
                    ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'ชั่วโมง', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => "$hoursTxt ชม.", 'size' => 'sm', 'flex' => 5],
                    ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm', 'contents' => [
                        ['type' => 'text', 'text' => 'ยอด', 'color' => '#64748b', 'size' => 'sm', 'flex' => 2],
                        ['type' => 'text', 'text' => "$amountTxt บาท", 'size' => 'md', 'weight' => 'bold', 'color' => '#059669', 'flex' => 5],
                    ]],
                    ['type' => 'separator', 'margin' => 'md'],
                    ['type' => 'text', 'text' => '📍 กรุณาติดต่อรับเงินที่ฝ่ายการเงินคลินิก',
                        'size' => 'sm', 'color' => '#0f172a', 'wrap' => true, 'margin' => 'md'],
                    ['type' => 'text', 'text' => 'เวลาทำการ: ตามเวลาคลินิก',
                        'size' => 'xs', 'color' => '#94a3b8', 'margin' => 'sm'],
                ],
            ],
        ],
    ];
}

/**
 * LINE Group registry (เก็บใน sys_site_settings key 'line.groups.discovered')
 *
 * เก็บเป็น JSON array ของ { id, type, name?, joined_at, last_seen_at, member_count? }
 *   - type: 'group' | 'room'
 *   - ทุก group ที่ OA ถูกเชิญเข้าจะถูก auto-save ผ่าน webhook 'join' event
 *
 * ใช้คู่กับ key 'line.group.default_id' (admin เลือกกลุ่มหลักที่จะ push)
 */
function line_groups_list(PDO $pdo): array {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM sys_site_settings WHERE setting_key = 'line.groups.discovered' LIMIT 1");
        $stmt->execute();
        $json = (string)($stmt->fetchColumn() ?: '[]');
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    } catch (Throwable $e) {
        error_log('[line_groups_list] ' . $e->getMessage());
        return [];
    }
}

/**
 * บันทึก/อัปเดต group เข้า registry (idempotent — เรียกซ้ำได้)
 *
 * @param string $type 'group' | 'room'
 */
function line_groups_upsert(PDO $pdo, string $groupId, string $type = 'group', ?string $name = null, ?int $memberCount = null): bool {
    if ($groupId === '') return false;
    try {
        $groups = line_groups_list($pdo);
        $now = date('c');
        $found = false;
        foreach ($groups as &$g) {
            if (($g['id'] ?? '') === $groupId) {
                $g['last_seen_at'] = $now;
                if ($name !== null && $name !== '')         $g['name'] = $name;
                if ($memberCount !== null)                  $g['member_count'] = $memberCount;
                $found = true;
                break;
            }
        }
        unset($g);
        if (!$found) {
            $groups[] = [
                'id'            => $groupId,
                'type'          => $type,
                'name'          => $name ?? '',
                'joined_at'     => $now,
                'last_seen_at'  => $now,
                'member_count'  => $memberCount,
            ];
        }
        $stmt = $pdo->prepare("INSERT INTO sys_site_settings (setting_key, setting_value)
                               VALUES ('line.groups.discovered', :v)
                               ON DUPLICATE KEY UPDATE setting_value = :v2");
        $payload = json_encode($groups, JSON_UNESCAPED_UNICODE);
        return $stmt->execute([':v' => $payload, ':v2' => $payload]);
    } catch (Throwable $e) {
        error_log('[line_groups_upsert] ' . $e->getMessage());
        return false;
    }
}

/**
 * ลบ group ออกจาก registry (เมื่อ OA โดนเตะ/leave)
 */
function line_groups_remove(PDO $pdo, string $groupId): bool {
    try {
        $groups = line_groups_list($pdo);
        $filtered = array_values(array_filter($groups, fn($g) => ($g['id'] ?? '') !== $groupId));
        if (count($filtered) === count($groups)) return true;
        $stmt = $pdo->prepare("UPDATE sys_site_settings SET setting_value = :v WHERE setting_key = 'line.groups.discovered'");
        return $stmt->execute([':v' => json_encode($filtered, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        error_log('[line_groups_remove] ' . $e->getMessage());
        return false;
    }
}

/**
 * คืน groupId เริ่มต้นที่ admin เลือกไว้ (สำหรับ push อัตโนมัติ เช่น SOS)
 * คืน '' ถ้ายังไม่ได้ตั้ง
 */
function line_groups_get_default(PDO $pdo): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM sys_site_settings WHERE setting_key = 'line.group.default_id' LIMIT 1");
        $stmt->execute();
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('[line_groups_get_default] ' . $e->getMessage());
        return '';
    }
}

/**
 * ค้น user จาก LINE UID ระหว่างช่วง migrate provider
 * เช็ค line_user_id_new ก่อน → ถ้าไม่เจอ fallback ไป line_user_id เดิม
 *
 * @param PDO    $pdo
 * @param string $uid     UID ที่ได้จาก LINE (อาจเป็น new หรือ old)
 * @param string $columns columns ที่ต้องการ (default: *)
 * @return array|null     แถว user หรือ null ถ้าไม่เจอ
 */
function find_user_by_line_uid(PDO $pdo, string $uid, string $columns = '*'): ?array {
    if ($uid === '') return null;

    // 1. ลอง new UID ก่อน (เผื่อ user migrate แล้ว)
    try {
        $stmt = $pdo->prepare("SELECT {$columns} FROM sys_users WHERE line_user_id_new = :uid LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (PDOException $e) {
        // column ยังไม่มี — ข้ามไป fallback
    }

    // 2. fallback ไป old UID
    $stmt = $pdo->prepare("SELECT {$columns} FROM sys_users WHERE line_user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
