<?php
/**
 * portal/ajax_identity_test_line.php — ทดสอบส่งข้อความ LINE ไปยัง user ที่ระบุ
 *
 * Permission: admin / superadmin (ไม่ใช่ editor — กัน abuse)
 * รับ POST: user_id (integer ของ sys_users.id)
 * ส่ง: Flex Message ทดสอบ ระบุชื่อ admin ผู้ส่ง + เวลา + แหล่ง UID
 *
 * ลำดับการเลือก UID: line_user_id_new (post-migration) → line_user_id (legacy)
 * Validate รูปแบบ U + 32 hex — ถ้าผิดให้ user re-link ผ่าน LIFF
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/line_helper.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'admin');

if (!in_array($adminRole, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'ต้องเป็น admin หรือ superadmin']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
validate_csrf_or_die();

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ระบุ user_id ไม่ถูกต้อง']);
    exit;
}

$pdo = db();

// เลือก line_user_id_new ก่อน (post-migration) — fallback line_user_id
try {
    $col = $pdo->query("SHOW COLUMNS FROM sys_users LIKE 'line_user_id_new'");
    $hasNew = (bool)$col->fetch();
} catch (PDOException) {
    $hasNew = false;
}

$cols = $hasNew ? 'id, full_name, line_user_id, line_user_id_new' : 'id, full_name, line_user_id';
$stmt = $pdo->prepare("SELECT $cols FROM sys_users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'ไม่พบ user']);
    exit;
}

$targetUid = '';
$source = '';
if ($hasNew && !empty($user['line_user_id_new'])) {
    $targetUid = (string)$user['line_user_id_new'];
    $source = 'new';
} elseif (!empty($user['line_user_id'])) {
    $targetUid = (string)$user['line_user_id'];
    $source = 'legacy';
}

if ($targetUid === '') {
    echo json_encode(['ok' => false, 'error' => 'ผู้ใช้ยังไม่ได้ link LINE account']);
    exit;
}

if (!preg_match('/^U[0-9a-f]{32}$/i', $targetUid)) {
    echo json_encode([
        'ok' => false,
        'error' => 'รูปแบบ LINE User ID ไม่ถูกต้อง (อาจเป็น UID เก่าจาก Provider เดิม) — รอ user re-link ผ่าน LIFF',
        'source' => $source,
    ]);
    exit;
}

// Resolve LINE token
$token = defined('LINE_MESSAGING_CHANNEL_ACCESS_TOKEN') ? (string)LINE_MESSAGING_CHANNEL_ACCESS_TOKEN : '';
if ($token === '') {
    $secretsFile = __DIR__ . '/../config/secrets.php';
    if (is_file($secretsFile)) {
        $s = require $secretsFile;
        if (is_array($s)) {
            $token = (string)($s['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? $s['EBORROW_LINE_MESSAGE_TOKEN'] ?? '');
        }
    }
}
if ($token === '') {
    echo json_encode([
        'ok' => false,
        'error' => 'ระบบยังไม่ได้ตั้ง LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ใน config/secrets.php',
    ]);
    exit;
}

// สร้าง Flex Message ทดสอบ
$nowThai = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('d/m/Y H:i');
$flex = [
    'type' => 'flex',
    'altText' => '[ทดสอบ] ข้อความจากระบบ RSU Medical Clinic',
    'contents' => [
        'type' => 'bubble',
        'header' => [
            'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg',
            'backgroundColor' => '#dc2626',
            'contents' => [
                ['type' => 'text', 'text' => '🧪 ข้อความทดสอบ',
                 'color' => '#fff', 'size' => 'lg', 'weight' => 'bold'],
                ['type' => 'text', 'text' => 'RSU Medical Clinic Services',
                 'color' => '#fff', 'size' => 'xxs', 'margin' => 'xs'],
            ],
        ],
        'body' => [
            'type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'lg', 'spacing' => 'md',
            'contents' => [
                ['type' => 'text',
                 'text' => 'สวัสดี ' . ($user['full_name'] ?: 'ผู้ใช้งาน'),
                 'size' => 'md', 'weight' => 'bold', 'color' => '#0f172a', 'wrap' => true],
                ['type' => 'text',
                 'text' => 'นี่คือข้อความทดสอบจากระบบ เพื่อยืนยันว่าการเชื่อมต่อ LINE ของคุณทำงานได้ปกติ',
                 'size' => 'sm', 'color' => '#475569', 'wrap' => true],
                ['type' => 'separator', 'margin' => 'lg'],
                ['type' => 'box', 'layout' => 'vertical', 'margin' => 'md', 'spacing' => 'sm',
                 'contents' => [
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm',
                     'contents' => [
                        ['type' => 'text', 'text' => 'ส่งโดย', 'size' => 'xs', 'color' => '#94a3b8', 'flex' => 2],
                        ['type' => 'text', 'text' => $adminName, 'size' => 'xs', 'color' => '#0f172a', 'flex' => 5, 'wrap' => true],
                     ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm',
                     'contents' => [
                        ['type' => 'text', 'text' => 'เวลา', 'size' => 'xs', 'color' => '#94a3b8', 'flex' => 2],
                        ['type' => 'text', 'text' => $nowThai, 'size' => 'xs', 'color' => '#0f172a', 'flex' => 5],
                     ]],
                    ['type' => 'box', 'layout' => 'baseline', 'spacing' => 'sm',
                     'contents' => [
                        ['type' => 'text', 'text' => 'แหล่ง UID', 'size' => 'xs', 'color' => '#94a3b8', 'flex' => 2],
                        ['type' => 'text', 'text' => $source === 'new' ? 'Provider ใหม่' : 'Provider เดิม',
                         'size' => 'xs', 'color' => '#0f172a', 'flex' => 5],
                     ]],
                 ]],
            ],
        ],
    ],
];

$ok = send_line_push($targetUid, [$flex], $token);
if ($ok) {
    if (function_exists('log_activity')) {
        @log_activity('Test LINE Push',
            "Sent test message to user_id={$userId} via {$source} UID");
    }
    echo json_encode([
        'ok' => true,
        'message' => 'ส่งสำเร็จ',
        'target_masked' => '…' . substr($targetUid, -6),
        'source' => $source,
    ]);
} else {
    echo json_encode([
        'ok' => false,
        'error' => 'LINE API ปฏิเสธ: ' . (get_last_line_error() ?: 'unknown') . ' · ตรวจว่า user ได้ add LINE OA แล้วหรือยัง',
        'target_masked' => '…' . substr($targetUid, -6),
        'source' => $source,
    ]);
}
