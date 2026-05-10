<?php
/**
 * portal/ajax_gold_card.php
 * AJAX endpoint สำหรับจัดการสมาชิกบัตรทอง + เอกสารแนบ
 *
 * Pattern: entity:action
 *   member:list, member:get, member:save, member:delete, member:stats
 *   document:upload, document:list, document:download, document:delete
 *   chart:trend, chart:by_status, chart:by_hospital
 *
 * Auth: ต้องมี access_insurance หรือ superadmin
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

$adminRole   = $_SESSION['admin_role'] ?? '';
$adminId     = (int)($_SESSION['admin_id'] ?? 0);
$isSuper     = $adminRole === 'superadmin';
$hasAccess   = $isSuper || !empty($_SESSION['access_insurance']);

if (!$hasAccess) {
    json_err('ไม่มีสิทธิ์เข้าถึงระบบบัตรทอง', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();

$pdo    = db();
$entity = $_POST['entity'] ?? '';
$action = $_POST['action'] ?? '';

// ── Helper: file storage dir ──────────────────────────────────────────────────
function gold_card_uploads_dir(): string
{
    return dirname(__DIR__) . '/uploads/gold_card';
}

function gold_card_save_uploaded_file(array $file, int $memberId): ?array
{
    $maxSize = 20 * 1024 * 1024; // 20MB
    $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx'];

    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] <= 0 || $file['size'] > $maxSize) return null;

    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return null;

    $year   = date('Y');
    $month  = date('m');
    $base   = gold_card_uploads_dir();
    $subdir = $base . "/$year/$month";
    if (!is_dir($subdir)) {
        if (!@mkdir($subdir, 0755, true)) return null;
    }

    $hash = sha1_file($file['tmp_name']) ?: bin2hex(random_bytes(8));
    $stored = sprintf('%s/%s/mem%d_%s.%s', $year, $month, $memberId, substr($hash, 0, 16), $ext);
    $target = $base . '/' . $stored;

    if (!move_uploaded_file($file['tmp_name'], $target)) return null;

    return [
        'file_name'   => $origName,
        'stored_path' => $stored,
        'mime_type'   => $file['type'] ?? null,
        'file_size'   => (int)$file['size'],
        'sha1_hash'   => $hash,
    ];
}

function gold_card_log_history(PDO $pdo, ?int $memberId, string $action, $oldVal, $newVal, int $changedBy): void
{
    try {
        $pdo->prepare("INSERT INTO gold_card_history
            (member_id, action, old_value, new_value, changed_by, ip_address)
            VALUES (?,?,?,?,?,?)")->execute([
            $memberId,
            $action,
            is_string($oldVal) ? $oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE),
            is_string($newVal) ? $newVal : json_encode($newVal, JSON_UNESCAPED_UNICODE),
            $changedBy ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) { /* silent */ }
}

// ── Auto-migrate guard ────────────────────────────────────────────────────────
try {
    $pdo->query("SELECT 1 FROM gold_card_members LIMIT 1");
} catch (PDOException $e) {
    json_err('ตาราง gold_card_members ยังไม่ถูกสร้าง — กรุณารัน migrate_gold_card_module.php ก่อน');
}

// Auto-add bulk import columns ถ้าไม่มี (self-healing)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM gold_card_members")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('linked_user_id', $cols, true)) {
        $pdo->exec("ALTER TABLE gold_card_members ADD COLUMN linked_user_id INT UNSIGNED NULL AFTER citizen_id, ADD INDEX idx_linked_user (linked_user_id)");
    }
    if (!in_array('source_filename', $cols, true)) {
        $pdo->exec("ALTER TABLE gold_card_members ADD COLUMN source_filename VARCHAR(500) NULL AFTER remarks");
    }
} catch (PDOException $e) { /* silent */ }

