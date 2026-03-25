<?php
// process/add_item_process.php
// (เธญเธฑเธเน€เธ”เธ•: V2 - เธ”เธฑเธเธเธฑเธ Error Duplicate Serial Number)

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin เนเธฅเธฐเธ•เธฑเนเธเธเนเธฒ Header
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}
header('Content-Type: application/json');

// 3. เธชเธฃเนเธฒเธเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเน€เธเนเธเธเธณเธ•เธญเธ
$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

// 4. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเน€เธเนเธเธเธฒเธฃเธชเนเธเธเนเธญเธกเธนเธฅเนเธเธ POST เธซเธฃเธทเธญเนเธกเน
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 5. เธฃเธฑเธเธเนเธญเธกเธนเธฅ
    $type_id       = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
    $name          = isset($_POST['name']) ? trim($_POST['name']) : '';
    $serial_number = isset($_POST['serial_number']) ? trim($_POST['serial_number']) : null;
    $description   = isset($_POST['description']) ? trim($_POST['description']) : null;
    
    if (empty($name)) $name = 'Item'; // (เน€เธเธทเนเธญเนเธงเน)
    if (empty($serial_number)) $serial_number = null; // (เธ–เนเธฒเธชเนเธเธเนเธฒเธงเนเธฒเธเธกเธฒ เนเธซเนเน€เธเนเธ NULL)
    if (empty($description)) $description = null;

    // 6. เธ•เธฃเธงเธเธชเธญเธเธเนเธญเธกเธนเธฅ
    if ($type_id == 0) {
        $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน (Type ID)';
        echo json_encode($response);
        exit;
    }

    try {
        // (Transaction)
        $pdo->beginTransaction();

        // 7. INSERT "เธเธดเนเธ" เธญเธธเธเธเธฃเธ“เน (item)
        $sql_item = "INSERT INTO borrow_items (type_id, name, description, serial_number, status) 
                     VALUES (?, ?, ?, ?, 'available')";
        $stmt_item = $pdo->prepare($sql_item);
        $stmt_item->execute([$type_id, $name, $description, $serial_number]);
        $new_item_id = $pdo->lastInsertId();

        // 8. เธญเธฑเธเน€เธ”เธ• "เธเธฃเธฐเน€เธ เธ—" (type) เน€เธเธดเนเธกเธเธณเธเธงเธ +1
        $sql_type = "UPDATE borrow_categories 
                     SET total_quantity = total_quantity + 1, 
                         available_quantity = available_quantity + 1
                     WHERE id = ?";
        $stmt_type = $pdo->prepare($sql_type);
        $stmt_type->execute([$type_id]);

        // 9. เธเธฑเธเธ—เธถเธ Log
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$_SESSION['user_id']}) 
                     เนเธ”เนเน€เธเธดเนเธกเธญเธธเธเธเธฃเธ“เนเธเธดเนเธเนเธซเธกเน (ItemID: {$new_item_id}, Name: {$name}) 
                     เธฅเธเนเธเธเธฃเธฐเน€เธ เธ— (TypeID: {$type_id})";
        log_action($pdo, $_SESSION['user_id'], 'add_item', $log_desc);

        $pdo->commit();
        $response['status'] = 'success';
        $response['message'] = 'เน€เธเธดเนเธกเธญเธธเธเธเธฃเธ“เนเธเธดเนเธเนเธซเธกเนเธชเธณเน€เธฃเนเธ';

    } catch (PDOException $e) {
        $pdo->rollBack();
        
        // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธกเธเธฒเธฃเธ”เธฑเธเธเธฑเธ Error Code โ—€๏ธ
        // (Error 23000 เธเธทเธญ Integrity constraint violation, 1062 เธเธทเธญ Duplicate entry)
        if ($e->getCode() == '23000' || $e->errorInfo[1] == 1062) {
            
            // (เน€เธฃเธฒเธ”เธถเธ $serial_number เธ—เธตเนเธเธนเนเนเธเนเธเธฃเธญเธ เธกเธฒเนเธชเธ”เธเนเธ Error)
            $response['message'] = "เธกเธตเธญเธธเธเธเธฃเธ“เนเธเธดเนเธเธญเธทเนเธเธ—เธตเนเนเธเน Serial Number '" . htmlspecialchars($serial_number) . "' เธเธตเนเนเธเนเธฅเนเธง";
        
        } else {
            // (เธ–เนเธฒเน€เธเนเธ Error เธญเธทเนเธเน)
             $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage();
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

// 10. เธชเนเธเธเธณเธ•เธญเธ
echo json_encode($response);
exit;
?>