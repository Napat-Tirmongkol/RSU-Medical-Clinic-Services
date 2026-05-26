<?php
/**
 * portal/ajax_gold_card_stats.php
 *
 * Gold Card Monthly Statistics — สถิติยอดสมาชิกบัตรทอง ณ สิ้นเดือน
 *
 * Actions:
 *   - GET  list                  (range from_year/to_year พ.ศ.)
 *   - POST monthly:create        (year_be, month, member_count, note)
 *   - POST monthly:update        (id, ...)
 *   - POST monthly:delete        (id)
 *   - GET  analytics:yearly      (เปรียบเทียบ year-over-year)
 *
 * Permission: superadmin / access_insurance (เหมือน gold_card.php)
 *
 * Schema: sys_gold_card_monthly_stats UNIQUE (year_be, month)
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$canUse    = $isSuper || !empty($_SESSION['access_insurance']);
if (!$canUse) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ — ต้องมี access_insurance']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('validate_csrf_or_die')) validate_csrf_or_die();
}

$pdo = db();

/* Auto-migrate */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_gold_card_monthly_stats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        year_be SMALLINT UNSIGNED NOT NULL,
        month TINYINT UNSIGNED NOT NULL,
        member_count INT UNSIGNED NOT NULL DEFAULT 0,
        note TEXT NULL,
        created_by VARCHAR(100) NULL,
        updated_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_year_month (year_be, month),
        INDEX idx_year_month (year_be, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    error_log('[ajax_gold_card_stats migrate] ' . $e->getMessage());
}

$action = $_REQUEST['action'] ?? '';
$_who   = $_SESSION['admin_name'] ?? $_SESSION['name'] ?? $_SESSION['username'] ?? 'admin';

