<?php
// [แก้ไข: process/toggle_staff_status.php]
// ปรับชื่อตัวแปรให้ตรงกับ admin_app.js (user_id, new_status)

// 1. ตั้งค่า Error Reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once '../includes/db_connect.php';

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ'];

try {
    session_start();

    // 2. ตรวจสอบสิทธิ์ Admin
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('คุณไม่มีสิทธิ์ดำเนินการนี้ (Access Denied)');
    }

    // 3. รับค่าจาก AJAX (แก้ไขให้รองรับชื่อตัวแปรจาก admin_app.js)
    // JS ส่ง: formData.append('user_id', userId);
    // JS ส่ง: formData.append('new_status', newStatus);
    
    $target_id = $_POST['user_id'] ?? $_POST['id'] ?? null; 
    $target_status = $_POST['new_status'] ?? $_POST['status'] ?? null;

    // 4. Debug: ถ้าไม่เจอ ID ให้แจ้งกลับไปว่าได้รับอะไรมาบ้าง
    if (!$target_id) {
        $received_data = json_encode($_POST); // แปลงข้อมูลที่ได้รับเป็นข้อความ
        throw new Exception("ไม่พบข้อมูล ID ผู้ใช้งาน (Server ได้รับ: $received_data)");
    }

    // 5. ป้องกันการระงับตัวเอง
    if ($target_id == $_SESSION['user_id']) {
        throw new Exception('ไม่สามารถระงับบัญชีของตัวเองได้');
    }

    // 6. กำหนดค่าสถานะที่จะบันทึก (active/disabled)
    // admin_app.js ส่งค่ามาเป็น 'active' หรือ 'disabled' ตรงๆ อยู่แล้ว
    $final_status = ($target_status === 'disabled') ? 'disabled' : 'active';

    // 7. อัปเดตข้อมูล (ใช้ sys_staff และ account_status)
    $sql = "UPDATE sys_staff SET account_status = :status WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':status' => $final_status,
        ':id' => $target_id
    ]);

    if ($result) {
        $response = [
            'status' => 'success',
            'message' => 'อัปเดตสถานะเป็น ' . ($final_status == 'active' ? 'ปกติ (Active)' : 'ระงับ (Disabled)') . ' เรียบร้อยแล้ว',
            'new_status' => $final_status
        ];
    } else {
        throw new Exception('เกิดข้อผิดพลาดในการอัปเดตฐานข้อมูล');
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>
