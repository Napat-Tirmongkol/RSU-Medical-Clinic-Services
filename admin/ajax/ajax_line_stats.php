<?php
// admin/ajax/ajax_line_stats.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure LINE_MESSAGING_CHANNEL_ACCESS_TOKEN is defined via config
if (!defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN')) {
    require_once __DIR__ . '/../../line_api/line_config.php';
}

function line_get(string $url): array {
    $token = defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ? LINE_MESSAGING_CHANNEL_ACCESS_TOKEN : '';
    if ($token === '') {
        return ['_error' => 'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ยังไม่ได้ตั้งค่า'];
    }

    $opts = [
        'http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\n",
            'timeout'       => 12,
            'ignore_errors' => true,
        ]
    ];
    $body = @file_get_contents($url, false, stream_context_create($opts));
    if ($body === false) {
        return ['_error' => 'HTTP request failed'];
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : ['_error' => 'Invalid JSON', '_raw' => substr($body, 0, 200)];
}

$action = $_GET['action'] ?? '';

switch ($action) {

    // ── Quota + consumption ────────────────────────────────────────────────
    case 'quota':
        $quota       = line_get('https://api.line.me/v2/bot/message/quota');
        $consumption = line_get('https://api.line.me/v2/bot/message/quota/consumption');
        echo json_encode([
            'status'      => 'ok',
            'quota'       => $quota,
            'consumption' => $consumption,
        ]);
        break;

    // ── Delivery insight (all types in one call) ──────────────────────────
    case 'delivery':
        $raw  = $_GET['date'] ?? date('Ymd', strtotime('-1 day'));
        $date = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($date) !== 8) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'รูปแบบวันที่ไม่ถูกต้อง (ต้องการ YYYYMMDD)']);
            exit;
        }
        $data = line_get("https://api.line.me/v2/bot/insight/message/delivery?date=$date");
        echo json_encode([
            'status' => 'ok',
            'date'   => $date,
            'data'   => $data,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}
