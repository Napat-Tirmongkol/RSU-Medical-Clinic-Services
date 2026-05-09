<?php
/**
 * database/migrations/migrate_staff_positions.php
 * สร้างระบบ "ตำแหน่งงาน" (Position-based access)
 *
 *   sys_staff_positions
 *   ├─ id, name (UNIQUE), description, flags (JSON), created_at, updated_at
 *
 *   sys_staff
 *   └─ + position_id (INT NULL)  ← NULL = Custom (override flags ตาม column เดิม)
 *
 * Logic ที่ login:
 *   - position_id IS NOT NULL → ใช้ flag จาก position.flags (live link)
 *   - position_id IS NULL     → ใช้ flag จาก sys_staff.access_* (Custom)
 *
 * Idempotent — รันซ้ำได้ปลอดภัย
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sys_staff_positions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(500) NULL,
            flags JSON NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✅ [OK] ตาราง sys_staff_positions พร้อมใช้\n";

    $cols = $pdo->query("DESCRIBE sys_staff")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('position_id', $cols, true)) {
        $pdo->exec("ALTER TABLE sys_staff ADD COLUMN position_id INT UNSIGNED NULL AFTER role");
        $pdo->exec("ALTER TABLE sys_staff ADD INDEX idx_position (position_id)");
        echo "✅ [Added] sys_staff.position_id\n";
    } else {
        echo "ℹ️  [Skip] sys_staff.position_id มีอยู่แล้ว\n";
    }

    echo "\n✨ Migration เสร็จเรียบร้อย — ใช้ Identity Governance สร้างตำแหน่งงานได้เลย\n";
} catch (PDOException $e) {
    fwrite(STDERR, "❌ [Error] " . $e->getMessage() . "\n");
    exit(1);
}
