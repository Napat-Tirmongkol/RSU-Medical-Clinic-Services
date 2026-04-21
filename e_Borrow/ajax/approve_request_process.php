<?php
// process/approve_request_process.php
include('../includes/check_session.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');

// ตรวจสอบว่าเป็น POST และมีค่า transaction_id ส่งมา
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['transaction_id'])) {
    
    $transaction_id = $_POST['transaction_id'];
    $selected_item_id = $_POST['selected_item_id']; // ไอเท็มที่ Admin เลือกจาก Dropdown
    
    // ตรวจสอบตัวแปร Session ของ Admin (รองรับทั้ง user_id และ id)
    $admin_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;

    if (empty($selected_item_id)) {
        $_SESSION['error'] = "กรุณาเลือกอุปกรณ์ (Serial Number)";
        header("Location: ../admin/index.php");
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. ดึงข้อมูลเดิมจาก Database (เพื่อความชัวร์ที่สุด ไม่เชื่อค่าจาก Form)
        $stmt_chk = $pdo->prepare("SELECT item_id FROM borrow_records WHERE id = ?");
        $stmt_chk->execute([$transaction_id]);
        $current_item_id = $stmt_chk->fetchColumn(); // นี่คือของที่ระบบจองไว้อยู่

        // 2. เปรียบเทียบของที่เลือก (Selected) กับของเดิม (Current)
        if ($selected_item_id != $current_item_id) {
            
            // [กรณีเปลี่ยนชิ้น]
            
            // 2.1 ปล่อยของเดิมให้ว่าง (ถ้ามี)
            if (!empty($current_item_id)) {
                $stmt_release = $pdo->prepare("UPDATE borrow_items SET status = 'available' WHERE id = ?");
                $stmt_release->execute([$current_item_id]);
            }

            // 2.2 เช็คของใหม่ว่าว่างจริงไหม
            $stmt_status = $pdo->prepare("SELECT status FROM borrow_items WHERE id = ?");
            $stmt_status->execute([$selected_item_id]);
            $new_item_status = $stmt_status->fetchColumn();

            if ($new_item_status !== 'available') {
                throw new Exception("อุปกรณ์ชิ้นที่เลือก (ID: $selected_item_id) ไม่ว่าง (ถูกยืมไปแล้ว)");
            }

            // 2.3 จองของใหม่
            $stmt_borrow = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
            $stmt_borrow->execute([$selected_item_id]);

        } else {
            // [กรณีเลือกชิ้นเดิม]
            // ไม่ต้องทำอะไรกับ table items เพราะสถานะมันเป็น borrowed โดยรายการนี้อยู่แล้วถูกต้อง
        }
        
        // 3. อัปเดตสถานะคำขอเป็น 'approved'
        $sql = "UPDATE borrow_records 
                SET approval_status = 'approved', 
                    approver_id = ?, 
                    item_id = ?,      -- บันทึกชิ้นที่เลือก
                    equipment_id = ?  -- อัปเดต Foreign Key
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        // หมายเหตุ: ใช้ $selected_item_id ใส่ทั้งช่อง item_id และ equipment_id
        $stmt->execute([$admin_id, $selected_item_id, $selected_item_id, $transaction_id]);

        $pdo->commit();
        
        // บันทึก Log
        if(function_exists('writeLog')){
            writeLog($pdo, $admin_id, "Approve request ID: $transaction_id (Selected Item: $selected_item_id)", "approve");
        }

        $_SESSION['success'] = "อนุมัติเรียบร้อยแล้ว (มอบอุปกรณ์ ID: $selected_item_id)";

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "ข้อมูลไม่ครบถ้วน";
}

header("Location: ../admin/index.php");
exit();
?>