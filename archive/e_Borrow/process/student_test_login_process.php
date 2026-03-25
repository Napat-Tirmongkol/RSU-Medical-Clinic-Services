<?php
// student_test_login_process.php
// เธ•เธฃเธงเธเธชเธญเธเธฃเธซเธฑเธชเธ—เธ”เธชเธญเธ เนเธฅเธฐเธชเธฃเนเธฒเธ Session เธเธฑเธเธจเธถเธเธฉเธฒ

session_start();

// (เธชเธณเธเธฑเธ!) เธ•เธฑเนเธเธฃเธซเธฑเธชเธเนเธฒเธเธซเธฅเธฑเธเธชเธณเธซเธฃเธฑเธ Tester เธ—เธตเนเธเธตเน
$MASTER_TEST_CODE = "testmode123";

// (เธชเธณเธเธฑเธ!) เธ•เธฑเนเธเธเนเธฒ ID เธเธญเธเธเธฑเธเธจเธถเธเธฉเธฒเนเธเธเธฒเธเธเนเธญเธกเธนเธฅ เธ—เธตเนเธเธธเธ“เธ•เนเธญเธเธเธฒเธฃเนเธซเน Tester เธชเธงเธกเธเธ—เธเธฒเธ—
// (เธเธกเธเธฐเนเธเน ID 9 "NAPATO" เธเธฒเธเธเธฒเธเธเนเธญเธกเธนเธฅ เธเธญเธเธเธธเธ“เน€เธเนเธเธ•เธฑเธงเธญเธขเนเธฒเธ)
$TEST_STUDENT_ID_TO_USE = 3;


// 1. เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเธชเนเธเนเธเธ POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    require_once(__DIR__ . '/../../../config/db_connect.php');
    $submitted_code = $_POST['test_code'];

    // 2. เธ•เธฃเธงเธเธชเธญเธเธฃเธซเธฑเธช
    if ($submitted_code === $MASTER_TEST_CODE) {
        
        // 3. เธฃเธซเธฑเธชเธ–เธนเธ! -> เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฑเธเธจเธถเธเธฉเธฒเธเธฒเธ DB
        try {
            $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE id = ?");
            $stmt->execute([$TEST_STUDENT_ID_TO_USE]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($student) {
                // 4. เธชเธฃเนเธฒเธ Session เธ—เธตเนเธเธณเน€เธเนเธ (เน€เธซเธกเธทเธญเธเนเธเนเธเธฅเน save_profile.php เนเธฅเธฐ line_callback.php)
                $_SESSION['student_id'] = $student['id'];
                $_SESSION['student_line_id'] = $student['line_user_id'] ?? 'test_user'; // (เธ–เนเธฒเธกเธต line_id เธเนเนเธเน, เนเธกเนเธกเธตเธเนเนเธชเนเธเนเธฒเธเธณเธฅเธญเธ)
                $_SESSION['student_full_name'] = $student['full_name'] . " (Tester)"; // (เน€เธ•เธดเธก (Tester) เนเธซเนเธฃเธนเน)
                $_SESSION['user_role'] = $student['status'];

                // 5. เธชเนเธเนเธเธซเธเนเธฒ Dashboard
                header("Location: ../index.php");
                exit;
                
            } else {
                // (เน€เธเธดเธ”เธเธฃเธ“เธตเธ—เธตเนเธ•เธฑเนเธ $TEST_STUDENT_ID_TO_USE เธเธดเธ”)
                header("Location: ../student_test_login.php?error=Test user ID {$TEST_STUDENT_ID_TO_USE} not found in DB.");
                exit;
            }

        } catch (PDOException $e) {
            die("Database Error: " . $e->getMessage());
        }

    } else {
        // 10. เธฃเธซเธฑเธชเธเธดเธ”
        header("Location: ../student_test_login.php?error=1");
        exit;
    }

} else {
    // เธ–เนเธฒเน€เธเนเธฒเธกเธฒเธซเธเนเธฒเธเธตเนเธ•เธฃเธเน
    header("Location: ../student_test_login.php");
    exit;
}
?>
