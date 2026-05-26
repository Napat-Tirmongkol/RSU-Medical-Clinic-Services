<?php
/**
 * portal/accident_log_import.php — Excel import (วันที่ + จำนวน)
 *
 * Accepts .xlsx / .xls / .csv with 2 columns:
 *   A: วันที่ (YYYY-MM-DD หรือ Excel serial)
 *   B: จำนวนครั้ง (integer ≥ 0)
 *
 * Behavior:
 *   - Idempotent — duplicate entry_date → UPDATE (latest count wins)
 *   - Invalid date / negative count → skipped
 *   - Empty row → skipped
 *   - Returns: { ok, inserted, updated, skipped }
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsxDate;

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$can = ($adminRole === 'superadmin') || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
if (!$can) { echo json_encode(['ok' => false, 'message' => 'Access Denied']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']); exit;
}
if (function_exists('validate_csrf_or_die')) {
    validate_csrf_or_die();
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'message' => 'อัปโหลดไฟล์ไม่สำเร็จ']); exit;
}

// File size cap 5 MB
if (($_FILES['file']['size'] ?? 0) > 5 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'message' => 'ไฟล์ใหญ่เกิน 5 MB']); exit;
}

$pdo = db();
$_who = $_SESSION['admin_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'admin';

try {
    // Ensure table exists (same migration as AJAX endpoint)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_accident_daily (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_date DATE NOT NULL,
        accident_count INT UNSIGNED NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_by VARCHAR(100) NULL,
        updated_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_entry_date (entry_date),
        INDEX idx_date (entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $reader = IOFactory::createReaderForFile($_FILES['file']['tmp_name']);
    $reader->setReadDataOnly(true);
    $ss = $reader->load($_FILES['file']['tmp_name']);
    $rows = $ss->getActiveSheet()->toArray(null, true, true, false);

    // Locate header row — first row whose A cell contains "วันที่"
    $startIdx = 0;
    foreach ($rows as $i => $r) {
        $first = trim((string)($r[0] ?? ''));
        if ($first !== '' && mb_strpos($first, 'วันที่') !== false) {
            $startIdx = $i + 1;
            break;
        }
    }
    if ($startIdx === 0) {
        // No header found — assume row 0 is data (allow plain CSVs)
        $startIdx = 0;
    }

    $checkStmt = $pdo->prepare("SELECT id FROM sys_accident_daily WHERE entry_date = :d");
    $insStmt   = $pdo->prepare("INSERT INTO sys_accident_daily
        (entry_date, accident_count, created_by, updated_by) VALUES (:d, :c, :u, :u)");
    $updStmt   = $pdo->prepare("UPDATE sys_accident_daily
        SET accident_count = :c, updated_by = :u WHERE id = :id");

    $inserted = 0; $updated = 0; $skipped = 0;
    $total = count($rows);
    for ($i = $startIdx; $i < $total; $i++) {
        $r = $rows[$i];
        $dateRaw  = trim((string)($r[0] ?? ''));
        $countRaw = trim((string)($r[1] ?? ''));
        if ($dateRaw === '' && $countRaw === '') continue; // empty row

        // Parse date — accept Excel serial (numeric) or string formats
        $date = null;
        if (is_numeric($dateRaw)) {
            try { $date = date('Y-m-d', XlsxDate::excelToTimestamp((float)$dateRaw)); }
            catch (Throwable) { $date = null; }
        } else {
            $ts = strtotime($dateRaw);
            if ($ts) $date = date('Y-m-d', $ts);
        }
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }

        // Reject obviously absurd dates (e.g., year < 2000 / > 2100)
        $y = (int)substr($date, 0, 4);
        if ($y < 2000 || $y > 2100) { $skipped++; continue; }

        // Parse count
        if ($countRaw === '' || !is_numeric($countRaw)) { $skipped++; continue; }
        $count = max(0, (int)$countRaw);

        $checkStmt->execute([':d' => $date]);
        $existId = (int)$checkStmt->fetchColumn();
        if ($existId) {
            $updStmt->execute([':c' => $count, ':u' => $_who, ':id' => $existId]);
            $updated++;
        } else {
            $insStmt->execute([':d' => $date, ':c' => $count, ':u' => $_who]);
            $inserted++;
        }
    }

    if (function_exists('log_activity')) {
        log_activity('Accident Log', "Import: insert=$inserted update=$updated skip=$skipped");
    }
    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
    ]);
} catch (Throwable $e) {
    error_log('[accident_log_import] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'อ่านไฟล์ไม่ได้ — ตรวจรูปแบบ Excel แล้วลองใหม่']);
}
