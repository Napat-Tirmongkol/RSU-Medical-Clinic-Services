<?php
// process/delete_equipment_type_process.php
// (ไฟล์ใหม่)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');
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
    $type_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($type_id == 0) {
        $response['message'] = 'ไม่ได้ระบุ ID ประเภทอุปกรณ์';
        echo json_encode($response);
        exit;
    }

    try {
        // (Transaction)
        $pdo->beginTransaction();

        // 6. (สำคัญ) ตรวจสอบว่ามี "ชิ้น" อุปกรณ์ (items)
        //    ผูกอยู่กับ "ประเภท" (type) นี้หรือไม่
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM borrow_items WHERE type_id = ?");
        $stmt_check->execute([$type_id]);
        $item_count = $stmt_check->fetchColumn();

        if ($item_count > 0) {
            // (ถ้ามีของผูกอยู่ ห้ามลบ)
            throw new Exception("ไม่สามารถลบได้: ยังมีอุปกรณ์รายชิ้น ($item_count ชิ้น) อยู่ในประเภทนี้");
        }
        
        // 7. (ดึงข้อมูลเก่ามาเก็บไว้ Log + ลบรูป)
        $stmt_get = $pdo->prepare("SELECT name, image_url FROM borrow_categories WHERE id = ?");
        $stmt_get->execute([$type_id]);
        $old_data = $stmt_get->fetch(PDO::FETCH_ASSOC);
        $old_name = $old_data['name'] ?? 'N/A';
        $old_image_url = $old_data['image_url'] ?? null;


        // 8. ดำเนินการ DELETE
        $stmt_delete = $pdo->prepare("DELETE FROM borrow_categories WHERE id = ?");
        $stmt_delete->execute([$type_id]);

        if ($stmt_delete->rowCount() > 0) {
            
            // ◀️ (แก้ไข) เพิ่ม ../ สำหรับ Path ลบไฟล์ ◀️
            // (ถ้าลบสำเร็จ ให้พยายามลบรูปเก่าด้วย)
            if (!empty($old_image_url)) {
                $file_to_delete = '../' . $old_image_url;
                if (file_exists($file_to_delete)) {
                    @unlink($file_to_delete);
                }
            }

            // 9. บันทึก Log
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$_SESSION['user_id']}) 
                         ได้ลบประเภทอุปกรณ์ (Type ID: {$type_id}, Name: {$old_name})";
            log_action($pdo, $_SESSION['user_id'], 'delete_type', $log_desc);

            $pdo->commit();
            $response['status'] = 'success';
            $response['message'] = 'ลบประเภทอุปกรณ์สำเร็จ';
        } else {
            throw new Exception("ไม่พบประเภทอุปกรณ์ที่ต้องการลบ (ID: $type_id)");
        }

    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() == '23000') {
             $response['message'] = 'เกิดข้อผิดพลาด FK Constraint (อาจมีข้อมูลอื่นผูกอยู่)';
        } else {
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