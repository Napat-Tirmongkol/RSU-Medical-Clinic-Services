<?php
// get_equipments_ajax.php
// (เนเธเธฅเนเนเธซเธกเน) Endpoint เธชเธณเธซเธฃเธฑเธเธ”เธถเธเธเนเธญเธกเธนเธฅเธญเธธเธเธเธฃเธ“เนเธ”เนเธงเธข AJAX

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”', 'data' => []];

try {
    // 1. เน€เธ•เธฃเธตเธขเธก SQL Query เธเธทเนเธเธเธฒเธ (เน€เธซเธกเธทเธญเธเนเธ manage_equipment.php)
    // (เนเธเนเนเธ) เน€เธเธฅเธตเนเธขเธเน€เธเนเธ Query เธเธฒเธ borrow_categories
    $sql = "SELECT * FROM borrow_categories";

    $conditions = [];
    $params = [];

    // 2. เธฃเธฑเธเธเนเธฒเธ•เธฑเธงเธเธฃเธญเธเธเธฒเธ Request (GET เธซเธฃเธทเธญ POST เธเนเนเธ”เน)
    $search_query = $_REQUEST['search'] ?? '';
    $status_query = $_REQUEST['status'] ?? '';

    // 3. เธชเธฃเนเธฒเธเน€เธเธทเนเธญเธเนเธเนเธเธเนเธ”เธเธฒเธกเธดเธ
    if (!empty($search_query)) {
        $conditions[] = "(name LIKE ? OR description LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
    }

    if (!empty($status_query)) {
        // (เธซเธกเธฒเธขเน€เธซเธ•เธธ: เธเธฒเธฃเธเธฃเธญเธเธ•เธฒเธกเธชเธ–เธฒเธเธฐเธฃเธฒเธขเธเธดเนเธเนเธเธซเธเนเธฒเธเธตเนเธญเธฒเธเนเธกเนเธเธณเน€เธเนเธเนเธฅเนเธง)
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY name ASC";

    // 4. เธ”เธถเธเธเนเธญเธกเธนเธฅเนเธฅเธฐเธชเนเธเธเธฅเธฑเธเน€เธเนเธ JSON
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response['status'] = 'success';
    $response['data'] = $equipments;

} catch (PDOException $e) {
    $response['message'] = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: " . $e->getMessage();
}

echo json_encode($response);
?>