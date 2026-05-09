<?php
/**
 * database/migrations/migrate_access_scholarship.php
 * เพิ่ม access_scholarship flag สำหรับโมดูลนักศึกษาทุน
 *   - portal admin (sys_admins) ไม่ใช้ flag นี้ (ผ่านเสมอผ่าน superadmin/admin role)
 *   - sys_staff ที่ไม่ใช่ superadmin ต้องมี access_scholarship = 1 ถึงจะเข้าหน้าได้
 *
 * Idempotent — รันซ้ำได้ปลอดภัย
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();
    $cols = $pdo->query("DESCRIBE sys_staff")->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('access_scholarship', $cols, true)) {
        echo "ℹ️  [Skip] คอลัมน์ access_scholarship มีอยู่แล้ว\n";
    } else {
        $pdo->exec("ALTER TABLE sys_staff ADD COLUMN access_scholarship TINYINT(1) NOT NULL DEFAULT 0");
        echo "✅ [Added] access_scholarship (ระบบนักศึกษาทุน)\n";
    }

    echo "\n✨ Migration เสร็จเรียบร้อย — ใช้ Identity Governance ใน portal เพื่อมอบสิทธิ์ให้เจ้าหน้าที่\n";
} catch (PDOException $e) {
    fwrite(STDERR, "❌ [Error] " . $e->getMessage() . "\n");
    exit(1);
}
