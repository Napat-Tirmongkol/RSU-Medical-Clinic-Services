<?php
// portal/ajax_insurance_export.php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

$adminRole = $_SESSION['admin_role'] ?? '';
$isStaff   = !empty($_SESSION['is_ecampaign_staff']);
if ($isStaff && $adminRole === '') {
    http_response_code(403);
    exit('ไม่มีสิทธิ์');
}

$allowedRoles = ['admin', 'superadmin', 'editor'];
if (!in_array($adminRole, $allowedRoles, true)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

$type   = $_GET['type'] ?? 'active'; // active | newcomers | sync_newcomers
$syncId = (int)($_GET['sync_id'] ?? 0);
$pdo    = db();

function csv_row(array $cols): string {
    return implode(',', array_map(fn($c) => '"' . str_replace('"', '""', (string)$c) . '"', $cols)) . "\r\n";
}

$headers = [
    'รหัสบุคลากร/นักศึกษา',
    'ชื่อ-สกุล',
    'สถานะสมาชิก',
    'ตำแหน่ง',
    'เลขบัตรประชาชน',
    'วันเกิด',
    'สถานะประกัน',
    'วันเริ่มต้นสิทธิ์',
    'วันสิ้นสุดสิทธิ์',
    'เลขกรมธรรม์',
    'หมายเหตุ',
    'อัปเดตล่าสุด',
];

if ($type === 'active') {
    $stmt = $pdo->query("
        SELECT member_id, full_name, member_status, position, citizen_id, date_of_birth,
               insurance_status, coverage_start, coverage_end, policy_number, remarks, updated_at
        FROM insurance_members
        WHERE insurance_status = 'Active'
        ORDER BY full_name ASC
    ");
    $filename = 'insurance_active_' . date('Ymd') . '.csv';

} elseif ($type === 'newcomers' && $syncId > 0) {
    // Members inserted in a specific sync
    $stmt = $pdo->prepare("
        SELECT m.member_id, m.full_name, m.member_status, m.position, m.citizen_id, m.date_of_birth,
               m.insurance_status, m.coverage_start, m.coverage_end, m.policy_number, m.remarks, m.updated_at
        FROM insurance_member_history h
        JOIN insurance_members m ON h.member_id = m.member_id
        WHERE h.sync_id = :sid AND h.change_type = 'inserted'
        ORDER BY m.full_name ASC
    ");
    $stmt->execute([':sid' => $syncId]);
    $filename = "insurance_newcomers_sync{$syncId}_" . date('Ymd') . '.csv';

} else {
    http_response_code(400);
    exit('ประเภทการส่งออกไม่ถูกต้อง');
}

// Stream CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

// BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";
echo csv_row($headers);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo csv_row([
        $row['member_id'],
        $row['full_name'],
        $row['member_status'],
        $row['position'],
        $row['citizen_id'],
        $row['date_of_birth'] ?? '',
        $row['insurance_status'],
        $row['coverage_start'] ?? '',
        $row['coverage_end'] ?? '',
        $row['policy_number'],
        $row['remarks'] ?? '',
        $row['updated_at'],
    ]);
}
