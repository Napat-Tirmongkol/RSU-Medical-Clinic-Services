<?php
/**
 * portal/ajax_insurance_policy_upload.php
 * Admin อัปโหลด CSV เพื่ออัปเดตเลขกรมธรรม์ของสมาชิกประกัน
 *
 * ใช้เมื่อ:
 *  - คลินิกได้รับ list จากบริษัทประกันโดยตรง (ไม่ผ่าน partner portal)
 *  - ต้องการ bulk update policy_number / coverage_start / coverage_end / remarks
 *
 * CSV format (ใช้ pattern เดียวกับ insurance_partner/import_policy.php):
 *   - member_id / รหัสนักศึกษา / รหัสบุคลากร (required)
 *   - policy_number / เลขกรมธรรม์ (required)
 *   - coverage_start / วันเริ่มต้นสิทธิ์ (optional, YYYY-MM-DD)
 *   - coverage_end / วันสิ้นสุดสิทธิ์ (optional, YYYY-MM-DD)
 *   - remarks / หมายเหตุ (optional)
 *
 * Body: multipart form
 *   csv_file: uploaded file
 *   csrf_token
 *   force_company: (optional) override insurance_company filter
 *                  default = ไม่ filter → update ทุกบริษัท · เฉพาะ superadmin
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'admin');
$adminId   = (int)($_SESSION['admin_id'] ?? 0);

// Permission: admin / superadmin / access_insurance
$hasAccess = ($adminRole === 'superadmin') || ($adminRole === 'admin') || !empty($_SESSION['access_insurance']);
if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'ต้องมีสิทธิ์ access_insurance']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
validate_csrf_or_die();

if (empty($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'กรุณาแนบไฟล์ CSV']);
    exit;
}
$file = $_FILES['csv_file'];
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'ไฟล์ใหญ่เกิน 5MB']);
    exit;
}

// Read + strip UTF-8 BOM (Excel exports prefix this)
$content = file_get_contents($file['tmp_name']);
if ($content === false) {
    echo json_encode(['ok' => false, 'error' => 'อ่านไฟล์ไม่สำเร็จ']);
    exit;
}
if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);

// Split lines (handle \r\n, \n, \r)
$lines = preg_split('/\r\n|\n|\r/', $content);
$lines = array_values(array_filter($lines, fn($l) => trim((string)$l) !== ''));
if (count($lines) < 2) {
    echo json_encode(['ok' => false, 'error' => 'ไฟล์ไม่มีข้อมูล (อย่างน้อยต้องมี 1 row นอกจาก header)']);
    exit;
}

// Parse header
$headerCsv = str_getcsv(array_shift($lines));
$headerMap = [];
$aliases = [
    'รหัสบุคลากร/นักศึกษา' => 'member_id',
    'รหัสนักศึกษา'        => 'member_id',
    'รหัสบุคลากร'         => 'member_id',
    'member_id'           => 'member_id',
    'memberid'            => 'member_id',
    'เลขกรมธรรม์'         => 'policy_number',
    'policy_number'       => 'policy_number',
    'policy'              => 'policy_number',
    'policyno'            => 'policy_number',
    'policy_no'           => 'policy_number',
    'วันเริ่มต้นสิทธิ์'   => 'coverage_start',
    'coverage_start'      => 'coverage_start',
    'coverage start'      => 'coverage_start',
    'startdate'           => 'coverage_start',
    'วันสิ้นสุดสิทธิ์'    => 'coverage_end',
    'coverage_end'        => 'coverage_end',
    'coverage end'        => 'coverage_end',
    'enddate'             => 'coverage_end',
    'หมายเหตุ'            => 'remarks',
    'remarks'             => 'remarks',
];
foreach ($headerCsv as $i => $h) {
    $key = strtolower(trim((string)$h));
    if (isset($aliases[$key])) {
        $headerMap[$i] = $aliases[$key];
    } elseif (isset($aliases[trim((string)$h)])) {
        // Thai keys are not lowercase-affected
        $headerMap[$i] = $aliases[trim((string)$h)];
    }
}

if (!in_array('member_id', $headerMap, true) || !in_array('policy_number', $headerMap, true)) {
    echo json_encode([
        'ok' => false,
        'error' => 'ไฟล์ต้องมี column "member_id" และ "policy_number" (หรือชื่อไทย "รหัสนักศึกษา" และ "เลขกรมธรรม์")',
    ]);
    exit;
}

$pdo = db();

// Pre-flight: check coverage_* + remarks columns exist
$colCheck = $pdo->query("SHOW COLUMNS FROM insurance_members")->fetchAll(PDO::FETCH_COLUMN);
$hasCoverage = in_array('coverage_start', $colCheck, true) && in_array('coverage_end', $colCheck, true);
$hasRemarks  = in_array('remarks', $colCheck, true);

// Build UPDATE SQL dynamically (กัน column missing)
$updateFields = ['policy_number = :pn'];
if ($hasCoverage) {
    $updateFields[] = 'coverage_start = COALESCE(NULLIF(:cs, ""), coverage_start)';
    $updateFields[] = 'coverage_end   = COALESCE(NULLIF(:ce, ""), coverage_end)';
}
if ($hasRemarks) {
    $updateFields[] = 'remarks = CASE WHEN :rm <> "" THEN :rm2 ELSE remarks END';
}
$updateSql = 'UPDATE insurance_members SET ' . implode(', ', $updateFields)
            . ' WHERE member_id = :mid';
$updateStmt = $pdo->prepare($updateSql);

$checkStmt = $pdo->prepare("SELECT member_id, policy_number, insurance_status FROM insurance_members WHERE member_id = :mid LIMIT 1");

$pdo->beginTransaction();
try {
    $cntOk = 0;
    $cntSkipNoMember = 0;
    $cntSkipNoChange = 0;
    $errors = [];

    foreach ($lines as $ln => $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $cols = str_getcsv($line);
        $row = [];
        foreach ($cols as $i => $val) {
            $key = $headerMap[$i] ?? null;
            if ($key) $row[$key] = trim((string)$val);
        }
        $memberId = $row['member_id'] ?? '';
        $policyNo = $row['policy_number'] ?? '';

        if ($memberId === '' || $policyNo === '') {
            $errors[] = "บรรทัด " . ($ln + 2) . ": member_id หรือ policy_number ว่าง";
            continue;
        }

        // Validate date format ถ้ามี
        $covStart = $row['coverage_start'] ?? '';
        $covEnd   = $row['coverage_end']   ?? '';
        foreach (['coverage_start' => $covStart, 'coverage_end' => $covEnd] as $field => $val) {
            if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $errors[] = "บรรทัด " . ($ln + 2) . ": {$field} ต้องเป็น YYYY-MM-DD (ได้รับ: {$val})";
                continue 2;
            }
        }
        $remarks = $row['remarks'] ?? '';

        $checkStmt->execute([':mid' => $memberId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $cntSkipNoMember++;
            continue;
        }

        $params = [
            ':pn'  => $policyNo,
            ':mid' => $memberId,
        ];
        if ($hasCoverage) {
            $params[':cs'] = $covStart;
            $params[':ce'] = $covEnd;
        }
        if ($hasRemarks) {
            $params[':rm']  = $remarks;
            $params[':rm2'] = $remarks;
        }
        $updateStmt->execute($params);

        if ($updateStmt->rowCount() > 0) {
            $cntOk++;
        } else {
            $cntSkipNoChange++;
        }
    }

    $pdo->commit();

    // Best-effort activity log
    if (function_exists('log_activity')) {
        @log_activity('Insurance Policy Bulk Upload',
            "Updated={$cntOk}, skipped_no_member={$cntSkipNoMember}, skipped_no_change={$cntSkipNoChange}, errors=" . count($errors));
    }

    echo json_encode([
        'ok' => true,
        'message' => "อัปเดตสำเร็จ {$cntOk} ราย",
        'stats' => [
            'updated' => $cntOk,
            'skipped_no_member' => $cntSkipNoMember,
            'skipped_no_change' => $cntSkipNoChange,
            'errors' => $errors,
            'error_count' => count($errors),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ajax_insurance_policy_upload] ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
