<?php
/**
 * database/migrations/migrate_access_flags_extra.php
 * เพิ่ม access flags ใหม่สำหรับโมดูลที่ยังไม่มี role check ครอบคลุม:
 *   - access_ai           : AI Assistant / QA Lab / Prompts / Knowledge
 *   - access_consumables  : ระบบวัสดุสิ้นเปลือง (/consumables/)
 *   - access_asset        : ระบบครุภัณฑ์ (/asset/)
 *
 * Idempotent — รันซ้ำได้ปลอดภัย
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();
    $cols = $pdo->query("DESCRIBE sys_staff")->fetchAll(PDO::FETCH_COLUMN);

    $newFlags = [
        'access_ai'          => 'AI Assistant/QA Lab/Prompts/Knowledge',
        'access_consumables' => 'ระบบวัสดุสิ้นเปลือง (consumables)',
        'access_asset'       => 'ระบบครุภัณฑ์ (asset)',
    ];

    foreach ($newFlags as $col => $desc) {
        if (in_array($col, $cols, true)) {
            echo "ℹ️  [Skip] คอลัมน์ {$col} มีอยู่แล้ว ({$desc})\n";
            continue;
        }
        $pdo->exec("ALTER TABLE sys_staff ADD COLUMN {$col} TINYINT(1) NOT NULL DEFAULT 0");
        echo "✅ [Added] {$col} ({$desc})\n";
    }

    echo "\n✨ Migration เสร็จเรียบร้อย — ใช้ Identity Governance ใน portal เพื่อมอบสิทธิ์ให้เจ้าหน้าที่\n";
} catch (PDOException $e) {
    fwrite(STDERR, "❌ [Error] " . $e->getMessage() . "\n");
    exit(1);
}
