<?php
// e_Borrow/includes/check_student_session.php
// สำหรับหน้าเว็บนักศึกษา -> ถ้าไม่มีสิทธิ์ ให้ดีดไปหน้า Login
@session_start();

$timeout_duration = 1800; // 30 นาที

if (isset($_SESSION['LAST_ACTIVITY_STUDENT'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY_STUDENT']) > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: " . dirname($_SERVER['PHP_SELF']) . "/login.php?timeout=1");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY_STUDENT'] = time();

// ตรวจสอบว่า Session ของ e_Borrow มีหรือไม่
// Session 'student_id' ถูก set โดย line_api/callback.php ตัวกลาง
if (empty($_SESSION['student_id'])) {
    // ยังไม่ได้ Login -> ส่งไปหน้า Login ของ e_Borrow
    header("Location: login.php");
    exit;
}