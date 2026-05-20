<?php
// portal/ajax_vaccine_catalog.php — CRUD for sys_vaccine_types (Phase 2)
// Actions:
//   list           GET  → catalog rows + usage count per type
//   create         POST → insert new vaccine type
//   update         POST → modify existing type
//   toggle_active  POST → flip is_active flag
//   delete         POST → hard delete (only if no records reference it)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$canWrite  = $isSuper || ($adminRole === 'admin');
$canRead   = $canWrite || !empty($_SESSION['access_identity']);
if (!$canRead) {
    echo json_encode(['ok' => false, 'message' => 'ต้องมีสิทธิ์ admin/superadmin']);
    exit;
}

$pdo    = db();
$action = (string)($_GET['action'] ?? '');

// Self-healing schema: add optional fields the catalog UI exposes that the
// Phase-1 migration didn't yet ship, plus link campaigns to a catalog row
try {
    foreach ([
        'default_manufacturer' => "VARCHAR(150) NULL DEFAULT NULL",
        'notes'                => "TEXT NULL DEFAULT NULL",
    ] as $col => $def) {
        try { $pdo->exec("ALTER TABLE sys_vaccine_types ADD COLUMN IF NOT EXISTS {$col} {$def}"); } catch (PDOException) {}
    }
    try { $pdo->exec("ALTER TABLE camp_list ADD COLUMN IF NOT EXISTS vaccine_type_id INT UNSIGNED NULL DEFAULT NULL"); } catch (PDOException) {}
    try { $pdo->exec("ALTER TABLE camp_list ADD INDEX IF NOT EXISTS idx_vaccine_type_id (vaccine_type_id)"); } catch (PDOException) {}
} catch (Throwable $e) {
    error_log('[vaccine_catalog] migration: ' . $e->getMessage());
}

if ($action === 'list') {
    try {
        // Catalog + per-row usage stats: campaigns linked + records inserted
        $rows = $pdo->query("
            SELECT t.id, t.code, t.name_th, t.name_en, t.default_doses, t.interval_days,
                   t.category, t.default_manufacturer, t.notes, t.is_active, t.sort_order,
                   t.created_at, t.updated_at,
                   (SELECT COUNT(*) FROM camp_list cl WHERE cl.vaccine_type_id = t.id) AS campaign_count,
                   (SELECT COUNT(*) FROM user_vaccination_records uvr WHERE uvr.vaccine_type_id = t.id) AS record_count
            FROM sys_vaccine_types t
            ORDER BY t.is_active DESC, t.sort_order ASC, t.name_th ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'types' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[vaccine_catalog] list: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'ดึง catalog ไม่สำเร็จ']);
    }
    exit;
}

// All write actions require admin+
function vc_require_write(bool $canWrite): void {
    if (!$canWrite) throw new LogicException('ต้องใช้สิทธิ์ admin หรือ superadmin');
}

/**
 * Whitelisted field parse from $_POST. Caller decides what's required vs
 * optional via $required. Returns normalised [col => value] array, throws
 * LogicException on the first validation miss.
 */
