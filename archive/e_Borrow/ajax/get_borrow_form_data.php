<?php
// get_borrow_form_data.php
// (เธญเธฑเธเน€เธ”เธ•: เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฒเธ sys_users)
// เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธณเธซเธฃเธฑเธ Popup "เธขเธทเธก" (เธ—เธตเน Admin เธเธ”)

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
// (เน€เธฃเธฒเนเธเน 'check_session.php' เน€เธเธฃเธฒเธฐเธเธตเนเธเธทเธญเธเธฑเธเธเนเธเธฑเธเธเธญเธ Admin/Staff)
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. เธ•เธฑเนเธเธเนเธฒ Header เนเธซเนเธ•เธญเธเธเธฅเธฑเธเน€เธเนเธ JSON
header('Content-Type: application/json');

// 3. เธชเธฃเนเธฒเธเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเน€เธเนเธเธเธณเธ•เธญเธ
$response = [
    'status' => 'error', 
    'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ',
    'equipment_type' => null, 
    'borrowers' => []  // (***เธชเธณเธเธฑเธ: เน€เธฃเธฒเธขเธฑเธเนเธเนเธเธทเนเธญ 'borrowers' เน€เธซเธกเธทเธญเธเน€เธ”เธดเธก)
];

// 4. เธฃเธฑเธ ID เธญเธธเธเธเธฃเธ“เนเธเธฒเธ URL
$type_id = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
if ($type_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน';
    echo json_encode($response);
    exit;
}

try {
    // 5.1 เธ”เธถเธเธเนเธญเธกเธนเธฅเธญเธธเธเธเธฃเธ“เน (เน€เธซเธกเธทเธญเธเน€เธ”เธดเธก)
    $stmt_equip = $pdo->prepare("SELECT id, name FROM borrow_categories WHERE id = ? AND available_quantity > 0");
    $stmt_equip->execute([$type_id]);
    $equipment_type = $stmt_equip->fetch(PDO::FETCH_ASSOC);

    if (!$equipment_type) {
        $response['message'] = 'เนเธกเนเธเธเธญเธธเธเธเธฃเธ“เน เธซเธฃเธทเธญเธญเธธเธเธเธฃเธ“เนเธเธตเนเนเธกเนเธเธฃเนเธญเธกเนเธซเนเธขเธทเธก';
        echo json_encode($response);
        exit;
    }
    $response['equipment_type'] = $equipment_type;

    // 5.2 (SQL เนเธซเธกเน) เธ”เธถเธเธฃเธฒเธขเธเธทเนเธญเธเธนเนเนเธเนเธ—เธฑเนเธเธซเธกเธ”เธเธฒเธ sys_users
    $stmt_borrowers = $pdo->prepare("SELECT id, full_name, phone_number FROM sys_users ORDER BY full_name ASC");
    $stmt_borrowers->execute();
    $borrowers_list = $stmt_borrowers->fetchAll(PDO::FETCH_ASSOC);
    
    // (เน€เธเธฅเธตเนเธขเธ 'phone_number' เน€เธเนเธ 'contact_info' เน€เธเธทเนเธญเนเธซเน JS เน€เธ”เธดเธกเธ—เธณเธเธฒเธเนเธ”เน)
    $borrowers_formatted = [];
    foreach ($borrowers_list as $person) {
        $borrowers_formatted[] = [
            'id' => $person['id'],
            'full_name' => $person['full_name'],
            'contact_info' => $person['phone_number'] // (เนเธเธฅเธเธเธทเนเธญเธเธญเธฅเธฑเธกเธเน)
        ];
    }
    
    $response['borrowers'] = $borrowers_formatted; // (เธชเนเธเธเธฅเธฑเธเนเธเธเธทเนเธญ 'borrowers')
    $response['status'] = 'success';
    $response['message'] = 'เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธณเน€เธฃเนเธ';

} catch (PDOException $e) {
    $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
}

// 6. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>
