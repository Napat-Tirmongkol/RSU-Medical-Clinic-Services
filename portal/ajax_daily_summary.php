<?php
// portal/ajax_daily_summary.php — Daily clinic summary aggregator (read-only)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper   = ($adminRole === 'superadmin');
$canAccess = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_daily_summary']);
if (!$canAccess) { echo json_encode(['ok' => false, 'message' => 'ไม่มีสิทธิ์ใช้โมดูลนี้']); exit; }

$pdo = db();
$action = $_REQUEST['action'] ?? 'summary:get';
$date   = $_REQUEST['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

// Optional: previous day for delta
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));

try {
    if ($action !== 'summary:get') throw new RuntimeException('unknown action');

    /* ─────────── 1) PRODUCTIVITY (per dept on this date) ─────────── */
    // Pull rows + per-dept settings, compute prod inline
    $prodRows = $pdo->prepare("
        SELECT d.dept_id, dept.name AS dept_name,
               d.patients, d.rn_count, d.head_count, d.shift_hours,
               d.rn_source, d.head_source,
               COALESCE(s.hpv, 0.24)         AS hpv,
               COALESCE(s.threshold_low, 80) AS thr_low,
               COALESCE(s.threshold_high,110) AS thr_high
        FROM sys_nurse_productivity_daily d
        LEFT JOIN sys_departments dept ON dept.id = d.dept_id
        LEFT JOIN sys_nurse_productivity_settings s ON s.dept_id = d.dept_id
        WHERE d.entry_date = ?
        ORDER BY dept.sort_order, dept.name
    ");
    $prodRows->execute([$date]);
    $prodList = []; $totVisits = 0; $prodSum = 0.0; $prodN = 0;
    foreach ($prodRows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $needed = (int)$r['patients'] * (float)$r['hpv'];
        $avail  = ((int)$r['rn_count'] + (int)$r['head_count']) * (float)$r['shift_hours'];
        $prod   = $avail > 0 ? ($needed / $avail) * 100 : 0;
        $status = 'optimal';
        if ($prod < (float)$r['thr_low'])  $status = 'over';
        elseif ($prod > (float)$r['thr_high']) $status = 'under';
        $prodList[] = [
            'deptId'    => (int)$r['dept_id'],
            'deptName'  => $r['dept_name'] ?: '—',
            'patients'  => (int)$r['patients'],
            'rn'        => (int)$r['rn_count'],
            'head'      => (int)$r['head_count'],
            'prod'      => round($prod, 1),
            'status'    => $status,
            'rnSource'  => $r['rn_source'],
            'headSource'=> $r['head_source'],
        ];
        $totVisits += (int)$r['patients'];
        $prodSum   += $prod;
        $prodN++;
    }
    // Previous-day visits for delta
    $prevVisits = (int)$pdo->prepare("SELECT COALESCE(SUM(patients), 0) FROM sys_nurse_productivity_daily WHERE entry_date = ?");
    $st = $pdo->prepare("SELECT COALESCE(SUM(patients), 0) FROM sys_nurse_productivity_daily WHERE entry_date = ?");
    $st->execute([$prevDate]);
    $prevVisits = (int)$st->fetchColumn();

    $productivity = [
        'totalVisits' => $totVisits,
        'avgProd'     => $prodN ? round($prodSum / $prodN, 1) : 0,
        'deptCount'   => $prodN,
        'list'        => $prodList,
        'prevVisits'  => $prevVisits,
        'visitsDelta' => $prevVisits > 0 ? round((($totVisits - $prevVisits) / $prevVisits) * 100, 1) : null,
    ];

    /* ─────────── 2) CASH BOOK (income/expense on this date) ─────────── */
    $finStats = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN kind='income'  THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END), 0) AS expense,
            COUNT(*) AS txn_count
        FROM sys_finance_transactions
        WHERE txn_date = ?
    ");
    $finStats->execute([$date]);
    $finRow = $finStats->fetch(PDO::FETCH_ASSOC);

    // Top 5 categories on this date
    $finCats = $pdo->prepare("
        SELECT c.id, c.name, c.kind, c.icon, c.color,
               COUNT(t.id) AS cnt, COALESCE(SUM(t.amount), 0) AS total
        FROM sys_finance_transactions t
        LEFT JOIN sys_finance_categories c ON c.id = t.category_id
        WHERE t.txn_date = ?
        GROUP BY c.id, c.name, c.kind, c.icon, c.color
        ORDER BY total DESC
        LIMIT 6
    ");
    $finCats->execute([$date]);

    // Previous-day income/expense for delta
    $finPrev = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN kind='income' THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN kind='expense' THEN amount ELSE 0 END), 0) AS expense
        FROM sys_finance_transactions WHERE txn_date = ?");
    $finPrev->execute([$prevDate]);
    $finPrevRow = $finPrev->fetch(PDO::FETCH_ASSOC);

    $finance = [
        'income'    => (float)$finRow['income'],
        'expense'   => (float)$finRow['expense'],
        'net'       => (float)$finRow['income'] - (float)$finRow['expense'],
        'txnCount'  => (int)$finRow['txn_count'],
        'topCategories' => array_map(fn($r) => [
            'name'  => $r['name'] ?: '— ไม่ระบุหมวด —',
            'kind'  => $r['kind'] ?: 'expense',
            'icon'  => $r['icon'],
            'color' => $r['color'],
            'count' => (int)$r['cnt'],
            'total' => (float)$r['total'],
        ], $finCats->fetchAll(PDO::FETCH_ASSOC)),
        'incomeDelta'  => (float)$finPrevRow['income']  > 0 ? round((((float)$finRow['income']  - (float)$finPrevRow['income'])  / (float)$finPrevRow['income'])  * 100, 1) : null,
        'expenseDelta' => (float)$finPrevRow['expense'] > 0 ? round((((float)$finRow['expense'] - (float)$finPrevRow['expense']) / (float)$finPrevRow['expense']) * 100, 1) : null,
    ];

    /* ─────────── 3) STOCK (consumables movement + alerts) ─────────── */
    $stockMov = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN txn_type='receive' THEN qty_change ELSE 0 END), 0) AS qty_in,
            COALESCE(SUM(CASE WHEN txn_type='issue'   THEN -qty_change ELSE 0 END), 0) AS qty_out,
            COUNT(DISTINCT consumable_id) AS items_touched,
            COUNT(*) AS txn_count
        FROM consumable_transactions WHERE txn_date = ?
    ");
    $stockMov->execute([$date]);
    $stRow = $stockMov->fetch(PDO::FETCH_ASSOC);

    // Top items issued today
    $stockTop = $pdo->prepare("
        SELECT c.id, c.name, c.unit_piece, SUM(-t.qty_change) AS qty_issued, c.qty_on_hand, c.min_stock
        FROM consumable_transactions t
        INNER JOIN consumables c ON c.id = t.consumable_id
        WHERE t.txn_date = ? AND t.txn_type = 'issue'
        GROUP BY c.id, c.name, c.unit_piece, c.qty_on_hand, c.min_stock
        ORDER BY qty_issued DESC
        LIMIT 6
    ");
    $stockTop->execute([$date]);

    // Low-stock items (qty_on_hand ≤ min_stock and min_stock > 0)
    $stockLow = $pdo->query("
        SELECT id, name, unit_piece, qty_on_hand, min_stock
        FROM consumables
        WHERE min_stock > 0 AND qty_on_hand <= min_stock
        ORDER BY (qty_on_hand / GREATEST(min_stock,1)) ASC
        LIMIT 8
    ");

    $stock = [
        'qtyIn'        => (int)$stRow['qty_in'],
        'qtyOut'       => (int)$stRow['qty_out'],
        'itemsTouched' => (int)$stRow['items_touched'],
        'txnCount'     => (int)$stRow['txn_count'],
        'topIssued'    => array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'unit' => $r['unit_piece'],
            'qty' => (int)$r['qty_issued'], 'onHand' => (int)$r['qty_on_hand'], 'min' => (int)$r['min_stock'],
        ], $stockTop->fetchAll(PDO::FETCH_ASSOC)),
        'lowStock'     => array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'unit' => $r['unit_piece'],
            'onHand' => (int)$r['qty_on_hand'], 'min' => (int)$r['min_stock'],
        ], $stockLow->fetchAll(PDO::FETCH_ASSOC)),
    ];

    /* ─────────── 4) OTHER (gold card / insurance / schedule) ─────────── */
    // Gold card transfers today — count "added" or "transferred" actions
    $goldCnt = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM gold_card_history WHERE DATE(changed_at) = ?");
        $st->execute([$date]);
        $goldCnt = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* table missing in some installs */ }

    // Insurance batches uploaded today
    $insCnt = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM insurance_batch WHERE DATE(uploaded_at) = ?");
        $st->execute([$date]);
        $insCnt = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* table missing */ }

    // Asset events today
    $assetCnt = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM asset_movements WHERE DATE(moved_at) = ?");
        $st->execute([$date]);
        $assetCnt = (int)$st->fetchColumn();
    } catch (Throwable $e) { /* table missing */ }

    // EDMS docs today
    $docsIn = 0; $docsOut = 0;
    try {
        $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN doc_direction='in'  THEN 1 ELSE 0 END), 0) AS d_in,
            COALESCE(SUM(CASE WHEN doc_direction='out' THEN 1 ELSE 0 END), 0) AS d_out
            FROM sys_doc_documents WHERE DATE(created_at) = ?");
        $st->execute([$date]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $docsIn = (int)($r['d_in'] ?? 0);
        $docsOut = (int)($r['d_out'] ?? 0);
    } catch (Throwable $e) { /* col/table missing */ }

    // Schedule today — parse nurses_json + schedule_json
    $scheduleList = [];
    try {
        $ts = strtotime($date);
        $yearBE = (int)date('Y', $ts) + 543;
        $month = (int)date('n', $ts);
        $day = (int)date('j', $ts);
        $st = $pdo->prepare("SELECT schedule_json FROM sys_nurse_schedule_monthly WHERE year_be = ? AND month = ?");
        $st->execute([$yearBE, $month]);
        $schedJson = $st->fetchColumn();
        $g = $pdo->query("SELECT nurses_json FROM sys_nurse_schedule_global WHERE id = 1")->fetchColumn();
        if ($schedJson && $g) {
            $sched = json_decode($schedJson, true) ?: [];
            $nurses = json_decode($g, true) ?: [];
            foreach ($nurses as $n) {
                if (!is_array($n)) continue;
                $nid = (string)($n['id'] ?? '');
                $code = $sched[$nid . '-' . $day] ?? null;
                if (!$code || $code === 'O' || $code === 'o') continue;
                $scheduleList[] = [
                    'name'     => (string)($n['name'] ?? '—'),
                    'position' => (string)($n['position'] ?? ''),
                    'shift'    => (string)$code,
                ];
            }
        }
    } catch (Throwable $e) { /* schedule tables missing */ }

    $other = [
        'goldCard'    => $goldCnt,
        'insurance'   => $insCnt,
        'assetEvents' => $assetCnt,
        'docs'        => ['in' => $docsIn, 'out' => $docsOut],
        'schedule'    => $scheduleList,
    ];

    /* ─────────── HEADLINE METRICS ─────────── */
    $isWeekend = in_array((int)date('w', strtotime($date)), [0, 6], true);
    $headline = [
        'date'      => $date,
        'dateBE'    => (int)date('Y', strtotime($date)) + 543,
        'dayName'   => ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'][(int)date('w', strtotime($date))],
        'isWeekend' => $isWeekend,
        'isToday'   => $date === date('Y-m-d'),
        'totals'    => [
            'visits'  => $totVisits,
            'revenue' => (float)$finRow['income'],
            'expense' => (float)$finRow['expense'],
            'alerts'  => count($stock['lowStock']),
        ],
    ];

    echo json_encode([
        'ok'           => true,
        'headline'     => $headline,
        'productivity' => $productivity,
        'finance'      => $finance,
        'stock'        => $stock,
        'other'        => $other,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
