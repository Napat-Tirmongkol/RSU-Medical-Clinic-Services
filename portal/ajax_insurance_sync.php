<?php
// portal/ajax_insurance_sync.php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

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

validate_csrf_or_die();

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
 * Expected columns (case-insensitive header match):
 *   member_id, full_name, member_status, position, citizen_id, date_of_birth,
 *   coverage_start, coverage_end, policy_number, remarks
 */
function parse_insurance_csv(string $csvText): array {
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

    $required = ['member_id', 'full_name'];
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
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาอัปโหลดไฟล์ CSV']);
        exit;
    }

    $rawContent = file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($rawContent === false) {
        echo json_encode(['status' => 'error', 'message' => 'อ่านไฟล์ไม่ได้']);
        exit;
    }

    $csvText = decode_csv_content($rawContent);
    $parsed  = parse_insurance_csv($csvText);

    if (isset($parsed['error'])) {
        echo json_encode(['status' => 'error', 'message' => $parsed['error']]);
        exit;
    }

    $csvRows = $parsed['rows'];
    $csvIds  = array_column($csvRows, 'member_id');
    $csvIds  = array_map(fn($id) => (string)$id, $csvIds);

    ensure_insurance_tables($pdo);

    // Load existing active members
    $existingStmt = $pdo->query("SELECT member_id, full_name, insurance_status, manually_overridden FROM insurance_members");
    $existing     = [];
    while ($row = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[$row['member_id']] = $row;
    }

    $matched      = [];
    $newcomers    = [];
    $inactivated  = [];

    // Check each CSV row
    foreach ($csvRows as $row) {
        $mid = $row['member_id'];
        if (isset($existing[$mid])) {
            $matched[] = [
                'member_id'  => $mid,
                'full_name'  => $row['full_name'] ?: $existing[$mid]['full_name'],
                'old_status' => $existing[$mid]['insurance_status'],
                'new_status' => 'Active',
                'manually_overridden' => (int)$existing[$mid]['manually_overridden'],
            ];
        } else {
            $newcomers[] = [
                'member_id' => $mid,
                'full_name' => $row['full_name'],
                'position'  => $row['position'] ?? '',
            ];
        }
    }

    // Active members NOT in CSV → will be inactivated (skip manually_overridden)
    foreach ($existing as $mid => $ex) {
        if ($ex['insurance_status'] === 'Active' && !in_array($mid, $csvIds, true)) {
            $inactivated[] = [
                'member_id'           => $mid,
                'full_name'           => $ex['full_name'],
                'old_status'          => 'Active',
                'new_status'          => 'Inactive',
                'manually_overridden' => (int)$ex['manually_overridden'],
            ];
        }
    }

    // 30% Inactive guard
    $currentActiveCount = count(array_filter($existing, fn($e) => $e['insurance_status'] === 'Active'));
    $inactivateCount    = count(array_filter($inactivated, fn($i) => !$i['manually_overridden']));
    $guardTriggered     = false;
    $guardPercent       = 0;
    if ($currentActiveCount > 0) {
        $guardPercent   = round($inactivateCount / $currentActiveCount * 100, 1);
        $guardTriggered = $guardPercent >= 30;
    }

    echo json_encode([
        'status'           => 'ok',
        'filename'         => htmlspecialchars($_FILES['csv_file']['name']),
        'total_csv'        => count($csvRows),
        'total_matched'    => count($matched),
        'total_newcomers'  => count($newcomers),
        'total_inactivated'=> count($inactivated),
        'guard_triggered'  => $guardTriggered,
        'guard_percent'    => $guardPercent,
        'matched'          => array_slice($matched, 0, 100),
        'newcomers'        => array_slice($newcomers, 0, 100),
        'inactivated'      => array_slice($inactivated, 0, 100),
        'csv_base64'       => base64_encode($rawContent), // pass back for execute step
    ]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ACTION: execute
// ─ Commit sync — write to DB, log history
// ═════════════════════════════════════════════════════════════════════════════
if ($action === 'execute') {
    $csvBase64    = $_POST['csv_base64'] ?? '';
    $forceOverride = ($_POST['force_override'] ?? '0') === '1';
    $filename      = trim($_POST['filename'] ?? 'upload.csv');

    if (!$csvBase64) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูล CSV (กรุณา Dry Run ใหม่)']);
        exit;
    }

    if (!acquire_sync_lock()) {
        echo json_encode(['status' => 'error', 'message' => 'ระบบกำลังซิงค์อยู่แล้ว กรุณารอสักครู่']);
        exit;
    }

    try {
        ensure_insurance_tables($pdo);

        $rawContent = base64_decode($csvBase64);
        $csvText    = decode_csv_content($rawContent);
        $parsed     = parse_insurance_csv($csvText);

        if (isset($parsed['error'])) {
            release_sync_lock();
            echo json_encode(['status' => 'error', 'message' => $parsed['error']]);
            exit;
        }

        $csvRows = $parsed['rows'];
        $csvIds  = array_column($csvRows, 'member_id');

        // Load existing
        $existingStmt = $pdo->query("SELECT * FROM insurance_members");
        $existing     = [];
        while ($row = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['member_id']] = $row;
        }

        // 30% guard check
        $currentActiveCount = count(array_filter($existing, fn($e) => $e['insurance_status'] === 'Active'));
        $willInactivate = 0;
        foreach ($existing as $mid => $ex) {
            if ($ex['insurance_status'] === 'Active' && !in_array($mid, $csvIds, true) && !$ex['manually_overridden']) {
                $willInactivate++;
            }
        }
        if (!$forceOverride && $currentActiveCount > 0) {
            $pct = $willInactivate / $currentActiveCount * 100;
            if ($pct >= 30) {
                release_sync_lock();
                echo json_encode([
                    'status'  => 'guard',
                    'message' => "ระบบตรวจพบว่าจะมีการ Inactivate สมาชิก {$willInactivate} คน ({$pct}%) ซึ่งมากกว่า 30% กรุณายืนยันอีกครั้ง",
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
        $logStmt->execute([
            ':uid' => $_SESSION['admin_id'] ?? 0,
            ':fn'  => $filename,
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
                (:mid, :fn, :ms, :pos, :cid, :dob, 'Active', :cs, :ce, :pn, :rem, :sid, 0)
            ON DUPLICATE KEY UPDATE
                full_name        = IF(manually_overridden = 0, VALUES(full_name), full_name),
                member_status    = IF(manually_overridden = 0, VALUES(member_status), member_status),
                position         = IF(manually_overridden = 0, VALUES(position), position),
                citizen_id       = IF(manually_overridden = 0, VALUES(citizen_id), citizen_id),
                date_of_birth    = IF(manually_overridden = 0, VALUES(date_of_birth), date_of_birth),
                insurance_status = IF(manually_overridden = 0, 'Active', insurance_status),
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

        foreach ($csvRows as $row) {
            $mid       = $row['member_id'];
            $oldStatus = $existing[$mid]['insurance_status'] ?? 'new';
            $changeType = isset($existing[$mid]) ? 'matched' : 'inserted';

            $upsertStmt->execute([
                ':mid' => $mid,
                ':fn'  => $row['full_name'] ?? '',
                ':ms'  => $row['member_status'] ?? '',
                ':pos' => $row['position'] ?? '',
                ':cid' => $row['citizen_id'] ?? '',
                ':dob' => normalise_date($row['date_of_birth'] ?? null),
                ':cs'  => normalise_date($row['coverage_start'] ?? null),
                ':ce'  => normalise_date($row['coverage_end'] ?? null),
                ':pn'  => $row['policy_number'] ?? '',
                ':rem' => $row['remarks'] ?? '',
                ':sid' => $syncId,
            ]);

            $histStmt->execute([
                ':mid'  => $mid,
                ':sid'  => $syncId,
                ':ct'   => $changeType,
                ':old'  => $oldStatus,
                ':new'  => 'Active',
                ':snap' => json_encode($row),
            ]);

            if ($changeType === 'inserted') $cntNewcomers++;
            else $cntMatched++;
        }

        // Inactivate members not in CSV (skip manually_overridden)
        $inactivateStmt = $pdo->prepare("
            UPDATE insurance_members SET insurance_status = 'Inactive', last_sync_id = :sid
            WHERE member_id = :mid AND manually_overridden = 0
        ");

        foreach ($existing as $mid => $ex) {
            if ($ex['insurance_status'] === 'Active' && !in_array($mid, $csvIds, true)) {
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
