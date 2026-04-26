<?php
require_once __DIR__ . '/../config.php';

try {
    $pdo = db();
    echo "Connected to: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
    
    echo "Attempting to log activity...\n";
    $res = log_activity("Debug Action", "Testing logging from scratch script");
    
    if ($res) {
        echo "Log successful!\n";
    } else {
        echo "Log failed (returned false).\n";
    }
} catch (Exception $e) {
    echo "Error caught: " . $e->getMessage() . "\n";
}
