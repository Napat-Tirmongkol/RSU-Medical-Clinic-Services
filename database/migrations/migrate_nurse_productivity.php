<?php
/**
 * database/migrations/migrate_nurse_productivity.php
 *
 * Module: Nurse Productivity (ระบบคำนวณ Productivity พยาบาล OPD)
 *
 * - sys_nurse_productivity_settings : ข้อมูล รพ. + เกณฑ์มาตรฐาน (1 row ต่อ ฝ่าย)
 * - sys_nurse_productivity_daily    : รายการกรอกรายวัน (1 row = 1 วัน/ฝ่าย)
 * - sys_nurse_productivity_audit    : append-only audit log
 *
 * - sys_staff.access_nurse_productivity : flag สิทธิ์เข้าโมดูล
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── 1) sys_nurse_productivity_settings ─────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_nurse_productivity_settings (
        dept_id         INT UNSIGNED NOT NULL PRIMARY KEY,
        hospital_name   VARCHAR(255) NULL,
        level           ENUM('A','S','M1','M2','F1','F2','F3') NOT NULL DEFAULT 'F2',
        beds            INT UNSIGNED NULL,
        province        VARCHAR(120) NULL,
        director        VARCHAR(255) NULL,
        moph_code       VARCHAR(10) NULL,
        period_label    VARCHAR(60) NULL,
        hpv             DECIMAL(4,2) NOT NULL DEFAULT 0.24,
        shift_hours     DECIMAL(3,1) NOT NULL DEFAULT 7.0,
        threshold_low   SMALLINT UNSIGNED NOT NULL DEFAULT 80,
        threshold_high  SMALLINT UNSIGNED NOT NULL DEFAULT 110,
        updated_by      INT UNSIGNED NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_np_set_dept FOREIGN KEY (dept_id) REFERENCES sys_departments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ sys_nurse_productivity_settings';
} catch (PDOException $e) { $results[] = '❌ sys_nurse_productivity_settings: ' . $e->getMessage(); }

// ── 2) sys_nurse_productivity_daily ────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_nurse_productivity_daily (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dept_id         INT UNSIGNED NOT NULL,
        entry_date      DATE NOT NULL,
        patients        INT UNSIGNED NOT NULL DEFAULT 0,
        rn_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        head_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        shift_hours     DECIMAL(3,1) NOT NULL DEFAULT 7.0,
        note            VARCHAR(500) NULL,
        rn_source       ENUM('manual','schedule') NOT NULL DEFAULT 'manual',
        head_source     ENUM('manual','schedule') NOT NULL DEFAULT 'manual',
        created_by      INT UNSIGNED NULL,
        updated_by      INT UNSIGNED NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_dept_date (dept_id, entry_date),
        INDEX idx_date (entry_date),
        INDEX idx_dept_period (dept_id, entry_date),
        CONSTRAINT fk_np_daily_dept FOREIGN KEY (dept_id) REFERENCES sys_departments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ sys_nurse_productivity_daily';
} catch (PDOException $e) { $results[] = '❌ sys_nurse_productivity_daily: ' . $e->getMessage(); }

// ── 3) sys_nurse_productivity_audit ────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_nurse_productivity_audit (
        id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dept_id            INT UNSIGNED NULL,
        action             VARCHAR(60) NOT NULL,
        target_id          INT UNSIGNED NULL,
        changes_json       LONGTEXT NULL,
        performed_by       INT UNSIGNED NULL,
        performed_by_name  VARCHAR(255) NULL,
        ip_addr            VARCHAR(64) NULL,
        created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dept (dept_id, created_at),
        INDEX idx_action (action, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ sys_nurse_productivity_audit';
} catch (PDOException $e) { $results[] = '❌ sys_nurse_productivity_audit: ' . $e->getMessage(); }

// ── 4) sys_staff.access_nurse_productivity ─────────────────────────────────
$staffCols = $pdo->query("SHOW COLUMNS FROM sys_staff")->fetchAll(PDO::FETCH_COLUMN);
if (in_array('access_nurse_productivity', $staffCols, true)) {
    $results[] = '↪ sys_staff.access_nurse_productivity — มีอยู่แล้ว';
} else {
    try {
        $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_nurse_productivity TINYINT(1) NOT NULL DEFAULT 0");
        $results[] = '✅ sys_staff.access_nurse_productivity';
    } catch (PDOException $e) { $results[] = '❌ sys_staff.access_nurse_productivity: ' . $e->getMessage(); }
}

?><!doctype html>
<html><head><meta charset="utf-8"><title>Migration: Nurse Productivity</title>
<style>body{font-family:system-ui,sans-serif;max-width:760px;margin:40px auto;padding:0 20px;line-height:1.7}
h1{color:#1e293b;border-bottom:2px solid #2e9e63;padding-bottom:8px}
li{padding:4px 0}.ok{color:#059669}.err{color:#dc2626}.skip{color:#64748b}</style>
</head><body>
<h1>Migration: Nurse Productivity Module</h1>
<ul>
<?php foreach ($results as $r): ?>
    <li class="<?= str_starts_with($r,'❌') ? 'err' : (str_starts_with($r,'↪')?'skip':'ok') ?>"><?= htmlspecialchars($r) ?></li>
<?php endforeach; ?>
</ul>
<p><b>เสร็จสิ้น</b> — กรุณาลบไฟล์นี้ออกหลังรันสำเร็จ</p>
<p>ต่อไป: ตั้งสิทธิ์ <code>access_nurse_productivity</code> ที่ Identity &amp; Governance</p>
</body></html>
