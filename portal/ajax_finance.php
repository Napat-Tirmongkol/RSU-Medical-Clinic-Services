<?php
// portal/ajax_finance.php — Finance module (cash book) AJAX
// Entities: txn (income/expense rows), category (CRUD)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/finance_link.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper = ($adminRole === 'superadmin');
$canFinance = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_finance']);
if (!$canFinance) { echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้โมดูลการเงิน']); exit; }

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'system');

/**
 * Append an audit row. Cheap append-only; never throws to caller —
 * audit failure should never block the finance write. When the audit
 * INSERT fails we re-log with an ALERT prefix + the lost payload so
 * ops can still reconstruct what happened from the server error log.
 */
function fin_audit_log(PDO $pdo, int $txnId, string $action, ?array $changes, ?int $adminId, string $adminName): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO sys_finance_audit
            (txn_id, action, changes_json, performed_by, performed_by_name, ip_addr)
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $txnId,
            $action,
            $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            $adminId ?: null,
            $adminName,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // Compliance-critical: capture the full audit payload in the server
        // log so a DBA can still reconstruct who-did-what after the fact.
        $payload = json_encode([
            'txn_id' => $txnId, 'action' => $action, 'changes' => $changes,
            'by_id'  => $adminId, 'by_name' => $adminName,
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'ts'     => date('c'),
        ], JSON_UNESCAPED_UNICODE);
        error_log('[fin_audit_log][ALERT][AUDIT_LOST] ' . $e->getMessage() . ' payload=' . $payload);
    }
}

/**
 * Validate a money amount string the way MySQL DECIMAL(12,2) expects it.
 * Returns the string for direct binding (avoids (float) precision loss
 * on values like 0.1 + 0.2). Returns null when the input is malformed.
 *
 * Accepts: 1234, 1234.5, 1234.56  (max 10 digits before decimal, 0-2 after)
 * Rejects: -10, 1e3, scientific notation, blanks, anything with a comma.
 */
function fin_parse_amount(mixed $raw): ?string
{
    $s = is_string($raw) ? trim($raw) : (string)$raw;
    if ($s === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $s)) return null;
    if ((float)$s <= 0) return null;
    return $s;
}

/**
 * Drop a deny-all .htaccess and an empty index.html into the given dir.
 * Idempotent — skips files that already exist. Belt-and-braces against:
 *  - direct URL access to a receipt (Apache should refuse)
 *  - Apache misconfig that would otherwise execute uploaded .php
 *  - directory listing of the uploads tree
 * Note: .htaccess is a no-op under Nginx. The real defense for Nginx is
 * to keep all attachment access behind finance_attachment.php; the deny
 * file is still useful on Apache and as a defense-in-depth marker.
 */
function fin_harden_upload_dir(string $absDir): void
{
    if (!is_dir($absDir)) return;
    $ht = $absDir . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "# Deny direct access — finance attachments are sensitive.\n" .
            "# All access must go through finance_attachment.php (auth-gated).\n" .
            "Order deny,allow\nDeny from all\n# Apache 2.4+\nRequire all denied\n\n" .
            "# Defence-in-depth: block any kind of script execution even if\n" .
            "# Apache is misconfigured to hand uploaded files to a PHP handler.\n" .
            "RemoveHandler .php .php3 .php4 .php5 .php7 .phtml .phps\n" .
            "RemoveType .php .php3 .php4 .php5 .php7 .phtml .phps\n" .
            "AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phps\n" .
            "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n" .
            "<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n" .
            "Options -Indexes -ExecCGI\n"
        );
    }
    $idx = $absDir . '/index.html';
    if (!file_exists($idx)) @file_put_contents($idx, '');
}

/**
 * Auto-generate any due recurring rules for the current month.
 * Idempotent: each rule has last_generated_ym; once set to YYYY-MM
 * for the current month, we don't generate again. Inserts into
 * sys_finance_transactions and stamps source_module=finance_recurring.
 *
 * Runs every time the user opens the page (cheap — selects + maybe a
 * few inserts).
 */
