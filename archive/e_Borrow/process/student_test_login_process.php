<?php
// student_test_login_process.php
// ตรวจสอบรหัสทดสอบ และสร้าง Session นักศึกษา

session_start();

// (สำคัญ!) ตั้งรหัสผ่านหลักสำหรับ Tester ที่นี่
$MASTER_TEST_CODE = "testmode123";

// (สำคัญ!) ตั้งค่า ID ของนักศึกษาในฐานข้อมูล ที่คุณต้องการให้ Tester สวมบทบาท
// (ผมจะใช้ ID 9 "NAPATO" จากฐานข้อมูล ของคุณเป็นตัวอย่าง)
$TEST_STUDENT_ID_TO_USE = 3;


// 1. ตรวจสอบว่าส่งแบบ POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    require_once(__DIR__ . '/../../../config/db_connect.php');
    $submitted_code = $_POST['test_code'];

    // 2. ตรวจสอบรหัส
    if ($submitted_code === $MASTER_TEST_CODE) {
        
        // 3. รหัสถูก! -> ดึงข้อมูลนักศึกษาจาก DB
        try {
            $stmt = $pdo->prepare("SELECT * FROM sys_users WHERE id = ?");
            $stmt->execute([$TEST_STUDENT_ID_TO_USE]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($student) {
                // 4. สร้าง Session ที่จำเป็น (เหมือนในไฟล์ save_profile.php และ line_callback.php)
                $_SESSION['student_id'] = $student['id'];
                $_SESSION['student_line_id'] = $student['line_user_id'] ?? 'test_user'; // (ถ้ามี line_id ก็ใช้, ไม่มีก็ใส่ค่าจำลอง)
                $_SESSION['student_full_name'] = $student['full_name'] . " (Tester)"; // (เติม (Tester) ให้รู้)
                $_SESSION['user_role'] = $student['status'];

                // 5. ส่งไปหน้า Dashboard
                header("Location: ../index.php");
                exit;
                
            } else {
                // (เกิดกรณีที่ตั้ง $TEST_STUDENT_ID_TO_USE ผิด)
                header("Location: ../student_test_login.php?error=Test user ID {$TEST_STUDENT_ID_TO_USE} not found in DB.");
                exit;
            }

        } catch (PDOException $e) {
            die("Database Error: " . $e->getMessage());
        }

    } else {
        // 10. รหัสผิด
        header("Location: ../student_test_login.php?error=1");
        exit;
    }

} else {
    // ถ้าเข้ามาหน้านี้ตรงๆ
    header("Location: ../student_test_login.php");
    exit;
}
?>
