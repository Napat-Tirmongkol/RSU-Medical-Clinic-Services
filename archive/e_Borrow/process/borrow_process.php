<?php
// borrow_process.php
// เธเธฑเธเธ—เธถเธเธเธฒเธฃเธขเธทเธกเธ—เธตเน Admin/Staff เน€เธเนเธเธเธเธเธ”เนเธซเน

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
include('includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('includes/log_function.php'); // โ—€๏ธ (เน€เธเธดเนเธก) เน€เธฃเธตเธขเธเนเธเน Log

// 2. เธ•เธฑเนเธเธเนเธฒ Header
header('Content-Type: application/json');

// 3. เธชเธฃเนเธฒเธเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเน€เธเนเธเธเธณเธ•เธญเธ
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

// 4. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเน€เธเนเธเธเธฒเธฃเธชเนเธเธเนเธญเธกเธนเธฅเนเธเธ POST เธซเธฃเธทเธญเนเธกเน
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 5. เธฃเธฑเธเธเนเธญเธกเธนเธฅเธเธฒเธเธเธญเธฃเนเธก
    $type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0; 
    $borrower_student_id = isset($_POST['borrower_id']) ? (int)$_POST['borrower_id'] : 0;
    $due_date = isset($_POST['due_date']) ? $_POST['due_date'] : null;

    if ($type_id == 0 || $borrower_student_id == 0 || $due_date == null) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเนเธกเนเธเธฃเธเธ–เนเธงเธ (เธเธนเนเธขเธทเธก เธซเธฃเธทเธญ เธงเธฑเธเธ—เธตเนเธเธทเธ)';
        echo json_encode($response);
        exit;
    }

    // 6. เน€เธฃเธดเนเธก Transaction (เธเธฒเธฃเธขเธทเธก)
    try {
        $pdo->beginTransaction();

        // 6.1 เธซเธฒ item เธ—เธตเนเธงเนเธฒเธเธเธฒเธ type เธเธตเน
        $stmt_find_item = $pdo->prepare("SELECT id FROM borrow_items WHERE type_id = ? AND status = 'available' LIMIT 1 FOR UPDATE");
        $stmt_find_item->execute([$type_id]);
        $available_item_id = $stmt_find_item->fetchColumn();

        if (!$available_item_id) {
            throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธขเธทเธกเนเธ”เน เธญเธธเธเธเธฃเธ“เนเธเธฃเธฐเน€เธ เธ—เธเธตเนเนเธกเนเธงเนเธฒเธเนเธฅเนเธง");
        }

        // 6.2 UPDATE เธญเธธเธเธเธฃเธ“เน (item) เน€เธเนเธ 'borrowed'
        $stmt_item = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
        $stmt_item->execute([$available_item_id]);

        // 6.3 UPDATE เธเธณเธเธงเธเนเธเธเธฃเธฐเน€เธ เธ— (type)
        $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity - 1 WHERE id = ? AND available_quantity > 0");
        $stmt_type->execute([$type_id]);

        // 6.4 INSERT เธเธฃเธฐเธงเธฑเธ•เธดเธเธฒเธฃเธขเธทเธก
        $sql_insert = "INSERT INTO borrow_records 
                        (equipment_id, equipment_type_id, borrower_student_id, due_date, status, approval_status, quantity, lending_staff_id) 
                       VALUES 
                        (?, ?, ?, ?, 'borrowed', 'staff_added', 1, ?)";
        $stmt_insert = $pdo->prepare($sql_insert);
        
        $admin_user_id = $_SESSION['user_id'] ?? null; // โ—€๏ธ (เธ”เธถเธ ID Admin เธ—เธตเนเธเธ”เธขเธทเธก)
        
        $stmt_insert->execute([$available_item_id, $type_id, $borrower_student_id, $due_date, $admin_user_id]);

        // โ—€๏ธ --- (เน€เธเธดเนเธกเธชเนเธงเธ Log) --- โ—€๏ธ
        if ($stmt_insert->rowCount() > 0) {
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) เนเธ”เนเธเธฑเธเธ—เธถเธเธเธฒเธฃเธขเธทเธก (Type ID: {$type_id}, Item ID: {$available_item_id}) เนเธซเนเธเธฑเธเธเธนเนเนเธเน (SID: {$borrower_student_id})";
            log_action($pdo, $admin_user_id, 'create_borrow_staff', $log_desc);
        }
        // โ—€๏ธ --- (เธเธเธชเนเธงเธ Log) --- โ—€๏ธ

        $pdo->commit();

        // 7. เธ–เนเธฒเธชเธณเน€เธฃเนเธ
        $response['status'] = 'success';
        $response['message'] = 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเธขเธทเธกเธชเธณเน€เธฃเนเธ';

    } catch (Exception $e) {
        $pdo->rollBack(); 
        $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ' . $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

// 8. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>