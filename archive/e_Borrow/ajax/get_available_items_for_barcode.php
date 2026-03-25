<?php
// ajax/get_available_items_for_barcode.php
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

$type_id = $_GET['type_id'] ?? 0;

if (!$type_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Type ID']);
    exit;
}

try {
    // เธ”เธถเธเน€เธเธเธฒเธฐเธ—เธตเนเธงเนเธฒเธ (available)
    $sql = "SELECT id, name, serial_number 
            FROM borrow_items 
            WHERE type_id = ? AND status = 'available' 
            ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$type_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status' => 'success', 'items' => $items]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>