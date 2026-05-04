<?php
// asset/ajax/upload_attachment.php — อัปโหลดเอกสารแนบของครุภัณฑ์
require_once __DIR__ . '/../includes/check_session_ajax.php';
require_once __DIR__ . '/../includes/helpers.php';

asset_require_manage_ajax();
asset_validate_csrf_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'method_not_allowed']);
    exit;
}

$assetId = (int)($_POST['asset_id'] ?? 0);
if ($assetId <= 0 || empty($_FILES['file'])) {
    echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ครบ']);
    exit;
}

$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'message' => 'อัปโหลดล้มเหลว (error ' . $f['error'] . ')']);
    exit;
}
if ($f['size'] > 10 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'message' => 'ไฟล์ใหญ่เกิน 10 MB']);
    exit;
}

// ตรวจ mime type
$allowed = [
    'application/pdf'                                                          => 'pdf',
    'image/jpeg'                                                               => 'jpg',
    'image/png'                                                                => 'png',
    'image/webp'                                                               => 'webp',
    'application/msword'                                                       => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'  => 'docx',
    'application/vnd.ms-excel'                                                 => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'        => 'xlsx',
];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $f['tmp_name']);
finfo_close($finfo);
if (!isset($allowed[$mime])) {
    echo json_encode(['ok' => false, 'message' => 'รองรับเฉพาะ PDF/Word/Excel/รูปภาพ (ไฟล์: ' . $mime . ')']);
    exit;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM assets WHERE id = ?");
    $stmt->execute([$assetId]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'ไม่พบครุภัณฑ์']);
        exit;
    }

    $ext     = $allowed[$mime];
    $name    = 'att_' . $assetId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destDir = __DIR__ . '/../uploads/attachments/';
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $dest = $destDir . $name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'message' => 'บันทึกไฟล์ไม่สำเร็จ']);
        exit;
    }

    $rel = 'uploads/attachments/' . $name;
    $orig = mb_substr((string)$f['name'], 0, 250);
    $ins = $pdo->prepare(
        "INSERT INTO asset_attachments (asset_id, file_path, file_name, mime_type, uploaded_by)
         VALUES (?, ?, ?, ?, ?)"
    );
    $ins->execute([$assetId, $rel, $orig, $mime, $_SESSION['user_id'] ?? null]);

    echo json_encode([
        'ok' => true,
        'attachment' => [
            'id'         => (int)$pdo->lastInsertId(),
            'file_path'  => $rel,
            'file_name'  => $orig,
            'mime_type'  => $mime,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
