<?php
require_once 'config.php';
$pdo = db();

echo "Checking database tables...\n";
$tables = ['sys_activity_logs', 'sys_error_logs', 'sys_users', 'sys_admins'];
foreach ($tables as $t) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$t'");
        if ($stmt->rowCount() > 0) {
            $count = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
            echo "[OK] Table '$t' exists. Count: $count\n";
        } else {
            echo "[!!] Table '$t' MISSING.\n";
        }
    } catch (Exception $e) {
        echo "[ERROR] checking '$t': " . $e->getMessage() . "\n";
    }
}

echo "\nChecking recent activity logs (last 5):\n";
try {
    $logs = $pdo->query("SELECT * FROM sys_activity_logs ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($logs);
} catch (Exception $e) {
    echo "Error fetching logs: " . $e->getMessage() . "\n";
}
