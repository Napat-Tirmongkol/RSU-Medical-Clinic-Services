<?php
$secretsPath = __DIR__ . '/../config/secrets.php';
echo "Trying to read secrets from: " . realpath($secretsPath) . "\n";
if (file_exists($secretsPath)) {
    $secrets = require $secretsPath;
    echo "Secrets loaded. Keys found:\n";
    print_r(array_keys($secrets));
} else {
    echo "Secrets file NOT FOUND at " . realpath($secretsPath) . "\n";
}

require_once __DIR__ . '/../config/db_connect.php';
$ref = new ReflectionFunction('db');
echo "db() function defined in: " . $ref->getFileName() . " at line " . $ref->getStartLine() . "\n";

try {
    $pdo = db();
    echo "Successfully called db().\n";
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    echo "Active Database: " . ($dbName ?: "[NONE]") . "\n";
} catch (Exception $e) {
    echo "db() call failed: " . $e->getMessage() . "\n";
}
