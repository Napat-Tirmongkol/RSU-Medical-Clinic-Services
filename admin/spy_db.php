<?php
require_once __DIR__ . '/config.php';
$pdo = db();
try {
    $stmt = $pdo->query("DESC sys_activity_logs");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = "";
    foreach($cols as $c) {
        $out .= $c['Field'] . " (" . $c['Type'] . ")\n";
    }
    file_put_contents(__DIR__ . '/db_structure.txt', $out);
    echo "COLUMNS_SAVED";
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/db_structure.txt', "ERROR: " . $e->getMessage());
    echo "ERROR_SAVED";
}
?>
