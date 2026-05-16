<?php
// portal/ajax_nurse_productivity.php — Nurse OPD productivity AJAX
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper   = ($adminRole === 'superadmin');
$canAccess = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_nurse_productivity']);
if (!$canAccess) { echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้โมดูลนี้']); exit; }

$pdo       = db();
$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'system');

// ──────────────────────────────────────────────────────────────────
// AUDIT LOG (append-only, never throws to caller)
// ──────────────────────────────────────────────────────────────────
function np_audit(PDO $pdo, ?int $deptId, string $action, ?int $targetId, ?array $changes, int $adminId, string $adminName): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO sys_nurse_productivity_audit
            (dept_id, action, target_id, changes_json, performed_by, performed_by_name, ip_addr)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $deptId ?: null,
            $action,
            $targetId,
            $changes !== null ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
            $adminId ?: null,
            $adminName,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) { error_log('[np_audit] ' . $e->getMessage()); }
}

// ──────────────────────────────────────────────────────────────────
// SETTINGS — ensure singleton row per dept, returns assoc array
// ──────────────────────────────────────────────────────────────────
function np_get_settings(PDO $pdo, int $deptId): array
{
    $row = $pdo->prepare("SELECT * FROM sys_nurse_productivity_settings WHERE dept_id = ?");
    $row->execute([$deptId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;
    // create default row
    $deptName = (string)($pdo->query("SELECT name FROM sys_departments WHERE id=" . (int)$deptId)->fetchColumn() ?: '');
    $ins = $pdo->prepare("INSERT INTO sys_nurse_productivity_settings (dept_id, hospital_name) VALUES (?, ?)");
    $ins->execute([$deptId, $deptName]);
    $row->execute([$deptId]);
    return $row->fetch(PDO::FETCH_ASSOC);
}

// ──────────────────────────────────────────────────────────────────
// CALCULATION — productivity per row given thresholds
// ──────────────────────────────────────────────────────────────────
function np_calc(array $r, array $s): array
{
    $patients = (int)($r['patients'] ?? 0);
    $rn       = (int)($r['rn_count'] ?? 0);
    $head     = (int)($r['head_count'] ?? 0);
    $shift    = (float)($r['shift_hours'] ?? $s['shift_hours']);
    $hpv      = (float)$s['hpv'];
    $needed    = $patients * $hpv;
    $available = ($rn + $head) * $shift;
    $prod      = $available > 0 ? ($needed / $available) * 100 : 0.0;
    $status    = 'optimal';
    if ($prod < (float)$s['threshold_low'])  $status = 'over';
    elseif ($prod > (float)$s['threshold_high']) $status = 'under';
    return ['needed' => $needed, 'available' => $available, 'prod' => $prod, 'status' => $status];
}

// ──────────────────────────────────────────────────────────────────
// SCHEDULE INTEGRATION
//   Given an entry_date, look up sys_nurse_schedule_monthly for
//   that (year_be, month) and count nurses on duty for that day.
//   Returns ['rn' => N, 'head' => N] or null if no schedule found.
// ──────────────────────────────────────────────────────────────────
function np_derive_from_schedule(PDO $pdo, string $entryDate): ?array
{
    static $cache = []; // memoize per (year_be, month) lookup within request

    $ts = strtotime($entryDate);
    if (!$ts) return null;
    $yearAD  = (int)date('Y', $ts);
    $yearBE  = $yearAD + 543;
    $month   = (int)date('n', $ts);
    $day     = (int)date('j', $ts);
    $cacheKey = $yearBE . '-' . $month;

    if (!isset($cache[$cacheKey])) {
        $st = $pdo->prepare("SELECT schedule_json FROM sys_nurse_schedule_monthly WHERE year_be = ? AND month = ?");
        $st->execute([$yearBE, $month]);
        $sched = $st->fetchColumn();
        $globalRow = $pdo->query("SELECT nurses_json FROM sys_nurse_schedule_global WHERE id = 1")->fetchColumn();
        $cache[$cacheKey] = [
            'schedule' => $sched ? json_decode($sched, true) : null,
            'nurses'   => $globalRow ? json_decode($globalRow, true) : null,
        ];
    }
    $sched  = $cache[$cacheKey]['schedule'] ?? null;
    $nurses = $cache[$cacheKey]['nurses'] ?? null;
    if (!$sched || !is_array($sched) || !$nurses || !is_array($nurses)) return null;

    // shift code 'O' or empty = off; anything else = on duty
    $isWorking = static function ($code): bool {
        if ($code === null || $code === '' || $code === 'O' || $code === 'o') return false;
        return true;
    };

    $rnCount = 0; $headCount = 0;
    foreach ($nurses as $n) {
        if (!is_array($n)) continue;
        if (!empty($n['active']) && $n['active'] === false) continue; // active default true
        $position = (string)($n['position'] ?? '');
        $nid      = (string)($n['id'] ?? '');
        if ($nid === '') continue;
        $key      = $nid . '-' . $day;
        $code     = $sched[$key] ?? null;
        if (!$isWorking($code)) continue;
        if ($position === 'พยาบาลวิชาชีพ') {
            $rnCount++;
        } elseif (in_array($position, ['หัวหน้าหอผู้ป่วย', 'รองหัวหน้าหอผู้ป่วย', 'พยาบาลหัวหน้าเวร'], true)) {
            $headCount++;
        }
    }
    return ['rn' => $rnCount, 'head' => $headCount];
}

// ──────────────────────────────────────────────────────────────────
// Aggregate computed metrics for an array of daily rows
// ──────────────────────────────────────────────────────────────────
function np_aggregate(array $rows, array $settings): array
{
    if (!$rows) {
        return [
            'count' => 0, 'totalVisits' => 0, 'totalNeeded' => 0.0, 'totalAvailable' => 0.0,
            'avgProd' => 0.0, 'optimal' => 0, 'under' => 0, 'over' => 0,
        ];
    }
    $totalVisits = 0; $totalNeeded = 0.0; $totalAvailable = 0.0;
    $prodSum = 0.0; $opt = 0; $und = 0; $ovr = 0;
    foreach ($rows as $r) {
        $c = np_calc($r, $settings);
        $totalVisits    += (int)$r['patients'];
        $totalNeeded    += $c['needed'];
        $totalAvailable += $c['available'];
        $prodSum        += $c['prod'];
        if ($c['status'] === 'optimal') $opt++;
        elseif ($c['status'] === 'under') $und++;
        else $ovr++;
    }
    $n = count($rows);
    return [
        'count' => $n, 'totalVisits' => $totalVisits,
        'totalNeeded' => $totalNeeded, 'totalAvailable' => $totalAvailable,
        'avgProd' => $prodSum / $n,
        'optimal' => $opt, 'under' => $und, 'over' => $ovr,
    ];
}

// ──────────────────────────────────────────────────────────────────
// REQUEST DISPATCH
// ──────────────────────────────────────────────────────────────────
$action = $_REQUEST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ─────────────── DEPARTMENT LIST ───────────────
        case 'depts:list': {
            $rows = $pdo->query("SELECT id, name, sort_order FROM sys_departments WHERE active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'data' => $rows]); break;
        }

        // ─────────────── SETTINGS ───────────────
        case 'settings:get': {
            $deptId = (int)($_GET['dept_id'] ?? 0);
            if (!$deptId) throw new RuntimeException('ต้องระบุ dept_id');
            echo json_encode(['ok' => true, 'data' => np_get_settings($pdo, $deptId)]); break;
        }
        case 'settings:save': {
            if ($method !== 'POST') throw new RuntimeException('POST only');
            validate_csrf_or_die();
            $deptId = (int)($_POST['dept_id'] ?? 0);
            if (!$deptId) throw new RuntimeException('ต้องระบุ dept_id');
            np_get_settings($pdo, $deptId); // ensure exists
            $fields = [
                'hospital_name' => (string)($_POST['hospital_name'] ?? ''),
                'level'         => in_array(($_POST['level'] ?? 'F2'), ['A','S','M1','M2','F1','F2','F3'], true) ? $_POST['level'] : 'F2',
                'beds'          => ($_POST['beds'] ?? '') !== '' ? (int)$_POST['beds'] : null,
                'province'      => (string)($_POST['province'] ?? ''),
                'director'      => (string)($_POST['director'] ?? ''),
                'moph_code'     => substr((string)($_POST['moph_code'] ?? ''), 0, 10),
                'period_label'  => (string)($_POST['period_label'] ?? ''),
                'hpv'           => max(0.01, min(9.99, (float)($_POST['hpv'] ?? 0.24))),
                'shift_hours'   => max(0.5, min(24, (float)($_POST['shift_hours'] ?? 7))),
                'threshold_low' => max(1, min(999, (int)($_POST['threshold_low'] ?? 80))),
                'threshold_high'=> max(1, min(999, (int)($_POST['threshold_high'] ?? 110))),
            ];
            $stmt = $pdo->prepare("UPDATE sys_nurse_productivity_settings SET
                hospital_name = :hospital_name, level = :level, beds = :beds, province = :province,
                director = :director, moph_code = :moph_code, period_label = :period_label,
                hpv = :hpv, shift_hours = :shift_hours,
                threshold_low = :threshold_low, threshold_high = :threshold_high,
                updated_by = :uby
                WHERE dept_id = :did");
            $stmt->execute(array_merge($fields, ['uby' => $adminId ?: null, 'did' => $deptId]));
            np_audit($pdo, $deptId, 'settings_save', null, $fields, $adminId, $adminName);
            echo json_encode(['ok' => true, 'data' => np_get_settings($pdo, $deptId)]); break;
        }

        // ─────────────── DAILY ENTRIES ───────────────
        case 'daily:list': {
            $deptId = (int)($_GET['dept_id'] ?? 0);
            if (!$deptId) throw new RuntimeException('ต้องระบุ dept_id');
            $from = $_GET['from'] ?? null;
            $to   = $_GET['to'] ?? null;
            $sql = "SELECT * FROM sys_nurse_productivity_daily WHERE dept_id = :did";
            $bind = ['did' => $deptId];
            if ($from) { $sql .= " AND entry_date >= :from"; $bind['from'] = $from; }
            if ($to)   { $sql .= " AND entry_date <= :to"; $bind['to'] = $to; }
            $sql .= " ORDER BY entry_date ASC";
            $st = $pdo->prepare($sql);
            $st->execute($bind);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $settings = np_get_settings($pdo, $deptId);
            // append computed fields
            foreach ($rows as &$r) {
                $c = np_calc($r, $settings);
                $r['needed']    = round($c['needed'], 2);
                $r['available'] = round($c['available'], 2);
                $r['prod']      = round($c['prod'], 1);
                $r['status']    = $c['status'];
            }
            unset($r);
            echo json_encode(['ok' => true, 'data' => $rows, 'settings' => $settings]); break;
        }
        case 'daily:create':
        case 'daily:update': {
            if ($method !== 'POST') throw new RuntimeException('POST only');
            validate_csrf_or_die();
            $deptId = (int)($_POST['dept_id'] ?? 0);
            $date   = (string)($_POST['entry_date'] ?? '');
            if (!$deptId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new RuntimeException('ต้องระบุ dept_id และ entry_date (YYYY-MM-DD)');
            }
            np_get_settings($pdo, $deptId); // ensure exists

            $patients = max(0, (int)($_POST['patients'] ?? 0));
            $rn       = isset($_POST['rn_count']) && $_POST['rn_count'] !== '' ? max(0, (int)$_POST['rn_count']) : null;
            $head     = isset($_POST['head_count']) && $_POST['head_count'] !== '' ? max(0, (int)$_POST['head_count']) : null;
            $shift    = max(0.5, (float)($_POST['shift_hours'] ?? 7));
            $note     = substr((string)($_POST['note'] ?? ''), 0, 500);

            // Auto-fill from schedule if rn/head are blank
            $rnSource = 'manual'; $headSource = 'manual';
            if ($rn === null || $head === null) {
                $derived = np_derive_from_schedule($pdo, $date);
                if ($derived) {
                    if ($rn === null)   { $rn   = (int)$derived['rn'];   $rnSource   = 'schedule'; }
                    if ($head === null) { $head = (int)$derived['head']; $headSource = 'schedule'; }
                }
            }
            $rn   ??= 0;
            $head ??= 0;

            $id = (int)($_POST['id'] ?? 0);
            if ($action === 'daily:create' || !$id) {
                // Upsert by (dept_id, entry_date)
                $exists = $pdo->prepare("SELECT id FROM sys_nurse_productivity_daily WHERE dept_id = ? AND entry_date = ?");
                $exists->execute([$deptId, $date]);
                $existingId = (int)$exists->fetchColumn();
                if ($existingId) {
                    $id = $existingId;
                    goto do_update;
                }
                $ins = $pdo->prepare("INSERT INTO sys_nurse_productivity_daily
                    (dept_id, entry_date, patients, rn_count, head_count, shift_hours, note, rn_source, head_source, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$deptId, $date, $patients, $rn, $head, $shift, $note, $rnSource, $headSource, $adminId ?: null, $adminId ?: null]);
                $newId = (int)$pdo->lastInsertId();
                np_audit($pdo, $deptId, 'daily_create', $newId,
                    compact('date','patients','rn','head','shift','note','rnSource','headSource'),
                    $adminId, $adminName);
                echo json_encode(['ok' => true, 'id' => $newId, 'rn_source' => $rnSource, 'head_source' => $headSource]); break;
            }

            do_update:
            $upd = $pdo->prepare("UPDATE sys_nurse_productivity_daily SET
                patients = ?, rn_count = ?, head_count = ?, shift_hours = ?, note = ?,
                rn_source = ?, head_source = ?, updated_by = ?
                WHERE id = ? AND dept_id = ?");
            $upd->execute([$patients, $rn, $head, $shift, $note, $rnSource, $headSource, $adminId ?: null, $id, $deptId]);
            np_audit($pdo, $deptId, 'daily_update', $id,
                compact('date','patients','rn','head','shift','note','rnSource','headSource'),
                $adminId, $adminName);
            echo json_encode(['ok' => true, 'id' => $id, 'rn_source' => $rnSource, 'head_source' => $headSource]); break;
        }
        case 'daily:delete': {
            if ($method !== 'POST') throw new RuntimeException('POST only');
            validate_csrf_or_die();
            $deptId = (int)($_POST['dept_id'] ?? 0);
            $id     = (int)($_POST['id'] ?? 0);
            if (!$deptId || !$id) throw new RuntimeException('ต้องระบุ dept_id และ id');
            $snap = $pdo->prepare("SELECT * FROM sys_nurse_productivity_daily WHERE id = ? AND dept_id = ?");
            $snap->execute([$id, $deptId]);
            $row = $snap->fetch(PDO::FETCH_ASSOC);
            $del = $pdo->prepare("DELETE FROM sys_nurse_productivity_daily WHERE id = ? AND dept_id = ?");
            $del->execute([$id, $deptId]);
            np_audit($pdo, $deptId, 'daily_delete', $id, $row ?: null, $adminId, $adminName);
            echo json_encode(['ok' => true]); break;
        }
        case 'daily:bulk_delete': {
            if ($method !== 'POST') throw new RuntimeException('POST only');
            validate_csrf_or_die();
            $deptId = (int)($_POST['dept_id'] ?? 0);
            $idsRaw = $_POST['ids'] ?? '[]';
            $ids = is_array($idsRaw) ? $idsRaw : json_decode((string)$idsRaw, true);
            if (!is_array($ids) || !$ids) throw new RuntimeException('ต้องระบุ ids');
            $ids = array_map('intval', $ids);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $snap = $pdo->prepare("SELECT * FROM sys_nurse_productivity_daily WHERE id IN ($ph) AND dept_id = ?");
            $snap->execute(array_merge($ids, [$deptId]));
            $snapsById = [];
            foreach ($snap->fetchAll(PDO::FETCH_ASSOC) as $r) $snapsById[(int)$r['id']] = $r;
            $del = $pdo->prepare("DELETE FROM sys_nurse_productivity_daily WHERE id IN ($ph) AND dept_id = ?");
            $del->execute(array_merge($ids, [$deptId]));
            foreach ($ids as $i) np_audit($pdo, $deptId, 'daily_bulk_delete', $i, $snapsById[$i] ?? null, $adminId, $adminName);
            echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]); break;
        }
        case 'daily:delete_all': {
            if ($method !== 'POST') throw new RuntimeException('POST only');
            validate_csrf_or_die();
            $deptId = (int)($_POST['dept_id'] ?? 0);
            if (!$deptId) throw new RuntimeException('ต้องระบุ dept_id');
            $cnt = (int)$pdo->prepare("SELECT COUNT(*) FROM sys_nurse_productivity_daily WHERE dept_id = ?");
            $del = $pdo->prepare("DELETE FROM sys_nurse_productivity_daily WHERE dept_id = ?");
            $del->execute([$deptId]);
            np_audit($pdo, $deptId, 'daily_delete_all', null, ['deleted' => $del->rowCount()], $adminId, $adminName);
            echo json_encode(['ok' => true, 'deleted' => $del->rowCount()]); break;
        }

        // ─────────────── ANALYTICS ───────────────
        case 'analytics:summary': {
            // Daily view summary for given dept + date range
            $deptId = (int)($_GET['dept_id'] ?? 0);
            $from   = $_GET['from'] ?? null;
            $to     = $_GET['to'] ?? null;
            if (!$deptId) throw new RuntimeException('ต้องระบุ dept_id');
            $settings = np_get_settings($pdo, $deptId);

            $sql = "SELECT * FROM sys_nurse_productivity_daily WHERE dept_id = :did";
            $bind = ['did' => $deptId];
            if ($from) { $sql .= " AND entry_date >= :from"; $bind['from'] = $from; }
            if ($to)   { $sql .= " AND entry_date <= :to";   $bind['to'] = $to; }
            $sql .= " ORDER BY entry_date ASC";
            $st = $pdo->prepare($sql); $st->execute($bind);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $agg  = np_aggregate($rows, $settings);

            // Build chart datasets
            $labels = []; $prods = [];
            $dowSum = [0,0,0,0,0,0,0];
            $dowCount = [0,0,0,0,0,0,0];
            foreach ($rows as $r) {
                $c = np_calc($r, $settings);
                $ts = strtotime($r['entry_date']);
                $labels[] = date('d/m', $ts);
                $prods[]  = round($c['prod'], 1);
                $dow = (int)date('w', $ts);
                $dowSum[$dow]   += (int)$r['patients'];
                $dowCount[$dow] += 1;
            }
            $dowAvg = array_map(fn($s,$c) => $c > 0 ? $s / $c : 0, $dowSum, $dowCount);

            // Period delta — compare to prior period of same length
            $delta = null;
            if ($from && $to) {
                $fts = strtotime($from); $tts = strtotime($to);
                if ($fts && $tts && $tts >= $fts) {
                    $days = (int)floor(($tts - $fts) / 86400) + 1;
                    $prevTo   = date('Y-m-d', $fts - 86400);
                    $prevFrom = date('Y-m-d', $fts - 86400 * $days);
                    $st2 = $pdo->prepare("SELECT * FROM sys_nurse_productivity_daily WHERE dept_id = ? AND entry_date BETWEEN ? AND ?");
                    $st2->execute([$deptId, $prevFrom, $prevTo]);
                    $prevRows = $st2->fetchAll(PDO::FETCH_ASSOC);
                    $prevAgg = np_aggregate($prevRows, $settings);
                    $delta = [
                        'prevFrom' => $prevFrom, 'prevTo' => $prevTo,
                        'avgProd'     => $prevAgg['avgProd'] > 0 ? (($agg['avgProd'] - $prevAgg['avgProd']) / $prevAgg['avgProd']) * 100 : null,
                        'totalVisits' => $prevAgg['totalVisits'] > 0 ? (($agg['totalVisits'] - $prevAgg['totalVisits']) / $prevAgg['totalVisits']) * 100 : null,
                        'totalNeeded' => $prevAgg['totalNeeded'] > 0 ? (($agg['totalNeeded'] - $prevAgg['totalNeeded']) / $prevAgg['totalNeeded']) * 100 : null,
                    ];
                }
            }

            echo json_encode(['ok' => true, 'summary' => $agg, 'settings' => $settings,
                'labels' => $labels, 'prods' => $prods, 'dowAvg' => $dowAvg, 'delta' => $delta]);
            break;
        }
        case 'analytics:rollup_monthly': {
            // Last 12 months
            $deptId = (int)($_GET['dept_id'] ?? 0);
            if (!$deptId) throw new RuntimeException('ต้องระบุ dept_id');
            $settings = np_get_settings($pdo, $deptId);
            $st = $pdo->prepare("SELECT
                DATE_FORMAT(entry_date, '%Y-%m') AS ym,
                SUM(patients) AS visits,
                SUM(patients * :hpv) AS needed,
                SUM((rn_count + head_count) * shift_hours) AS available,
                COUNT(*) AS days
                FROM sys_nurse_productivity_daily
                WHERE dept_id = :did AND entry_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY ym ORDER BY ym ASC");
            $st->execute(['hpv' => $settings['hpv'], 'did' => $deptId]);
            $months = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $needed = (float)$m['needed'];
                $available = (float)$m['available'];
                $months[] = [
                    'ym'        => $m['ym'],
                    'visits'    => (int)$m['visits'],
                    'needed'    => round($needed, 1),
                    'available' => round($available, 1),
                    'avgProd'   => $available > 0 ? round(($needed / $available) * 100, 1) : 0,
                    'days'      => (int)$m['days'],
                ];
            }
            echo json_encode(['ok' => true, 'months' => $months, 'settings' => $settings]); break;
        }
        case 'analytics:rollup_yearly': {
            $deptId = (int)($_GET['dept_id'] ?? 0);
            if (!$deptId) throw new RuntimeException('ต้องระบุ dept_id');
            $settings = np_get_settings($pdo, $deptId);
            $st = $pdo->prepare("SELECT
                YEAR(entry_date) AS y,
                SUM(patients) AS visits,
                SUM(patients * :hpv) AS needed,
                SUM((rn_count + head_count) * shift_hours) AS available,
                COUNT(*) AS days
                FROM sys_nurse_productivity_daily
                WHERE dept_id = :did
                GROUP BY y ORDER BY y ASC");
            $st->execute(['hpv' => $settings['hpv'], 'did' => $deptId]);
            $years = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $y) {
                $needed = (float)$y['needed'];
                $available = (float)$y['available'];
                $years[] = [
                    'year'      => (int)$y['y'],
                    'yearBE'    => (int)$y['y'] + 543,
                    'visits'    => (int)$y['visits'],
                    'needed'    => round($needed, 1),
                    'available' => round($available, 1),
                    'avgProd'   => $available > 0 ? round(($needed / $available) * 100, 1) : 0,
                    'days'      => (int)$y['days'],
                ];
            }
            echo json_encode(['ok' => true, 'years' => $years, 'settings' => $settings]); break;
        }
        case 'analytics:cross_dept': {
            // Compare avg productivity across depts for a given period
            $from = $_GET['from'] ?? null;
            $to   = $_GET['to'] ?? null;
            $sqlWhere = "";
            $bind = [];
            if ($from) { $sqlWhere .= " AND d.entry_date >= :from"; $bind['from'] = $from; }
            if ($to)   { $sqlWhere .= " AND d.entry_date <= :to";   $bind['to'] = $to; }

            // Need each dept's hpv from settings
            $st = $pdo->prepare("SELECT
                dept.id AS dept_id, dept.name AS dept_name,
                COALESCE(s.hpv, 0.24) AS hpv,
                COALESCE(SUM(d.patients), 0) AS visits,
                COALESCE(SUM(d.patients * COALESCE(s.hpv, 0.24)), 0) AS needed,
                COALESCE(SUM((d.rn_count + d.head_count) * d.shift_hours), 0) AS available,
                COUNT(d.id) AS days
                FROM sys_departments dept
                LEFT JOIN sys_nurse_productivity_settings s ON s.dept_id = dept.id
                LEFT JOIN sys_nurse_productivity_daily d
                    ON d.dept_id = dept.id $sqlWhere
                WHERE dept.active = 1
                GROUP BY dept.id, dept.name, s.hpv
                ORDER BY dept.sort_order, dept.name");
            $st->execute($bind);
            $depts = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $needed = (float)$d['needed'];
                $available = (float)$d['available'];
                $depts[] = [
                    'deptId'   => (int)$d['dept_id'],
                    'deptName' => $d['dept_name'],
                    'visits'   => (int)$d['visits'],
                    'days'     => (int)$d['days'],
                    'avgProd'  => $available > 0 ? round(($needed / $available) * 100, 1) : 0,
                ];
            }
            echo json_encode(['ok' => true, 'depts' => $depts, 'from' => $from, 'to' => $to]); break;
        }

        // ─────────────── SCHEDULE INTEGRATION (preview) ───────────────
        case 'schedule:derive': {
            $date = (string)($_GET['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('date ต้องเป็น YYYY-MM-DD');
            $d = np_derive_from_schedule($pdo, $date);
            echo json_encode(['ok' => true, 'data' => $d]); break;
        }

        default: {
            echo json_encode(['ok' => false, 'message' => 'unknown action: ' . $action]); break;
        }
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
