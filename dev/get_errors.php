<?php
require_once __DIR__ . '/../config.php';
try {
    $pdo = db();
    $stmt = $pdo->query("SELECT * FROM sys_error_logs ORDER BY created_at DESC LIMIT 5");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($logs);
} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage();
}
