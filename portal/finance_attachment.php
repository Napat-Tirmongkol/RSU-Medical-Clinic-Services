<?php
/**
 * portal/finance_attachment.php — auth-gated download proxy for
 * receipt attachments stored under uploads/finance/.
 *
 * Sits behind the same portal admin session + finance access check
 * as ajax_finance.php. We never serve uploads/finance/* directly
 * because receipts can contain sensitive billing info.
 *
 * Usage: finance_attachment.php?id=<attachment_id>
 *         finance_attachment.php?id=<attachment_id>&download=1   (force download dialog)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper   = ($adminRole === 'superadmin');
$canFinance = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_finance']);
if (!$canFinance) { http_response_code(403); exit('Access denied'); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$pdo = db();
$stmt = $pdo->prepare("SELECT stored_name, original_name, mime_type, size_bytes
    FROM sys_finance_attachments WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Not found'); }

$abs = __DIR__ . '/../' . $row['stored_name'];
if (!is_file($abs)) { http_response_code(404); exit('File missing on disk'); }

// Defence: ensure resolved path is still under uploads/finance/
$realBase = realpath(__DIR__ . '/../uploads/finance');
$realFile = realpath($abs);
if (!$realBase || !$realFile || strpos($realFile, $realBase) !== 0) {
    http_response_code(403); exit('Forbidden path');
}

$mime = $row['mime_type'] ?: 'application/octet-stream';
$disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
$orig = $row['original_name'] ?: basename($row['stored_name']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realFile));
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($orig) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

readfile($realFile);
exit;
