<?php
/**
 * database/migrations/migrate_kpi_overrides.php
 *
 * ins_kpi_overrides — เก็บค่า override ของ KPI ที่ admin กรอกเอง
 *  - kpi_key       VARCHAR(100) PRIMARY KEY  (เช่น 'gold_total','mti_total_active')
 *  - override_value BIGINT NOT NULL
 *  - is_active     TINYINT(1)               (0 = ใช้ค่าจริง, 1 = ใช้ override)
 *  - note          VARCHAR(500)             (เหตุผลที่ override)
 *  - updated_by    INT UNSIGNED NULL
 *  - updated_at    DATETIME
 */
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ins_kpi_overrides (
        kpi_key         VARCHAR(100)            NOT NULL PRIMARY KEY,
        override_value  BIGINT                  NOT NULL DEFAULT 0,
        is_active       TINYINT(1)              NOT NULL DEFAULT 1,
        note            VARCHAR(500)            NOT NULL DEFAULT '',
        updated_by      INT UNSIGNED            NULL,
        updated_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at      DATETIME                NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $results[] = '✅ ins_kpi_overrides — สร้างเรียบร้อย';
} catch (PDOException $e) {
    $results[] = '❌ ins_kpi_overrides: ' . $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
echo "<h2>Migration: KPI Overrides</h2><ul>";
foreach ($results as $r) echo "<li>" . htmlspecialchars($r) . "</li>";
echo "</ul><p><strong>เสร็จสิ้น</strong> — ลบไฟล์นี้หลังรันสำเร็จ</p>";
