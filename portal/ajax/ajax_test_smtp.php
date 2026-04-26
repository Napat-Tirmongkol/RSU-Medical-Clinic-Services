<?php
// portal/ajax_test_smtp.php — ทดสอบการส่งอีเมลผ่าน SMTP
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// CSRF check
if (!function_exists('validate_csrf_or_die')) {
    // Fallback if not loaded
    function validate_csrf_or_die() {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || $token !== ($_SESSION['csrf_token'] ?? '')) {
            http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Method not allowed']));
}

validate_csrf_or_die();

// Superadmin เท่านั้น
if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
}

require_once __DIR__ . '/../../includes/mail_helper.php';

$action = $_POST['action'] ?? 'test';

// ── บันทึก SMTP config ลง secrets.php ─────────────────────────────────────────
if ($action === 'save') {
    $secretsPath = __DIR__ . '/../config/secrets.php';
    $existing    = file_exists($secretsPath) ? (require $secretsPath) : [];

    $existing['SMTP_HOST']       = trim($_POST['SMTP_HOST']       ?? '');
    $existing['SMTP_PORT']       = (int)($_POST['SMTP_PORT']      ?? 587);
    $existing['SMTP_USER']       = trim($_POST['SMTP_USER']       ?? '');
    $existing['SMTP_FROM_EMAIL'] = trim($_POST['SMTP_FROM_EMAIL'] ?? '');
    $existing['SMTP_FROM_NAME']  = trim($_POST['SMTP_FROM_NAME']  ?? 'RSU Medical Clinic Services');

    // อัปเดต password เฉพาะถ้ามีการกรอกใหม่
    $newPass = trim($_POST['SMTP_PASS'] ?? '');
    if ($newPass !== '') {
        $existing['SMTP_PASS'] = $newPass;
    }

    // เขียนกลับ
    $export = "<?php\nreturn " . var_export($existing, true) . ";\n";
    if (file_put_contents($secretsPath, $export) === false) {
        echo json_encode(['ok' => false, 'error' => 'ไม่สามารถเขียน config/secrets.php ได้ — ตรวจสอบ file permission']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'บันทึกการตั้งค่า SMTP แล้ว']);
    exit;
}

// ── ทดสอบส่งอีเมลตาม template ─────────────────────────────────────────────────
if ($action === 'test_template') {
    $toEmail = trim($_POST['to_email'] ?? '');
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error' => 'อีเมลปลายทางไม่ถูกต้อง']);
        exit;
    }

    $templateType   = $_POST['template_type'] ?? '';
    $allowedTypes   = ['confirmation', 'approved', 'cancelled_by_user', 'cancelled_by_admin'];
    if (!in_array($templateType, $allowedTypes, true)) {
        echo json_encode(['ok' => false, 'error' => 'template_type ไม่ถูกต้อง']);
        exit;
    }

    $secrets = get_secrets();
    $cfg = [
        'SMTP_HOST'       => trim($_POST['SMTP_HOST']       ?? $secrets['SMTP_HOST']       ?? ''),
        'SMTP_PORT'       => (int)($_POST['SMTP_PORT']       ?? $secrets['SMTP_PORT']       ?? 587),
        'SMTP_USER'       => trim($_POST['SMTP_USER']       ?? $secrets['SMTP_USER']       ?? ''),
        'SMTP_PASS'       => trim($_POST['SMTP_PASS']       ?? $secrets['SMTP_PASS']       ?? ''),
        'SMTP_FROM_EMAIL' => trim($_POST['SMTP_FROM_EMAIL'] ?? $secrets['SMTP_FROM_EMAIL'] ?? ''),
        'SMTP_FROM_NAME'  => trim($_POST['SMTP_FROM_NAME']  ?? $secrets['SMTP_FROM_NAME']  ?? 'RSU Medical Clinic Services'),
    ];
    if ($cfg['SMTP_PASS'] === '' && !empty($secrets['SMTP_PASS'])) {
        $cfg['SMTP_PASS'] = $secrets['SMTP_PASS'];
    }
    if (empty($cfg['SMTP_HOST']) || empty($cfg['SMTP_USER'])) {
        echo json_encode(['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า SMTP_HOST หรือ SMTP_USER']);
        exit;
    }

    // ข้อมูลจำลองสำหรับ preview
    $dummyData = [
        'full_name'      => 'นาย ทดสอบ ระบบ',
        'campaign_title' => '[ทดสอบ] ตรวจสุขภาพประจำปี 2026',
        'date'           => date('d/m/Y', strtotime('+3 days')),
        'time'           => '09:00 – 09:30 น.',
    ];

    $map = [
        'confirmation'       => ['ยืนยันการจองกิจกรรม', 'จองกิจกรรมสำเร็จ!',               'ระบบได้รับการลงทะเบียนของคุณเรียบร้อยแล้ว',                'success',  null],
        'approved'           => ['การจองได้รับการอนุมัติ', 'การจองได้รับการอนุมัติแล้ว!',  'เจ้าหน้าที่ได้อนุมัติคิวการจองของคุณเรียบร้อยแล้ว',      'approved', 'อนุมัติแล้ว'],
        'cancelled_by_user'  => ['ยกเลิกการจอง',          'ยกเลิกการจองแล้ว',              'การจองกิจกรรมต่อไปนี้ถูกยกเลิกตามคำขอของคุณ',             'cancel',   'ยกเลิกแล้ว'],
        'cancelled_by_admin' => ['แจ้งยกเลิกคิว',         'ขออภัย — มีการยกเลิกคิวของคุณ', 'เจ้าหน้าที่ขอยกเลิกคิวเดิมของคุณ เนื่องจากมีเหตุจำเป็น', 'cancel',   'คิวถูกยกเลิก (กรุณาจองใหม่)'],
    ];

    [$subjectPrefix, $emailTitle, $msgText, $tplType, $statusLabel] = $map[$templateType];

    $details = [
        'กิจกรรม' => $dummyData['campaign_title'],
        'วันที่'   => $dummyData['date'],
        'เวลา'    => $dummyData['time'],
    ];
    if ($statusLabel !== null) {
        $details['สถานะ'] = $statusLabel;
    }

    $greeting = 'สวัสดีคุณ ' . $dummyData['full_name'];
    $body     = get_email_template($emailTitle, "{$greeting} — {$msgText} (อีเมลนี้เป็นการทดสอบระบบ)", $details, $tplType);
    $subject  = "[ทดสอบ] {$subjectPrefix}: " . $dummyData['campaign_title'];
    $ok       = smtp_send($toEmail, $subject, $body, $cfg);

    echo json_encode($ok
        ? ['ok' => true,  'message' => "ส่ง template '{$subjectPrefix}' ไปที่ {$toEmail} สำเร็จ! กรุณาตรวจสอบ Inbox"]
        : ['ok' => false, 'error'   => "ส่ง template '{$subjectPrefix}' ไม่สำเร็จ — ตรวจสอบ SMTP credentials และ PHP error_log"]
    );
    exit;
}

