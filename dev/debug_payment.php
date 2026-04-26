<?php
require_once(__DIR__ . '/../e_Borrow/includes/db_connect.php');
$pdo = db();
$id = 17;

echo "--- borrow_payments for ID $id ---\n";
$stmt = $pdo->prepare("SELECT * FROM borrow_payments WHERE id = ?");
$stmt->execute([$id]);
print_r($stmt->fetch());

echo "\n--- borrow_fines linked? ---\n";
$stmt = $pdo->prepare("SELECT * FROM borrow_fines WHERE id = (SELECT fine_id FROM borrow_payments WHERE id = ?)");
$stmt->execute([$id]);
print_r($stmt->fetch());

echo "\n--- borrow_records linked? ---\n";
$stmt = $pdo->prepare("SELECT * FROM borrow_records WHERE id = (SELECT transaction_id FROM borrow_payments WHERE id = ?)");
$stmt->execute([$id]);
print_r($stmt->fetch());
?>
