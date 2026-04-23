<?php
require_once __DIR__ . '/../config.php';
// require_once __DIR__ . '/includes/auth.php';
// if (($_SESSION['admin_role'] ?? '') !== 'superadmin') { die("Unauthorized"); }

$pdo = db();
$results = [];

try {
    // Check sys_admins
    $stmt = $pdo->query("SHOW COLUMNS FROM sys_admins LIKE 'reset_token'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE sys_admins ADD COLUMN reset_token VARCHAR(100) DEFAULT NULL, ADD COLUMN reset_expiry DATETIME DEFAULT NULL");
        $results[] = "Added reset columns to sys_admins.";
    } else {
        $results[] = "sys_admins already has reset columns.";
    }

    // Check sys_staff
    $stmt = $pdo->query("SHOW COLUMNS FROM sys_staff LIKE 'reset_token'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE sys_staff ADD COLUMN reset_token VARCHAR(100) DEFAULT NULL, ADD COLUMN reset_expiry DATETIME DEFAULT NULL");
        $results[] = "Added reset columns to sys_staff.";
    } else {
        $results[] = "sys_staff already has reset columns.";
    }

    $status = "success";
} catch (Exception $e) {
    $status = "error";
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head><title>Database Migration</title></head>
<body>
    <h1>Migration Status</h1>
    <?php if ($status === 'success'): ?>
        <ul style="color: green;">
            <?php foreach ($results as $res): ?>
                <li><?= htmlspecialchars($res) ?></li>
            <?php endforeach; ?>
        </ul>
        <p><strong>Success!</strong> You can now delete this file.</p>
    <?php else: ?>
        <p style="color: red;">Error: <?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <a href="index.php">Go back to Dashboard</a>
</body>
</html>
