<?php
require_once(__DIR__ . '/../e_Borrow/includes/db_connect.php');
$pdo = db();
$res = $pdo->query("DESCRIBE borrow_payments")->fetchAll(PDO::FETCH_ASSOC);
file_put_contents(__DIR__ . '/db_schema.txt', print_r($res, true));
echo "Done";
?>
