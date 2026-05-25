<?php
/**
 * includes/payroll_helper.php
 *
 * Thai payroll module helpers — calculation engine + CRUD support.
 *
 * Tax model (Thai PIT 2024-2026):
 *   - Progressive brackets (0% to 35%)
 *   - Personal allowance: 60,000
 *   - Spouse allowance: 60,000 (if applicable)
 *   - Child allowance: 30,000 each
 *   - 40% expense deduction (cap 100,000)
 *   - SSO contribution deductible (annual cap 9,000)
 *
 * Monthly withholding logic:
 *   monthly_gross × 12 → annual_gross
 *   annual_taxable = annual_gross − expense_deduct − allowances − annual_sso
 *   annual_tax = progressive(annual_taxable)
 *   monthly_tax = annual_tax / 12
 *
 * ประกันสังคม (Social Security):
 *   employee = min(salary × 5%, 750) per month
 *   employer matches (informational, doesn't reduce net)
 */
declare(strict_types=1);

const PR_EMPLOYMENT_TYPES = [
    'full_time' => 'พนักงานประจำ',
    'part_time' => 'พนักงานพาร์ทไทม์',
    'contract'  => 'จ้างเหมา/สัญญา',
    'hourly'    => 'รายชั่วโมง',
];

const PR_PERIOD_STATUSES = [
    'draft'     => 'ฉบับร่าง',
    'approved'  => 'อนุมัติแล้ว',
    'paid'      => 'จ่ายเงินแล้ว',
    'cancelled' => 'ยกเลิก',
];

const PR_DEFAULT_EXPENSE_CATEGORY = 'เงินเดือน/ค่าจ้าง';

/** SSO monthly cap (employee portion). 5% of salary up to 15,000 base. */
const PR_SSO_RATE        = 0.05;
const PR_SSO_MAX_MONTHLY = 750.00;   // 15,000 × 5%
const PR_SSO_MAX_YEARLY  = 9000.00;  // annual deduction cap for PIT

/**
 * Idempotent schema check — same pattern as patient_billing_helper.
 */
