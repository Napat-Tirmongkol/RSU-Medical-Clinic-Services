<?php
// live_search_equipment.php
// โ—€๏ธ (เนเธเนเนเธ) API เธเธตเนเธเธฐเธเนเธเธซเธฒเธเธฒเธ "Types" เธ—เธตเนเธงเนเธฒเธ

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

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
    
    // โ—€๏ธ (เนเธเนเนเธ) เธเนเธเธซเธฒเธเธฒเธ borrow_categories เนเธฅเธฐเธ•เนเธญเธเธกเธตเธเธญเธเธงเนเธฒเธ (available_quantity > 0)
    $sql = "SELECT id, name, serial_number, image_url, description, available_quantity
            FROM borrow_categories 
            WHERE available_quantity > 0 
              AND (name LIKE ? OR description LIKE ?)
            ORDER BY name ASC
            LIMIT 10"; 
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$search_param, $search_param]); // โ—€๏ธ (เนเธเน Parameter)
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response['status'] = 'success';
    $response['results'] = $equipments;
    $response['message'] = 'Search successful';

} catch (PDOException $e) {
    $response['message'] = 'Database Error: ' . $e->getMessage(); // โ—€๏ธ เนเธเนเนเธ .getMessage
}

echo json_encode($response);
exit;
?>