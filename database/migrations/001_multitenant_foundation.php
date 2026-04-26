<?php
/**
 * Migration 001: Multi-Tenant Foundation
 *
 * สร้างโครงสร้างพื้นฐานสำหรับ Multi-Tenant:
 *   1. สร้างตาราง sys_clinics (tenant registry)
 *   2. เพิ่มคอลัมน์ clinic_id ให้ทุกตารางที่เก็บข้อมูลต่อ tenant
 *   3. Seed clinic แรก (Medical Clinic) ด้วยข้อมูลจาก sys_site_settings เดิม
 *   4. UPDATE ข้อมูลเก่าให้มี clinic_id = 1
 *
 * เรียกใช้จาก CLI: php database/migrations/001_multitenant_foundation.php
 * ปลอดภัยที่จะรันซ้ำ (idempotent)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "[Migration 001] Starting multi-tenant foundation migration...\n\n";

// ── 1. สร้างตาราง sys_clinics ──────────────────────────────────────────────
echo "[1/6] Creating sys_clinics table...\n";
$pdo->exec("
    CREATE TABLE IF NOT EXISTS sys_clinics (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(255)  NOT NULL,
        slug         VARCHAR(100)  NOT NULL UNIQUE COMMENT 'URL-safe identifier เช่น medical, pharmacy',
        subdomain    VARCHAR(255)  NULL      COMMENT 'Full subdomain เช่น medical.rsu.ac.th',
        is_active    TINYINT(1)    NOT NULL DEFAULT 1,
        created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_slug      (slug),
        INDEX idx_subdomain (subdomain),
        INDEX idx_active    (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "    ✓ sys_clinics ready\n";

// ── 2. Seed clinic แรก (Medical Clinic) ───────────────────────────────────
echo "[2/6] Seeding default clinic (id=1)...\n";

// ดึงชื่อจาก sys_site_settings ถ้ามี
$existingName = 'RSU Medical Clinic';
try {
    $row = $pdo->query("SELECT setting_value FROM sys_site_settings WHERE setting_key = 'site_name' LIMIT 1")->fetch();
    if ($row && !empty($row['setting_value'])) {
        $existingName = $row['setting_value'];
    }
} catch (Exception $e) {
    // ยังไม่มีตาราง site_settings — ใช้ค่า default
}

$pdo->prepare("
    INSERT INTO sys_clinics (id, name, slug, subdomain, is_active)
    VALUES (1, :name, 'medical', NULL, 1)
    ON DUPLICATE KEY UPDATE name = VALUES(name)
")->execute([':name' => $existingName]);

echo "    ✓ Clinic id=1 '{$existingName}' seeded\n";

// ── Helper: เพิ่ม column แบบ idempotent ───────────────────────────────────
function addColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $exists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
          AND COLUMN_NAME  = '{$column}'
    ")->fetchColumn();

    if ($exists) {
        echo "    – {$table}.{$column} already exists, skipping\n";
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    echo "    ✓ Added {$table}.{$column}\n";
}

// Helper: เพิ่ม index แบบ idempotent
function addIndex(PDO $pdo, string $table, string $indexName, string $column): void
{
    $exists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$table}'
          AND INDEX_NAME   = '{$indexName}'
    ")->fetchColumn();

    if ($exists) {
        echo "    – index {$indexName} on {$table} already exists, skipping\n";
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`{$column}`)");
    echo "    ✓ Added index {$indexName} on {$table}\n";
}

// Helper: backfill clinic_id = 1 ที่ยังเป็น NULL
function backfill(PDO $pdo, string $table): void
{
    $affected = $pdo->exec("UPDATE `{$table}` SET clinic_id = 1 WHERE clinic_id IS NULL");
    if ($affected > 0) {
        echo "    ✓ Backfilled {$affected} rows in {$table}\n";
    }
}

// ── 3. Core user/staff/admin tables ───────────────────────────────────────
echo "[3/6] Adding clinic_id to user/staff/admin tables...\n";

$coreTables = [
    'sys_admins' => 'sys_admins',
    'sys_staff'  => 'sys_staff',
    'sys_users'  => 'sys_users',
];

foreach ($coreTables as $table => $_) {
    // ตรวจว่า table มีอยู่ก่อน
    $exists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
    ")->fetchColumn();

    if (!$exists) {
        echo "    – {$table} not found, skipping\n";
        continue;
    }

    addColumn($pdo, $table, 'clinic_id', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`');
    addIndex($pdo, $table, 'idx_clinic', 'clinic_id');
    backfill($pdo, $table);
}

// ── 4. Campaign tables ────────────────────────────────────────────────────
echo "[4/6] Adding clinic_id to campaign tables...\n";

$campaignTables = ['camp_list', 'camp_bookings', 'camp_slots'];

foreach ($campaignTables as $table) {
    $exists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
    ")->fetchColumn();

    if (!$exists) {
        echo "    – {$table} not found, skipping\n";
        continue;
    }

    addColumn($pdo, $table, 'clinic_id', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `id`');
    addIndex($pdo, $table, 'idx_clinic', 'clinic_id');
    backfill($pdo, $table);
}

// ── 5. Settings, logs, announcements tables ───────────────────────────────
echo "[5/6] Adding clinic_id to settings/logs/announcements tables...\n";

$supportTables = [
    'sys_site_settings'  => 'AFTER `setting_key`',
    'sys_activity_logs'  => 'AFTER `id`',
    'sys_announcements'  => 'AFTER `id`',
    'sys_chat_messages'  => 'AFTER `id`',
    'satisfaction_surveys' => 'AFTER `id`',
    'sys_email_logs'     => 'AFTER `id`',
    'vac_appointments'   => 'AFTER `id`',
    'insurance_members'  => 'AFTER `id`',
];

foreach ($supportTables as $table => $position) {
    $exists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'
    ")->fetchColumn();

    if (!$exists) {
        echo "    – {$table} not found, skipping\n";
        continue;
    }

    addColumn($pdo, $table, 'clinic_id', "INT UNSIGNED NOT NULL DEFAULT 1 {$position}");
    addIndex($pdo, $table, 'idx_clinic', 'clinic_id');
    backfill($pdo, $table);
}

// ── 6. เพิ่ม FK constraint บน sys_clinics (optional — ทำได้ถ้า InnoDB) ────
echo "[6/6] Adding Foreign Key to sys_site_settings.clinic_id (optional)...\n";
try {
    // ตรวจว่า FK มีอยู่แล้วไหม
    $fkExists = $pdo->query("
        SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA    = DATABASE()
          AND TABLE_NAME      = 'sys_site_settings'
          AND CONSTRAINT_NAME = 'fk_site_settings_clinic'
    ")->fetchColumn();

    if (!$fkExists) {
        $pdo->exec("
            ALTER TABLE sys_site_settings
            ADD CONSTRAINT fk_site_settings_clinic
            FOREIGN KEY (clinic_id) REFERENCES sys_clinics(id) ON DELETE CASCADE
        ");
        echo "    ✓ FK added on sys_site_settings\n";
    } else {
        echo "    – FK already exists, skipping\n";
    }
} catch (Exception $e) {
    echo "    ⚠ FK skipped (non-fatal): " . $e->getMessage() . "\n";
}

echo "\n[Migration 001] ✅ Complete! All tenant foundation tables are ready.\n";
echo "                   Current state: 1 clinic seeded (id=1), all existing data has clinic_id=1\n";