function pr_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_payroll_employees (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            staff_id INT UNSIGNED NOT NULL,
            employee_no VARCHAR(40) NULL,
            employment_type ENUM('full_time','part_time','contract','hourly')
                            NOT NULL DEFAULT 'full_time',
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            monthly_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            ot_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            bank_name VARCHAR(80) NULL,
            bank_account VARCHAR(40) NULL,
            tax_id VARCHAR(20) NULL,
            sso_no VARCHAR(20) NULL,
            is_in_sso TINYINT(1) NOT NULL DEFAULT 1,
            is_in_pf TINYINT(1) NOT NULL DEFAULT 0,
            pf_rate_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            personal_allowance DECIMAL(12,2) NOT NULL DEFAULT 60000.00,
            spouse_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            children_count INT UNSIGNED NOT NULL DEFAULT 0,
            hire_date DATE NULL,
            terminate_date DATE NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_staff (staff_id),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_payroll_periods (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_ym VARCHAR(7) NOT NULL,
            pay_date DATE NULL,
            status ENUM('draft','approved','paid','cancelled')
                   NOT NULL DEFAULT 'draft',
            total_gross DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total_net DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            finance_txn_id INT UNSIGNED NULL,
            approved_by INT UNSIGNED NULL,
            approved_at TIMESTAMP NULL,
            paid_at TIMESTAMP NULL,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_period (period_ym),
            KEY idx_status (status, period_ym)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_payroll_entries (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period_id INT UNSIGNED NOT NULL,
            employee_id INT UNSIGNED NOT NULL,
            staff_id INT UNSIGNED NOT NULL,
            employee_no VARCHAR(40) NULL,
            full_name VARCHAR(200) NULL,
            position_title VARCHAR(120) NULL,
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            ot_hours DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            ot_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            other_income DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            gross_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            sso_employee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            sso_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            pf_employee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            pf_employer DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            other_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            net_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_period_emp (period_id, employee_id),
            KEY idx_period (period_id),
            KEY idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) {
        error_log('[pr_ensure_schema] ' . $e->getMessage());
    }
}

/**
 * Thai PIT progressive tax — annual taxable amount → annual tax.
 * Brackets 2024-2026 (verify each tax year — these are the current rates).
 */
function pr_compute_annual_pit(float $taxable): float
{
    if ($taxable <= 0) return 0.0;

    // [upper_limit_of_bracket, rate]
    $brackets = [
        [150000,   0.00],
        [300000,   0.05],
        [500000,   0.10],
        [750000,   0.15],
        [1000000,  0.20],
        [2000000,  0.25],
        [5000000,  0.30],
        [PHP_FLOAT_MAX, 0.35],
    ];

    $tax  = 0.0;
    $prev = 0.0;
    foreach ($brackets as [$top, $rate]) {
        if ($taxable <= $prev) break;
        $slice = min($taxable, (float)$top) - $prev;
        $tax += $slice * $rate;
        $prev = (float)$top;
    }
    return round($tax, 2);
}

/**
 * Calculate monthly payroll line for one entry. Reads the employee profile
 * for SSO/PF/allowance settings, applies the inputs (base, OT, bonus, etc.)
 * and writes back the derived columns (gross, tax, sso, deductions, net).
 *
 * Returns the computed fields without writing to DB — caller decides when
 * to UPDATE (typically inside a transaction).
 */
function pr_calc_entry(array $emp, array $inputs): array
{
    $base       = max(0.0, (float)($inputs['base_salary']      ?? 0));
    $allowance  = max(0.0, (float)($inputs['allowance']        ?? 0));
    $otHours    = max(0.0, (float)($inputs['ot_hours']         ?? 0));
    $bonus      = max(0.0, (float)($inputs['bonus']            ?? 0));
    $otherInc   = max(0.0, (float)($inputs['other_income']     ?? 0));
    $otherDed   = max(0.0, (float)($inputs['other_deductions'] ?? 0));

    $otRate = max(0.0, (float)($emp['ot_rate'] ?? 0));
    $otAmount = round($otHours * $otRate, 2);

    $gross = round($base + $allowance + $otAmount + $bonus + $otherInc, 2);

    // ── ประกันสังคม (Social Security) ───────────────────────────────────────
    $ssoEmployee = 0.0;
    $ssoEmployer = 0.0;
    if (!empty($emp['is_in_sso'])) {
        $ssoEmployee = round(min($gross * PR_SSO_RATE, PR_SSO_MAX_MONTHLY), 2);
        $ssoEmployer = $ssoEmployee;
    }

    // ── Provident fund (optional) ───────────────────────────────────────────
    $pfEmployee = 0.0;
    $pfEmployer = 0.0;
    if (!empty($emp['is_in_pf']) && (float)($emp['pf_rate_pct'] ?? 0) > 0) {
        $rate = (float)$emp['pf_rate_pct'] / 100.0;
        $pfEmployee = round($gross * $rate, 2);
        $pfEmployer = $pfEmployee;  // employer typically matches
    }

    // ── ภงด.1 (Monthly withholding tax) ─────────────────────────────────────
    // Annualize, deduct, apply brackets, divide by 12.
    $annualGross = $gross * 12;
    // 40% expense deduction for salary income, capped at 100,000/year
    $expenseDeduct = min($annualGross * 0.40, 100000.0);

    $allowances = (float)($emp['personal_allowance'] ?? 60000)
                + (float)($emp['spouse_allowance']  ?? 0)
                + ((int)($emp['children_count']    ?? 0) * 30000);

    // SSO deduction (annual, capped at 9,000)
    $annualSso = min($ssoEmployee * 12, PR_SSO_MAX_YEARLY);

    $taxable = max(0.0, $annualGross - $expenseDeduct - $allowances - $annualSso);
    $annualTax  = pr_compute_annual_pit($taxable);
    $monthlyTax = round($annualTax / 12, 2);

    $totalDeductions = round($monthlyTax + $ssoEmployee + $pfEmployee + $otherDed, 2);
    $net             = round($gross - $totalDeductions, 2);

    return [
        'base_salary'      => $base,
        'allowance'        => $allowance,
        'ot_hours'         => $otHours,
        'ot_amount'        => $otAmount,
        'bonus'            => $bonus,
        'other_income'     => $otherInc,
        'gross_total'      => $gross,
        'tax_amount'       => $monthlyTax,
        'sso_employee'     => $ssoEmployee,
        'sso_employer'     => $ssoEmployer,
        'pf_employee'      => $pfEmployee,
        'pf_employer'      => $pfEmployer,
        'other_deductions' => $otherDed,
        'total_deductions' => $totalDeductions,
        'net_amount'       => $net,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Employee profile CRUD
// ─────────────────────────────────────────────────────────────────────────────

/**
 * List payroll employees joined with sys_staff for display fields.
 */
function pr_list_employees(PDO $pdo, array $opts = []): array
{
    pr_ensure_schema($pdo);

    $q      = trim((string)($opts['q']      ?? ''));
    $active = isset($opts['active']) ? (int)$opts['active'] : 1;
    $page   = max(1, (int)($opts['page']    ?? 1));
    $per    = max(1, min(200, (int)($opts['per_page'] ?? 20)));

    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[]            = '(s.full_name LIKE :q1 OR e.employee_no LIKE :q2 OR e.tax_id LIKE :q3)';
        $params[':q1']      = '%' . $q . '%';
        $params[':q2']      = '%' . $q . '%';
        $params[':q3']      = '%' . $q . '%';
    }
    if ($active === 0 || $active === 1) {
        $where[]              = 'e.is_active = :active';
        $params[':active']    = $active;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*)
                          FROM sys_payroll_employees e
                          JOIN sys_staff s ON s.id = e.staff_id
                          {$whereSql}");
    foreach ($params as $k => $v) $cnt->bindValue($k, $v);
    $cnt->execute();
    $total = (int)$cnt->fetchColumn();

    $offset = ($page - 1) * $per;
    $sql = "SELECT e.*, s.full_name, s.job_title, s.official_title
            FROM sys_payroll_employees e
            JOIN sys_staff s ON s.id = e.staff_id
            {$whereSql}
            ORDER BY s.full_name ASC
            LIMIT :limit OFFSET :offset";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':limit',  $per,    PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();

    return [
        'rows'     => $st->fetchAll(PDO::FETCH_ASSOC),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per,
        'pages'    => max(1, (int)ceil($total / $per)),
    ];
}

function pr_validate_employee(array $p): array
{
    $errors = [];
    $staffId = (int)($p['staff_id'] ?? 0);
    $type    = (string)($p['employment_type'] ?? 'full_time');
    $base    = (float)($p['base_salary'] ?? 0);
    $rate    = (float)($p['pf_rate_pct'] ?? 0);

    if ($staffId <= 0)                              $errors['staff_id'] = 'ระบุพนักงาน';
    if (!isset(PR_EMPLOYMENT_TYPES[$type]))         $errors['employment_type'] = 'ประเภทไม่ถูกต้อง';
    if ($base < 0)                                  $errors['base_salary'] = 'เงินเดือนต้องไม่ติดลบ';
    if ($base > 9999999.99)                         $errors['base_salary'] = 'เงินเดือนเกินขีดจำกัด';
    if ($rate < 0 || $rate > 100)                   $errors['pf_rate_pct'] = 'ต้องอยู่ระหว่าง 0-100%';

    if ($errors) return ['ok' => false, 'errors' => $errors];

    return ['ok' => true, 'data' => [
        'staff_id'           => $staffId,
        'employee_no'        => trim((string)($p['employee_no'] ?? '')) ?: null,
        'employment_type'    => $type,
        'base_salary'        => $base,
        'monthly_allowance'  => max(0.0, (float)($p['monthly_allowance'] ?? 0)),
        'ot_rate'            => max(0.0, (float)($p['ot_rate']           ?? 0)),
        'bank_name'          => trim((string)($p['bank_name']    ?? '')) ?: null,
        'bank_account'       => trim((string)($p['bank_account'] ?? '')) ?: null,
        'tax_id'             => trim((string)($p['tax_id']       ?? '')) ?: null,
        'sso_no'             => trim((string)($p['sso_no']       ?? '')) ?: null,
        'is_in_sso'          => (int)!empty($p['is_in_sso']),
        'is_in_pf'           => (int)!empty($p['is_in_pf']),
        'pf_rate_pct'        => $rate,
        'personal_allowance' => max(0.0, (float)($p['personal_allowance'] ?? 60000)),
        'spouse_allowance'   => max(0.0, (float)($p['spouse_allowance']   ?? 0)),
        'children_count'     => max(0, (int)($p['children_count'] ?? 0)),
        'hire_date'          => !empty($p['hire_date'])      ? $p['hire_date']      : null,
        'terminate_date'     => !empty($p['terminate_date']) ? $p['terminate_date'] : null,
        'is_active'          => isset($p['is_active']) ? (int)!empty($p['is_active']) : 1,
        'notes'              => trim((string)($p['notes'] ?? '')) ?: null,
    ]];
}

// ─────────────────────────────────────────────────────────────────────────────
// Period generation + calculation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a draft period for the given YM and auto-generate entries for all
 * active employees with default values (base salary + allowance, 0 OT/bonus).
 * Returns ['ok' => true, 'period_id' => N, 'created_entries' => N] on success.
 */
function pr_create_period(PDO $pdo, string $periodYm, ?string $payDate = null, ?int $adminId = null): array
{
    pr_ensure_schema($pdo);

    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodYm)) {
        return ['ok' => false, 'error' => 'รูปแบบ period_ym ต้องเป็น YYYY-MM'];
    }
    if ($payDate !== null && $payDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
        return ['ok' => false, 'error' => 'รูปแบบ pay_date ไม่ถูกต้อง'];
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO sys_payroll_periods
            (period_ym, pay_date, status, created_by)
            VALUES (:ym, :date, 'draft', :by)");
        $ins->execute([
            ':ym'   => $periodYm,
            ':date' => $payDate ?: null,
            ':by'   => $adminId ?: null,
        ]);
        $periodId = (int)$pdo->lastInsertId();

        // Pull all active employees with their staff records
        $emps = $pdo->query("
            SELECT e.*, s.full_name, s.job_title, s.official_title
            FROM sys_payroll_employees e
            JOIN sys_staff s ON s.id = e.staff_id
            WHERE e.is_active = 1
            ORDER BY s.full_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $insEntry = $pdo->prepare("INSERT INTO sys_payroll_entries
            (period_id, employee_id, staff_id, employee_no, full_name, position_title,
             base_salary, allowance, ot_hours, ot_amount, bonus, other_income, gross_total,
             tax_amount, sso_employee, sso_employer, pf_employee, pf_employer,
             other_deductions, total_deductions, net_amount)
            VALUES
            (:pid, :eid, :sid, :eno, :fn, :pt,
             :base, :allow, 0, 0, 0, 0, :gross,
             :tax, :ssoE, :ssoR, :pfE, :pfR,
             0, :td, :net)");

        $created = 0;
        foreach ($emps as $emp) {
            $calc = pr_calc_entry($emp, [
                'base_salary' => $emp['base_salary'],
                'allowance'   => $emp['monthly_allowance'],
            ]);
            $position = trim((string)($emp['official_title'] ?: $emp['job_title']));
            $insEntry->execute([
                ':pid'   => $periodId,
                ':eid'   => (int)$emp['id'],
                ':sid'   => (int)$emp['staff_id'],
                ':eno'   => $emp['employee_no'],
                ':fn'    => $emp['full_name'],
                ':pt'    => $position !== '' ? $position : null,
                ':base'  => $calc['base_salary'],
                ':allow' => $calc['allowance'],
                ':gross' => $calc['gross_total'],
                ':tax'   => $calc['tax_amount'],
                ':ssoE'  => $calc['sso_employee'],
                ':ssoR'  => $calc['sso_employer'],
                ':pfE'   => $calc['pf_employee'],
                ':pfR'   => $calc['pf_employer'],
                ':td'    => $calc['total_deductions'],
                ':net'   => $calc['net_amount'],
            ]);
            $created++;
        }

        pr_recalc_period_totals($pdo, $periodId);

        $pdo->commit();
        return ['ok' => true, 'period_id' => $periodId, 'created_entries' => $created];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => "งวด {$periodYm} มีอยู่แล้ว"];
        }
        return ['ok' => false, 'error' => 'สร้าง period ไม่สำเร็จ: ' . $e->getMessage()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'สร้าง period ไม่สำเร็จ: ' . $e->getMessage()];
    }
}

