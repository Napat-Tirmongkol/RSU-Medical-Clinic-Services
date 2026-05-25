<?php
/**
 * portal/ajax_payroll.php — Thai Payroll AJAX
 *
 * Entities: employee, period, entry, lookup
 * Action pattern: entity:action  (e.g. employee:list, period:create)
 *
 * Auth gate: superadmin OR role=admin OR access_finance OR access_payroll
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/payroll_helper.php';

$adminRole  = $_SESSION['admin_role'] ?? 'editor';
$isSuper    = ($adminRole === 'superadmin');
$canPayroll = $isSuper || ($adminRole === 'admin')
            || !empty($_SESSION['access_finance'])
            || !empty($_SESSION['access_payroll']);

// Early-return for CSV exports (no JSON header)
$rawAction = (string)($_REQUEST['action'] ?? '');
[$entity, $verb] = array_pad(explode(':', $rawAction, 2), 2, '');

if ($entity === 'report' && in_array($verb, ['pnd1_csv','sso_csv','bank_csv'], true)) {
    if (!$canPayroll) { http_response_code(403); exit('Forbidden'); }
    require __DIR__ . '/../includes/payroll_helper.php';  // ensure loaded
    handle_report_export(db(), $verb);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if (!$canPayroll) {
    echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้โมดูล Payroll']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$pdo     = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// Read-only entities/verbs are exempt from CSRF + POST requirement
$readOnlyVerbs    = ['list', 'get'];
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

pr_ensure_schema($pdo);

try {
    switch ($entity) {
        case 'employee': handle_employee($pdo, $verb, $adminId); break;
        case 'period':   handle_period($pdo, $verb, $adminId);   break;
        case 'entry':    handle_entry($pdo, $verb, $adminId);    break;
        case 'lookup':   handle_lookup_pr($pdo, $verb);          break;
        default:
            echo json_encode(['ok' => false, 'message' => 'entity ไม่รู้จัก: ' . $entity]);
    }
} catch (Throwable $e) {
    error_log('[ajax_payroll] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Employee
// ─────────────────────────────────────────────────────────────────────────────

function handle_employee(PDO $pdo, string $verb, int $adminId): void
{
    switch ($verb) {
        case 'list': {
            $res = pr_list_employees($pdo, [
                'q'        => $_REQUEST['q']        ?? '',
                'active'   => isset($_REQUEST['active']) ? (int)$_REQUEST['active'] : 1,
                'page'     => (int)($_REQUEST['page']     ?? 1),
                'per_page' => (int)($_REQUEST['per_page'] ?? 20),
            ]);
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
            return;
        }
        case 'get': {
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("SELECT e.*, s.full_name, s.job_title, s.official_title
                                 FROM sys_payroll_employees e
                                 JOIN sys_staff s ON s.id = e.staff_id
                                 WHERE e.id = :id");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'message' => 'ไม่พบพนักงาน']); return; }
            echo json_encode(['ok' => true, 'row' => $row], JSON_UNESCAPED_UNICODE);
            return;
        }
        case 'save': {
            // Insert or update via single endpoint (id=0 = insert)
            $id = (int)($_POST['id'] ?? 0);
            $v = pr_validate_employee($_POST);
            if (!$v['ok']) {
                echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง', 'errors' => $v['errors']]);
                return;
            }
            $d = $v['data'];
            try {
                if ($id > 0) {
                    $st = $pdo->prepare("UPDATE sys_payroll_employees SET
                        employee_no = :eno, employment_type = :et, base_salary = :base,
                        monthly_allowance = :allow, ot_rate = :otr,
                        bank_name = :bn, bank_account = :ba, tax_id = :tax, sso_no = :sso,
                        is_in_sso = :iss, is_in_pf = :ipf, pf_rate_pct = :pfr,
                        personal_allowance = :pa, spouse_allowance = :sa, children_count = :cc,
                        hire_date = :hd, terminate_date = :td, is_active = :act, notes = :notes
                        WHERE id = :id");
                    $st->execute([
                        ':id' => $id,
                        ':eno' => $d['employee_no'], ':et' => $d['employment_type'], ':base' => $d['base_salary'],
                        ':allow' => $d['monthly_allowance'], ':otr' => $d['ot_rate'],
                        ':bn' => $d['bank_name'], ':ba' => $d['bank_account'], ':tax' => $d['tax_id'], ':sso' => $d['sso_no'],
                        ':iss' => $d['is_in_sso'], ':ipf' => $d['is_in_pf'], ':pfr' => $d['pf_rate_pct'],
                        ':pa'  => $d['personal_allowance'], ':sa' => $d['spouse_allowance'], ':cc' => $d['children_count'],
                        ':hd'  => $d['hire_date'], ':td' => $d['terminate_date'], ':act' => $d['is_active'], ':notes' => $d['notes'],
                    ]);
                    echo json_encode(['ok' => true, 'id' => $id, 'message' => 'อัปเดตพนักงานแล้ว']);
                } else {
                    $st = $pdo->prepare("INSERT INTO sys_payroll_employees
                        (staff_id, employee_no, employment_type, base_salary, monthly_allowance, ot_rate,
                         bank_name, bank_account, tax_id, sso_no, is_in_sso, is_in_pf, pf_rate_pct,
                         personal_allowance, spouse_allowance, children_count,
                         hire_date, terminate_date, is_active, notes)
                        VALUES
                        (:sid, :eno, :et, :base, :allow, :otr,
                         :bn, :ba, :tax, :sso, :iss, :ipf, :pfr,
                         :pa, :sa, :cc, :hd, :td, :act, :notes)");
                    $st->execute([
                        ':sid' => $d['staff_id'],
                        ':eno' => $d['employee_no'], ':et' => $d['employment_type'], ':base' => $d['base_salary'],
                        ':allow' => $d['monthly_allowance'], ':otr' => $d['ot_rate'],
                        ':bn' => $d['bank_name'], ':ba' => $d['bank_account'], ':tax' => $d['tax_id'], ':sso' => $d['sso_no'],
                        ':iss' => $d['is_in_sso'], ':ipf' => $d['is_in_pf'], ':pfr' => $d['pf_rate_pct'],
                        ':pa'  => $d['personal_allowance'], ':sa' => $d['spouse_allowance'], ':cc' => $d['children_count'],
                        ':hd'  => $d['hire_date'], ':td' => $d['terminate_date'], ':act' => $d['is_active'], ':notes' => $d['notes'],
                    ]);
                    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'เพิ่มพนักงานแล้ว']);
                }
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    echo json_encode(['ok' => false, 'message' => 'พนักงานนี้มีโปรไฟล์ payroll อยู่แล้ว — เลือกแก้ไขแทน']);
                } else { throw $e; }
            }
            return;
        }
        case 'toggle': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $pdo->prepare("UPDATE sys_payroll_employees
                           SET is_active = IF(is_active=1, 0, 1) WHERE id = :id")
                ->execute([':id' => $id]);
            $st = $pdo->prepare("SELECT is_active FROM sys_payroll_employees WHERE id = :id");
            $st->execute([':id' => $id]);
            $newState = (int)$st->fetchColumn();
            echo json_encode(['ok' => true, 'is_active' => $newState,
                              'message' => $newState ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว']);
            return;
        }
        default:
            echo json_encode(['ok' => false, 'message' => 'employee action ไม่รู้จัก: ' . $verb]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Period
// ─────────────────────────────────────────────────────────────────────────────

function handle_period(PDO $pdo, string $verb, int $adminId): void
{
    switch ($verb) {
        case 'list': {
            $res = pr_list_periods($pdo, [
                'page'     => (int)($_REQUEST['page']     ?? 1),
                'per_page' => (int)($_REQUEST['per_page'] ?? 20),
            ]);
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
            return;
        }
        case 'get': {
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $p = pr_get_period_full($pdo, $id);
            if (!$p) { echo json_encode(['ok' => false, 'message' => 'ไม่พบ period']); return; }
            echo json_encode(['ok' => true, 'row' => $p], JSON_UNESCAPED_UNICODE);
            return;
        }
        case 'create': {
            $ym       = (string)($_POST['period_ym'] ?? '');
            $payDate  = (string)($_POST['pay_date']  ?? '');
            $r = pr_create_period($pdo, $ym, $payDate ?: null, $adminId);
            if ($r['ok']) {
                echo json_encode(['ok' => true, 'period_id' => $r['period_id'],
                                  'created_entries' => $r['created_entries'],
                                  'message' => "สร้างงวด {$ym} แล้ว · gen {$r['created_entries']} รายการ"],
                                 JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['ok' => false, 'message' => $r['error']], JSON_UNESCAPED_UNICODE);
            }
            return;
        }
        case 'approve': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("UPDATE sys_payroll_periods
                                 SET status = 'approved', approved_at = NOW(), approved_by = :by
                                 WHERE id = :id AND status = 'draft'");
            $st->execute([':id' => $id, ':by' => $adminId ?: null]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'อนุมัติไม่ได้ — สถานะไม่ใช่ draft']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'อนุมัติแล้ว · กดต่อ "จ่ายเงิน" เพื่อ post Cash Book']);
            return;
        }
        case 'unapprove': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            // Only un-approve if not yet paid
            $st = $pdo->prepare("UPDATE sys_payroll_periods
                                 SET status = 'draft', approved_at = NULL, approved_by = NULL
                                 WHERE id = :id AND status = 'approved'");
            $st->execute([':id' => $id]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'ยกเลิกอนุมัติไม่ได้ — อาจจ่ายเงินไปแล้ว']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'กลับเป็นฉบับร่างแล้ว แก้ไขรายการได้']);
            return;
        }
        case 'pay': {
            $id      = (int)($_POST['id'] ?? 0);
            $payDate = (string)($_POST['pay_date'] ?? '');
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $r = pr_mark_period_paid($pdo, $id, $payDate ?: null, $adminId);
            if ($r['ok']) {
                echo json_encode(['ok' => true, 'finance_txn_id' => $r['finance_txn_id'],
                                  'message' => 'จ่ายเงินสำเร็จ · เข้า Cash Book แล้ว']);
            } else {
                echo json_encode(['ok' => false, 'message' => $r['error']]);
            }
            return;
        }
        case 'cancel': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("UPDATE sys_payroll_periods
                                 SET status = 'cancelled'
                                 WHERE id = :id AND status IN ('draft', 'approved')");
            $st->execute([':id' => $id]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'ยกเลิกไม่ได้ — งวดอาจจ่ายเงินไปแล้ว']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'ยกเลิกงวดแล้ว']);
            return;
        }
        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $st = $pdo->prepare("DELETE FROM sys_payroll_periods
                                 WHERE id = :id AND status IN ('draft', 'cancelled')");
            $st->execute([':id' => $id]);
            if ($st->rowCount() === 0) {
                echo json_encode(['ok' => false, 'message' => 'ลบได้เฉพาะ draft หรือ cancelled เท่านั้น']);
                return;
            }
            echo json_encode(['ok' => true, 'message' => 'ลบงวดแล้ว']);
            return;
        }
        default:
            echo json_encode(['ok' => false, 'message' => 'period action ไม่รู้จัก: ' . $verb]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Entry — update one row's mutable fields (OT, bonus, deductions)
// ─────────────────────────────────────────────────────────────────────────────

function handle_entry(PDO $pdo, string $verb, int $adminId): void
{
    switch ($verb) {
        case 'update': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); return; }
            $r = pr_update_entry($pdo, $id, [
                'base_salary'      => isset($_POST['base_salary'])      ? (float)$_POST['base_salary']      : null,
                'allowance'        => isset($_POST['allowance'])        ? (float)$_POST['allowance']        : null,
                'ot_hours'         => (float)($_POST['ot_hours']         ?? 0),
                'bonus'            => (float)($_POST['bonus']            ?? 0),
                'other_income'     => (float)($_POST['other_income']     ?? 0),
                'other_deductions' => (float)($_POST['other_deductions'] ?? 0),
                'notes'            => $_POST['notes'] ?? '',
            ]);
            if ($r['ok']) {
                echo json_encode(['ok' => true, 'calc' => $r['calc'], 'message' => 'อัปเดตแล้ว'],
                    JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['ok' => false, 'message' => $r['error']]);
            }
            return;
        }
        default:
            echo json_encode(['ok' => false, 'message' => 'entry action ไม่รู้จัก: ' . $verb]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Lookup — staff search (for add-employee picker)
// ─────────────────────────────────────────────────────────────────────────────

function handle_lookup_pr(PDO $pdo, string $verb): void
{
    switch ($verb) {
        case 'staff': {
            $q = trim((string)($_REQUEST['q'] ?? ''));
            // Exclude staff that already have a payroll profile
            $like = '%' . $q . '%';
            $sql = $q === ''
                ? "SELECT s.id, s.full_name, s.job_title, s.official_title
                   FROM sys_staff s
                   LEFT JOIN sys_payroll_employees e ON e.staff_id = s.id
                   WHERE e.id IS NULL
                   ORDER BY s.full_name ASC LIMIT 20"
                : "SELECT s.id, s.full_name, s.job_title, s.official_title
                   FROM sys_staff s
                   LEFT JOIN sys_payroll_employees e ON e.staff_id = s.id
                   WHERE e.id IS NULL
                     AND (s.full_name LIKE :q1 OR s.job_title LIKE :q2 OR s.official_title LIKE :q3)
                   ORDER BY s.full_name ASC LIMIT 20";
            $st = $pdo->prepare($sql);
            if ($q !== '') {
                $st->bindValue(':q1', $like);
                $st->bindValue(':q2', $like);
                $st->bindValue(':q3', $like);
            }
            $st->execute();
            echo json_encode(['ok' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)],
                JSON_UNESCAPED_UNICODE);
            return;
        }
        default:
            echo json_encode(['ok' => false, 'message' => 'lookup verb ไม่รู้จัก: ' . $verb]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Reports — ภงด.1, ประกันสังคม, bank transfer CSV
// ─────────────────────────────────────────────────────────────────────────────

function handle_report_export(PDO $pdo, string $kind): void
{
    pr_ensure_schema($pdo);
    $periodId = (int)($_REQUEST['period_id'] ?? 0);
    if ($periodId <= 0) { http_response_code(400); exit('period_id required'); }

    $period = $pdo->prepare("SELECT * FROM sys_payroll_periods WHERE id = :id");
    $period->execute([':id' => $periodId]);
    $p = $period->fetch(PDO::FETCH_ASSOC);
    if (!$p) { http_response_code(404); exit('Period not found'); }

    $rows = $pdo->prepare("SELECT e.*, emp.tax_id, emp.sso_no, emp.bank_name, emp.bank_account
                           FROM sys_payroll_entries e
                           LEFT JOIN sys_payroll_employees emp ON emp.id = e.employee_id
                           WHERE e.period_id = :id
                           ORDER BY e.full_name ASC");
    $rows->execute([':id' => $periodId]);
    $entries = $rows->fetchAll(PDO::FETCH_ASSOC);

    $fname = sprintf('%s_%s_%s.csv', $kind, $p['period_ym'], date('Ymd_His'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    if ($kind === 'pnd1_csv') {
        fputcsv($out, ['ภงด.1 — ภาษีหัก ณ ที่จ่าย ของพนักงาน']);
        fputcsv($out, ['งวด', $p['period_ym']]);
        fputcsv($out, ['ออก ณ', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['ลำดับ','เลขประจำตัวผู้เสียภาษี','ชื่อ-นามสกุล','เงินเดือน','OT','โบนัส','รายได้อื่น','รวมรายได้','ภาษีหัก ณ ที่จ่าย']);
        $totals = ['gross' => 0, 'tax' => 0];
        foreach ($entries as $i => $r) {
            fputcsv($out, [
                $i+1, $r['tax_id'] ?? '', $r['full_name'],
                number_format((float)$r['base_salary'],2,'.',''),
                number_format((float)$r['ot_amount'],2,'.',''),
                number_format((float)$r['bonus'],2,'.',''),
                number_format((float)$r['other_income'],2,'.',''),
                number_format((float)$r['gross_total'],2,'.',''),
                number_format((float)$r['tax_amount'],2,'.',''),
            ]);
            $totals['gross'] += (float)$r['gross_total'];
            $totals['tax']   += (float)$r['tax_amount'];
        }
        fputcsv($out, []);
        fputcsv($out, ['รวม','','','','','','',
            number_format($totals['gross'],2,'.',''),
            number_format($totals['tax'],2,'.','')
        ]);
    }
    elseif ($kind === 'sso_csv') {
        fputcsv($out, ['ประกันสังคม — สมทบประจำเดือน']);
        fputcsv($out, ['งวด', $p['period_ym']]);
        fputcsv($out, ['ออก ณ', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['ลำดับ','เลขประกันสังคม','ชื่อ-นามสกุล','ค่าจ้าง','สมทบฝั่งลูกจ้าง','สมทบฝั่งนายจ้าง','รวมต่อคน']);
        $totals = ['ee' => 0, 'er' => 0];
        foreach ($entries as $i => $r) {
            $combined = (float)$r['sso_employee'] + (float)$r['sso_employer'];
            fputcsv($out, [
                $i+1, $r['sso_no'] ?? '', $r['full_name'],
                number_format((float)$r['gross_total'],2,'.',''),
                number_format((float)$r['sso_employee'],2,'.',''),
                number_format((float)$r['sso_employer'],2,'.',''),
                number_format($combined,2,'.',''),
            ]);
            $totals['ee'] += (float)$r['sso_employee'];
            $totals['er'] += (float)$r['sso_employer'];
        }
        fputcsv($out, []);
        fputcsv($out, ['รวม','','','',
            number_format($totals['ee'],2,'.',''),
            number_format($totals['er'],2,'.',''),
            number_format($totals['ee']+$totals['er'],2,'.',''),
        ]);
    }
    elseif ($kind === 'bank_csv') {
        fputcsv($out, ['Bank Transfer — โอนเงินเดือนเข้าบัญชี']);
        fputcsv($out, ['งวด', $p['period_ym']]);
        fputcsv($out, ['ออก ณ', date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['ลำดับ','ชื่อ-นามสกุล','ธนาคาร','เลขที่บัญชี','จำนวนเงิน (สุทธิ)']);
        $total = 0;
        foreach ($entries as $i => $r) {
            fputcsv($out, [
                $i+1, $r['full_name'],
                $r['bank_name'] ?? '',
                $r['bank_account'] ?? '',
                number_format((float)$r['net_amount'],2,'.',''),
            ]);
            $total += (float)$r['net_amount'];
        }
        fputcsv($out, []);
        fputcsv($out, ['รวม','','','', number_format($total,2,'.','')]);
    }

    fclose($out);
}
