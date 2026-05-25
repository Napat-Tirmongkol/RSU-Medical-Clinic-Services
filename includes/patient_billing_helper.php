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
        $where[]            = '(code LIKE :q1 OR name LIKE :q2)';
        $params[':q1']      = '%' . $q . '%';
        $params[':q2']      = '%' . $q . '%';
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

// ─────────────────────────────────────────────────────────────────────────────
// Encounter (visit) helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generate the next encounter number. Format: ENC-{yearBE}-{seq:06d}.
 * Sequence is per-calendar-year (Buddhist Era) and continuous regardless of
 * draft/finalized status — deleted drafts leave gaps which is acceptable.
 *
 * Race-tolerant: caller MUST insert under a UNIQUE constraint and retry on
 * collision. Low-traffic clinic so single-attempt is almost always fine.
 */
function pb_next_encounter_no(PDO $pdo): string
{
    $yearBE = (int)date('Y') + 543;
    $prefix = "ENC-{$yearBE}-";
    $st = $pdo->prepare("SELECT encounter_no FROM sys_billing_encounters
                         WHERE encounter_no LIKE :prefix
                         ORDER BY id DESC LIMIT 1");
    $st->execute([':prefix' => $prefix . '%']);
    $last = (string)($st->fetchColumn() ?: '');
    $seq  = (preg_match('/(\d{6})$/', $last, $m)) ? ((int)$m[1] + 1) : 1;
    return sprintf('%s%06d', $prefix, $seq);
}

/**
 * Lightweight patient typeahead — searches sys_users by name / student id /
 * phone / citizen_id. Returns at most 15 rows.
 */
function pb_lookup_patient(PDO $pdo, string $q): array
{
    $q = trim($q);
    if ($q === '' || mb_strlen($q) < 1) return [];
    $like = '%' . $q . '%';
    // PDO emulate-prepares is off — bind a unique name per placeholder.
    $st = $pdo->prepare("
        SELECT id, full_name, student_personnel_id, phone_number, citizen_id, status
        FROM sys_users
        WHERE full_name           LIKE :q1
           OR student_personnel_id LIKE :q2
           OR phone_number         LIKE :q3
           OR citizen_id           LIKE :q4
        ORDER BY
          CASE WHEN student_personnel_id = :exact1 OR citizen_id = :exact2 THEN 0 ELSE 1 END,
          full_name ASC
        LIMIT 15
    ");
    $st->execute([
        ':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like,
        ':exact1' => $q, ':exact2' => $q,
    ]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Provider (doctor/nurse) typeahead — searches sys_staff by name / official
 * title / job title. Returns at most 15 rows.
 */
function pb_lookup_provider(PDO $pdo, string $q): array
{
    $q = trim($q);
    if ($q === '') return [];
    $like = '%' . $q . '%';
    try {
        $st = $pdo->prepare("
            SELECT id, full_name,
                   COALESCE(NULLIF(official_title,''), NULLIF(job_title,''), '') AS title
            FROM sys_staff
            WHERE full_name      LIKE :q1
               OR official_title LIKE :q2
               OR job_title      LIKE :q3
            ORDER BY full_name ASC
            LIMIT 15
        ");
        $st->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        return [];
    }
}

/**
 * Recalculate encounter totals from its line items. Returns the computed
 * subtotal so callers can reuse without a second SELECT.
 */
function pb_recalc_encounter_totals(PDO $pdo, int $encounterId): float
{
    // line_total is already (qty × unit_price − discount) per row
    $st = $pdo->prepare("SELECT COALESCE(SUM(line_total),0)
                         FROM sys_billing_encounter_items
                         WHERE encounter_id = :id");
    $st->execute([':id' => $encounterId]);
    $subtotal = (float)$st->fetchColumn();

    // Read current header discount/tax so we can compute final total
    $hdr = $pdo->prepare("SELECT discount, tax FROM sys_billing_encounters WHERE id = :id");
    $hdr->execute([':id' => $encounterId]);
    $h = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$h) return $subtotal;

    $discount = (float)$h['discount'];
    $tax      = (float)$h['tax'];
    $total    = max(0, $subtotal - $discount) + $tax;

    $up = $pdo->prepare("UPDATE sys_billing_encounters
                         SET subtotal = :sub, total = :tot
                         WHERE id = :id");
    $up->execute([':sub' => $subtotal, ':tot' => $total, ':id' => $encounterId]);
    return $subtotal;
}

/**
 * List encounters with filters + pagination.
 *
 * @param array $opts {
 *     @type string $q          Search by encounter_no or patient name
 *     @type string $status     One of draft|finalized|invoiced|cancelled (or '')
 *     @type string $date_from  YYYY-MM-DD
 *     @type string $date_to    YYYY-MM-DD
 *     @type int    $patient_id Filter to one patient
 *     @type int    $page       1-based
 *     @type int    $per_page   Default 20
 * }
 */
function pb_list_encounters(PDO $pdo, array $opts = []): array
{
    pb_ensure_schema($pdo);

    $q        = trim((string)($opts['q']        ?? ''));
    $status   = trim((string)($opts['status']   ?? ''));
    $dateFrom = trim((string)($opts['date_from'] ?? ''));
    $dateTo   = trim((string)($opts['date_to']   ?? ''));
    $patient  = (int)($opts['patient_id'] ?? 0);
    $page     = max(1, (int)($opts['page']     ?? 1));
    $perPage  = max(1, min(200, (int)($opts['per_page'] ?? 20)));

    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[]            = '(e.encounter_no LIKE :q1 OR u.full_name LIKE :q2 OR u.student_personnel_id LIKE :q3)';
        $params[':q1']      = '%' . $q . '%';
        $params[':q2']      = '%' . $q . '%';
        $params[':q3']      = '%' . $q . '%';
    }
    if ($status !== '' && in_array($status, ['draft','finalized','invoiced','cancelled'], true)) {
        $where[]              = 'e.status = :status';
        $params[':status']    = $status;
    }
    if ($dateFrom !== '') {
        $where[]              = 'e.visit_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]              = 'e.visit_date <= :date_to';
        $params[':date_to']   = $dateTo;
    }
    if ($patient > 0) {
        $where[]               = 'e.patient_id = :patient';
        $params[':patient']    = $patient;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // total
    $cntSql = "SELECT COUNT(*)
               FROM sys_billing_encounters e
               LEFT JOIN sys_users u ON u.id = e.patient_id
               {$whereSql}";
    $cntSt = $pdo->prepare($cntSql);
    foreach ($params as $k => $v) $cntSt->bindValue($k, $v);
    $cntSt->execute();
    $total = (int)$cntSt->fetchColumn();

    // page
    $offset = ($page - 1) * $perPage;
    $sql = "SELECT e.id, e.encounter_no, e.patient_id, e.visit_date, e.provider_id,
                   e.diagnosis, e.status, e.subtotal, e.discount, e.tax, e.total,
                   e.created_at, e.finalized_at,
                   u.full_name AS patient_name, u.student_personnel_id AS patient_code,
                   s.full_name AS provider_name
            FROM sys_billing_encounters e
            LEFT JOIN sys_users u ON u.id = e.patient_id
            LEFT JOIN sys_staff s ON s.id = e.provider_id
            {$whereSql}
            ORDER BY e.visit_date DESC, e.id DESC
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
 * Validate + normalize an encounter payload (header only).
 */
function pb_validate_encounter_header(array $p): array
{
    $errors  = [];
    $patient = (int)($p['patient_id'] ?? 0);
    $visit   = trim((string)($p['visit_date'] ?? ''));
    $provider = (int)($p['provider_id'] ?? 0) ?: null;
    $diag    = trim((string)($p['diagnosis'] ?? ''));
    $notes   = trim((string)($p['notes']     ?? ''));
    $discount = max(0.0, (float)($p['discount'] ?? 0));
    $tax      = max(0.0, (float)($p['tax']      ?? 0));

    if ($patient <= 0)               $errors['patient_id'] = 'ระบุผู้ป่วย';
    if ($visit === '')               $errors['visit_date'] = 'ระบุวันเข้ารับบริการ';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit))
                                     $errors['visit_date'] = 'รูปแบบวันที่ไม่ถูกต้อง';
    if (mb_strlen($diag) > 500)      $errors['diagnosis']  = 'การวินิจฉัยยาวเกินไป';

    if ($errors) return ['ok' => false, 'errors' => $errors];

    return ['ok' => true, 'data' => [
        'patient_id'  => $patient,
        'visit_date'  => $visit,
        'provider_id' => $provider,
        'diagnosis'   => $diag !== '' ? $diag : null,
        'notes'       => $notes !== '' ? $notes : null,
        'discount'    => $discount,
        'tax'         => $tax,
    ]];
}

// ─────────────────────────────────────────────────────────────────────────────
// Invoice + Payment helpers (Phase 1C)
// ─────────────────────────────────────────────────────────────────────────────

/** Default income category in sys_finance_categories used when syncing
 *  billing payments → Cash Book. Matches the seed in ajax_finance.php. */
const PB_DEFAULT_INCOME_CATEGORY = 'ค่ารักษาผู้ป่วย';

/** Generate next invoice number — INV-{yearBE}-{seq:06d}. Race-tolerant
 *  via UNIQUE constraint + retry in caller. */
function pb_next_invoice_no(PDO $pdo): string
{
    $yearBE = (int)date('Y') + 543;
    $prefix = "INV-{$yearBE}-";
    $st = $pdo->prepare("SELECT invoice_no FROM sys_billing_invoices
                         WHERE invoice_no LIKE :prefix
                         ORDER BY id DESC LIMIT 1");
    $st->execute([':prefix' => $prefix . '%']);
    $last = (string)($st->fetchColumn() ?: '');
    $seq  = (preg_match('/(\d{6})$/', $last, $m)) ? ((int)$m[1] + 1) : 1;
    return sprintf('%s%06d', $prefix, $seq);
}

/**
 * Generate an invoice from a finalized encounter. Atomic:
 *   - Locks the encounter row
 *   - Refuses if status != 'finalized' or already invoiced
 *   - Copies totals to invoice header (encounter is the source of truth)
 *   - Flips encounter.status → 'invoiced'
 *
 * @return array{ok:bool, invoice_id?:int, invoice_no?:string, error?:string}
 */
function pb_generate_invoice_from_encounter(PDO $pdo, int $encounterId, array $opts, ?int $adminId = null): array
{
    pb_ensure_schema($pdo);

    $payerType = $opts['payer_type'] ?? 'patient';
    if (!isset(PB_PAYER_TYPES[$payerType])) {
        return ['ok' => false, 'error' => 'payer_type ไม่ถูกต้อง'];
    }
    $payerId  = isset($opts['payer_id']) ? (int)$opts['payer_id'] : null;
    $dueDate  = trim((string)($opts['due_date'] ?? ''));
    if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
        return ['ok' => false, 'error' => 'รูปแบบ due_date ไม่ถูกต้อง'];
    }
    $notes = trim((string)($opts['notes'] ?? ''));

    $pdo->beginTransaction();
    try {
        // Lock encounter
        $enc = $pdo->prepare("SELECT id, patient_id, status, subtotal, discount, tax, total
                              FROM sys_billing_encounters WHERE id = :id FOR UPDATE");
        $enc->execute([':id' => $encounterId]);
        $e = $enc->fetch(PDO::FETCH_ASSOC);
        if (!$e) { $pdo->rollBack(); return ['ok' => false, 'error' => 'ไม่พบ encounter']; }
        if ($e['status'] === 'invoiced') {
            // Look up existing invoice for friendly error
            $existing = $pdo->prepare("SELECT id, invoice_no FROM sys_billing_invoices
                                       WHERE encounter_id = :eid LIMIT 1");
            $existing->execute([':eid' => $encounterId]);
            $exist = $existing->fetch(PDO::FETCH_ASSOC);
            $pdo->rollBack();
            return ['ok' => false,
                    'error' => 'encounter นี้ออกใบแจ้งหนี้แล้ว: ' . ($exist['invoice_no'] ?? '?'),
                    'existing_invoice_id' => $exist['id'] ?? null];
        }
        if ($e['status'] !== 'finalized') {
            $pdo->rollBack();
            return ['ok' => false,
                    'error' => "encounter สถานะ '{$e['status']}' ออกใบแจ้งหนี้ไม่ได้ — ต้อง finalized ก่อน"];
        }

        // Generate invoice number with retry on UNIQUE collision
        $invoiceId = 0;
        $invoiceNo = '';
        $maxRetry = 3;
        for ($try = 0; $try < $maxRetry; $try++) {
            $invoiceNo = pb_next_invoice_no($pdo);
            try {
                $ins = $pdo->prepare("INSERT INTO sys_billing_invoices
                    (invoice_no, encounter_id, patient_id, issue_date, due_date,
                     payer_type, payer_id, subtotal, discount, tax, total, paid_amount,
                     status, notes, created_by, issued_at)
                    VALUES
                    (:no, :eid, :pid, CURDATE(), :due,
                     :ptype, :pid_payer, :sub, :disc, :tax, :tot, 0,
                     'issued', :notes, :by, NOW())");
                $ins->execute([
                    ':no'         => $invoiceNo,
                    ':eid'        => $encounterId,
                    ':pid'        => (int)$e['patient_id'],
                    ':due'        => $dueDate !== '' ? $dueDate : null,
                    ':ptype'      => $payerType,
                    ':pid_payer'  => $payerId ?: null,
                    ':sub'        => (float)$e['subtotal'],
                    ':disc'       => (float)$e['discount'],
                    ':tax'        => (float)$e['tax'],
                    ':tot'        => (float)$e['total'],
                    ':notes'      => $notes !== '' ? $notes : null,
                    ':by'         => $adminId ?: null,
                ]);
                $invoiceId = (int)$pdo->lastInsertId();
                break;
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062 && $try < $maxRetry - 1) {
                    continue;
                }
                throw $ex;
            }
        }

        // Flip encounter status
        $pdo->prepare("UPDATE sys_billing_encounters
                       SET status = 'invoiced'
                       WHERE id = :id AND status = 'finalized'")
            ->execute([':id' => $encounterId]);

        $pdo->commit();
        return ['ok' => true, 'invoice_id' => $invoiceId, 'invoice_no' => $invoiceNo];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'สร้างใบแจ้งหนี้ไม่สำเร็จ: ' . $e->getMessage()];
    }
}

/** List invoices with filters + pagination. */
function pb_list_invoices(PDO $pdo, array $opts = []): array
{
    pb_ensure_schema($pdo);

    $q          = trim((string)($opts['q']         ?? ''));
    $status     = trim((string)($opts['status']    ?? ''));
    $payerType  = trim((string)($opts['payer_type'] ?? ''));
    $dateFrom   = trim((string)($opts['date_from'] ?? ''));
    $dateTo     = trim((string)($opts['date_to']   ?? ''));
    $patient    = (int)($opts['patient_id'] ?? 0);
    $page       = max(1, (int)($opts['page']     ?? 1));
    $perPage    = max(1, min(200, (int)($opts['per_page'] ?? 20)));

    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[]            = '(i.invoice_no LIKE :q1 OR u.full_name LIKE :q2 OR u.student_personnel_id LIKE :q3)';
        $params[':q1']      = '%' . $q . '%';
        $params[':q2']      = '%' . $q . '%';
        $params[':q3']      = '%' . $q . '%';
    }
    if ($status !== '' && isset(PB_INVOICE_STATUSES[$status])) {
        $where[]              = 'i.status = :status';
        $params[':status']    = $status;
    }
    if ($payerType !== '' && isset(PB_PAYER_TYPES[$payerType])) {
        $where[]                = 'i.payer_type = :ptype';
        $params[':ptype']       = $payerType;
    }
    if ($dateFrom !== '') {
        $where[]              = 'i.issue_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]              = 'i.issue_date <= :date_to';
        $params[':date_to']   = $dateTo;
    }
    if ($patient > 0) {
        $where[]               = 'i.patient_id = :patient';
        $params[':patient']    = $patient;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $cntSql = "SELECT COUNT(*)
               FROM sys_billing_invoices i
               LEFT JOIN sys_users u ON u.id = i.patient_id
               {$whereSql}";
    $cntSt = $pdo->prepare($cntSql);
    foreach ($params as $k => $v) $cntSt->bindValue($k, $v);
    $cntSt->execute();
    $total = (int)$cntSt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $sql = "SELECT i.id, i.invoice_no, i.encounter_id, i.patient_id, i.issue_date, i.due_date,
                   i.payer_type, i.subtotal, i.discount, i.tax, i.total, i.paid_amount, i.status,
                   i.created_at,
                   u.full_name AS patient_name, u.student_personnel_id AS patient_code,
                   (i.total - i.paid_amount) AS balance
            FROM sys_billing_invoices i
            LEFT JOIN sys_users u ON u.id = i.patient_id
            {$whereSql}
            ORDER BY i.issue_date DESC, i.id DESC
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

/** Get one invoice with header + encounter info + line items + payments. */
function pb_get_invoice_full(PDO $pdo, int $invoiceId): ?array
{
    pb_ensure_schema($pdo);

    $hdr = $pdo->prepare("
        SELECT i.*,
               u.full_name AS patient_name, u.student_personnel_id AS patient_code,
               u.phone_number AS patient_phone, u.citizen_id AS patient_citizen,
               e.encounter_no, e.visit_date, e.provider_id, e.diagnosis,
               s.full_name AS provider_name
        FROM sys_billing_invoices i
        LEFT JOIN sys_users u ON u.id = i.patient_id
        LEFT JOIN sys_billing_encounters e ON e.id = i.encounter_id
        LEFT JOIN sys_staff s ON s.id = e.provider_id
        WHERE i.id = :id
    ");
    $hdr->execute([':id' => $invoiceId]);
    $inv = $hdr->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return null;

    // Items from the linked encounter (snapshot)
    $items = [];
    if (!empty($inv['encounter_id'])) {
        $st = $pdo->prepare("
            SELECT id, service_code, service_name, quantity, unit_price, discount, line_total, note
            FROM sys_billing_encounter_items
            WHERE encounter_id = :eid
            ORDER BY id ASC
        ");
        $st->execute([':eid' => (int)$inv['encounter_id']]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $inv['items'] = $items;

    $pay = $pdo->prepare("
        SELECT id, payment_date, amount, method, reference, note, finance_txn_id, created_at
        FROM sys_billing_payments
        WHERE invoice_id = :id
        ORDER BY payment_date ASC, id ASC
    ");
    $pay->execute([':id' => $invoiceId]);
    $inv['payments'] = $pay->fetchAll(PDO::FETCH_ASSOC);

    return $inv;
}

// ─────────────────────────────────────────────────────────────────────────────
// AR Aging Report (Phase 1D)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Standard 4-bucket aging: 0-30 / 31-60 / 61-90 / 90+ days.
 * Age is measured from due_date (or issue_date if no due_date) → today.
 * Only includes invoices with outstanding balance (issued, partially_paid, overdue).
 * Excludes 'paid' and 'void' rows.
 *
 * @return array{
 *     buckets: array<string, array{count:int, total:float}>,
 *     summary: array{count:int, total:float, avg_age_days:float, oldest_age_days:int, oldest_invoice_no:?string}
 * }
 */
function pb_ar_aging_summary(PDO $pdo, ?string $payerType = null): array
{
    pb_ensure_schema($pdo);

    $where = ["i.status IN ('issued','partially_paid','overdue')",
              "(i.total - i.paid_amount) > 0.005"];
    $params = [];
    if ($payerType && isset(PB_PAYER_TYPES[$payerType])) {
        $where[]              = 'i.payer_type = :ptype';
        $params[':ptype']     = $payerType;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // One pass over outstanding invoices computing both buckets + summary stats
    $sql = "SELECT
              DATEDIFF(CURDATE(), COALESCE(i.due_date, i.issue_date)) AS age_days,
              (i.total - i.paid_amount) AS balance,
              i.invoice_no
            FROM sys_billing_invoices i
            {$whereSql}";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->execute();

    $buckets = [
        '0-30'  => ['count' => 0, 'total' => 0.0],
        '31-60' => ['count' => 0, 'total' => 0.0],
        '61-90' => ['count' => 0, 'total' => 0.0],
        '90+'   => ['count' => 0, 'total' => 0.0],
    ];
    $count = 0; $total = 0.0; $ageSum = 0; $oldest = -1; $oldestNo = null;

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $age = max(0, (int)$row['age_days']);
        $bal = (float)$row['balance'];
        $bucket = $age <= 30 ? '0-30'
                : ($age <= 60 ? '31-60'
                : ($age <= 90 ? '61-90' : '90+'));
        $buckets[$bucket]['count']++;
        $buckets[$bucket]['total'] += $bal;

        $count++;
        $total  += $bal;
        $ageSum += $age;
        if ($age > $oldest) { $oldest = $age; $oldestNo = $row['invoice_no']; }
    }

    return [
        'buckets' => $buckets,
        'summary' => [
            'count'             => $count,
            'total'             => $total,
            'avg_age_days'      => $count > 0 ? round($ageSum / $count, 1) : 0.0,
            'oldest_age_days'   => $oldest > 0 ? $oldest : 0,
            'oldest_invoice_no' => $oldestNo,
        ],
    ];
}

/**
 * Top patients by outstanding balance. Caps at $limit rows.
 */
function pb_ar_aging_top_patients(PDO $pdo, int $limit = 10, ?string $payerType = null): array
{
    pb_ensure_schema($pdo);

    $where = ["i.status IN ('issued','partially_paid','overdue')",
              "(i.total - i.paid_amount) > 0.005"];
    $params = [];
    if ($payerType && isset(PB_PAYER_TYPES[$payerType])) {
        $where[]              = 'i.payer_type = :ptype';
        $params[':ptype']     = $payerType;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT
              i.patient_id,
              u.full_name AS patient_name,
              u.student_personnel_id AS patient_code,
              u.phone_number AS patient_phone,
              COUNT(*) AS invoice_count,
              SUM(i.total - i.paid_amount) AS balance,
              MAX(DATEDIFF(CURDATE(), COALESCE(i.due_date, i.issue_date))) AS max_age_days
            FROM sys_billing_invoices i
            LEFT JOIN sys_users u ON u.id = i.patient_id
            {$whereSql}
            GROUP BY i.patient_id, u.full_name, u.student_personnel_id, u.phone_number
            ORDER BY balance DESC
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Invoices inside a specific aging bucket — for drill-down view.
 * $bucket = '0-30' | '31-60' | '61-90' | '90+' | 'all'
 */
function pb_ar_aging_detail(PDO $pdo, string $bucket, ?string $payerType = null, int $limit = 200): array
{
    pb_ensure_schema($pdo);

    $where = ["i.status IN ('issued','partially_paid','overdue')",
              "(i.total - i.paid_amount) > 0.005"];
    $params = [];
    if ($payerType && isset(PB_PAYER_TYPES[$payerType])) {
        $where[]              = 'i.payer_type = :ptype';
        $params[':ptype']     = $payerType;
    }
    switch ($bucket) {
        case '0-30':
            $where[] = 'DATEDIFF(CURDATE(), COALESCE(i.due_date, i.issue_date)) BETWEEN 0 AND 30';
            break;
        case '31-60':
            $where[] = 'DATEDIFF(CURDATE(), COALESCE(i.due_date, i.issue_date)) BETWEEN 31 AND 60';
            break;
        case '61-90':
            $where[] = 'DATEDIFF(CURDATE(), COALESCE(i.due_date, i.issue_date)) BETWEEN 61 AND 90';
            break;
        case '90+':
            $where[] = 'DATEDIFF(CURDATE(), COALESCE(i.due_date, i.issue_date)) > 90';
            break;
        case 'all':
        default:
            // no extra filter
            break;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "SELECT
              i.id, i.invoice_no, i.patient_id, i.issue_date, i.due_date,
              i.payer_type, i.total, i.paid_amount,
              (i.total - i.paid_amount) AS balance,
              DATEDIFF(CURDATE(), COALESCE(i.due_date, i.issue_date)) AS age_days,
              i.status,
              u.full_name AS patient_name,
              u.student_personnel_id AS patient_code,
              u.phone_number AS patient_phone
            FROM sys_billing_invoices i
            LEFT JOIN sys_users u ON u.id = i.patient_id
            {$whereSql}
            ORDER BY age_days DESC, balance DESC
            LIMIT :lim";
    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Record a payment against an invoice, atomically syncing it to Cash Book.
 *
 * Consistency model: if Cash Book sync fails, the payment is rolled back too —
 * we never want a payment row that's invisible to the finance dashboard.
 *
 * @return array{ok:bool, payment_id?:int, finance_txn_id?:int, error?:string}
 */
function pb_record_payment(PDO $pdo, int $invoiceId, array $payload, ?int $adminId = null, string $adminName = ''): array
{
    pb_ensure_schema($pdo);

    $amount = (float)($payload['amount'] ?? 0);
    if ($amount <= 0)         return ['ok' => false, 'error' => 'จำนวนเงินต้อง > 0'];
    if ($amount > 9999999.99) return ['ok' => false, 'error' => 'จำนวนเงินเกินขีดจำกัด'];

    $method = (string)($payload['method'] ?? 'cash');
    if (!isset(PB_PAYMENT_METHODS[$method])) {
        return ['ok' => false, 'error' => 'วิธีชำระไม่ถูกต้อง'];
    }

    $payDate = trim((string)($payload['payment_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
        return ['ok' => false, 'error' => 'รูปแบบวันที่ไม่ถูกต้อง'];
    }

    $reference = trim((string)($payload['reference'] ?? '')) ?: null;
    $note      = trim((string)($payload['note']      ?? '')) ?: null;

    $pdo->beginTransaction();
    try {
        // Lock invoice
        $inv = $pdo->prepare("SELECT i.id, i.invoice_no, i.total, i.paid_amount, i.status,
                                     u.full_name AS patient_name
                              FROM sys_billing_invoices i
                              LEFT JOIN sys_users u ON u.id = i.patient_id
                              WHERE i.id = :id FOR UPDATE");
        $inv->execute([':id' => $invoiceId]);
        $i = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$i) { $pdo->rollBack(); return ['ok' => false, 'error' => 'ไม่พบใบแจ้งหนี้']; }
        if (in_array($i['status'], ['void', 'draft'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => "ใบแจ้งหนี้สถานะ '{$i['status']}' รับชำระไม่ได้"];
        }

        $remaining = (float)$i['total'] - (float)$i['paid_amount'];
        if ($amount > $remaining + 0.005) {  // tiny epsilon for float comparison
            $pdo->rollBack();
            return ['ok' => false, 'error' => sprintf(
                'จำนวนเงินเกินยอดคงค้าง (เหลือ %s ฿)',
                number_format($remaining, 2)
            )];
        }

        // Insert payment (finance_txn_id NULL initially, fill after sync)
        $insPay = $pdo->prepare("INSERT INTO sys_billing_payments
            (invoice_id, payment_date, amount, method, reference, note, created_by)
            VALUES (:iid, :date, :amt, :method, :ref, :note, :by)");
        $insPay->execute([
            ':iid'    => $invoiceId,
            ':date'   => $payDate,
            ':amt'    => $amount,
            ':method' => $method,
            ':ref'    => $reference,
            ':note'   => $note,
            ':by'     => $adminId ?: null,
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        // Update invoice totals + status
        $newPaid = (float)$i['paid_amount'] + $amount;
        if (abs($newPaid - (float)$i['total']) < 0.005) {
            $newStatus = 'paid';
        } elseif ($newPaid > 0) {
            $newStatus = 'partially_paid';
        } else {
            $newStatus = $i['status'];
        }
        $pdo->prepare("UPDATE sys_billing_invoices
                       SET paid_amount = :paid, status = :status
                       WHERE id = :id")
            ->execute([
                ':paid'   => $newPaid,
                ':status' => $newStatus,
                ':id'     => $invoiceId,
            ]);

        // Sync to Cash Book — fails rolled back via outer catch
        require_once __DIR__ . '/finance_sync_helper.php';
        $desc = sprintf('ค่าบริการคลินิก %s%s',
            $i['invoice_no'],
            !empty($i['patient_name']) ? ' · ' . $i['patient_name'] : ''
        );
        $sync = finance_sync_upsert($pdo, [
            'source_module'  => 'billing_payment',
            'source_id'      => (string)$paymentId,
            'kind'           => 'income',
            'amount'         => $amount,
            'txn_date'       => $payDate,
            'category_name'  => PB_DEFAULT_INCOME_CATEGORY,
            'description'    => $desc,
            'reference'      => $i['invoice_no'],
            'note'           => $note,
            'admin_id'       => $adminId,
        ]);
        if (!$sync['ok']) {
            // Hard fail — don't leave an orphan payment
            throw new RuntimeException('ส่งเข้า Cash Book ไม่ได้: ' . ($sync['error'] ?? 'unknown'));
        }
        $txnId = (int)$sync['id'];

        // Link payment ← txn
        $pdo->prepare("UPDATE sys_billing_payments
                       SET finance_txn_id = :txn WHERE id = :id")
            ->execute([':txn' => $txnId, ':id' => $paymentId]);

        $pdo->commit();
        return ['ok' => true, 'payment_id' => $paymentId, 'finance_txn_id' => $txnId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
