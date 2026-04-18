<?php
// borrow_process.php
// บันทึกการยืมที่ Admin/Staff เป็นคนกดให้

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('includes/check_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');
require_once('includes/log_function.php'); // ◀️ (เพิ่ม) เรียกใช้ Log

// 2. ตั้งค่า Header
header('Content-Type: application/json');

// 3. สร้างตัวแปรสำหรับเก็บคำตอบ
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

// 4. ตรวจสอบว่าเป็นการส่งข้อมูลแบบ POST หรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 5. รับข้อมูลจากฟอร์ม
    $type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0; 
    $borrower_student_id = isset($_POST['borrower_id']) ? (int)$_POST['borrower_id'] : 0;
    $due_date = isset($_POST['due_date']) ? $_POST['due_date'] : null;

    if ($type_id == 0 || $borrower_student_id == 0 || $due_date == null) {
        $response['message'] = 'ข้อมูลไม่ครบถ้วน (ผู้ยืม หรือ วันที่คืน)';
        echo json_encode($response);
        exit;
    }

    // 6. เริ่ม Transaction (การยืม)
    try {
        $pdo->beginTransaction();

        // 6.1 หา item ที่ว่างจาก type นี้
        $stmt_find_item = $pdo->prepare("SELECT id FROM borrow_items WHERE type_id = ? AND status = 'available' LIMIT 1 FOR UPDATE");
        $stmt_find_item->execute([$type_id]);
        $available_item_id = $stmt_find_item->fetchColumn();

        if (!$available_item_id) {
            throw new Exception("ไม่สามารถยืมได้ อุปกรณ์ประเภทนี้ไม่ว่างแล้ว");
        }

        // 6.2 UPDATE อุปกรณ์ (item) เป็น 'borrowed'
        $stmt_item = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
        $stmt_item->execute([$available_item_id]);

        // 6.3 UPDATE จำนวนในประเภท (type)
        $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity - 1 WHERE id = ? AND available_quantity > 0");
        $stmt_type->execute([$type_id]);

        if ($stmt_type->rowCount() == 0) {
            throw new Exception("จำนวนอุปกรณ์ในคลังไม่เพียงพอ กรุณาตรวจสอบสต็อก");
        }

        // 6.4 INSERT ประวัติการยืม
        $sql_insert = "INSERT INTO borrow_records 
                        (equipment_id, equipment_type_id, borrower_student_id, due_date, status, approval_status, quantity, lending_staff_id) 
                       VALUES 
                        (?, ?, ?, ?, 'borrowed', 'staff_added', 1, ?)";
        $stmt_insert = $pdo->prepare($sql_insert);
        
        $admin_user_id = $_SESSION['user_id'] ?? null; // ◀️ (ดึง ID Admin ที่กดยืม)
        
        $stmt_insert->execute([$available_item_id, $type_id, $borrower_student_id, $due_date, $admin_user_id]);

        // ◀️ --- (เพิ่มส่วน Log) --- ◀️
        if ($stmt_insert->rowCount() > 0) {
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) ได้บันทึกการยืม (Type ID: {$type_id}, Item ID: {$available_item_id}) ให้กับผู้ใช้ (SID: {$borrower_student_id})";
            log_action($pdo, $admin_user_id, 'create_borrow_staff', $log_desc);
        }
        // ◀️ --- (จบส่วน Log) --- ◀️

        $pdo->commit();

        // 7. ถ้าสำเร็จ
        $response['status'] = 'success';
        $response['message'] = 'บันทึกการยืมสำเร็จ';

    } catch (Throwable $e) {
        $pdo->rollBack(); 
        $response['message'] = 'เกิดข้อผิดพลาด: ' . $e->getMessage(); // ◀️ (แก้ไข)
    }

} else {
    $response['message'] = 'ต้องใช้วิธี POST เท่านั้น';
}

// 8. ส่งคำตอบ (JSON) กลับไปให้ JavaScript
echo json_encode($response);
exit;
?>