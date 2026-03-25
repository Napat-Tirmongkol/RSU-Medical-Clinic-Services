<?php
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
    $type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0; // (เน€เธฃเธฒเธชเนเธ type_id เธกเธฒเธ”เนเธงเธข)

    if ($item_id == 0 || $type_id == 0) {
        $response['message'] = 'ID เธญเธธเธเธเธฃเธ“เน เธซเธฃเธทเธญ ID เธเธฃเธฐเน€เธ เธ— เนเธกเนเธ–เธนเธเธ•เนเธญเธ';
        echo json_encode($response);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. เธ”เธถเธเธเนเธญเธกเธนเธฅเธชเธ–เธฒเธเธฐเธเธญเธ Item เธ—เธตเนเธเธฐเธฅเธ
        $stmt_get = $pdo->prepare("SELECT status FROM borrow_items WHERE id = ? AND type_id = ?");
        $stmt_get->execute([$item_id, $type_id]);
        $item = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception("เนเธกเนเธเธเธญเธธเธเธเธฃเธ“เนเธเธดเนเธเธเธตเน");
        }

        if ($item['status'] == 'borrowed') {
            throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธเธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธเธณเธฅเธฑเธเธ–เธนเธเธขเธทเธกเนเธ”เน");
        }

        // 2. เธ”เธณเน€เธเธดเธเธเธฒเธฃเธฅเธ
        $stmt_delete = $pdo->prepare("DELETE FROM borrow_items WHERE id = ?");
        $stmt_delete->execute([$item_id]);

        if ($stmt_delete->rowCount() > 0) {
            
            // 3. เธญเธฑเธเน€เธ”เธ•เธเธณเธเธงเธเนเธ borrow_categories
            // (เธ–เนเธฒเธฅเธเธเธญเธเธ—เธตเน 'available' เนเธซเนเธฅเธ”เธ—เธฑเนเธ total เนเธฅเธฐ available)
            if ($item['status'] == 'available') {
                $sql_update_type = "UPDATE borrow_categories SET total_quantity = total_quantity - 1, available_quantity = available_quantity - 1 WHERE id = ?";
            } 
            // (เธ–เนเธฒเธฅเธเธเธญเธเธ—เธตเน 'maintenance' เนเธซเนเธฅเธ”เนเธเน total)
            else { 
                $sql_update_type = "UPDATE borrow_categories SET total_quantity = total_quantity - 1 WHERE id = ?";
            }
            $stmt_update = $pdo->prepare($sql_update_type);
            $stmt_update->execute([$type_id]);

            // 4. เธเธฑเธเธ—เธถเธ Log
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' เนเธ”เนเธฅเธเธญเธธเธเธเธฃเธ“เน (ItemID: {$item_id}) เธญเธญเธเธเธฒเธเธเธฃเธฐเน€เธ เธ— (TypeID: {$type_id})";
            log_action($pdo, $admin_user_id, 'delete_equipment_item', $log_desc);

            $pdo->commit();
            $response['status'] = 'success';
            $response['message'] = 'เธฅเธเธญเธธเธเธเธฃเธ“เนเธชเธณเน€เธฃเนเธ';
            
        } else {
            throw new Exception("เธฅเธเธเนเธญเธกเธนเธฅเนเธกเนเธชเธณเน€เธฃเนเธ (rowCount = 0)");
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }
}

echo json_encode($response);
exit;
?>