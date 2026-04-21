<?php
// edit_item_process.php
// (ไฟล์ใหม่สำหรับบันทึกการแก้ไข Item)

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}
header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $serial_number = isset($_POST['serial_number']) ? trim($_POST['serial_number']) : null;
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';

    if ($item_id == 0 || empty($name) || empty($new_status)) {
        $response['message'] = 'ข้อมูลไม่ครบถ้วน (ID, Name หรือ Status)';
        echo json_encode($response);
        exit;
    }
    if (!in_array($new_status, ['available', 'maintenance'])) {
         $response['message'] = 'สถานะที่ส่งมาไม่ถูกต้อง';
         echo json_encode($response);
         exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. ดึงข้อมูลสถานะเก่า และ Type ID
        $stmt_get = $pdo->prepare("SELECT status, type_id, serial_number FROM borrow_items WHERE id = ? FOR UPDATE");
        $stmt_get->execute([$item_id]);
        $current_item = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if (!$current_item) {
            throw new Exception("ไม่พบอุปกรณ์ชิ้นนี้");
        }
        
        $old_status = $current_item['status'];
        $type_id = $current_item['type_id'];
        $old_serial = $current_item['serial_number'];

        if ($old_status == 'borrowed') {
            throw new Exception("ไม่สามารถแก้ไขอุปกรณ์ที่กำลังถูกยืมได้");
        }

        // 2. เช็ค Serial Number ซ้ำ (ถ้ามีการกรอก และมีการเปลี่ยนแปลง)
        if (!empty($serial_number) && $serial_number != $old_serial) {
            $stmt_check = $pdo->prepare("SELECT id FROM borrow_items WHERE serial_number = ? AND id != ?");
            $stmt_check->execute([$serial_number, $item_id]);
            if ($stmt_check->fetch()) {
                throw new Exception("เลขซีเรียล '$serial_number' นี้มีในระบบแล้ว");
            }
        }

        // 3. อัปเดต Item
        $sql_item = "UPDATE borrow_items SET name = ?, serial_number = ?, description = ?, status = ? WHERE id = ?";
        $stmt_item = $pdo->prepare($sql_item);
        $stmt_item->execute([$name, $serial_number, $description, $new_status, $item_id]);

        // 4. อัปเดตจำนวนใน Type (ถ้าสถานะเปลี่ยน)
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
        $response['message'] = 'บันทึกการเปลี่ยนแปลงสำเร็จ';

    } catch (Throwable $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }
}

echo json_encode($response);
exit;
?>