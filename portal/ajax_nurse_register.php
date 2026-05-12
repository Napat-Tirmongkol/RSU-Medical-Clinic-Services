<?php
// portal/ajax_nurse_register.php — สำหรับนำเข้ารายชื่อพยาบาลจาก sys_staff
// (job_title ที่มีคำว่า "พยาบาล" หรือ "ผู้ช่วยพยาบาล") เข้าสู่ระบบจัดตารางเวร
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'list_staff_nurses') {
        // 1) ดึง staff ที่มี job_title คำว่า "พยาบาล" หรือเชื่อมกับ org chart "พยาบาล"
        $sqlStaff = "SELECT
                    'staff' AS source,
                    s.id AS staff_id,
                    NULL AS org_member_id,
                    s.full_name,
                    s.username,
                    s.job_title,
                    s.account_status,
                    (SELECT op.title FROM sys_org_members om
                       INNER JOIN sys_org_positions op ON op.id = om.position_id
                       WHERE om.staff_id = s.id AND om.is_active = 1
                       ORDER BY om.display_order ASC, om.id ASC LIMIT 1) AS org_position_title
                FROM sys_staff s
                WHERE s.account_status = 'active'
                  AND (
                       s.job_title LIKE '%พยาบาล%'
                    OR EXISTS(
                         SELECT 1 FROM sys_org_members om
                         INNER JOIN sys_org_positions op ON op.id = om.position_id
                         WHERE om.staff_id = s.id AND om.is_active = 1
                           AND op.title LIKE '%พยาบาล%'
                       )
                  )
                ORDER BY s.full_name ASC";

        // 2) ดึง org_members ที่ตำแหน่งมี "พยาบาล" แต่ไม่มี staff_id (standalone)
        //    เพื่อไม่ duplicate กับผลข้อ 1 ซึ่งดึงผ่าน staff_id อยู่แล้ว
        $sqlOrg = "SELECT
                    'org' AS source,
                    NULL AS staff_id,
                    om.id AS org_member_id,
                    TRIM(CONCAT(COALESCE(om.prefix, ''), ' ', om.full_name)) AS full_name,
                    NULL AS username,
                    NULL AS job_title,
                    'active' AS account_status,
                    op.title AS org_position_title
                FROM sys_org_members om
                INNER JOIN sys_org_positions op ON op.id = om.position_id
                WHERE om.is_active = 1 AND op.is_active = 1
                  AND om.staff_id IS NULL
                  AND op.title LIKE '%พยาบาล%'
                ORDER BY om.full_name ASC";

        $rows = [];
        try { $rows = array_merge($rows, $pdo->query($sqlStaff)->fetchAll(PDO::FETCH_ASSOC) ?: []); }
        catch (PDOException $e) { /* tables may not exist */ }
        try { $rows = array_merge($rows, $pdo->query($sqlOrg)->fetchAll(PDO::FETCH_ASSOC) ?: []); }
        catch (PDOException $e) { /* tables may not exist */ }

        // sort รวมตามชื่อ
        usort($rows, fn($a, $b) => strcmp($a['full_name'], $b['full_name']));

        echo json_encode(['ok' => true, 'staff' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('[nurse_register] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
