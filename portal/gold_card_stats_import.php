<?php
/**
 * portal/gold_card_stats_import.php — Excel import (ปี, เดือน, จำนวน)
 *
 * Accepts .xlsx / .xls / .csv with 3 columns:
 *   A: ปี (พ.ศ. หรือ ค.ศ. — auto-detect)
 *   B: เดือน (ตัวเลข 1-12 หรือชื่อภาษาไทย)
 *   C: จำนวน
 *
 * รองรับ format ของ user ที่มี 4 คอลัมน์ (ปี / เดือน / วันที่ / จำนวน):
 *   ถ้า column count > 3 → ใช้ A, B, D (ข้าม C ที่เป็นวันที่)
 *
 * Idempotent: ปี+เดือนซ้ำ → UPDATE
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$can = $isSuper || !empty($_SESSION['access_insurance']);
if (!$can) { echo json_encode(['ok' => false, 'message' => 'Access Denied']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']); exit;
}
if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'message' => 'อัปโหลดไฟล์ไม่สำเร็จ']); exit;
}
if (($_FILES['file']['size'] ?? 0) > 5 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'message' => 'ไฟล์ใหญ่เกิน 5 MB']); exit;
}

$pdo = db();
$_who = $_SESSION['admin_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'admin';

/** ── Thai month string → 1..12 ── */
function gcs_parse_month($s): ?int {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (is_numeric($s)) {
        $n = (int)$s;
        return ($n >= 1 && $n <= 12) ? $n : null;
    }
    static $map = null;
    if ($map === null) {
        $names = [
            1  => ['มกราคม','ม.ค.','มค'],
            2  => ['กุมภาพันธ์','ก.พ.','กพ'],
            3  => ['มีนาคม','มี.ค.','มีค'],
            4  => ['เมษายน','เม.ย.','เมย'],
            5  => ['พฤษภาคม','พ.ค.','พค'],
            6  => ['มิถุนายน','มิ.ย.','มิย'],
            7  => ['กรกฎาคม','ก.ค.','กค'],
            8  => ['สิงหาคม','ส.ค.','สค'],
            9  => ['กันยายน','ก.ย.','กย'],
            10 => ['ตุลาคม','ต.ค.','ตค'],
            11 => ['พฤศจิกายน','พ.ย.','พย'],
            12 => ['ธันวาคม','ธ.ค.','ธค'],
        ];
        $map = [];
        foreach ($names as $n => $list) {
            foreach ($list as $k) $map[$k] = $n;
        }
    }
    // Try exact + first 3 chars of name
    if (isset($map[$s])) return $map[$s];
    foreach ($map as $k => $v) {
        if (mb_strpos($s, $k) === 0) return $v;
    }
    return null;
}

try {
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_gold_card_monthly_stats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year_be SMALLINT UNSIGNED NOT NULL,
        month TINYINT UNSIGNED NOT NULL,
        member_count INT UNSIGNED NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_by VARCHAR(100) NULL,
        updated_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_year_month (year_be, month),
        INDEX idx_year_month (year_be, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $origName = $_FILES['file']['name'] ?? '';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $reader = IOFactory::createReader('Csv');
        $reader->setDelimiter(','); $reader->setEnclosure('"');
        $reader->setInputEncoding('UTF-8');
    } elseif ($ext === 'xls') {
        $reader = IOFactory::createReader('Xls');
    } else {
        $reader = IOFactory::createReader('Xlsx');
    }
    $reader->setReadDataOnly(true);
    $ss = $reader->load($_FILES['file']['tmp_name']);
    $rows = $ss->getActiveSheet()->toArray(null, true, true, false);

    // Locate header row — first row with "ปี" or "year" in col A
    $startIdx = 0;
    foreach ($rows as $i => $r) {
        $first = trim((string)($r[0] ?? ''));
        if ($first !== '' && (mb_strpos($first, 'ปี') !== false || stripos($first, 'year') !== false)) {
            $startIdx = $i + 1;
            break;
        }
    }

    // Detect whether the file is 4-column legacy (user's original Excel)
    // by inspecting first data row — if col C looks like a date (slash or dash) → use D for count
    $useColDForCount = false;
    if ($startIdx < count($rows)) {
        $sample = $rows[$startIdx] ?? null;
        if ($sample && isset($sample[2]) && isset($sample[3])) {
            $cVal = (string)$sample[2];
            // Excel serial numbers อาจ format ผิด ใช้ regex จับ DD/MM/YYYY หรือ YYYY-MM-DD
            if (preg_match('#[/-]#', $cVal) || (is_numeric($cVal) && (float)$cVal > 40000)) {
                $useColDForCount = true;
            }
        }
    }

    $checkStmt = $pdo->prepare("SELECT id FROM sys_gold_card_monthly_stats WHERE year_be = :y AND month = :m");
    $insStmt   = $pdo->prepare("INSERT INTO sys_gold_card_monthly_stats
        (year_be, month, member_count, created_by, updated_by)
        VALUES (:y, :m, :c, :cu, :uu)");
    $updStmt   = $pdo->prepare("UPDATE sys_gold_card_monthly_stats
        SET member_count = :c, updated_by = :u WHERE id = :id");

    $inserted = 0; $updated = 0; $skipped = 0;
    $total = count($rows);
    for ($i = $startIdx; $i < $total; $i++) {
        $r = $rows[$i];
        $yearRaw  = trim((string)($r[0] ?? ''));
        $monthRaw = trim((string)($r[1] ?? ''));
        $countRaw = trim((string)(($useColDForCount ? ($r[3] ?? '') : ($r[2] ?? ''))));
        if ($yearRaw === '' && $monthRaw === '' && $countRaw === '') continue;

        // Year parse
        if (!is_numeric($yearRaw)) { $skipped++; continue; }
        $year = (int)$yearRaw;
        if ($year > 0 && $year < 2400) $year += 543;     // ค.ศ. → พ.ศ.
        if ($year < 2500 || $year > 2700) { $skipped++; continue; }

        // Month parse
        $month = gcs_parse_month($monthRaw);
        if ($month === null) { $skipped++; continue; }

        // Count parse — strip commas, accept decimal (round to int)
        $countClean = preg_replace('/[\s,]/', '', $countRaw);
        if ($countClean === '' || !is_numeric($countClean)) { $skipped++; continue; }
        $count = max(0, (int)round((float)$countClean));

        $checkStmt->execute([':y' => $year, ':m' => $month]);
        $existId = (int)$checkStmt->fetchColumn();
        if ($existId) {
            $updStmt->execute([':c' => $count, ':u' => $_who, ':id' => $existId]);
            $updated++;
        } else {
            $insStmt->execute([':y' => $year, ':m' => $month, ':c' => $count, ':cu' => $_who, ':uu' => $_who]);
            $inserted++;
        }
    }

    if (function_exists('log_activity')) {
        log_activity('Gold Card Stats', "Import: insert=$inserted update=$updated skip=$skipped" . ($useColDForCount ? ' (4-col)' : ''));
    }
    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'format'   => $useColDForCount ? '4-column (ปี / เดือน / วันที่ / จำนวน)' : '3-column (ปี / เดือน / จำนวน)',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[gold_card_stats_import] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'message' => 'อ่านไฟล์ไม่ได้: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
