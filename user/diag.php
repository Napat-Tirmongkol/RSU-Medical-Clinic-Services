<?php
// user/diag.php — เช็คโครงสร้างตาราง sys_faculties
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config.php';
$pdo = db();

echo "<h2>Analyzing sys_faculties Structure...</h2>";
try {
    $stmt = $pdo->query("DESCRIBE sys_faculties");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $c) {
        echo "<tr>";
        foreach ($c as $val) echo "<td>" . htmlspecialchars((string)$val) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "❌ Error describing table: " . $e->getMessage() . "<br>";
}

echo "<h2>Done!</h2>";
