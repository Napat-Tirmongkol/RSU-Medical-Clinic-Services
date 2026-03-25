<?php
// process/edit_equipment_type_process.php
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
    $type_id       = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
    $name          = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description   = isset($_POST['description']) ? trim($_POST['description']) : null;
    
    if (empty($description)) $description = null;

    // 6. เธ•เธฃเธงเธเธชเธญเธเธเนเธญเธกเธนเธฅ
    if ($type_id == 0 || empty($name)) {
        $response['message'] = 'เธเนเธญเธกเธนเธฅเนเธกเนเธเธฃเธเธ–เนเธงเธ (ID เธซเธฃเธทเธญ Name)';
        echo json_encode($response);
        exit;
    }
    
    try {
        // 7. เธ”เธถเธเธเนเธญเธกเธนเธฅเธฃเธนเธเธ เธฒเธเน€เธ”เธดเธกเธเนเธญเธ
        $stmt_get_old = $pdo->prepare("SELECT image_url FROM borrow_categories WHERE id = ?");
        $stmt_get_old->execute([$type_id]);
        $current_data = $stmt_get_old->fetch(PDO::FETCH_ASSOC);

        if (!$current_data) {
             throw new Exception("เนเธกเนเธเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธ•เนเธญเธเธเธฒเธฃเนเธเนเนเธ (ID: $type_id)");
        }
        
        $image_url_to_db = $current_data['image_url']; // (เนเธเนเธฃเธนเธเน€เธ”เธดเธกเน€เธเนเธเธเนเธฒเน€เธฃเธดเนเธกเธ•เนเธ)

        // 8. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเธกเธตเธเธฒเธฃเธญเธฑเธเนเธซเธฅเธ”เนเธเธฅเนเนเธซเธกเนเธซเธฃเธทเธญเนเธกเน
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
                    
                    // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ เธ•เธญเธเน€เธเนเธเนเธเธฅเนเน€เธเนเธฒ โ—€๏ธ
                    if (!empty($image_url_to_db) && file_exists('../' . $image_url_to_db)) {
                        @unlink('../' . $image_url_to_db);
                    }
                    $image_url_to_db = $target_file_db; // (เนเธเน Path เธ—เธตเนเธ–เธนเธเธ•เนเธญเธ)
                } else {
                    throw new Exception("เธญเธฑเธเนเธซเธฅเธ”เนเธเธฅเนเนเธซเธกเนเธฅเนเธกเน€เธซเธฅเธง (เธขเนเธฒเธขเนเธเธฅเนเนเธกเนเธชเธณเน€เธฃเนเธ)");
                }
            } else {
                 throw new Exception("เนเธเธฅเนเธ—เธตเนเนเธเธเธกเธฒเนเธกเนเนเธเนเนเธเธฅเนเธฃเธนเธเธ เธฒเธ");
            }
        }

        // 9. เธ”เธณเน€เธเธดเธเธเธฒเธฃ UPDATE
        $sql = "UPDATE borrow_categories 
                SET name = ?, description = ?, image_url = ?
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $description, $image_url_to_db, $type_id]);

        $response['status'] = 'success';
        $response['message'] = 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเนเธเธฅเธเธชเธณเน€เธฃเนเธ';

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