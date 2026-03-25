<?php
// [แก้ไขไฟล์: process/request_borrow_process.php]

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
require_once('../includes/check_student_session_ajax.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 2. รับข้อมูลจากฟอร์ม
    $type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
    $student_id = $_SESSION['student_id']; 
    $reason = isset($_POST['reason_for_borrowing']) ? trim($_POST['reason_for_borrowing']) : '';
    $staff_id = isset($_POST['lending_staff_id']) ? (int)$_POST['lending_staff_id'] : 0;
    $due_date = isset($_POST['due_date']) ? $_POST['due_date'] : null;

    if ($type_id == 0 || $staff_id == 0 || empty($reason) || $due_date == null) {
        $response['message'] = 'ข้อมูลที่ส่งมาไม่ครบถ้วน (เหตุผล, ผู้ดูแล, หรือวันที่คืน)';
        echo json_encode($response);
        exit;
    }

    // ✅ (3) ส่วนจัดการไฟล์อัปโหลด (แก้ไขให้รองรับรูปภาพ + แก้การส่งค่ากลับแบบ JSON)
    $attachment_url = NULL; // กำหนดค่าเริ่มต้นเป็น NULL

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        
        $file_tmp = $_FILES['attachment']['tmp_name'];
        $file_name = $_FILES['attachment']['name'];
        $file_size = $_FILES['attachment']['size'];
        
        // 1. [แก้ไข] เพิ่มนามสกุลรูปภาพ (jpg, png)
        $allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png'];
        
        // 2. [แก้ไข] เพิ่ม MIME Types ของรูปภาพ
        $allowed_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'image/jpeg', // เพิ่ม
            'image/png'   // เพิ่ม
        ];

        // แยกนามสกุลไฟล์
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // ตรวจสอบ 1: นามสกุล
        if (!in_array($file_ext, $allowed_extensions)) {
            echo json_encode(['status' => 'error', 'message' => 'อนุญาตเฉพาะไฟล์เอกสาร (PDF, Word) และรูปภาพ (JPG, PNG) เท่านั้น']);
            exit;
        }

        // ตรวจสอบ 2: MIME Type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_mimes)) {
            echo json_encode(['status' => 'error', 'message' => 'ไฟล์ไม่ถูกต้อง หรืออาจเป็นไฟล์อันตราย']);
            exit;
        }

        // ตรวจสอบ 3: ขนาดไฟล์ (5MB)
        if ($file_size > 5 * 1024 * 1024) {
            echo json_encode(['status' => 'error', 'message' => 'ไฟล์มีขนาดใหญ่เกินไป (ห้ามเกิน 5MB)']);
            exit;
        }

        // 3. ตั้งชื่อไฟล์ใหม่
        $new_filename = "req-" . uniqid() . "." . $file_ext;
        $upload_dir = '../uploads/attachments/';
        
        // สร้างโฟลเดอร์ถ้ายังไม่มี
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $destination = $upload_dir . $new_filename;

        if (move_uploaded_file($file_tmp, $destination)) {
            // เก็บ Path ลงตัวแปร $attachment_url (ตัวแปรนี้แหละที่ต้องเอาไปใช้)
            $attachment_url = 'uploads/attachments/' . $new_filename;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (move_uploaded_file failed)']);
            exit;
        }
    }

    // 4. เริ่ม Transaction
    try {
        $pdo->beginTransaction();

        // 4.1 ค้นหา "ชิ้น" อุปกรณ์
        $stmt_find = $pdo->prepare("SELECT id FROM borrow_items WHERE type_id = ? AND status = 'available' LIMIT 1 FOR UPDATE");
        $stmt_find->execute([$type_id]);
        $item_id = $stmt_find->fetchColumn();

        if (!$item_id) {
            throw new Exception("อุปกรณ์ประเภทนี้ถูกยืมไปหมดแล้วในขณะนี้");
        }

        // 4.2 "จอง" อุปกรณ์
        $stmt_item = $pdo->prepare("UPDATE borrow_items SET status = 'borrowed' WHERE id = ?");
        $stmt_item->execute([$item_id]);

        // 4.3 "ลด" จำนวนของว่าง
        $stmt_type = $pdo->prepare("UPDATE borrow_categories SET available_quantity = available_quantity - 1 WHERE id = ? AND available_quantity > 0");
        $stmt_type->execute([$type_id]);
        
        if ($stmt_item->rowCount() == 0 || $stmt_type->rowCount() == 0) {
             throw new Exception("ไม่สามารถอัปเดตสต็อกอุปกรณ์ได้");
        }

        // 4.4 "สร้าง" คำขอยืม
        $sql_trans = "INSERT INTO borrow_records 
                        (type_id, item_id, equipment_id, borrower_student_id, reason_for_borrowing, 
                         attachment_url, 
                         lending_staff_id, due_date, 
                         status, approval_status, quantity) 
                      VALUES 
                        (?, ?, ?, ?, ?, 
                         ?, 
                         ?, ?, 
                         'borrowed', 'pending', 1)";
        
        $stmt_trans = $pdo->prepare($sql_trans);
        
        // ✅ [แก้ไขจุดสำคัญ]: เปลี่ยนจาก $attachment_url_to_db เป็น $attachment_url
        $stmt_trans->execute([
            $type_id, $item_id, $item_id, $student_id, $reason, 
            $attachment_url, // <-- แก้ตรงนี้ (เดิมใช้ตัวแปรผิด)
            $staff_id, $due_date
        ]);

        $pdo->commit();

        $response['status'] = 'success';
        $response['message'] = 'ส่งคำขอยืมสำเร็จ! กรุณารอ Admin อนุมัติ';

    } catch (Exception $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'ต้องใช้วิธี POST เท่านั้น';
}

// ส่งคำตอบ
echo json_encode($response);
exit;
?>