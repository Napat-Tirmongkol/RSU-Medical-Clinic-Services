<?php
// save_profile.php
// เธเธฑเธเธ—เธถเธเธเนเธญเธกเธนเธฅเนเธเธฃเนเธเธฅเนเธฅเธ DB

session_start();
require_once('../includes/line_config.php');
require_once(__DIR__ . '/../../../config/db_connect.php'); //

if (!isset($_POST['terms_agree']) || $_POST['terms_agree'] != 'yes') {
    // (เธ–เนเธฒเนเธกเนเธขเธญเธกเธฃเธฑเธ เนเธซเนเน€เธ”เนเธเธเธฅเธฑเธเนเธเธซเธเนเธฒเน€เธ”เธดเธกเธเธฃเนเธญเธก Error)
    $_SESSION['form_error'] = 'เธเธฃเธธเธ“เธฒเธเธ”เธขเธญเธกเธฃเธฑเธเธเนเธญเธ•เธเธฅเธเธเธฒเธฃเนเธเนเธเธฒเธเธเนเธญเธเธ”เธณเน€เธเธดเธเธเธฒเธฃเธ•เนเธญ';
    header("Location: ../create_profile.php");
    exit;
}
// 1. "เธขเธฒเธกเน€เธเนเธฒเธเธฃเธฐเธ•เธน"
//    เธ•เนเธญเธเธกเธต line_id_to_register เนเธ Session เน€เธ—เนเธฒเธเธฑเนเธ
if (!isset($_SESSION['line_id_to_register']) || $_SERVER["REQUEST_METHOD"] != "POST") {
    // เธ–เนเธฒเนเธกเนเธกเธต Session เธซเธฃเธทเธญเนเธกเนเนเธ”เนเธชเนเธเนเธเธ POST เธกเธฒ เนเธซเนเน€เธ”เนเธเธเธฅเธฑเธ
    header("Location: ../login.php");
    exit;
}

// 2. เธ”เธถเธ line_user_id เธ—เธตเนเน€เธเนเธเนเธงเนเนเธ Session
$line_user_id = $_SESSION['line_id_to_register'];

// 3. เธฃเธฑเธเธเนเธญเธกเธนเธฅเธเธฒเธเธเธญเธฃเนเธก
$full_name = trim($_POST['full_name']);
$department = trim($_POST['department']);
$status = trim($_POST['status']);
$status_other = ($status == 'other') ? trim($_POST['status_other']) : null;
$student_personnel_id = trim($_POST['student_personnel_id']);
$phone_number = trim($_POST['phone_number']);

// 4. เธ•เธฃเธงเธเธชเธญเธเธเนเธญเธกเธนเธฅเธเธฑเธเธเธฑเธ
if (empty($full_name) || empty($status) || ($status == 'other' && empty($status_other))) {
    die("เธเนเธญเธกเธนเธฅเธเธฑเธเธเธฑเธ (เธเธทเนเธญ-เธชเธเธธเธฅ, เธชเธ–เธฒเธเธ เธฒเธ) เนเธกเนเธเธฃเธเธ–เนเธงเธ <a href='create_profile.php'>เธเธฅเธฑเธเนเธเธเธฃเธญเธเนเธซเธกเน</a>");
}

// 5. เธเธฑเธเธ—เธถเธเธฅเธเธเธฒเธเธเนเธญเธกเธนเธฅ (sys_users)
try {
    $sql = "INSERT INTO sys_users 
                (line_user_id, full_name, department, status, status_other, student_personnel_id, phone_number) 
            VALUES 
                (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $line_user_id,
        $full_name,
        $department,
        $status,
        $status_other,
        $student_personnel_id,
        $phone_number
    ]);

    // 6. เธเธฑเธเธ—เธถเธเธชเธณเน€เธฃเนเธ!
    //    เธ”เธถเธ ID เธ—เธตเนเน€เธเธดเนเธเธชเธฃเนเธฒเธเธเธถเนเธเธกเธฒ
    $new_student_id = $pdo->lastInsertId();

    // 7. เธชเธฃเนเธฒเธ Session เธเธฃเธดเธเธชเธณเธซเธฃเธฑเธเธเธนเนเนเธเนเธเธฒเธ
    $_SESSION['student_id'] = $new_student_id; // (เธซเนเธฒเธกเน€เธเธฅเธตเนเธขเธเธเธทเนเธญเธ•เธฑเธงเนเธเธฃเธเธตเน)
    $_SESSION['student_line_id'] = $line_user_id;
    $_SESSION['student_full_name'] = $full_name;
    $_SESSION['user_role'] = $status; // (เธญเธฑเธเธเธตเนเธเธทเธญเธชเธ–เธฒเธเธ เธฒเธ เน€เธเนเธ student, teacher เนเธกเนเนเธเน role employee)

    // 8. เธฅเนเธฒเธ Session เธเธฑเนเธงเธเธฃเธฒเธง
    unset($_SESSION['line_id_to_register']);
    unset($_SESSION['line_name_to_register']);

    // 9. เธชเนเธเนเธเธซเธเนเธฒ Dashboard เธเธญเธเธเธนเนเนเธเนเธเธฒเธ
    header("Location: ../index.php"); 
    exit;

} catch (PDOException $e) {
    // (เธเธฃเธ“เธตเธเธขเธฒเธขเธฒเธกเธฅเธเธ—เธฐเน€เธเธตเธขเธเธเนเธณเธ”เนเธงเธข LINE ID เน€เธ”เธตเธขเธงเธเธฑเธ)
    if ($e->getCode() == '23000') {
         die("เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: LINE User ID เธเธตเนเธ–เธนเธเธฅเธเธ—เธฐเน€เธเธตเธขเธเนเธเธฃเธฐเธเธเนเธฅเนเธง <a href='../login.php'>เธเธฅเธฑเธเนเธเธซเธเนเธฒ Login</a>");
    } else {
         die("เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธเธฑเธเธ—เธถเธเธเธฒเธเธเนเธญเธกเธนเธฅ: " . $e->getMessage());
    }
}
?>
