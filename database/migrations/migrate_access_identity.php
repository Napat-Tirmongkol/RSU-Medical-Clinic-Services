<?php
/**
 * database/migrations/migrate_access_identity.php
 * เพิ่ม access_identity flag สำหรับการเข้าถึง Identity & Governance
 *   - superadmin ผ่านเสมอ (ไม่ต้องมี flag)
 *   - admin/editor/staff ที่จะเห็น section identity ต้องมี access_identity = 1
 *
 * Idempotent — รันซ้ำได้ปลอดภัย
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();
    $cols = $pdo->query("DESCRIBE sys_staff")->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('access_identity', $cols, true)) {
        echo "ℹ️  [Skip] คอลัมน์ access_identity มีอยู่แล้ว\n";
    } else {
        $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_identity TINYINT(1) NOT NULL DEFAULT 0");
        echo "✅ [Added] access_identity (Identity & Governance)\n";
    }

    echo "\n✨ Migration เสร็จเรียบร้อย — ใช้ Identity Governance ใน portal เพื่อมอบสิทธิ์ให้เจ้าหน้าที่\n";
} catch (PDOException $e) {
    fwrite(STDERR, "❌ [Error] " . $e->getMessage() . "\n");
    exit(1);
}
