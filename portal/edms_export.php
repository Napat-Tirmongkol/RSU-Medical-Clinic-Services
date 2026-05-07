<?php
/**
 * portal/edms_export.php — Export EDMS documents to CSV
 *
 * GET params:
 *   format=list (default) | reports
 *   For format=list:
 *     type=incoming|outgoing|internal|circular (optional, ALL if missing)
 *     s, status, priority, from, to (same as list view filters)
 *   For format=reports:
 *     from, to (date range — KPI / status / category breakdown)
 *
 * Output: CSV with UTF-8 BOM (Excel-friendly Thai)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$isSuper   = ($adminRole === 'superadmin');
$hasEdms   = !empty($_SESSION['access_edms']);

if (!$isSuper && !$hasEdms) {
    http_response_code(403);
    exit('Permission denied');
}

$format = $_GET['format'] ?? 'list';
$pdo = db();

function edms_csv_send_headers(string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
}

function edms_csv_row(array $cols): void
{
    $out = fopen('php://output', 'w');
    fputcsv($out, $cols);
    fclose($out);
}

if ($format === 'reports') {
    $to   = $_GET['to']   ?? date('Y-m-d');
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-90 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-90 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
    $range = [$from, $to . ' 23:59:59'];

    edms_csv_send_headers("edms_reports_{$from}_{$to}.csv");

    edms_csv_row(['EDMS Reports']);
    edms_csv_row(['Period', $from . ' to ' . $to]);
    edms_csv_row(['Generated', date('Y-m-d H:i:s')]);
    edms_csv_row([]);

    // KPI section
    $total = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_documents")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_documents WHERE created_at BETWEEN ? AND ?");
    $st->execute($range);
    $inRange = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM sys_doc_documents WHERE status IN ('completed','archived') AND created_at BETWEEN ? AND ?");
    $st->execute($range);
    $completed = (int)$st->fetchColumn();
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM sys_doc_documents WHERE status IN ('routing','in_progress')")->fetchColumn();

    edms_csv_row(['KPI']);
    edms_csv_row(['Total documents (all time)', $total]);
    edms_csv_row(['Documents in range', $inRange]);
    edms_csv_row(['Completed in range', $completed]);
    edms_csv_row(['Currently pending', $pending]);
    edms_csv_row([]);

    // By type
    edms_csv_row(['By Document Type (in range)']);
    edms_csv_row(['Type', 'Count']);
    $st = $pdo->prepare("SELECT doc_type, COUNT(*) AS cnt FROM sys_doc_documents WHERE created_at BETWEEN ? AND ? GROUP BY doc_type");
    $st->execute($range);
    $typeMap = ['incoming' => 'หนังสือรับ', 'outgoing' => 'หนังสือส่ง', 'internal' => 'บันทึกข้อความ', 'circular' => 'หนังสือเวียน'];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        edms_csv_row([$typeMap[$r['doc_type']] ?? $r['doc_type'], (int)$r['cnt']]);
    }
    edms_csv_row([]);

    // By status
    edms_csv_row(['By Status (in range)']);
    edms_csv_row(['Status', 'Count']);
    $st = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM sys_doc_documents WHERE created_at BETWEEN ? AND ? GROUP BY status");
    $st->execute($range);
    $statusMap = [
        'draft'=>'ฉบับร่าง','registered'=>'ลงทะเบียน','routing'=>'อยู่ระหว่างโอน',
        'in_progress'=>'ดำเนินการ','completed'=>'เสร็จสิ้น','archived'=>'เก็บแฟ้ม','cancelled'=>'ยกเลิก',
    ];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        edms_csv_row([$statusMap[$r['status']] ?? $r['status'], (int)$r['cnt']]);
    }
    edms_csv_row([]);

    // Top creators
    edms_csv_row(['Top Creators (in range)']);
    edms_csv_row(['Name', 'Count']);
    $st = $pdo->prepare("
        SELECT s.full_name, COUNT(*) AS cnt
        FROM sys_doc_documents d LEFT JOIN sys_staff s ON s.id = d.created_by
        WHERE d.created_at BETWEEN ? AND ? AND d.created_by IS NOT NULL
        GROUP BY d.created_by, s.full_name
        ORDER BY cnt DESC LIMIT 20
    ");
    $st->execute($range);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        edms_csv_row([$r['full_name'] ?: '— ไม่ทราบ —', (int)$r['cnt']]);
    }

    exit;
}

// ── format=list ──────────────────────────────────────────────────────────
$validTypes = ['incoming','outgoing','internal','circular'];
$type = $_GET['type'] ?? '';

$where = 'WHERE 1=1';
$params = [];

if (in_array($type, $validTypes, true)) {
    $where .= ' AND d.doc_type = ?';
    $params[] = $type;
}

$search = trim($_GET['s'] ?? '');
if ($search !== '') {
    $where .= ' AND (d.subject LIKE ? OR d.doc_number LIKE ? OR d.sender LIKE ? OR d.recipient LIKE ?)';
    $kw = "%$search%";
    array_push($params, $kw, $kw, $kw, $kw);
}

$status = $_GET['status'] ?? '';
if ($status !== '' && in_array($status, ['draft','registered','routing','in_progress','completed','archived','cancelled'], true)) {
    $where .= ' AND d.status = ?';
    $params[] = $status;
}

$priority = (int)($_GET['priority'] ?? 0);
if ($priority > 0) {
    $where .= ' AND d.priority_id = ?';
    $params[] = $priority;
}

$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where .= ' AND d.doc_date >= ?';
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where .= ' AND d.doc_date <= ?';
    $params[] = $to;
}

$sql = "SELECT d.*, cat.name AS priority_name, s.full_name AS created_by_name,
               (SELECT COUNT(*) FROM sys_doc_attachments a WHERE a.doc_id = d.id) AS att_count
        FROM sys_doc_documents d
        LEFT JOIN sys_doc_categories cat ON cat.id = d.priority_id
        LEFT JOIN sys_staff s ON s.id = d.created_by
        $where
        ORDER BY d.created_at DESC
        LIMIT 5000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$typeLabels = [
    'incoming' => 'หนังสือรับ',
    'outgoing' => 'หนังสือส่ง',
    'internal' => 'บันทึกข้อความ',
    'circular' => 'หนังสือเวียน',
];
$statusLabels = [
    'draft'       => 'ฉบับร่าง',
    'registered'  => 'ลงทะเบียน',
    'routing'     => 'อยู่ระหว่างโอน',
    'in_progress' => 'ดำเนินการ',
    'completed'   => 'เสร็จสิ้น',
    'archived'    => 'เก็บแฟ้ม',
    'cancelled'   => 'ยกเลิก',
];
$confLabels = [
    'normal'        => 'ปกติ',
    'confidential'  => 'ลับ',
    'secret'        => 'ลับมาก',
    'top_secret'    => 'ลับที่สุด',
];

$filename = 'edms_export_' . date('Ymd_His') . '.csv';
edms_csv_send_headers($filename);

edms_csv_row([
    'เลขที่', 'ประเภท', 'เรื่อง', 'ลงวันที่', 'วันที่รับ', 'จาก', 'เรียน',
    'ความเร่งด่วน', 'ชั้นความลับ', 'สถานะ', 'ไฟล์แนบ', 'ผู้สร้าง', 'สร้างเมื่อ', 'แก้ไขล่าสุด',
]);

while ($d = $stmt->fetch(PDO::FETCH_ASSOC)) {
    edms_csv_row([
        $d['doc_number'] ?: '— ฉบับร่าง —',
        $typeLabels[$d['doc_type']] ?? $d['doc_type'],
        $d['subject'],
        $d['doc_date'] ?: '',
        $d['received_date'] ?: '',
        $d['sender'] ?: '',
        $d['recipient'] ?: '',
        $d['priority_name'] ?: '',
        $confLabels[$d['confidentiality']] ?? $d['confidentiality'],
        $statusLabels[$d['status']] ?? $d['status'],
        (int)$d['att_count'],
        $d['created_by_name'] ?: '',
        $d['created_at'] ?: '',
        $d['updated_at'] ?: '',
    ]);
}
exit;
