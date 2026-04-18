<?php
// demote_staff_process.php
// รับ ID พนักงาน (sys_staff) มาเพื่อลบ

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('includes/check_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');
require_once('includes/log_function.php'); // ◀️ (เพิ่ม) เรียกใช้ Log

// 2. ตรวจสอบสิทธิ์ Admin และตั้งค่า Header
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}
header('Content-Type: application/json');

// 3. สร้างตัวแปรสำหรับเก็บคำตอบ
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

// 4. ตรวจสอบว่าเป็นการส่งข้อมูลแบบ POST หรือไม่
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 5. รับ ID พนักงาน
    $user_id = isset($_POST['user_id_to_demote']) ? (int)$_POST['user_id_to_demote'] : 0;

    if ($user_id == 0) {
        $response['message'] = 'ไม่ได้ระบุ ID พนักงาน';
        echo json_encode($response);
        exit;
    }
    
    if ($user_id == $_SESSION['user_id']) {
         $response['message'] = 'คุณไม่สามารถลดสิทธิ์บัญชีของตัวเองได้';
         echo json_encode($response);
         exit;
    }

    // 6. ตรวจสอบ Foreign Key
    try {
        $sql_check = "SELECT COUNT(*) FROM borrow_records WHERE lending_staff_id = ?";
        $stmt_check = $pdo->prepare($sql_check);
        $stmt_check->execute([$user_id]);
        $transaction_count = $stmt_check->fetchColumn();

        if ($transaction_count > 0) {
             throw new Exception("ไม่สามารถลบ/ลดสิทธิ์ได้ เนื่องจากพนักงานคนนี้มีประวัติการอนุมัติคำขอค้างอยู่ (Foreign Key Constraint)");
        }

        // ◀️ --- (เพิ่มส่วน Log) --- ◀️
        // (ดึงข้อมูลพนักงาน "ก่อน" ที่จะลบ)
        $stmt_get = $pdo->prepare("SELECT username, full_name FROM sys_staff WHERE id = ?");
        $stmt_get->execute([$user_id]);
        $staff_info = $stmt_get->fetch(PDO::FETCH_ASSOC);
        $staff_name_for_log = $staff_info ? "{$staff_info['full_name']} (Username: {$staff_info['username']})" : "ID: {$user_id}";
        // ◀️ --- (จบส่วนดึงข้อมูล Log) --- ◀️

        // 8. ถ้าไม่มีประวัติ -> ดำเนินการลบจาก sys_staff
        $sql_delete = "DELETE FROM sys_staff WHERE id = ?";
        $stmt_delete = $pdo->prepare($sql_delete);
        $stmt_delete->execute([$user_id]);

        if ($stmt_delete->rowCount() > 0) {
            
            // ◀️ --- (เพิ่มส่วน Log) --- ◀️
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$admin_user_id}) ได้ลบ/ลดสิทธิ์บัญชีพนักงาน: '{$staff_name_for_log}'";
            log_action($pdo, $admin_user_id, 'delete_staff', $log_desc);
            // ◀️ --- (จบส่วน Log) --- ◀️

            $response['status'] = 'success';
            $response['message'] = 'ลดสิทธิ์/ลบบัญชีพนักงานกลับเป็นผู้ใช้งานสำเร็จ';
        } else {
            throw new Exception("ไม่พบพนักงานคนนี้ในระบบ (อาจถูกลบไปแล้ว)");
        }

    } catch (Throwable $e) {
        $response['message'] = $e->getMessage(); // ◀️ (แก้ไข)
    }

} else {
    $response['message'] = 'ต้องใช้วิธี POST เท่านั้น';
}

// 9. ส่งคำตอบ (JSON) กลับไปให้ JavaScript
echo json_encode($response);
exit;
?>
