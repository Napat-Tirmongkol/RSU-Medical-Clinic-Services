<?php
// portal/nurse_productivity_import.php — Excel import via PhpSpreadsheet
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$can = ($adminRole === 'superadmin') || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
if (!$can) { echo json_encode(['ok' => false, 'message' => 'Access Denied']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
validate_csrf_or_die();

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$deptId = (int)($_POST['dept_id'] ?? 0);
if (!$deptId) { echo json_encode(['ok' => false, 'message' => 'dept_id required']); exit; }

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'message' => 'อัปโหลดไฟล์ไม่สำเร็จ']); exit;
}

// Schedule helper (re-implemented compact for this script)
function np_derive(PDO $pdo, string $entryDate): ?array {
    static $cache = [];
    $ts = strtotime($entryDate); if (!$ts) return null;
    $yearBE = (int)date('Y', $ts) + 543;
    $month = (int)date('n', $ts);
    $day = (int)date('j', $ts);
    $key = $yearBE . '-' . $month;
    if (!isset($cache[$key])) {
        $st = $pdo->prepare("SELECT schedule_json FROM sys_nurse_schedule_monthly WHERE year_be = ? AND month = ?");
        $st->execute([$yearBE, $month]);
        $sched = $st->fetchColumn();
        $g = $pdo->query("SELECT nurses_json FROM sys_nurse_schedule_global WHERE id = 1")->fetchColumn();
        $cache[$key] = ['s' => $sched ? json_decode($sched, true) : null, 'n' => $g ? json_decode($g, true) : null];
    }
    $s = $cache[$key]['s']; $n = $cache[$key]['n'];
    if (!$s || !$n || !is_array($s) || !is_array($n)) return null;
    $rn = 0; $head = 0;
    foreach ($n as $row) {
        if (!is_array($row)) continue;
        $code = $s[($row['id'] ?? '') . '-' . $day] ?? null;
        if ($code === null || $code === '' || $code === 'O' || $code === 'o') continue;
        $pos = (string)($row['position'] ?? '');
        if ($pos === 'พยาบาลวิชาชีพ') $rn++;
        elseif (in_array($pos, ['หัวหน้าหอผู้ป่วย','รองหัวหน้าหอผู้ป่วย','พยาบาลหัวหน้าเวร'], true)) $head++;
    }
    return ['rn' => $rn, 'head' => $head];
}

try {
    $reader = IOFactory::createReaderForFile($_FILES['file']['tmp_name']);
    $reader->setReadDataOnly(true);
    $ss = $reader->load($_FILES['file']['tmp_name']);
    $rows = $ss->getActiveSheet()->toArray(null, true, true, false);

    $inserted = 0; $updated = 0; $skipped = 0;
    // Find header row (first row that has "วันที่" cell)
    $startIdx = 0;
    foreach ($rows as $i => $r) {
        $first = trim((string)($r[0] ?? ''));
        if (str_contains($first, 'วันที่')) { $startIdx = $i + 1; break; }
    }
    if ($startIdx === 0) $startIdx = 1; // assume row 0 is header

    $upsertCheck = $pdo->prepare("SELECT id FROM sys_nurse_productivity_daily WHERE dept_id = ? AND entry_date = ?");
    $insStmt = $pdo->prepare("INSERT INTO sys_nurse_productivity_daily
        (dept_id, entry_date, patients, rn_count, head_count, shift_hours, note, rn_source, head_source, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $updStmt = $pdo->prepare("UPDATE sys_nurse_productivity_daily SET
        patients = ?, rn_count = ?, head_count = ?, shift_hours = ?, note = ?,
        rn_source = ?, head_source = ?, updated_by = ?
        WHERE id = ?");

    for ($i = $startIdx; $i < count($rows); $i++) {
        $r = $rows[$i];
        $dateRaw = trim((string)($r[0] ?? ''));
        if ($dateRaw === '') continue;
        // Accept YYYY-MM-DD or Excel serial date
        if (is_numeric($dateRaw)) {
            $date = date('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$dateRaw));
        } else {
            $ts = strtotime($dateRaw);
            if (!$ts) { $skipped++; continue; }
            $date = date('Y-m-d', $ts);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $skipped++; continue; }

        $patients = max(0, (int)($r[1] ?? 0));
        $rnRaw    = trim((string)($r[2] ?? ''));
        $headRaw  = trim((string)($r[3] ?? ''));
        $shift    = (float)($r[4] ?? 7);
        $note     = mb_substr((string)($r[5] ?? ''), 0, 500);

        $rn = $rnRaw === '' ? null : max(0, (int)$rnRaw);
        $head = $headRaw === '' ? null : max(0, (int)$headRaw);
        $rnSrc = 'manual'; $headSrc = 'manual';
        if ($rn === null || $head === null) {
            $derived = np_derive($pdo, $date);
            if ($derived) {
                if ($rn === null)   { $rn = (int)$derived['rn'];   $rnSrc = 'schedule'; }
                if ($head === null) { $head = (int)$derived['head']; $headSrc = 'schedule'; }
            }
        }
        $rn ??= 0; $head ??= 0;

        $upsertCheck->execute([$deptId, $date]);
        $existId = (int)$upsertCheck->fetchColumn();
        if ($existId) {
            $updStmt->execute([$patients, $rn, $head, $shift, $note, $rnSrc, $headSrc, $adminId ?: null, $existId]);
            $updated++;
        } else {
            $insStmt->execute([$deptId, $date, $patients, $rn, $head, $shift, $note, $rnSrc, $headSrc, $adminId ?: null, $adminId ?: null]);
            $inserted++;
        }
    }

    echo json_encode(['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'ระบบขัดข้อง กรุณาลองอีกครั้ง']);
}
