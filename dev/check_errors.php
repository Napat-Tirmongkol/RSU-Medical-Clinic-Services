<?php
require_once __DIR__ . '/../config.php';
$pdo = db();
$rows = $pdo->query("SELECT * FROM sys_error_logs ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo "ID: " . $row['id'] . "\n";
    echo "Time: " . $row['created_at'] . "\n";
    echo "Source: " . $row['source'] . "\n";
    echo "Message: " . $row['message'] . "\n";
    echo "Context: " . $row['context'] . "\n";
    echo "-----------------------------------\n";
}
