<?php
/**
 * portal/edms_file.php — serve EDMS attachment files
 *
 * GET ?id=<attachment_id>&disposition=inline|attachment
 *
 * เหตุผลที่แยกจาก ajax_edms.php: ไฟล์ดาวน์โหลด/ดู inline ไม่สามารถส่งผ่าน AJAX JSON ได้
 * ผู้ที่ผ่านการเข้าสู่ระบบ portal และมีสิทธิ์ access_edms (หรือ superadmin) เท่านั้น
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$hasEdms   = !empty($_SESSION['access_edms']);

if (!$isSuper && !$hasEdms) {
    http_response_code(403);
    exit('Permission denied');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid id');
}

$disposition = ($_GET['disposition'] ?? 'inline') === 'attachment' ? 'attachment' : 'inline';

try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT file_name, stored_path, mime_type, file_size FROM sys_doc_attachments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database error');
}

if (!$row) {
    http_response_code(404);
    exit('File not found');
}

$base = dirname(__DIR__) . '/uploads/edms';
$path = $base . '/' . $row['stored_path'];

// ป้องกัน path traversal — resolved path ต้องอยู่ใต้ base
$realBase = realpath($base);
$realPath = realpath($path);
if (!$realBase || !$realPath || !str_starts_with($realPath, $realBase)) {
    http_response_code(403);
    exit('Forbidden');
}

if (!is_file($realPath)) {
    http_response_code(404);
    exit('File missing on disk');
}

$mime = $row['mime_type'] ?: (function_exists('mime_content_type') ? @mime_content_type($realPath) : 'application/octet-stream');
$mime = $mime ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realPath));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($row['file_name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

readfile($realPath);
exit;
