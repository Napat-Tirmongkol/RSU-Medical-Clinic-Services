<?php
/**
 * database/migrations/migrate_payroll.php
 *
 * Thai Payroll module — 3 tables for monthly payroll workflow:
 *   employee setup → period creation → entry calculation → payslip → Cash Book
 *
 * Tax brackets are computed in PHP (not stored in DB) — Thai PIT 2024-2026.
 * Idempotent: safe to re-run.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$pdo = db();
$log = [];

try {
    // ── 1. Payroll employee profile (extends sys_staff) ──────────────────────
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
        KEY idx_active (is_active),
        KEY idx_employment_type (employment_type, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✓ sys_payroll_employees';

    // ── 2. Pay periods (one row per month) ───────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_payroll_periods (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        period_ym VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
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
    $log[] = '✓ sys_payroll_periods';

    // ── 3. Per-employee per-period entries ───────────────────────────────────
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
        KEY idx_employee (employee_id),
        KEY idx_staff (staff_id),
        CONSTRAINT fk_pr_entry_period FOREIGN KEY (period_id)
            REFERENCES sys_payroll_periods(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✓ sys_payroll_entries';

    // Optional: add access_payroll flag to sys_staff so we can gate UI per-user
    try {
        $check = $pdo->query("SHOW COLUMNS FROM sys_staff LIKE 'access_payroll'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_payroll TINYINT(1) NOT NULL DEFAULT 0");
            $log[] = '✓ added sys_staff.access_payroll';
        }
    } catch (Throwable) {}

    echo implode("\n", $log) . "\n\nDone.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
