<?php
/**
 * portal/ajax_billing.php — Patient Billing AJAX
 *
 * Entities: service (catalog CRUD).
 * Future:   encounter, invoice, payment, ar_aging (Phase 1B–1D)
 *
 * Action pattern: entity:action  (e.g. service:list, service:create)
 *
 * Auth gate: same as finance — superadmin OR role=admin OR access_finance
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/patient_billing_helper.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole  = $_SESSION['admin_role'] ?? 'editor';
$isSuper    = ($adminRole === 'superadmin');
$canBilling = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_finance']);
if (!$canBilling) {
    echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้โมดูล Patient Billing']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$pdo       = db();
$adminId   = (int)($_SESSION['admin_id'] ?? 0);

$rawAction = (string)($_REQUEST['action'] ?? '');
[$entity, $verb] = array_pad(explode(':', $rawAction, 2), 2, '');

// Mutating actions require CSRF + POST
$mutating = !in_array($verb, ['list', 'get'], true);
if ($mutating) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'message' => 'ต้องใช้ POST']);
        exit;
    }
    validate_csrf_or_die();
}

pb_ensure_schema($pdo);

try {
    switch ($entity) {
        case 'service':
            handle_service($pdo, $verb, $adminId);
            break;
        default:
            echo json_encode(['ok' => false, 'message' => 'entity ไม่รู้จัก: ' . $entity]);
    }
} catch (Throwable $e) {
    error_log('[ajax_billing] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Service catalog
// ─────────────────────────────────────────────────────────────────────────────

function handle_service(PDO $pdo, string $verb, int $adminId): void
{
    switch ($verb) {
        case 'list': {
            $res = pb_list_services($pdo, [
                'q'        => $_REQUEST['q']        ?? '',
                'category' => $_REQUEST['category'] ?? '',
                'active'   => isset($_REQUEST['active']) ? (int)$_REQUEST['active'] : 1,
                'page'     => (int)($_REQUEST['page']     ?? 1),
                'per_page' => (int)($_REQUEST['per_page'] ?? 20),
                'sort'     => $_REQUEST['sort']     ?? 'sort_order',
                'dir'      => $_REQUEST['dir']      ?? 'asc',
            ]);
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'get': {
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("SELECT * FROM sys_billing_services WHERE id = :id LIMIT 1");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'message' => 'ไม่พบบริการ']); return; }
            echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'create': {
            $v = pb_validate_service_payload($_POST);
            if (!$v['ok']) {
                echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง', 'errors' => $v['errors']]);
                return;
            }
            $d = $v['data'];
            try {
                $st = $pdo->prepare("INSERT INTO sys_billing_services
                    (code, name, category, description, unit_price, unit_label,
                     is_taxable, is_active, sort_order, created_by)
                    VALUES (:code, :name, :category, :description, :unit_price, :unit_label,
                            :is_taxable, :is_active, :sort_order, :created_by)");
                $st->execute([
                    ':code'        => $d['code'],
                    ':name'        => $d['name'],
                    ':category'    => $d['category'],
                    ':description' => $d['description'],
                    ':unit_price'  => $d['unit_price'],
                    ':unit_label'  => $d['unit_label'],
                    ':is_taxable'  => $d['is_taxable'],
                    ':is_active'   => $d['is_active'],
                    ':sort_order'  => $d['sort_order'],
                    ':created_by'  => $adminId ?: null,
                ]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'เพิ่มบริการแล้ว']);
            } catch (PDOException $e) {
                if ($e->errorInfo[1] ?? 0 === 1062) {
                    echo json_encode(['ok' => false, 'message' => 'รหัสบริการซ้ำ — มีรหัส "' . $d['code'] . '" อยู่แล้ว']);
                } else {
                    throw $e;
                }
            }
            return;
        }

        case 'update': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }

            $v = pb_validate_service_payload($_POST);
            if (!$v['ok']) {
                echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง', 'errors' => $v['errors']]);
                return;
            }
            $d = $v['data'];
            try {
                $st = $pdo->prepare("UPDATE sys_billing_services SET
                    code = :code, name = :name, category = :category, description = :description,
                    unit_price = :unit_price, unit_label = :unit_label,
                    is_taxable = :is_taxable, is_active = :is_active, sort_order = :sort_order
                    WHERE id = :id");
                $st->execute([
                    ':id'          => $id,
                    ':code'        => $d['code'],
                    ':name'        => $d['name'],
                    ':category'    => $d['category'],
                    ':description' => $d['description'],
                    ':unit_price'  => $d['unit_price'],
                    ':unit_label'  => $d['unit_label'],
                    ':is_taxable'  => $d['is_taxable'],
                    ':is_active'   => $d['is_active'],
                    ':sort_order'  => $d['sort_order'],
                ]);
                echo json_encode(['ok' => true, 'message' => 'อัปเดตบริการแล้ว']);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    echo json_encode(['ok' => false, 'message' => 'รหัสบริการซ้ำ — มีรหัส "' . $d['code'] . '" อยู่แล้ว']);
                } else {
                    throw $e;
                }
            }
            return;
        }

        case 'toggle': {
            // Flip is_active without touching anything else
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $pdo->prepare("UPDATE sys_billing_services
                           SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id")
                ->execute([':id' => $id]);
            // Return the new state so the UI can update without reload
            $st = $pdo->prepare("SELECT is_active FROM sys_billing_services WHERE id = :id");
            $st->execute([':id' => $id]);
            $newState = (int)$st->fetchColumn();
            echo json_encode(['ok' => true, 'is_active' => $newState,
                              'message' => $newState ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว']);
            return;
        }

        case 'delete': {
            // Reject delete if the service is referenced in any encounter (FK safety net).
            // Better UX than letting the FK constraint blow up.
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }

            $refs = $pdo->prepare("SELECT COUNT(*) FROM sys_billing_encounter_items WHERE service_id = :id");
            $refs->execute([':id' => $id]);
            if ((int)$refs->fetchColumn() > 0) {
                echo json_encode([
                    'ok'      => false,
                    'message' => 'บริการนี้ถูกอ้างอิงในประวัติการเข้ารับบริการแล้ว ลบไม่ได้ — กดปิดใช้งานแทนได้',
                ]);
                return;
            }
            $pdo->prepare("DELETE FROM sys_billing_services WHERE id = :id")
                ->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'message' => 'ลบบริการแล้ว']);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'service action ไม่รู้จัก: ' . $verb]);
    }
}
