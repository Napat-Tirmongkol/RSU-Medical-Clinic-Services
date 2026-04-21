<?php
// process/approve_request_process.php (ฉบับแก้ไข: รองรับ AJAX/JSON และ approver_id)
include('../includes/check_session.php');
require_once(__DIR__ . '/../includes/db_connect.php');
require_once('../includes/log_function.php');

// ตั้งค่าให้ตอบกลับเป็น JSON
header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

// ตรวจสอบว่าเป็น POST และมีค่า transaction_id ส่งมา
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['transaction_id'])) {
    
    $transaction_id = (int)$_POST['transaction_id'];
    $selected_item_id = (int)$_POST['selected_item_id']; // ไอเท็มที่ Admin เลือกจาก Dropdown
    
    // ดึง ID ของคนอนุมัติ
    $admin_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;

    if (empty($selected_item_id)) {
        $response['message'] = "กรุณาเลือกอุปกรณ์ (Serial Number)";
        echo json_encode($response);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. ดึงข้อมูลเดิมจาก Database โดยตรง
        $stmt_chk = $pdo->prepare("SELECT item_id FROM borrow_records WHERE id = ?");
        $stmt_chk->execute([$transaction_id]);
        $current_item_id = $stmt_chk->fetchColumn(); 

        // 2. เปรียบเทียบของที่เลือกใหม่ (Selected) กับของเดิม (Current)
        if ($selected_item_id != $current_item_id) {
            
            // === กรณีมีการเปลี่ยนชิ้นอุปกรณ์ ===
            
            // 2.1 ปล่อยของเดิมให้ว่าง (ถ้ามีค่า)
            if (!empty($current_item_id)) {
                $stmt_release = $pdo->prepare("UPDATE borrow_items SET status = 'available' WHERE id = ?");
                $stmt_release->execute([$current_item_id]);
            }

            // 2.2 เช็คของชิ้นใหม่ว่าว่างจริงไหม
            $stmt_status = $pdo->prepare("SELECT status FROM borrow_items WHERE id = ?");
            $stmt_status->execute([$selected_item_id]);
            $new_item_status = $stmt_status->fetchColumn();

            if ($new_item_status !== 'available') {
                // ถ้าสถานะไม่ใช่ available แสดงว่าถูกคนอื่นแย่งไปแล้ว
                throw new Exception("อุปกรณ์ชิ้นที่เลือก (ID: $selected_item_id) ไม่ว่าง (สถานะ: $new_item_status)");
            }

            // 2.3 จองของชิ้นใหม่
            $stmt_borrow = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
            $stmt_borrow->execute([$selected_item_id]);

        } 
        
        // 3. อัปเดตสถานะคำขอเป็น 'approved' และบันทึกข้อมูลผู้อนุมัติ (approver_id)
        $sql = "UPDATE borrow_records 
                SET approval_status = 'approved', 
                    approver_id = ?,    -- ✅ คอลัมน์ที่เพิ่มในขั้นตอนที่ 1
                    item_id = ?,      
                    equipment_id = ?  
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$admin_id, $selected_item_id, $selected_item_id, $transaction_id]);

        $pdo->commit();
        
        // บันทึก Log 
        if(function_exists('log_action')){
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_id}) ได้อนุมัติคำขอ (TID: {$transaction_id}) สำหรับอุปกรณ์ (Item ID: {$selected_item_id})";
            log_action($pdo, $admin_id, 'approve_request', $log_desc);
        }

        // ✅ ส่ง JSON ตอบกลับเมื่อสำเร็จ
        $response['status'] = 'success';
        $response['message'] = "อนุมัติเรียบร้อยแล้ว (มอบอุปกรณ์ ID: $selected_item_id)";

    } catch (Throwable $e) {
        $pdo->rollBack();
        $response['message'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
} else {
    $response['message'] = "ข้อมูลไม่ครบถ้วน";
}

// 4. ส่งคำตอบ JSON กลับไปเสมอ
echo json_encode($response);
exit();
?>