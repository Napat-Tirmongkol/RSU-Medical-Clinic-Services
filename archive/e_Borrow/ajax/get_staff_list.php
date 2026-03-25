<?php
// get_staff_list.php
// ดึงรายชื่อเจ้าหน้าที่ (Admin/Employee) ทั้งหมดเพื่อใช้ใน Dropdown

include('../includes/check_student_session_ajax.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

header('Content-Type: application/json');
$response = ['status' => 'error', 'staff' => []];

try {
    // ดึงเฉพาะ Admin และ Employee (เจ้าหน้าที่)
    $stmt = $pdo->prepare("SELECT id, full_name FROM sys_staff WHERE role IN ('admin', 'employee') ORDER BY full_name ASC");
    $stmt->execute();
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response['status'] = 'success';
    $response['staff'] = $staff_list;

} catch (PDOException $e) {
    $response['message'] = $e->getMessage(); // ◀️ (แก้ไข)
}

echo json_encode($response);
exit;
?>
