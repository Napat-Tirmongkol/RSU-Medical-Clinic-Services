<?php
// process/add_equipment_type_process.php

ob_start();
header('Content-Type: application/json');

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include(__DIR__ . '/../includes/check_session_ajax.php');

try {
    require_once(__DIR__ . '/../../../config/db_connect.php');
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้']);
    exit;
}

require_once(__DIR__ . '/../includes/log_function.php');

$pdo = db();

// 2. ตรวจสอบสิทธิ์ Admin
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}
ob_end_clean();

// 3. สร้างตัวแปรสำหรับเก็บคำตอบ
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

// 4. ตรวจสอบว่าเป็นการส่งข้อมูลแบบ POST หรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 5. รับข้อมูลจากฟอร์ม
    $name          = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description   = isset($_POST['description']) ? trim($_POST['description']) : null;
    
    if (empty($description)) $description = null;

    // 6. ตรวจสอบข้อมูล
    if (empty($name)) {
        $response['message'] = 'กรุณากรอกชื่อประเภทอุปกรณ์';
        echo json_encode($response);
        exit;
    }
    
    try {
        // 7. (ใหม่) ตรวจสอบการอัปโหลดไฟล์
        $image_url_to_db = null;
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] == 0) {
            
            // ◀️ (แก้ไข) Path ที่จะ "ย้ายไฟล์ไปเก็บ" (ต้องถอยหลัง ../) ◀️
            $upload_dir_server_path = '../uploads/equipment_images/'; 
            
            // ◀️ (แก้ไข) Path ที่จะ "บันทึกลง DB" (ไม่ต้องถอยหลัง สัมพันธ์กับ <base href>) ◀️
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
                    $image_url_to_db = $target_file_db; // (ใช้ Path ที่ถูกต้อง)
                } else {
                    throw new Exception("อัปโหลดไฟล์ล้มเหลว (ย้ายไฟล์ไม่สำเร็จ)");
                }
            } else {
                throw new Exception("ไฟล์ที่แนบมาไม่ใช่ไฟล์รูปภาพ");
            }
        }

        // 8. ดำเนินการ INSERT
        $sql = "INSERT INTO borrow_categories (name, description, image_url, total_quantity, available_quantity) 
                VALUES (?, ?, ?, 0, 0)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$name, $description, $image_url_to_db]);
        
        $new_type_id = $pdo->lastInsertId();

        // 9. บันทึก Log
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$_SESSION['user_id']}) 
                     ได้เพิ่มประเภทอุปกรณ์ใหม่ (Type ID: {$new_type_id}, Name: {$name})";
        log_action($pdo, $_SESSION['user_id'], 'add_type', $log_desc);

        $response['status'] = 'success';
        $response['message'] = 'เพิ่มประเภทอุปกรณ์ใหม่สำเร็จ';

    } catch (PDOException $e) {
        if ($e->getCode() == '23000') {
             $response['message'] = 'ชื่อประเภทอุปกรณ์นี้มีในระบบแล้ว';
        } else {
             $response['message'] = 'เกิดข้อผิดพลาด DB: ' . $e->getMessage();
        }
    } catch (Throwable $e) {
         $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'ต้องใช้วิธี POST เท่านั้น';
}

// 10. ส่งคำตอบ (JSON) กลับไปให้ JavaScript
echo json_encode($response);
exit;
?>