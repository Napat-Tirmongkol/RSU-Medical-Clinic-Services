<?php
/**
 * portal/ajax_accident_log.php
 *
 * Accident Log AJAX endpoint — บันทึกอุบัติเหตุรายวัน
 *
 * Actions:
 *   - GET  list                 (range from/to)
 *   - POST daily:create         (entry_date, accident_count, note)
 *   - POST daily:update         (id, ...)
 *   - POST daily:delete         (id)
 *   - GET  analytics:monthly    (12 เดือนล่าสุด — bar chart)
 *
 * Permission: superadmin / admin / มี access_nurse_productivity
 * (piggyback flag เดิม — basic version ยังไม่เพิ่ม access_accident_log)
 *
 * Schema auto-migrate: sys_accident_daily (entry_date UNIQUE)
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

/* ── Permission gate ─────────────────────────────────────────────────────── */
$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$canUse    = $isSuper || $adminRole === 'admin' || !empty($_SESSION['access_nurse_productivity']);
if (!$canUse) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

/* ── CSRF (POST only) ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('validate_csrf_or_die')) {
        validate_csrf_or_die();
    }
}

$pdo = db();

/* ── Auto-migrate schema (idempotent) ────────────────────────────────────── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_accident_daily (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_date DATE NOT NULL,
        accident_count INT UNSIGNED NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_by VARCHAR(100) NULL,
        updated_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_entry_date (entry_date),
        INDEX idx_date (entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    error_log('[ajax_accident_log migrate] ' . $e->getMessage());
}

$action  = $_REQUEST['action'] ?? '';
$_who    = $_SESSION['admin_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'admin';

try {
    switch ($action) {
        /* ───────── List with date range filter ───────── */
        case 'list': {
            $from = $_GET['from'] ?? null;
            $to   = $_GET['to']   ?? null;
            $sql  = "SELECT id, entry_date, accident_count, note, created_by, updated_by, updated_at
                     FROM sys_accident_daily WHERE 1=1";
            $bind = [];
            if ($from) { $sql .= " AND entry_date >= :from"; $bind['from'] = $from; }
            if ($to)   { $sql .= " AND entry_date <= :to";   $bind['to']   = $to;   }
            $sql .= " ORDER BY entry_date ASC";
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Summary across range
            $sumSql = "SELECT
                COUNT(*)                    AS day_count,
                COALESCE(SUM(accident_count), 0) AS total,
                COALESCE(MAX(accident_count), 0) AS peak,
                COALESCE(AVG(accident_count), 0) AS avg_per_day
                FROM sys_accident_daily WHERE 1=1";
            if ($from) $sumSql .= " AND entry_date >= :from";
            if ($to)   $sumSql .= " AND entry_date <= :to";
            $st2 = $pdo->prepare($sumSql);
            $st2->execute($bind);
            $sum = $st2->fetch(PDO::FETCH_ASSOC) ?: ['day_count'=>0,'total'=>0,'peak'=>0,'avg_per_day'=>0];

            // Peak date(s) — single row จะแสดง 1 วันที่
            $peakDate = null;
            if ((int)$sum['peak'] > 0) {
                $st3 = $pdo->prepare("SELECT entry_date FROM sys_accident_daily
                                      WHERE accident_count = :peak"
                                     . ($from ? " AND entry_date >= :from" : "")
                                     . ($to   ? " AND entry_date <= :to"   : "")
                                     . " ORDER BY entry_date DESC LIMIT 1");
                $st3->execute(array_merge(['peak' => (int)$sum['peak']], $bind));
                $peakDate = $st3->fetchColumn() ?: null;
            }

            echo json_encode([
                'ok'      => true,
                'data'    => $rows,
                'summary' => [
                    'day_count'   => (int)$sum['day_count'],
                    'total'       => (int)$sum['total'],
                    'peak'        => (int)$sum['peak'],
                    'peak_date'   => $peakDate,
                    'avg_per_day' => round((float)$sum['avg_per_day'], 2),
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        /* ───────── Create one row ───────── */
        case 'daily:create': {
            $date  = trim((string)($_POST['entry_date'] ?? date('Y-m-d')));
            $count = max(0, (int)($_POST['accident_count'] ?? 0));
            $note  = trim((string)($_POST['note'] ?? ''));

            // Validate date
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                throw new RuntimeException('รูปแบบวันที่ไม่ถูกต้อง');
            }

            $st = $pdo->prepare("INSERT INTO sys_accident_daily
                (entry_date, accident_count, note, created_by, updated_by)
                VALUES (:d, :c, :n, :cu, :uu)
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
            $st->execute([':d' => $date, ':c' => $count, ':n' => $note, ':cu' => $_who, ':uu' => $_who]);
            $id = (int)$pdo->lastInsertId();

            // If row already existed (LAST_INSERT_ID returns existing id), don't overwrite
            $existed = $st->rowCount() === 0 || $st->rowCount() === 2;
            if (function_exists('log_activity')) {
                log_activity('Accident Log', $existed ? "วันที่ $date มีอยู่แล้ว — ข้าม" : "เพิ่ม $date จำนวน $count");
            }
            echo json_encode([
                'ok' => true,
                'id' => $id,
                'duplicate' => $existed,
                'message' => $existed ? "วันที่ $date มีรายการอยู่แล้ว — กดที่แถวเดิมเพื่อแก้ไข" : 'เพิ่มแถวสำเร็จ',
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        /* ───────── Update one row ───────── */
        case 'daily:update': {
            $id    = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('ต้องระบุ id');
            $date  = trim((string)($_POST['entry_date'] ?? ''));
            $count = max(0, (int)($_POST['accident_count'] ?? 0));
            $note  = trim((string)($_POST['note'] ?? ''));

            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                throw new RuntimeException('รูปแบบวันที่ไม่ถูกต้อง');
            }

            // Check unique constraint manually so we can return a friendly error
            $dup = $pdo->prepare("SELECT id FROM sys_accident_daily WHERE entry_date = :d AND id != :id LIMIT 1");
            $dup->execute([':d' => $date, ':id' => $id]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException("วันที่ $date มีรายการอยู่แล้ว — ลบรายการเดิมก่อนค่อยเปลี่ยน");
            }

            $st = $pdo->prepare("UPDATE sys_accident_daily
                SET entry_date = :d, accident_count = :c, note = :n, updated_by = :u
                WHERE id = :id");
            $st->execute([':d' => $date, ':c' => $count, ':n' => $note, ':u' => $_who, ':id' => $id]);
            if (function_exists('log_activity')) {
                log_activity('Accident Log', "แก้ไข id=$id วันที่ $date จำนวน $count");
            }
            echo json_encode(['ok' => true]);
            break;
        }

        /* ───────── Delete one row ───────── */
        case 'daily:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('ต้องระบุ id');
            $st = $pdo->prepare("DELETE FROM sys_accident_daily WHERE id = :id");
            $st->execute([':id' => $id]);
            if (function_exists('log_activity')) {
                log_activity('Accident Log', "ลบ id=$id");
            }
            echo json_encode(['ok' => true]);
            break;
        }

        /* ───────── Monthly aggregate for chart (12 months back) ───────── */
        case 'analytics:monthly': {
            $from = $_GET['from'] ?? null;
            $to   = $_GET['to']   ?? null;
            $sql  = "SELECT DATE_FORMAT(entry_date, '%Y-%m') AS ym,
                            SUM(accident_count) AS total,
                            COUNT(*) AS days_logged
                     FROM sys_accident_daily WHERE 1=1";
            $bind = [];
            if ($from) { $sql .= " AND entry_date >= :from"; $bind['from'] = $from; }
            if ($to)   { $sql .= " AND entry_date <= :to";   $bind['to']   = $to;   }
            $sql .= " GROUP BY ym ORDER BY ym ASC";
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            throw new RuntimeException('action ไม่รู้จัก');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
