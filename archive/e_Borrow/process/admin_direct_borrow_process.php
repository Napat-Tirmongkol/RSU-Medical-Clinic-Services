<?php
// [เนเธเนเนเธ: process/admin_direct_borrow_process.php]
// เนเธเนเธเธทเนเธญเธเธญเธฅเธฑเธกเธเน borrower_student_id เธ•เธฒเธกเนเธเธฅเน SQL borrow_records.sql

ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/db_connect.php';
session_start();

$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เธ—เธตเนเนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

try {
    // 1. เธ•เธฃเธงเธเธชเธญเธ Login
    if (empty($_SESSION['user_id'])) {
        throw new Exception('เธเธฃเธธเธ“เธฒเน€เธเนเธฒเธชเธนเนเธฃเธฐเธเธเนเธซเธกเน (Session Expired)');
    }

    // 2. เธฃเธฑเธเธเนเธฒ
    $borrower_student_id = $_POST['student_id'] ?? null; // เธฃเธฑเธเธเนเธฒ ID เธเธฑเธเธจเธถเธเธฉเธฒ
    $lending_staff_id = $_POST['lending_staff_id'] ?? $_SESSION['user_id'];
    $due_date = $_POST['due_date'] ?? null;
    $cart_json = $_POST['cart_data'] ?? '[]';
    $cart_data = json_decode($cart_json, true);

    // 3. เธ•เธฃเธงเธเธชเธญเธเธเนเธฒเธงเนเธฒเธ
    if (empty($borrower_student_id)) throw new Exception('เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธนเนเธขเธทเธก (Student ID)');
    if (empty($cart_data)) throw new Exception('เนเธกเนเธเธเธฃเธฒเธขเธเธฒเธฃเธญเธธเธเธเธฃเธ“เนเนเธเธ•เธฐเธเธฃเนเธฒ');
    if (empty($due_date)) throw new Exception('เธเธฃเธธเธ“เธฒเธฃเธฐเธเธธเธงเธฑเธเธ—เธตเนเธเธทเธ');

    // เธ•เธฃเธงเธเธชเธญเธ Staff ID
    $stmtCheckStaff = $pdo->prepare("SELECT id FROM sys_staff WHERE id = ?");
    $stmtCheckStaff->execute([$lending_staff_id]);
    if ($stmtCheckStaff->rowCount() == 0) {
        $lending_staff_id = $pdo->query("SELECT id FROM sys_staff ORDER BY id ASC LIMIT 1")->fetchColumn();
    }

    // 4. เน€เธฃเธดเนเธก Transaction
    $pdo->beginTransaction();
    $success_count = 0;
    $errors = [];

    // Prepared Statements
    // เธญเธฑเธเน€เธ”เธ•เธชเธ–เธฒเธเธฐเธเธญเธ (เนเธเนเธงเธดเธเธตเน€เธเนเธ Case-insensitive เน€เธเธทเนเธญ Available/available)
    $sql_update = "UPDATE borrow_items 
                   SET status = 'borrowed' 
                   WHERE id = :eid AND (status = 'available' OR status = 'Available')";
    
    // โ… เนเธเนเนเธเธเธทเนเธญเธเธญเธฅเธฑเธกเธเนเนเธซเนเธ•เธฃเธเธเธฑเธ Database (borrow_records.sql)
    // - borrower_student_id: เธเธนเนเธขเธทเธก
    // - equipment_id: เนเธญเน€เธ—เนเธกเธ—เธตเนเธขเธทเธก
    // - item_id: เนเธญเน€เธ—เนเธกเธ—เธตเนเธขเธทเธก (เนเธชเนเน€เธเธทเนเธญเนเธงเนเธ–เนเธฒเธกเธต)
    // - type_id: เธเธฃเธฐเน€เธ เธ— (เธฃเธฑเธเน€เธเธดเนเธกเธเธฒเธ cart)
    $sql_insert = "INSERT INTO borrow_records 
                   (borrower_student_id, equipment_id, item_id, type_id, lending_staff_id, borrow_date, due_date, status, quantity) 
                   VALUES (:sid, :eid, :iid, :tid, :lid, NOW(), :due, 'borrowed', 1)";

    $stmt_update = $pdo->prepare($sql_update);
    $stmt_insert = $pdo->prepare($sql_insert);

    foreach ($cart_data as $item) {
        $item_id = $item['item_id'] ?? null;
        $type_id = $item['type_id'] ?? null; // เธฃเธฑเธ type_id เธกเธฒเธ”เนเธงเธข
        
        if (!$item_id) continue;

        // 4.1 เธ•เธฑเธ”เธชเธ•เนเธญเธ
        $stmt_update->execute([':eid' => $item_id]);
        
        if ($stmt_update->rowCount() > 0) {
            // 4.2 เธเธฑเธเธ—เธถเธ Transaction
            try {
                $stmt_insert->execute([
                    ':sid' => $borrower_student_id, // เนเธเน ID เธเธฑเธเธจเธถเธเธฉเธฒเธ—เธตเนเธฃเธฑเธเธกเธฒ
                    ':eid' => $item_id,             // equipment_id
                    ':iid' => $item_id,             // item_id (เนเธชเนเธเนเธฒเน€เธ”เธตเธขเธงเธเธฑเธ)
                    ':tid' => $type_id,             // type_id (เธ–เนเธฒเนเธกเนเธกเธตเธเธฐเน€เธเนเธ null)
                    ':lid' => $lending_staff_id,
                    ':due' => $due_date
                ]);
                $success_count++;
            } catch (PDOException $ex) {
                $errors[] = "Item $item_id DB Error: " . $ex->getMessage();
            }
        } else {
            $errors[] = "Item $item_id เนเธกเนเธงเนเธฒเธ (เธซเธฃเธทเธญเนเธกเนเธกเธตเธญเธขเธนเนเธเธฃเธดเธ)";
        }
    }

    // 5. เธชเธฃเธธเธเธเธฅ
    if ($success_count > 0) {
        $pdo->commit();
        $response = [
            'status' => 'success',
            'message' => "เธเธฑเธเธ—เธถเธเธชเธณเน€เธฃเนเธ $success_count เธฃเธฒเธขเธเธฒเธฃ",
            'count' => $success_count
        ];
    } else {
        $pdo->rollBack();
        $error_msg = "เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธเธฑเธเธ—เธถเธเธฃเธฒเธขเธเธฒเธฃเนเธ”เนเน€เธฅเธข";
        if (!empty($errors)) {
            $error_msg .= "\nเธชเธฒเน€เธซเธ•เธธ: " . implode(", ", $errors);
        }
        throw new Exception($error_msg);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

ob_end_clean();
echo json_encode($response);
exit;
?>
