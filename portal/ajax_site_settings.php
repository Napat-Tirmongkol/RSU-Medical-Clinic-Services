<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

// CSRF required — this endpoint writes Gemini API key + uploads logo.
validate_csrf_or_die();

if (($_SESSION['admin_role'] ?? '') !== 'superadmin' && ($_SESSION['admin_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Permission denied']);
    exit;
}

$siteSettingsFile = __DIR__ . '/../config/site_settings.json';
$settings = file_exists($siteSettingsFile) ? json_decode(file_get_contents($siteSettingsFile), true) : [];
if (!is_array($settings)) $settings = [];

$siteName = trim($_POST['site_name'] ?? '');
if ($siteName !== '') {
    $settings['site_name'] = $siteName;
}

$geminiKey = trim($_POST['gemini_api_key'] ?? '');
$settings['gemini_api_key'] = $geminiKey;

$settings['show_insurance'] = isset($_POST['show_insurance']) && $_POST['show_insurance'] === '1';

// Handle Logo Upload — SVG rejected (stored-XSS risk); MIME verified via finfo
// (NOT $_FILES['type'] which is client-controlled); extension forced from
// server-determined MIME (drops attacker-supplied filename extension entirely).
if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
    $maxSize = 2 * 1024 * 1024; // 2MB
    $file = $_FILES['site_logo'];

    if ($file['size'] > $maxSize) {
        echo json_encode(['status' => 'error', 'message' => 'ขนาดไฟล์ต้องไม่เกิน 2MB']);
        exit;
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        echo json_encode(['status' => 'error', 'message' => 'ไฟล์อัปโหลดไม่ถูกต้อง']);
        exit;
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($file['tmp_name']) ?: '';

    if (!isset($mimeToExt[$detectedMime])) {
        echo json_encode(['status' => 'error', 'message' => 'รองรับเฉพาะไฟล์ PNG, JPG, WEBP เท่านั้น (ไม่รองรับ SVG)']);
        exit;
    }

    $uploadDir = __DIR__ . '/../assets/images/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = 'site_logo_' . bin2hex(random_bytes(8)) . '.' . $mimeToExt[$detectedMime];
    $targetPath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        // Delete old logo if exists — confine to assets/images/ via realpath.
        if (!empty($settings['site_logo'])) {
            $oldAbs = realpath(__DIR__ . '/../' . $settings['site_logo']);
            $assetsRoot = realpath(__DIR__ . '/../assets/images') ?: '';
            if ($oldAbs && $assetsRoot && str_starts_with($oldAbs, $assetsRoot . DIRECTORY_SEPARATOR)) {
                @unlink($oldAbs);
            }
        }
        $settings['site_logo'] = 'assets/images/' . $filename;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์']);
        exit;
    }
}

// Save to JSON
$savedJson = file_put_contents($siteSettingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Save to Database (Persistence Layer)
try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO sys_site_settings (setting_key, setting_value) VALUES (:key, :val) ON DUPLICATE KEY UPDATE setting_value = :val2");
    foreach ($settings as $key => $val) {
        // Convert boolean/numeric to string for storage if needed
        $saveVal = is_bool($val) ? ($val ? '1' : '0') : (string)$val;
        $stmt->execute([':key' => $key, ':val' => $saveVal, ':val2' => $saveVal]);
    }
    $savedDb = true;
} catch (Exception $e) {
    $savedDb = false;
    error_log("Settings DB Save Error: " . $e->getMessage());
}

if ($savedJson || $savedDb) {
    log_activity('update_site_settings', "อัปเดตการตั้งค่าเว็บไซต์: {$settings['site_name']}");
    echo json_encode(['status' => 'success', 'message' => 'บันทึกการตั้งค่าสำเร็จ!', 'data' => $settings]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถบันทึกข้อมูลได้ (Storage Error)']);
}
