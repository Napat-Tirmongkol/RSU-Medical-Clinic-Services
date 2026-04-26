<?php
require_once 'config.php';
try {
    $pdo = db();
    $stmt = $pdo->query('DESCRIBE sys_users');
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
