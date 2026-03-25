<?php
// process/approve_request_process.php
include('../includes/check_session.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

// เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเน€เธเนเธ POST เนเธฅเธฐเธกเธตเธเนเธฒ transaction_id เธชเนเธเธกเธฒ
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['transaction_id'])) {
    
    $transaction_id = $_POST['transaction_id'];
    $selected_item_id = $_POST['selected_item_id']; // เนเธญเน€เธ—เนเธกเธ—เธตเน Admin เน€เธฅเธทเธญเธเธเธฒเธ Dropdown
    
    // เธ•เธฃเธงเธเธชเธญเธเธ•เธฑเธงเนเธเธฃ Session เธเธญเธ Admin (เธฃเธญเธเธฃเธฑเธเธ—เธฑเนเธ user_id เนเธฅเธฐ id)
    $admin_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;

    if (empty($selected_item_id)) {
        $_SESSION['error'] = "เธเธฃเธธเธ“เธฒเน€เธฅเธทเธญเธเธญเธธเธเธเธฃเธ“เน (Serial Number)";
        header("Location: ../admin/index.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. เธ”เธถเธเธเนเธญเธกเธนเธฅเน€เธ”เธดเธกเธเธฒเธ Database (เน€เธเธทเนเธญเธเธงเธฒเธกเธเธฑเธงเธฃเนเธ—เธตเนเธชเธธเธ” เนเธกเนเน€เธเธทเนเธญเธเนเธฒเธเธฒเธ Form)
        $stmt_chk = $pdo->prepare("SELECT item_id FROM borrow_records WHERE id = ?");
        $stmt_chk->execute([$transaction_id]);
        $current_item_id = $stmt_chk->fetchColumn(); // เธเธตเนเธเธทเธญเธเธญเธเธ—เธตเนเธฃเธฐเธเธเธเธญเธเนเธงเนเธญเธขเธนเน

        // 2. เน€เธเธฃเธตเธขเธเน€เธ—เธตเธขเธเธเธญเธเธ—เธตเนเน€เธฅเธทเธญเธ (Selected) เธเธฑเธเธเธญเธเน€เธ”เธดเธก (Current)
        if ($selected_item_id != $current_item_id) {
            
            // [เธเธฃเธ“เธตเน€เธเธฅเธตเนเธขเธเธเธดเนเธ]
            
            // 2.1 เธเธฅเนเธญเธขเธเธญเธเน€เธ”เธดเธกเนเธซเนเธงเนเธฒเธ (เธ–เนเธฒเธกเธต)
            if (!empty($current_item_id)) {
                $stmt_release = $pdo->prepare("UPDATE borrow_items SET status = 'available' WHERE id = ?");
                $stmt_release->execute([$current_item_id]);
            }

            // 2.2 เน€เธเนเธเธเธญเธเนเธซเธกเนเธงเนเธฒเธงเนเธฒเธเธเธฃเธดเธเนเธซเธก
            $stmt_status = $pdo->prepare("SELECT status FROM borrow_items WHERE id = ?");
            $stmt_status->execute([$selected_item_id]);
            $new_item_status = $stmt_status->fetchColumn();

            if ($new_item_status !== 'available') {
                throw new Exception("เธญเธธเธเธเธฃเธ“เนเธเธดเนเธเธ—เธตเนเน€เธฅเธทเธญเธ (ID: $selected_item_id) เนเธกเนเธงเนเธฒเธ (เธ–เธนเธเธขเธทเธกเนเธเนเธฅเนเธง)");
            }

            // 2.3 เธเธญเธเธเธญเธเนเธซเธกเน
            $stmt_borrow = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
            $stmt_borrow->execute([$selected_item_id]);

        } else {
            // [เธเธฃเธ“เธตเน€เธฅเธทเธญเธเธเธดเนเธเน€เธ”เธดเธก]
            // เนเธกเนเธ•เนเธญเธเธ—เธณเธญเธฐเนเธฃเธเธฑเธ table items เน€เธเธฃเธฒเธฐเธชเธ–เธฒเธเธฐเธกเธฑเธเน€เธเนเธ borrowed เนเธ”เธขเธฃเธฒเธขเธเธฒเธฃเธเธตเนเธญเธขเธนเนเนเธฅเนเธงเธ–เธนเธเธ•เนเธญเธ
        }
        
        // 3. เธญเธฑเธเน€เธ”เธ•เธชเธ–เธฒเธเธฐเธเธณเธเธญเน€เธเนเธ 'approved'
        $sql = "UPDATE borrow_records 
                SET approval_status = 'approved', 
                    approver_id = ?, 
                    item_id = ?,      -- เธเธฑเธเธ—เธถเธเธเธดเนเธเธ—เธตเนเน€เธฅเธทเธญเธ
                    equipment_id = ?  -- เธญเธฑเธเน€เธ”เธ• Foreign Key
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        // เธซเธกเธฒเธขเน€เธซเธ•เธธ: เนเธเน $selected_item_id เนเธชเนเธ—เธฑเนเธเธเนเธญเธ item_id เนเธฅเธฐ equipment_id
        $stmt->execute([$admin_id, $selected_item_id, $selected_item_id, $transaction_id]);

        $pdo->commit();
        
        // เธเธฑเธเธ—เธถเธ Log
        if(function_exists('writeLog')){
            writeLog($pdo, $admin_id, "Approve request ID: $transaction_id (Selected Item: $selected_item_id)", "approve");
        }

        $_SESSION['success'] = "เธญเธเธธเธกเธฑเธ•เธดเน€เธฃเธตเธขเธเธฃเนเธญเธขเนเธฅเนเธง (เธกเธญเธเธญเธธเธเธเธฃเธ“เน ID: $selected_item_id)";

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "เธเนเธญเธกเธนเธฅเนเธกเนเธเธฃเธเธ–เนเธงเธ";
}

header("Location: ../admin/index.php");
exit();
?>