<?php
/**
 * database/migrations/migrate_dashboard_builder.php
 *
 * Module: Insurance Dashboard Builder (Public + Admin Editable)
 * - ins_dashboard_widgets   : config ของ widget แต่ละชิ้น (type, title, data_source, color, sort)
 * - ins_dashboard_datasets  : custom CSV ที่ admin upload (เก็บ JSON rows)
 * - sys_staff.access_dashboard_admin TINYINT(1) — สิทธิ์แก้ dashboard
 *
 * รันครั้งเดียวผ่านเบราว์เซอร์ แล้วลบไฟล์นี้ทิ้ง
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

// ── ins_dashboard_widgets ─────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ins_dashboard_widgets (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        widget_type     ENUM('kpi','line','bar','donut','pie','area','stat_group')
                                                NOT NULL DEFAULT 'kpi',
        title           VARCHAR(150)            NOT NULL DEFAULT '',
        subtitle        VARCHAR(200)            NOT NULL DEFAULT '',
        data_source     VARCHAR(100)            NOT NULL DEFAULT '',
        config_json     JSON                    NULL,
        color_theme     VARCHAR(20)             NOT NULL DEFAULT 'blue',
        size            ENUM('sm','md','lg','xl') NOT NULL DEFAULT 'md',
        sort_order      INT UNSIGNED            NOT NULL DEFAULT 0,
        is_visible      TINYINT(1)              NOT NULL DEFAULT 1,
        is_public       TINYINT(1)              NOT NULL DEFAULT 1,
        created_by      INT UNSIGNED            NULL,
        created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sort (sort_order),
        INDEX idx_visible (is_visible),
        INDEX idx_public (is_public)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ ins_dashboard_widgets — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ ins_dashboard_widgets: ' . $e->getMessage();
}

// ── ins_dashboard_datasets (custom CSV) ───────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ins_dashboard_datasets (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        dataset_key     VARCHAR(100)            NOT NULL,
        dataset_name    VARCHAR(150)            NOT NULL,
        description     VARCHAR(500)            NOT NULL DEFAULT '',
        label_column    VARCHAR(100)            NOT NULL DEFAULT 'label',
        value_column    VARCHAR(100)            NOT NULL DEFAULT 'value',
        rows_json       JSON                    NOT NULL,
        row_count       INT UNSIGNED            NOT NULL DEFAULT 0,
        uploaded_by     INT UNSIGNED            NULL,
        uploaded_at     DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_key (dataset_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ ins_dashboard_datasets — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ ins_dashboard_datasets: ' . $e->getMessage();
}

// ── sys_staff.access_dashboard_admin ──────────────────────────────────────────
try {
    $cols = $pdo->query("SHOW COLUMNS FROM sys_staff")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('access_dashboard_admin', $cols, true)) {
        $pdo->exec("ALTER TABLE sys_staff
            ADD COLUMN access_dashboard_admin TINYINT(1) NOT NULL DEFAULT 0");
        $results[] = '✅ sys_staff.access_dashboard_admin — เพิ่มเรียบร้อย';
    } else {
        $results[] = 'ℹ️ sys_staff.access_dashboard_admin มีอยู่แล้ว';
    }
} catch (PDOException $e) {
    $results[] = '❌ sys_staff.access_dashboard_admin: ' . $e->getMessage();
}

// ── Seed widgets เริ่มต้น (ถ้ายังไม่มี) ──────────────────────────────────────
try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM ins_dashboard_widgets")->fetchColumn();
    if ($cnt === 0) {
        $seed = [
            ['kpi',  'ประกันอุบัติเหตุ (Active)', 'จำนวนผู้มีสิทธิ์ทั้งหมด',  'mti_total_active',     'emerald', 'md', 1, 1, 1],
            ['kpi',  'บัตรทอง',                   'จำนวนผู้ลงทะเบียนทั้งหมด', 'gold_total',           'amber',   'md', 1, 1, 2],
            ['kpi',  'ครอบคลุมรวม',               'ผู้มีสิทธิ์รวมทั้งสองระบบ', 'coverage_total',       'blue',    'md', 1, 1, 3],
            ['kpi',  'ใกล้หมดอายุ ≤30 วัน',       'ต้องดำเนินการต่ออายุ',     'mti_expiring_30d',     'rose',    'md', 1, 1, 4],
            ['line', 'แนวโน้มการลงทะเบียน',        '12 เดือนล่าสุด',           'mti_trend_12m',        'blue',    'lg', 1, 1, 5],
            ['donut','สัดส่วนตามประเภท (MTI)',    'บุคลากร vs นักศึกษา',      'mti_breakdown_type',   'cyan',    'md', 1, 1, 6],
            ['bar',  'Top รพ.หลัก (บัตรทอง)',     '10 อันดับแรก',             'gold_by_hospital',     'amber',   'lg', 1, 1, 7],
            ['donut','สถานะเอกสารบัตรทอง',        'จำแนกตามสถานะ',            'gold_by_status',       'purple',  'md', 1, 1, 8],
        ];
        $stmt = $pdo->prepare("INSERT INTO ins_dashboard_widgets
            (widget_type, title, subtitle, data_source, color_theme, size, is_visible, is_public, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($seed as $row) $stmt->execute($row);
        $results[] = '✅ ins_dashboard_widgets — seed widgets เริ่มต้น (' . count($seed) . ' รายการ)';
    } else {
        $results[] = "ℹ️ ins_dashboard_widgets มีข้อมูลอยู่แล้ว ($cnt รายการ) — ข้าม seed";
    }
} catch (PDOException $e) {
    $results[] = '❌ seed widgets: ' . $e->getMessage();
}

// ── Output ────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration: Dashboard Builder</h2><ul>";
foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>";
echo "</ul><p><strong>เสร็จสิ้น</strong> — กรุณาลบไฟล์นี้ออกหลังรันสำเร็จ</p>";
