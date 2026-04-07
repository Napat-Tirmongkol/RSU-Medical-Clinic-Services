<?php
// [แก้ไข: process/admin_direct_borrow_process.php]
// ใช้ชื่อคอลัมน์ borrower_student_id ตามไฟล์ SQL borrow_records.sql

ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/db_connect.php';
session_start();
require_once '../includes/csrf_validate.php';

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ'];

try {
    // 1. ตรวจสอบ Login
    if (empty($_SESSION['user_id'])) {
        throw new Exception('กรุณาเข้าสู่ระบบใหม่ (Session Expired)');
    }

    // 2. รับค่า
    $borrower_student_id = $_POST['student_id'] ?? null; // รับค่า ID นักศึกษา
    $lending_staff_id = $_POST['lending_staff_id'] ?? $_SESSION['user_id'];
    $due_date = $_POST['due_date'] ?? null;
    $cart_json = $_POST['cart_data'] ?? '[]';
    $cart_data = json_decode($cart_json, true);

    // 3. ตรวจสอบค่าว่าง
    if (empty($borrower_student_id)) throw new Exception('ไม่พบข้อมูลผู้ยืม (Student ID)');
    if (empty($cart_data)) throw new Exception('ไม่พบรายการอุปกรณ์ในตะกร้า');
    if (empty($due_date)) throw new Exception('กรุณาระบุวันที่คืน');

    // ตรวจสอบ Staff ID
    $stmtCheckStaff = $pdo->prepare("SELECT id FROM sys_staff WHERE id = ?");
    $stmtCheckStaff->execute([$lending_staff_id]);
    if ($stmtCheckStaff->rowCount() == 0) {
        $lending_staff_id = $pdo->query("SELECT id FROM sys_staff ORDER BY id ASC LIMIT 1")->fetchColumn();
    }

    // 4. เริ่ม Transaction
    $pdo->beginTransaction();
    $success_count = 0;
    $errors = [];

    // Prepared Statements
    // อัปเดตสถานะของ (ใช้วิธีเช็ค Case-insensitive เผื่อ Available/available)
    $sql_update = "UPDATE borrow_items 
                   SET status = 'borrowed' 
                   WHERE id = :eid AND (status = 'available' OR status = 'Available')";
    
    // ✅ แก้ไขชื่อคอลัมน์ให้ตรงกับ Database (borrow_records.sql)
    // - borrower_student_id: ผู้ยืม
    // - equipment_id: ไอเท็มที่ยืม
    // - item_id: ไอเท็มที่ยืม (ใส่เผื่อไว้ถ้ามี)
    // - type_id: ประเภท (รับเพิ่มจาก cart)
    $sql_insert = "INSERT INTO borrow_records 
                   (borrower_student_id, equipment_id, item_id, type_id, lending_staff_id, borrow_date, due_date, status, quantity) 
                   VALUES (:sid, :eid, :iid, :tid, :lid, NOW(), :due, 'borrowed', 1)";

    $stmt_update = $pdo->prepare($sql_update);
    $stmt_insert = $pdo->prepare($sql_insert);

    foreach ($cart_data as $item) {
        $item_id = $item['item_id'] ?? null;
        $type_id = $item['type_id'] ?? null; // รับ type_id มาด้วย
        
        if (!$item_id) continue;

        // 4.1 ตัดสต็อก
        $stmt_update->execute([':eid' => $item_id]);
        
        if ($stmt_update->rowCount() > 0) {
            // 4.2 บันทึก Transaction
            try {
                $stmt_insert->execute([
                    ':sid' => $borrower_student_id, // ใช้ ID นักศึกษาที่รับมา
                    ':eid' => $item_id,             // equipment_id
                    ':iid' => $item_id,             // item_id (ใส่ค่าเดียวกัน)
                    ':tid' => $type_id,             // type_id (ถ้าไม่มีจะเป็น null)
                    ':lid' => $lending_staff_id,
                    ':due' => $due_date
                ]);
                $success_count++;
            } catch (PDOException $ex) {
                $errors[] = "Item $item_id DB Error: " . $ex->getMessage();
            }
        } else {
            $errors[] = "Item $item_id ไม่ว่าง (หรือไม่มีอยู่จริง)";
        }
    }

    // 5. สรุปผล
    if ($success_count > 0) {
        $pdo->commit();
        $response = [
            'status' => 'success',
            'message' => "บันทึกสำเร็จ $success_count รายการ",
            'count' => $success_count
        ];
    } else {
        $pdo->rollBack();
        $error_msg = "ไม่สามารถบันทึกรายการได้เลย";
        if (!empty($errors)) {
            $error_msg .= "\nสาเหตุ: " . implode(", ", $errors);
        }
        throw new Exception($error_msg);
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $response['message'] = $e->getMessage();
}

ob_end_clean();
echo json_encode($response);
exit;
?>
