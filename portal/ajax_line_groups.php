<?php
/**
 * portal/ajax_line_groups.php
 * จัดการ LINE Group registry (discovered groups + default selection)
 *
 * GET  ?action=list        → คืนรายการกลุ่มและ default_id
 * POST action=set_default  → ตั้ง line.group.default_id
 * POST action=test_push    → ส่งข้อความทดสอบไปยังกลุ่ม
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/line_helper.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_REQUEST['action'] ?? '';
$pdo    = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'list') {
        $groups    = line_groups_list($pdo);
        $defaultId = line_groups_get_default($pdo);
        echo json_encode([
            'ok'         => true,
            'groups'     => $groups,
            'default_id' => $defaultId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']); exit;
}

if ($action === 'set_default') {
    $groupId = trim($_POST['group_id'] ?? '');
    if ($groupId === '') {
        echo json_encode(['ok' => false, 'error' => 'group_id required']); exit;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO sys_site_settings (setting_key, setting_value)
                               VALUES ('line.group.default_id', :v)
                               ON DUPLICATE KEY UPDATE setting_value = :v2");
        $stmt->execute([':v' => $groupId, ':v2' => $groupId]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Server error']);
    }
    exit;
}

if ($action === 'test_push') {
    $groupId = trim($_POST['group_id'] ?? '');
    if ($groupId === '') {
        echo json_encode(['ok' => false, 'error' => 'group_id required']); exit;
    }

    $secrets     = require __DIR__ . '/../config/secrets.php';
    $accessToken = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';
    if ($accessToken === '') {
        echo json_encode(['ok' => false, 'error' => 'LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ไม่ได้ตั้งค่าใน config/secrets.php']); exit;
    }

    $messages = [[
        'type' => 'text',
        'text' => "🔔 ทดสอบส่งข้อความจากระบบ " . (defined('SITE_NAME') ? SITE_NAME : 'RSU Clinic') . "\n\nกลุ่มนี้พร้อมรับการแจ้งเหตุ SOS และประกาศจากคลินิกแล้วค่ะ ✅",
    ]];
    $ok = send_line_group_push($groupId, $messages, $accessToken);
    echo json_encode([
        'ok'         => $ok,
        'line_error' => $ok ? '' : get_last_line_error(),
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
