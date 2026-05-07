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

    $prefixes = [
        'incoming' => 'รับ',
        'outgoing' => 'ส่ง',
        'internal' => 'บันทึก',
        'circular' => 'เวียน',
    ];
    $prefix = $prefixes[$docType] ?? 'DOC';
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
            if (!in_array($docType, ['incoming', 'outgoing', 'internal', 'circular'], true)) {
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

        // ════════════ ATTACHMENT: DELETE ════════════
        case 'attachment:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('ระบุ id ไม่ถูกต้อง');

            $sel = $pdo->prepare("SELECT doc_id, stored_path FROM sys_doc_attachments WHERE id = ? LIMIT 1");
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('ไม่พบไฟล์');

            $pdo->prepare("DELETE FROM sys_doc_attachments WHERE id = ?")->execute([$id]);

            $path = edms_uploads_dir() . '/' . $row['stored_path'];
            if (is_file($path)) @unlink($path);

            edms_log($pdo, (int)$row['doc_id'], $userId, 'detach', ['attachment_id' => $id]);

            echo json_encode(['ok' => true, 'message' => 'ลบไฟล์แนบแล้ว']);
            exit;
        }

        default:
            throw new RuntimeException('Unknown entity:action — ' . htmlspecialchars("$entity:$action"));
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