function vc_parse_fields(array $post, array $required): array {
    $out = [];

    if (isset($post['code'])) {
        $c = trim((string)$post['code']);
        if ($c === '' || mb_strlen($c) > 50 || !preg_match('/^[A-Z0-9_\-]+$/', $c)) {
            throw new LogicException('code ต้องเป็น A-Z, 0-9, _ หรือ - เท่านั้น (สูงสุด 50 ตัว)');
        }
        $out['code'] = $c;
    }
    if (isset($post['name_th'])) {
        $v = trim((string)$post['name_th']);
        if ($v === '' || mb_strlen($v) > 200) throw new LogicException('ชื่อ (ไทย) ต้องไม่ว่าง และไม่เกิน 200 ตัว');
        $out['name_th'] = $v;
    }
    if (isset($post['name_en'])) {
        $v = trim((string)$post['name_en']);
        if (mb_strlen($v) > 200) throw new LogicException('ชื่อ (อังกฤษ) เกิน 200 ตัว');
        $out['name_en'] = $v;
    }
    if (isset($post['default_doses'])) {
        $n = (int)$post['default_doses'];
        if ($n < 1 || $n > 20) throw new LogicException('default_doses ต้องอยู่ระหว่าง 1-20');
        $out['default_doses'] = $n;
    }
    if (isset($post['interval_days'])) {
        $v = $post['interval_days'];
        if ($v === '' || $v === null) {
            $out['interval_days'] = null;
        } else {
            $n = (int)$v;
            if ($n < 0 || $n > 36500) throw new LogicException('interval_days ต้องอยู่ระหว่าง 0-36500');
            $out['interval_days'] = $n;
        }
    }
    if (isset($post['category'])) {
        $v = trim((string)$post['category']);
        if ($v === '' || mb_strlen($v) > 50) $v = 'routine';
        $out['category'] = $v;
    }
    if (isset($post['default_manufacturer'])) {
        $v = trim((string)$post['default_manufacturer']);
        if (mb_strlen($v) > 150) throw new LogicException('default_manufacturer เกิน 150 ตัว');
        $out['default_manufacturer'] = $v !== '' ? $v : null;
    }
    if (isset($post['notes'])) {
        $v = trim((string)$post['notes']);
        if (mb_strlen($v) > 2000) throw new LogicException('notes เกิน 2000 ตัว');
        $out['notes'] = $v !== '' ? $v : null;
    }
    if (isset($post['sort_order'])) {
        $out['sort_order'] = max(0, min(9999, (int)$post['sort_order']));
    }
    if (isset($post['is_active'])) {
        $out['is_active'] = (int)((string)$post['is_active'] === '1');
    }

    // Required-field check
    foreach ($required as $col) {
        if (!array_key_exists($col, $out)) {
            throw new LogicException("ขาดข้อมูล: {$col}");
        }
    }
    return $out;
}

if ($action === 'create') {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new LogicException('ต้องเป็น POST');
        validate_csrf_or_die();
        vc_require_write($canWrite);

        $fields = vc_parse_fields($_POST, ['code', 'name_th']);
        // Defaults
        $fields['default_doses'] = $fields['default_doses'] ?? 1;
        $fields['category']      = $fields['category']      ?? 'routine';
        $fields['sort_order']    = $fields['sort_order']    ?? 100;
        $fields['is_active']     = $fields['is_active']     ?? 1;

        // Duplicate-code check before INSERT (UNIQUE index would catch it
        // but error message is friendlier this way)
        $stmt = $pdo->prepare("SELECT id FROM sys_vaccine_types WHERE code = :c LIMIT 1");
        $stmt->execute([':c' => $fields['code']]);
        if ($stmt->fetch()) throw new LogicException('code นี้มีอยู่แล้ว');

        $cols = array_keys($fields);
        $placeholders = array_map(fn($c) => ":{$c}", $cols);
        $bind = [];
        foreach ($fields as $k => $v) $bind[":{$k}"] = $v;
        $sql = "INSERT INTO sys_vaccine_types (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
        $pdo->prepare($sql)->execute($bind);
        $newId = (int)$pdo->lastInsertId();

        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        try {
            log_activity('Vaccine Catalog Create', "id={$newId} code={$fields['code']} name=" . mb_substr($fields['name_th'], 0, 100), $adminId ?: null);
        } catch (Throwable $e) {}

        echo json_encode(['ok' => true, 'id' => $newId], JSON_UNESCAPED_UNICODE);
    } catch (LogicException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[vaccine_catalog] create: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'สร้างไม่สำเร็จ']);
    }
    exit;
}

