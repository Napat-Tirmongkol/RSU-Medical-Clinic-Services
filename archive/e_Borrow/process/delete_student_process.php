<?php
// [แก้ไขไฟล์: napat-tirmongkol/e-borrow/E-Borrow-c4df732f98db10bf52a8e9d7299e212b6f2abd37/process/delete_student_process.php]
// delete_student_process.php
// (อัปเกรต: เพิ่มการบันทึก Log)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
// ✅ (แก้ไข Path) เพิ่ม ../ และเปลี่ยนเป็นยามของ AJAX
include('../includes/check_session_ajax.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php'); 

// Set header to return JSON
header('Content-Type: application/json');

// 2. ตรวจสอบสิทธิ์ Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}

// 3. รับ ID ผู้ใช้งานจาก POST
$student_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($student_id == 0) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่ได้ระบุ ID ผู้ใช้งาน']);
    exit;
}

// 4. ตรวจสอบ Foreign Key
try {
    $sql_check = "SELECT COUNT(*) FROM borrow_records WHERE borrower_student_id = ?";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([$student_id]);
    $transaction_count = $stmt_check->fetchColumn();

    if ($transaction_count > 0) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถลบผู้ใช้งานได้ เนื่องจากมีประวัติการทำรายการค้างอยู่!']);
        exit;
    }

    // ◀️ --- (ใหม่: ส่วน Log) --- ◀️
    // (ดึงข้อมูลผู้ใช้ "ก่อน" ที่จะลบ)
    $stmt_get = $pdo->prepare("SELECT full_name, line_user_id FROM sys_users WHERE id = ?");
    $stmt_get->execute([$student_id]);
    $student_info = $stmt_get->fetch(PDO::FETCH_ASSOC);
    $student_name_for_log = $student_info ? $student_info['full_name'] : "ID: {$student_id}";
    // (แยประเภท Log ระหว่าง User ที่ Admin เพิ่มเอง หรือ User ที่มาจาก LINE)
    $log_action_type = $student_info && $student_info['line_user_id'] ? 'delete_user_line' : 'delete_user_staff';
    // ◀️ --- (จบส่วนดึงข้อมูล Log) --- ◀️

    // 6. ดำเนินการลบ
    $sql_delete = "DELETE FROM sys_users WHERE id = ?";
    $stmt_delete = $pdo->prepare($sql_delete);
    $stmt_delete->execute([$student_id]);

    // 7. ตรวจสอบ
    if ($stmt_delete->rowCount() > 0) {
        
        // ◀️ --- (ใหม่: บันทึก Log) --- ◀️
        $admin_user_id = $_SESSION['user_id'] ?? null;
        $admin_user_name = $_SESSION['full_name'] ?? 'System';
        $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) ได้ลบผู้ใช้งาน: '{$student_name_for_log}' (SID: {$student_id})";
        log_action($pdo, $admin_user_id, $log_action_type, $log_desc);
        // ◀️ --- (จบส่วน Log) --- ◀️

        echo json_encode(['status' => 'success', 'message' => 'ลบผู้ใช้งานสำเร็จ']);
        exit;
    } else {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบผู้ใช้งานหรือไม่สามารถลบได้']);
        exit;
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    exit;
}
?>
