<?php
require_once 'config.php';
$pdo = db();
$stmt = $pdo->query("DESCRIBE sys_users");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $columns);
