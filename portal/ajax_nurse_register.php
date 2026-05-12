<?php
// portal/ajax_nurse_register.php — ทะเบียนพยาบาล/บุคลากรสำหรับระบบจัดตารางเวร
// - list_staff_nurses : ดึงรายชื่อจาก Identity (sys_staff) + ผังองค์กร (sys_org_members)
// - get_nurse_info    : โหลด national_id / official_title / hourly_rate / signer settings
// - save_nurse_info   : บันทึก field ใหม่ลง sys_staff หรือ sys_org_members
// - get_timesheet_settings / save_timesheet_settings : ข้อมูลคลินิก + ผู้ลงนาม + อัตราภาษี
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$action = $_REQUEST['action'] ?? '';

// ── Self-heal: เพิ่ม column ที่หน้า timesheet ต้องใช้ ──
function ensure_timesheet_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $cols = [
        'sys_staff' => [
            'national_id'    => "ALTER TABLE sys_staff ADD COLUMN national_id VARCHAR(13) NULL",
            'official_title' => "ALTER TABLE sys_staff ADD COLUMN official_title VARCHAR(255) NULL",
            'hourly_rate'    => "ALTER TABLE sys_staff ADD COLUMN hourly_rate DECIMAL(8,2) NULL",
        ],
        'sys_org_members' => [
            'national_id'    => "ALTER TABLE sys_org_members ADD COLUMN national_id VARCHAR(13) NULL",
            'official_title' => "ALTER TABLE sys_org_members ADD COLUMN official_title VARCHAR(255) NULL",
            'hourly_rate'    => "ALTER TABLE sys_org_members ADD COLUMN hourly_rate DECIMAL(8,2) NULL",
        ],
    ];
    foreach ($cols as $table => $alters) {
        foreach ($alters as $col => $sql) {
            try { $pdo->exec($sql); } catch (PDOException) { /* exists or table missing */ }
        }
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_nurse_timesheet_settings (
            id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            clinic_name VARCHAR(255) DEFAULT 'คลินิกเวชกรรม มหาวิทยาลัยรังสิต',
            signer_name VARCHAR(255) NULL,
            signer_title VARCHAR(255) NULL,
            tax_rate DECIMAL(5,2) NOT NULL DEFAULT 3.00,
            default_hourly_rate DECIMAL(8,2) NOT NULL DEFAULT 120.00,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT IGNORE INTO sys_nurse_timesheet_settings (id) VALUES (1)");
    } catch (PDOException $e) {
        error_log('[nurse_register] schema: ' . $e->getMessage());
    }
    $done = true;
}
ensure_timesheet_schema($pdo);