function fin_run_recurring(PDO $pdo, ?int $adminId, string $adminName): int
{
    $ym = date('Y-m');
    $today = (int)date('j');
    try {
        $stmt = $pdo->prepare("SELECT * FROM sys_finance_recurring
            WHERE active = 1
              AND (last_generated_ym IS NULL OR last_generated_ym <> ?)
              AND day_of_month <= ?");
        $stmt->execute([$ym, $today]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $created = 0;
        foreach ($rules as $rule) {
            // Use the planned day; clamp to last day of month if > month length
            $day = max(1, min((int)$rule['day_of_month'], (int)date('t')));
            $txnDate = date('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
            $ins = $pdo->prepare("INSERT INTO sys_finance_transactions
                (txn_date, kind, category_id, amount, description, payment_method,
                 source_module, source_id, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, 'finance_recurring', ?, ?, ?)");
            $ins->execute([
                $txnDate,
                $rule['kind'],
                $rule['category_id'] ?: null,
                $rule['amount'],
                $rule['description'] ?: $rule['name'],
                $rule['payment_method'] ?: null,
                (string)$rule['id'] . ':' . $ym,
                $adminId ?: null,
                $adminId ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            fin_audit_log($pdo, $newId, 'recurring_generate', [
                'rule_id'   => (int)$rule['id'],
                'rule_name' => $rule['name'],
                'amount'    => (float)$rule['amount'],
            ], $adminId, $adminName);

            $pdo->prepare("UPDATE sys_finance_recurring SET last_generated_ym=? WHERE id=?")
                ->execute([$ym, $rule['id']]);
            $created++;
        }
        return $created;
    } catch (Throwable $e) {
        error_log('[fin_run_recurring] ' . $e->getMessage());
        return 0;
    }
}

// ── Self-heal schema ─────────────────────────────────────────────────────
function ensure_finance_schema(PDO $pdo): void {
    static $done = false; if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            kind ENUM('income','expense') NOT NULL,
            icon VARCHAR(50) NULL, color VARCHAR(20) DEFAULT '#64748b',
            sort_order INT DEFAULT 0, is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_name_kind (name, kind),
            KEY idx_kind_active (kind, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            txn_date DATE NOT NULL,
            kind ENUM('income','expense') NOT NULL,
            category_id INT UNSIGNED NULL,
            amount DECIMAL(12,2) NOT NULL,
            description VARCHAR(500) NULL,
            reference VARCHAR(100) NULL,
            payment_method VARCHAR(50) NULL,
            note TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by INT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_date_kind (txn_date, kind), KEY idx_category (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Self-heal: เพิ่ม columns ใหม่สำหรับ receipt + cross-module sourcing
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD COLUMN receipt_no VARCHAR(30) NULL UNIQUE"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD COLUMN source_module VARCHAR(50) NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD COLUMN source_id VARCHAR(50) NULL"); } catch (PDOException) {}
        // Self-heal: เปลี่ยน source_id จาก BIGINT → VARCHAR(50) เพื่อรองรับ
        // string IDs เช่น 'TS-S6-256905' จาก nurse_timesheet (ก่อนหน้าใช้ BIGINT
        // แล้ว INSERT พังเพราะ "Incorrect integer value")
        try { $pdo->exec("ALTER TABLE sys_finance_transactions MODIFY COLUMN source_id VARCHAR(50) NULL"); } catch (PDOException) {}
        try { $pdo->exec("ALTER TABLE sys_finance_transactions ADD INDEX idx_source (source_module, source_id)"); } catch (PDOException) {}
        // First-run seed (idempotent via INSERT IGNORE)
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM sys_finance_categories")->fetchColumn();
        if ($cnt === 0) {
            $defaults = [
                ['ค่ารักษาผู้ป่วย','income','fa-stethoscope','#0ea5e9',1],
                ['ค่ายา/วัสดุ','income','fa-pills','#10b981',2],
                ['ค่าตรวจ Lab','income','fa-vial','#a855f7',3],
                ['Claim ประกัน','income','fa-shield-halved','#3b82f6',4],
                ['รายรับอื่นๆ','income','fa-circle-plus','#64748b',9],
                ['จัดซื้อวัสดุการแพทย์','expense','fa-syringe','#dc2626',1],
                ['จัดซื้อครุภัณฑ์','expense','fa-boxes-stacked','#d97706',2],
                ['เงินเดือน/ค่าจ้าง','expense','fa-user-tie','#6366f1',3],
                ['ค่าสาธารณูปโภค','expense','fa-bolt','#eab308',4],
                ['ค่าซ่อมบำรุง','expense','fa-wrench','#64748b',5],
                ['รายจ่ายอื่นๆ','expense','fa-circle-minus','#64748b',9],
            ];
            $ins = $pdo->prepare("INSERT IGNORE INTO sys_finance_categories (name,kind,icon,color,sort_order) VALUES (?,?,?,?,?)");
            foreach ($defaults as $d) $ins->execute($d);
        }

        // ── Phase C tables ───────────────────────────────────────────
        // Recurring transaction templates (ค่าน้ำ ค่าไฟ ค่าเช่า ฯลฯ)
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_recurring (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            kind ENUM('income','expense') NOT NULL,
            category_id INT UNSIGNED NULL,
            amount DECIMAL(12,2) NOT NULL,
            description VARCHAR(500) NULL,
            payment_method VARCHAR(50) NULL,
            day_of_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
            active TINYINT(1) DEFAULT 1,
            last_generated_ym VARCHAR(7) NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_active (active),
            KEY idx_kind (kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Receipt attachments
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_attachments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            txn_id INT UNSIGNED NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NULL,
            mime_type VARCHAR(100) NULL,
            size_bytes INT UNSIGNED DEFAULT 0,
            uploaded_by INT UNSIGNED NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_txn (txn_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Audit log per transaction
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_finance_audit (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            txn_id INT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            changes_json TEXT NULL,
            performed_by INT UNSIGNED NULL,
            performed_by_name VARCHAR(120) NULL,
            performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_addr VARCHAR(45) NULL,
            KEY idx_txn_time (txn_id, performed_at),
            KEY idx_time (performed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) { error_log('[finance] schema: ' . $e->getMessage()); }

    // Proactively harden the upload root so direct URL access is blocked
    // even before any file has been uploaded (used to be lazy on first
    // upload — that left a window where the dir was world-readable).
    $financeRoot = __DIR__ . '/../uploads/finance';
    if (!is_dir($financeRoot)) @mkdir($financeRoot, 0775, true);
    fin_harden_upload_dir($financeRoot);

    $done = true;
}
ensure_finance_schema($pdo);

// Resolve action from method-specific superglobals (avoid $_REQUEST so
// cookies and unexpected param sources can't influence routing)
$action = ($_SERVER['REQUEST_METHOD'] === 'POST')
    ? (string)($_POST['action'] ?? '')
    : (string)($_GET['action'] ?? '');

try {
    // ── Analytics: monthly trend + category breakdown + period delta ──────
    // GET allowed. Inputs: from, to, kind, category_id, q (current filter)
    // Returns:
    //   monthly:    last 12 months trailing today {month, income, expense, net, count}
    //   categories: aggregate by category within the current filter range, split by kind
    //   delta:      {income, expense, net} prior period (same length immediately before "from")
    if ($action === 'analytics') {
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-t');
        $kind = $_GET['kind'] ?? '';
        $catId = (int)($_GET['category_id'] ?? 0);
        $q    = trim((string)($_GET['q'] ?? ''));

        // 1) Monthly trend — fixed 12 months trailing today (independent of filter
        //    so users always see the long-term trend)
        $monthStart = date('Y-m-01', strtotime('-11 months'));
        $monthEnd   = date('Y-m-t');
        $mStmt = $pdo->prepare("
            SELECT DATE_FORMAT(txn_date, '%Y-%m') AS ym,
                   SUM(CASE WHEN kind='income'  THEN amount ELSE 0 END) AS income,
                   SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END) AS expense,
                   COUNT(*) AS count
            FROM sys_finance_transactions
            WHERE txn_date BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(txn_date, '%Y-%m')
            ORDER BY ym ASC");
        $mStmt->execute([$monthStart, $monthEnd]);
        $byMonth = [];
        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $byMonth[$r['ym']] = $r;
        // Fill every month, even empty ones, so the chart has 12 evenly-spaced bars
        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} months"));
            $r = $byMonth[$ym] ?? null;
            $monthly[] = [
                'month'   => $ym,
                'income'  => (float)($r['income']  ?? 0),
                'expense' => (float)($r['expense'] ?? 0),
                'net'     => (float)($r['income']  ?? 0) - (float)($r['expense'] ?? 0),
                'count'   => (int)  ($r['count']   ?? 0),
            ];
        }

        // 2) Category breakdown — within the user's current filter
        $where  = ['t.txn_date BETWEEN :from AND :to'];
        $params = [':from' => $from, ':to' => $to];
        if ($kind === 'income' || $kind === 'expense') { $where[] = 't.kind = :k'; $params[':k'] = $kind; }
        if ($catId > 0) { $where[] = 't.category_id = :cid'; $params[':cid'] = $catId; }
        if ($q !== '') {
            $where[] = '(t.description LIKE :q OR t.reference LIKE :q OR t.receipt_no LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $cStmt = $pdo->prepare("
            SELECT t.category_id, t.kind, COALESCE(c.name, '(ไม่ระบุหมวด)') AS name,
                   c.icon, c.color,
                   SUM(t.amount) AS total, COUNT(*) AS count
            FROM sys_finance_transactions t
            LEFT JOIN sys_finance_categories c ON c.id = t.category_id
            {$whereSql}
            GROUP BY t.category_id, t.kind, c.name, c.icon, c.color
            ORDER BY total DESC");
        $cStmt->execute($params);
        $categories = [];
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $categories[] = [
                'category_id' => $r['category_id'] ? (int)$r['category_id'] : null,
                'kind'  => $r['kind'],
                'name'  => $r['name'],
                'icon'  => $r['icon']  ?: 'fa-circle',
                'color' => $r['color'] ?: '#64748b',
                'total' => (float)$r['total'],
                'count' => (int)$r['count'],
            ];
        }

        // 3) Period delta — compare current (from..to) to the same length immediately
        //    before. e.g. May 1-31 → April 1-30.
        $fromTs = strtotime($from); $toTs = strtotime($to);
        $lenSec = $toTs - $fromTs;                 // seconds in current window
        $prevTo = date('Y-m-d', $fromTs - 86400);  // day before current "from"
        $prevFrom = date('Y-m-d', $fromTs - 86400 - $lenSec);
        $dStmt = $pdo->prepare("
            SELECT SUM(CASE WHEN kind='income'  THEN amount ELSE 0 END) AS income,
                   SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END) AS expense
            FROM sys_finance_transactions
            WHERE txn_date BETWEEN ? AND ?");
        $dStmt->execute([$prevFrom, $prevTo]);
        $prev = $dStmt->fetch(PDO::FETCH_ASSOC) ?: ['income' => 0, 'expense' => 0];

        echo json_encode([
            'ok' => true,
            'monthly'    => $monthly,
            'categories' => $categories,
            'delta'      => [
                'prev_from'    => $prevFrom,
                'prev_to'      => $prevTo,
                'income_prev'  => (float)$prev['income'],
                'expense_prev' => (float)$prev['expense'],
                'net_prev'     => (float)$prev['income'] - (float)$prev['expense'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── List + summary (GET allowed) ───────────────────────────────────────
    if ($action === 'list') {
        // Auto-generate due recurring rules for current month before listing
        fin_run_recurring($pdo, $adminId, $adminName);

        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-t');
        $kind = $_GET['kind'] ?? '';
        $catId = (int)($_GET['category_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $q   = trim((string)($_GET['q'] ?? ''));

        $where = ['t.txn_date BETWEEN :from AND :to'];
        $params = [':from' => $from, ':to' => $to];
        if ($kind === 'income' || $kind === 'expense') { $where[] = 't.kind = :k'; $params[':k'] = $kind; }
        if ($catId > 0) { $where[] = 't.category_id = :cid'; $params[':cid'] = $catId; }
        if ($q !== '') {
            // ค้นใน description / reference / receipt_no — ใช้ LIKE บน 3 column แต่ bind ค่าเดียวกัน
            $where[] = '(t.description LIKE :q OR t.reference LIKE :q OR t.receipt_no LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_finance_transactions t {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT t.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
            (SELECT COUNT(*) FROM sys_finance_attachments WHERE txn_id = t.id) AS attachment_count
            FROM sys_finance_transactions t LEFT JOIN sys_finance_categories c ON c.id = t.category_id
            {$whereSql} ORDER BY t.txn_date DESC, t.id DESC LIMIT {$perPage} OFFSET {$offset}");
        // หมายเหตุ: t.* รวม receipt_no, source_module, source_id ที่เพิ่งเพิ่มแล้ว
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Sign each row's id so the print link in the partial cannot be
        // enumerated by editing ?id=N in the address bar.
        foreach ($rows as &$r) { $r['receipt_sig'] = fin_receipt_sig((int)$r['id']); }
        unset($r);

        // Summary in same date range (ignore kind/category filter for summary totals)
        $sumStmt = $pdo->prepare("SELECT
            SUM(CASE WHEN kind='income'  THEN amount ELSE 0 END) AS total_income,
            SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END) AS total_expense,
            COUNT(*) AS count_all
            FROM sys_finance_transactions WHERE txn_date BETWEEN :from AND :to");
        $sumStmt->execute([':from' => $from, ':to' => $to]);
        $sum = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $cats = $pdo->query("SELECT id, name, kind, icon, color FROM sys_finance_categories WHERE is_active=1 ORDER BY kind, sort_order, name")
            ->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'summary' => [
                'income'  => (float)($sum['total_income']  ?? 0),
                'expense' => (float)($sum['total_expense'] ?? 0),
                'net'     => (float)($sum['total_income'] ?? 0) - (float)($sum['total_expense'] ?? 0),
                'count'   => (int)($sum['count_all'] ?? 0),
            ],
            'categories' => $cats,
            'filters' => ['from' => $from, 'to' => $to, 'kind' => $kind, 'category_id' => $catId],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Below actions require POST + CSRF
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'message' => 'POST only']); exit; }
    validate_csrf_or_die();

    if ($action === 'txn:create' || $action === 'txn:update') {
        $isCreate = $action === 'txn:create';
        $id = (int)($_POST['id'] ?? 0);
        $txnDate = trim((string)($_POST['txn_date'] ?? ''));
        $kindIn = (string)($_POST['kind'] ?? '');
        if ($kindIn !== 'income' && $kindIn !== 'expense') {
            echo json_encode(['ok' => false, 'message' => 'ต้องระบุประเภท (รายรับ/รายจ่าย)']); exit;
        }
        $kind = $kindIn;
        $catId = (int)($_POST['category_id'] ?? 0) ?: null;
        $amount = fin_parse_amount($_POST['amount'] ?? '');
        if ($amount === null) { echo json_encode(['ok' => false, 'message' => 'จำนวนเงินไม่ถูกต้อง (ต้องเป็นตัวเลข > 0, ทศนิยมไม่เกิน 2)']); exit; }
        $desc = trim((string)($_POST['description'] ?? ''));
        $ref = trim((string)($_POST['reference'] ?? ''));
        $pay = trim((string)($_POST['payment_method'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if (!$txnDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) { echo json_encode(['ok' => false, 'message' => 'รูปแบบวันที่ไม่ถูกต้อง']); exit; }

        if ($isCreate) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO sys_finance_transactions
                    (txn_date, kind, category_id, amount, description, reference, payment_method, note, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $ref ?: null, $pay ?: null, $note ?: null, $adminId ?: null, $adminId ?: null]);
                $newId = (int)$pdo->lastInsertId();
                fin_audit_log($pdo, $newId, 'create', [
                    'txn_date' => $txnDate, 'kind' => $kind, 'category_id' => $catId,
                    'amount' => $amount, 'description' => $desc, 'reference' => $ref,
                ], $adminId, $adminName);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            echo json_encode(['ok' => true, 'id' => $newId]); exit;
        } else {
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
            $pdo->beginTransaction();
            try {
                // Capture before-state so the audit can diff later
                $beforeStmt = $pdo->prepare("SELECT txn_date, kind, category_id, amount, description, reference, payment_method, note FROM sys_finance_transactions WHERE id=? FOR UPDATE");
                $beforeStmt->execute([$id]);
                $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $stmt = $pdo->prepare("UPDATE sys_finance_transactions SET txn_date=?, kind=?, category_id=?, amount=?, description=?, reference=?, payment_method=?, note=?, updated_by=? WHERE id=?");
                $stmt->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $ref ?: null, $pay ?: null, $note ?: null, $adminId ?: null, $id]);

                $after = ['txn_date' => $txnDate, 'kind' => $kind, 'category_id' => $catId,
                          'amount' => $amount, 'description' => $desc, 'reference' => $ref,
                          'payment_method' => $pay, 'note' => $note];
                $changes = [];
                foreach ($after as $k => $v) {
                    $oldV = $before[$k] ?? null;
                    if ((string)$oldV !== (string)$v) $changes[$k] = ['from' => $oldV, 'to' => $v];
                }
                if (!empty($changes)) fin_audit_log($pdo, $id, 'update', $changes, $adminId, $adminName);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            echo json_encode(['ok' => true]); exit;
        }
    }

    if ($action === 'txn:delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
        $pdo->beginTransaction();
        try {
            // Snapshot before destroy so the audit row keeps a permanent record
            $snap = $pdo->prepare("SELECT txn_date, kind, amount, description, reference FROM sys_finance_transactions WHERE id=? FOR UPDATE");
            $snap->execute([$id]);
            $row = $snap->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->prepare("DELETE FROM sys_finance_transactions WHERE id=?")->execute([$id]);
            fin_audit_log($pdo, $id, 'delete', $row, $adminId, $adminName);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        echo json_encode(['ok' => true]); exit;
    }

    // Bulk delete — accepts ids[] array
    if ($action === 'txn:bulk_delete') {
        $rawIds = $_POST['ids'] ?? [];
        if (!is_array($rawIds) || empty($rawIds)) {
            echo json_encode(['ok' => false, 'message' => 'ไม่มี id ที่จะลบ']); exit;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($v) => $v > 0)));
        if (empty($ids)) { echo json_encode(['ok' => false, 'message' => 'id ไม่ถูกต้อง']); exit; }
        if (count($ids) > 500) { echo json_encode(['ok' => false, 'message' => 'ลบครั้งละไม่เกิน 500 รายการ']); exit; }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->beginTransaction();
        try {
            // Snapshot each row before delete so audit retains a record
            $snapStmt = $pdo->prepare("SELECT id, txn_date, kind, amount, description, reference FROM sys_finance_transactions WHERE id IN ({$placeholders}) FOR UPDATE");
            $snapStmt->execute($ids);
            $snaps = [];
            foreach ($snapStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $snaps[(int)$r['id']] = $r;

            $stmt = $pdo->prepare("DELETE FROM sys_finance_transactions WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deleted = $stmt->rowCount();
            foreach ($ids as $delId) {
                fin_audit_log($pdo, $delId, 'bulk_delete', $snaps[$delId] ?? null, $adminId, $adminName);
            }
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        echo json_encode(['ok' => true, 'deleted' => $deleted]); exit;
    }

    // Duplicate-detection probe — non-destructive lookup before user confirms create
    // Inputs: reference, txn_date (optional, narrows match), kind (optional), exclude_id (for edit)
    if ($action === 'txn:check_duplicate') {
        $ref     = trim((string)($_POST['reference'] ?? ''));
        $txnDate = trim((string)($_POST['txn_date']  ?? ''));
        $kindIn  = (string)($_POST['kind']           ?? '');
        $exclude = (int)($_POST['exclude_id']        ?? 0);
        if ($ref === '') { echo json_encode(['ok' => true, 'duplicates' => []]); exit; }

        $where  = ['LOWER(reference) = LOWER(?)'];
        $params = [$ref];
        if ($kindIn === 'income' || $kindIn === 'expense') {
            $where[] = 'kind = ?';
            $params[] = $kindIn;
        }
        if ($exclude > 0) {
            $where[] = 'id <> ?';
            $params[] = $exclude;
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $stmt = $pdo->prepare("SELECT id, txn_date, kind, amount, description, reference
            FROM sys_finance_transactions {$whereSql}
            ORDER BY txn_date DESC, id DESC LIMIT 5");
        $stmt->execute($params);
        $dups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'duplicates' => $dups], JSON_UNESCAPED_UNICODE); exit;
    }

    // ── Upsert from source module (cross-module integration) ──
    // Inputs: source_module, source_id (string), kind, amount, txn_date, description, category_name
    // ถ้ามี record ที่ (source_module, source_id) อยู่แล้ว → UPDATE; ไม่งั้น INSERT
    if ($action === 'txn:upsert_from_source') {
        // Whitelist source_module — ห้าม spoof จาก client เพื่อ overwrite record
        // ของโมดูลอื่น (เช่น เปลี่ยน OT พยาบาลเป็น 1 บาท)
        $ALLOWED_SOURCES = [
            'nurse_schedule', 'scholarship', 'asset',
            'consumables_txn', 'eborrow_payment', 'finance_recurring',
        ];
        $srcMod = trim((string)($_POST['source_module'] ?? ''));
        $srcId  = trim((string)($_POST['source_id'] ?? ''));
        if (!in_array($srcMod, $ALLOWED_SOURCES, true)) {
            echo json_encode(['ok' => false, 'message' => 'source_module ไม่ได้รับอนุญาต']); exit;
        }
        $kindIn = (string)($_POST['kind'] ?? '');
        if ($kindIn !== 'income' && $kindIn !== 'expense') {
            echo json_encode(['ok' => false, 'message' => 'ต้องระบุ kind = income หรือ expense']); exit;
        }
        $kind = $kindIn;
        $amount = fin_parse_amount($_POST['amount'] ?? '');
        $txnDate = trim((string)($_POST['txn_date'] ?? date('Y-m-d')));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $catName = trim((string)($_POST['category_name'] ?? ''));
        $note   = trim((string)($_POST['note'] ?? ''));

        if ($srcMod === '' || $srcId === '') { echo json_encode(['ok' => false, 'message' => 'ต้องระบุ source_module + source_id']); exit; }
        if ($amount === null) { echo json_encode(['ok' => false, 'message' => 'จำนวนเงินไม่ถูกต้อง']); exit; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $txnDate)) { echo json_encode(['ok' => false, 'message' => 'รูปแบบวันที่ไม่ถูกต้อง']); exit; }

        // หา category_id จากชื่อ
        $catId = null;
        if ($catName !== '') {
            $cs = $pdo->prepare("SELECT id FROM sys_finance_categories WHERE name=? AND kind=? LIMIT 1");
            $cs->execute([$catName, $kind]);
            $catId = $cs->fetchColumn() ?: null;
        }

        // ตรวจสอบว่ามี record อยู่แล้วไหม
        $ex = $pdo->prepare("SELECT id, txn_date, kind, category_id, amount, description, note
            FROM sys_finance_transactions WHERE source_module=? AND source_id=? LIMIT 1");
        $ex->execute([$srcMod, $srcId]);
        $existing = $ex->fetch(PDO::FETCH_ASSOC);
        $existingId = $existing ? (int)$existing['id'] : 0;

        if ($existingId > 0) {
            // ห้ามเปลี่ยน kind ของ record ที่มีอยู่ — กัน flip income↔expense
            // ผ่าน upsert (อยากเปลี่ยนต้องลบแล้วสร้างใหม่)
            if ((string)$existing['kind'] !== $kind) {
                echo json_encode(['ok' => false, 'message' => 'ห้ามเปลี่ยนประเภท (income/expense) ผ่าน upsert_from_source']); exit;
            }
            $pdo->prepare("UPDATE sys_finance_transactions
                SET txn_date=?, kind=?, category_id=?, amount=?, description=?, note=?, updated_by=?
                WHERE id=?")
                ->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $note ?: null, $adminId ?: null, $existingId]);

            $after = ['txn_date' => $txnDate, 'kind' => $kind, 'category_id' => $catId,
                      'amount' => $amount, 'description' => $desc, 'note' => $note];
            $changes = ['source_module' => $srcMod, 'source_id' => $srcId];
            foreach ($after as $k => $v) {
                $oldV = $existing[$k] ?? null;
                if ((string)$oldV !== (string)$v) $changes[$k] = ['from' => $oldV, 'to' => $v];
            }
            fin_audit_log($pdo, $existingId, 'upsert_from_source:update', $changes, $adminId, $adminName);
            echo json_encode(['ok' => true, 'id' => $existingId, 'mode' => 'updated']); exit;
        } else {
            $pdo->prepare("INSERT INTO sys_finance_transactions
                (txn_date, kind, category_id, amount, description, note, source_module, source_id, created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$txnDate, $kind, $catId, $amount, $desc ?: null, $note ?: null, $srcMod, $srcId, $adminId ?: null, $adminId ?: null]);
            $newId = (int)$pdo->lastInsertId();
            fin_audit_log($pdo, $newId, 'upsert_from_source:create', [
                'source_module' => $srcMod, 'source_id' => $srcId,
                'txn_date' => $txnDate, 'kind' => $kind, 'category_id' => $catId,
                'amount' => $amount, 'description' => $desc,
            ], $adminId, $adminName);
            echo json_encode(['ok' => true, 'id' => $newId, 'mode' => 'created']); exit;
        }
    }

    // ── Assign receipt number (atomic running number per year) ──
    if ($action === 'txn:assign_receipt') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
        // ถ้ามี receipt_no แล้ว → คืนค่าเดิม (อ่านนอก transaction ก่อน — เร็วกว่า)
        $existing = $pdo->prepare("SELECT receipt_no, kind, txn_date FROM sys_finance_transactions WHERE id=?");
        $existing->execute([$id]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false, 'message' => 'ไม่พบรายการ']); exit; }
        if (!empty($row['receipt_no'])) { echo json_encode(['ok' => true, 'receipt_no' => $row['receipt_no']]); exit; }

        $prefix = ($row['kind'] === 'income') ? 'RCP' : 'PV'; // RCP=ใบเสร็จรับ, PV=ใบสำคัญจ่าย
        $yearBE = (int)date('Y', strtotime($row['txn_date'])) + 543;
        $like = $prefix . '-' . $yearBE . '-%';

        // Atomic running-number: lock the rows that match the prefix+year
        // with FOR UPDATE so concurrent assigns can't read the same MAX.
        $pdo->beginTransaction();
        try {
            $maxStmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(receipt_no, " . (strlen($prefix) + 7) . ") AS UNSIGNED)) AS m
                FROM sys_finance_transactions WHERE receipt_no LIKE ? FOR UPDATE");
            $maxStmt->execute([$like]);
            $next = ((int)$maxStmt->fetchColumn()) + 1;
            $receiptNo = sprintf('%s-%d-%06d', $prefix, $yearBE, $next);
            $pdo->prepare("UPDATE sys_finance_transactions SET receipt_no=?, updated_by=? WHERE id=? AND (receipt_no IS NULL OR receipt_no='')")
                ->execute([$receiptNo, $adminId ?: null, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        fin_audit_log($pdo, $id, 'assign_receipt', ['receipt_no' => $receiptNo], $adminId, $adminName);
        echo json_encode(['ok' => true, 'receipt_no' => $receiptNo]); exit;
    }

    // ── Recurring rules ───────────────────────────────────────────
    if ($action === 'recurring:list') {
        // GET allowed for read
        $stmt = $pdo->query("SELECT r.*, c.name AS category_name, c.color AS category_color, c.icon AS category_icon
            FROM sys_finance_recurring r
            LEFT JOIN sys_finance_categories c ON c.id = r.category_id
            ORDER BY r.active DESC, r.day_of_month ASC, r.name ASC");
        echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($action === 'recurring:create' || $action === 'recurring:update') {
        $isCreate = $action === 'recurring:create';
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim((string)($_POST['name'] ?? ''));
        $kindIn = (string)($_POST['kind'] ?? '');
        if ($kindIn !== 'income' && $kindIn !== 'expense') {
            echo json_encode(['ok'=>false,'message'=>'ต้องระบุประเภท (รายรับ/รายจ่าย)']); exit;
        }
        $kind = $kindIn;
        $catId  = (int)($_POST['category_id'] ?? 0) ?: null;
        $amount = fin_parse_amount($_POST['amount'] ?? '');
        $desc   = trim((string)($_POST['description'] ?? ''));
        $pay    = trim((string)($_POST['payment_method'] ?? ''));
        $day    = max(1, min(28, (int)($_POST['day_of_month'] ?? 1))); // cap at 28 to dodge Feb edge cases
        if ($name === '') { echo json_encode(['ok'=>false,'message'=>'ระบุชื่อรายการ']); exit; }
        if ($amount === null) { echo json_encode(['ok'=>false,'message'=>'จำนวนเงินไม่ถูกต้อง']); exit; }

        if ($isCreate) {
            $stmt = $pdo->prepare("INSERT INTO sys_finance_recurring
                (name, kind, category_id, amount, description, payment_method, day_of_month, active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)");
            $stmt->execute([$name, $kind, $catId, $amount, $desc ?: null, $pay ?: null, $day, $adminId ?: null]);
            $newId = (int)$pdo->lastInsertId();
            fin_audit_log($pdo, 0, 'recurring:create', [
                'recurring_id' => $newId, 'name' => $name, 'kind' => $kind,
                'category_id' => $catId, 'amount' => $amount, 'day_of_month' => $day,
            ], $adminId, $adminName);
            echo json_encode(['ok' => true, 'id' => $newId]); exit;
        }
        if ($id <= 0) { echo json_encode(['ok'=>false,'message'=>'ไม่ระบุ id']); exit; }
        // Snapshot before-state for audit diff
        $beforeStmt = $pdo->prepare("SELECT name, kind, category_id, amount, description, payment_method, day_of_month FROM sys_finance_recurring WHERE id=?");
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stmt = $pdo->prepare("UPDATE sys_finance_recurring SET name=?, kind=?, category_id=?, amount=?, description=?, payment_method=?, day_of_month=? WHERE id=?");
        $stmt->execute([$name, $kind, $catId, $amount, $desc ?: null, $pay ?: null, $day, $id]);
        $after = ['name'=>$name,'kind'=>$kind,'category_id'=>$catId,'amount'=>$amount,
                  'description'=>$desc,'payment_method'=>$pay,'day_of_month'=>$day];
        $changes = ['recurring_id' => $id];
        foreach ($after as $k => $v) {
            $oldV = $before[$k] ?? null;
            if ((string)$oldV !== (string)$v) $changes[$k] = ['from' => $oldV, 'to' => $v];
        }
        fin_audit_log($pdo, 0, 'recurring:update', $changes, $adminId, $adminName);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'recurring:toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'message'=>'ไม่ระบุ id']); exit; }
        $pdo->prepare("UPDATE sys_finance_recurring SET active = 1 - active WHERE id=?")->execute([$id]);
        $newState = $pdo->prepare("SELECT active FROM sys_finance_recurring WHERE id=?");
        $newState->execute([$id]);
        $isActive = (int)$newState->fetchColumn();
        fin_audit_log($pdo, 0, 'recurring:toggle', ['recurring_id' => $id, 'active' => $isActive], $adminId, $adminName);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'recurring:delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'message'=>'ไม่ระบุ id']); exit; }
        $snap = $pdo->prepare("SELECT name, kind, category_id, amount, day_of_month FROM sys_finance_recurring WHERE id=?");
        $snap->execute([$id]);
        $row = $snap->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare("DELETE FROM sys_finance_recurring WHERE id=?")->execute([$id]);
        fin_audit_log($pdo, 0, 'recurring:delete', ['recurring_id' => $id] + $row, $adminId, $adminName);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'recurring:run') {
        // Manual force-run — clears last_generated_ym for this rule so the next
        // tick of fin_run_recurring will pick it up.
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'message'=>'ไม่ระบุ id']); exit; }
        $pdo->prepare("UPDATE sys_finance_recurring SET last_generated_ym=NULL WHERE id=?")->execute([$id]);
        $n = fin_run_recurring($pdo, $adminId, $adminName);
        fin_audit_log($pdo, 0, 'recurring:run', ['recurring_id' => $id, 'generated' => $n], $adminId, $adminName);
        echo json_encode(['ok' => true, 'generated' => $n]); exit;
    }

    // ── Audit log (read-only) ─────────────────────────────────────
    if ($action === 'audit:list') {
        $txnId = (int)($_GET['txn_id'] ?? $_POST['txn_id'] ?? 0);
        if ($txnId <= 0) { echo json_encode(['ok'=>false,'message'=>'ไม่ระบุ txn_id']); exit; }
        $stmt = $pdo->prepare("SELECT id, action, changes_json, performed_by, performed_by_name, performed_at, ip_addr
            FROM sys_finance_audit WHERE txn_id=? ORDER BY performed_at DESC, id DESC LIMIT 100");
        $stmt->execute([$txnId]);
        echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
    }

    // ── Attachments ───────────────────────────────────────────────
    if ($action === 'attachment:list') {
        $txnId = (int)($_GET['txn_id'] ?? $_POST['txn_id'] ?? 0);
        if ($txnId <= 0) { echo json_encode(['ok'=>false,'message'=>'ไม่ระบุ txn_id']); exit; }
        $stmt = $pdo->prepare("SELECT id, stored_name, original_name, mime_type, size_bytes, uploaded_at
            FROM sys_finance_attachments WHERE txn_id=? ORDER BY uploaded_at DESC");
        $stmt->execute([$txnId]);
        echo json_encode(['ok' => true, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($action === 'attachment:upload') {
        $txnId = (int)($_POST['txn_id'] ?? 0);
        if ($txnId <= 0) { echo json_encode(['ok'=>false,'message'=>'ไม่ระบุ txn_id']); exit; }
        // Validate txn_id exists — prevents orphan attachments
        $txnEx = $pdo->prepare("SELECT 1 FROM sys_finance_transactions WHERE id=?");
        $txnEx->execute([$txnId]);
        if (!$txnEx->fetchColumn()) { echo json_encode(['ok'=>false,'message'=>'ไม่พบรายการ']); exit; }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok'=>false,'message'=>'อัปโหลดไฟล์ไม่สำเร็จ']); exit;
        }
        $f = $_FILES['file'];
        if ($f['size'] > 8 * 1024 * 1024) { echo json_encode(['ok'=>false,'message'=>'ไฟล์ใหญ่เกิน 8MB']); exit; }
        // Verify MIME + lock extension to canonical form from MIME (defeats
        // polyglot uploads like shell.php.jpg even if Apache handler misconfig)
        $mimeExtMap = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            'application/pdf' => 'pdf',
        ];
        $mime = mime_content_type($f['tmp_name']) ?: $f['type'];
        if (!isset($mimeExtMap[$mime])) {
            echo json_encode(['ok'=>false,'message'=>'รองรับเฉพาะ JPG/PNG/WEBP/GIF/PDF']); exit;
        }
        $userExt = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $canonicalExt = $mimeExtMap[$mime];
        // Allow common alias (jpeg ↔ jpg); otherwise extension must match MIME
        $aliases = ['jpg' => ['jpg','jpeg'], 'png' => ['png'], 'webp' => ['webp'], 'gif' => ['gif'], 'pdf' => ['pdf']];
        if (!in_array($userExt, $aliases[$canonicalExt], true)) {
            echo json_encode(['ok'=>false,'message'=>'นามสกุลไฟล์ไม่ตรงกับชนิดไฟล์']); exit;
        }
        // Sanitize original name; store with hashed filename + canonical ext
        $orig = preg_replace('/[^\w.\-\x{0E00}-\x{0E7F}]+/u', '_', $f['name']);
        $stored = bin2hex(random_bytes(12)) . '.' . $canonicalExt;

        // Storage path: uploads/finance/{YYYY}/{MM}/
        $rel = sprintf('uploads/finance/%s/%s', date('Y'), date('m'));
        $abs = __DIR__ . '/../' . $rel;
        if (!is_dir($abs) && !mkdir($abs, 0775, true) && !is_dir($abs)) {
            echo json_encode(['ok'=>false,'message'=>'สร้างโฟลเดอร์อัปโหลดไม่ได้']); exit;
        }
        // Harden every directory along the path — root + year + month — so
        // every level has its own deny-all .htaccess and empty index.html.
        // Re-running is cheap (idempotent) and survives a manual `rmdir`.
        $rootDir = __DIR__ . '/../uploads/finance';
        fin_harden_upload_dir($rootDir);
        fin_harden_upload_dir($rootDir . '/' . date('Y'));
        fin_harden_upload_dir($abs);
        $dest = $abs . '/' . $stored;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            echo json_encode(['ok'=>false,'message'=>'ย้ายไฟล์ไม่สำเร็จ']); exit;
        }

        // DB row + audit in a single transaction. If anything fails after
        // the file has been moved into place we unlink to avoid orphans.
        $relStored = $rel . '/' . $stored;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO sys_finance_attachments
                (txn_id, stored_name, original_name, mime_type, size_bytes, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$txnId, $relStored, $orig, $mime, (int)$f['size'], $adminId ?: null]);
            $attachId = (int)$pdo->lastInsertId();
            fin_audit_log($pdo, $txnId, 'attach_add', [
                'attachment_id' => $attachId, 'file' => $orig, 'size' => (int)$f['size'],
            ], $adminId, $adminName);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            @unlink($dest);
            throw $e;
        }
        echo json_encode(['ok' => true, 'id' => $attachId, 'stored_name' => $relStored, 'original_name' => $orig]); exit;
    }

    if ($action === 'attachment:delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok'=>false,'message'=>'ไม่ระบุ id']); exit; }
        $sel = $pdo->prepare("SELECT txn_id, stored_name, original_name, uploaded_by FROM sys_finance_attachments WHERE id=?");
        $sel->execute([$id]);
        $att = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$att) { echo json_encode(['ok'=>false,'message'=>'ไม่พบไฟล์']); exit; }
        // Only the uploader, admin, or superadmin can delete an attachment —
        // an editor with access_finance cannot remove someone else's evidence.
        $isAdminRole = $isSuper || ($adminRole === 'admin');
        $isUploader  = ((int)$att['uploaded_by'] > 0) && ((int)$att['uploaded_by'] === $adminId);
        if (!$isAdminRole && !$isUploader) {
            echo json_encode(['ok'=>false,'message'=>'ไม่มีสิทธิ์ลบไฟล์แนบของผู้อื่น']); exit;
        }
        // Constrain unlink path to uploads/finance/ to defeat any stored-name
        // tampering (defense-in-depth — stored_name is server-generated)
        $financeRoot = realpath(__DIR__ . '/../uploads/finance');
        $abs = realpath(__DIR__ . '/../' . $att['stored_name']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM sys_finance_attachments WHERE id=?")->execute([$id]);
            fin_audit_log($pdo, (int)$att['txn_id'], 'attach_remove', [
                'attachment_id' => $id, 'file' => $att['original_name'],
            ], $adminId, $adminName);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
        // Unlink only after DB commit succeeds — otherwise a rollback would
        // leave the row pointing at a deleted file.
        if ($financeRoot && $abs && str_starts_with($abs, $financeRoot . DIRECTORY_SEPARATOR) && is_file($abs)) {
            @unlink($abs);
        }
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'category:create' || $action === 'category:update') {
        $isCreate = $action === 'category:create';
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $kindIn = (string)($_POST['kind'] ?? '');
        if ($kindIn !== 'income' && $kindIn !== 'expense') {
            echo json_encode(['ok' => false, 'message' => 'ต้องระบุประเภทหมวด (รายรับ/รายจ่าย)']); exit;
        }
        $kind = $kindIn;
        $icon = trim((string)($_POST['icon'] ?? 'fa-circle'));
        $color = trim((string)($_POST['color'] ?? '#64748b'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') { echo json_encode(['ok' => false, 'message' => 'กรุณากรอกชื่อหมวด']); exit; }

        if ($isCreate) {
            $stmt = $pdo->prepare("INSERT INTO sys_finance_categories (name, kind, icon, color, sort_order) VALUES (?, ?, ?, ?, ?)");
            try { $stmt->execute([$name, $kind, $icon, $color, $sortOrder]); }
            catch (PDOException $e) {
                if ((int)$e->errorInfo[1] === 1062) { echo json_encode(['ok' => false, 'message' => 'ชื่อหมวดซ้ำในประเภทเดียวกัน']); exit; }
                throw $e;
            }
            $newId = (int)$pdo->lastInsertId();
            fin_audit_log($pdo, 0, 'category:create', [
                'category_id' => $newId, 'name' => $name, 'kind' => $kind,
                'icon' => $icon, 'color' => $color, 'sort_order' => $sortOrder,
            ], $adminId, $adminName);
            echo json_encode(['ok' => true, 'id' => $newId]); exit;
        } else {
            if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
            $beforeStmt = $pdo->prepare("SELECT name, kind, icon, color, sort_order FROM sys_finance_categories WHERE id=?");
            $beforeStmt->execute([$id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $pdo->prepare("UPDATE sys_finance_categories SET name=?, kind=?, icon=?, color=?, sort_order=? WHERE id=?")
                ->execute([$name, $kind, $icon, $color, $sortOrder, $id]);
            $after = ['name'=>$name,'kind'=>$kind,'icon'=>$icon,'color'=>$color,'sort_order'=>$sortOrder];
            $changes = ['category_id' => $id];
            foreach ($after as $k => $v) {
                $oldV = $before[$k] ?? null;
                if ((string)$oldV !== (string)$v) $changes[$k] = ['from' => $oldV, 'to' => $v];
            }
            fin_audit_log($pdo, 0, 'category:update', $changes, $adminId, $adminName);
            echo json_encode(['ok' => true]); exit;
        }
    }

    if ($action === 'category:toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
        $pdo->prepare("UPDATE sys_finance_categories SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
        $newState = $pdo->prepare("SELECT is_active FROM sys_finance_categories WHERE id=?");
        $newState->execute([$id]);
        $isActive = (int)$newState->fetchColumn();
        fin_audit_log($pdo, 0, 'category:toggle', ['category_id' => $id, 'is_active' => $isActive], $adminId, $adminName);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'category:delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'ไม่ระบุ id']); exit; }
        $usedStmt = $pdo->prepare("SELECT COUNT(*) FROM sys_finance_transactions WHERE category_id=?");
        $usedStmt->execute([$id]);
        $used = (int)$usedStmt->fetchColumn();
        if ($used > 0) { echo json_encode(['ok' => false, 'message' => "มีรายการใช้หมวดนี้อยู่ {$used} รายการ — ลบไม่ได้"]); exit; }
        $snap = $pdo->prepare("SELECT name, kind FROM sys_finance_categories WHERE id=?");
        $snap->execute([$id]);
        $row = $snap->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->prepare("DELETE FROM sys_finance_categories WHERE id=?")->execute([$id]);
        fin_audit_log($pdo, 0, 'category:delete', ['category_id' => $id] + $row, $adminId, $adminName);
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['ok' => false, 'message' => 'unknown action: ' . $action]);
} catch (Throwable $e) {
    error_log('[finance] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'message' => 'เกิดข้อผิดพลาดภายในระบบ กรุณาลองอีกครั้งหรือติดต่อผู้ดูแล']);
}
