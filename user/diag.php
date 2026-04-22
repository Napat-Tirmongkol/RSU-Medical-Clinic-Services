<?php
// user/diag.php — ดึงข้อมูลตัวอย่างจาก sys_faculties
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config.php';
$pdo = db();

echo "<h2>Sampling sys_faculties Data...</h2>";
try {
    $stmt = $pdo->query("SELECT * FROM sys_faculties LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "✅ Found data! Columns identified:<br>";
        echo "<pre>" . print_r(array_keys($row), true) . "</pre>";
        echo "Sample values:<br>";
        echo "<pre>" . print_r($row, true) . "</pre>";
    } else {
        echo "❌ Table is EMPTY. Cannot identify columns via sampling.<br>";
        
        // Try SHOW COLUMNS as fallback
        echo "<h3>Attempting SHOW COLUMNS...</h3>";
        $stmt = $pdo->query("SHOW COLUMNS FROM sys_faculties");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        print_r($cols);
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<h2>Done!</h2>";
