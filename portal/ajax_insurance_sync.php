<?php
// portal/ajax_insurance_sync.php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$isStaff   = !empty($_SESSION['is_ecampaign_staff']);
$hasRegistry = !empty($_SESSION['access_registry']);
$hasInsurance = !empty($_SESSION['access_insurance']) || $adminRole === 'superadmin';

// Registry users can only call upload-related actions (member_id list maintenance only)
$registryAllowedActions = ['upload', 'analyze_upload', 'upload_combined', 'ai_review'];
$requestedAction = $_POST['action'] ?? $_GET['action'] ?? '';

if (($isStaff && $adminRole === '') || !in_array($adminRole, ['admin', 'superadmin', 'editor'], true)) {
    json_err('ไม่มีสิทธิ์เข้าถึงระบบนี้', 403);
}
if (!$hasInsurance) {
    if (!$hasRegistry || !in_array($requestedAction, $registryAllowedActions, true)) {
        json_err('ไม่มีสิทธิ์เข้าถึงระบบนี้', 403);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method Not Allowed', 405);
}

// CSRF: accept same-origin header OR session token
$proto          = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http'));
$expectedOrigin = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$originOk       = (($_SERVER['HTTP_ORIGIN'] ?? '') === $expectedOrigin);
$sessionOk      = verify_csrf_token($_POST['csrf_token'] ?? '');
if (!$originOk && !$sessionOk) {
    json_err('CSRF validation failed กรุณาโหลดหน้าใหม่', 403);
}

if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    json_err('ไฟล์มีขนาดใหญ่เกิน limit (' . ini_get('post_max_size') . ')');
}

$action = $_POST['action'] ?? '';
$pdo    = db();

