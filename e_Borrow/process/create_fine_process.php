<?php
// create_fine_process.php
// (ไฟล์ใหม่) บันทึกการ "สร้าง" ค่าปรับ

include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../config/db_connect.php');
require_once('../includes/log_function.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}

header('Content-Type: application/json');
$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $staff_id = $_SESSION['user_id'];

    if ($transaction_id == 0 || $student_id == 0 || $amount <= 0) {
        $response['message'] = 'ข้อมูลไม่ครบถ้วน (Transaction ID, Student ID, หรือ Amount)';
        echo json_encode($response);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. INSERT ลงตาราง borrow_fines
        $sql_fine = "INSERT INTO borrow_fines 
                        (transaction_id, student_id, amount, notes, created_by_staff_id, status) 
                     VALUES (?, ?, ?, ?, ?, 'pending')";
        $stmt_fine = $pdo->prepare($sql_fine);
        $stmt_fine->execute([$transaction_id, $student_id, $amount, $notes, $staff_id]);
        
        // 2. อัปเดตตาราง borrow_records ให้มีสถานะ 'pending'
        $sql_trans = "UPDATE borrow_records SET fine_status = 'pending' WHERE id = ?";
        $stmt_trans = $pdo->prepare($sql_trans);
        $stmt_trans->execute([$transaction_id]);

        if ($stmt_fine->rowCount() > 0 && $stmt_trans->rowCount() > 0) {
            
            // 3. บันทึก Log
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' (ID: {$staff_id}) ได้สร้างค่าปรับ (TID: {$transaction_id}) 
                         สำหรับผู้ใช้ (SID: {$student_id}) จำนวน: {$amount} บาท";
            log_action($pdo, $staff_id, 'create_fine', $log_desc);

            $pdo->commit();
            $response['status'] = 'success';
            $response['message'] = 'สร้างค่าปรับสำเร็จ';
        } else {
            throw new Exception("ไม่สามารถอัปเดตข้อมูล Transaction หรือสร้าง Fine ได้");
        }

    } catch (Throwable $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }

} else {
    $response['message'] = 'ต้องใช้วิธี POST เท่านั้น';
}

echo json_encode($response);
exit;
?>