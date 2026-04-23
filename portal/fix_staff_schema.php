<?php
require_once __DIR__ . '/../config.php';
$pdo = db();

echo "Starting Migration...<br>";

try {
    $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS access_eborrow TINYINT(1) DEFAULT 1 AFTER role");
    echo "Added access_eborrow successfully.<br>";
} catch(Exception $e) {
    echo "access_eborrow: " . $e->getMessage() . "<br>";
}

try {
    $pdo->exec("ALTER TABLE sys_staff ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL AFTER full_name");
    echo "Added email successfully.<br>";
} catch(Exception $e) {
    echo "email: " . $e->getMessage() . "<br>";
}

echo "Migration Finished.";
