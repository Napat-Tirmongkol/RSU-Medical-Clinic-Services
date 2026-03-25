<?php
// get_staff_list.php
// เธ”เธถเธเธฃเธฒเธขเธเธทเนเธญเน€เธเนเธฒเธซเธเนเธฒเธ—เธตเน (Admin/Employee) เธ—เธฑเนเธเธซเธกเธ”เน€เธเธทเนเธญเนเธเนเนเธ Dropdown

include('../includes/check_student_session_ajax.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

header('Content-Type: application/json');
$response = ['status' => 'error', 'staff' => []];

try {
    // เธ”เธถเธเน€เธเธเธฒเธฐ Admin เนเธฅเธฐ Employee (เน€เธเนเธฒเธซเธเนเธฒเธ—เธตเน)
    $stmt = $pdo->prepare("SELECT id, full_name FROM sys_staff WHERE role IN ('admin', 'employee') ORDER BY full_name ASC");
    $stmt->execute();
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response['status'] = 'success';
    $response['staff'] = $staff_list;

} catch (PDOException $e) {
    $response['message'] = $e->getMessage(); // โ—€๏ธ (เนเธเนเนเธ)
}

echo json_encode($response);
exit;
?>
