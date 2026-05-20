<?php
// portal/ajax_db_schema.php — Database Schema Explorer (Option C: FK + heuristic)
// Actions:
//   graph  → nodes (tables) + edges (FK + inferred *_id) + counts
//   table  → columns + row count + outbound relations for one table
//
// Admin-only. Read-only over INFORMATION_SCHEMA — never exposes row data.
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
// Schema metadata reveals architecture but no PII. Restrict to admin/super
// so editors / external partners don't get a free reconnaissance map.
$canSchema = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_identity']);
if (!$canSchema) {
    echo json_encode(['ok' => false, 'message' => 'ต้องมีสิทธิ์ admin หรือ access_identity']);
    exit;
}

$pdo    = db();
$action = (string)($_GET['action'] ?? '');

/**
 * Bucket a table into a visual domain.
 *
 * Used both for node colour and for the legend. Prefix-based with a few
 * explicit exceptions for cross-cutting tables (sys_users, sys_admins, etc.)
 * Returns [domain_key, hex_colour, thai_label].
 */
function ds_table_domain(string $name): array {
    // Order matters — sys_finance_* matches before generic sys_*
    if (str_starts_with($name, 'sys_finance_'))           return ['finance',   '#7c3aed', 'การเงิน'];
    if (str_starts_with($name, 'sys_announcement'))       return ['comm',      '#f59e0b', 'สื่อสาร'];
    if (str_starts_with($name, 'sys_activity_log')
        || str_starts_with($name, 'error_log')
        || str_starts_with($name, 'sys_audit')
        || str_starts_with($name, 'sys_pdpa_'))           return ['audit',     '#dc2626', 'audit/log'];
    if (str_starts_with($name, 'gold_card'))              return ['goldcard',  '#f59e0b', 'บัตรทอง'];
    if (str_starts_with($name, 'camp_'))                  return ['campaign',  '#3b82f6', 'แคมเปญ'];
    if (str_starts_with($name, 'borrow_')
        || str_starts_with($name, 'eb_'))                 return ['borrow',    '#f43f5e', 'e-Borrow'];
    if (str_starts_with($name, 'consumables_')
        || str_starts_with($name, 'asset_'))              return ['inventory', '#06b6d4', 'คลังพัสดุ'];
    if (str_starts_with($name, 'sys_scholarship')
        || str_starts_with($name, 'scholar'))             return ['scholarship', '#10b981', 'ทุนการศึกษา'];
    if (str_starts_with($name, 'sys_insurance'))          return ['insurance', '#0891b2', 'ประกัน'];
    if ($name === 'sys_users')                            return ['user',      '#2e9e63', 'ผู้ใช้'];
    if (str_starts_with($name, 'sys_staff')
        || str_starts_with($name, 'sys_admin'))           return ['staff',     '#0ea5e9', 'เจ้าหน้าที่'];
    if (str_starts_with($name, 'sys_ai_'))                return ['ai',        '#a855f7', 'AI'];
    if (str_starts_with($name, 'sys_'))                   return ['masterdata','#64748b', 'master data'];
    return ['other', '#94a3b8', 'อื่นๆ'];
}

/**
 * Heuristic relationship inferrer.
 *
 * For a column named `foo_id`, look for an existing table named (in order):
 *   sys_foos, sys_foo, foos, foo
 * Falls back to a hand-curated alias map for common cross-cutting joins
 * (user_id, student_id → sys_users; staff_id → sys_staff; etc.) — these are
 * relationships the project uses heavily but never declared as FK constraints.
 *
 * Returns null if no plausible target is found.
 */
function ds_infer_target(string $column, array $tableSet): ?string {
    // Hand-curated aliases — these names are special-cased because their
    // bare `_id` form doesn't yield a usable singular/plural table name
    static $aliases = [
        'user_id'             => 'sys_users',
        'student_id'          => 'sys_users',
        'patient_id'          => 'sys_users',
        'borrower_student_id' => 'sys_users',
        'linked_user_id'      => 'sys_users',
        'admin_id'            => 'sys_admins',
        'staff_id'            => 'sys_staff',
        'created_by'          => 'sys_admins',
        'updated_by'          => 'sys_admins',
        'performed_by'        => 'sys_admins',
        'approved_by'         => 'sys_admins',
        'campaign_id'         => 'camp_campaigns',
        'slot_id'             => 'camp_slots',
        'booking_id'          => 'camp_bookings',
        'category_id'         => null, // ambiguous — skip
        'parent_id'           => null, // self-ref ambiguous
        'member_id'           => null, // ambiguous between gold_card and sys_users
    ];
    if (array_key_exists($column, $aliases)) {
        $target = $aliases[$column];
        return ($target !== null && in_array($target, $tableSet, true)) ? $target : null;
    }

    if (!str_ends_with($column, '_id')) return null;
    $base = substr($column, 0, -3);  // strip "_id"
    foreach (["sys_{$base}s", "sys_{$base}", "{$base}s", "{$base}"] as $candidate) {
        if (in_array($candidate, $tableSet, true)) return $candidate;
    }
    return null;
}

