<?php
// portal/ajax_insurance_sync.php
declare(strict_types=1);

// Let auth.php handle session_start() with correct cookie settings (secure, samesite)
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

// Staff ที่ไม่ใช่ admin/superadmin ไม่มีสิทธิ์
$adminRole = $_SESSION['admin_role'] ?? '';
$isStaff   = !empty($_SESSION['is_ecampaign_staff']);
if ($isStaff && $adminRole === '') {
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึงระบบนี้']);
    exit;
}

$allowedRoles = ['admin', 'superadmin', 'editor'];
if (!in_array($adminRole, $allowedRoles, true)) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึงระบบนี้']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// CSRF protection — dual strategy:
// 1) Origin header check (reliable for same-origin AJAX, no session needed)
// 2) Session token fallback (for old browsers / proxies that strip Origin)
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$serverHost    = $_SERVER['HTTP_HOST'] ?? '';
$proto = !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
    ? $_SERVER['HTTP_X_FORWARDED_PROTO']
    : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
$expectedOrigin = $proto . '://' . $serverHost;

$originOk  = ($requestOrigin !== '' && $requestOrigin === $expectedOrigin);
$sessionOk = verify_csrf_token($_POST['csrf_token'] ?? '');

if (!$originOk && !$sessionOk) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed กรุณาโหลดหน้าใหม่']);
    exit;
}

// Detect PHP silently dropping POST body when post_max_size exceeded
if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    $limit = ini_get('post_max_size');
    echo json_encode(['status' => 'error', 'message' => "ไฟล์มีขนาดใหญ่เกิน limit ({$limit}) กรุณาลดขนาดไฟล์หรือติดต่อผู้ดูแลระบบ"]);
    exit;
}

$action = $_POST['action'] ?? '';
$pdo    = db();

// ── Auto-migrate tables ───────────────────────────────────────────────────────
function ensure_insurance_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS insurance_members (
            member_id           VARCHAR(20)   NOT NULL,
            full_name           VARCHAR(255)  NOT NULL DEFAULT '',
            member_status       VARCHAR(50)   NOT NULL DEFAULT '',
            position            VARCHAR(100)  NOT NULL DEFAULT '',
            citizen_id          VARCHAR(13)   NOT NULL DEFAULT '',
            date_of_birth       DATE          NULL,
            insurance_status    ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            coverage_start      DATE          NULL,
            coverage_end        DATE          NULL,
            policy_number       VARCHAR(100)  NOT NULL DEFAULT '',
            remarks             TEXT          NULL,
            last_sync_id        INT           NULL,
            manually_overridden TINYINT(1)    NOT NULL DEFAULT 0,
            updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (member_id),
            INDEX idx_citizen_id (citizen_id),
            INDEX idx_insurance_status (insurance_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS insurance_sync_logs (
            id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            synced_by           INT           NOT NULL,
            filename            VARCHAR(255)  NOT NULL DEFAULT '',
            total_matched       INT           NOT NULL DEFAULT 0,
            total_inactivated   INT           NOT NULL DEFAULT 0,
            total_newcomers     INT           NOT NULL DEFAULT 0,
            total_active        INT           NOT NULL DEFAULT 0,
            synced_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes               TEXT          NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS insurance_member_history (
            id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            member_id   VARCHAR(20)   NOT NULL,
            sync_id     INT           NULL,
            change_type ENUM('matched','inactivated','inserted','manual') NOT NULL,
            old_status  VARCHAR(20)   NOT NULL DEFAULT '',
            new_status  VARCHAR(20)   NOT NULL DEFAULT '',
            snapshot    JSON          NULL,
            changed_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_member_id (member_id),
            INDEX idx_sync_id (sync_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// ── CSV Parsing helpers ───────────────────────────────────────────────────────

/**
 * Detect TIS-620 / Windows-874 and convert to UTF-8
 */
function decode_csv_content(string $raw): string {
    if (mb_detect_encoding($raw, ['UTF-8'], true) === 'UTF-8') {
        return $raw;
    }
    $converted = iconv('Windows-874', 'UTF-8//TRANSLIT//IGNORE', $raw);
    return $converted !== false ? $converted : $raw;
}

/**
 * Parse CSV string into array of assoc rows.
 * @param string[] $required  required column names (default: ['member_id'])
 */
function parse_insurance_csv(string $csvText, array $required = ['member_id']): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($csvText));
    if (count($lines) < 2) {
        return ['error' => 'ไฟล์ CSV ต้องมีอย่างน้อย 1 แถวข้อมูล (ไม่นับหัวตาราง)'];
    }

    // Parse header
    $headerLine = array_shift($lines);
    // Handle BOM
    $headerLine = ltrim($headerLine, "\xEF\xBB\xBF");
    $headers = str_getcsv($headerLine);
    $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

    foreach ($required as $r) {
        if (!in_array($r, $headers, true)) {
            return ['error' => "ไม่พบคอลัมน์ที่จำเป็น: {$r}"];
        }
    }

    $rows = [];
    $seen = [];

    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        if ($line === '') continue;

        $cols = str_getcsv($line);

        // Pad missing columns
        while (count($cols) < count($headers)) {
            $cols[] = '';
        }

        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = trim($cols[$i] ?? '');
        }

        $mid = $row['member_id'] ?? '';
        if ($mid === '') continue;

        // Dedup — keep first occurrence
        if (isset($seen[$mid])) continue;
        $seen[$mid] = true;

        $rows[] = $row;
    }

    if (empty($rows)) {
        return ['error' => 'ไม่พบข้อมูลในไฟล์ CSV'];
    }

    return ['rows' => $rows];
}

function normalise_date(?string $d): ?string {
    if (!$d) return null;
    // Try common formats: d/m/Y, Y-m-d, d-m-Y
    foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $d);
        if ($dt) return $dt->format('Y-m-d');
    }
    return null;
}

