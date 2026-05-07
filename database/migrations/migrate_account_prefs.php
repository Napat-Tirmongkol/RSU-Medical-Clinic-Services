<?php
require_once __DIR__ . '/../../config.php';

$pdo = db();
$results = [];

$cols = [
    'phone'        => "ADD COLUMN phone VARCHAR(30) DEFAULT NULL",
    'avatar_path'  => "ADD COLUMN avatar_path VARCHAR(255) DEFAULT NULL",
    'theme_pref'   => "ADD COLUMN theme_pref ENUM('light','dark','auto') NOT NULL DEFAULT 'light'",
    'notif_email'  => "ADD COLUMN notif_email TINYINT(1) NOT NULL DEFAULT 1",
    'notif_inapp'  => "ADD COLUMN notif_inapp TINYINT(1) NOT NULL DEFAULT 1",
];

try {
    foreach ($cols as $colName => $alterClause) {
        $stmt = $pdo->query("SHOW COLUMNS FROM sys_admins LIKE " . $pdo->quote($colName));
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE sys_admins $alterClause");
            $results[] = "sys_admins: added column `$colName`.";
        } else {
            $results[] = "sys_admins: column `$colName` already exists.";
        }
    }
    $status = "success";
} catch (Exception $e) {
    $status = "error";
    $error  = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head><title>Migrate Account Prefs</title></head>
<body style="font-family: system-ui, sans-serif; padding: 40px; max-width: 720px; margin: 0 auto;">
    <h1>Migration: Account Prefs (sys_admins)</h1>
    <?php if (($status ?? '') === 'success'): ?>
        <ul style="color:#15803d; line-height:1.7">
            <?php foreach ($results as $res): ?>
                <li><?= htmlspecialchars($res) ?></li>
            <?php endforeach; ?>
        </ul>
        <p><strong style="color:#15803d">Success.</strong> ลบไฟล์นี้ออกหลังรันเสร็จเพื่อความปลอดภัย</p>
    <?php else: ?>
        <p style="color:#dc2626">Error: <?= htmlspecialchars($error ?? 'unknown') ?></p>
    <?php endif; ?>
    <p><a href="../../portal/index.php">← กลับ Portal</a></p>
</body>
</html>
