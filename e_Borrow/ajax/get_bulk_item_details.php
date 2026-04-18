<?php
// ajax/get_bulk_item_details.php
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');

// รับข้อมูลเป็น Array ของ ID [1, 2, 3, ...]
$data_json = $_POST['ids'] ?? '[]';
$ids = json_decode($data_json, true);

if (empty($ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No IDs provided']);
    exit;
}

try {
    // แปลง Array เป็น string สำหรับ query (เช่น "1,2,3")
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    $sql = "SELECT id, name, serial_number FROM borrow_items WHERE id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status' => 'success', 'items' => $items]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>