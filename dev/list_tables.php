<?php
require_once __DIR__ . '/../config.php';
$pdo = db();
echo "--- ALL TABLES ---\n";
$stmt = $pdo->query("SHOW TABLES");
while($row = $stmt->fetch()) {
    echo $row[0] . "\n";
}
echo "\n--- SYS_USERS COLUMNS ---\n";
$stmt = $pdo->query("SHOW COLUMNS FROM sys_users");
while($row = $stmt->fetch()) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
