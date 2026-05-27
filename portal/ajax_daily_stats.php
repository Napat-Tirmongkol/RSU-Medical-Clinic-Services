<?php
/**
 * portal/ajax_daily_stats.php — บันทึก/ดึงสถิติประจำวันแบบง่าย
 *
 * Actions:
 *   GET  ?action=get&date=YYYY-MM-DD   → คืนข้อมูลของวันนั้น (หรือ default ถ้ายังไม่มี)
 *   POST action=save                   → upsert (CSRF guard)
 *   GET  ?action=list&from=&to=        → ดึง 30 วันล่าสุด (จำกัด 90 rows)
 *
 * Schema (auto-create, idempotent):
 *   sys_daily_clinic_stats(stat_date PRIMARY KEY · patient_count · accident_count · note · updated_by · updated_at)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'system');
if (!$adminId) { echo json_encode(['ok'=>false,'error'=>'unauthenticated']); exit; }

$pdo = db();

// ─── Schema (idempotent auto-migrate) ────────────────────────────────────────
function ensure_daily_stats_schema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_daily_clinic_stats (
        stat_date       DATE NOT NULL PRIMARY KEY,
        patient_count   INT UNSIGNED NOT NULL DEFAULT 0,
        accident_count  INT UNSIGNED NOT NULL DEFAULT 0,
        note            VARCHAR(500) NULL,
        updated_by      VARCHAR(120) NULL,
        updated_at      DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $checked = true;
}
ensure_daily_stats_schema($pdo);

$action = (string)($_REQUEST['action'] ?? 'get');
function _ds_send(array $r): void { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

switch ($action) {

    case 'get': {
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) _ds_send(['ok'=>false,'error'=>'bad date']);
        $st = $pdo->prepare("SELECT * FROM sys_daily_clinic_stats WHERE stat_date = :d");
        $st->execute([':d' => $date]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        _ds_send(['ok' => true, 'date' => $date, 'row' => $row ?: null]);
    }

    case 'save': {
        validate_csrf_or_die();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') _ds_send(['ok'=>false,'error'=>'POST only']);
        $date    = (string)($_POST['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) _ds_send(['ok'=>false,'error'=>'bad date']);
        // Sanity bounds — กัน accidental "999999999"
        $patient  = max(0, min(99999, (int)($_POST['patient_count'] ?? 0)));
        $accident = max(0, min(9999,  (int)($_POST['accident_count'] ?? 0)));
        $note     = trim((string)($_POST['note'] ?? ''));
        if (mb_strlen($note) > 500) $note = mb_substr($note, 0, 500);

        $st = $pdo->prepare("INSERT INTO sys_daily_clinic_stats
            (stat_date, patient_count, accident_count, note, updated_by, updated_at)
            VALUES (:d, :p, :a, :n, :u, NOW())
            ON DUPLICATE KEY UPDATE
                patient_count = :p2, accident_count = :a2, note = :n2,
                updated_by = :u2, updated_at = NOW()");
        $st->execute([
            ':d' => $date, ':p' => $patient, ':a' => $accident, ':n' => ($note?:null), ':u' => $adminName,
            ':p2' => $patient, ':a2' => $accident, ':n2' => ($note?:null), ':u2' => $adminName,
        ]);
        // Return updated row for client to refresh state
        $sel = $pdo->prepare("SELECT * FROM sys_daily_clinic_stats WHERE stat_date = :d");
        $sel->execute([':d' => $date]);
        _ds_send(['ok' => true, 'message' => 'บันทึกแล้ว', 'row' => $sel->fetch(PDO::FETCH_ASSOC)]);
    }

    case 'list': {
        $from = (string)($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
        $to   = (string)($_GET['to']   ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            _ds_send(['ok'=>false,'error'=>'bad date range']);
        }
        $st = $pdo->prepare("SELECT * FROM sys_daily_clinic_stats
            WHERE stat_date BETWEEN :f AND :t
            ORDER BY stat_date DESC LIMIT 90");
        $st->execute([':f' => $from, ':t' => $to]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        // Sum across range for summary
        $totalP = 0; $totalA = 0;
        foreach ($rows as $r) { $totalP += (int)$r['patient_count']; $totalA += (int)$r['accident_count']; }
        _ds_send(['ok' => true, 'rows' => $rows, 'summary' => [
            'days' => count($rows),
            'total_patients' => $totalP,
            'total_accidents' => $totalA,
        ]]);
    }

    default:
        _ds_send(['ok' => false, 'error' => 'unknown action: ' . $action]);
}
