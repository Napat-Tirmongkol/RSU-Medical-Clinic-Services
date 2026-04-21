<?php
// process/add_item_process.php
// (อัปเดต: V2 - ดักจับ Error Duplicate Serial Number)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');

// 2. ตรวจสอบสิทธิ์ Admin และตั้งค่า Header
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}
header('Content-Type: application/json');

// 3. สร้างตัวแปรสำหรับเก็บคำตอบ
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

// 4. ตรวจสอบว่าเป็นการส่งข้อมูลแบบ POST หรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 5. รับข้อมูล
    $type_id       = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
    $name          = isset($_POST['name']) ? trim($_POST['name']) : '';
    $serial_number = isset($_POST['serial_number']) ? trim($_POST['serial_number']) : null;
    $description   = isset($_POST['description']) ? trim($_POST['description']) : null;
    
    if (empty($name)) $name = 'Item'; // (เผื่อไว้)
    if (empty($serial_number)) $serial_number = null; // (ถ้าส่งค่าว่างมา ให้เป็น NULL)
    if (empty($description)) $description = null;

    // 6. ตรวจสอบข้อมูล
    if ($type_id == 0) {
        $response['message'] = 'ไม่ได้ระบุ ID ประเภทอุปกรณ์ (Type ID)';
        echo json_encode($response);
        exit;
    }

    try {
        // (Transaction)
        $pdo->beginTransaction();

        // 7. INSERT "ชิ้น" อุปกรณ์ (item)
        $sql_item = "INSERT INTO borrow_items (type_id, name, description, serial_number, status) 
                     VALUES (?, ?, ?, ?, 'available')";
        $stmt_item = $pdo->prepare($sql_item);
        $stmt_item->execute([$type_id, $name, $description, $serial_number]);
        $new_item_id = $pdo->lastInsertId();

        // 8. อัปเดต "ประเภท" (type) เพิ่มจำนวน +1
        $sql_type = "UPDATE borrow_categories 
                     SET total_quantity = total_quantity + 1, 
                         available_quantity = available_quantity + 1
                     WHERE id = ?";
        $stmt_type = $pdo->prepare($sql_type);
        $stmt_type->execute([$type_id]);

        // 9. บันทึก Log
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$_SESSION['user_id']}) 
                     ได้เพิ่มอุปกรณ์ชิ้นใหม่ (ItemID: {$new_item_id}, Name: {$name}) 
                     ลงในประเภท (TypeID: {$type_id})";
        log_action($pdo, $_SESSION['user_id'], 'add_item', $log_desc);

        $pdo->commit();
        $response['status'] = 'success';
        $response['message'] = 'เพิ่มอุปกรณ์ชิ้นใหม่สำเร็จ';

    } catch (PDOException $e) {
        $pdo->rollBack();
        
        // ◀️ (แก้ไข) เพิ่มการดักจับ Error Code ◀️
        // (Error 23000 คือ Integrity constraint violation, 1062 คือ Duplicate entry)
        if ($e->getCode() == '23000' || $e->errorInfo[1] == 1062) {
            
            // (เราดึง $serial_number ที่ผู้ใช้กรอก มาแสดงใน Error)
            $response['message'] = "มีอุปกรณ์ชิ้นอื่นที่ใช้ Serial Number '" . htmlspecialchars($serial_number) . "' นี้ไปแล้ว";
        
        } else {
            // (ถ้าเป็น Error อื่นๆ)
             $response['message'] = 'เกิดข้อผิดพลาด DB: ' . $e->getMessage();
        }
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'ต้องใช้วิธี POST เท่านั้น';
}

// 10. ส่งคำตอบ
echo json_encode($response);
exit;
?>