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

// aging:export bypasses the JSON header because it streams CSV
if ($entity === 'aging' && $verb === 'export') {
    handle_aging_export($pdo);
    exit;
}

try {
    switch ($entity) {
        case 'service':   handle_service($pdo, $verb, $adminId);   break;
        case 'encounter': handle_encounter($pdo, $verb, $adminId); break;
        case 'invoice':   handle_invoice($pdo, $verb, $adminId);   break;
        case 'payment':   handle_payment($pdo, $verb, $adminId);   break;
        case 'aging':     handle_aging($pdo, $verb);               break;
        case 'lookup':    handle_lookup($pdo, $verb);              break;
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

// ─────────────────────────────────────────────────────────────────────────────
// Lookups (typeahead for encounter form)
// ─────────────────────────────────────────────────────────────────────────────

function handle_lookup(PDO $pdo, string $verb): void
{
    $q = trim((string)($_REQUEST['q'] ?? ''));
    switch ($verb) {
        case 'patient':
            echo json_encode(['ok' => true, 'rows' => pb_lookup_patient($pdo, $q)],
                JSON_UNESCAPED_UNICODE);
            return;
        case 'provider':
            echo json_encode(['ok' => true, 'rows' => pb_lookup_provider($pdo, $q)],
                JSON_UNESCAPED_UNICODE);
            return;
        case 'service':
            // Active services only, name/code match — different from service:list
            // (no pagination, no inactive). Used by the encounter line-item picker.
            $like = '%' . $q . '%';
            $sql  = $q === ''
                ? "SELECT id, code, name, category, unit_price, unit_label
                   FROM sys_billing_services WHERE is_active = 1
                   ORDER BY sort_order ASC, name ASC LIMIT 20"
                : "SELECT id, code, name, category, unit_price, unit_label
                   FROM sys_billing_services
                   WHERE is_active = 1 AND (code LIKE :q OR name LIKE :q)
                   ORDER BY sort_order ASC, name ASC LIMIT 20";
            $st = $pdo->prepare($sql);
            if ($q !== '') $st->bindValue(':q', $like);
            $st->execute();
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)],
                JSON_UNESCAPED_UNICODE);
            return;
        default:
            echo json_encode(['ok' => false, 'message' => 'lookup verb ไม่รู้จัก: ' . $verb]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Encounter (visit) — list, get, save, finalize, cancel, delete
// ─────────────────────────────────────────────────────────────────────────────

function handle_encounter(PDO $pdo, string $verb, int $adminId): void
{
    switch ($verb) {
        case 'list': {
            $res = pb_list_encounters($pdo, [
                'q'          => $_REQUEST['q']          ?? '',
                'status'     => $_REQUEST['status']     ?? '',
                'date_from'  => $_REQUEST['date_from']  ?? '',
                'date_to'    => $_REQUEST['date_to']    ?? '',
                'patient_id' => (int)($_REQUEST['patient_id'] ?? 0),
                'page'       => (int)($_REQUEST['page']     ?? 1),
                'per_page'   => (int)($_REQUEST['per_page'] ?? 20),
            ]);
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'get': {
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $hdr = $pdo->prepare("
                SELECT e.*,
                       u.full_name AS patient_name, u.student_personnel_id AS patient_code,
                       u.phone_number AS patient_phone, u.citizen_id AS patient_citizen,
                       s.full_name AS provider_name
                FROM sys_billing_encounters e
                LEFT JOIN sys_users u ON u.id = e.patient_id
                LEFT JOIN sys_staff s ON s.id = e.provider_id
                WHERE e.id = :id
            ");
            $hdr->execute([':id' => $id]);
            $row = $hdr->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'message' => 'ไม่พบข้อมูล']); return; }

            $items = $pdo->prepare("
                SELECT id, service_id, service_code, service_name,
                       quantity, unit_price, discount, line_total, note
                FROM sys_billing_encounter_items
                WHERE encounter_id = :id
                ORDER BY id ASC
            ");
            $items->execute([':id' => $id]);
            $row['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'save': {
            // Single save endpoint handles both insert (id=0) and update (id>0).
            // Items array fully replaces existing items on update — cleaner than
            // tracking diffs from the client.
            $id = (int)($_POST['id'] ?? 0);

            $v = pb_validate_encounter_header($_POST);
            if (!$v['ok']) {
                echo json_encode(['ok' => false, 'message' => 'ข้อมูลส่วนหัวไม่ถูกต้อง', 'errors' => $v['errors']]);
                return;
            }
            $h = $v['data'];

            // Items can arrive as JSON string (preferred) or items[] form array
            $itemsRaw = $_POST['items'] ?? '[]';
            if (is_string($itemsRaw)) {
                $items = json_decode($itemsRaw, true) ?: [];
            } else {
                $items = (array)$itemsRaw;
            }

            if (empty($items)) {
                echo json_encode(['ok' => false, 'message' => 'ต้องมีรายการบริการอย่างน้อย 1 รายการ']);
                return;
            }

            // Validate items (lookup current price as snapshot if not provided)
            $cleanItems = [];
            $subtotal   = 0.0;
            foreach ($items as $i => $it) {
                $serviceId = (int)($it['service_id'] ?? 0);
                $qty       = max(0.01, (float)($it['quantity']   ?? 1));
                $price     = (float)($it['unit_price'] ?? 0);
                $lineDisc  = max(0.0, (float)($it['discount']  ?? 0));
                $note      = trim((string)($it['note'] ?? ''));

                if ($serviceId <= 0) {
                    echo json_encode(['ok' => false, 'message' => "รายการที่ " . ($i+1) . " ขาดข้อมูลบริการ"]);
                    return;
                }
                // Snapshot service info — service price/name might change in future
                $svc = $pdo->prepare("SELECT code, name, unit_price
                                       FROM sys_billing_services WHERE id = :id");
                $svc->execute([':id' => $serviceId]);
                $s = $svc->fetch(PDO::FETCH_ASSOC);
                if (!$s) {
                    echo json_encode(['ok' => false, 'message' => "รายการที่ " . ($i+1) . " — ไม่พบบริการ"]);
                    return;
                }
                if ($price <= 0) $price = (float)$s['unit_price'];

                $lineTotal = max(0, ($qty * $price) - $lineDisc);
                $subtotal += $lineTotal;

                $cleanItems[] = [
                    'service_id'   => $serviceId,
                    'service_code' => $s['code'],
                    'service_name' => $s['name'],
                    'quantity'     => $qty,
                    'unit_price'   => $price,
                    'discount'     => $lineDisc,
                    'line_total'   => $lineTotal,
                    'note'         => $note !== '' ? $note : null,
                ];
            }

            $total = max(0, $subtotal - $h['discount']) + $h['tax'];

            $pdo->beginTransaction();
            try {
                if ($id > 0) {
                    // UPDATE — only allowed when status='draft'
                    $cur = $pdo->prepare("SELECT status FROM sys_billing_encounters WHERE id = :id FOR UPDATE");
                    $cur->execute([':id' => $id]);
                    $curStatus = (string)$cur->fetchColumn();
                    if ($curStatus === '') {
                        throw new RuntimeException('ไม่พบ encounter ที่จะแก้ไข');
                    }
                    if ($curStatus !== 'draft') {
                        throw new RuntimeException("encounter สถานะ '{$curStatus}' แก้ไขไม่ได้ — มีเฉพาะ draft เท่านั้น");
                    }
                    $up = $pdo->prepare("UPDATE sys_billing_encounters SET
                        patient_id = :pid, visit_date = :vd, provider_id = :prov,
                        diagnosis = :diag, notes = :notes,
                        subtotal = :sub, discount = :disc, tax = :tax, total = :tot
                        WHERE id = :id");
                    $up->execute([
                        ':id'    => $id,
                        ':pid'   => $h['patient_id'],
                        ':vd'    => $h['visit_date'],
                        ':prov'  => $h['provider_id'],
                        ':diag'  => $h['diagnosis'],
                        ':notes' => $h['notes'],
                        ':sub'   => $subtotal,
                        ':disc'  => $h['discount'],
                        ':tax'   => $h['tax'],
                        ':tot'   => $total,
                    ]);
                    // Replace items (delete + insert all)
                    $pdo->prepare("DELETE FROM sys_billing_encounter_items WHERE encounter_id = :id")
                        ->execute([':id' => $id]);
                } else {
                    // INSERT — generate encounter_no with retry on unique collision
                    $maxRetry = 3;
                    for ($try = 0; $try < $maxRetry; $try++) {
                        $no = pb_next_encounter_no($pdo);
                        try {
                            $ins = $pdo->prepare("INSERT INTO sys_billing_encounters
                                (encounter_no, patient_id, visit_date, provider_id,
                                 diagnosis, notes, status, subtotal, discount, tax, total, created_by)
                                VALUES
                                (:no, :pid, :vd, :prov, :diag, :notes, 'draft',
                                 :sub, :disc, :tax, :tot, :by)");
                            $ins->execute([
                                ':no'    => $no,
                                ':pid'   => $h['patient_id'],
                                ':vd'    => $h['visit_date'],
                                ':prov'  => $h['provider_id'],
                                ':diag'  => $h['diagnosis'],
                                ':notes' => $h['notes'],
                                ':sub'   => $subtotal,
                                ':disc'  => $h['discount'],
                                ':tax'   => $h['tax'],
                                ':tot'   => $total,
                                ':by'    => $adminId ?: null,
                            ]);
                            $id = (int)$pdo->lastInsertId();
                            break;
                        } catch (PDOException $e) {
                            if (($e->errorInfo[1] ?? 0) === 1062 && $try < $maxRetry - 1) {
                                continue;  // collision — regenerate and retry
                            }
                            throw $e;
                        }
                    }
                }

                // Insert items
                $insItem = $pdo->prepare("INSERT INTO sys_billing_encounter_items
                    (encounter_id, service_id, service_code, service_name,
                     quantity, unit_price, discount, line_total, note)
                    VALUES
                    (:enc, :sid, :code, :sname, :qty, :price, :disc, :lt, :note)");
                foreach ($cleanItems as $ci) {
                    $insItem->execute([
                        ':enc'   => $id,
                        ':sid'   => $ci['service_id'],
                        ':code'  => $ci['service_code'],
                        ':sname' => $ci['service_name'],
                        ':qty'   => $ci['quantity'],
                        ':price' => $ci['unit_price'],
                        ':disc'  => $ci['discount'],
                        ':lt'    => $ci['line_total'],
                        ':note'  => $ci['note'],
                    ]);
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'id' => $id,
                                  'message' => 'บันทึก encounter แล้ว']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            }
            return;
        }

        case 'finalize': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("UPDATE sys_billing_encounters
                                 SET status = 'finalized', finalized_at = NOW()
                                 WHERE id = :id AND status = 'draft'");
            $st->execute([':id' => $id]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'ปิด encounter ไม่ได้ — สถานะไม่ใช่ draft แล้ว']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'ปิด encounter เรียบร้อย พร้อมออกใบแจ้งหนี้']);
            return;
        }

        case 'cancel': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            // Can cancel anything that isn't already invoiced (refund flow is different)
            $st = $pdo->prepare("UPDATE sys_billing_encounters
                                 SET status = 'cancelled'
                                 WHERE id = :id AND status IN ('draft','finalized')");
            $st->execute([':id' => $id]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'ยกเลิกไม่ได้ — อาจถูกออกใบแจ้งหนี้แล้ว']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'ยกเลิก encounter แล้ว']);
            return;
        }

        case 'delete': {
            // Only drafts can be deleted. Others get cancelled.
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("DELETE FROM sys_billing_encounters
                                 WHERE id = :id AND status = 'draft'");
            $st->execute([':id' => $id]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'ลบไม่ได้ — มีเฉพาะ draft เท่านั้นที่ลบได้']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'ลบ encounter แล้ว']);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'encounter action ไม่รู้จัก: ' . $verb]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Invoice — list, get, create_from_encounter, void
// ─────────────────────────────────────────────────────────────────────────────

function handle_invoice(PDO $pdo, string $verb, int $adminId): void
{
    switch ($verb) {
        case 'list': {
            $res = pb_list_invoices($pdo, [
                'q'          => $_REQUEST['q']          ?? '',
                'status'     => $_REQUEST['status']     ?? '',
                'payer_type' => $_REQUEST['payer_type'] ?? '',
                'date_from'  => $_REQUEST['date_from']  ?? '',
                'date_to'    => $_REQUEST['date_to']    ?? '',
                'patient_id' => (int)($_REQUEST['patient_id'] ?? 0),
                'page'       => (int)($_REQUEST['page']     ?? 1),
                'per_page'   => (int)($_REQUEST['per_page'] ?? 20),
            ]);
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'get': {
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $inv = pb_get_invoice_full($pdo, $id);
            if (!$inv) { echo json_encode(['ok' => false, 'message' => 'ไม่พบใบแจ้งหนี้']); return; }
            echo json_encode(['ok' => true, 'row' => $inv], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'create_from_encounter': {
            $encId = (int)($_POST['encounter_id'] ?? 0);
            if ($encId <= 0) { echo json_encode(['ok' => false, 'message' => 'encounter_id ไม่ถูกต้อง']); return; }
            $r = pb_generate_invoice_from_encounter($pdo, $encId, [
                'payer_type' => $_POST['payer_type'] ?? 'patient',
                'payer_id'   => $_POST['payer_id']   ?? null,
                'due_date'   => $_POST['due_date']   ?? '',
                'notes'      => $_POST['notes']      ?? '',
            ], $adminId);
            if ($r['ok']) {
                echo json_encode(['ok' => true,
                                  'invoice_id' => $r['invoice_id'],
                                  'invoice_no' => $r['invoice_no'],
                                  'message'    => 'ออกใบแจ้งหนี้ ' . $r['invoice_no'] . ' แล้ว'],
                    JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['ok' => false,
                                  'message' => $r['error'],
                                  'existing_invoice_id' => $r['existing_invoice_id'] ?? null],
                    JSON_UNESCAPED_UNICODE);
            }
            return;
        }

        case 'void': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            // Refuse to void if any payment recorded (Phase 1D will add refund flow)
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM sys_billing_payments WHERE invoice_id = :id");
            $cnt->execute([':id' => $id]);
            if ((int)$cnt->fetchColumn() > 0) {
                echo json_encode(['ok' => false,
                    'message' => 'มีการชำระเงินบางส่วนแล้ว — ยกเลิกใบแจ้งหนี้ไม่ได้ (ต้องคืนเงินก่อน)']);
                return;
            }
            $st = $pdo->prepare("UPDATE sys_billing_invoices
                                 SET status = 'void' WHERE id = :id AND status != 'void'");
            $st->execute([':id' => $id]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'ใบแจ้งหนี้ถูกยกเลิกอยู่แล้ว']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'ยกเลิกใบแจ้งหนี้แล้ว']);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'invoice action ไม่รู้จัก: ' . $verb]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Payment — list (per invoice), create (with Cash Book sync)
// ─────────────────────────────────────────────────────────────────────────────

function handle_payment(PDO $pdo, string $verb, int $adminId): void
{
    $adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'system');

    switch ($verb) {
        case 'create': {
            $invoiceId = (int)($_POST['invoice_id'] ?? 0);
            if ($invoiceId <= 0) { echo json_encode(['ok' => false, 'message' => 'invoice_id ไม่ถูกต้อง']); return; }
            $r = pb_record_payment($pdo, $invoiceId, [
                'amount'       => $_POST['amount']       ?? 0,
                'method'       => $_POST['method']       ?? 'cash',
                'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
                'reference'    => $_POST['reference']    ?? '',
                'note'         => $_POST['note']         ?? '',
            ], $adminId, $adminName);
            if ($r['ok']) {
                echo json_encode(['ok' => true,
                                  'payment_id'     => $r['payment_id'],
                                  'finance_txn_id' => $r['finance_txn_id'],
                                  'message' => 'รับชำระแล้ว · บันทึกเข้า Cash Book อัตโนมัติ'],
                    JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['ok' => false, 'message' => $r['error']],
                    JSON_UNESCAPED_UNICODE);
            }
            return;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'payment action ไม่รู้จัก: ' . $verb]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AR Aging — buckets + drill-down + CSV export
// ─────────────────────────────────────────────────────────────────────────────

function handle_aging(PDO $pdo, string $verb): void
{
    $payerType = trim((string)($_REQUEST['payer_type'] ?? '')) ?: null;

    switch ($verb) {
        case 'summary': {
            $sum = pb_ar_aging_summary($pdo, $payerType);
            $top = pb_ar_aging_top_patients($pdo, 10, $payerType);
            echo json_encode([
                'ok'           => true,
                'buckets'      => $sum['buckets'],
                'summary'      => $sum['summary'],
                'top_patients' => $top,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'detail': {
            $bucket = (string)($_REQUEST['bucket'] ?? 'all');
            $rows = pb_ar_aging_detail($pdo, $bucket, $payerType, 200);
            echo json_encode(['ok' => true, 'bucket' => $bucket, 'rows' => $rows],
                JSON_UNESCAPED_UNICODE);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'message' => 'aging action ไม่รู้จัก: ' . $verb]);
    }
}

function handle_aging_export(PDO $pdo): void
{
    $payerType = trim((string)($_REQUEST['payer_type'] ?? '')) ?: null;
    $bucket    = (string)($_REQUEST['bucket'] ?? 'all');

    $rows = pb_ar_aging_detail($pdo, $bucket, $payerType, 10000);

    $fname = sprintf('ar_aging_%s_%s.csv',
        $bucket === 'all' ? 'all' : str_replace('+', 'plus', $bucket),
        date('Ymd_His'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");  // BOM for Excel UTF-8

    fputcsv($out, ['รายงานลูกหนี้คงค้าง (AR Aging)']);
    fputcsv($out, ['ช่วงอายุ', $bucket]);
    fputcsv($out, ['ประเภทผู้ชำระ', $payerType ?: 'ทุกประเภท']);
    fputcsv($out, ['ออก ณ', date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    fputcsv($out, [
        'ลำดับ', 'เลขที่ใบ', 'รหัสผู้ป่วย', 'ชื่อผู้ป่วย', 'เบอร์โทร',
        'ออก', 'ครบกำหนด', 'อายุ (วัน)', 'ยอดสุทธิ', 'ชำระแล้ว', 'คงค้าง',
        'ผู้ชำระ', 'สถานะ'
    ]);

    $payerLabels = PB_PAYER_TYPES;
    $statusLabels = PB_INVOICE_STATUSES;
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['invoice_no'],
            $r['patient_code'] ?? '',
            $r['patient_name'] ?? '',
            $r['patient_phone'] ?? '',
            $r['issue_date'],
            $r['due_date'] ?: '—',
            (int)$r['age_days'],
            number_format((float)$r['total'], 2, '.', ''),
            number_format((float)$r['paid_amount'], 2, '.', ''),
            number_format((float)$r['balance'], 2, '.', ''),
            $payerLabels[$r['payer_type']] ?? $r['payer_type'],
            $statusLabels[$r['status']] ?? $r['status'],
        ]);
    }

    // Footer
    fputcsv($out, []);
    fputcsv($out, ['รวม', '', '', '', '', '', '', '',
        number_format(array_sum(array_column($rows, 'total')), 2, '.', ''),
        number_format(array_sum(array_column($rows, 'paid_amount')), 2, '.', ''),
        number_format(array_sum(array_column($rows, 'balance')), 2, '.', ''),
        '', '']);

    fclose($out);
}
