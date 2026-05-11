<?php
/**
 * database/migrations/migrate_monthly_report_module.php
 *
 * Module: Monthly Report (รายงานการดำเนินงานประจำเดือน)
 *
 * - sys_departments              : ฝ่าย/หน่วยงาน
 * - sys_report_templates         : template กิจกรรม (fix per ฝ่าย)
 * - sys_monthly_reports          : รายงาน 1 row = 1 ฝ่าย / เดือน
 * - sys_monthly_report_items     : รายการที่กรอก (1 row = 1 บรรทัด)
 * - sys_monthly_report_history   : audit log
 *
 * - sys_staff.department_id          : link คนกรอกกับฝ่าย
 * - sys_staff.access_monthly_report  : flag สิทธิ์เข้ารายงาน
 * - sys_staff.access_director_view   : flag ผู้อำนวยการ (ดูทั้งหมด + approve)
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) sys_departments ─────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_departments (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(255) NOT NULL,
        description TEXT NULL,
        sort_order  INT NOT NULL DEFAULT 0,
        active      TINYINT(1) NOT NULL DEFAULT 1,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ sys_departments';
} catch (PDOException $e) { $results[] = '❌ sys_departments: ' . $e->getMessage(); }

// ── 2) sys_report_templates ────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_report_templates (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        department_id   INT UNSIGNED NOT NULL,
        category        VARCHAR(255) NULL,
        activity        VARCHAR(255) NOT NULL,
        detail_default  TEXT NULL,
        hint            TEXT NULL,
        sort_order      INT NOT NULL DEFAULT 0,
        active          TINYINT(1) NOT NULL DEFAULT 1,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dept (department_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ sys_report_templates';
} catch (PDOException $e) { $results[] = '❌ sys_report_templates: ' . $e->getMessage(); }

// ── 3) sys_monthly_reports ─────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_monthly_reports (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        department_id   INT UNSIGNED NOT NULL,
        report_year     SMALLINT UNSIGNED NOT NULL,
        report_month    TINYINT UNSIGNED NOT NULL,
        meeting_info    VARCHAR(500) NULL,
        status          ENUM('draft','submitted','approved') NOT NULL DEFAULT 'draft',
        submitted_by    INT UNSIGNED NULL,
        submitted_at    DATETIME NULL,
        approved_by     INT UNSIGNED NULL,
        approved_at     DATETIME NULL,
        approved_note   TEXT NULL,
        created_by      INT UNSIGNED NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_dept_period (department_id, report_year, report_month),
        INDEX idx_period (report_year, report_month),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ sys_monthly_reports';
} catch (PDOException $e) { $results[] = '❌ sys_monthly_reports: ' . $e->getMessage(); }

// ── 4) sys_monthly_report_items ────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_monthly_report_items (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        report_id    INT UNSIGNED NOT NULL,
        template_id  INT UNSIGNED NULL,
        category     VARCHAR(255) NULL,
        activity     VARCHAR(255) NOT NULL,
        detail       TEXT NULL,
        result       TEXT NULL,
        suggestion   TEXT NULL,
        sort_order   INT NOT NULL DEFAULT 0,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_report (report_id, sort_order),
        CONSTRAINT fk_mri_report FOREIGN KEY (report_id) REFERENCES sys_monthly_reports(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ sys_monthly_report_items';
} catch (PDOException $e) { $results[] = '❌ sys_monthly_report_items: ' . $e->getMessage(); }

// ── 5) sys_monthly_report_history ──────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_monthly_report_history (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        report_id   INT UNSIGNED NOT NULL,
        action      VARCHAR(50) NOT NULL,
        changed_by  INT UNSIGNED NULL,
        changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        old_value   LONGTEXT NULL,
        new_value   LONGTEXT NULL,
        note        VARCHAR(500) NULL,
        INDEX idx_report (report_id, changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ sys_monthly_report_history';
} catch (PDOException $e) { $results[] = '❌ sys_monthly_report_history: ' . $e->getMessage(); }

// ── 6) sys_staff columns ───────────────────────────────────────────────────
$staffCols = $pdo->query("SHOW COLUMNS FROM sys_staff")->fetchAll(PDO::FETCH_COLUMN);
$addCol = function($pdo, $col, $sql) use (&$results, $staffCols) {
    if (in_array($col, $staffCols, true)) {
        $results[] = "↪ sys_staff.$col — มีอยู่แล้ว";
        return;
    }
    try { $pdo->exec($sql); $results[] = "✅ sys_staff.$col"; }
    catch (PDOException $e) { $results[] = "❌ sys_staff.$col: " . $e->getMessage(); }
};
$addCol($pdo, 'department_id',          "ALTER TABLE sys_staff ADD COLUMN department_id INT UNSIGNED NULL AFTER role");
$addCol($pdo, 'access_monthly_report',  "ALTER TABLE sys_staff ADD COLUMN access_monthly_report TINYINT(1) NOT NULL DEFAULT 0");
$addCol($pdo, 'access_director_view',   "ALTER TABLE sys_staff ADD COLUMN access_director_view TINYINT(1) NOT NULL DEFAULT 0");

// ── 7) Seed default departments + templates ────────────────────────────────
$existsDept = (int)$pdo->query("SELECT COUNT(*) FROM sys_departments")->fetchColumn();
if ($existsDept === 0) {
    $depts = [
        ['name' => 'หน่วยบริการสุขภาพ',       'sort_order' => 10],
        ['name' => 'หน่วยพัฒนาคุณภาพชีวิต',   'sort_order' => 20],
    ];
    $insDept = $pdo->prepare("INSERT INTO sys_departments (name, sort_order) VALUES (?, ?)");
    foreach ($depts as $d) $insDept->execute([$d['name'], $d['sort_order']]);
    $results[] = '✅ Seed departments — ' . count($depts) . ' รายการ';

    $health = (int)$pdo->query("SELECT id FROM sys_departments WHERE name='หน่วยบริการสุขภาพ'")->fetchColumn();
    $life   = (int)$pdo->query("SELECT id FROM sys_departments WHERE name='หน่วยพัฒนาคุณภาพชีวิต'")->fetchColumn();

    $templates = [
        // หน่วยบริการสุขภาพ
        [$health, 'หน่วยบริการสุขภาพ', 'งานปฐมพยาบาลเบื้องต้น',  'จัดบริการปฐมพยาบาลเบื้องต้น',                                          'จำนวน ราย', 10],
        [$health, 'หน่วยบริการสุขภาพ', 'งานตรวจรักษา',              'ตรวจรักษาประจำวัน ให้คำแนะนำเรื่องโรค สุขภาพ โดยแพทย์ พยาบาล', 'จำนวน ราย / 5 อันดับโรค',     20],
        [$health, 'หน่วยบริการสุขภาพ', 'งานบัตรทอง',                'โอนย้ายสิทธิประกันสุขภาพถ้วนหน้า',                                       'ยอดบัตรทอง — รวมทั้งหมด ราย', 30],
        [$health, 'หน่วยบริการสุขภาพ', 'งานส่งเสริมสุขภาพ',         'รณรงค์ฉีดวัคซีน / ส่งเสริมสุขภาพ',                                       'จำนวน ราย',                        40],
        [$health, 'หน่วยบริการสุขภาพ', 'งานประกันอุบัติเหตุ',         'งานประกันอุบัติเหตุ (เคลม + รักษา)',                                    'ยอดเคลม / ยอดรักษา + 5 อันดับเหตุ', 50],
        // หน่วยพัฒนาคุณภาพชีวิต
        [$life, 'หน่วยพัฒนาคุณภาพชีวิต',  'การให้คำปรึกษา',          'งานให้คำปรึกษาด้านจิตวิทยา',                                            'จำนวน คน · จำนวนครั้ง · รายใหม่ · ส่งต่อ', 10],
        [$life, 'หน่วยพัฒนาคุณภาพชีวิต',  'คลินิกอดบุหรี่',           'คลินิกอดบุหรี่ — นักศึกษาเภสัช ปี 6 ฝึกงาน',                            'จำนวน คน · จำนวนครั้ง · รายใหม่ · รายเก่า', 20],
    ];
    $insTpl = $pdo->prepare("INSERT INTO sys_report_templates
        (department_id, category, activity, detail_default, hint, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($templates as $t) $insTpl->execute($t);
    $results[] = '✅ Seed templates — ' . count($templates) . ' รายการ';
} else {
    $results[] = "↪ sys_departments มีข้อมูล $existsDept รายการอยู่แล้ว — ข้าม seed";
}

?><!doctype html>
<html><head><meta charset="utf-8"><title>Migration: Monthly Report</title>
<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;line-height:1.7}
h1{color:#1e293b;border-bottom:2px solid #f59e0b;padding-bottom:8px}
li{padding:4px 0}.ok{color:#059669}.err{color:#dc2626}.skip{color:#64748b}</style>
</head><body>
<h1>Migration: Monthly Report Module</h1>
<ul>
<?php foreach ($results as $r): ?>
    <li class="<?= str_starts_with($r,'❌') ? 'err' : (str_starts_with($r,'↪')?'skip':'ok') ?>"><?= htmlspecialchars($r) ?></li>
<?php endforeach; ?>
</ul>
<p><b>เสร็จสิ้น</b> — กรุณาลบไฟล์นี้ออกหลังรันสำเร็จ</p>
</body></html>
