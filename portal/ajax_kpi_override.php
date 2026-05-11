<?php
/**
 * portal/ajax_kpi_override.php
 * Admin endpoint สำหรับ override KPI value
 *
 * Permission: superadmin หรือ access_dashboard_admin
 *
 * actions:
 *   set    — POST kpi_key, value, note → upsert + is_active=1
 *   clear  — POST kpi_key              → is_active=0 (เก็บ history)
 *   get    — POST kpi_key              → คืน {value, is_active, note, updated_at}
 *   list   — คืนทั้งหมด (พร้อม catalog)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ajax_helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/kpi_override_helper.php';
require_once __DIR__ . '/../includes/dashboard_data_sources.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$isSuper   = $adminRole === 'superadmin';
$canEdit   = $isSuper || !empty($_SESSION['access_dashboard_admin']);

if (!$canEdit) json_err('ไม่มีสิทธิ์แก้ไขค่า KPI', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err('Method not allowed', 405);
if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();

$pdo    = db();
$action = $_POST['action'] ?? '';
$catalog = kpi_override_catalog();

// Auto-migrate guard
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ins_kpi_overrides (
        kpi_key VARCHAR(100) NOT NULL PRIMARY KEY,
        override_value BIGINT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        note VARCHAR(500) NOT NULL DEFAULT '',
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) { /* silent */ }

/**
 * คำนวณค่าจริงของ KPI โดยตรงจาก DB — bypass override ทุกตัว
 */
