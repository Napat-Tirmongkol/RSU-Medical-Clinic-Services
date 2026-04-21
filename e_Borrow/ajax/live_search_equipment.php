<?php
// live_search_equipment.php
// ◀️ (แก้ไข) API นี้จะค้นหาจาก "Types" ที่ว่าง

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');

header('Content-Type: application/json');

$search_term = $_GET['term'] ?? '';

$response = [
    'status' => 'error',
    'message' => 'No term provided',
    'results' => []
];

if (empty($search_term)) {
    echo json_encode($response);
    exit;
}

try {
    $search_param = '%' . $search_term . '%';
    
    // ◀️ (แก้ไข) ค้นหาจาก borrow_categories และต้องมีของว่าง (available_quantity > 0)
    $sql = "SELECT id, name, serial_number, image_url, description, available_quantity
            FROM borrow_categories 
            WHERE available_quantity > 0 
              AND (name LIKE ? OR description LIKE ?)
            ORDER BY name ASC
            LIMIT 10"; 
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$search_param, $search_param]); // ◀️ (แก้ Parameter)
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response['status'] = 'success';
    $response['results'] = $equipments;
    $response['message'] = 'Search successful';

} catch (PDOException $e) {
    $response['message'] = 'Database Error: ' . $e->getMessage(); // ◀️ แก้ไข .getMessage
}

echo json_encode($response);
exit;
?>