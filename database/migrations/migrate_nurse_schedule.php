<?php
/**
 * database/migrations/migrate_nurse_schedule.php
 * ระบบจัดตารางเวรพยาบาล Phase 2 — backend storage (multi-user)
 *
 *   sys_nurse_schedule_global  — ข้อมูลกลาง (รายชื่อพยาบาล, requirements, OT, วันหยุดที่แก้ไข)
 *   sys_nurse_schedule_monthly — ข้อมูลต่อเดือน (ตารางเวร + ใบลา)
 *
 * Idempotent — รันซ้ำได้ปลอดภัย
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_nurse_schedule_global (
        id TINYINT UNSIGNED NOT NULL DEFAULT 1,
        nurses_json LONGTEXT NULL,
        requirements_json LONGTEXT NULL,
        ot_settings_json LONGTEXT NULL,
        custom_holidays_json LONGTEXT NULL,
        removed_holidays_json LONGTEXT NULL,
        updated_by INT UNSIGNED NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ [OK] sys_nurse_schedule_global\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_nurse_schedule_monthly (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        year_be SMALLINT UNSIGNED NOT NULL,
        month TINYINT UNSIGNED NOT NULL,
        schedule_json LONGTEXT NULL,
        leaves_json LONGTEXT NULL,
        updated_by INT UNSIGNED NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ym (year_be, month),
        KEY idx_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✅ [OK] sys_nurse_schedule_monthly\n";

    // Seed singleton row if not exists
    $pdo->exec("INSERT IGNORE INTO sys_nurse_schedule_global (id) VALUES (1)");

    echo "\n✨ Migration เสร็จเรียบร้อย — ใช้งานได้ผ่าน portal/ajax_nurse_schedule.php\n";
} catch (PDOException $e) {
    fwrite(STDERR, "❌ [Error] " . $e->getMessage() . "\n");
    exit(1);
}
