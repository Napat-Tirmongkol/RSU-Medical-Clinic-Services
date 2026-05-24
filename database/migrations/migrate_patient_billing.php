<?php
/**
 * database/migrations/migrate_patient_billing.php
 *
 * Patient Billing (AR) module — 5 tables for the
 *   Service Catalog → Encounter → Invoice → Payment → AR Aging
 * workflow. Idempotent: safe to re-run.
 *
 * Run:  php database/migrations/migrate_patient_billing.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$pdo = db();
$log = [];

try {
    // ── 1. Service catalog ────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_billing_services (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(40) NOT NULL,
        name VARCHAR(200) NOT NULL,
        category ENUM('consultation','treatment','procedure','lab','vaccination','consumable','other')
                 NOT NULL DEFAULT 'other',
        description TEXT NULL,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        unit_label VARCHAR(40) NOT NULL DEFAULT 'ครั้ง',
        is_taxable TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        linked_vaccine_type_id INT UNSIGNED NULL,
        linked_consumable_id INT UNSIGNED NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_code (code),
        KEY idx_category (category, is_active),
        KEY idx_active (is_active, sort_order),
        KEY idx_vaccine (linked_vaccine_type_id),
        KEY idx_consumable (linked_consumable_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✓ sys_billing_services';

    // ── 2. Encounter (visit) ──────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_billing_encounters (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        encounter_no VARCHAR(30) NOT NULL,
        patient_id INT UNSIGNED NOT NULL,
        visit_date DATE NOT NULL,
        provider_id INT UNSIGNED NULL,
        diagnosis VARCHAR(500) NULL,
        notes TEXT NULL,
        status ENUM('draft','finalized','invoiced','cancelled') NOT NULL DEFAULT 'draft',
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        finalized_at TIMESTAMP NULL,
        UNIQUE KEY uniq_no (encounter_no),
        KEY idx_patient (patient_id, visit_date),
        KEY idx_status (status, visit_date),
        KEY idx_provider (provider_id, visit_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✓ sys_billing_encounters';

    // ── 3. Encounter line items ───────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_billing_encounter_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        encounter_id INT UNSIGNED NOT NULL,
        service_id INT UNSIGNED NOT NULL,
        service_code VARCHAR(40) NULL,
        service_name VARCHAR(200) NULL,
        quantity DECIMAL(8,2) NOT NULL DEFAULT 1.00,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        note VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_encounter (encounter_id),
        KEY idx_service (service_id),
        CONSTRAINT fk_enc_item_encounter FOREIGN KEY (encounter_id)
            REFERENCES sys_billing_encounters(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✓ sys_billing_encounter_items';

    // ── 4. Invoice ────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_billing_invoices (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_no VARCHAR(30) NOT NULL,
        encounter_id INT UNSIGNED NULL,
        patient_id INT UNSIGNED NOT NULL,
        issue_date DATE NOT NULL,
        due_date DATE NULL,
        payer_type ENUM('patient','insurance','gold_card','other') NOT NULL DEFAULT 'patient',
        payer_id INT UNSIGNED NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status ENUM('draft','issued','partially_paid','paid','void','overdue') NOT NULL DEFAULT 'draft',
        notes TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        issued_at TIMESTAMP NULL,
        UNIQUE KEY uniq_invoice_no (invoice_no),
        KEY idx_patient (patient_id, status),
        KEY idx_status_due (status, due_date),
        KEY idx_payer (payer_type, payer_id),
        KEY idx_encounter (encounter_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✓ sys_billing_invoices';

    // ── 5. Payment ────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_billing_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT UNSIGNED NOT NULL,
        payment_date DATE NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        method ENUM('cash','transfer','card','cheque','other') NOT NULL DEFAULT 'cash',
        reference VARCHAR(100) NULL,
        note TEXT NULL,
        finance_txn_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_invoice (invoice_id),
        KEY idx_date (payment_date),
        KEY idx_method (method, payment_date),
        CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id)
            REFERENCES sys_billing_invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $log[] = '✓ sys_billing_payments';

    // ── Seed: default common services (idempotent — only if catalog empty) ────
    $count = (int)$pdo->query("SELECT COUNT(*) FROM sys_billing_services")->fetchColumn();
    if ($count === 0) {
        $seed = [
            ['CONS-GP',       'ตรวจรักษาทั่วไป (GP consultation)',           'consultation', 200,  'ครั้ง'],
            ['CONS-SP',       'ตรวจรักษาโดยแพทย์เฉพาะทาง',                   'consultation', 500,  'ครั้ง'],
            ['LAB-CBC',       'ตรวจเลือดสมบูรณ์ (CBC)',                       'lab',          150,  'ครั้ง'],
            ['LAB-URINE',     'ตรวจปัสสาวะ',                                 'lab',          80,   'ครั้ง'],
            ['PROC-DRESSING', 'ทำแผล',                                       'procedure',    100,  'ครั้ง'],
            ['PROC-INJECT',   'ฉีดยา',                                       'procedure',    50,   'ครั้ง'],
            ['VAC-FLU',       'วัคซีนไข้หวัดใหญ่',                            'vaccination',  450,  'เข็ม'],
            ['VAC-TETANUS',   'วัคซีนบาดทะยัก',                              'vaccination',  150,  'เข็ม'],
            ['TRT-IV',        'ให้สารน้ำทางหลอดเลือดดำ',                      'treatment',    300,  'ครั้ง'],
            ['OTH-CERT',      'ออกใบรับรองแพทย์',                            'other',        50,   'ฉบับ'],
        ];
        $ins = $pdo->prepare("INSERT INTO sys_billing_services
            (code, name, category, unit_price, unit_label, is_active, sort_order)
            VALUES (?, ?, ?, ?, ?, 1, ?)");
        foreach ($seed as $i => $row) {
            $ins->execute([$row[0], $row[1], $row[2], $row[3], $row[4], ($i + 1) * 10]);
        }
        $log[] = '✓ seeded ' . count($seed) . ' default services';
    } else {
        $log[] = "· catalog already has {$count} services — seed skipped";
    }

    echo implode("\n", $log) . "\n\nDone.\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