try {
    if ($action === 'list_staff_nurses') {
        // 1) ดึง staff ที่อยู่ในผังองค์กร (ทุกตำแหน่ง) หรือมี job_title คำว่า "พยาบาล"
        //    เพื่อให้ตารางเวรเห็นภาพรวมทั้งพยาบาลและเจ้าหน้าที่อื่นที่ใช้เวรร่วมกัน
        $sqlStaff = "SELECT
                    'staff' AS source,
                    s.id AS staff_id,
                    NULL AS org_member_id,
                    s.full_name,
                    s.username,
                    s.job_title,
                    s.account_status,
                    s.national_id,
                    s.official_title,
                    s.hourly_rate,
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
                           AND op.is_active = 1
                       )
                  )
                ORDER BY s.full_name ASC";

        // 2) ดึง org_members ทุกตำแหน่งที่ไม่มี staff_id (standalone)
        //    — เพื่อไม่ duplicate กับผลข้อ 1 ซึ่งดึงผ่าน staff_id อยู่แล้ว
        $sqlOrg = "SELECT
                    'org' AS source,
                    NULL AS staff_id,
                    om.id AS org_member_id,
                    TRIM(CONCAT(COALESCE(om.prefix, ''), ' ', om.full_name)) AS full_name,
                    NULL AS username,
                    NULL AS job_title,
                    'active' AS account_status,
                    om.national_id,
                    om.official_title,
                    om.hourly_rate,
                    op.title AS org_position_title
                FROM sys_org_members om
                INNER JOIN sys_org_positions op ON op.id = om.position_id
                WHERE om.is_active = 1 AND op.is_active = 1
                  AND om.staff_id IS NULL
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

    // ── ดึงข้อมูลรายบุคคล (สำหรับ Editor) ──
    if ($action === 'get_nurse_info') {
        $staffId = (int)($_GET['staff_id'] ?? 0);
        $orgId   = (int)($_GET['org_member_id'] ?? 0);
        if ($staffId > 0) {
            $st = $pdo->prepare("SELECT id, full_name, national_id, official_title, hourly_rate, job_title FROM sys_staff WHERE id = ?");
            $st->execute([$staffId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'error' => 'ไม่พบ staff']); exit; }
            // ดึง org_position_title สำหรับใช้เป็น fallback ของ official_title
            $opTitle = $pdo->prepare("SELECT op.title FROM sys_org_members om
                INNER JOIN sys_org_positions op ON op.id = om.position_id
                WHERE om.staff_id = ? AND om.is_active = 1
                ORDER BY om.display_order ASC, om.id ASC LIMIT 1");
            $opTitle->execute([$staffId]);
            $row['org_position_title'] = $opTitle->fetchColumn() ?: null;
            echo json_encode(['ok' => true, 'info' => $row], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($orgId > 0) {
            $st = $pdo->prepare("SELECT om.id, TRIM(CONCAT(COALESCE(om.prefix,''),' ',om.full_name)) AS full_name,
                                        om.national_id, om.official_title, om.hourly_rate,
                                        op.title AS org_position_title
                                 FROM sys_org_members om
                                 LEFT JOIN sys_org_positions op ON op.id = om.position_id
                                 WHERE om.id = ?");
            $st->execute([$orgId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'error' => 'ไม่พบ org member']); exit; }
            echo json_encode(['ok' => true, 'info' => $row], JSON_UNESCAPED_UNICODE); exit;
        }
        echo json_encode(['ok' => false, 'error' => 'ต้องระบุ staff_id หรือ org_member_id']); exit;
    }

    // ── บันทึก national_id / official_title / hourly_rate ──
    if ($action === 'save_nurse_info') {
        validate_csrf_or_die();
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $orgId   = (int)($_POST['org_member_id'] ?? 0);
        $nid     = trim((string)($_POST['national_id'] ?? ''));
        $title   = trim((string)($_POST['official_title'] ?? ''));
        $rateRaw = $_POST['hourly_rate'] ?? '';
        $rate    = ($rateRaw === '' || $rateRaw === null) ? null : (float)$rateRaw;

        if ($nid !== '' && !preg_match('/^\d{13}$/', $nid)) {
            echo json_encode(['ok' => false, 'error' => 'เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก']); exit;
        }
        if ($rate !== null && ($rate < 0 || $rate > 10000)) {
            echo json_encode(['ok' => false, 'error' => 'อัตราต่อชั่วโมงไม่สมเหตุสมผล']); exit;
        }

        if ($staffId > 0) {
            $st = $pdo->prepare("UPDATE sys_staff SET national_id = ?, official_title = ?, hourly_rate = ? WHERE id = ?");
            $st->execute([$nid ?: null, $title ?: null, $rate, $staffId]);
            echo json_encode(['ok' => true]); exit;
        }
        if ($orgId > 0) {
            $st = $pdo->prepare("UPDATE sys_org_members SET national_id = ?, official_title = ?, hourly_rate = ? WHERE id = ?");
            $st->execute([$nid ?: null, $title ?: null, $rate, $orgId]);
            echo json_encode(['ok' => true]); exit;
        }
        echo json_encode(['ok' => false, 'error' => 'ต้องระบุ staff_id หรือ org_member_id']); exit;
    }

    // ── ตั้งค่าใบลงเวลา (ชื่อคลินิก/ผู้ลงนาม/อัตราภาษี/อัตราต่อชั่วโมงเริ่มต้น) ──
    if ($action === 'get_timesheet_settings') {
        $row = $pdo->query("SELECT clinic_name, signer_name, signer_title, tax_rate, default_hourly_rate FROM sys_nurse_timesheet_settings WHERE id = 1")
            ->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['ok' => true, 'settings' => $row], JSON_UNESCAPED_UNICODE); exit;
    }

    if ($action === 'save_timesheet_settings') {
        validate_csrf_or_die();
        $clinic    = trim((string)($_POST['clinic_name'] ?? 'คลินิกเวชกรรม มหาวิทยาลัยรังสิต'));
        $signerN   = trim((string)($_POST['signer_name'] ?? ''));
        $signerT   = trim((string)($_POST['signer_title'] ?? ''));
        $tax       = (float)($_POST['tax_rate'] ?? 3);
        $defRate   = (float)($_POST['default_hourly_rate'] ?? 120);
        if ($tax < 0 || $tax > 30)        { echo json_encode(['ok' => false, 'error' => 'อัตราภาษีไม่สมเหตุสมผล']); exit; }
        if ($defRate < 0 || $defRate > 10000) { echo json_encode(['ok' => false, 'error' => 'อัตราต่อชั่วโมงไม่สมเหตุสมผล']); exit; }
        $st = $pdo->prepare("UPDATE sys_nurse_timesheet_settings
            SET clinic_name = ?, signer_name = ?, signer_title = ?, tax_rate = ?, default_hourly_rate = ?
            WHERE id = 1");
        $st->execute([$clinic, $signerN ?: null, $signerT ?: null, $tax, $defRate]);
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('[nurse_register] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