// ── ทดสอบส่งอีเมล ──────────────────────────────────────────────────────────────
$toEmail = trim($_POST['to_email'] ?? '');
if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'อีเมลปลายทางไม่ถูกต้อง']);
    exit;
}

// อ่าน config ที่อาจส่งมาทดสอบ (ไม่บันทึก)
$secrets = get_secrets();
$cfg = [
    'SMTP_HOST'       => trim($_POST['SMTP_HOST']       ?? $secrets['SMTP_HOST']       ?? ''),
    'SMTP_PORT'       => (int)($_POST['SMTP_PORT']       ?? $secrets['SMTP_PORT']       ?? 587),
    'SMTP_USER'       => trim($_POST['SMTP_USER']       ?? $secrets['SMTP_USER']       ?? ''),
    'SMTP_PASS'       => trim($_POST['SMTP_PASS']       ?? $secrets['SMTP_PASS']       ?? ''),
    'SMTP_FROM_EMAIL' => trim($_POST['SMTP_FROM_EMAIL'] ?? $secrets['SMTP_FROM_EMAIL'] ?? ''),
    'SMTP_FROM_NAME'  => trim($_POST['SMTP_FROM_NAME']  ?? $secrets['SMTP_FROM_NAME']  ?? 'RSU Medical Clinic Services'),
];

// ถ้า password ว่างให้ดึงจาก secrets เดิม (กรณีไม่ได้กรอกใหม่)
if ($cfg['SMTP_PASS'] === '' && !empty($secrets['SMTP_PASS'])) {
    $cfg['SMTP_PASS'] = $secrets['SMTP_PASS'];
}

if (empty($cfg['SMTP_HOST']) || empty($cfg['SMTP_USER'])) {
    echo json_encode(['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่า SMTP_HOST หรือ SMTP_USER']);
    exit;
}

// สร้าง HTML test email
$body = get_email_template(
    'ทดสอบระบบอีเมล',
    'อีเมลนี้เป็นการทดสอบการเชื่อมต่อ SMTP ของระบบ RSU Medical Clinic หากคุณได้รับอีเมลนี้ แสดงว่าระบบทำงานได้ปกติแล้ว',
    [
        'SMTP Host'  => $cfg['SMTP_HOST'] . ':' . $cfg['SMTP_PORT'],
        'จาก'        => $cfg['SMTP_FROM_NAME'] . ' <' . ($cfg['SMTP_FROM_EMAIL'] ?: $cfg['SMTP_USER']) . '>',
        'ถึง'         => $toEmail,
        'ทดสอบโดย'  => $_SESSION['admin_name'] ?? 'Superadmin',
        'เวลา'       => date('d/m/Y H:i:s'),
    ],
    'success'
);

$ok = smtp_send($toEmail, 'ทดสอบอีเมล — RSU Medical Clinic', $body, $cfg);

if ($ok) {
    echo json_encode([
        'ok'      => true,
        'message' => "ส่งอีเมลทดสอบไปที่ {$toEmail} สำเร็จ! กรุณาตรวจสอบ Inbox (และ Spam folder)",
    ]);
} else {
    // อ่าน PHP error log บรรทัดสุดท้าย
    $logHint = '';
    $logFile = ini_get('error_log');
    if ($logFile && file_exists($logFile) && is_readable($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $smtpLines = array_filter($lines ?? [], fn($l) => str_contains($l, 'SMTP'));
        if (!empty($smtpLines)) {
            $logHint = ': ' . strip_tags(end($smtpLines));
        }
    }
    echo json_encode([
        'ok'    => false,
        'error' => 'ส่งอีเมลไม่สำเร็จ' . $logHint . ' — ตรวจสอบ SMTP credentials และ PHP error_log',
    ]);
}
