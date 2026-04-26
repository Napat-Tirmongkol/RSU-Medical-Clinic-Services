<?php
/**
 * portal/ajax_test_line.php — ทดสอบการส่งข้อความผ่าน LINE Messaging API
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/line_helper.php';

header('Content-Type: application/json');

// ตรวจสอบสิทธิ์ (เฉพาะ superadmin)
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

// ตรวจสอบ CSRF
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token']);
    exit;
}

$action = $_POST['action'] ?? '';
$secretsPath = __DIR__ . '/../config/secrets.php';
$secrets = file_exists($secretsPath) ? require $secretsPath : [];

// ── 1. บันทึกการตั้งค่า ────────────────────────────────────────────────────────
if ($action === 'save') {
    if (!is_writable($secretsPath)) {
        echo json_encode(['ok' => false, 'error' => 'ไฟล์ config/secrets.php ไม่สามารถเขียนได้ (Check Permission)']);
        exit;
    }

    $token  = trim($_POST['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '');
    $secret = trim($_POST['LINE_MESSAGING_CHANNEL_SECRET'] ?? '');

    $existing = $secrets;
    $existing['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] = $token;
    $existing['LINE_MESSAGING_CHANNEL_SECRET']       = $secret;

    $content = "<?php\n// config/secrets.php\n\nreturn " . var_export($existing, true) . ";\n";
    if (file_put_contents($secretsPath, $content)) {
        echo json_encode(['ok' => true, 'message' => 'บันทึกการตั้งค่า LINE แล้ว']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'ไม่สามารถเขียนไฟล์ secrets.php ได้']);
    }
    exit;
}

// ── 2. ทดสอบส่งข้อความ ────────────────────────────────────────────────────────
if ($action === 'test') {
    $toUserId = trim($_POST['to_user_id'] ?? '');
    $token    = trim($_POST['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '');
    
    // ถ้าไม่ได้ส่ง Token มาใน POST ให้ใช้จาก secrets.php
    if (empty($token)) {
        $token = $secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '';
    }

    if (empty($toUserId)) {
        echo json_encode(['ok' => false, 'error' => 'กรุณาระบุ LINE User ID']);
        exit;
    }

    if (empty($token)) {
        echo json_encode(['ok' => false, 'error' => 'ไม่พบ Channel Access Token']);
        exit;
    }

    $messages = [
        [
            'type' => 'text',
            'text' => "🧪 ทดสอบการเชื่อมต่อ LINE Messaging API\n"
                    . "ระบบ: " . SITE_NAME . "\n"
                    . "เวลา: " . date('Y-m-d H:i:s') . "\n\n"
                    . "หากคุณได้รับข้อความนี้ แสดงว่าการตั้งค่าของคุณถูกต้องแล้วค่ะ! 🎉"
        ]
    ];

    if (send_line_push($toUserId, $messages, $token)) {
        echo json_encode(['ok' => true, 'message' => 'ส่งข้อความทดสอบสำเร็จ! กรุณาเช็คใน LINE ของคุณ']);
    } else {
        $lastError = get_last_line_error();
        $errorMsg = 'ส่งไม่สำเร็จ — ตรวจสอบ Token และ User ID';
        if ($lastError) {
            $errorMsg .= "\nรายละเอียดจาก LINE: " . $lastError;
        }
        echo json_encode(['ok' => false, 'error' => $errorMsg]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid action']);
