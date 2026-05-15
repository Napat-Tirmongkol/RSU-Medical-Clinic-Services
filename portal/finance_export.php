<?php
/**
 * portal/finance_export.php — Cash Book CSV export
 *
 * Streams a CSV of sys_finance_transactions using the same filter
 * params the on-page list uses (from, to, kind, category_id, q).
 *
 * Uses the same portal auth gate as ajax_finance.php so only users
 * with finance access can pull the data.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? 'editor';
$isSuper   = ($adminRole === 'superadmin');
$canFinance = $isSuper || ($adminRole === 'admin') || !empty($_SESSION['access_finance']);
if (!$canFinance) {
    http_response_code(403);
    exit('Access denied');
}

$pdo = db();

$from  = $_GET['from'] ?? date('Y-m-01');
$to    = $_GET['to']   ?? date('Y-m-t');
$kind  = $_GET['kind'] ?? '';
$catId = (int)($_GET['category_id'] ?? 0);
$q     = trim((string)($_GET['q'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    exit('Invalid date range');
}

$where = ['t.txn_date BETWEEN :from AND :to'];
$params = [':from' => $from, ':to' => $to];
if ($kind === 'income' || $kind === 'expense') { $where[] = 't.kind = :k';   $params[':k']   = $kind; }
if ($catId > 0)                                 { $where[] = 't.category_id = :cid'; $params[':cid'] = $catId; }
if ($q !== '') {
    $where[] = '(t.description LIKE :q OR t.reference LIKE :q OR t.receipt_no LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT t.txn_date, t.kind, c.name AS category_name,
    t.description, t.amount, t.reference, t.receipt_no, t.payment_method, t.note,
    t.source_module, t.source_id, t.created_at
    FROM sys_finance_transactions t
    LEFT JOIN sys_finance_categories c ON c.id = t.category_id
    {$whereSql}
    ORDER BY t.txn_date ASC, t.id ASC");
$stmt->execute($params);

$filename = sprintf('cashbook_%s_to_%s.csv', $from, $to);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel opens Thai text correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'วันที่',
    'ประเภท',
    'หมวด',
    'รายละเอียด',
    'จำนวนเงิน',
    'เลขที่ใบเสร็จ',
    'อ้างอิง',
    'วิธีชำระ',
    'หมายเหตุ',
    'แหล่งที่มา',
    'บันทึกเมื่อ',
]);

$totalIncome = 0.0;
$totalExpense = 0.0;
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $amt = (float)$r['amount'];
    if ($r['kind'] === 'income')  $totalIncome  += $amt;
    if ($r['kind'] === 'expense') $totalExpense += $amt;

    $src = $r['source_module']
        ? trim($r['source_module'] . ' #' . ($r['source_id'] ?? ''))
        : '';

    fputcsv($out, [
        $r['txn_date'],
        $r['kind'] === 'income' ? 'รายได้' : 'รายจ่าย',
        $r['category_name'] ?? '',
        $r['description']   ?? '',
        number_format($amt, 2, '.', ''),
        $r['receipt_no']    ?? '',
        $r['reference']     ?? '',
        $r['payment_method'] ?? '',
        $r['note']          ?? '',
        $src,
        $r['created_at']    ?? '',
    ]);
}

// Summary row
fputcsv($out, []);
fputcsv($out, ['', '', '', 'รวมรายได้',  number_format($totalIncome, 2, '.', '')]);
fputcsv($out, ['', '', '', 'รวมรายจ่าย', number_format($totalExpense, 2, '.', '')]);
fputcsv($out, ['', '', '', 'สุทธิ',     number_format($totalIncome - $totalExpense, 2, '.', '')]);

fclose($out);
exit;
