<?php
require_once __DIR__ . '/../config.php';
$pdo = db();
$stmt = $pdo->query("DESCRIBE camp_list");
$schema = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($schema, JSON_PRETTY_PRINT);
