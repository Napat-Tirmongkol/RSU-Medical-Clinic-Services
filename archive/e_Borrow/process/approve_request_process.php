<?php
// process/approve_request_process.php (เธเธเธฑเธเนเธเนเนเธ: เธฃเธญเธเธฃเธฑเธ AJAX/JSON เนเธฅเธฐ approver_id)
include('../includes/check_session.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

// เธ•เธฑเนเธเธเนเธฒเนเธซเนเธ•เธญเธเธเธฅเธฑเธเน€เธเนเธ JSON
header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

// เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเน€เธเนเธ POST เนเธฅเธฐเธกเธตเธเนเธฒ transaction_id เธชเนเธเธกเธฒ
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['transaction_id'])) {
    
    $transaction_id = $_POST['transaction_id'];
    $selected_item_id = $_POST['selected_item_id']; // เนเธญเน€เธ—เนเธกเธ—เธตเน Admin เน€เธฅเธทเธญเธเธเธฒเธ Dropdown
    
    // เธ”เธถเธ ID เธเธญเธเธเธเธญเธเธธเธกเธฑเธ•เธด
    $admin_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;

    if (empty($selected_item_id)) {
        $response['message'] = "เธเธฃเธธเธ“เธฒเน€เธฅเธทเธญเธเธญเธธเธเธเธฃเธ“เน (Serial Number)";
        echo json_encode($response);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. เธ”เธถเธเธเนเธญเธกเธนเธฅเน€เธ”เธดเธกเธเธฒเธ Database เนเธ”เธขเธ•เธฃเธ
        $stmt_chk = $pdo->prepare("SELECT item_id FROM borrow_records WHERE id = ?");
        $stmt_chk->execute([$transaction_id]);
        $current_item_id = $stmt_chk->fetchColumn(); 

        // 2. เน€เธเธฃเธตเธขเธเน€เธ—เธตเธขเธเธเธญเธเธ—เธตเนเน€เธฅเธทเธญเธเนเธซเธกเน (Selected) เธเธฑเธเธเธญเธเน€เธ”เธดเธก (Current)
        if ($selected_item_id != $current_item_id) {
            
            // === เธเธฃเธ“เธตเธกเธตเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเธเธดเนเธเธญเธธเธเธเธฃเธ“เน ===
            
            // 2.1 เธเธฅเนเธญเธขเธเธญเธเน€เธ”เธดเธกเนเธซเนเธงเนเธฒเธ (เธ–เนเธฒเธกเธตเธเนเธฒ)
            if (!empty($current_item_id)) {
                $stmt_release = $pdo->prepare("UPDATE borrow_items SET status = 'available' WHERE id = ?");
                $stmt_release->execute([$current_item_id]);
            }

            // 2.2 เน€เธเนเธเธเธญเธเธเธดเนเธเนเธซเธกเนเธงเนเธฒเธงเนเธฒเธเธเธฃเธดเธเนเธซเธก
            $stmt_status = $pdo->prepare("SELECT status FROM borrow_items WHERE id = ?");
            $stmt_status->execute([$selected_item_id]);
            $new_item_status = $stmt_status->fetchColumn();

            if ($new_item_status !== 'available') {
                // เธ–เนเธฒเธชเธ–เธฒเธเธฐเนเธกเนเนเธเน available เนเธชเธ”เธเธงเนเธฒเธ–เธนเธเธเธเธญเธทเนเธเนเธขเนเธเนเธเนเธฅเนเธง
                throw new Exception("เธญเธธเธเธเธฃเธ“เนเธเธดเนเธเธ—เธตเนเน€เธฅเธทเธญเธ (ID: $selected_item_id) เนเธกเนเธงเนเธฒเธ (เธชเธ–เธฒเธเธฐ: $new_item_status)");
            }

            // 2.3 เธเธญเธเธเธญเธเธเธดเนเธเนเธซเธกเน
            $stmt_borrow = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
            $stmt_borrow->execute([$selected_item_id]);

        } 
        
        // 3. เธญเธฑเธเน€เธ”เธ•เธชเธ–เธฒเธเธฐเธเธณเธเธญเน€เธเนเธ 'approved' เนเธฅเธฐเธเธฑเธเธ—เธถเธเธเนเธญเธกเธนเธฅเธเธนเนเธญเธเธธเธกเธฑเธ•เธด (approver_id)
        $sql = "UPDATE borrow_records 
                SET approval_status = 'approved', 
                    approver_id = ?,    -- โ… เธเธญเธฅเธฑเธกเธเนเธ—เธตเนเน€เธเธดเนเธกเนเธเธเธฑเนเธเธ•เธญเธเธ—เธตเน 1
                    item_id = ?,      
                    equipment_id = ?  
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$admin_id, $selected_item_id, $selected_item_id, $transaction_id]);

        $pdo->commit();
        
        // เธเธฑเธเธ—เธถเธ Log 
        if(function_exists('log_action')){
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_id}) เนเธ”เนเธญเธเธธเธกเธฑเธ•เธดเธเธณเธเธญ (TID: {$transaction_id}) เธชเธณเธซเธฃเธฑเธเธญเธธเธเธเธฃเธ“เน (Item ID: {$selected_item_id})";
            log_action($pdo, $admin_id, 'approve_request', $log_desc);
        }

        // โ… เธชเนเธ JSON เธ•เธญเธเธเธฅเธฑเธเน€เธกเธทเนเธญเธชเธณเน€เธฃเนเธ
        $response['status'] = 'success';
        $response['message'] = "เธญเธเธธเธกเธฑเธ•เธดเน€เธฃเธตเธขเธเธฃเนเธญเธขเนเธฅเนเธง (เธกเธญเธเธญเธธเธเธเธฃเธ“เน ID: $selected_item_id)";

    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: " . $e->getMessage();
    }
} else {
    $response['message'] = "เธเนเธญเธกเธนเธฅเนเธกเนเธเธฃเธเธ–เนเธงเธ";
}

// 4. เธชเนเธเธเธณเธ•เธญเธ JSON เธเธฅเธฑเธเนเธเน€เธชเธกเธญ
echo json_encode($response);
exit();
?>