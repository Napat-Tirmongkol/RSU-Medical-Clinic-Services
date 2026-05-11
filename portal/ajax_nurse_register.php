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
        // ดึง staff ที่มี job_title คำว่า "พยาบาล" หรือเกี่ยวข้อง
        // หรือมี org chart position เป็นพยาบาล
        $sql = "SELECT
                    s.id AS staff_id,
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
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            // เผื่อ table/column ยังไม่มี
            $rows = [];
        }

        echo json_encode(['ok' => true, 'staff' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('[nurse_register] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
