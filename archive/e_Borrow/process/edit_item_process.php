<?php
// edit_item_process.php
// (เนเธเธฅเนเนเธซเธกเนเธชเธณเธซเธฃเธฑเธเธเธฑเธเธ—เธถเธเธเธฒเธฃเนเธเนเนเธ Item)

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'เธเธธเธ“เนเธกเนเธกเธตเธชเธดเธ—เธเธดเนเธ”เธณเน€เธเธดเธเธเธฒเธฃ']);
    exit;
}
header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธกเนเธ—เธฃเธฒเธเธชเธฒเน€เธซเธ•เธธ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $serial_number = isset($_POST['serial_number']) ? trim($_POST['serial_number']) : null;
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';

    if ($item_id == 0 || empty($name) || empty($new_status)) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเนเธกเนเธเธฃเธเธ–เนเธงเธ (ID, Name เธซเธฃเธทเธญ Status)';
        echo json_encode($response);
        exit;
    }
    if (!in_array($new_status, ['available', 'maintenance'])) {
         $response['message'] = 'เธชเธ–เธฒเธเธฐเธ—เธตเนเธชเนเธเธกเธฒเนเธกเนเธ–เธนเธเธ•เนเธญเธ';
         echo json_encode($response);
         exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธ–เธฒเธเธฐเน€เธเนเธฒ เนเธฅเธฐ Type ID
        $stmt_get = $pdo->prepare("SELECT status, type_id, serial_number FROM borrow_items WHERE id = ? FOR UPDATE");
        $stmt_get->execute([$item_id]);
        $current_item = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if (!$current_item) {
            throw new Exception("เนเธกเนเธเธเธญเธธเธเธเธฃเธ“เนเธเธดเนเธเธเธตเน");
        }
        
        $old_status = $current_item['status'];
        $type_id = $current_item['type_id'];
        $old_serial = $current_item['serial_number'];

        if ($old_status == 'borrowed') {
            throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เนเธเนเนเธเธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธเธณเธฅเธฑเธเธ–เธนเธเธขเธทเธกเนเธ”เน");
        }

        // 2. เน€เธเนเธ Serial Number เธเนเธณ (เธ–เนเธฒเธกเธตเธเธฒเธฃเธเธฃเธญเธ เนเธฅเธฐเธกเธตเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเนเธเธฅเธ)
        if (!empty($serial_number) && $serial_number != $old_serial) {
            $stmt_check = $pdo->prepare("SELECT id FROM borrow_items WHERE serial_number = ? AND id != ?");
            $stmt_check->execute([$serial_number, $item_id]);
            if ($stmt_check->fetch()) {
                throw new Exception("เน€เธฅเธเธเธตเน€เธฃเธตเธขเธฅ '$serial_number' เธเธตเนเธกเธตเนเธเธฃเธฐเธเธเนเธฅเนเธง");
            }
        }

        // 3. เธญเธฑเธเน€เธ”เธ• Item
        $sql_item = "UPDATE borrow_items SET name = ?, serial_number = ?, description = ?, status = ? WHERE id = ?";
        $stmt_item = $pdo->prepare($sql_item);
        $stmt_item->execute([$name, $serial_number, $description, $new_status, $item_id]);

        // 4. เธญเธฑเธเน€เธ”เธ•เธเธณเธเธงเธเนเธ Type (เธ–เนเธฒเธชเธ–เธฒเธเธฐเน€เธเธฅเธตเนเธขเธ)
        if ($old_status == 'available' && $new_status == 'maintenance') {
            $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity - 1 WHERE id = ?");
            $stmt_type->execute([$type_id]);
        }
        elseif ($old_status == 'maintenance' && $new_status == 'available') {
             $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity + 1 WHERE id = ?");
             $stmt_type->execute([$type_id]);
        }

        $pdo->commit();
        $response['status'] = 'success';
        $response['message'] = 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเนเธเธฅเธเธชเธณเน€เธฃเนเธ';

    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }
}

echo json_encode($response);
exit;
?>