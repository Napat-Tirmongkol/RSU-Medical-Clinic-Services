<?php
/**
 * portal/ajax_edms.php — central AJAX for EDMS module
 *
 * Entity / Action pattern (POST):
 *   document:get      — รายละเอียดเอกสาร 1 รายการ (รวม attachments)
 *   document:create   — สร้างใหม่ (auto running number ถ้า status != draft)
 *   document:update   — แก้ไข
 *   document:delete   — ลบ + ลบ attachments file ใต้ uploads/edms/
 *   document:archive  — เปลี่ยน status เป็น archived
 *   document:cancel   — เปลี่ยน status เป็น cancelled
 *   attachment:upload — อัปโหลดไฟล์แนบ (multi-file ผ่าน $_FILES['files'])
 *   attachment:delete — ลบไฟล์แนบเดี่ยว
 *
 * Authentication: superadmin หรือ access_edms = 1
 * CSRF: validate_csrf_or_die() ทุก action
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/_partials/edms/_helpers.php';
require_once __DIR__ . '/../includes/edms_sla_helper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'POST only']);
    exit;
}

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$hasEdms   = !empty($_SESSION['access_edms']);

if (!$isSuper && !$hasEdms) {
    echo json_encode(['ok' => false, 'message' => 'Permission denied']);
    exit;
}

validate_csrf_or_die();

$pdo    = db();
$entity = $_POST['entity'] ?? '';
$action = $_POST['action'] ?? '';
$userId = (int)($_SESSION['admin_id'] ?? 0);

// ── Runtime self-heal: versioning columns on sys_doc_attachments ─────────
// (canonical schema lives in database/migrations/migrate_edms_module.php
//  but rerunning that script in production is rare — these idempotent
//  ALTERs let upload_version / list_versions land without manual ops)
foreach ([
    "ALTER TABLE sys_doc_attachments ADD COLUMN root_id INT UNSIGNED NULL AFTER doc_id",
    "ALTER TABLE sys_doc_attachments ADD COLUMN version_no INT NOT NULL DEFAULT 1 AFTER root_id",
    "ALTER TABLE sys_doc_attachments ADD COLUMN is_current TINYINT(1) NOT NULL DEFAULT 1 AFTER version_no",
    "ALTER TABLE sys_doc_attachments ADD COLUMN role ENUM('primary','supporting') NOT NULL DEFAULT 'supporting' AFTER is_current",
    "ALTER TABLE sys_doc_attachments ADD COLUMN superseded_at DATETIME NULL AFTER role",
    "ALTER TABLE sys_doc_attachments ADD INDEX idx_doc_current (doc_id, is_current)",
    "ALTER TABLE sys_doc_attachments ADD INDEX idx_doc_role (doc_id, role)",
    "ALTER TABLE sys_doc_attachments ADD INDEX idx_root_chain (root_id, version_no)",
] as $sql) {
    try { $pdo->exec($sql); } catch (PDOException) {}
}

// ── Helpers ──────────────────────────────────────────────────────────────

function edms_log(PDO $pdo, int $docId, ?int $userId, string $action, array $detail = []): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO sys_doc_logs (doc_id, user_id, action, detail, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $docId,
            $userId ?: null,
            $action,
            $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException) {
        // log failure จะไม่บล็อก operation
    }
}

function edms_year_be(?string $date = null): int
{
    $ts = $date ? strtotime($date) : time();
    return (int)date('Y', $ts) + 543;
}

function edms_assign_number(PDO $pdo, string $docType, int $yearBe): array
{
    // Atomic: lock + increment counter ภายใน transaction (เรียกจาก context ที่อยู่ใน transaction แล้ว)
    $sel = $pdo->prepare("SELECT current_no FROM sys_doc_counters WHERE year_be = ? AND doc_type = ? FOR UPDATE");
    $sel->execute([$yearBe, $docType]);
    $current = $sel->fetchColumn();

    $next = ($current === false) ? 1 : ((int)$current + 1);

    if ($current === false) {
        $pdo->prepare("INSERT INTO sys_doc_counters (year_be, doc_type, current_no) VALUES (?, ?, ?)")
            ->execute([$yearBe, $docType, $next]);
    } else {
        $pdo->prepare("UPDATE sys_doc_counters SET current_no = ? WHERE year_be = ? AND doc_type = ?")
            ->execute([$next, $yearBe, $docType]);
    }

    // โหลด prefix (short_label) จากตาราง sys_doc_types
    $typeMap = edms_get_doc_type_map($pdo, false);
    $prefix = $typeMap[$docType]['short_label'] ?? 'DOC';
    if ($prefix === '' || $prefix === null) $prefix = 'DOC';
    $number = sprintf('%s-%03d/%d', $prefix, $next, $yearBe);

    return ['running_no' => $next, 'doc_number' => $number, 'year_be' => $yearBe];
}

function edms_normalize_date(?string $val): ?string
{
    if (!$val) return null;
    $val = trim($val);
    return $val !== '' ? $val : null;
}

function edms_uploads_dir(): string
{
    return dirname(__DIR__) . '/uploads/edms';
}

function edms_save_uploaded_file(array $file, int $docId): ?array
{
    $maxSize = 20 * 1024 * 1024; // 20MB
    $allowedExt = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'];

    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] <= 0 || $file['size'] > $maxSize) return null;

    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return null;

    $year   = date('Y');
    $month  = date('m');
    $base   = edms_uploads_dir();
    $subdir = $base . "/$year/$month";
    if (!is_dir($subdir)) {
        if (!@mkdir($subdir, 0755, true)) return null;
    }

    $hash = sha1_file($file['tmp_name']) ?: bin2hex(random_bytes(8));
    $stored = sprintf('%s/%s/doc%d_%s.%s', $year, $month, $docId, substr($hash, 0, 16), $ext);
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

// ── Auto-migrate guard (in case migration ยังไม่รัน) ───────────────────────
try {
    $pdo->query("SELECT 1 FROM sys_doc_documents LIMIT 1");
} catch (PDOException) {
    echo json_encode(['ok' => false, 'message' => 'ตาราง sys_doc_documents ยังไม่ถูกสร้าง — กรุณารัน migrate_edms_module.php ก่อน']);
    exit;
}

// ── Dispatch ─────────────────────────────────────────────────────────────
try {
    switch ("$entity:$action") {

        // ════════════ DOCUMENT: GET ════════════
        case 'document:get': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $stmt = $pdo->prepare("
                SELECT d.*,
                       cat.name AS priority_name,
                       cat.color AS priority_color,
                       s.full_name AS created_by_name
                FROM sys_doc_documents d
                LEFT JOIN sys_doc_categories cat ON cat.id = d.priority_id
                LEFT JOIN sys_staff s ON s.id = d.created_by
                WHERE d.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new RuntimeException('ไม่พบเอกสาร');

            $att = $pdo->prepare("SELECT id, file_name, stored_path, mime_type, file_size, uploaded_at
                                  FROM sys_doc_attachments WHERE doc_id = ? ORDER BY id ASC");
            $att->execute([$id]);
            $doc['attachments'] = $att->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'data' => $doc], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ DOCUMENT: CREATE ════════════
        case 'document:create': {
            $docType = $_POST['doc_type'] ?? '';
            if (!edms_valid_doc_type($pdo, $docType)) {
                throw new RuntimeException('doc_type ไม่ถูกต้อง');
            }

            $subject = trim($_POST['subject'] ?? '');
            if ($subject === '') throw new RuntimeException('กรุณาระบุเรื่อง');

            $status = $_POST['status'] ?? 'draft';
            if (!in_array($status, ['draft', 'registered'], true)) $status = 'draft';

            $docDate     = edms_normalize_date($_POST['doc_date'] ?? null);
            $receivedDate= edms_normalize_date($_POST['received_date'] ?? null);
            $sender      = trim($_POST['sender'] ?? '') ?: null;
            $recipient   = trim($_POST['recipient'] ?? '') ?: null;
            $body        = trim($_POST['body'] ?? '') ?: null;
            $summary     = trim($_POST['summary'] ?? '') ?: null;
            $priorityId  = (int)($_POST['priority_id'] ?? 0) ?: null;
            $confidentiality = $_POST['confidentiality'] ?? 'normal';
            if (!in_array($confidentiality, ['normal', 'confidential', 'secret', 'top_secret'], true)) {
                $confidentiality = 'normal';
            }

            $pdo->beginTransaction();

            $docNumber = null;
            $runningNo = null;
            $yearBe    = edms_year_be($docDate);

            if ($status === 'registered') {
                $assigned   = edms_assign_number($pdo, $docType, $yearBe);
                $docNumber  = $assigned['doc_number'];
                $runningNo  = $assigned['running_no'];
            }

            $stmt = $pdo->prepare("
                INSERT INTO sys_doc_documents
                    (doc_type, doc_number, running_no, year_be, subject, body, summary,
                     doc_date, received_date, sender, recipient,
                     priority_id, confidentiality, status, created_by, updated_by)
                VALUES
                    (:doc_type, :doc_number, :running_no, :year_be, :subject, :body, :summary,
                     :doc_date, :received_date, :sender, :recipient,
                     :priority_id, :confidentiality, :status, :created_by, :updated_by)
            ");
            $stmt->execute([
                ':doc_type'       => $docType,
                ':doc_number'     => $docNumber,
                ':running_no'     => $runningNo,
                ':year_be'        => $yearBe,
                ':subject'        => $subject,
                ':body'           => $body,
                ':summary'        => $summary,
                ':doc_date'       => $docDate,
                ':received_date'  => $receivedDate,
                ':sender'         => $sender,
                ':recipient'      => $recipient,
                ':priority_id'    => $priorityId,
                ':confidentiality'=> $confidentiality,
                ':status'         => $status,
                ':created_by'     => $userId ?: null,
                ':updated_by'     => $userId ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->commit();

            edms_log($pdo, $newId, $userId, 'create', ['status' => $status, 'doc_number' => $docNumber]);

            echo json_encode([
                'ok'         => true,
                'id'         => $newId,
                'doc_number' => $docNumber,
                'message'    => $status === 'registered' ? "ลงทะเบียนเอกสาร {$docNumber} แล้ว" : 'บันทึกฉบับร่างแล้ว',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ DOCUMENT: UPDATE ════════════
        case 'document:update': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $cur = $pdo->prepare("SELECT * FROM sys_doc_documents WHERE id = ? LIMIT 1");
            $cur->execute([$id]);
            $doc = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new RuntimeException('ไม่พบเอกสาร');

            $subject = trim($_POST['subject'] ?? '');
            if ($subject === '') throw new RuntimeException('กรุณาระบุเรื่อง');

            $newStatus = $_POST['status'] ?? $doc['status'];
            if (!in_array($newStatus, ['draft', 'registered', 'routing', 'in_progress', 'completed', 'archived', 'cancelled'], true)) {
                $newStatus = $doc['status'];
            }

            $docDate      = edms_normalize_date($_POST['doc_date'] ?? null);
            $receivedDate = edms_normalize_date($_POST['received_date'] ?? null);
            $sender       = trim($_POST['sender'] ?? '') ?: null;
            $recipient    = trim($_POST['recipient'] ?? '') ?: null;
            $body         = trim($_POST['body'] ?? '') ?: null;
            $summary      = trim($_POST['summary'] ?? '') ?: null;
            $priorityId   = (int)($_POST['priority_id'] ?? 0) ?: null;
            $confidentiality = $_POST['confidentiality'] ?? $doc['confidentiality'];
            if (!in_array($confidentiality, ['normal', 'confidential', 'secret', 'top_secret'], true)) {
                $confidentiality = 'normal';
            }

            $pdo->beginTransaction();

            // ถ้าเดิม draft แล้วเปลี่ยนเป็น registered → assign running number
            $docNumber = $doc['doc_number'];
            $runningNo = $doc['running_no'];
            $yearBe    = $doc['year_be'] ?: edms_year_be($docDate);

            if ($doc['status'] === 'draft' && $newStatus !== 'draft' && empty($doc['doc_number'])) {
                $yearBe   = edms_year_be($docDate);
                $assigned = edms_assign_number($pdo, $doc['doc_type'], $yearBe);
                $docNumber = $assigned['doc_number'];
                $runningNo = $assigned['running_no'];
            }

            $stmt = $pdo->prepare("
                UPDATE sys_doc_documents SET
                    doc_number       = :doc_number,
                    running_no       = :running_no,
                    year_be          = :year_be,
                    subject          = :subject,
                    body             = :body,
                    summary          = :summary,
                    doc_date         = :doc_date,
                    received_date    = :received_date,
                    sender           = :sender,
                    recipient        = :recipient,
                    priority_id      = :priority_id,
                    confidentiality  = :confidentiality,
                    status           = :status,
                    updated_by       = :updated_by
                WHERE id = :id
            ");
            $stmt->execute([
                ':doc_number'     => $docNumber,
                ':running_no'     => $runningNo,
                ':year_be'        => $yearBe,
                ':subject'        => $subject,
                ':body'           => $body,
                ':summary'        => $summary,
                ':doc_date'       => $docDate,
                ':received_date'  => $receivedDate,
                ':sender'         => $sender,
                ':recipient'      => $recipient,
                ':priority_id'    => $priorityId,
                ':confidentiality'=> $confidentiality,
                ':status'         => $newStatus,
                ':updated_by'     => $userId ?: null,
                ':id'             => $id,
            ]);
            $pdo->commit();

            edms_log($pdo, $id, $userId, 'update', ['status_from' => $doc['status'], 'status_to' => $newStatus, 'doc_number' => $docNumber]);

            echo json_encode([
                'ok' => true,
                'id' => $id,
                'doc_number' => $docNumber,
                'message' => $docNumber && $doc['status'] === 'draft' ? "ลงทะเบียนเอกสาร {$docNumber} แล้ว" : 'บันทึกแล้ว',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ DOCUMENT: DELETE ════════════
        case 'document:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $att = $pdo->prepare("SELECT id, stored_path FROM sys_doc_attachments WHERE doc_id = ?");
            $att->execute([$id]);
            $files = $att->fetchAll(PDO::FETCH_ASSOC);

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM sys_doc_attachments WHERE doc_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM sys_doc_routings WHERE doc_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM sys_doc_logs WHERE doc_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM sys_doc_documents WHERE id = ?")->execute([$id]);
            $pdo->commit();

            // ลบไฟล์จริงหลัง commit สำเร็จ
            $base = edms_uploads_dir();
            foreach ($files as $f) {
                $path = $base . '/' . $f['stored_path'];
                if (is_file($path)) @unlink($path);
            }

            echo json_encode(['ok' => true, 'message' => 'ลบเอกสารแล้ว']);
            exit;
        }

        // ════════════ DOCUMENT: ARCHIVE / CANCEL ════════════
        case 'document:archive':
        case 'document:cancel': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $newStatus = $action === 'archive' ? 'archived' : 'cancelled';
            $pdo->prepare("UPDATE sys_doc_documents SET status = ?, updated_by = ? WHERE id = ?")
                ->execute([$newStatus, $userId ?: null, $id]);

            edms_log($pdo, $id, $userId, $newStatus);

            echo json_encode(['ok' => true, 'message' => $newStatus === 'archived' ? 'เก็บเข้าแฟ้มแล้ว' : 'ยกเลิกแล้ว']);
            exit;
        }

        // ════════════ ATTACHMENT: UPLOAD ════════════
        case 'attachment:upload': {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId <= 0) throw new RuntimeException('ระบุ doc_id ไม่ถูกต้อง');

            $check = $pdo->prepare("SELECT id FROM sys_doc_documents WHERE id = ?");
            $check->execute([$docId]);
            if (!$check->fetchColumn()) throw new RuntimeException('ไม่พบเอกสาร');

            if (empty($_FILES['files']['name'][0]) && empty($_FILES['files']['name'])) {
                throw new RuntimeException('ไม่พบไฟล์');
            }

            // Normalize multi-file vs single-file
            $files = [];
            if (is_array($_FILES['files']['name'])) {
                foreach ($_FILES['files']['name'] as $i => $name) {
                    $files[] = [
                        'name'     => $name,
                        'type'     => $_FILES['files']['type'][$i] ?? '',
                        'tmp_name' => $_FILES['files']['tmp_name'][$i] ?? '',
                        'error'    => $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                        'size'     => $_FILES['files']['size'][$i] ?? 0,
                    ];
                }
            }

            $uploaded = [];
            $skipped  = [];
            $stmt = $pdo->prepare("
                INSERT INTO sys_doc_attachments (doc_id, file_name, stored_path, mime_type, file_size, sha1_hash, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($files as $f) {
                if ($f['error'] !== UPLOAD_ERR_OK) { $skipped[] = $f['name']; continue; }
                $saved = edms_save_uploaded_file($f, $docId);
                if (!$saved) { $skipped[] = $f['name']; continue; }
                $stmt->execute([
                    $docId,
                    $saved['file_name'],
                    $saved['stored_path'],
                    $saved['mime_type'],
                    $saved['file_size'],
                    $saved['sha1_hash'],
                    $userId ?: null,
                ]);
                $uploaded[] = ['id' => (int)$pdo->lastInsertId(), 'file_name' => $saved['file_name']];
            }

            edms_log($pdo, $docId, $userId, 'attach', ['uploaded' => count($uploaded), 'skipped' => count($skipped)]);

            $msg = count($uploaded) . ' ไฟล์อัปโหลดแล้ว';
            if ($skipped) $msg .= ' · ข้าม ' . count($skipped) . ' ไฟล์ (ขนาดเกิน 20MB หรือนามสกุลไม่รองรับ)';

            echo json_encode(['ok' => true, 'uploaded' => $uploaded, 'skipped' => $skipped, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ DOCUMENT: COMPLETE ════════════
        case 'document:complete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $pdo->prepare("UPDATE sys_doc_documents SET status = 'completed', updated_by = ? WHERE id = ?")
                ->execute([$userId ?: null, $id]);

            edms_log($pdo, $id, $userId, 'complete');
            echo json_encode(['ok' => true, 'message' => 'ปิดเรื่องเรียบร้อย']);
            exit;
        }

        // ════════════ ROUTING: FORWARD (โอน/มอบหมาย) ════════════
        case 'routing:forward': {
            $docId    = (int)($_POST['doc_id'] ?? 0);
            $toUser   = (int)($_POST['to_user_id'] ?? 0) ?: null;
            $toDept   = trim($_POST['to_dept'] ?? '') ?: null;
            $rAction  = $_POST['r_action'] ?? 'forward';
            $comment  = trim($_POST['comment'] ?? '') ?: null;
            $dueDate  = edms_normalize_date($_POST['due_date'] ?? null);

            // SLA override (Auto + ติ๊ก "กำหนดเวลาเอง")
            $slaOverride = !empty($_POST['sla_override']);
            $slaAckOver  = trim($_POST['sla_ack_deadline'] ?? '') ?: null;
            $slaResOver  = trim($_POST['sla_resolve_deadline'] ?? '') ?: null;
            $slaReason   = trim($_POST['sla_reason'] ?? '') ?: null;
            if ($slaOverride) {
                if (!$slaAckOver || !$slaResOver) {
                    throw new RuntimeException('กำหนดเวลาเอง: ต้องระบุทั้ง ack และ resolve deadline');
                }
                if (!$slaReason) {
                    throw new RuntimeException('กำหนดเวลาเอง: ต้องระบุเหตุผล');
                }
            }

            if ($docId <= 0) throw new RuntimeException('ระบุ doc_id ไม่ถูกต้อง');
            if (!$toUser && !$toDept) throw new RuntimeException('กรุณาเลือกผู้รับหรือฝ่ายปลายทาง');
            if (!in_array($rAction, ['forward','assign','approve','sign','return','note','close'], true)) {
                $rAction = 'forward';
            }

            $cur = $pdo->prepare("SELECT id, status FROM sys_doc_documents WHERE id = ?");
            $cur->execute([$docId]);
            $doc = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new RuntimeException('ไม่พบเอกสาร');
            if (in_array($doc['status'], ['draft','archived','cancelled'], true)) {
                throw new RuntimeException('ไม่สามารถโอนเอกสารในสถานะนี้ได้ — กรุณาลงทะเบียนก่อน');
            }

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO sys_doc_routings (doc_id, from_user_id, to_user_id, to_dept, action, comment, due_date, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
            ")->execute([$docId, $userId ?: null, $toUser, $toDept, $rAction, $comment, $dueDate]);

            $newRouteId = (int)$pdo->lastInsertId();

            // เปลี่ยนสถานะเอกสารเป็น routing ถ้ายังเป็น registered
            if ($doc['status'] === 'registered') {
                $pdo->prepare("UPDATE sys_doc_documents SET status = 'routing', updated_by = ? WHERE id = ?")
                    ->execute([$userId ?: null, $docId]);
            }
            $pdo->commit();

            // Auto-attach SLA — outside transaction (best-effort)
            $slaOverrideArr = null;
            if ($slaOverride) {
                $slaOverrideArr = [
                    'ack_deadline_at'     => $slaAckOver,
                    'resolve_deadline_at' => $slaResOver,
                    'reason'              => $slaReason,
                ];
            }
            sla_attach_to_routing($pdo, $newRouteId, $slaOverrideArr);

            edms_log($pdo, $docId, $userId, 'route', [
                'routing_id' => $newRouteId,
                'action'     => $rAction,
                'to_user_id' => $toUser,
                'to_dept'    => $toDept,
                'due_date'   => $dueDate,
                'sla_override' => $slaOverride,
            ]);

            echo json_encode(['ok' => true, 'message' => 'โอนเอกสารแล้ว', 'routing_id' => $newRouteId], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ ROUTING: ACKNOWLEDGE / COMPLETE / RETURN ════════════
        case 'routing:acknowledge':
        case 'routing:complete':
        case 'routing:return': {
            $routeId = (int)($_POST['routing_id'] ?? 0);
            if ($routeId <= 0) throw new RuntimeException('ระบุ routing_id ไม่ถูกต้อง');
            $comment = trim($_POST['comment'] ?? '') ?: null;

            $sel = $pdo->prepare("SELECT * FROM sys_doc_routings WHERE id = ? LIMIT 1");
            $sel->execute([$routeId]);
            $route = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$route) throw new RuntimeException('ไม่พบรายการโอน');

            // ตรวจว่าเป็นผู้รับหรือไม่ (เฉพาะ to_user_id ที่ทำได้ + superadmin override)
            $isSuperUser = ($_SESSION['admin_role'] ?? '') === 'superadmin';
            if (!$isSuperUser && $route['to_user_id'] !== null && (int)$route['to_user_id'] !== $userId) {
                throw new RuntimeException('คุณไม่ใช่ผู้รับมอบหมายเอกสารนี้');
            }
            if (in_array($route['status'], ['done','returned'], true)) {
                throw new RuntimeException('รายการนี้ถูกปิดไปแล้ว');
            }

            $newStatus = match($action) {
                'acknowledge' => 'acknowledged',
                'complete'    => 'done',
                'return'      => 'returned',
            };

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE sys_doc_routings SET status = ?, completed_at = " . ($newStatus !== 'acknowledged' ? 'NOW()' : 'completed_at') . " WHERE id = ?")
                ->execute([$newStatus, $routeId]);

            // ถ้าตีกลับ → สร้าง routing ใหม่กลับไปยัง from_user
            if ($newStatus === 'returned' && !empty($route['from_user_id'])) {
                $pdo->prepare("
                    INSERT INTO sys_doc_routings (doc_id, from_user_id, to_user_id, action, comment, status)
                    VALUES (?, ?, ?, 'return', ?, 'pending')
                ")->execute([
                    $route['doc_id'],
                    $userId ?: null,
                    $route['from_user_id'],
                    $comment ?: 'ตีกลับเพื่อแก้ไข',
                ]);
            }

            // ถ้า routings ทั้งหมดของเอกสารนี้ปิดหมด (done/returned) และ doc status='routing' → ตั้ง in_progress
            $cnt = $pdo->prepare("SELECT
                    SUM(CASE WHEN status IN ('pending','acknowledged') THEN 1 ELSE 0 END) AS open_cnt,
                    COUNT(*) AS total
                FROM sys_doc_routings WHERE doc_id = ?");
            $cnt->execute([$route['doc_id']]);
            $rcnt = $cnt->fetch(PDO::FETCH_ASSOC);
            if ((int)$rcnt['open_cnt'] === 0 && (int)$rcnt['total'] > 0) {
                $pdo->prepare("UPDATE sys_doc_documents SET status = 'in_progress', updated_by = ? WHERE id = ? AND status = 'routing'")
                    ->execute([$userId ?: null, $route['doc_id']]);
            }
            $pdo->commit();

            // SLA hooks (outside transaction)
            if ($newStatus === 'acknowledged') {
                sla_acknowledge($pdo, $routeId, $userId ?: null);
            } elseif ($newStatus === 'done') {
                sla_mark_met($pdo, $routeId, $userId ?: null);
            } elseif ($newStatus === 'returned') {
                // ตีกลับ → cancel SLA ของ routing เดิม (ไม่ใช่ breach)
                try {
                    $pdo->prepare("UPDATE sys_doc_routings SET sla_state = 'cancelled' WHERE id = ? AND sla_state NOT IN ('met','cancelled','none')")
                        ->execute([$routeId]);
                    sla_event_log($pdo, $routeId, (int)$route['doc_id'], 'cancelled', $userId ?: null, 'routing returned');
                } catch (PDOException) {}
            }

            edms_log($pdo, (int)$route['doc_id'], $userId, $newStatus === 'done' ? 'route_done' : ($newStatus === 'returned' ? 'route_return' : 'route_ack'), [
                'routing_id' => $routeId,
                'comment'    => $comment,
            ]);

            $msg = match($newStatus) {
                'acknowledged' => 'รับทราบแล้ว',
                'done'         => 'ดำเนินการเสร็จสิ้น',
                'returned'     => 'ตีกลับแล้ว',
            };
            echo json_encode(['ok' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ CATEGORY: LIST ════════════
        case 'category:list': {
            $kind = $_REQUEST['kind'] ?? '';
            $sql = "SELECT id, kind, code, name, color, sort_order, is_active,
                           (SELECT COUNT(*) FROM sys_doc_documents
                              WHERE priority_id = sys_doc_categories.id) AS used_count
                    FROM sys_doc_categories";
            $params = [];
            if (in_array($kind, ['priority','confidentiality','custom'], true)) {
                $sql .= " WHERE kind = ?";
                $params[] = $kind;
            }
            $sql .= " ORDER BY kind ASC, sort_order ASC, name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode([
                'ok' => true,
                'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ CATEGORY: CRUD ════════════
        case 'category:create':
        case 'category:update': {
            $kind = $_POST['kind'] ?? 'priority';
            if (!in_array($kind, ['priority','confidentiality','custom'], true)) $kind = 'priority';
            $code  = trim($_POST['code'] ?? '');
            $name  = trim($_POST['name'] ?? '');
            $color = trim($_POST['color'] ?? '') ?: null;
            $sort  = (int)($_POST['sort_order'] ?? 0);
            $active= (int)($_POST['is_active'] ?? 1) ? 1 : 0;

            if ($code === '' || $name === '') throw new RuntimeException('กรอกรหัสและชื่อให้ครบ');

            if ($action === 'create') {
                $stmt = $pdo->prepare("INSERT INTO sys_doc_categories (kind, code, name, color, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$kind, $code, $name, $color, $sort, $active]);
                $newId = (int)$pdo->lastInsertId();
                echo json_encode(['ok' => true, 'id' => $newId, 'message' => 'เพิ่มหมวดแล้ว'], JSON_UNESCAPED_UNICODE);
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');
                $stmt = $pdo->prepare("UPDATE sys_doc_categories SET kind=?, code=?, name=?, color=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$kind, $code, $name, $color, $sort, $active, $id]);
                echo json_encode(['ok' => true, 'message' => 'อัปเดตแล้ว'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        case 'category:toggle': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');
            $pdo->prepare("UPDATE sys_doc_categories SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'อัปเดตสถานะแล้ว']);
            exit;
        }

        case 'category:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            // ห้ามลบถ้ามีเอกสารอ้างอิงอยู่
            $used = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_documents WHERE priority_id = ?");
            $used->execute([$id]);
            if ((int)$used->fetchColumn() > 0) {
                throw new RuntimeException('หมวดนี้ถูกใช้งานในเอกสารอยู่ — ปิดสถานะแทนการลบ');
            }
            $pdo->prepare("DELETE FROM sys_doc_categories WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            exit;
        }

        // ════════════ DOC TYPE: LIST ════════════
        case 'doctype:list': {
            edms_ensure_doc_types_schema($pdo);
            $rows = $pdo->query("SELECT t.*, (SELECT COUNT(*) FROM sys_doc_documents d WHERE d.doc_type = t.code) AS used_count
                                 FROM sys_doc_types t
                                 ORDER BY t.sort_order ASC, t.id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok' => true, 'doc_types' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ DOC TYPE: CREATE / UPDATE ════════════
        case 'doctype:create':
        case 'doctype:update': {
            edms_ensure_doc_types_schema($pdo);
            $id          = (int)($_POST['id'] ?? 0);
            $code        = trim((string)($_POST['code'] ?? ''));
            $name        = trim((string)($_POST['name'] ?? ''));
            $shortLabel  = trim((string)($_POST['short_label'] ?? ''));
            $description = trim((string)($_POST['description'] ?? '')) ?: null;
            $icon        = trim((string)($_POST['icon'] ?? '')) ?: null;
            $tone        = trim((string)($_POST['tone'] ?? ''));
            $sort        = (int)($_POST['sort_order'] ?? 0);
            $active      = (int)($_POST['is_active'] ?? 1) ? 1 : 0;

            if ($code === '' || $name === '') throw new RuntimeException('กรุณากรอก code และ name');
            if (!preg_match('/^[a-z0-9_]+$/i', $code)) {
                throw new RuntimeException('code ต้องเป็นอักษร a-z, 0-9, _ เท่านั้น (ไม่มีช่องว่าง/ภาษาไทย)');
            }
            $allowedTones = ['sky','emerald','violet','amber','rose','cyan','slate','teal','indigo','orange'];
            if ($tone !== '' && !in_array($tone, $allowedTones, true)) $tone = 'slate';
            if ($tone === '') $tone = 'slate';

            if ($action === 'doctype:create') {
                $exists = $pdo->prepare("SELECT id FROM sys_doc_types WHERE code = ?");
                $exists->execute([$code]);
                if ($exists->fetchColumn()) throw new RuntimeException('code นี้มีอยู่แล้ว');

                $stmt = $pdo->prepare("INSERT INTO sys_doc_types
                    (code, name, short_label, description, icon, tone, sort_order, is_active, is_system)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
                $stmt->execute([$code, $name, $shortLabel ?: null, $description, $icon, $tone, $sort, $active]);
                $newId = (int)$pdo->lastInsertId();
                echo json_encode(['ok' => true, 'message' => 'เพิ่มประเภทเอกสารแล้ว', 'id' => $newId]);
            } else {
                if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');
                $cur = $pdo->prepare("SELECT code, is_system FROM sys_doc_types WHERE id = ?");
                $cur->execute([$id]);
                $curRow = $cur->fetch(PDO::FETCH_ASSOC);
                if (!$curRow) throw new RuntimeException('ไม่พบรายการ');

                // ห้ามแก้ code ถ้าเป็น system type (จะกระทบ FK)
                if ((int)$curRow['is_system'] === 1 && $curRow['code'] !== $code) {
                    throw new RuntimeException('ประเภทมาตรฐานห้ามแก้ code (แก้ชื่อ/สี/icon ได้)');
                }
                // ถ้าเปลี่ยน code → ตรวจ collision และ update เอกสารด้วย
                if ($curRow['code'] !== $code) {
                    $col = $pdo->prepare("SELECT id FROM sys_doc_types WHERE code = ? AND id != ?");
                    $col->execute([$code, $id]);
                    if ($col->fetchColumn()) throw new RuntimeException('code นี้มีอยู่แล้ว');

                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("UPDATE sys_doc_documents SET doc_type = ? WHERE doc_type = ?")->execute([$code, $curRow['code']]);
                        $pdo->prepare("UPDATE sys_doc_counters  SET doc_type = ? WHERE doc_type = ?")->execute([$code, $curRow['code']]);
                        $pdo->prepare("UPDATE sys_doc_types SET code=?, name=?, short_label=?, description=?, icon=?, tone=?, sort_order=?, is_active=? WHERE id=?")
                            ->execute([$code, $name, $shortLabel ?: null, $description, $icon, $tone, $sort, $active, $id]);
                        $pdo->commit();
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                } else {
                    $pdo->prepare("UPDATE sys_doc_types SET name=?, short_label=?, description=?, icon=?, tone=?, sort_order=?, is_active=? WHERE id=?")
                        ->execute([$name, $shortLabel ?: null, $description, $icon, $tone, $sort, $active, $id]);
                }
                echo json_encode(['ok' => true, 'message' => 'อัปเดตแล้ว']);
            }
            exit;
        }

        case 'doctype:toggle': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');
            $pdo->prepare("UPDATE sys_doc_types SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'อัปเดตสถานะแล้ว']);
            exit;
        }

        case 'doctype:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $cur = $pdo->prepare("SELECT code, is_system FROM sys_doc_types WHERE id = ?");
            $cur->execute([$id]);
            $curRow = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$curRow) throw new RuntimeException('ไม่พบรายการ');

            if ((int)$curRow['is_system'] === 1) {
                throw new RuntimeException('ประเภทมาตรฐานห้ามลบ — ปิดสถานะแทน');
            }
            $used = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_documents WHERE doc_type = ?");
            $used->execute([$curRow['code']]);
            if ((int)$used->fetchColumn() > 0) {
                throw new RuntimeException('ประเภทนี้มีเอกสารอ้างอิงอยู่ — ปิดสถานะแทนการลบ');
            }
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM sys_doc_counters WHERE doc_type = ?")->execute([$curRow['code']]);
                $pdo->prepare("DELETE FROM sys_doc_types WHERE id = ?")->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            echo json_encode(['ok' => true, 'message' => 'ลบแล้ว']);
            exit;
        }

        // ════════════ ATTACHMENT: DELETE ════════════
        case 'attachment:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $sel = $pdo->prepare("SELECT doc_id, stored_path, uploaded_by, root_id, version_no, is_current
                FROM sys_doc_attachments WHERE id = ? LIMIT 1");
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('ไม่พบไฟล์');

            // Only the uploader or a superadmin may delete an attachment
            $isSuperadmin = ($_SESSION['admin_role'] ?? '') === 'superadmin';
            if (!$isSuperadmin && (int)$row['uploaded_by'] !== (int)$userId) {
                throw new RuntimeException('คุณไม่มีสิทธิ์ลบไฟล์ของผู้อื่น');
            }

            // If we're deleting the CURRENT version of a multi-version chain,
            // promote the previous version (max(version_no) < this) so the chain
            // doesn't end up with no current.
            $promotedTo = null;
            if ((int)$row['is_current'] === 1) {
                $rootId = $row['root_id'] !== null ? (int)$row['root_id'] : $id;
                $prev = $pdo->prepare("SELECT id FROM sys_doc_attachments
                    WHERE (id = ? OR root_id = ?)
                      AND id <> ?
                      AND version_no < ?
                    ORDER BY version_no DESC LIMIT 1");
                $prev->execute([$rootId, $rootId, $id, (int)$row['version_no']]);
                $prevId = (int)($prev->fetchColumn() ?: 0);
                if ($prevId > 0) {
                    $pdo->prepare("UPDATE sys_doc_attachments
                        SET is_current = 1, superseded_at = NULL WHERE id = ?")
                        ->execute([$prevId]);
                    $promotedTo = $prevId;
                }
            }

            $pdo->prepare("DELETE FROM sys_doc_attachments WHERE id = ?")->execute([$id]);

            // Realpath confinement — never unlink anything outside uploads/edms/
            // even if stored_path has been corrupted (DB tampering, legacy rows).
            $base      = edms_uploads_dir();
            $baseReal  = realpath($base) ?: '';
            $candidate = $base . '/' . ltrim((string)$row['stored_path'], '/');
            $pathReal  = realpath($candidate) ?: '';
            if ($pathReal !== '' && $baseReal !== ''
                && str_starts_with($pathReal, $baseReal . DIRECTORY_SEPARATOR)
                && is_file($pathReal)) {
                @unlink($pathReal);
            }

            edms_log($pdo, (int)$row['doc_id'], $userId, 'detach',
                ['attachment_id' => $id, 'promoted_previous' => $promotedTo]);

            echo json_encode([
                'ok' => true,
                'message' => $promotedTo
                    ? 'ลบเวอร์ชันแล้ว — เวอร์ชันก่อนหน้าถูกเลื่อนเป็นปัจจุบัน'
                    : 'ลบไฟล์แนบแล้ว',
                'promoted_previous' => $promotedTo,
            ]);
            exit;
        }

        // ════════════ ATTACHMENT: UPLOAD NEW VERSION ════════════
        // อัปโหลดไฟล์เป็นเวอร์ชันใหม่ของไฟล์ที่ระบุ — ตัวเก่าโดน supersede อัตโนมัติ
        case 'attachment:upload_version': {
            $parentId = (int)($_POST['parent_id'] ?? 0);
            if ($parentId <= 0) throw new RuntimeException('ระบุ parent_id ไม่ถูกต้อง');

            $sel = $pdo->prepare("SELECT doc_id, root_id, version_no, is_current, role
                FROM sys_doc_attachments WHERE id = ? LIMIT 1");
            $sel->execute([$parentId]);
            $parent = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$parent) throw new RuntimeException('ไม่พบไฟล์ต้นฉบับที่จะ override');

            $docId  = (int)$parent['doc_id'];
            $rootId = $parent['root_id'] !== null ? (int)$parent['root_id'] : $parentId;
            $parentRole = in_array($parent['role'] ?? 'supporting', ['primary', 'supporting'], true)
                ? $parent['role'] : 'supporting';

            if (empty($_FILES['file']['name'])) throw new RuntimeException('ไม่พบไฟล์');
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('อัปโหลดไม่สำเร็จ');

            $saved = edms_save_uploaded_file([
                'name'     => $_FILES['file']['name'],
                'type'     => $_FILES['file']['type'] ?? '',
                'tmp_name' => $_FILES['file']['tmp_name'],
                'error'    => $_FILES['file']['error'],
                'size'     => $_FILES['file']['size'] ?? 0,
            ], $docId);
            if (!$saved) throw new RuntimeException('ไฟล์ขนาดเกิน 20MB หรือนามสกุลไม่รองรับ');

            // Compute next version_no across the entire chain
            $vstmt = $pdo->prepare("SELECT COALESCE(MAX(version_no), 0) FROM sys_doc_attachments
                WHERE id = ? OR root_id = ?");
            $vstmt->execute([$rootId, $rootId]);
            $nextVersion = (int)$vstmt->fetchColumn() + 1;

            // Mark all current rows in this chain as superseded (defensive: there
            // should be exactly one, but a race could leave more than one)
            $pdo->prepare("UPDATE sys_doc_attachments
                SET is_current = 0, superseded_at = NOW()
                WHERE (id = ? OR root_id = ?) AND is_current = 1")
                ->execute([$rootId, $rootId]);

            $ins = $pdo->prepare("INSERT INTO sys_doc_attachments
                (doc_id, root_id, version_no, is_current, role,
                 file_name, stored_path, mime_type, file_size, sha1_hash, uploaded_by)
                VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $docId,
                $rootId,
                $nextVersion,
                $parentRole,
                $saved['file_name'],
                $saved['stored_path'],
                $saved['mime_type'],
                $saved['file_size'],
                $saved['sha1_hash'],
                $userId ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();

            edms_log($pdo, $docId, $userId, 'attach_version',
                ['root_id' => $rootId, 'version_no' => $nextVersion, 'new_id' => $newId, 'role' => $parentRole]);

            echo json_encode([
                'ok'        => true,
                'id'        => $newId,
                'root_id'   => $rootId,
                'version_no'=> $nextVersion,
                'role'      => $parentRole,
                'file_name' => $saved['file_name'],
                'message'   => 'อัปโหลดเวอร์ชันใหม่ (v' . $nextVersion . ') แล้ว',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ ATTACHMENT: SET ROLE (primary / supporting) ════════════
        // Promote a chain to primary (demoting any existing primary in same doc)
        // or unset primary back to supporting. Role is stored on every row of
        // the chain so version queries and visual grouping stay consistent.
        case 'attachment:set_role': {
            $id   = (int)($_POST['id'] ?? 0);
            $role = (string)($_POST['role'] ?? '');
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');
            if (!in_array($role, ['primary', 'supporting'], true)) {
                throw new RuntimeException('role ไม่ถูกต้อง');
            }

            $sel = $pdo->prepare("SELECT doc_id, root_id FROM sys_doc_attachments WHERE id = ? LIMIT 1");
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('ไม่พบไฟล์');
            $docId  = (int)$row['doc_id'];
            $rootId = $row['root_id'] !== null ? (int)$row['root_id'] : $id;

            $pdo->beginTransaction();
            try {
                // Demote any existing primary chain in this doc when promoting
                if ($role === 'primary') {
                    $pdo->prepare("UPDATE sys_doc_attachments
                        SET role = 'supporting'
                        WHERE doc_id = ? AND role = 'primary'
                          AND COALESCE(root_id, id) <> ?")
                        ->execute([$docId, $rootId]);
                }
                // Set the chosen chain's role on every row in its chain
                $pdo->prepare("UPDATE sys_doc_attachments
                    SET role = ?
                    WHERE id = ? OR root_id = ?")
                    ->execute([$role, $rootId, $rootId]);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            edms_log($pdo, $docId, $userId, 'set_role', ['root_id' => $rootId, 'role' => $role]);

            echo json_encode([
                'ok'      => true,
                'role'    => $role,
                'message' => $role === 'primary' ? 'ตั้งเป็นเอกสารหลักแล้ว' : 'ปลดออกจากเอกสารหลักแล้ว',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ════════════ ATTACHMENT: LIST VERSIONS ════════════
        // คืนค่าทุกเวอร์ชันใน chain เดียวกัน — เรียงเวอร์ชันล่าสุดก่อน
        case 'attachment:list_versions': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $sel = $pdo->prepare("SELECT id, root_id FROM sys_doc_attachments WHERE id = ? LIMIT 1");
            $sel->execute([$id]);
            $r = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new RuntimeException('ไม่พบไฟล์');
            $rootId = $r['root_id'] !== null ? (int)$r['root_id'] : (int)$r['id'];

            $stmt = $pdo->prepare("
                SELECT a.id, a.root_id, a.version_no, a.is_current, a.file_name,
                       a.file_size, a.mime_type, a.uploaded_at, a.uploaded_by,
                       a.superseded_at,
                       s.full_name AS uploader_name
                FROM sys_doc_attachments a
                LEFT JOIN sys_staff s ON s.id = a.uploaded_by
                WHERE a.id = ? OR a.root_id = ?
                ORDER BY a.version_no DESC, a.id DESC
            ");
            $stmt->execute([$rootId, $rootId]);
            echo json_encode([
                'ok' => true,
                'root_id'  => $rootId,
                'versions' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        default:
            throw new RuntimeException('Unknown entity:action — ' . htmlspecialchars("$entity:$action"));
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'ระบบขัดข้อง กรุณาลองอีกครั้ง'], JSON_UNESCAPED_UNICODE);
    exit;
}
