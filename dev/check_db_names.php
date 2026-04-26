<?php
// Try to connect to e_borrow instead of the one in secrets
$secrets = require(__DIR__ . '/../config/secrets.php');
$db_name = 'e_borrow'; // Trying the name from user's phpMyAdmin
$db_host = $secrets['DB_HOST'];
$db_user = $secrets['DB_USER'];
$db_pass = $secrets['DB_PASS'];

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    echo "Connected to $db_name successfully!\n";
    $stmt = $pdo->prepare("SELECT * FROM borrow_payments WHERE id = 17");
    $stmt->execute();
    print_r($stmt->fetch());
} catch (PDOException $e) {
    echo "Failed to connect to $db_name: " . $e->getMessage() . "\n";
}

// Try the one from secrets too
$db_name_secrets = $secrets['DB_NAME'];
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name_secrets;charset=utf8", $db_user, $db_pass);
    echo "Connected to $db_name_secrets successfully!\n";
    $stmt = $pdo->prepare("SELECT * FROM borrow_payments WHERE id = 17");
    $stmt->execute();
    print_r($stmt->fetch());
} catch (PDOException $e) {
    echo "Failed to connect to $db_name_secrets: " . $e->getMessage() . "\n";
}
?>
