<?php
// [แก้ไข: process/delete_staff_process.php]
// แก้ไขให้รับค่า user_id_to_delete และใช้ตาราง sys_staff

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

    // 3. รับค่าจาก AJAX (ชื่อตัวแปรต้องตรงกับ admin_app.js: formData.append('user_id_to_delete', ...))
    $target_id = $_POST['user_id_to_delete'] ?? null;

    if (!$target_id) {
        throw new Exception('ไม่พบข้อมูล ID ผู้ใช้งานที่จะลบ');
    }

    // 4. ป้องกันการลบตัวเอง
    if ($target_id == $_SESSION['user_id']) {
        throw new Exception('ไม่สามารถลบบัญชีของตัวเองได้');
    }

    // 5. ตรวจสอบว่ามีรายการค้างอยู่หรือไม่ (Optional Check)
    // เช็คว่าเคยอนุมัติรายการ (approver_id) หรือรับของคืน (return_staff_id) หรือไม่
    // ถ้าซีเรียสเรื่อง Data Integrity ควรใช้การ "ระงับ" แทนการ "ลบ" 
    // แต่ถ้าต้องการลบจริงๆ SQL จะทำงานตาม Constraint (เช่น ON DELETE SET NULL หรือ RESTRICT)
    
    // ลองลบข้อมูล
    $sql = "DELETE FROM sys_staff WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    
    // ใช้ Try-Catch เฉพาะจุด Execute เพื่อดักจับ Error จาก Foreign Key (เช่น ติด Constraint)
    try {
        $result = $stmt->execute([':id' => $target_id]);
        
        if ($result) {
            $response = [
                'status' => 'success', 
                'message' => 'ลบบัญชีพนักงานเรียบร้อยแล้ว'
            ];
        } else {
            throw new Exception('ไม่สามารถลบข้อมูลได้ (Execute Failed)');
        }

    } catch (PDOException $e) {
        // กรณีลบไม่ได้เพราะติด Foreign Key Constraint
        if ($e->getCode() == '23000') {
            throw new Exception('ไม่สามารถลบได้ เนื่องจากพนักงานคนนี้มีประวัติการทำรายการในระบบ (แนะนำให้ใช้วิธี "ระงับการใช้งาน" แทน)');
        } else {
            throw $e; // Error อื่นๆ โยนต่อไป
        }
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>