// ── Table bootstrap ───────────────────────────────────────────────────────────
function ensure_insurance_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!empty($_SESSION['ins_table_v2'])) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS insurance_members (
            member_id        VARCHAR(20)              NOT NULL,
            full_name        VARCHAR(255)             NOT NULL DEFAULT '',
            member_status    VARCHAR(50)              NOT NULL DEFAULT '',
            citizen_id       VARCHAR(13)              NOT NULL DEFAULT '',
            date_of_birth    DATE                     NULL,
            insurance_status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            coverage_start   DATE                     NULL,
            coverage_end     DATE                     NULL,
            policy_number    VARCHAR(100)             NOT NULL DEFAULT '',
            remarks          TEXT                     NULL,
            updated_at       DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at       DATETIME                 NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (member_id),
            INDEX idx_member_status (member_status),
            INDEX idx_insurance_status (insurance_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM insurance_members")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('last_sync_id', $columns, true)) {
        $pdo->exec("ALTER TABLE insurance_members ADD COLUMN last_sync_id INT UNSIGNED NULL AFTER remarks");
    }
    if (!in_array('manually_overridden', $columns, true)) {
        $pdo->exec("ALTER TABLE insurance_members ADD COLUMN manually_overridden TINYINT(1) NOT NULL DEFAULT 0 AFTER last_sync_id");
    }
    if (!in_array('position', $columns, true)) {
        $pdo->exec("ALTER TABLE insurance_members ADD COLUMN position VARCHAR(100) NOT NULL DEFAULT '' AFTER member_status");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS insurance_member_history (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id   VARCHAR(20)  NOT NULL,
            sync_id     INT UNSIGNED NOT NULL,
            change_type VARCHAR(30)  NOT NULL,
            old_status  VARCHAR(50)  NULL,
            new_status  VARCHAR(50)  NULL,
            snapshot    LONGTEXT     NULL,
            changed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sync_id (sync_id),
            INDEX idx_member_id (member_id),
            INDEX idx_change_type (change_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // If table was created with ENUM or narrow VARCHAR, migrate to VARCHAR(50)
    $ct = $pdo->query("SHOW COLUMNS FROM insurance_member_history LIKE 'change_type'")->fetch(PDO::FETCH_ASSOC);
    if ($ct && stripos($ct['Type'], 'varchar(50)') === false) {
        $pdo->exec("ALTER TABLE insurance_member_history MODIFY COLUMN change_type VARCHAR(50) NOT NULL DEFAULT ''");
    }

    $_SESSION['ins_table_v2'] = true;
}

function insurance_snapshot(array $row): string
{
    return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function decode_csv(string $raw): string
{
    if (mb_detect_encoding($raw, ['UTF-8'], true) === 'UTF-8') return $raw;
    $c = iconv('Windows-874', 'UTF-8//TRANSLIT//IGNORE', $raw);
    return $c !== false ? $c : $raw;
}

function parse_csv(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    if (count($lines) < 2) return ['error' => 'ไฟล์ CSV ต้องมีอย่างน้อย 1 แถวข้อมูล'];

    $headerLine = ltrim(array_shift($lines), "\xEF\xBB\xBF");
    $headers    = array_map(fn($h) => strtolower(trim($h)), str_getcsv($headerLine));

    if (!in_array('member_id', $headers, true)) return ['error' => 'ไม่พบคอลัมน์ member_id'];

    $rows = [];
    $seen = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $cols = str_getcsv($line);
        while (count($cols) < count($headers)) $cols[] = '';
        $row = [];
        foreach ($headers as $i => $h) $row[$h] = trim($cols[$i] ?? '');
        $mid = $row['member_id'] ?? '';
        if ($mid === '' || isset($seen[$mid])) continue;
        $seen[$mid] = true;
        $rows[] = $row;
    }

    return empty($rows) ? ['error' => 'ไม่พบข้อมูลในไฟล์'] : ['rows' => $rows];
}

function norm_date(?string $d, string $prefer = 'dmy'): ?string
{
    if (!$d) return null;
    $d = trim($d);
    if ($d === '') return null;

    // Replace Thai month names with numeric form. Original Thai layout is
    // "DD <month> YYYY" so we convert to "DD/MM/YYYY" and force d/m/Y order.
    static $thaiMonths = [
        'มกราคม' => '01', 'ม.ค.' => '01',
        'กุมภาพันธ์' => '02', 'ก.พ.' => '02',
        'มีนาคม' => '03', 'มี.ค.' => '03',
        'เมษายน' => '04', 'เม.ย.' => '04',
        'พฤษภาคม' => '05', 'พ.ค.' => '05',
        'มิถุนายน' => '06', 'มิ.ย.' => '06',
        'กรกฎาคม' => '07', 'กรกฏาคม' => '07', 'ก.ค.' => '07',
        'สิงหาคม' => '08', 'ส.ค.' => '08',
        'กันยายน' => '09', 'ก.ย.' => '09',
        'ตุลาคม' => '10', 'ต.ค.' => '10',
        'พฤศจิกายน' => '11', 'พ.ย.' => '11',
        'ธันวาคม' => '12', 'ธ.ค.' => '12',
    ];
    $thaiReplaced = false;
    foreach ($thaiMonths as $thai => $num) {
        if (mb_strpos($d, $thai) !== false) {
            $d = str_replace($thai, '/' . $num . '/', $d);
            $d = preg_replace('/\s+/', '', $d);
            $d = preg_replace('/\/+/', '/', $d);
            $d = trim($d, '/');
            $thaiReplaced = true;
            break;
        }
    }

    // $prefer = 'dmy' (default Thai/Euro) or 'mdy' (registry birthdate files).
    // Strict-mode rejection (warning_count > 0) lets unambiguous dates fall
    // through, so 31/12/2020 still resolves to 2020-12-31 either way.
    $dmy = ['Y-m-d', 'j/n/Y', 'd/m/Y', 'n/j/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d', 'd/m/y'];
    $mdy = ['Y-m-d', 'n/j/Y', 'm/d/Y', 'j/n/Y', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd/m/y'];
    $formats = $thaiReplaced ? ['j/n/Y', 'd/m/Y'] : ($prefer === 'mdy' ? $mdy : $dmy);

    foreach ($formats as $fmt) {
        $dt  = DateTime::createFromFormat($fmt, $d);
        $err = DateTime::getLastErrors() ?: ['warning_count' => 0, 'error_count' => 0];
        if ($dt && $err['warning_count'] === 0 && $err['error_count'] === 0) {
            // Thai BE → CE (4/8/2505 → 1962-08-04)
            $year = (int)$dt->format('Y');
            if ($year >= 2400 && $year <= 2700) {
                $dt->modify('-543 years');
            }
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: upload — parse file, upsert Active rows, inactivate missing
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'upload') {
    if (!isset($_FILES['insurance_file']) || $_FILES['insurance_file']['error'] !== UPLOAD_ERR_OK) {
        json_err('กรุณาเลือกไฟล์ก่อนอัปโหลด');
    }

    $uploadMode = ($_POST['upload_mode'] ?? 'full_sync') === 'append' ? 'append' : 'full_sync';

    $raw    = file_get_contents($_FILES['insurance_file']['tmp_name']);
    $parsed = parse_csv(decode_csv($raw));
    if (isset($parsed['error'])) {
        json_err($parsed['error']);
    }

    ensure_insurance_table($pdo);

    // Normalize Thai/alternative column name aliases
    $aliases = [
        'วันเริ่มต้น'       => 'coverage_start',
        'วันสิ้นสุด'        => 'coverage_end',
        'วันสิ้นสุดคุ้มครอง' => 'coverage_end',
        'ชื่อ'              => 'full_name',
        'ชื่อ-นามสกุล'      => 'full_name',
        'ประเภท'            => 'member_status',
        'เลขบัตรประชาชน'    => 'citizen_id',
        'เลขกรมธรรม์'       => 'policy_number',
        'หมายเหตุ'          => 'remarks',
    ];
    $rows = array_map(function($row) use ($aliases) {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$aliases[$k] ?? $k] = $v;
        }
        return $out;
    }, $parsed['rows']);

    $csvIdSet  = array_flip(array_column($rows, 'member_id'));
    $totalCsv  = count($rows);

    // Load existing members for diff/history
    $existingRows = $pdo->query("
        SELECT member_id, full_name, member_status, citizen_id, date_of_birth,
               insurance_status, coverage_start, coverage_end, policy_number, remarks,
               last_sync_id, manually_overridden
        FROM insurance_members
    ")->fetchAll(PDO::FETCH_ASSOC);
    $existing = array_column($existingRows, 'member_id');
    $existingById = [];
    foreach ($existingRows as $existingRow) {
        $existingById[$existingRow['member_id']] = $existingRow;
    }
    $existSet = array_flip($existing);

    $cntNew         = 0;
    $cntUpdated     = 0;
    $cntInactivated = 0;
    $cntProtected   = 0;

    // Only update columns that actually exist in the CSV — prevents wiping existing data
    // Standard set matches the official upload format
    $updatableCols = ['full_name', 'citizen_id', 'member_status', 'position',
                      'policy_number', 'coverage_start', 'coverage_end'];
    $csvColumns    = !empty($rows) ? array_keys($rows[0]) : [];
    $presentCols   = array_intersect($updatableCols, $csvColumns);

    $updateParts   = ['insurance_status = IF(manually_overridden = 1, insurance_status, \'Active\')',
                      'last_sync_id = :sync_id_update'];
    foreach ($presentCols as $col) {
        $updateParts[] = "{$col} = IF(manually_overridden = 1, {$col}, VALUES({$col}))";
    }

    $upsert = $pdo->prepare("
        INSERT INTO insurance_members
            (member_id, full_name, member_status, position, citizen_id, date_of_birth,
             insurance_status, coverage_start, coverage_end, policy_number, remarks,
             last_sync_id, manually_overridden)
        VALUES
            (:mid, :fn, :ms, :pos, :cid, :dob, 'Active', :cs, :ce, :pn, :rem,
             :sync_id_insert, 0)
        ON DUPLICATE KEY UPDATE
            " . implode(",\n            ", $updateParts) . "
    ");

    $pdo->beginTransaction();
    try {
        $syncId = (int)$pdo->query("SELECT COALESCE(MAX(sync_id), 0) + 1 FROM insurance_member_history")->fetchColumn();
        if ($syncId <= 0) $syncId = 1;

        $history = $pdo->prepare("
            INSERT INTO insurance_member_history
                (member_id, sync_id, change_type, old_status, new_status, snapshot)
            VALUES
                (:member_id, :sync_id, :change_type, :old_status, :new_status, :snapshot)
        ");

        foreach ($rows as $r) {
            $mid         = $r['member_id'];
            $existing_   = $existingById[$mid] ?? null;
            $isProtected = $existing_ !== null && (int)($existing_['manually_overridden'] ?? 0) === 1;
            $oldStatus   = $existing_['insurance_status'] ?? 'new';

            $upsert->execute([
                ':mid' => $mid,
                ':fn'  => $r['full_name']      ?? '',
                ':ms'  => $r['member_status']  ?? '',
                ':pos' => $r['position']       ?? '',
                ':cid' => $r['citizen_id']     ?? '',
                ':dob' => norm_date($r['date_of_birth'] ?? null, 'mdy'),
                ':cs'  => norm_date($r['coverage_start'] ?? null),
                ':ce'  => norm_date($r['coverage_end']   ?? null),
                ':pn'  => $r['policy_number']  ?? '',
                ':rem' => $r['remarks']        ?? '',
                ':sync_id_insert' => $syncId,
                ':sync_id_update' => $syncId,
            ]);

            if (isset($existSet[$mid])) {
                if ($isProtected) {
                    $cntProtected++;
                    $changeType = 'protected';
                    $newStatus = $oldStatus;
                } else {
                    $cntUpdated++;
                    $changeType = 'updated';
                    $newStatus = 'Active';
                }
            } else {
                $cntNew++;
                $changeType = 'inserted';
                $newStatus = 'Active';
            }

            // For 'updated': snapshot = old DB row (needed for rollback restore)
            // For 'inserted': snapshot = CSV row (to know what was added)
            // For 'protected': snapshot = existing DB row
            $snapshotData = ($changeType === 'updated' || $changeType === 'protected')
                ? ($existingById[$mid] ?? ['member_id' => $mid])
                : $r;
            $history->execute([
                ':member_id' => $mid,
                ':sync_id' => $syncId,
                ':change_type' => $changeType,
                ':old_status' => (string)$oldStatus,
                ':new_status' => (string)$newStatus,
                ':snapshot' => insurance_snapshot($snapshotData),
            ]);
        }

        // Inactivate members not in file — skipped in append mode
        if ($uploadMode === 'full_sync') {
            $inactivate = $pdo->prepare("
                UPDATE insurance_members
                SET insurance_status = 'Inactive',
                    last_sync_id = :sync_id
                WHERE member_id = :mid
                  AND insurance_status = 'Active'
                  AND manually_overridden = 0
            ");
            foreach ($existing as $mid) {
                if (!isset($csvIdSet[$mid])) {
                    $inactivate->execute([':mid' => $mid, ':sync_id' => $syncId]);
                    if ($inactivate->rowCount() > 0) {
                        $cntInactivated++;
                        $history->execute([
                            ':member_id' => $mid,
                            ':sync_id' => $syncId,
                            ':change_type' => 'inactivated',
                            ':old_status' => (string)($existingById[$mid]['insurance_status'] ?? 'Active'),
                            ':new_status' => 'Inactive',
                            ':snapshot' => insurance_snapshot($existingById[$mid] ?? ['member_id' => $mid]),
                        ]);
                    }
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_err('เกิดข้อผิดพลาด: ' . $e->getMessage());
    }

    log_activity('insurance_upload', "mode={$uploadMode}, total={$totalCsv}, new={$cntNew}, updated={$cntUpdated}, protected={$cntProtected}, inactivated={$cntInactivated}");

    // ── Create batch tracking row + initial event ─────────────────────────────
    $batchId = null;
    try {
        require_once __DIR__ . '/includes/insurance_batch.php';

        // Detect source_type from session — registry user vs clinic staff
        $sourceType = !empty($_SESSION['access_registry']) && empty($_SESSION['access_insurance'])
            ? 'registry'
            : 'clinic_manual';
        $sourceType .= '_' . $uploadMode;

        $insBatch = $pdo->prepare("
            INSERT INTO insurance_batch
                (sync_id, batch_code, upload_mode, source_type, insurance_company,
                 status, total_members, members_inserted, members_updated, members_inactivated,
                 uploaded_by, uploaded_by_name, uploaded_at)
            VALUES
                (:sid, :bc, :um, :st, 'MTI',
                 'pending_review', :tm, :mi, :mu, :minact,
                 :ub, :ubn, NOW())
        ");
        // Retry on UNIQUE batch_code collision (concurrent uploads on same day)
        $batchCode = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $batchCode = ins_batch_generate_code($pdo);
            try {
                $insBatch->execute([
                    ':sid'    => $syncId,
                    ':bc'     => $batchCode,
                    ':um'     => $uploadMode,
                    ':st'     => $sourceType,
                    ':tm'     => $totalCsv,
                    ':mi'     => $cntNew,
                    ':mu'     => $cntUpdated,
                    ':minact' => $cntInactivated,
                    ':ub'     => (int)($_SESSION['admin_id'] ?? 0) ?: null,
                    ':ubn'    => $_SESSION['admin_username'] ?? null,
                ]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                if ($attempt === 4) throw $e;
                usleep(random_int(10000, 50000));
            }
        }
        $batchId = (int)$pdo->lastInsertId();

        ins_batch_log_event(
            $pdo, $batchId, 'uploaded', null, 'pending_review',
            'staff', (int)($_SESSION['admin_id'] ?? 0) ?: null,
            $_SESSION['admin_username'] ?? null,
            "mode={$uploadMode}, total={$totalCsv}, new={$cntNew}, updated={$cntUpdated}, inactivated={$cntInactivated}"
        );
    } catch (Exception $e) {
        error_log('insurance_batch create: ' . $e->getMessage());
    }

    json_ok([
        'total_csv'         => $totalCsv,
        'total_new'         => $cntNew,
        'total_updated'     => $cntUpdated,
        'total_protected'   => $cntProtected,
        'total_inactivated' => $cntInactivated,
        'sync_id'           => $syncId,
        'batch_id'          => $batchId,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: upload_combined — รวม 3 ไฟล์ (staff + student + resigned) เป็น batch เดียว
//
//   1. parse 3 ไฟล์ (อย่างน้อย staff หรือ student ต้องมี)
//   2. dedupe staff ↔ student ด้วย citizen_id (fallback member_id) — บุคลากรชนะ
//   3. ตัดแถวที่ตรงกับไฟล์คนออก (citizen_id หรือ member_id)
//   4. mode=preview → ส่ง summary + sample
//      mode=commit  → upsert + full_sync inactivate + create insurance_batch
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'upload_combined') {
    $mode = ($_POST['mode'] ?? 'preview') === 'commit' ? 'commit' : 'preview';

    $hasStaff    = !empty($_FILES['staff_file'])    && $_FILES['staff_file']['error']    === UPLOAD_ERR_OK;
    $hasStudent  = !empty($_FILES['student_file'])  && $_FILES['student_file']['error']  === UPLOAD_ERR_OK;
    $hasResigned = !empty($_FILES['resigned_file']) && $_FILES['resigned_file']['error'] === UPLOAD_ERR_OK;

    if (!$hasStaff && !$hasStudent) {
        json_err('ต้องมีไฟล์รายชื่อบุคลากรหรือนักศึกษาอย่างน้อย 1 ไฟล์');
    }

    $aliases = [
        'วันเริ่มต้น'        => 'coverage_start',
        'วันสิ้นสุด'         => 'coverage_end',
        'วันสิ้นสุดคุ้มครอง' => 'coverage_end',
        'วันเริ่มคุ้มครอง'   => 'coverage_start',
        'ชื่อ-นามสกุล'       => 'full_name',
        'ชื่อ-สกุล'          => 'full_name',
        'ชื่อ นามสกุล'       => 'full_name',
        'ชื่อพนักงาน'         => 'full_name',
        'ชื่อ-นามสกุล (รวม)'  => 'full_name',
        'ชื่อ'               => 'first_name',
        'ชื่อจริง'           => 'first_name',
        'นามสกุล'            => 'last_name',
        'สกุล'               => 'last_name',
        'คำนำ'               => 'name_prefix',
        'คำนำหน้า'           => 'name_prefix',
        'คำนำหน้าชื่อ'       => 'name_prefix',
        'ประเภท'             => 'member_status',
        'เลขบัตรประชาชน'     => 'citizen_id',
        'รหัสบัตรประชาชน'    => 'citizen_id',
        'เลขบัตร'            => 'citizen_id',
        'หมายเลขประจำตัวประชาชน' => 'citizen_id',
        'หมายเลขประจำตัว'    => 'citizen_id',
        'เลขประจำตัวประชาชน' => 'citizen_id',
        'id_card_no'         => 'citizen_id',
        'birthday'           => 'date_of_birth',
        'เลขกรมธรรม์'        => 'policy_number',
        'หมายเหตุ'           => 'remarks',
        'รหัส'               => 'member_id',
        'รหัสบุคลากร'        => 'member_id',
        'รหัสพนักงาน'        => 'member_id',
        'รหัสนักศึกษา'       => 'member_id',
        'ลำดับ'              => '_seq',
        'ตำแหน่ง'            => 'position',
        'สาขา'               => 'position',
        'สาขาวิชา'           => 'position',
        'คณะ'                => 'position',
        'สังกัด'             => 'position',
        'แผนก'               => 'position',
        'หน่วยงาน'           => 'position',
        'student_code'       => 'member_id',
        'วันที่ออก'           => 'resign_date',
        'วันออก'             => 'resign_date',
        'วันลาออก'           => 'resign_date',
        'วันที่ลาออก'         => 'resign_date',
        'effective_date'     => 'resign_date',
        'วันเดือนปีเกิด'      => 'date_of_birth',
        'วันเดือนปี เกิด'     => 'date_of_birth',
        'วันเกิด'            => 'date_of_birth',
        'dob'                => 'date_of_birth',
    ];

    $applyAliases = function (array $row) use ($aliases): array {
        $out = [];
        foreach ($row as $k => $v) $out[$aliases[$k] ?? $k] = $v;
        return $out;
    };
    $normCitizen = function (string $cid): string {
        $cid = preg_replace('/\D/', '', $cid);
        return strlen($cid) === 13 ? $cid : '';
    };

    $parseFlexible = function (string $tmp, bool $requireMemberId) use ($applyAliases, $normCitizen): array {
        $raw   = file_get_contents($tmp);
        $text  = decode_csv($raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        if (count($lines) < 2) return ['error' => 'ไฟล์ต้องมีอย่างน้อย 1 แถวข้อมูล'];

        $headerLine = ltrim(array_shift($lines), "\xEF\xBB\xBF");
        $headers    = array_map(fn($h) => strtolower(trim($h)), str_getcsv($headerLine));

        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cols = str_getcsv($line);
            while (count($cols) < count($headers)) $cols[] = '';
            $row = [];
            foreach ($headers as $i => $h) $row[$h] = trim($cols[$i] ?? '');
            $row = $applyAliases($row);
            $row['member_id']  = (string)($row['member_id']  ?? '');
            $row['citizen_id'] = $normCitizen((string)($row['citizen_id'] ?? ''));

            // Compose full_name from prefix + first_name + last_name when not already provided
            if (empty($row['full_name'])) {
                $prefix = trim((string)($row['name_prefix'] ?? ''));
                $first  = trim((string)($row['first_name']  ?? ''));
                $last   = trim((string)($row['last_name']   ?? ''));
                if ($first !== '' || $last !== '') {
                    $row['full_name'] = trim($prefix . $first . ($last !== '' ? ' ' . $last : ''));
                }
            }

            if ($requireMemberId && $row['member_id'] === '') continue;
            if (!$requireMemberId && $row['member_id'] === '' && $row['citizen_id'] === '') continue;

            $rows[] = $row;
        }
        return ['rows' => $rows];
    };

    $staffParse    = $hasStaff    ? $parseFlexible($_FILES['staff_file']['tmp_name'],    true)  : ['rows' => []];
    $studentParse  = $hasStudent  ? $parseFlexible($_FILES['student_file']['tmp_name'],  true)  : ['rows' => []];
    $resignedParse = $hasResigned ? $parseFlexible($_FILES['resigned_file']['tmp_name'], false) : ['rows' => []];

    if (isset($staffParse['error']))    json_err('ไฟล์บุคลากร: ' . $staffParse['error']);
    if (isset($studentParse['error']))  json_err('ไฟล์นักศึกษา: ' . $studentParse['error']);
    if (isset($resignedParse['error'])) json_err('ไฟล์คนออก: '   . $resignedParse['error']);

    $tagSource = function (string $src, string $defaultStatus) {
        return function (array $r) use ($src, $defaultStatus): array {
            $r['_source']       = $src;
            $r['member_status'] = !empty($r['member_status']) ? $r['member_status'] : $defaultStatus;
            return $r;
        };
    };
    $staffList   = array_map($tagSource('staff',   'บุคลากร'),  $staffParse['rows']);
    $studentList = array_map($tagSource('student', 'นักศึกษา'), $studentParse['rows']);
    $resignedList = $resignedParse['rows'];

    // Build leaver indexes (carry resign_date so we can persist it on inactivate)
    $leaverByCid = [];
    $leaverByMid = [];
    foreach ($resignedList as $r) {
        $info = [
            'resign_date' => norm_date($r['resign_date'] ?? null),
            'member_id'   => $r['member_id'],
            'citizen_id'  => $r['citizen_id'],
            'full_name'   => $r['full_name'] ?? '',
        ];
        if ($r['citizen_id']) $leaverByCid[$r['citizen_id']] = $info;
        if ($r['member_id'])  $leaverByMid[$r['member_id']]  = $info;
    }
    $leaverInfoFor = function (string $cid, string $mid) use ($leaverByCid, $leaverByMid): ?array {
        if ($cid !== '' && isset($leaverByCid[$cid])) {
            return $leaverByCid[$cid] + ['_match' => 'citizen_id', '_match_value' => $cid];
        }
        if ($mid !== '' && isset($leaverByMid[$mid])) {
            return $leaverByMid[$mid] + ['_match' => 'member_id', '_match_value' => $mid];
        }
        return null;
    };

    $keyFor = function (array $r): string {
        return !empty($r['citizen_id']) ? ('C:' . $r['citizen_id']) : ('M:' . $r['member_id']);
    };

    $merged           = [];
    $duplicates       = [];
    $droppedLeavers   = [];
    $droppedLeaverCnt = 0;
    $countStaffIn     = 0;
    $countStudentIn   = 0;

    // Staff first (priority — บุคลากรชนะ)
    foreach ($staffList as $r) {
        $info = $leaverInfoFor($r['citizen_id'] ?? '', $r['member_id'] ?? '');
        if ($info !== null) {
            $droppedLeaverCnt++;
            if (count($droppedLeavers) < 50) {
                $droppedLeavers[] = $r + [
                    '_dropped_from' => 'staff',
                    '_resign_date'  => $info['resign_date'],
                    '_match'        => $info['_match'],
                    '_match_value'  => $info['_match_value'],
                ];
            }
            continue;
        }
        $countStaffIn++;
        $merged[$keyFor($r)] = $r;
    }

    foreach ($studentList as $r) {
        $info = $leaverInfoFor($r['citizen_id'] ?? '', $r['member_id'] ?? '');
        if ($info !== null) {
            $droppedLeaverCnt++;
            if (count($droppedLeavers) < 50) {
                $droppedLeavers[] = $r + [
                    '_dropped_from' => 'student',
                    '_resign_date'  => $info['resign_date'],
                    '_match'        => $info['_match'],
                    '_match_value'  => $info['_match_value'],
                ];
            }
            continue;
        }
        $key = $keyFor($r);
        if (isset($merged[$key]) && $merged[$key]['_source'] === 'staff') {
            $staffRow = $merged[$key];
            $duplicates[] = [
                'staff_member_id'   => $staffRow['member_id'],
                'student_member_id' => $r['member_id'],
                'citizen_id'        => $staffRow['citizen_id'] ?: $r['citizen_id'],
                'full_name'         => $staffRow['full_name'] ?? ($r['full_name'] ?? ''),
            ];
            $note = "เรียนต่อ — รหัส นศ. {$r['member_id']}";
            $oldRem = (string)($staffRow['remarks'] ?? '');
            $merged[$key]['remarks'] = $oldRem === '' ? $note : ($oldRem . '; ' . $note);
            continue;
        }
        $countStudentIn++;
        $merged[$key] = $r;
    }

    $finalRows = array_values($merged);

    // ── PREVIEW MODE ────────────────────────────────────────────────────────────
    if ($mode === 'preview') {
        json_ok([
            'summary' => [
                'staff_in_file'    => count($staffList),
                'student_in_file'  => count($studentList),
                'resigned_in_file' => count($resignedList),
                'duplicates'       => count($duplicates),
                'dropped_leavers'  => $droppedLeaverCnt,
                'final_count'      => count($finalRows),
                'staff_kept'       => $countStaffIn,
                'student_kept'     => $countStudentIn,
            ],
            'duplicates_sample' => array_slice($duplicates, 0, 50),
            'dropped_sample'    => $droppedLeavers,
            'final_sample'      => array_slice($finalRows, 0, 50),
        ]);
    }

    // ── COMMIT MODE ─────────────────────────────────────────────────────────────
    ensure_insurance_table($pdo);

    $existingRows = $pdo->query("
        SELECT member_id, full_name, member_status, citizen_id, date_of_birth,
               insurance_status, coverage_start, coverage_end, policy_number, remarks,
               last_sync_id, manually_overridden
        FROM insurance_members
    ")->fetchAll(PDO::FETCH_ASSOC);
    $existingById = [];
    foreach ($existingRows as $existingRow) $existingById[$existingRow['member_id']] = $existingRow;
    $existSet = array_flip(array_column($existingRows, 'member_id'));

    $finalIdSet = [];
    foreach ($finalRows as $r) $finalIdSet[$r['member_id']] = true;

    $cntNew = $cntUpdated = $cntInactivated = $cntProtected = 0;

    $upsert = $pdo->prepare("
        INSERT INTO insurance_members
            (member_id, full_name, member_status, position, citizen_id, date_of_birth,
             insurance_status, coverage_start, coverage_end, policy_number, remarks,
             last_sync_id, manually_overridden)
        VALUES
            (:mid, :fn, :ms, :pos, :cid, :dob, 'Active', :cs, :ce, :pn, :rem,
             :sync_id_insert, 0)
        ON DUPLICATE KEY UPDATE
            insurance_status = IF(manually_overridden = 1, insurance_status, 'Active'),
            last_sync_id     = :sync_id_update,
            full_name        = IF(manually_overridden = 1, full_name,      VALUES(full_name)),
            citizen_id       = IF(manually_overridden = 1, citizen_id,     VALUES(citizen_id)),
            member_status    = IF(manually_overridden = 1, member_status,  VALUES(member_status)),
            position         = IF(manually_overridden = 1, position,       VALUES(position)),
            policy_number    = IF(manually_overridden = 1, policy_number,  VALUES(policy_number)),
            coverage_start   = IF(manually_overridden = 1, coverage_start, VALUES(coverage_start)),
            coverage_end     = IF(manually_overridden = 1, coverage_end,   VALUES(coverage_end)),
            remarks          = IF(manually_overridden = 1, remarks,        VALUES(remarks))
    ");

    $pdo->beginTransaction();
    try {
        $syncId = (int)$pdo->query("SELECT COALESCE(MAX(sync_id), 0) + 1 FROM insurance_member_history")->fetchColumn();
        if ($syncId <= 0) $syncId = 1;

        $history = $pdo->prepare("
            INSERT INTO insurance_member_history
                (member_id, sync_id, change_type, old_status, new_status, snapshot)
            VALUES
                (:member_id, :sync_id, :change_type, :old_status, :new_status, :snapshot)
        ");

        foreach ($finalRows as $r) {
            $mid         = $r['member_id'];
            $existing_   = $existingById[$mid] ?? null;
            $isProtected = $existing_ !== null && (int)($existing_['manually_overridden'] ?? 0) === 1;
            $oldStatus   = $existing_['insurance_status'] ?? 'new';

            $upsert->execute([
                ':mid' => $mid,
                ':fn'  => $r['full_name']      ?? '',
                ':ms'  => $r['member_status']  ?? '',
                ':pos' => $r['position']       ?? '',
                ':cid' => $r['citizen_id']     ?? '',
                ':dob' => norm_date($r['date_of_birth'] ?? null, 'mdy'),
                ':cs'  => norm_date($r['coverage_start'] ?? null),
                ':ce'  => norm_date($r['coverage_end']   ?? null),
                ':pn'  => $r['policy_number']  ?? '',
                ':rem' => $r['remarks']        ?? '',
                ':sync_id_insert' => $syncId,
                ':sync_id_update' => $syncId,
            ]);

            if (isset($existSet[$mid])) {
                if ($isProtected) {
                    $cntProtected++;
                    $changeType = 'protected';
                    $newStatus  = $oldStatus;
                } else {
                    $cntUpdated++;
                    $changeType = 'updated';
                    $newStatus  = 'Active';
                }
            } else {
                $cntNew++;
                $changeType = 'inserted';
                $newStatus  = 'Active';
            }

            $snapshotData = ($changeType === 'updated' || $changeType === 'protected')
                ? ($existingById[$mid] ?? ['member_id' => $mid])
                : $r;
            $history->execute([
                ':member_id'   => $mid,
                ':sync_id'     => $syncId,
                ':change_type' => $changeType,
                ':old_status'  => (string)$oldStatus,
                ':new_status'  => (string)$newStatus,
                ':snapshot'    => insurance_snapshot($snapshotData),
            ]);
        }

        // Inactivate anyone in DB but not in finalRows (full_sync semantics)
        $inactivate = $pdo->prepare("
            UPDATE insurance_members
            SET insurance_status = 'Inactive',
                last_sync_id     = :sync_id
            WHERE member_id = :mid
              AND insurance_status = 'Active'
              AND manually_overridden = 0
        ");
        // Update remarks + coverage_end for those matched in resigned file (only when not protected)
        $applyResign = $pdo->prepare("
            UPDATE insurance_members
            SET remarks      = TRIM(BOTH '; ' FROM CONCAT(COALESCE(remarks,''), '; ', :note)),
                coverage_end = COALESCE(:rd, coverage_end)
            WHERE member_id = :mid AND manually_overridden = 0
        ");

        foreach (array_keys($existSet) as $mid) {
            if (!isset($finalIdSet[$mid])) {
                $inactivate->execute([':mid' => $mid, ':sync_id' => $syncId]);
                if ($inactivate->rowCount() > 0) {
                    $cntInactivated++;
                    $existRow = $existingById[$mid] ?? ['member_id' => $mid];
                    $info     = $leaverInfoFor((string)($existRow['citizen_id'] ?? ''), (string)$mid);
                    $byLeaver = $info !== null;
                    $changeType = $byLeaver ? 'inactivated_resigned' : 'inactivated';

                    if ($byLeaver) {
                        $resignDate = $info['resign_date'];
                        $note = $resignDate ? "ออกเมื่อ {$resignDate}" : 'ออกจากงาน';
                        $applyResign->execute([
                            ':note' => $note,
                            ':rd'   => $resignDate,
                            ':mid'  => $mid,
                        ]);
                        $existRow['_resign_date'] = $resignDate;
                    }

                    $history->execute([
                        ':member_id'   => $mid,
                        ':sync_id'     => $syncId,
                        ':change_type' => $changeType,
                        ':old_status'  => (string)($existRow['insurance_status'] ?? 'Active'),
                        ':new_status'  => 'Inactive',
                        ':snapshot'    => insurance_snapshot($existRow),
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_err('เกิดข้อผิดพลาด: ' . $e->getMessage());
    }

    log_activity('insurance_upload_combined',
        "staff={$countStaffIn}, student={$countStudentIn}, dup=" . count($duplicates) .
        ", drop_leaver={$droppedLeaverCnt}, final=" . count($finalRows) .
        ", new={$cntNew}, updated={$cntUpdated}, inactivated={$cntInactivated}");

    $batchId   = null;
    $batchCode = null;
    try {
        require_once __DIR__ . '/includes/insurance_batch.php';
        $sourceType = !empty($_SESSION['access_registry']) && empty($_SESSION['access_insurance'])
            ? 'registry_combined' : 'clinic_combined';

        $insBatch = $pdo->prepare("
            INSERT INTO insurance_batch
                (sync_id, batch_code, upload_mode, source_type, insurance_company,
                 status, total_members, members_inserted, members_updated, members_inactivated,
                 uploaded_by, uploaded_by_name, uploaded_at)
            VALUES
                (:sid, :bc, 'full_sync', :st, 'MTI',
                 'pending_review', :tm, :mi, :mu, :minact,
                 :ub, :ubn, NOW())
        ");
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $batchCode = ins_batch_generate_code($pdo);
            try {
                $insBatch->execute([
                    ':sid'    => $syncId,
                    ':bc'     => $batchCode,
                    ':st'     => $sourceType,
                    ':tm'     => count($finalRows),
                    ':mi'     => $cntNew,
                    ':mu'     => $cntUpdated,
                    ':minact' => $cntInactivated,
                    ':ub'     => (int)($_SESSION['admin_id'] ?? 0) ?: null,
                    ':ubn'    => $_SESSION['admin_username'] ?? null,
                ]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                if ($attempt === 4) throw $e;
                usleep(random_int(10000, 50000));
            }
        }
        $batchId = (int)$pdo->lastInsertId();

        ins_batch_log_event(
            $pdo, $batchId, 'uploaded', null, 'pending_review',
            'staff', (int)($_SESSION['admin_id'] ?? 0) ?: null,
            $_SESSION['admin_username'] ?? null,
            "combined: staff={$countStaffIn}, student={$countStudentIn}, dup=" . count($duplicates) .
            ", leaver={$droppedLeaverCnt}, final=" . count($finalRows)
        );
    } catch (Exception $e) {
        error_log('insurance_batch combined: ' . $e->getMessage());
    }

    json_ok([
        'sync_id'    => $syncId,
        'batch_id'   => $batchId,
        'batch_code' => $batchCode,
        'summary'    => [
            'staff_in_file'    => count($staffList),
            'student_in_file'  => count($studentList),
            'resigned_in_file' => count($resignedList),
            'duplicates'       => count($duplicates),
            'dropped_leavers'  => $droppedLeaverCnt,
            'final_count'      => count($finalRows),
            'new'              => $cntNew,
            'updated'          => $cntUpdated,
            'inactivated'      => $cntInactivated,
            'protected'        => $cntProtected,
        ],
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: ai_review — Gemini-powered data quality review of preview payload
//
// PDPA-safe: client masks citizen_id (1XXXXXXXXXXX3) and names (initials)
// before submitting. Server only forwards summary + masked sample to Gemini.
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'ai_review') {
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') {
        json_err('AI ยังไม่ได้ตั้งค่า — admin ต้องใส่ GEMINI_API_KEY ใน Site Settings');
    }

    $summary = $_POST['summary'] ?? '';
    $sample  = $_POST['sample']  ?? '';
    if (is_string($summary)) $summary = json_decode($summary, true);
    if (is_string($sample))  $sample  = json_decode($sample, true);
    if (!is_array($summary) || !is_array($sample)) json_err('ข้อมูลไม่ครบ');

    // Defensive — strip any field that looks like full citizen_id (13 digits)
    foreach ($sample as &$row) {
        if (is_array($row)) {
            foreach ($row as $k => $v) {
                if (is_string($v) && preg_match('/^\d{13}$/', $v)) {
                    $row[$k] = substr($v, 0, 1) . str_repeat('X', 11) . substr($v, -1);
                }
            }
        }
    }
    unset($row);

    $prompt = "คุณคือผู้ช่วยตรวจคุณภาพข้อมูลรายชื่อสำหรับส่งให้บริษัทประกันสุขภาพ\n";
    $prompt .= "ของศูนย์การแพทย์มหาวิทยาลัยรังสิต\n";
    $prompt .= "ข้อมูลถูก anonymize แล้ว (เลขบัตรเป็น 1XXXXXXXXXXX3, ชื่อเป็น initials)\n\n";
    $prompt .= "=== สรุปสถิติ ===\n";
    $prompt .= json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    $prompt .= "=== ตัวอย่างแถว (anonymized) ===\n";
    $prompt .= json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    $prompt .= "วิเคราะห์เป็น bullet points ภาษาไทย ไม่เกิน 6 ข้อ ครอบคลุม:\n";
    $prompt .= "1. คุณภาพข้อมูลโดยรวม (ดี/พอใช้/ต้องตรวจ)\n";
    $prompt .= "2. ความผิดปกติของวันเกิด (อายุ <5 หรือ >80, ในอนาคต ฯลฯ) ระบุจำนวน\n";
    $prompt .= "3. รายการที่ citizen_id ผิดรูป (ไม่ใช่ 13 หลัก/ว่าง) ระบุจำนวนและที่มา\n";
    $prompt .= "4. ความสมดุลของ บุคลากร vs นักศึกษา ดูสมเหตุสมผลไหม\n";
    $prompt .= "5. ข้อสังเกตอื่นที่ admin ควรตรวจก่อน commit\n";
    $prompt .= "ตอบสั้นกระชับ ตรงประเด็น ใส่ emoji นำหน้าแต่ละข้อ (✅ คือดี, ⚠️ คือควรเช็ค, ❌ คือต้องแก้)";

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . urlencode(GEMINI_API_KEY);
    $payload = [
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 800],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) json_err('AI ติดต่อไม่ได้: ' . $curlErr);
    if ($httpCode !== 200) {
        $err = json_decode($resp, true)['error']['message'] ?? "HTTP $httpCode";
        json_err('AI ตอบกลับผิดพลาด: ' . $err);
    }

    $data  = json_decode($resp, true);
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $text  = '';
    foreach ($parts as $part) {
        if (!($part['thought'] ?? false) && isset($part['text'])) {
            $text .= $part['text'];
        }
    }
    if ($text === '') json_err('AI ตอบมาว่างเปล่า — ลองอีกครั้ง');

    log_activity('insurance_ai_review', 'sample=' . count($sample) . ', tokens~' . (int)($data['usageMetadata']['totalTokenCount'] ?? 0));
    json_ok([
        'review' => $text,
        'tokens' => (int)($data['usageMetadata']['totalTokenCount'] ?? 0),
        'model'  => 'gemini-2.5-flash',
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: get_sync_detail — member-level breakdown for a specific sync
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'get_sync_detail') {
    $syncId  = (int)($_POST['sync_id'] ?? 0);
    $page    = max(1, (int)($_POST['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;
    if ($syncId <= 0) json_err('sync_id ไม่ถูกต้อง');

    ensure_insurance_table($pdo);

    $total = (int)$pdo->prepare("SELECT COUNT(*) FROM insurance_member_history WHERE sync_id = :sid")
        ->execute([':sid' => $syncId]) ? $pdo->query("SELECT COUNT(*) FROM insurance_member_history WHERE sync_id = {$syncId}")->fetchColumn() : 0;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM insurance_member_history WHERE sync_id = :sid");
    $countStmt->execute([':sid' => $syncId]);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT h.member_id, h.change_type, h.old_status, h.new_status,
               COALESCE(m.full_name, '') AS full_name,
               COALESCE(m.member_status, '') AS member_status,
               COALESCE(m.position, '') AS position
        FROM insurance_member_history h
        LEFT JOIN insurance_members m ON m.member_id = h.member_id
        WHERE h.sync_id = :sid
        ORDER BY FIELD(h.change_type,'inserted','updated','inactivated','protected'), h.member_id
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':sid', $syncId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    json_ok([
        'sync_id'  => $syncId,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'rows'     => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: list_history — recent upload/update history from insurance_member_history
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'list_history') {
    ensure_insurance_table($pdo);

    $page      = max(1, (int)($_POST['page'] ?? 1));
    $perPage   = 20;
    $offset    = ($page - 1) * $perPage;
    $dateFrom  = trim($_POST['date_from'] ?? '');
    $dateTo    = trim($_POST['date_to']   ?? '');

    $where  = [];
    $params = [];
    if ($dateFrom) { $where[] = 'changed_at >= :df'; $params[':df'] = $dateFrom . ' 00:00:00'; }
    if ($dateTo)   { $where[] = 'changed_at <= :dt'; $params[':dt'] = $dateTo   . ' 23:59:59'; }
    $wSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT sync_id) FROM insurance_member_history {$wSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            sync_id,
            MIN(changed_at)                          AS sync_time,
            SUM(change_type = 'inserted')            AS cnt_new,
            SUM(change_type = 'inactivated')         AS cnt_removed,
            SUM(change_type = 'updated')             AS cnt_updated,
            SUM(change_type = 'protected')           AS cnt_protected,
            COUNT(*)                                 AS cnt_total
        FROM insurance_member_history {$wSql}
        GROUP BY sync_id
        ORDER BY sync_id DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
    $stmt->execute();

    json_ok([
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'history'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: rollback_sync — reverse all changes made by a specific sync
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'rollback_sync') {
    $syncId = (int)($_POST['sync_id'] ?? 0);
    if ($syncId <= 0) json_err('sync_id ไม่ถูกต้อง');

    ensure_insurance_table($pdo);

    $records = $pdo->prepare("
        SELECT member_id, change_type, old_status, snapshot
        FROM insurance_member_history
        WHERE sync_id = :sync_id
    ");
    $records->execute([':sync_id' => $syncId]);
    $items = $records->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) json_err("ไม่พบประวัติ Sync #{$syncId}");

    $cntDeleted  = 0;
    $cntRestored = 0;
    $cntReverted = 0;

    $pdo->beginTransaction();
    try {
        $stmtDelete   = $pdo->prepare("DELETE FROM insurance_members WHERE member_id = :mid");
        $stmtActivate = $pdo->prepare("UPDATE insurance_members SET insurance_status = 'Active', manually_overridden = 0 WHERE member_id = :mid");
        $stmtRestore  = $pdo->prepare("
            UPDATE insurance_members SET
                full_name        = :fn,
                member_status    = :ms,
                position         = :pos,
                citizen_id       = :cid,
                insurance_status = :ins,
                coverage_start   = :cs,
                coverage_end     = :ce,
                policy_number    = :pn,
                manually_overridden = :mo
            WHERE member_id = :mid
        ");

        foreach ($items as $item) {
            $mid = $item['member_id'];
            switch ($item['change_type']) {
                case 'inserted':
                    $stmtDelete->execute([':mid' => $mid]);
                    if ($stmtDelete->rowCount() > 0) $cntDeleted++;
                    break;

                case 'inactivated':
                    $stmtActivate->execute([':mid' => $mid]);
                    if ($stmtActivate->rowCount() > 0) $cntRestored++;
                    break;

                case 'updated':
                    $old = json_decode($item['snapshot'] ?? '{}', true);
                    if ($old && !empty($old['member_id'])) {
                        $stmtRestore->execute([
                            ':mid' => $mid,
                            ':fn'  => $old['full_name']          ?? '',
                            ':ms'  => $old['member_status']      ?? '',
                            ':pos' => $old['position']           ?? '',
                            ':cid' => $old['citizen_id']         ?? '',
                            ':ins' => $old['insurance_status']   ?? 'Active',
                            ':cs'  => $old['coverage_start']     ?: null,
                            ':ce'  => $old['coverage_end']       ?: null,
                            ':pn'  => $old['policy_number']      ?? '',
                            ':mo'  => (int)($old['manually_overridden'] ?? 0),
                        ]);
                        if ($stmtRestore->rowCount() > 0) $cntReverted++;
                    }
                    break;
            }
        }

        $pdo->prepare("DELETE FROM insurance_member_history WHERE sync_id = :sync_id")
            ->execute([':sync_id' => $syncId]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_err('ย้อนกลับไม่สำเร็จ: ' . $e->getMessage());
    }

    log_activity('insurance_rollback', "ย้อน sync #{$syncId}: deleted={$cntDeleted}, restored={$cntRestored}, reverted={$cntReverted}");

    json_ok([
        'sync_id'      => $syncId,
        'cnt_deleted'  => $cntDeleted,
        'cnt_restored' => $cntRestored,
        'cnt_reverted' => $cntReverted,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: analyze_upload — dry-run: compare CSV member_ids with DB (no commit)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'analyze_upload') {
    $memberIds = json_decode($_POST['member_ids'] ?? '[]', true);
    if (!is_array($memberIds) || empty($memberIds)) json_err('ไม่พบข้อมูล member_id');

    ensure_insurance_table($pdo);

    $memberIds = array_values(array_unique(array_filter(array_map('strval', $memberIds))));
    $csvSet    = array_flip($memberIds);
    $totalCsv  = count($memberIds);

    $activeRows = $pdo->query("SELECT member_id FROM insurance_members WHERE insurance_status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
    $activeSet  = array_flip($activeRows);

    $cntNew = $cntExisting = $cntWouldInactivate = 0;
    foreach ($memberIds as $mid) {
        if (isset($activeSet[$mid])) $cntExisting++;
        else $cntNew++;
    }
    foreach ($activeRows as $mid) {
        if (!isset($csvSet[$mid])) $cntWouldInactivate++;
    }

    json_ok([
        'total_csv'             => $totalCsv,
        'cnt_new'               => $cntNew,
        'cnt_existing'          => $cntExisting,
        'cnt_would_inactivate'  => $cntWouldInactivate,
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: list_members — paginated member list
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'list_members') {
    ensure_insurance_table($pdo);

    $page    = max(1, (int)($_POST['page'] ?? 1));
    $perPage = 20;
    $offset  = ($page - 1) * $perPage;
    $search  = trim($_POST['search']        ?? '');
    $fType   = trim($_POST['filter_type']   ?? '');
    $fStatus = trim($_POST['filter_status'] ?? '');

    $where  = [];
    $params = [];

    if ($search !== '') {
        $where[]      = '(member_id LIKE :s OR full_name LIKE :s2 OR citizen_id LIKE :s3 OR policy_number LIKE :s4)';
        $params[':s']  = "%{$search}%";
        $params[':s2'] = "%{$search}%";
        $params[':s3'] = "%{$search}%";
        $params[':s4'] = "%{$search}%";
    }
    if ($fType !== '') {
        $where[]       = 'member_status = :ft';
        $params[':ft'] = $fType;
    }
    if ($fStatus === 'expiring') {
        $where[] = "insurance_status = 'Active' AND coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif (in_array($fStatus, ['Active', 'Inactive'], true)) {
        $where[]       = 'insurance_status = :fs';
        $params[':fs'] = $fStatus;
    }

    $wSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM insurance_members {$wSql}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT member_id, full_name, member_status, insurance_status,
               coverage_start, coverage_end, citizen_id, policy_number, remarks,
               manually_overridden
        FROM insurance_members {$wSql}
        ORDER BY full_name ASC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);

    json_ok([
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'members'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: save_member — insert or update a single member record
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'save_member') {
    $mid    = trim($_POST['member_id']      ?? '');
    $isEdit = ($_POST['is_edit']            ?? '0') === '1';
    $fn     = trim($_POST['full_name']      ?? '');
    $ms     = trim($_POST['member_status']  ?? '');
    $ins    = $_POST['insurance_status']    ?? 'Active';
    $cid    = trim($_POST['citizen_id']     ?? '');
    $pn     = trim($_POST['policy_number']  ?? '');
    $cs     = trim($_POST['coverage_start'] ?? '') ?: null;
    $ce     = trim($_POST['coverage_end']   ?? '') ?: null;
    $rem    = trim($_POST['remarks']        ?? '');

    if ($mid === '') json_err('กรุณาระบุรหัสสมาชิก');
    if (!in_array($ins, ['Active', 'Inactive'], true)) json_err('สถานะสิทธิ์ไม่ถูกต้อง');

    ensure_insurance_table($pdo);

    if ($isEdit) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM insurance_members WHERE member_id = :mid");
        $s->execute([':mid' => $mid]);
        if ((int)$s->fetchColumn() === 0) {
            json_err('ไม่พบสมาชิกรหัส ' . htmlspecialchars($mid));
        }

        $pdo->prepare("
            UPDATE insurance_members SET
                full_name        = :fn,
                member_status    = :ms,
                insurance_status = :ins,
                citizen_id       = :cid,
                policy_number    = :pn,
                coverage_start   = :cs,
                coverage_end     = :ce,
                remarks          = :rem,
                manually_overridden = 1
            WHERE member_id = :mid
        ")->execute([':fn'=>$fn,':ms'=>$ms,':ins'=>$ins,':cid'=>$cid,':pn'=>$pn,':cs'=>$cs,':ce'=>$ce,':rem'=>$rem,':mid'=>$mid]);

        log_activity('insurance_edit', "แก้ไขสมาชิก member_id={$mid}");
    } else {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM insurance_members WHERE member_id = :mid");
        $dup->execute([':mid' => $mid]);
        if ((int)$dup->fetchColumn() > 0) {
            json_err("รหัสสมาชิก {$mid} มีอยู่ในระบบแล้ว");
        }

        $pdo->prepare("
            INSERT INTO insurance_members
                (member_id, full_name, member_status, insurance_status, citizen_id,
                 policy_number, coverage_start, coverage_end, remarks, manually_overridden)
            VALUES
                (:mid, :fn, :ms, :ins, :cid, :pn, :cs, :ce, :rem, 1)
        ")->execute([':mid'=>$mid,':fn'=>$fn,':ms'=>$ms,':ins'=>$ins,':cid'=>$cid,':pn'=>$pn,':cs'=>$cs,':ce'=>$ce,':rem'=>$rem]);

        log_activity('insurance_add', "เพิ่มสมาชิก member_id={$mid}");
    }

    json_ok();
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: set_visibility — toggle insurance card on user/hub.php
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'set_visibility') {
    $active       = ($_POST['active'] ?? '0') === '1';
    $activeVal    = $active ? '1' : '0';
    $settingsFile = __DIR__ . '/../config/site_settings.json';

    // Write JSON
    $settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?? []) : [];
    $settings['show_insurance'] = $active;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Write DB (DB takes precedence over JSON in config.php)
    try {
        $pdo->prepare("
            INSERT INTO sys_site_settings (setting_key, setting_value)
            VALUES ('show_insurance', :val)
            ON DUPLICATE KEY UPDATE setting_value = :val2
        ")->execute([':val' => $activeVal, ':val2' => $activeVal]);
    } catch (Exception $e) {
        json_err('ไม่สามารถบันทึกข้อมูลได้: ' . $e->getMessage());
    }

    log_activity('update_site_settings', 'Toggle Insurance Card: ' . ($active ? 'ON' : 'OFF'));
    json_ok();
}

json_err('Unknown action');
