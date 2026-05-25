<?php
/**
 * portal/ajax_vitals.php — Blood pressure logbook AJAX
 *
 * Entities: bp (CRUD + summary), lookup (patient typeahead)
 *
 * Auth gate: superadmin OR admin OR editor (any logged-in clinical staff).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/vitals_helper.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper   = ($adminRole === 'superadmin');
$canVitals = $isSuper || in_array($adminRole, ['admin', 'editor'], true);
if (!$canVitals) {
    echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้สมุดความดัน']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$pdo       = db();
$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$adminName = (string)($_SESSION['admin_full_name']
                ?? $_SESSION['admin_username']
                ?? $_SESSION['full_name']
                ?? 'system');

$rawAction = (string)($_REQUEST['action'] ?? '');
[$entity, $verb] = array_pad(explode(':', $rawAction, 2), 2, '');

// Read-only verbs/entities skip CSRF + POST requirement
$readOnlyVerbs    = ['list', 'get', 'summary'];
$readOnlyEntities = ['lookup'];
$mutating = !in_array($verb, $readOnlyVerbs, true)
         && !in_array($entity, $readOnlyEntities, true);
if ($mutating) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'message' => 'ต้องใช้ POST']);
        exit;
    }
    validate_csrf_or_die();
}

vitals_bp_ensure_schema($pdo);

try {
    switch ($entity) {
        case 'bp':     handle_bp($pdo, $verb, $adminId, $adminName); break;
        case 'lookup': handle_lookup_patient($pdo, $verb);            break;
        default:
            echo json_encode(['ok' => false, 'message' => 'entity ไม่รู้จัก: ' . $entity]);
    }
} catch (Throwable $e) {
    error_log('[ajax_vitals] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}

function handle_bp(PDO $pdo, string $verb, int $adminId, string $adminName): void
{
    switch ($verb) {
        case 'list': {
            $res = vitals_bp_list($pdo, [
                'q'              => $_REQUEST['q']              ?? '',
                'patient_id'     => (int)($_REQUEST['patient_id'] ?? 0),
                'classification' => $_REQUEST['classification'] ?? '',
                'date_from'      => $_REQUEST['date_from']      ?? '',
                'date_to'        => $_REQUEST['date_to']        ?? '',
                'page'           => (int)($_REQUEST['page']     ?? 1),
                'per_page'       => (int)($_REQUEST['per_page'] ?? 20),
            ]);
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'get': {
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("SELECT b.*, u.full_name AS patient_name,
                                        u.student_personnel_id AS patient_code
                                 FROM sys_vitals_bp b
                                 LEFT JOIN sys_users u ON u.id = b.patient_id
                                 WHERE b.id = :id");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'message' => 'ไม่พบรายการ']); return; }
            echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'summary': {
            $patientId = (int)($_REQUEST['patient_id'] ?? 0);
            if ($patientId <= 0) { echo json_encode(['ok' => false, 'message' => 'patient_id ไม่ถูกต้อง']); return; }
            $sum = vitals_bp_patient_summary($pdo, $patientId, 60);
            if (!$sum) { echo json_encode(['ok' => false, 'message' => 'ไม่พบผู้ป่วย']); return; }
            echo json_encode(['ok' => true] + $sum, JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'save': {
            $id = (int)($_POST['id'] ?? 0);
            $v = vitals_bp_validate($_POST);
            if (!$v['ok']) {
                echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง', 'errors' => $v['errors']]);
                return;
            }
            $d = $v['data'];
            if ($id > 0) {
                $st = $pdo->prepare("UPDATE sys_vitals_bp SET
                    patient_id = :pid, systolic = :sbp, diastolic = :dbp, pulse_rate = :pulse,
                    measured_at = :mat, position = :pos, arm = :arm, notes = :notes,
                    classification = :cls
                    WHERE id = :id");
                $st->execute([
                    ':id'   => $id,
                    ':pid'  => $d['patient_id'], ':sbp' => $d['systolic'], ':dbp' => $d['diastolic'],
                    ':pulse' => $d['pulse_rate'],
                    ':mat'  => $d['measured_at'], ':pos' => $d['position'], ':arm' => $d['arm'],
                    ':notes' => $d['notes'], ':cls' => $d['classification'],
                ]);
                echo json_encode(['ok' => true, 'id' => $id, 'classification' => $d['classification'],
                                  'message' => 'อัปเดตแล้ว'], JSON_UNESCAPED_UNICODE);
            } else {
                $st = $pdo->prepare("INSERT INTO sys_vitals_bp
                    (patient_id, systolic, diastolic, pulse_rate, measured_at, position, arm,
                     notes, classification, recorded_by, recorded_by_name)
                    VALUES
                    (:pid, :sbp, :dbp, :pulse, :mat, :pos, :arm, :notes, :cls, :by, :byn)");
                $st->execute([
                    ':pid'  => $d['patient_id'], ':sbp' => $d['systolic'], ':dbp' => $d['diastolic'],
                    ':pulse' => $d['pulse_rate'],
                    ':mat'  => $d['measured_at'], ':pos' => $d['position'], ':arm' => $d['arm'],
                    ':notes' => $d['notes'], ':cls' => $d['classification'],
                    ':by'   => $adminId ?: null, ':byn' => $adminName,
                ]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(),
                                  'classification' => $d['classification'],
                                  'message' => 'บันทึกแล้ว'], JSON_UNESCAPED_UNICODE);
            }
            return;
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("DELETE FROM sys_vitals_bp WHERE id = :id");
            $st->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'message' => 'ลบรายการแล้ว']);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'bp action ไม่รู้จัก: ' . $verb]);
    }
}

function handle_lookup_patient(PDO $pdo, string $verb): void
{
    if ($verb !== 'patient') {
        echo json_encode(['ok' => false, 'message' => 'lookup verb ไม่รู้จัก: ' . $verb]);
        return;
    }
    $q = trim((string)($_REQUEST['q'] ?? ''));
    if ($q === '') {
        echo json_encode(['ok' => true, 'rows' => []]);
        return;
    }
    $like = '%' . $q . '%';
    $st = $pdo->prepare("
        SELECT id, full_name, student_personnel_id, phone_number, citizen_id, status
        FROM sys_users
        WHERE full_name LIKE :q
           OR student_personnel_id LIKE :q
           OR phone_number LIKE :q
           OR citizen_id  LIKE :q
        ORDER BY
          CASE WHEN student_personnel_id = :exact OR citizen_id = :exact THEN 0 ELSE 1 END,
          full_name ASC
        LIMIT 15
    ");
    $st->execute([':q' => $like, ':exact' => $q]);
    echo json_encode(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)],
        JSON_UNESCAPED_UNICODE);
}
