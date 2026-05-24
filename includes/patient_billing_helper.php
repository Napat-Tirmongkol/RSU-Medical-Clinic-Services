<?php
/**
 * includes/patient_billing_helper.php
 *
 * Patient Billing module helpers — used by portal/ajax_billing.php and any
 * cross-module integration (Cash Book sync, Insurance claims, etc.).
 *
 * Tables: sys_billing_services, sys_billing_encounters,
 *         sys_billing_encounter_items, sys_billing_invoices,
 *         sys_billing_payments
 *
 * Same idempotent ensure-schema pattern as finance_sync_helper.php so we
 * can call it from any entry point without depending on the migration
 * having been run.
 */
declare(strict_types=1);

const PB_SERVICE_CATEGORIES = [
    'consultation' => 'ตรวจรักษา',
    'treatment'    => 'การรักษา',
    'procedure'    => 'หัตถการ',
    'lab'          => 'ตรวจแล็บ',
    'vaccination'  => 'วัคซีน',
    'consumable'   => 'วัสดุ/ยา',
    'other'        => 'อื่นๆ',
];

const PB_INVOICE_STATUSES = [
    'draft'           => 'ฉบับร่าง',
    'issued'          => 'ออกแล้ว',
    'partially_paid'  => 'ชำระบางส่วน',
    'paid'            => 'ชำระครบ',
    'void'            => 'ยกเลิก',
    'overdue'         => 'เลยกำหนด',
];

const PB_PAYER_TYPES = [
    'patient'    => 'ผู้ป่วยจ่ายเอง',
    'insurance'  => 'ประกัน',
    'gold_card'  => 'บัตรทอง',
    'other'      => 'อื่นๆ',
];

const PB_PAYMENT_METHODS = [
    'cash'     => 'เงินสด',
    'transfer' => 'โอน',
    'card'     => 'บัตรเครดิต/เดบิต',
    'cheque'   => 'เช็ค',
    'other'    => 'อื่นๆ',
];

/**
 * Idempotently create all 5 Patient Billing tables. Cheap no-op on
 * subsequent calls (CREATE TABLE IF NOT EXISTS). Mirrors what
 * `database/migrations/migrate_patient_billing.php` does so production
 * deployments are safe even if the migration was never run manually.
 */
function pb_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    try {
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
            KEY idx_active (is_active, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
            KEY idx_service (service_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
            KEY idx_payer (payer_type, payer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
            KEY idx_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) {
        error_log('[pb_ensure_schema] ' . $e->getMessage());
    }
}

/**
 * List services with optional filters. Returns paginated rows + total count.
 *
 * @param array $opts {
 *     @type string $q         Search query (matches code/name)
 *     @type string $category  Filter by category (one of PB_SERVICE_CATEGORIES keys, or '')
 *     @type int    $active    -1 = all, 0 = inactive only, 1 = active only (default 1)
 *     @type int    $page      1-based page number (default 1)
 *     @type int    $per_page  Default 20
 *     @type string $sort      Field: name|code|unit_price|sort_order (default sort_order)
 *     @type string $dir       asc|desc (default asc)
 * }
 */
function pb_list_services(PDO $pdo, array $opts = []): array
{
    pb_ensure_schema($pdo);

    $q        = trim((string)($opts['q']        ?? ''));
    $category = trim((string)($opts['category'] ?? ''));
    $active   = (int)($opts['active']   ?? 1);
    $page     = max(1, (int)($opts['page']     ?? 1));
    $perPage  = max(1, min(200, (int)($opts['per_page'] ?? 20)));
    $sort     = (string)($opts['sort']     ?? 'sort_order');
    $dir      = strtolower((string)($opts['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

    // Whitelist sort fields — prevent SQL injection via $sort
    $allowedSort = ['name', 'code', 'unit_price', 'sort_order', 'category', 'created_at'];
    if (!in_array($sort, $allowedSort, true)) $sort = 'sort_order';

    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[]        = '(code LIKE :q OR name LIKE :q)';
        $params[':q']   = '%' . $q . '%';
    }
    if ($category !== '' && isset(PB_SERVICE_CATEGORIES[$category])) {
        $where[]               = 'category = :category';
        $params[':category']   = $category;
    }
    if ($active === 0 || $active === 1) {
        $where[]              = 'is_active = :active';
        $params[':active']    = $active;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // total count
    $cntSql = "SELECT COUNT(*) FROM sys_billing_services {$whereSql}";
    $cntSt  = $pdo->prepare($cntSql);
    foreach ($params as $k => $v) $cntSt->bindValue($k, $v);
    $cntSt->execute();
    $total = (int)$cntSt->fetchColumn();

    // page
    $offset = ($page - 1) * $perPage;
    $sql    = "SELECT id, code, name, category, description, unit_price, unit_label,
                      is_taxable, is_active, sort_order, created_at, updated_at
               FROM sys_billing_services {$whereSql}
               ORDER BY {$sort} {$dir}, id ASC
               LIMIT :limit OFFSET :offset";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $st->execute();

    return [
        'rows'     => $st->fetchAll(PDO::FETCH_ASSOC),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => max(1, (int)ceil($total / $perPage)),
    ];
}

/**
 * Validate + normalize a service payload. Returns ['ok' => bool, 'data'|'errors' => ...].
 */
function pb_validate_service_payload(array $p): array
{
    $errors = [];
    $code   = trim((string)($p['code']   ?? ''));
    $name   = trim((string)($p['name']   ?? ''));
    $cat    = trim((string)($p['category'] ?? 'other'));
    $price  = (float)($p['unit_price'] ?? 0);
    $unit   = trim((string)($p['unit_label'] ?? 'ครั้ง'));
    $desc   = trim((string)($p['description'] ?? ''));
    $taxable = (int)!empty($p['is_taxable']);
    $active  = isset($p['is_active']) ? (int)!empty($p['is_active']) : 1;
    $sort    = (int)($p['sort_order'] ?? 0);

    if ($code === '')                                  $errors['code']     = 'ระบุรหัสบริการ';
    if (strlen($code) > 40)                            $errors['code']     = 'รหัสยาวเกิน 40 ตัวอักษร';
    if ($name === '')                                  $errors['name']     = 'ระบุชื่อบริการ';
    if (strlen($name) > 200)                           $errors['name']     = 'ชื่อยาวเกิน 200 ตัวอักษร';
    if (!isset(PB_SERVICE_CATEGORIES[$cat]))           $errors['category'] = 'หมวดหมู่ไม่ถูกต้อง';
    if ($price < 0)                                    $errors['unit_price'] = 'ราคาต่อหน่วยต้องไม่ติดลบ';
    if ($price > 9999999.99)                           $errors['unit_price'] = 'ราคาเกินขีดจำกัด';
    if ($unit === '')                                  $errors['unit_label'] = 'ระบุหน่วยนับ';

    if ($errors) return ['ok' => false, 'errors' => $errors];

    return ['ok' => true, 'data' => [
        'code'        => $code,
        'name'        => $name,
        'category'    => $cat,
        'description' => $desc !== '' ? $desc : null,
        'unit_price'  => $price,
        'unit_label'  => $unit,
        'is_taxable'  => $taxable,
        'is_active'   => $active,
        'sort_order'  => $sort,
    ]];
}