/**
 * Merge insurance row + registry row into one record.
 * Registry is authoritative for personal info; insurance for coverage info.
 */
function merge_member_data(array $ins, array $reg): array {
    return [
        'member_id'      => $ins['member_id'],
        'full_name'      => ($reg['full_name'] ?? '') ?: ($ins['full_name'] ?? ''),
        'member_status'  => ($reg['member_status'] ?? '') ?: ($ins['member_status'] ?? ''),
        'position'       => ($reg['position'] ?? '') ?: ($ins['position'] ?? ''),
        'citizen_id'     => ($reg['citizen_id'] ?? '') ?: ($ins['citizen_id'] ?? ''),
        'date_of_birth'  => ($reg['date_of_birth'] ?? '') ?: ($ins['date_of_birth'] ?? ''),
        'coverage_start' => $ins['coverage_start'] ?? '',
        'coverage_end'   => $ins['coverage_end'] ?? '',
        'policy_number'  => $ins['policy_number'] ?? '',
        'remarks'        => ($ins['remarks'] ?? '') ?: ($reg['remarks'] ?? ''),
    ];
}

// ── Sync Lock helpers ─────────────────────────────────────────────────────────
define('SYNC_LOCK_FILE', sys_get_temp_dir() . '/insurance_sync.lock');

function acquire_sync_lock(): bool {
    if (file_exists(SYNC_LOCK_FILE)) {
        $ts = (int)file_get_contents(SYNC_LOCK_FILE);
        if (time() - $ts < 300) return false; // lock held < 5 min
    }
    file_put_contents(SYNC_LOCK_FILE, (string)time());
    return true;
}

