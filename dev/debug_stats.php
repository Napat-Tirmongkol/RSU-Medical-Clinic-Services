<?php
require 'c:/xampp/htdocs/e-campaignv2/config/db_connect.php';
$pdo = db();
echo "--- ALL CAMPAIGNS ---\n";
$stmt = $pdo->query('SELECT id, name, status, total_capacity FROM camp_list');
while($row = $stmt->fetch()) {
    echo "ID: {$row['id']} | Name: {$row['name']} | Status: {$row['status']} | Capacity: {$row['total_capacity']}\n";
}

echo "\n--- BOOKINGS COUNT ---\n";
$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM camp_bookings GROUP BY status");
while($row = $stmt->fetch()) {
    echo "Status: {$row['status']} | Count: {$row['count']}\n";
}
