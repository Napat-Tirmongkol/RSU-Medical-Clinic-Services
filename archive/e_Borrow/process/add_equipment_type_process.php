<?php
// process/add_equipment_type_process.php
// (เธเธเธฑเธเนเธเนเนเธ Path เธญเธฑเธเนเธซเธฅเธ”)

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

    // 5. เธฃเธฑเธเธเนเธญเธกเธนเธฅเธเธฒเธเธเธญเธฃเนเธก
    $name          = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description   = isset($_POST['description']) ? trim($_POST['description']) : null;
    
    if (empty($description)) $description = null;

    // 6. เธ•เธฃเธงเธเธชเธญเธเธเนเธญเธกเธนเธฅ
    if (empty($name)) {
        $response['message'] = 'เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเธทเนเธญเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน';
        echo json_encode($response);
        exit;
    }
    
    try {
        // 7. (เนเธซเธกเน) เธ•เธฃเธงเธเธชเธญเธเธเธฒเธฃเธญเธฑเธเนเธซเธฅเธ”เนเธเธฅเน
        $image_url_to_db = null;
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] == 0) {
            
            // โ—€๏ธ (เนเธเนเนเธ) Path เธ—เธตเนเธเธฐ "เธขเนเธฒเธขเนเธเธฅเนเนเธเน€เธเนเธ" (เธ•เนเธญเธเธ–เธญเธขเธซเธฅเธฑเธ ../) โ—€๏ธ
            $upload_dir_server_path = '../uploads/equipment_images/'; 
            
            // โ—€๏ธ (เนเธเนเนเธ) Path เธ—เธตเนเธเธฐ "เธเธฑเธเธ—เธถเธเธฅเธ DB" (เนเธกเนเธ•เนเธญเธเธ–เธญเธขเธซเธฅเธฑเธ เธชเธฑเธกเธเธฑเธเธเนเธเธฑเธ <base href>) โ—€๏ธ
            $upload_dir_db_path = 'uploads/equipment_images/';

            if (!is_dir($upload_dir_server_path)) {
                mkdir($upload_dir_server_path, 0755, true);
            }

            $file_extension = pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid('equip-', true) . '.' . strtolower($file_extension);

            $target_file_server = $upload_dir_server_path . $new_filename;
            $target_file_db = $upload_dir_db_path . $new_filename;

            $check = getimagesize($_FILES['image_file']['tmp_name']);
            if ($check !== false) {
                if (move_uploaded_file($_FILES['image_file']['tmp_name'], $target_file_server)) {
                    $image_url_to_db = $target_file_db; // (เนเธเน Path เธ—เธตเนเธ–เธนเธเธ•เนเธญเธ)
                } else {
                    throw new Exception("เธญเธฑเธเนเธซเธฅเธ”เนเธเธฅเนเธฅเนเธกเน€เธซเธฅเธง (เธขเนเธฒเธขเนเธเธฅเนเนเธกเนเธชเธณเน€เธฃเนเธ)");
                }
            } else {
                throw new Exception("เนเธเธฅเนเธ—เธตเนเนเธเธเธกเธฒเนเธกเนเนเธเนเนเธเธฅเนเธฃเธนเธเธ เธฒเธ");
            }
        }

        // 8. เธ”เธณเน€เธเธดเธเธเธฒเธฃ INSERT
        $sql = "INSERT INTO borrow_categories (name, description, image_url, total_quantity, available_quantity) 
                VALUES (?, ?, ?, 0, 0)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $description, $image_url_to_db]);
        
        $new_type_id = $pdo->lastInsertId();

        // 9. เธเธฑเธเธ—เธถเธ Log
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$_SESSION['user_id']}) 
                     เนเธ”เนเน€เธเธดเนเธกเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเนเธซเธกเน (Type ID: {$new_type_id}, Name: {$name})";
        log_action($pdo, $_SESSION['user_id'], 'add_type', $log_desc);

        $response['status'] = 'success';
        $response['message'] = 'เน€เธเธดเนเธกเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเนเธซเธกเนเธชเธณเน€เธฃเนเธ';

    } catch (PDOException $e) {
        if ($e->getCode() == '23000') { 
             $response['message'] = 'เธเธทเนเธญเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเธเธตเนเธกเธตเนเธเธฃเธฐเธเธเนเธฅเนเธง';
        } else {
             $response['message'] = 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” DB: ' . $e->getMessage();
        }
    } catch (Exception $e) {
         $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'เธ•เนเธญเธเนเธเนเธงเธดเธเธต POST เน€เธ—เนเธฒเธเธฑเนเธ';
}

// 10. เธชเนเธเธเธณเธ•เธญเธ (JSON) เธเธฅเธฑเธเนเธเนเธซเน JavaScript
echo json_encode($response);
exit;
?>