function release_sync_lock(): void {
    if (file_exists(SYNC_LOCK_FILE)) @unlink(SYNC_LOCK_FILE);
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: dryrun
// ─ Parse CSV, compute diff groups, return preview (no DB writes)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'dryrun') {
    $hasIns = isset($_FILES['insurance_file']) && $_FILES['insurance_file']['error'] === UPLOAD_ERR_OK;
    $hasReg = isset($_FILES['registry_file'])  && $_FILES['registry_file']['error']  === UPLOAD_ERR_OK;

    if (!$hasIns && !$hasReg) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาอัปโหลดไฟล์อย่างน้อย 1 ไฟล์']);
        exit;
    }

    // ── ไฟล์บริษัทประกัน (ไม่บังคับ) ──
    $insRaw    = '';
    $insRows   = [];
    $insIdSet  = [];
    $insRowMap = [];
    if ($hasIns) {
        $insRaw    = file_get_contents($_FILES['insurance_file']['tmp_name']);
        $insParsed = parse_insurance_csv(decode_csv_content($insRaw), ['member_id']);
        if (isset($insParsed['error'])) {
            echo json_encode(['status' => 'error', 'message' => '[ไฟล์ประกัน] ' . $insParsed['error']]);
            exit;
        }
        $insRows   = $insParsed['rows'];
        $insIds    = array_map(fn($r) => (string)$r['member_id'], $insRows);
        $insIdSet  = array_flip($insIds);
        $insRowMap = array_column($insRows, null, 'member_id');
    }

    // ── ไฟล์ทะเบียน (ไม่บังคับ) ──
    $regRaw      = '';
    $registryMap = [];
    if ($hasReg) {
        $regRaw    = file_get_contents($_FILES['registry_file']['tmp_name']);
        $regParsed = parse_insurance_csv(decode_csv_content($regRaw), ['member_id']);
        if (isset($regParsed['error'])) {
            echo json_encode(['status' => 'error', 'message' => '[ไฟล์ทะเบียน] ' . $regParsed['error']]);
            exit;
        }
        foreach ($regParsed['rows'] as $r) {
            $registryMap[$r['member_id']] = $r;
        }
    }

    ensure_insurance_tables($pdo);

    $existingStmt = $pdo->query("SELECT member_id, full_name, insurance_status, manually_overridden FROM insurance_members");
    $existing = [];
    while ($row = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[$row['member_id']] = $row;
    }

    $matched = $newcomers = $inactivated = [];

    if (!empty($registryMap) && !empty($insIdSet)) {
        // Both files: registry = master roster, insurance = coverage indicator
        foreach ($registryMap as $mid => $regRow) {
            $coveredByIns = isset($insIdSet[$mid]);
            $newStatus    = $coveredByIns ? 'Active' : 'Inactive';
            $insRow       = $insRowMap[$mid] ?? ['member_id' => $mid];
            $merged       = merge_member_data($insRow, $regRow);

            if (isset($existing[$mid])) {
                $matched[] = [
                    'member_id'           => $mid,
                    'full_name'           => $merged['full_name'] ?: $existing[$mid]['full_name'],
                    'position'            => $merged['position'],
                    'old_status'          => $existing[$mid]['insurance_status'],
                    'new_status'          => $newStatus,
                    'manually_overridden' => (int)$existing[$mid]['manually_overridden'],
                ];
            } else {
                $newcomers[] = [
                    'member_id'  => $mid,
                    'full_name'  => $merged['full_name'],
                    'position'   => $merged['position'],
                    'new_status' => $newStatus,
                ];
            }
        }
        // DB Active absent from registry → Inactive
        foreach ($existing as $mid => $ex) {
            if ($ex['insurance_status'] === 'Active' && !isset($registryMap[$mid])) {
                $inactivated[] = [
                    'member_id'           => $mid,
                    'full_name'           => $ex['full_name'],
                    'old_status'          => 'Active',
                    'new_status'          => 'Inactive',
                    'manually_overridden' => (int)$ex['manually_overridden'],
                ];
            }
        }
    } elseif (!empty($registryMap)) {
        // Registry-only: everyone in registry = Active (no insurance file to cross-check)
        foreach ($registryMap as $mid => $regRow) {
            $merged = merge_member_data(['member_id' => $mid], $regRow);
            if (isset($existing[$mid])) {
                $matched[] = [
                    'member_id'           => $mid,
                    'full_name'           => $merged['full_name'] ?: $existing[$mid]['full_name'],
                    'position'            => $merged['position'],
                    'old_status'          => $existing[$mid]['insurance_status'],
                    'new_status'          => 'Active',
                    'manually_overridden' => (int)$existing[$mid]['manually_overridden'],
                ];
            } else {
                $newcomers[] = [
                    'member_id'  => $mid,
                    'full_name'  => $merged['full_name'],
                    'position'   => $merged['position'],
                    'new_status' => 'Active',
                ];
            }
        }
        // DB Active absent from registry → Inactive
        foreach ($existing as $mid => $ex) {
            if ($ex['insurance_status'] === 'Active' && !isset($registryMap[$mid])) {
                $inactivated[] = [
                    'member_id'           => $mid,
                    'full_name'           => $ex['full_name'],
                    'old_status'          => 'Active',
                    'new_status'          => 'Inactive',
                    'manually_overridden' => (int)$ex['manually_overridden'],
                ];
            }
        }
    } else {
        // Insurance-only mode: insurance drives everything
        foreach ($insRows as $row) {
            $mid    = $row['member_id'];
            $merged = merge_member_data($row, []);
            if (isset($existing[$mid])) {
                $matched[] = [
                    'member_id'           => $mid,
                    'full_name'           => $merged['full_name'] ?: $existing[$mid]['full_name'],
                    'position'            => $merged['position'],
                    'old_status'          => $existing[$mid]['insurance_status'],
                    'new_status'          => 'Active',
                    'manually_overridden' => (int)$existing[$mid]['manually_overridden'],
                ];
            } else {
                $newcomers[] = [
                    'member_id'  => $mid,
                    'full_name'  => $merged['full_name'],
                    'position'   => $merged['position'],
                    'new_status' => 'Active',
                ];
            }
        }
        // DB Active not in insurance → Inactive
        foreach ($existing as $mid => $ex) {
            if ($ex['insurance_status'] === 'Active' && !isset($insIdSet[$mid])) {
                $inactivated[] = [
                    'member_id'           => $mid,
                    'full_name'           => $ex['full_name'],
                    'old_status'          => 'Active',
                    'new_status'          => 'Inactive',
                    'manually_overridden' => (int)$ex['manually_overridden'],
                ];
            }
        }
    }

    $currentActiveCount = count(array_filter($existing, fn($e) => $e['insurance_status'] === 'Active'));
    // Count all Active→Inactive transitions: removed from roster + matched but no longer covered
    $inactivateCount = count(array_filter($inactivated, fn($i) => !$i['manually_overridden']))
        + count(array_filter($matched, fn($m) => $m['old_status'] === 'Active' && $m['new_status'] === 'Inactive' && !$m['manually_overridden']));
    $guardTriggered = false;
    $guardPercent   = 0;
    if ($currentActiveCount > 0) {
        $guardPercent   = round($inactivateCount / $currentActiveCount * 100, 1);
        $guardTriggered = $guardPercent >= 30;
    }

    // Newcomers going Active vs Inactive (for frontend display)
    $matchedInactive = array_values(array_filter($matched, fn($m) => $m['new_status'] === 'Inactive'));

    echo json_encode([
        'status'               => 'ok',
        'has_registry'         => !empty($registryMap),
        'ins_filename'         => $hasIns ? htmlspecialchars($_FILES['insurance_file']['name']) : null,
        'reg_filename'         => $hasReg ? htmlspecialchars($_FILES['registry_file']['name']) : null,
        'total_csv'            => !empty($registryMap) ? count($registryMap) : count($insRows),
        'total_matched'        => count($matched),
        'total_newcomers'      => count($newcomers),
        'total_inactivated'    => count($inactivated),
        'total_will_inactivate'=> $inactivateCount,
        'guard_triggered'      => $guardTriggered,
        'guard_percent'        => $guardPercent,
        'matched'              => array_slice($matched, 0, 100),
        'matched_inactive'     => array_slice($matchedInactive, 0, 100),
        'newcomers'            => array_slice($newcomers, 0, 100),
        'inactivated'          => array_slice($inactivated, 0, 100),
        'insurance_b64'        => base64_encode($insRaw),
        'registry_b64'         => $regRaw ? base64_encode($regRaw) : '',
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: execute
// ─ Commit sync — write to DB, log history
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'execute') {
    $insB64       = $_POST['insurance_b64'] ?? '';
    $regB64       = $_POST['registry_b64'] ?? '';
    $forceOverride = ($_POST['force_override'] ?? '0') === '1';
    $insFilename   = trim($_POST['ins_filename'] ?? 'insurance.csv');
    $regFilename   = trim($_POST['reg_filename'] ?? '');

    if (!$insB64 && !$regB64) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลไฟล์ (กรุณา Dry Run ใหม่)']);
        exit;
    }

    if (!acquire_sync_lock()) {
        echo json_encode(['status' => 'error', 'message' => 'ระบบกำลังซิงค์อยู่แล้ว กรุณารอสักครู่']);
        exit;
    }

    try {
        ensure_insurance_tables($pdo);

        $insRows   = [];
        $csvIds    = [];
        $insIdSet  = [];
        $insRowMap = [];
        if ($insB64) {
            $insParsed = parse_insurance_csv(decode_csv_content(base64_decode($insB64)), ['member_id']);
            if (isset($insParsed['error'])) {
                release_sync_lock();
                echo json_encode(['status' => 'error', 'message' => '[ไฟล์ประกัน] ' . $insParsed['error']]);
                exit;
            }
            $insRows   = $insParsed['rows'];
            $csvIds    = array_column($insRows, 'member_id');
            $insIdSet  = array_flip($csvIds);
            $insRowMap = array_column($insRows, null, 'member_id');
        }
        $insRowMap = array_column($insRows, null, 'member_id');

        // Registry map (optional)
        $registryMap = [];
        if ($regB64) {
            $regParsed = parse_insurance_csv(decode_csv_content(base64_decode($regB64)), ['member_id']);
            if (!isset($regParsed['error'])) {
                foreach ($regParsed['rows'] as $r) {
                    $registryMap[$r['member_id']] = $r;
                }
            }
        }

        // Load existing
        $existingStmt = $pdo->query("SELECT * FROM insurance_members");
        $existing     = [];
        while ($row = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['member_id']] = $row;
        }

        // 30% guard: count all Active→Inactive transitions
        $currentActiveCount = count(array_filter($existing, fn($e) => $e['insurance_status'] === 'Active'));
        $willInactivate = 0;
        if (!empty($registryMap) && !empty($insIdSet)) {
            // Both files
            foreach ($existing as $mid => $ex) {
                if ($ex['insurance_status'] !== 'Active' || $ex['manually_overridden']) continue;
                if (!isset($registryMap[$mid])) { $willInactivate++; continue; }
                if (!isset($insIdSet[$mid])) $willInactivate++;
            }
        } elseif (!empty($registryMap)) {
            // Registry-only: those not in registry become Inactive
            foreach ($existing as $mid => $ex) {
                if ($ex['insurance_status'] === 'Active' && !isset($registryMap[$mid]) && !$ex['manually_overridden']) {
                    $willInactivate++;
                }
            }
        } else {
            // Insurance-only
            foreach ($existing as $mid => $ex) {
                if ($ex['insurance_status'] === 'Active' && !isset($insIdSet[$mid]) && !$ex['manually_overridden']) {
                    $willInactivate++;
                }
            }
        }
        if (!$forceOverride && $currentActiveCount > 0) {
            $pct = $willInactivate / $currentActiveCount * 100;
            if ($pct >= 30) {
                release_sync_lock();
                echo json_encode([
                    'status'  => 'guard',
                    'message' => "ระบบตรวจพบว่าจะมีการ Inactivate สมาชิก {$willInactivate} คน (" . round($pct, 1) . "%) ซึ่งมากกว่า 30% กรุณายืนยันอีกครั้ง",
                    'percent' => round($pct, 1),
                ]);
                exit;
            }
        }

        $pdo->beginTransaction();

        // Insert sync log first to get sync_id
        $logStmt = $pdo->prepare("
            INSERT INTO insurance_sync_logs (synced_by, filename, total_matched, total_inactivated, total_newcomers, total_active, notes)
            VALUES (:uid, :fn, 0, 0, 0, 0, '')
        ");
        $combinedFilename = $insFilename . ($regFilename ? ' + ' . $regFilename : '');
        $logStmt->execute([
            ':uid' => $_SESSION['admin_id'] ?? 0,
            ':fn'  => $combinedFilename,
        ]);
        $syncId = (int)$pdo->lastInsertId();

        $cntMatched     = 0;
        $cntInactivated = 0;
        $cntNewcomers   = 0;

        $upsertStmt = $pdo->prepare("
            INSERT INTO insurance_members
                (member_id, full_name, member_status, position, citizen_id, date_of_birth,
                 insurance_status, coverage_start, coverage_end, policy_number, remarks, last_sync_id, manually_overridden)
            VALUES
                (:mid, :fn, :ms, :pos, :cid, :dob, :ins_status, :cs, :ce, :pn, :rem, :sid, 0)
            ON DUPLICATE KEY UPDATE
                full_name        = IF(manually_overridden = 0, VALUES(full_name), full_name),
                member_status    = IF(manually_overridden = 0, VALUES(member_status), member_status),
                position         = IF(manually_overridden = 0, VALUES(position), position),
                citizen_id       = IF(manually_overridden = 0, VALUES(citizen_id), citizen_id),
                date_of_birth    = IF(manually_overridden = 0, VALUES(date_of_birth), date_of_birth),
                insurance_status = IF(manually_overridden = 0, VALUES(insurance_status), insurance_status),
                coverage_start   = IF(manually_overridden = 0, VALUES(coverage_start), coverage_start),
                coverage_end     = IF(manually_overridden = 0, VALUES(coverage_end), coverage_end),
                policy_number    = IF(manually_overridden = 0, VALUES(policy_number), policy_number),
                remarks          = IF(manually_overridden = 0, VALUES(remarks), remarks),
                last_sync_id     = VALUES(last_sync_id),
                manually_overridden = 0
        ");

        $histStmt = $pdo->prepare("
            INSERT INTO insurance_member_history (member_id, sync_id, change_type, old_status, new_status, snapshot)
            VALUES (:mid, :sid, :ct, :old, :new, :snap)
        ");

        $inactivateStmt = $pdo->prepare("
            UPDATE insurance_members SET insurance_status = 'Inactive', last_sync_id = :sid
            WHERE member_id = :mid AND manually_overridden = 0
        ");

        if (!empty($registryMap) && !empty($insIdSet)) {
            // Both files: registry = master roster, insurance = coverage indicator
            foreach ($registryMap as $mid => $regRow) {
                $coveredByIns = isset($insIdSet[$mid]);
                $newStatus    = $coveredByIns ? 'Active' : 'Inactive';
                $insRow       = $insRowMap[$mid] ?? ['member_id' => $mid];
                $merged       = merge_member_data($insRow, $regRow);
                $oldStatus    = $existing[$mid]['insurance_status'] ?? 'new';
                $changeType   = isset($existing[$mid]) ? 'matched' : 'inserted';

                $upsertStmt->execute([
                    ':mid'        => $mid,
                    ':fn'         => $merged['full_name'],
                    ':ms'         => $merged['member_status'],
                    ':pos'        => $merged['position'],
                    ':cid'        => $merged['citizen_id'],
                    ':dob'        => normalise_date($merged['date_of_birth'] ?: null),
                    ':ins_status' => $newStatus,
                    ':cs'         => normalise_date($merged['coverage_start'] ?: null),
                    ':ce'         => normalise_date($merged['coverage_end'] ?: null),
                    ':pn'         => $merged['policy_number'],
                    ':rem'        => $merged['remarks'],
                    ':sid'        => $syncId,
                ]);

                $histStmt->execute([
                    ':mid'  => $mid,
                    ':sid'  => $syncId,
                    ':ct'   => $changeType,
                    ':old'  => $oldStatus,
                    ':new'  => $newStatus,
                    ':snap' => json_encode($merged),
                ]);

                if ($changeType === 'inserted') $cntNewcomers++;
                else $cntMatched++;
            }

            // DB Active members absent from registry → Inactive
            foreach ($existing as $mid => $ex) {
                if ($ex['insurance_status'] === 'Active' && !isset($registryMap[$mid])) {
                    $inactivateStmt->execute([':sid' => $syncId, ':mid' => $mid]);
                    $histStmt->execute([
                        ':mid'  => $mid,
                        ':sid'  => $syncId,
                        ':ct'   => 'inactivated',
                        ':old'  => 'Active',
                        ':new'  => 'Inactive',
                        ':snap' => json_encode($ex),
                    ]);
                    $cntInactivated++;
                }
            }
        } elseif (!empty($registryMap)) {
            // Registry-only: everyone in registry = Active
            foreach ($registryMap as $mid => $regRow) {
                $merged     = merge_member_data(['member_id' => $mid], $regRow);
                $oldStatus  = $existing[$mid]['insurance_status'] ?? 'new';
                $changeType = isset($existing[$mid]) ? 'matched' : 'inserted';

                $upsertStmt->execute([
                    ':mid'        => $mid,
                    ':fn'         => $merged['full_name'],
                    ':ms'         => $merged['member_status'],
                    ':pos'        => $merged['position'],
                    ':cid'        => $merged['citizen_id'],
                    ':dob'        => normalise_date($merged['date_of_birth'] ?: null),
                    ':ins_status' => 'Active',
                    ':cs'         => normalise_date($merged['coverage_start'] ?: null),
                    ':ce'         => normalise_date($merged['coverage_end'] ?: null),
                    ':pn'         => $merged['policy_number'],
                    ':rem'        => $merged['remarks'],
                    ':sid'        => $syncId,
                ]);

                $histStmt->execute([
                    ':mid'  => $mid,
                    ':sid'  => $syncId,
                    ':ct'   => $changeType,
                    ':old'  => $oldStatus,
                    ':new'  => 'Active',
                    ':snap' => json_encode($merged),
                ]);

                if ($changeType === 'inserted') $cntNewcomers++;
                else $cntMatched++;
            }

            // DB Active absent from registry → Inactive
            foreach ($existing as $mid => $ex) {
                if ($ex['insurance_status'] === 'Active' && !isset($registryMap[$mid])) {
                    $inactivateStmt->execute([':sid' => $syncId, ':mid' => $mid]);
                    $histStmt->execute([
                        ':mid'  => $mid,
                        ':sid'  => $syncId,
                        ':ct'   => 'inactivated',
                        ':old'  => 'Active',
                        ':new'  => 'Inactive',
                        ':snap' => json_encode($ex),
                    ]);
                    $cntInactivated++;
                }
            }
        } else {
            // Insurance-only mode
            foreach ($insRows as $row) {
                $mid        = $row['member_id'];
                $merged     = merge_member_data($row, []);
                $oldStatus  = $existing[$mid]['insurance_status'] ?? 'new';
                $changeType = isset($existing[$mid]) ? 'matched' : 'inserted';

                $upsertStmt->execute([
                    ':mid'        => $mid,
                    ':fn'         => $merged['full_name'],
                    ':ms'         => $merged['member_status'],
                    ':pos'        => $merged['position'],
                    ':cid'        => $merged['citizen_id'],
                    ':dob'        => normalise_date($merged['date_of_birth'] ?: null),
                    ':ins_status' => 'Active',
                    ':cs'         => normalise_date($merged['coverage_start'] ?: null),
                    ':ce'         => normalise_date($merged['coverage_end'] ?: null),
                    ':pn'         => $merged['policy_number'],
                    ':rem'        => $merged['remarks'],
                    ':sid'        => $syncId,
                ]);

                $histStmt->execute([
                    ':mid'  => $mid,
                    ':sid'  => $syncId,
                    ':ct'   => $changeType,
                    ':old'  => $oldStatus,
                    ':new'  => 'Active',
                    ':snap' => json_encode($merged),
                ]);

                if ($changeType === 'inserted') $cntNewcomers++;
                else $cntMatched++;
            }

            // DB Active not in insurance → Inactive
            foreach ($existing as $mid => $ex) {
                if ($ex['insurance_status'] === 'Active' && !isset($insIdSet[$mid])) {
                    $inactivateStmt->execute([':sid' => $syncId, ':mid' => $mid]);
                    $histStmt->execute([
                        ':mid'  => $mid,
                        ':sid'  => $syncId,
                        ':ct'   => 'inactivated',
                        ':old'  => 'Active',
                        ':new'  => 'Inactive',
                        ':snap' => json_encode($ex),
                    ]);
                    $cntInactivated++;
                }
            }
        }

        $totalActive = (int)$pdo->query("SELECT COUNT(*) FROM insurance_members WHERE insurance_status = 'Active'")->fetchColumn();

        // Update sync log totals
        $pdo->prepare("
            UPDATE insurance_sync_logs
            SET total_matched = :tm, total_inactivated = :ti, total_newcomers = :tn, total_active = :ta
            WHERE id = :id
        ")->execute([
            ':tm' => $cntMatched,
            ':ti' => $cntInactivated,
            ':tn' => $cntNewcomers,
            ':ta' => $totalActive,
            ':id' => $syncId,
        ]);

        $pdo->commit();
        release_sync_lock();

        log_activity('insurance_sync', "Sync #{$syncId}: matched={$cntMatched}, newcomers={$cntNewcomers}, inactivated={$cntInactivated}");

        echo json_encode([
            'status'             => 'success',
            'sync_id'            => $syncId,
            'total_matched'      => $cntMatched,
            'total_newcomers'    => $cntNewcomers,
            'total_inactivated'  => $cntInactivated,
            'total_active'       => $totalActive,
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        release_sync_lock();
        error_log("Insurance Sync Execute Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการซิงค์: ' . $e->getMessage()]);
    }
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: get_sync_detail
// ─ Return history rows for a given sync_id (for modal)
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'get_sync_detail') {
    $syncId = (int)($_POST['sync_id'] ?? 0);
    if ($syncId <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'sync_id ไม่ถูกต้อง']);
        exit;
    }

    ensure_insurance_tables($pdo);

    $log = $pdo->prepare("SELECT l.*, a.full_name AS synced_by_name FROM insurance_sync_logs l LEFT JOIN sys_admins a ON l.synced_by = a.id WHERE l.id = :id");
    $log->execute([':id' => $syncId]);
    $logRow = $log->fetch(PDO::FETCH_ASSOC);

    if (!$logRow) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบประวัติ Sync นี้']);
        exit;
    }

    $history = $pdo->prepare("
        SELECT h.member_id, h.change_type, h.old_status, h.new_status, h.changed_at,
               m.full_name
        FROM insurance_member_history h
        LEFT JOIN insurance_members m ON h.member_id = m.member_id
        WHERE h.sync_id = :sid
        ORDER BY h.change_type, h.member_id
        LIMIT 500
    ");
    $history->execute([':sid' => $syncId]);
    $rows = $history->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'ok', 'log' => $logRow, 'rows' => $rows]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: manual_override
// ─ Manually change a member's insurance_status + audit log
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'manual_override') {
    $memberId  = trim($_POST['member_id'] ?? '');
    $newStatus = $_POST['new_status'] ?? '';

    if (!$memberId || !in_array($newStatus, ['Active', 'Inactive'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง']);
        exit;
    }

    ensure_insurance_tables($pdo);

    $existing = $pdo->prepare("SELECT * FROM insurance_members WHERE member_id = :mid");
    $existing->execute([':mid' => $memberId]);
    $member = $existing->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบสมาชิกนี้ในระบบ']);
        exit;
    }

    $oldStatus = $member['insurance_status'];

    $pdo->prepare("
        UPDATE insurance_members
        SET insurance_status = :ns, manually_overridden = 1, updated_at = NOW()
        WHERE member_id = :mid
    ")->execute([':ns' => $newStatus, ':mid' => $memberId]);

    $pdo->prepare("
        INSERT INTO insurance_member_history (member_id, sync_id, change_type, old_status, new_status, snapshot)
        VALUES (:mid, NULL, 'manual', :old, :new, :snap)
    ")->execute([
        ':mid'  => $memberId,
        ':old'  => $oldStatus,
        ':new'  => $newStatus,
        ':snap' => json_encode(['changed_by_admin_id' => $_SESSION['admin_id'] ?? 0, 'note' => trim($_POST['note'] ?? '')]),
    ]);

    log_activity('insurance_manual_override', "member_id={$memberId}: {$oldStatus} → {$newStatus}");

    echo json_encode(['status' => 'success', 'old_status' => $oldStatus, 'new_status' => $newStatus]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: list_members
// ─ Paginated member list for Members tab
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'list_members') {
    ensure_insurance_tables($pdo);

    $page     = max(1, (int)($_POST['page'] ?? 1));
    $perPage  = 50;
    $offset   = ($page - 1) * $perPage;
    $search   = trim($_POST['search'] ?? '');
    $filter   = $_POST['filter'] ?? 'all'; // all | Active | Inactive

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[]         = '(member_id LIKE :s OR full_name LIKE :s2 OR citizen_id LIKE :s3)';
        $params[':s']    = "%{$search}%";
        $params[':s2']   = "%{$search}%";
        $params[':s3']   = "%{$search}%";
    }
    if ($filter === 'Active' || $filter === 'Inactive') {
        $where[]          = 'insurance_status = :f';
        $params[':f']     = $filter;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = (int)$pdo->prepare("SELECT COUNT(*) FROM insurance_members {$whereClause}")->execute($params) ?
        $pdo->prepare("SELECT COUNT(*) FROM insurance_members {$whereClause}")->execute($params) : 0;
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM insurance_members {$whereClause}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT member_id, full_name, member_status, position, insurance_status, manually_overridden, updated_at
        FROM insurance_members {$whereClause}
        ORDER BY full_name ASC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'   => 'ok',
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'members'  => $members,
    ]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