if ($action === 'graph') {
    try {
        // Tables in the current DB. INFORMATION_SCHEMA.TABLES.TABLE_ROWS is
        // an *estimate* for InnoDB — fine for a visualisation, not precise.
        $tables = $pdo->query("
            SELECT TABLE_NAME AS name, TABLE_ROWS AS rows
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ")->fetchAll(PDO::FETCH_ASSOC);

        $tableNames = array_column($tables, 'name');
        $tableSet   = array_flip($tableNames);

        // Explicit FK relationships from REFERENTIAL_CONSTRAINTS
        $fks = $pdo->query("
            SELECT
                kcu.TABLE_NAME           AS src_table,
                kcu.COLUMN_NAME          AS src_col,
                kcu.REFERENCED_TABLE_NAME AS tgt_table,
                kcu.REFERENCED_COLUMN_NAME AS tgt_col
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = DATABASE()
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Columns for heuristic detection — exclude PRI keys
        $cols = $pdo->query("
            SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col, COLUMN_KEY AS kkey
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND COLUMN_KEY <> 'PRI'
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Track which (table.column) pairs already have an explicit FK so the
        // heuristic doesn't redundantly add a dashed edge over a solid one
        $fkSeen = [];
        foreach ($fks as $r) $fkSeen[$r['src_table'] . '.' . $r['src_col']] = true;

        $nodes = [];
        foreach ($tables as $t) {
            [$domain, $color, $label] = ds_table_domain($t['name']);
            $nodes[] = [
                'id'      => $t['name'],
                'label'   => $t['name'],
                'domain'  => $domain,
                'color'   => $color,
                'rows'    => (int)$t['rows'],
            ];
        }

        $edges    = [];
        $fkCount  = 0;
        $heuCount = 0;
        foreach ($fks as $r) {
            if (!isset($tableSet[$r['src_table']]) || !isset($tableSet[$r['tgt_table']])) continue;
            $edges[] = [
                'source' => $r['src_table'],
                'target' => $r['tgt_table'],
                'label'  => $r['src_col'],
                'type'   => 'fk',
            ];
            $fkCount++;
        }
        foreach ($cols as $c) {
            $key = $c['tbl'] . '.' . $c['col'];
            if (isset($fkSeen[$key])) continue;
            $target = ds_infer_target($c['col'], $tableNames);
            if ($target === null || $target === $c['tbl']) continue;
            $edges[] = [
                'source' => $c['tbl'],
                'target' => $target,
                'label'  => $c['col'],
                'type'   => 'heuristic',
            ];
            $heuCount++;
        }

        // Domain legend — build dynamically from what's actually present so
        // empty domains don't clutter the legend
        $legend = [];
        foreach ($nodes as $n) {
            $legend[$n['domain']] ??= [
                'domain' => $n['domain'],
                'color'  => $n['color'],
                'label'  => ds_table_domain($n['id'])[2],
                'count'  => 0,
            ];
            $legend[$n['domain']]['count']++;
        }
        // Re-sort largest domain first
        usort($legend, fn($a, $b) => $b['count'] - $a['count']);

        echo json_encode([
            'ok'    => true,
            'nodes' => $nodes,
            'edges' => $edges,
            'stats' => [
                'tables'    => count($nodes),
                'fk'        => $fkCount,
                'heuristic' => $heuCount,
            ],
            'legend' => array_values($legend),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[db_schema] graph: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'ดึง schema ไม่สำเร็จ — โปรดลองอีกครั้ง']);
    }
    exit;
}

if ($action === 'table') {
    try {
        $name = (string)($_GET['name'] ?? '');
        // Strict regex — table name goes into SQL via backtick interpolation
        // since prepared statements can't parameterise table identifiers
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new LogicException('ชื่อตารางไม่ถูกต้อง');
        }
        // Confirm table exists in this DB (rejects information_schema lookups
        // and any table outside the current schema)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n");
        $stmt->execute([':n' => $name]);
        if (!(int)$stmt->fetchColumn()) {
            throw new LogicException('ไม่พบตารางนี้');
        }

        // Columns
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable,
                   COLUMN_KEY AS kkey, COLUMN_DEFAULT AS dflt, EXTRA AS extra,
                   COLUMN_COMMENT AS comment
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->execute([':n' => $name]);
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Row count — explicit COUNT(*) since INFORMATION_SCHEMA.TABLE_ROWS
        // is just an estimate for InnoDB
        $rowCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$name}`")->fetchColumn();

        // Outbound FKs (real)
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME AS src, REFERENCED_TABLE_NAME AS tgt, REFERENCED_COLUMN_NAME AS tgt_col
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        $stmt->execute([':n' => $name]);
        $outFks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Inbound FKs — who references THIS table
        $stmt = $pdo->prepare("
            SELECT TABLE_NAME AS src_table, COLUMN_NAME AS src_col
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = :n
        ");
        $stmt->execute([':n' => $name]);
        $inFks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        [$domain, $color, $domainLabel] = ds_table_domain($name);

        echo json_encode([
            'ok'           => true,
            'name'         => $name,
            'domain'       => $domain,
            'domain_label' => $domainLabel,
            'color'        => $color,
            'row_count'    => $rowCount,
            'columns'      => $cols,
            'out_fks'      => $outFks,
            'in_fks'       => $inFks,
        ], JSON_UNESCAPED_UNICODE);
    } catch (LogicException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[db_schema] table: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'ดึงรายละเอียดตารางไม่สำเร็จ']);
    }
    exit;
}

echo json_encode(['ok' => false, 'message' => 'unknown action']);