// ── Helper: ดึงชื่อจาก filename + match กับ sys_users ─────────────────────────
function gc_extract_name(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    // ลบเลขนำหน้า เช่น "63123456_นายสมชาย" หรือ "631234_..."
    $name = preg_replace('/^[\d_\-\s.]+/u', '', $name);
    // ลบ suffix ที่เป็นตัวเลข/วันที่ ที่ตามหลัง _ หรือ -
    $name = preg_replace('/[_\-\s]+\d+$/u', '', $name);
    return trim($name);
}

function gc_match_user(PDO $pdo, string $filename): array
{
    $name = gc_extract_name($filename);
    if ($name === '') return ['status' => 'no_name', 'name' => '', 'candidates' => []];

    // 1) Exact match
    $stmt = $pdo->prepare("SELECT id, full_name, citizen_id, student_personnel_id, status
                           FROM sys_users WHERE TRIM(full_name) = ? LIMIT 5");
    $stmt->execute([$name]);
    $exact = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($exact) === 1) return ['status' => 'matched', 'name' => $name, 'user' => $exact[0]];
    if (count($exact) > 1)   return ['status' => 'ambiguous', 'name' => $name, 'candidates' => $exact];

    // 2) Fuzzy match (substring)
    $stmt = $pdo->prepare("SELECT id, full_name, citizen_id, student_personnel_id, status
                           FROM sys_users
                           WHERE full_name LIKE CONCAT('%', ?, '%')
                              OR ? LIKE CONCAT('%', full_name, '%')
                           LIMIT 5");
    $stmt->execute([$name, $name]);
    $fuzzy = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($fuzzy) === 1) return ['status' => 'matched', 'name' => $name, 'user' => $fuzzy[0]];
    if (count($fuzzy) > 1)   return ['status' => 'ambiguous', 'name' => $name, 'candidates' => $fuzzy];

    return ['status' => 'no_match', 'name' => $name, 'candidates' => []];
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
try {
    switch ("$entity:$action") {

        case 'member:stats': {
            $row = $pdo->query("
                SELECT
                    COUNT(*)                                                          AS total,
                    SUM(status IN ('approved','active'))                              AS approved,
                    SUM(status IN ('pending','submitted'))                            AS pending,
                    SUM(status = 'rejected')                                          AS rejected,
                    SUM(status = 'active' AND coverage_end BETWEEN CURDATE()
                        AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))                     AS expiring,
                    SUM(member_type = 'บุคลากร')                                       AS staff,
                    SUM(member_type = 'นักศึกษา')                                      AS student
                FROM gold_card_members
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            json_ok(['stats' => array_map('intval', $row)]);
        }

        case 'member:list': {
            $page     = max(1, (int)($_POST['page'] ?? 1));
            $pageSize = max(10, min(100, (int)($_POST['page_size'] ?? 20)));
            $offset   = ($page - 1) * $pageSize;
            $search   = trim((string)($_POST['search'] ?? ''));
            $type     = trim((string)($_POST['type'] ?? ''));
            $status   = trim((string)($_POST['status'] ?? ''));
            $hospital = trim((string)($_POST['hospital'] ?? ''));

            $where = [];
            $params = [];
            if ($search !== '') {
                $where[] = "(full_name LIKE :s1 OR citizen_id LIKE :s2 OR phone LIKE :s3)";
                $params[':s1'] = "%$search%";
                $params[':s2'] = "%$search%";
                $params[':s3'] = "%$search%";
            }
            if ($type !== '')     { $where[] = "member_type = :type";    $params[':type'] = $type; }
            if ($status !== '')   { $where[] = "status = :status";       $params[':status'] = $status; }
            if ($hospital !== '') { $where[] = "hospital_main LIKE :h";  $params[':h'] = "%$hospital%"; }

            $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM gold_card_members $whereSql");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT m.*,
                           (SELECT COUNT(*) FROM gold_card_documents d WHERE d.member_id = m.id) AS doc_count
                    FROM gold_card_members m
                    $whereSql
                    ORDER BY m.updated_at DESC
                    LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            json_ok([
                'rows'      => $rows,
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
                'pages'     => (int)ceil($total / $pageSize),
            ]);
        }

        case 'member:get': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $stmt = $pdo->prepare("SELECT * FROM gold_card_members WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$member) json_err('ไม่พบสมาชิก');

            $docs = $pdo->prepare("SELECT id, doc_type, file_name, mime_type, file_size, uploaded_at
                                   FROM gold_card_documents WHERE member_id = ?
                                   ORDER BY uploaded_at DESC");
            $docs->execute([$id]);

            $hist = $pdo->prepare("SELECT action, old_value, new_value, changed_at
                                   FROM gold_card_history WHERE member_id = ?
                                   ORDER BY changed_at DESC LIMIT 50");
            $hist->execute([$id]);

            json_ok([
                'member'    => $member,
                'documents' => $docs->fetchAll(PDO::FETCH_ASSOC),
                'history'   => $hist->fetchAll(PDO::FETCH_ASSOC),
            ]);
        }

        case 'member:save': {
            $id          = (int)($_POST['id'] ?? 0);
            $citizenId   = preg_replace('/\D/', '', trim((string)($_POST['citizen_id'] ?? '')));
            $fullName    = trim((string)($_POST['full_name'] ?? ''));
            $memberType  = trim((string)($_POST['member_type'] ?? ''));
            $position    = trim((string)($_POST['position'] ?? ''));
            $phone       = trim((string)($_POST['phone'] ?? ''));
            $hospMain    = trim((string)($_POST['hospital_main'] ?? ''));
            $hospSub     = trim((string)($_POST['hospital_sub'] ?? ''));
            $applyDate   = trim((string)($_POST['application_date'] ?? '')) ?: null;
            $covStart    = trim((string)($_POST['coverage_start'] ?? '')) ?: null;
            $covEnd      = trim((string)($_POST['coverage_end'] ?? '')) ?: null;
            $status      = trim((string)($_POST['status'] ?? 'pending'));
            $remarks     = trim((string)($_POST['remarks'] ?? '')) ?: null;

            $allowedStatus = ['pending','submitted','approved','active','rejected','expired'];
            if (!in_array($status, $allowedStatus, true)) $status = 'pending';

            if (strlen($citizenId) !== 13) json_err('เลขบัตรประชาชนต้องเป็น 13 หลัก');
            if ($fullName === '') json_err('กรุณาระบุชื่อ-นามสกุล');

            if ($id > 0) {
                $old = $pdo->prepare("SELECT * FROM gold_card_members WHERE id = ?");
                $old->execute([$id]);
                $oldRow = $old->fetch(PDO::FETCH_ASSOC);
                if (!$oldRow) json_err('ไม่พบสมาชิก');

                $stmt = $pdo->prepare("UPDATE gold_card_members SET
                    citizen_id=?, full_name=?, member_type=?, position=?, phone=?,
                    hospital_main=?, hospital_sub=?, application_date=?, coverage_start=?, coverage_end=?,
                    status=?, remarks=?
                    WHERE id=?");
                $stmt->execute([
                    $citizenId, $fullName, $memberType, $position, $phone,
                    $hospMain, $hospSub, $applyDate, $covStart, $covEnd,
                    $status, $remarks, $id
                ]);

                if (($oldRow['status'] ?? '') !== $status) {
                    gold_card_log_history($pdo, $id, 'status_changed', $oldRow['status'], $status, $adminId);
                }
                gold_card_log_history($pdo, $id, 'updated', $oldRow, [
                    'full_name'=>$fullName, 'status'=>$status, 'hospital_main'=>$hospMain
                ], $adminId);

                json_ok(['id' => $id, 'message' => 'บันทึกข้อมูลเรียบร้อย']);
            } else {
                $exists = $pdo->prepare("SELECT id FROM gold_card_members WHERE citizen_id = ? LIMIT 1");
                $exists->execute([$citizenId]);
                if ($exists->fetchColumn()) json_err('เลขบัตรประชาชนนี้มีอยู่ในระบบแล้ว');

                $stmt = $pdo->prepare("INSERT INTO gold_card_members
                    (citizen_id, full_name, member_type, position, phone,
                     hospital_main, hospital_sub, application_date, coverage_start, coverage_end,
                     status, remarks, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $citizenId, $fullName, $memberType, $position, $phone,
                    $hospMain, $hospSub, $applyDate, $covStart, $covEnd,
                    $status, $remarks, $adminId ?: null
                ]);
                $newId = (int)$pdo->lastInsertId();
                gold_card_log_history($pdo, $newId, 'created', null, [
                    'full_name'=>$fullName, 'status'=>$status
                ], $adminId);

                json_ok(['id' => $newId, 'message' => 'เพิ่มสมาชิกใหม่เรียบร้อย']);
            }
        }

        case 'member:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $row = $pdo->prepare("SELECT full_name, citizen_id FROM gold_card_members WHERE id = ?");
            $row->execute([$id]);
            $info = $row->fetch(PDO::FETCH_ASSOC);
            if (!$info) json_err('ไม่พบสมาชิก');

            $docs = $pdo->prepare("SELECT stored_path FROM gold_card_documents WHERE member_id = ?");
            $docs->execute([$id]);
            $base = gold_card_uploads_dir();
            foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $sp) {
                $p = $base . '/' . $sp;
                if (is_file($p)) @unlink($p);
            }

            $pdo->prepare("DELETE FROM gold_card_members WHERE id = ?")->execute([$id]);
            gold_card_log_history($pdo, null, 'deleted', $info, null, $adminId);

            json_ok(['message' => 'ลบสมาชิกและเอกสารทั้งหมดเรียบร้อย']);
        }

        case 'document:upload': {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $docType  = trim((string)($_POST['doc_type'] ?? 'other'));
            $allowed  = ['id_copy','house_reg','application','photo','medical','other'];
            if (!in_array($docType, $allowed, true)) $docType = 'other';

            if ($memberId <= 0) json_err('ระบุ member_id ไม่ถูกต้อง');
            if (empty($_FILES['file']['name'])) json_err('ไม่พบไฟล์');

            $check = $pdo->prepare("SELECT id FROM gold_card_members WHERE id = ?");
            $check->execute([$memberId]);
            if (!$check->fetchColumn()) json_err('ไม่พบสมาชิก');

            $saved = gold_card_save_uploaded_file($_FILES['file'], $memberId);
            if (!$saved) json_err('อัปโหลดไม่สำเร็จ — ตรวจสอบนามสกุลและขนาดไฟล์ (≤20MB, PDF/JPG/PNG/DOC)');

            $stmt = $pdo->prepare("INSERT INTO gold_card_documents
                (member_id, doc_type, file_name, stored_path, mime_type, file_size, sha1_hash, uploaded_by)
                VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $memberId, $docType,
                $saved['file_name'], $saved['stored_path'],
                $saved['mime_type'], $saved['file_size'], $saved['sha1_hash'],
                $adminId ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            gold_card_log_history($pdo, $memberId, 'doc_added', null, [
                'doc_id'=>$newId, 'doc_type'=>$docType, 'file_name'=>$saved['file_name']
            ], $adminId);

            json_ok([
                'id' => $newId,
                'file_name' => $saved['file_name'],
                'message' => 'อัปโหลดเอกสารเรียบร้อย',
            ]);
        }

        case 'document:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $stmt = $pdo->prepare("SELECT member_id, stored_path, file_name FROM gold_card_documents WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('ไม่พบไฟล์');

            $pdo->prepare("DELETE FROM gold_card_documents WHERE id = ?")->execute([$id]);
            $path = gold_card_uploads_dir() . '/' . $row['stored_path'];
            if (is_file($path)) @unlink($path);

            gold_card_log_history($pdo, (int)$row['member_id'], 'doc_removed', [
                'doc_id'=>$id, 'file_name'=>$row['file_name']
            ], null, $adminId);

            json_ok(['message' => 'ลบเอกสารเรียบร้อย']);
        }

        case 'document:download': {
            // ส่งไฟล์ออก (ไม่ใช่ JSON) — handle separately
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) json_err('ระบุ id ไม่ถูกต้อง');

            $stmt = $pdo->prepare("SELECT file_name, stored_path, mime_type FROM gold_card_documents WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) json_err('ไม่พบไฟล์');

            $path = gold_card_uploads_dir() . '/' . $row['stored_path'];
            if (!is_file($path)) json_err('ไฟล์หายไปจาก storage');

            // Override JSON header
            header_remove('Content-Type');
            header('Content-Type: ' . ($row['mime_type'] ?: 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . rawurlencode($row['file_name']) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }

        case 'bulk:scan': {
            // รับไฟล์หลายๆ ไฟล์ → preview ผลการ match (ยังไม่ commit)
            // Files ส่งมาเป็น $_FILES['files']['name'][...] (multiple)
            if (empty($_FILES['files']['name'])) json_err('ไม่พบไฟล์');
            if (!is_array($_FILES['files']['name'])) json_err('ใช้ <input type="file" multiple> เท่านั้น');

            $report = [];
            $names  = $_FILES['files']['name'];
            $errors = $_FILES['files']['error'];
            $sizes  = $_FILES['files']['size'];

            foreach ($names as $i => $fname) {
                if ($errors[$i] !== UPLOAD_ERR_OK) {
                    $report[] = ['filename' => $fname, 'status' => 'upload_error', 'name' => '', 'size' => 0];
                    continue;
                }
                $match = gc_match_user($pdo, $fname);
                $extracted = $match['name'];

                // Check if already exists
                $existsId = null;
                if (!empty($match['user']['citizen_id'])) {
                    $st = $pdo->prepare("SELECT id FROM gold_card_members WHERE citizen_id = ? LIMIT 1");
                    $st->execute([$match['user']['citizen_id']]);
                    $existsId = (int)$st->fetchColumn() ?: null;
                }
                if (!$existsId) {
                    $st = $pdo->prepare("SELECT id FROM gold_card_members WHERE source_filename = ? LIMIT 1");
                    $st->execute([$fname]);
                    $existsId = (int)$st->fetchColumn() ?: null;
                }

                $report[] = [
                    'index'      => $i,
                    'filename'   => $fname,
                    'extracted_name' => $extracted,
                    'size'       => $sizes[$i],
                    'status'     => $match['status'],
                    'user'       => $match['user'] ?? null,
                    'candidates' => $match['candidates'] ?? [],
                    'already_exists' => $existsId,
                ];
            }
            json_ok(['report' => $report]);
        }

        case 'bulk:commit': {
            // Commit รายการที่ admin confirm แล้ว
            // POST: items[] = JSON array of:
            //   { filename, name, user_id (nullable), citizen_id (nullable), tmp_idx }
            // Files ยัง stay ใน $_FILES['files']
            if (empty($_FILES['files']['name'])) json_err('ไม่พบไฟล์');
            $itemsJson = $_POST['items'] ?? '[]';
            $items = json_decode($itemsJson, true);
            if (!is_array($items)) json_err('items ไม่ถูกต้อง');

            $created = 0; $skipped = 0; $errs = [];

            foreach ($items as $it) {
                $idx       = (int)($it['index'] ?? -1);
                $filename  = $_FILES['files']['name'][$idx] ?? '';
                $tmpName   = $_FILES['files']['tmp_name'][$idx] ?? '';
                $errCode   = $_FILES['files']['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
                $userId    = !empty($it['user_id']) ? (int)$it['user_id'] : null;
                $citizenId = !empty($it['citizen_id']) ? preg_replace('/\D/', '', (string)$it['citizen_id']) : null;
                $fullName  = trim((string)($it['name'] ?? ''));

                if ($errCode !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
                    $skipped++; $errs[] = "$filename: upload error"; continue;
                }
                if ($fullName === '') { $skipped++; $errs[] = "$filename: ไม่มีชื่อ"; continue; }

                // Skip duplicates
                if ($citizenId) {
                    $st = $pdo->prepare("SELECT id FROM gold_card_members WHERE citizen_id = ? LIMIT 1");
                    $st->execute([$citizenId]);
                    if ($st->fetchColumn()) { $skipped++; $errs[] = "$filename: citizen_id ซ้ำ"; continue; }
                }

                // INSERT member
                $stmt = $pdo->prepare("INSERT INTO gold_card_members
                    (citizen_id, linked_user_id, full_name, member_type, status,
                     source_filename, application_date, coverage_start, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?)");
                $memberType = $userId ? 'นักศึกษา' : 'บุคคลทั่วไป';
                $stmt->execute([
                    $citizenId ?: null,
                    $userId,
                    $fullName,
                    $memberType,
                    $userId ? 'active' : 'pending',
                    $filename,
                    date('Y-m-d'),
                    date('Y-m-d'),
                    $adminId ?: null
                ]);
                $newId = (int)$pdo->lastInsertId();

                // Save file
                $saved = gold_card_save_uploaded_file([
                    'name' => $filename, 'tmp_name' => $tmpName,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $_FILES['files']['size'][$idx] ?? 0,
                    'type' => $_FILES['files']['type'][$idx] ?? null,
                ], $newId);

                if ($saved) {
                    $pdo->prepare("INSERT INTO gold_card_documents
                        (member_id, doc_type, file_name, stored_path, mime_type, file_size, sha1_hash, uploaded_by)
                        VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([
                            $newId, 'application',
                            $saved['file_name'], $saved['stored_path'],
                            $saved['mime_type'], $saved['file_size'], $saved['sha1_hash'],
                            $adminId ?: null,
                       ]);
                }

                gold_card_log_history($pdo, $newId, 'bulk_imported', null, [
                    'filename' => $filename, 'matched_user_id' => $userId
                ], $adminId);
                $created++;
            }

            json_ok([
                'created' => $created,
                'skipped' => $skipped,
                'errors'  => $errs,
                'message' => "สร้าง $created รายการ" . ($skipped > 0 ? " · ข้าม $skipped" : ''),
            ]);
        }

        case 'bulk:link_user': {
            // Manual link: admin เลือก member + user จาก dropdown
            $memberId = (int)($_POST['member_id'] ?? 0);
            $userId   = (int)($_POST['user_id'] ?? 0);
            if ($memberId <= 0 || $userId <= 0) json_err('ระบุ member_id + user_id ไม่ถูกต้อง');

            $u = $pdo->prepare("SELECT id, full_name, citizen_id FROM sys_users WHERE id = ? LIMIT 1");
            $u->execute([$userId]);
            $user = $u->fetch(PDO::FETCH_ASSOC);
            if (!$user) json_err('ไม่พบ user');

            $pdo->prepare("UPDATE gold_card_members
                           SET linked_user_id = ?, citizen_id = COALESCE(NULLIF(citizen_id,''), ?), status = 'active'
                           WHERE id = ?")
                ->execute([$userId, $user['citizen_id'] ?: null, $memberId]);

            gold_card_log_history($pdo, $memberId, 'linked_to_user', null, [
                'user_id' => $userId, 'user_name' => $user['full_name']
            ], $adminId);

            json_ok(['message' => 'Link เรียบร้อย — สถานะเปลี่ยนเป็น Active']);
        }

        case 'bulk:search_users': {
            // ค้นหา users ใน sys_users สำหรับ manual matching dropdown
            $q = trim((string)($_POST['q'] ?? ''));
            if (mb_strlen($q) < 2) json_ok(['users' => []]);
            $stmt = $pdo->prepare("SELECT id, full_name, citizen_id, student_personnel_id, status
                                   FROM sys_users
                                   WHERE full_name LIKE ? OR student_personnel_id LIKE ? OR citizen_id LIKE ?
                                   ORDER BY full_name ASC LIMIT 20");
            $like = "%$q%";
            $stmt->execute([$like, $like, $like]);
            json_ok(['users' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        default:
            json_err('ไม่รู้จัก entity:action — ' . htmlspecialchars("$entity:$action"));
    }
} catch (Throwable $e) {
    error_log('[ajax_gold_card] ' . $e->getMessage());
    json_err('ระบบขัดข้อง: ' . $e->getMessage(), 500);
}