/** Recompute period totals from its entries. Called after any entry change. */
function pr_recalc_period_totals(PDO $pdo, int $periodId): void
{
    $st = $pdo->prepare("UPDATE sys_payroll_periods p
        LEFT JOIN (
            SELECT period_id,
                   COALESCE(SUM(gross_total), 0)      AS total_gross,
                   COALESCE(SUM(total_deductions), 0) AS total_ded,
                   COALESCE(SUM(net_amount), 0)       AS total_net
            FROM sys_payroll_entries
            WHERE period_id = :id
        ) t ON t.period_id = p.id
        SET p.total_gross      = COALESCE(t.total_gross, 0),
            p.total_deductions = COALESCE(t.total_ded,   0),
            p.total_net        = COALESCE(t.total_net,   0)
        WHERE p.id = :id2");
    $st->execute([':id' => $periodId, ':id2' => $periodId]);
}

/** List periods (most recent first) with summary stats. */
function pr_list_periods(PDO $pdo, array $opts = []): array
{
    pr_ensure_schema($pdo);
    $page = max(1, (int)($opts['page']     ?? 1));
    $per  = max(1, min(200, (int)($opts['per_page'] ?? 20)));

    $total = (int)$pdo->query("SELECT COUNT(*) FROM sys_payroll_periods")->fetchColumn();
    $offset = ($page - 1) * $per;

    $sql = "SELECT p.*,
                   (SELECT COUNT(*) FROM sys_payroll_entries WHERE period_id = p.id) AS entry_count
            FROM sys_payroll_periods p
            ORDER BY p.period_ym DESC
            LIMIT :limit OFFSET :offset";
    $st = $pdo->prepare($sql);
    $st->bindValue(':limit',  $per,    PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();

    return [
        'rows'     => $st->fetchAll(PDO::FETCH_ASSOC),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per,
        'pages'    => max(1, (int)ceil($total / $per)),
    ];
}

/** Get one period + all its entries. */
function pr_get_period_full(PDO $pdo, int $periodId): ?array
{
    pr_ensure_schema($pdo);

    $hdr = $pdo->prepare("SELECT * FROM sys_payroll_periods WHERE id = :id");
    $hdr->execute([':id' => $periodId]);
    $period = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$period) return null;

    $items = $pdo->prepare("SELECT * FROM sys_payroll_entries
                            WHERE period_id = :id
                            ORDER BY full_name ASC");
    $items->execute([':id' => $periodId]);
    $period['entries'] = $items->fetchAll(PDO::FETCH_ASSOC);

    return $period;
}

/**
 * Update one entry's mutable fields (OT, bonus, other income/deductions, notes).
 * Recomputes all derived columns using the linked employee profile.
 * Refuses if period is not 'draft' (approved/paid are locked).
 */
function pr_update_entry(PDO $pdo, int $entryId, array $inputs): array
{
    pr_ensure_schema($pdo);

    $pdo->beginTransaction();
    try {
        // Lock the entry + check period status
        $row = $pdo->prepare("SELECT e.*, p.status AS period_status, p.id AS period_id
                              FROM sys_payroll_entries e
                              JOIN sys_payroll_periods p ON p.id = e.period_id
                              WHERE e.id = :id FOR UPDATE");
        $row->execute([':id' => $entryId]);
        $entry = $row->fetch(PDO::FETCH_ASSOC);
        if (!$entry) { $pdo->rollBack(); return ['ok' => false, 'error' => 'ไม่พบรายการ']; }
        if ($entry['period_status'] !== 'draft') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => "งวดสถานะ '{$entry['period_status']}' แก้ไขไม่ได้"];
        }

        // Pull employee profile for calc
        $emp = $pdo->prepare("SELECT * FROM sys_payroll_employees WHERE id = :id");
        $emp->execute([':id' => (int)$entry['employee_id']]);
        $empRow = $emp->fetch(PDO::FETCH_ASSOC);
        if (!$empRow) { $pdo->rollBack(); return ['ok' => false, 'error' => 'ไม่พบโปรไฟล์พนักงาน']; }

        $calc = pr_calc_entry($empRow, [
            'base_salary'      => $inputs['base_salary']      ?? $entry['base_salary'],
            'allowance'        => $inputs['allowance']        ?? $entry['allowance'],
            'ot_hours'         => $inputs['ot_hours']         ?? 0,
            'bonus'            => $inputs['bonus']            ?? 0,
            'other_income'     => $inputs['other_income']     ?? 0,
            'other_deductions' => $inputs['other_deductions'] ?? 0,
        ]);

        $up = $pdo->prepare("UPDATE sys_payroll_entries SET
            base_salary = :base, allowance = :allow,
            ot_hours = :oth, ot_amount = :ota,
            bonus = :bonus, other_income = :oinc, gross_total = :gross,
            tax_amount = :tax,
            sso_employee = :ssoE, sso_employer = :ssoR,
            pf_employee = :pfE, pf_employer = :pfR,
            other_deductions = :oded, total_deductions = :td, net_amount = :net,
            notes = :notes
            WHERE id = :id");
        $up->execute([
            ':id'    => $entryId,
            ':base'  => $calc['base_salary'],
            ':allow' => $calc['allowance'],
            ':oth'   => $calc['ot_hours'],
            ':ota'   => $calc['ot_amount'],
            ':bonus' => $calc['bonus'],
            ':oinc'  => $calc['other_income'],
            ':gross' => $calc['gross_total'],
            ':tax'   => $calc['tax_amount'],
            ':ssoE'  => $calc['sso_employee'],
            ':ssoR'  => $calc['sso_employer'],
            ':pfE'   => $calc['pf_employee'],
            ':pfR'   => $calc['pf_employer'],
            ':oded'  => $calc['other_deductions'],
            ':td'    => $calc['total_deductions'],
            ':net'   => $calc['net_amount'],
            ':notes' => trim((string)($inputs['notes'] ?? '')) ?: null,
        ]);

        pr_recalc_period_totals($pdo, (int)$entry['period_id']);
        $pdo->commit();
        return ['ok' => true, 'calc' => $calc];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Mark a period 'paid' and sync the total expense to Cash Book atomically.
 * Idempotent via source_module='payroll_period', source_id=period.id.
 */
function pr_mark_period_paid(PDO $pdo, int $periodId, ?string $payDate = null, ?int $adminId = null): array
{
    pr_ensure_schema($pdo);

    $pdo->beginTransaction();
    try {
        $row = $pdo->prepare("SELECT * FROM sys_payroll_periods WHERE id = :id FOR UPDATE");
        $row->execute([':id' => $periodId]);
        $p = $row->fetch(PDO::FETCH_ASSOC);
        if (!$p) { $pdo->rollBack(); return ['ok' => false, 'error' => 'ไม่พบ period']; }
        if ($p['status'] !== 'approved') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => "ต้องอนุมัติ period ก่อน — สถานะปัจจุบัน '{$p['status']}'"];
        }

        $total = (float)$p['total_net'];
        if ($total <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'ยอดสุทธิเป็น 0 — ไม่มีอะไรให้จ่าย'];
        }

        $finalPayDate = $payDate ?: ($p['pay_date'] ?: date('Y-m-d'));

        // Sync to Cash Book as expense
        require_once __DIR__ . '/finance_sync_helper.php';
        $sync = finance_sync_upsert($pdo, [
            'source_module' => 'payroll_period',
            'source_id'     => (string)$periodId,
            'kind'          => 'expense',
            'amount'        => $total,
            'txn_date'      => $finalPayDate,
            'category_name' => PR_DEFAULT_EXPENSE_CATEGORY,
            'description'   => 'จ่ายเงินเดือนงวด ' . $p['period_ym']
                             . ' (' . (int)$pdo->query("SELECT COUNT(*) FROM sys_payroll_entries WHERE period_id={$periodId}")->fetchColumn() . ' คน)',
            'reference'     => 'PAYROLL-' . $p['period_ym'],
            'admin_id'      => $adminId,
        ]);
        if (!$sync['ok']) {
            throw new RuntimeException('Cash Book sync ล้มเหลว: ' . ($sync['error'] ?? 'unknown'));
        }

        $pdo->prepare("UPDATE sys_payroll_periods
                       SET status = 'paid', pay_date = :date, paid_at = NOW(),
                           finance_txn_id = :txn
                       WHERE id = :id")
            ->execute([
                ':date' => $finalPayDate,
                ':txn'  => (int)$sync['id'],
                ':id'   => $periodId,
            ]);

        $pdo->commit();
        return ['ok' => true, 'finance_txn_id' => (int)$sync['id']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
