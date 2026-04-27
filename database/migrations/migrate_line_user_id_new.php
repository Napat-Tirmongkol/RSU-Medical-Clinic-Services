<?php
/**
 * migrate_line_user_id_new.php
 *
 * เพิ่มคอลัมน์ line_user_id_new ใน sys_users และ linked_line_user_id_new ใน sys_staff
 * สำหรับเก็บ UID ของ LINE Login provider ใหม่ ระหว่างช่วง migrate
 *
 * วิธีรัน:
 *   1) CLI:     php database/migrations/migrate_line_user_id_new.php
 *   2) Browser: https://<host>/.../database/migrations/migrate_line_user_id_new.php?token=<TOKEN>
 *               โดย <TOKEN> ต้องตรงกับ MIGRATION_TOKEN ใน config/secrets.php
 *
 * ⚠️  ความปลอดภัย:
 *   - ตั้งค่า MIGRATION_TOKEN ใน config/secrets.php ก่อนรันผ่าน browser
 *   - ลบไฟล์นี้ทิ้งทันทีหลังรันเสร็จ (หรืออย่างน้อยลบ token จาก secrets)
 */
declare(strict_types=1);

$isCli = (php_sapi_name() === 'cli');

// ── Web mode: ตรวจ token ก่อนทำอะไร ─────────────────────────────
if (!$isCli) {
    // โหลด secrets เพื่อเอา token
    $secretsPath = __DIR__ . '/../../config/secrets.php';
    $secrets = file_exists($secretsPath) ? require $secretsPath : [];
    $expectedToken = $secrets['MIGRATION_TOKEN'] ?? '';
    $providedToken = $_GET['token'] ?? '';

    if ($expectedToken === '' || $providedToken === '' || !hash_equals((string)$expectedToken, (string)$providedToken)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><meta charset=utf-8><title>Forbidden</title>";
        echo "<div style='font-family:sans-serif;padding:40px;text-align:center;color:#dc2626'>";
        echo "<h1>403 Forbidden</h1><p>Invalid or missing migration token.</p>";
        echo "</div>";
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><meta charset=utf-8><title>Migration: line_user_id_new</title>";
    echo "<style>body{font-family:ui-monospace,Menlo,Consolas,monospace;background:#0b1020;color:#d1e5ff;padding:30px;line-height:1.7}";
    echo "h1{color:#7dd3fc;font-size:18px;margin-bottom:20px;border-bottom:1px solid #1e293b;padding-bottom:10px}";
    echo ".ok{color:#34d399}.skip{color:#94a3b8}.err{color:#fb7185}.warn{color:#fbbf24;background:#1e1b1b;padding:15px;border-left:4px solid #fbbf24;margin-top:30px;border-radius:6px}</style>";
    echo "<h1>🛠  Migration: line_user_id_new</h1><pre>";
}

require_once __DIR__ . '/../../config.php';

/** พิมพ์บรรทัดผลลัพธ์ — รองรับทั้ง CLI และ web */
function mig_log(string $line, string $kind = 'ok'): void {
    global $isCli;
    if ($isCli) {
        echo $line . "\n";
    } else {
        $cls = htmlspecialchars($kind);
        echo "<span class='{$cls}'>" . htmlspecialchars($line) . "</span>\n";
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :col");
    $stmt->execute([':col' => $column]);
    return (bool)$stmt->fetch();
}

function index_exists(PDO $pdo, string $table, string $indexName): bool {
    $stmt = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = :name");
    $stmt->execute([':name' => $indexName]);
    return (bool)$stmt->fetch();
}

try {
    $pdo = db();

    // ── sys_users : line_user_id_new ──────────────────────────────
    if (!column_exists($pdo, 'sys_users', 'line_user_id_new')) {
        $pdo->exec("ALTER TABLE sys_users ADD COLUMN line_user_id_new VARCHAR(64) NULL DEFAULT NULL AFTER line_user_id");
        mig_log("✓ Added column sys_users.line_user_id_new", 'ok');
    } else {
        mig_log("• sys_users.line_user_id_new already exists", 'skip');
    }

    if (!index_exists($pdo, 'sys_users', 'idx_line_user_id_new')) {
        $pdo->exec("CREATE UNIQUE INDEX idx_line_user_id_new ON sys_users (line_user_id_new)");
        mig_log("✓ Added unique index sys_users.idx_line_user_id_new", 'ok');
    } else {
        mig_log("• sys_users index idx_line_user_id_new already exists", 'skip');
    }

    // ── sys_staff : linked_line_user_id_new ──────────────────────
    $hasStaff = $pdo->query("SHOW TABLES LIKE 'sys_staff'")->fetch();
    if ($hasStaff) {
        if (!column_exists($pdo, 'sys_staff', 'linked_line_user_id_new')) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN linked_line_user_id_new VARCHAR(64) NULL DEFAULT NULL AFTER linked_line_user_id");
            mig_log("✓ Added column sys_staff.linked_line_user_id_new", 'ok');
        } else {
            mig_log("• sys_staff.linked_line_user_id_new already exists", 'skip');
        }

        if (!index_exists($pdo, 'sys_staff', 'idx_linked_line_user_id_new')) {
            $pdo->exec("CREATE UNIQUE INDEX idx_linked_line_user_id_new ON sys_staff (linked_line_user_id_new)");
            mig_log("✓ Added unique index sys_staff.idx_linked_line_user_id_new", 'ok');
        } else {
            mig_log("• sys_staff index idx_linked_line_user_id_new already exists", 'skip');
        }
    } else {
        mig_log("• sys_staff table not found — skipped", 'skip');
    }

    mig_log("\nMigration complete.", 'ok');

} catch (Exception $e) {
    if ($isCli) {
        fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
        exit(1);
    } else {
        mig_log("✗ Migration failed: " . $e->getMessage(), 'err');
    }
}

if (!$isCli) {
    echo "</pre>";
    echo "<div class='warn'>";
    echo "⚠️  <strong>กรุณาลบไฟล์นี้ทิ้งทันที</strong> หรือล้างค่า MIGRATION_TOKEN ใน config/secrets.php<br>";
    echo "เพื่อป้องกันการรันซ้ำโดยไม่ตั้งใจหรือคนอื่นเข้าถึง";
    echo "</div>";
}