if ($action === 'update') {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new LogicException('ต้องเป็น POST');
        validate_csrf_or_die();
        vc_require_write($canWrite);

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new LogicException('id ไม่ถูกต้อง');

        // Snapshot before for audit
        $stmt = $pdo->prepare("SELECT * FROM sys_vaccine_types WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new LogicException('ไม่พบรายการ');

        $fields = vc_parse_fields($_POST, []);
        if (!$fields) throw new LogicException('ไม่มีฟิลด์ให้แก้ไข');

        // Code change requires duplicate check
        if (isset($fields['code']) && $fields['code'] !== $before['code']) {
            $stmt = $pdo->prepare("SELECT id FROM sys_vaccine_types WHERE code = :c AND id <> :id LIMIT 1");
            $stmt->execute([':c' => $fields['code'], ':id' => $id]);
            if ($stmt->fetch()) throw new LogicException('code นี้มีอยู่แล้ว');
        }

        $sets = [];
        $bind = [':id' => $id];
        foreach ($fields as $k => $v) {
            $sets[] = "`{$k}` = :{$k}";
            $bind[":{$k}"] = $v;
        }
        $pdo->prepare("UPDATE sys_vaccine_types SET " . implode(',', $sets) . " WHERE id = :id")->execute($bind);

        // Diff for audit
        $diff = [];
        foreach ($fields as $k => $v) {
            if ((string)($before[$k] ?? '') !== (string)$v) {
                $diff[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
            }
        }
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        try {
            log_activity('Vaccine Catalog Update', "id={$id} fields=" . implode(',', array_keys($diff)), $adminId ?: null);
        } catch (Throwable $e) {}

        echo json_encode(['ok' => true, 'diff' => $diff], JSON_UNESCAPED_UNICODE);
    } catch (LogicException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[vaccine_catalog] update: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'บันทึกไม่สำเร็จ']);
    }
    exit;
}

if ($action === 'toggle_active') {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new LogicException('ต้องเป็น POST');
        validate_csrf_or_die();
        vc_require_write($canWrite);

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new LogicException('id ไม่ถูกต้อง');

        $stmt = $pdo->prepare("UPDATE sys_vaccine_types SET is_active = 1 - is_active WHERE id = :id");
        $stmt->execute([':id' => $id]);

        $stmt2 = $pdo->prepare("SELECT is_active FROM sys_vaccine_types WHERE id = :id");
        $stmt2->execute([':id' => $id]);
        $newState = (int)$stmt2->fetchColumn();

        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        try {
            log_activity('Vaccine Catalog Toggle', "id={$id} active={$newState}", $adminId ?: null);
        } catch (Throwable $e) {}

        echo json_encode(['ok' => true, 'is_active' => $newState], JSON_UNESCAPED_UNICODE);
    } catch (LogicException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[vaccine_catalog] toggle: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'เปลี่ยนสถานะไม่สำเร็จ']);
    }
    exit;
}

if ($action === 'delete') {
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new LogicException('ต้องเป็น POST');
        validate_csrf_or_die();
        if (!$isSuper) throw new LogicException('การลบต้องใช้สิทธิ์ superadmin');

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new LogicException('id ไม่ถูกต้อง');

        // Hard delete only when nothing references this type — otherwise the
        // admin should toggle it inactive instead. Prevents orphaned FKs.
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_vaccination_records WHERE vaccine_type_id = :id");
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) throw new LogicException('ลบไม่ได้ — มี vaccination records อ้างถึง (ให้กด Stop แทน)');

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM camp_list WHERE vaccine_type_id = :id");
        $stmt->execute([':id' => $id]);
        if ((int)$stmt->fetchColumn() > 0) throw new LogicException('ลบไม่ได้ — มี campaigns อ้างถึง (ให้กด Stop แทน)');

        $pdo->prepare("DELETE FROM sys_vaccine_types WHERE id = :id")->execute([':id' => $id]);

        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        try {
            log_activity('Vaccine Catalog Delete', "id={$id}", $adminId ?: null);
        } catch (Throwable $e) {}

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (LogicException $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[vaccine_catalog] delete: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'ลบไม่สำเร็จ']);
    }
    exit;
}

echo json_encode(['ok' => false, 'message' => 'unknown action']);
