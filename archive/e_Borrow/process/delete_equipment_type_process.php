<?php
// process/delete_equipment_type_process.php
// (เนเธเธฅเนเนเธซเธกเน)

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
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
    $type_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($type_id == 0) {
        $response['message'] = 'เนเธกเนเนเธ”เนเธฃเธฐเธเธธ ID เธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน';
        echo json_encode($response);
        exit;
    }

    try {
        // (Transaction)
        $pdo->beginTransaction();

        // 6. (เธชเธณเธเธฑเธ) เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเธกเธต "เธเธดเนเธ" เธญเธธเธเธเธฃเธ“เน (items)
        //    เธเธนเธเธญเธขเธนเนเธเธฑเธ "เธเธฃเธฐเน€เธ เธ—" (type) เธเธตเนเธซเธฃเธทเธญเนเธกเน
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM borrow_items WHERE type_id = ?");
        $stmt_check->execute([$type_id]);
        $item_count = $stmt_check->fetchColumn();

        if ($item_count > 0) {
            // (เธ–เนเธฒเธกเธตเธเธญเธเธเธนเธเธญเธขเธนเน เธซเนเธฒเธกเธฅเธ)
            throw new Exception("เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เธฅเธเนเธ”เน: เธขเธฑเธเธกเธตเธญเธธเธเธเธฃเธ“เนเธฃเธฒเธขเธเธดเนเธ ($item_count เธเธดเนเธ) เธญเธขเธนเนเนเธเธเธฃเธฐเน€เธ เธ—เธเธตเน");
        }
        
        // 7. (เธ”เธถเธเธเนเธญเธกเธนเธฅเน€เธเนเธฒเธกเธฒเน€เธเนเธเนเธงเน Log + เธฅเธเธฃเธนเธ)
        $stmt_get = $pdo->prepare("SELECT name, image_url FROM borrow_categories WHERE id = ?");
        $stmt_get->execute([$type_id]);
        $old_data = $stmt_get->fetch(PDO::FETCH_ASSOC);
        $old_name = $old_data['name'] ?? 'N/A';
        $old_image_url = $old_data['image_url'] ?? null;


        // 8. เธ”เธณเน€เธเธดเธเธเธฒเธฃ DELETE
        $stmt_delete = $pdo->prepare("DELETE FROM borrow_categories WHERE id = ?");
        $stmt_delete->execute([$type_id]);

        if ($stmt_delete->rowCount() > 0) {
            
            // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ เธชเธณเธซเธฃเธฑเธ Path เธฅเธเนเธเธฅเน โ—€๏ธ
            // (เธ–เนเธฒเธฅเธเธชเธณเน€เธฃเนเธ เนเธซเนเธเธขเธฒเธขเธฒเธกเธฅเธเธฃเธนเธเน€เธเนเธฒเธ”เนเธงเธข)
            if (!empty($old_image_url)) {
                $file_to_delete = '../' . $old_image_url;
                if (file_exists($file_to_delete)) {
                    @unlink($file_to_delete);
                }
            }

            // 9. เธเธฑเธเธ—เธถเธ Log
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$_SESSION['user_id']}) 
                         เนเธ”เนเธฅเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน (Type ID: {$type_id}, Name: {$old_name})";
            log_action($pdo, $_SESSION['user_id'], 'delete_type', $log_desc);

            $pdo->commit();
            $response['status'] = 'success';
            $response['message'] = 'เธฅเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเธชเธณเน€เธฃเนเธ';
        } else {
            throw new Exception("เนเธกเนเธเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธ•เนเธญเธเธเธฒเธฃเธฅเธ (ID: $type_id)");
        }

    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == '23000') {
             $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” FK Constraint (เธญเธฒเธเธกเธตเธเนเธญเธกเธนเธฅเธญเธทเนเธเธเธนเธเธญเธขเธนเน)';
        } else {
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