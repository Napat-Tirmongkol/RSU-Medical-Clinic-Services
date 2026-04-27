<?php
/**
 * migrate_line_user_id_new.php
 *
 * เพิ่มคอลัมน์ line_user_id_new ใน sys_users และ linked_line_user_id_new ใน sys_staff
 * สำหรับเก็บ UID ของ LINE Login provider ใหม่ ระหว่างช่วง migrate
 *
 * รัน: php database/migrations/migrate_line_user_id_new.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

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
        echo "✓ Added column sys_users.line_user_id_new\n";
    } else {
        echo "• sys_users.line_user_id_new already exists\n";
    }

    if (!index_exists($pdo, 'sys_users', 'idx_line_user_id_new')) {
        $pdo->exec("CREATE UNIQUE INDEX idx_line_user_id_new ON sys_users (line_user_id_new)");
        echo "✓ Added unique index sys_users.idx_line_user_id_new\n";
    } else {
        echo "• sys_users index idx_line_user_id_new already exists\n";
    }

    // ── sys_staff : linked_line_user_id_new ──────────────────────
    $hasStaff = $pdo->query("SHOW TABLES LIKE 'sys_staff'")->fetch();
    if ($hasStaff) {
        if (!column_exists($pdo, 'sys_staff', 'linked_line_user_id_new')) {
            $pdo->exec("ALTER TABLE sys_staff ADD COLUMN linked_line_user_id_new VARCHAR(64) NULL DEFAULT NULL AFTER linked_line_user_id");
            echo "✓ Added column sys_staff.linked_line_user_id_new\n";
        } else {
            echo "• sys_staff.linked_line_user_id_new already exists\n";
        }

        if (!index_exists($pdo, 'sys_staff', 'idx_linked_line_user_id_new')) {
            $pdo->exec("CREATE UNIQUE INDEX idx_linked_line_user_id_new ON sys_staff (linked_line_user_id_new)");
            echo "✓ Added unique index sys_staff.idx_linked_line_user_id_new\n";
        } else {
            echo "• sys_staff index idx_linked_line_user_id_new already exists\n";
        }
    } else {
        echo "• sys_staff table not found — skipped\n";
    }

    echo "\nMigration complete.\n";

} catch (Exception $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
