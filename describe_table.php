<?php
require_once __DIR__ . '/config.php';
$pdo = db();
try {
    $stmt = $pdo->query("DESCRIBE sys_activity_logs");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "COLUMNS_FOUND: " . json_encode($columns);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>
