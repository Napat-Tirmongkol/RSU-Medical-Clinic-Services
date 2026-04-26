<?php
require_once 'config.php';
$pdo = db();
$stmt = $pdo->query('SELECT * FROM sys_error_logs ORDER BY id DESC LIMIT 5');
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
