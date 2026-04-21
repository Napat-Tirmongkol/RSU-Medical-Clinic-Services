<?php
/**
 * archive/e_Borrow/process/request_borrow_process.php
 * ประมวลผลการส่งคำขอยืมอุปกรณ์จากนักศึกษา
 */
declare(strict_types=1);
@session_start();

// 1. ตรวจสอบการล็อกอิน (Session Check)
require_once __DIR__ . '/../includes/check_student_session_ajax.php';

// 2. เชื่อมต่อฐานข้อมูล (พาธ 3 ชั้นจาก /process/ ถึง root)
require_once __DIR__ . '/../includes/db_connect.php';

// ตั้งค่า Header เป็น JSON และ UTF-8
header('Content-Type: application/json; charset=utf-8');

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการประมวลผล'];

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['status' => 'error', 'message' => 'อนุญาตเฉพาะการส่งข้อมูลแบบ POST เท่านั้น']);
    exit;
}

try {
    // 3. เริ่มต้นการเชื่อมต่อฐานข้อมูล
    $pdo = db();

    // 4. รับและกรองข้อมูลจากฟอร์ม
    $type_id    = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
    $student_id = (int)($_SESSION['student_id'] ?? 0); 
    $reason     = isset($_POST['reason_for_borrowing']) ? trim($_POST['reason_for_borrowing']) : '';
    $staff_id   = isset($_POST['lending_staff_id']) ? (int)$_POST['lending_staff_id'] : 0;
    $due_date   = isset($_POST['due_date']) ? $_POST['due_date'] : null;

    // ตรวจสอบข้อมูลบังคับ
    if ($type_id === 0 || $staff_id === 0 || empty($reason) || empty($due_date)) {
        throw new Exception('ข้อมูลไม่ครบถ้วน (กรุณาระบุเหตุผล, เจ้าหน้าที่ และวันที่คืน)');
    }

    // 5. จัดการไฟล์เอกสารแนบ (ถ้ามี)
    $attachment_url = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/attachments/';
        
        // สร้างโฟลเดอร์ถ้าไม่มี
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_info = pathinfo($_FILES['attachment']['name']);
        $file_ext  = strtolower($file_info['extension']);
        $allowed   = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

        if (!in_array($file_ext, $allowed)) {
            throw new Exception('ประเภทไฟล์แนบไม่ถูกต้อง (อนุญาต: PDF, Word, Image)');
        }

        $new_filename = 'req_' . uniqid() . '_' . time() . '.' . $file_ext;
        $dest_path    = $upload_dir . $new_filename;

        if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest_path)) {
            $attachment_url = 'uploads/attachments/' . $new_filename;
        } else {
            throw new Exception('ไม่สามารถอัปโหลดไฟล์แนบได้');
        }
    }

    // 6. เริ่มกระบวนการบันทึกข้อมูล (Database Transaction)
    $pdo->beginTransaction();

    // 6.1 ค้นหาอุปกรณ์ที่ว่าง (Available) 1 ชิ้นจากหมวดหมู่ที่เลือก
    $stmt_find = $pdo->prepare("SELECT id FROM borrow_items WHERE type_id = ? AND status = 'available' LIMIT 1 FOR UPDATE");
    $stmt_find->execute([$type_id]);
    $item_id = $stmt_find->fetchColumn();

    if (!$item_id) {
        throw new Exception("ขออภัย! อุปกรณ์ประเภทนี้ถูกยืมไปหมดแล้วในขณะนี้");
    }

    // 6.2 อัปเดตสถานะตัวอุปกรณ์ในตาราง borrow_items
    $stmt_update_item = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
    $stmt_update_item->execute([$item_id]);

    // 6.3 ลดจำนวนคงเหลือในตาราง borrow_categories
    $stmt_update_cat = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity - 1 WHERE id = ? AND available_quantity > 0");
    $stmt_update_cat->execute([$type_id]);

    if ($stmt_update_cat->rowCount() === 0) {
        throw new Exception("ไม่สามารถอัปเดตจำนวนอุปกรณ์ได้ (สต็อกอาจจะไม่พอ)");
    }

    // 6.4 บันทึกประวัติการยืมลงตาราง borrow_records
    $sql_insert = "INSERT INTO borrow_records 
                    (type_id, item_id, equipment_id, borrower_student_id, reason_for_borrowing, 
                     attachment_url, lending_staff_id, due_date, 
                     status, approval_status, quantity, borrow_date) 
                   VALUES 
                    (?, ?, ?, ?, ?, 
                     ?, ?, ?, 
                     'borrowed', 'pending', 1, NOW())";
    
    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->execute([
        $type_id,   // หมวดหมู่
        $item_id,   // รหัสอุปกรณ์ (item_id)
        $item_id,   // รหัสเดียวกัน (equipment_id) เพื่อรองรับฟิลด์เก่า
        $student_id, 
        $reason,
        $attachment_url, 
        $staff_id, 
        $due_date
    ]);

    // ยืนยันข้อมูลทั้งหมด
    $pdo->commit();

    // ส่งผลลัพธ์กลับแบบ Success
    echo json_encode([
        'status' => 'success',
        'message' => 'ส่งคำขอยืมสำเร็จ! กรุณารอเจ้าหน้าที่ตรวจสอบและอนุมัติ'
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database Error: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
exit;
?>