try {
    switch ($action) {
        /* ───────── List with year range filter ───────── */
        case 'list': {
            $fromYear = isset($_GET['from_year']) && $_GET['from_year'] !== '' ? (int)$_GET['from_year'] : null;
            $toYear   = isset($_GET['to_year'])   && $_GET['to_year']   !== '' ? (int)$_GET['to_year']   : null;

            $sql  = "SELECT id, year_be, month, member_count, note, updated_by, updated_at
                     FROM sys_gold_card_monthly_stats WHERE 1=1";
            $bind = [];
            if ($fromYear) { $sql .= " AND year_be >= :fy"; $bind['fy'] = $fromYear; }
            if ($toYear)   { $sql .= " AND year_be <= :ty"; $bind['ty'] = $toYear; }
            $sql .= " ORDER BY year_be ASC, month ASC";
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Summary
            $sumSql = "SELECT
                COUNT(*)                          AS row_count,
                COALESCE(MAX(member_count), 0)    AS peak,
                COALESCE(MIN(member_count), 0)    AS low
                FROM sys_gold_card_monthly_stats WHERE 1=1";
            if ($fromYear) $sumSql .= " AND year_be >= :fy";
            if ($toYear)   $sumSql .= " AND year_be <= :ty";
            $st2 = $pdo->prepare($sumSql);
            $st2->execute($bind);
            $sum = $st2->fetch(PDO::FETCH_ASSOC) ?: ['row_count'=>0,'peak'=>0,'low'=>0];

            // Latest entry (full row)
            $latest = null;
            if (count($rows) > 0) {
                $latest = end($rows);
            }

            // Peak / low detail
            $peakRow = null; $lowRow = null;
            if ((int)$sum['peak'] > 0 && count($rows) > 0) {
                foreach ($rows as $r) {
                    if ((int)$r['member_count'] === (int)$sum['peak'] && !$peakRow) $peakRow = $r;
                    if ((int)$r['member_count'] === (int)$sum['low']  && !$lowRow)  $lowRow  = $r;
                }
            }

            echo json_encode([
                'ok'      => true,
                'data'    => $rows,
                'summary' => [
                    'row_count'    => (int)$sum['row_count'],
                    'latest_value' => $latest ? (int)$latest['member_count'] : 0,
                    'latest_label' => $latest ? sprintf('%02d/%d', (int)$latest['month'], (int)$latest['year_be']) : null,
                    'peak'         => (int)$sum['peak'],
                    'peak_label'   => $peakRow ? sprintf('%02d/%d', (int)$peakRow['month'], (int)$peakRow['year_be']) : null,
                    'low'          => (int)$sum['low'],
                    'low_label'    => $lowRow ? sprintf('%02d/%d', (int)$lowRow['month'], (int)$lowRow['year_be']) : null,
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        /* ───────── Create one row ───────── */
        case 'monthly:create': {
            $year  = (int)($_POST['year_be'] ?? 0);
            $month = (int)($_POST['month'] ?? 0);
            $count = max(0, (int)($_POST['member_count'] ?? 0));
            $note  = trim((string)($_POST['note'] ?? ''));

            // Normalize: if user sent ค.ศ. by mistake, convert to พ.ศ.
            if ($year > 0 && $year < 2400) $year += 543;
            if ($year < 2500 || $year > 2700) throw new RuntimeException('ปี พ.ศ. ต้องอยู่ในช่วง 2500-2700');
            if ($month < 1 || $month > 12) throw new RuntimeException('เดือนต้อง 1-12');

            $st = $pdo->prepare("INSERT INTO sys_gold_card_monthly_stats
                (year_be, month, member_count, note, created_by, updated_by)
                VALUES (:y, :m, :c, :n, :cu, :uu)
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
            $st->execute([':y'=>$year, ':m'=>$month, ':c'=>$count, ':n'=>$note, ':cu'=>$_who, ':uu'=>$_who]);
            $id = (int)$pdo->lastInsertId();
            $existed = $st->rowCount() === 0 || $st->rowCount() === 2;

            if (function_exists('log_activity')) {
                log_activity('Gold Card Stats', $existed
                    ? "เดือน $month/$year มีอยู่แล้ว — ข้าม"
                    : "เพิ่ม $month/$year จำนวน $count");
            }
            echo json_encode([
                'ok' => true,
                'id' => $id,
                'duplicate' => $existed,
                'message' => $existed
                    ? "เดือน $month/$year มีรายการอยู่แล้ว — กดที่แถวเดิมเพื่อแก้ไข"
                    : 'เพิ่มแถวสำเร็จ',
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        /* ───────── Update one row ───────── */
        case 'monthly:update': {
            $id    = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('ต้องระบุ id');
            $year  = (int)($_POST['year_be'] ?? 0);
            $month = (int)($_POST['month'] ?? 0);
            $count = max(0, (int)($_POST['member_count'] ?? 0));
            $note  = trim((string)($_POST['note'] ?? ''));

            if ($year > 0 && $year < 2400) $year += 543;
            if ($year < 2500 || $year > 2700) throw new RuntimeException('ปี พ.ศ. ต้องอยู่ในช่วง 2500-2700');
            if ($month < 1 || $month > 12) throw new RuntimeException('เดือนต้อง 1-12');

            // Unique check (exclude self)
            $dup = $pdo->prepare("SELECT id FROM sys_gold_card_monthly_stats
                WHERE year_be = :y AND month = :m AND id != :id LIMIT 1");
            $dup->execute([':y'=>$year, ':m'=>$month, ':id'=>$id]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException("เดือน $month/$year มีรายการอยู่แล้ว — ลบรายการเดิมก่อนค่อยเปลี่ยน");
            }

            $st = $pdo->prepare("UPDATE sys_gold_card_monthly_stats
                SET year_be = :y, month = :m, member_count = :c, note = :n, updated_by = :u
                WHERE id = :id");
            $st->execute([':y'=>$year, ':m'=>$month, ':c'=>$count, ':n'=>$note, ':u'=>$_who, ':id'=>$id]);
            if (function_exists('log_activity')) {
                log_activity('Gold Card Stats', "แก้ไข id=$id $month/$year = $count");
            }
            echo json_encode(['ok' => true]);
            break;
        }

        /* ───────── Delete one row ───────── */
        case 'monthly:delete': {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('ต้องระบุ id');
            $st = $pdo->prepare("DELETE FROM sys_gold_card_monthly_stats WHERE id = :id");
            $st->execute([':id' => $id]);
            if (function_exists('log_activity')) log_activity('Gold Card Stats', "ลบ id=$id");
            echo json_encode(['ok' => true]);
            break;
        }

        /* ───────── Yearly comparison data ───────── */
        case 'analytics:yearly': {
            $fromYear = isset($_GET['from_year']) && $_GET['from_year'] !== '' ? (int)$_GET['from_year'] : null;
            $toYear   = isset($_GET['to_year'])   && $_GET['to_year']   !== '' ? (int)$_GET['to_year']   : null;
            $sql  = "SELECT year_be, month, member_count FROM sys_gold_card_monthly_stats WHERE 1=1";
            $bind = [];
            if ($fromYear) { $sql .= " AND year_be >= :fy"; $bind['fy'] = $fromYear; }
            if ($toYear)   { $sql .= " AND year_be <= :ty"; $bind['ty'] = $toYear; }
            $sql .= " ORDER BY year_be ASC, month ASC";
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
            break;
        }

        default:
            throw new RuntimeException('action ไม่รู้จัก');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
