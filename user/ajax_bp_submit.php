<?php
/**
 * user/ajax_bp_submit.php
 *
 * Patient self-service BP tracker AJAX.
 * Gates on LINE session — uses sys_users record linked to line_user_id.
 *
 * Actions:
 *   save     — create or update one of THIS user's self-records
 *   list     — paginated readings (both staff + self) for THIS user
 *   summary  — stats + trend points (same shape as admin summary)
 *   delete   — only allowed on source='self' rows owned by THIS user
 *
 * Staff records (source='staff') are visible read-only — patient can see
 * them in their trend but cannot edit or delete them.
 */
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/vitals_helper.php';

header('Content-Type: application/json; charset=utf-8');

$lineUserId = (string)($_SESSION['line_user_id'] ?? '');
if ($lineUserId === '') {
    echo json_encode(['ok' => false, 'message' => 'กรุณาเข้าสู่ระบบด้วย LINE ก่อน', 'need_login' => true]);
    exit;
}

$pdo = db();
vitals_bp_ensure_schema($pdo);

// Resolve sys_users.id from line_user_id (supports both line_user_id +
// line_user_id_new from the channel-migration in migrate_line_user_id_new).
$stu = $pdo->prepare("SELECT id, full_name FROM sys_users
                      WHERE line_user_id = :lid OR line_user_id_new = :lid2
                      LIMIT 1");
$stu->execute([':lid' => $lineUserId, ':lid2' => $lineUserId]);
$user = $stu->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo json_encode(['ok' => false, 'message' => 'ไม่พบโปรไฟล์ของคุณในระบบ', 'need_profile' => true]);
    exit;
}
$patientId = (int)$user['id'];
$patientName = (string)($user['full_name'] ?? '');

$action = (string)($_REQUEST['action'] ?? '');

try {
    switch ($action) {
        case 'list': {
            $page    = max(1, (int)($_REQUEST['page']     ?? 1));
            $perPage = max(1, min(50, (int)($_REQUEST['per_page'] ?? 20)));

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_vitals_bp WHERE patient_id = :id");
            $cnt->execute([':id' => $patientId]);
            $total = (int)$cnt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $st = $pdo->prepare("SELECT id, systolic, diastolic, pulse_rate, measured_at,
                                        position, arm, notes, classification, source,
                                        recorded_by_name
                                 FROM sys_vitals_bp WHERE patient_id = :id
                                 ORDER BY measured_at DESC, id DESC
                                 LIMIT :lim OFFSET :off");
            $st->bindValue(':id',  $patientId, PDO::PARAM_INT);
            $st->bindValue(':lim', $perPage,   PDO::PARAM_INT);
            $st->bindValue(':off', $offset,    PDO::PARAM_INT);
            $st->execute();
            echo json_encode([
                'ok'       => true,
                'rows'     => $st->fetchAll(PDO::FETCH_ASSOC),
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => max(1, (int)ceil($total / $perPage)),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'summary': {
            $sum = vitals_bp_patient_summary($pdo, $patientId, 60);
            if (!$sum) { echo json_encode(['ok' => false, 'message' => 'ไม่พบข้อมูล']); return; }
            echo json_encode(['ok' => true] + $sum, JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'save': {
            // Force patient_id to THIS user — never trust client-supplied value
            $_POST['patient_id'] = $patientId;

            // Default measured_at to now if not supplied (user-friendly)
            if (empty($_POST['measured_at'])) {
                $_POST['measured_at'] = date('Y-m-d H:i:00');
            }

            $v = vitals_bp_validate($_POST);
            if (!$v['ok']) {
                echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง', 'errors' => $v['errors']]);
                return;
            }
            $d = $v['data'];

            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // Update only own self-records — verify ownership inside WHERE
                $own = $pdo->prepare("SELECT id FROM sys_vitals_bp
                                      WHERE id = :id AND patient_id = :pid AND source = 'self'");
                $own->execute([':id' => $id, ':pid' => $patientId]);
                if (!$own->fetchColumn()) {
                    echo json_encode(['ok' => false, 'message' => 'แก้ไขรายการนี้ไม่ได้']);
                    return;
                }
                $up = $pdo->prepare("UPDATE sys_vitals_bp SET
                    systolic = :sbp, diastolic = :dbp, pulse_rate = :pulse,
                    measured_at = :mat, position = :pos, arm = :arm, notes = :notes,
                    classification = :cls
                    WHERE id = :id AND patient_id = :pid AND source = 'self'");
                $up->execute([
                    ':id'    => $id, ':pid' => $patientId,
                    ':sbp'   => $d['systolic'], ':dbp' => $d['diastolic'], ':pulse' => $d['pulse_rate'],
                    ':mat'   => $d['measured_at'], ':pos' => $d['position'], ':arm' => $d['arm'],
                    ':notes' => $d['notes'], ':cls' => $d['classification'],
                ]);
                echo json_encode(['ok' => true, 'id' => $id, 'classification' => $d['classification'],
                                  'message' => 'อัปเดตแล้ว'], JSON_UNESCAPED_UNICODE);
            } else {
                $ins = $pdo->prepare("INSERT INTO sys_vitals_bp
                    (patient_id, systolic, diastolic, pulse_rate, measured_at,
                     position, arm, notes, classification, source, recorded_by, recorded_by_name)
                    VALUES
                    (:pid, :sbp, :dbp, :pulse, :mat, :pos, :arm, :notes, :cls, 'self', NULL, :byn)");
                $ins->execute([
                    ':pid'   => $patientId,
                    ':sbp'   => $d['systolic'], ':dbp' => $d['diastolic'], ':pulse' => $d['pulse_rate'],
                    ':mat'   => $d['measured_at'], ':pos' => $d['position'], ':arm' => $d['arm'],
                    ':notes' => $d['notes'], ':cls' => $d['classification'],
                    ':byn'   => $patientName,
                ]);
                echo json_encode([
                    'ok' => true, 'id' => (int)$pdo->lastInsertId(),
                    'classification' => $d['classification'],
                    'message' => 'บันทึกแล้ว',
                ], JSON_UNESCAPED_UNICODE);
            }
            return;
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("DELETE FROM sys_vitals_bp
                                 WHERE id = :id AND patient_id = :pid AND source = 'self'");
            $st->execute([':id' => $id, ':pid' => $patientId]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'ลบไม่ได้ — เป็นบันทึกของเจ้าหน้าที่หรือไม่ใช่ของคุณ']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'ลบรายการแล้ว']);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'action ไม่รู้จัก: ' . $action]);
    }
} catch (Throwable $e) {
    error_log('[ajax_bp_submit] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