function _kpi_compute_auto(PDO $pdo, string $key): int
{
    try {
        switch ($key) {
            case 'mti_total_active':
                return (int)$pdo->query("SELECT COUNT(*) FROM insurance_members WHERE insurance_status='Active'")->fetchColumn();
            case 'mti_total_all':
                return (int)$pdo->query("SELECT COUNT(*) FROM insurance_members")->fetchColumn();
            case 'mti_staff':
                return (int)$pdo->query("SELECT COUNT(*) FROM insurance_members WHERE member_status='บุคลากร'")->fetchColumn();
            case 'mti_student':
                return (int)$pdo->query("SELECT COUNT(*) FROM insurance_members WHERE member_status='นักศึกษา'")->fetchColumn();
            case 'mti_manual_override':
                return (int)$pdo->query("SELECT COUNT(*) FROM insurance_members WHERE manually_overridden=1")->fetchColumn();
            case 'mti_expiring_30d':
                return (int)$pdo->query("SELECT COUNT(*) FROM insurance_members
                    WHERE insurance_status='Active'
                      AND coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
            case 'gold_total':
                return (int)$pdo->query("SELECT COUNT(*) FROM gold_card_members")->fetchColumn();
            case 'gold_approved':
                return (int)$pdo->query("SELECT COUNT(*) FROM gold_card_members WHERE status='approved'")->fetchColumn();
            case 'gold_auto_matched':
                return (int)$pdo->query("SELECT COUNT(*) FROM gold_card_members WHERE status='active'")->fetchColumn();
            case 'gold_pending_docs':
                return (int)$pdo->query("SELECT COUNT(*) FROM gold_card_members WHERE status='pending'")->fetchColumn();
            case 'gold_rejected':
                return (int)$pdo->query("SELECT COUNT(*) FROM gold_card_members WHERE status='rejected'")->fetchColumn();
            case 'gold_expiring_30d':
                return (int)$pdo->query("SELECT COUNT(*) FROM gold_card_members
                    WHERE status='active'
                      AND coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
            case 'coverage_total':
                $mti  = (int)$pdo->query("SELECT COUNT(DISTINCT citizen_id) FROM insurance_members WHERE insurance_status='Active'")->fetchColumn();
                $gold = (int)$pdo->query("SELECT COUNT(DISTINCT citizen_id) FROM gold_card_members WHERE status IN ('approved','active')")->fetchColumn();
                return $mti + $gold;
        }
    } catch (PDOException $e) { /* tables may not exist */ }
    return 0;
}

try {
    switch ($action) {

        case 'set': {
            $key   = trim((string)($_POST['kpi_key'] ?? ''));
            $value = (int)($_POST['value'] ?? 0);
            $note  = trim((string)($_POST['note'] ?? ''));

            if (!isset($catalog[$key])) json_err('KPI key ไม่ถูกต้อง: ' . htmlspecialchars($key));
            if ($value < 0) json_err('ค่าต้อง ≥ 0');

            $stmt = $pdo->prepare("INSERT INTO ins_kpi_overrides
                (kpi_key, override_value, is_active, note, updated_by)
                VALUES (?, ?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE
                    override_value = VALUES(override_value),
                    is_active      = 1,
                    note           = VALUES(note),
                    updated_by     = VALUES(updated_by)");
            $stmt->execute([$key, $value, $note, $adminId ?: null]);

            json_ok([
                'kpi_key'   => $key,
                'value'     => $value,
                'is_active' => 1,
                'message'   => 'ตั้งค่า override เรียบร้อย',
            ]);
        }

        case 'clear': {
            $key = trim((string)($_POST['kpi_key'] ?? ''));
            if (!isset($catalog[$key])) json_err('KPI key ไม่ถูกต้อง');
            $pdo->prepare("UPDATE ins_kpi_overrides SET is_active = 0, updated_by = ? WHERE kpi_key = ?")
                ->execute([$adminId ?: null, $key]);
            json_ok(['kpi_key' => $key, 'message' => 'ปิดการ override แล้ว — ใช้ค่าจริง']);
        }

        case 'get': {
            $key = trim((string)($_POST['kpi_key'] ?? ''));
            if (!isset($catalog[$key])) json_err('KPI key ไม่ถูกต้อง');
            $stmt = $pdo->prepare("SELECT override_value, is_active, note, updated_at FROM ins_kpi_overrides WHERE kpi_key = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // คำนวณ auto value จริงจาก DB (ผ่าน data resolver โดยไม่ใช้ override)
            // Resolver เมื่อไม่มี filter จะ apply override — ดังนั้นใช้ filter dummy ([year=>0]
            // ก็ไม่ work เพราะมัน trigger filter mode) → เรียก raw computation ตรง
            $autoValue = _kpi_compute_auto($pdo, $key);

            json_ok([
                'kpi_key'    => $key,
                'label'      => $catalog[$key]['label'],
                'value'      => $row ? (int)$row['override_value'] : null,
                'auto_value' => $autoValue,
                'is_active'  => $row ? (int)$row['is_active'] : 0,
                'note'       => $row['note'] ?? '',
                'updated_at' => $row['updated_at'] ?? null,
            ]);
        }

        case 'list': {
            $rows = $pdo->query("SELECT * FROM ins_kpi_overrides")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $byKey = [];
            foreach ($rows as $r) $byKey[$r['kpi_key']] = $r;

            $out = [];
            foreach ($catalog as $key => $meta) {
                $r = $byKey[$key] ?? null;
                $out[] = [
                    'kpi_key'    => $key,
                    'label'      => $meta['label'],
                    'group'      => $meta['group'],
                    'value'      => $r ? (int)$r['override_value'] : null,
                    'auto_value' => _kpi_compute_auto($pdo, $key),
                    'is_active'  => $r ? (int)$r['is_active'] : 0,
                    'note'       => $r['note'] ?? '',
                    'updated_at' => $r['updated_at'] ?? null,
                ];
            }
            json_ok(['overrides' => $out]);
        }

        default:
            json_err('ไม่รู้จัก action — ' . htmlspecialchars($action));
    }
} catch (Throwable $e) {
    error_log('[ajax_kpi_override] ' . $e->getMessage());
    json_err('ระบบขัดข้อง: ' . $e->getMessage(), 500);
}
