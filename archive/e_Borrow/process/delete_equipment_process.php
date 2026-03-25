<?php
// delete_equipment_process.php

// 1. (เน€เธเธฅเธตเนเธขเธ) เนเธเนเธขเธฒเธกเธชเธณเธซเธฃเธฑเธ AJAX
include('includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('includes/log_function.php'); // โ—€๏ธ (เน€เธเธดเนเธก) เน€เธฃเธตเธขเธเนเธเน Log

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}

// (เนเธซเธกเน) เธ•เธฑเนเธเธเนเธฒ Header เน€เธเนเธ JSON
header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

// 3. เธฃเธฑเธ ID เธญเธธเธเธเธฃเธ“เน
// (เน€เธเธฅเธตเนเธขเธ) เธฃเธฑเธเธเธฒเธ POST เธซเธฃเธทเธญ GET เธเนเนเธ”เน
$equipment_id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;

if ($equipment_id == 0) {
    $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธญเธธเธเธเธฃเธ“เน';
    echo json_encode($response);
    exit;
}

// 4. เธ•เธฃเธงเธเธชเธญเธ Foreign Key เนเธฅเธฐ เธ”เธณเน€เธเธดเธเธเธฒเธฃ
try {
    // (เนเธเนเนเธ) เน€เธเนเธเธงเนเธฒเธกเธต "เธเธดเนเธ" เธญเธธเธเธเธฃเธ“เนเธเธนเธเธญเธขเธนเนเธซเธฃเธทเธญเนเธกเน
    $sql_check = "SELECT COUNT(*) FROM borrow_items WHERE type_id = ?";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([$equipment_id]);
    $transaction_count = $stmt_check->fetchColumn();

    if ($transaction_count > 0) {
        // (เนเธเนเนเธ) เธชเนเธเน€เธเนเธ JSON error เธเธฅเธฑเธเนเธ
        throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธเนเธ”เน เน€เธเธทเนเธญเธเธเธฒเธเธขเธฑเธเธกเธตเธญเธธเธเธเธฃเธ“เนเธฃเธฒเธขเธเธดเนเธเธเธนเธเธญเธขเธนเนเธเธฑเธเธเธฃเธฐเน€เธ เธ—เธเธตเน ($transaction_count เธเธดเนเธ)");
    }

    // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
    // (เธ”เธถเธเธเนเธญเธกเธนเธฅเธญเธธเธเธเธฃเธ“เน "เธเนเธญเธ" เธ—เธตเนเธเธฐเธฅเธ)
    $stmt_get = $pdo->prepare("SELECT name FROM borrow_categories WHERE id = ?");
    $stmt_get->execute([$equipment_id]);
    $equip_name_for_log = $stmt_get->fetchColumn() ?: "ID: {$equipment_id}";
    // โ—€๏ธ --- (เธเธเธชเนเธงเธเธ”เธถเธเธเนเธญเธกเธนเธฅ Log) --- โ—€๏ธ


    // 6. เธ”เธณเน€เธเธดเธเธเธฒเธฃเธฅเธ
    $sql_delete = "DELETE FROM borrow_categories WHERE id = ?";
    $stmt_delete = $pdo->prepare($sql_delete);
    $stmt_delete->execute([$equipment_id]);

    // 7. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเธฅเธเธชเธณเน€เธฃเนเธเธซเธฃเธทเธญเนเธกเน
    if ($stmt_delete->rowCount() > 0) {
        
        // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
        $admin_user_id = $_SESSION['user_id'] ?? null;
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเธฅเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน: '{$equip_name_for_log}'";
        log_action($pdo, $admin_user_id, 'delete_equipment_type', $log_desc);
        // โ—€๏ธ --- (เธเธเธชเนเธงเธ Log) --- โ—€๏ธ

        $response['status'] = 'success';
        $response['message'] = 'เธฅเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเธชเธณเน€เธฃเนเธ';
    } else {
        throw new Exception("เนเธกเนเธเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธ•เนเธญเธเธเธฒเธฃเธฅเธ (ID: $equipment_id)");
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>