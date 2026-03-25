<?php
// [แก้ไข: includes/check_session.php]
// สำหรับ "หน้าเว็บ" (HTML Pages) -> ให้ Redirect กลับหน้า Login

@session_start();

// 1. ตั้งค่าเวลา Timeout (วินาที)
// ทดสอบ: 60 | ใช้งานจริง: 1800 (30 นาที)
$timeout_duration = 18000; 

// 2. ตรวจสอบ Timeout
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
        // ถ้าหมดเวลา -> ล้าง Session
        session_unset();     
        session_destroy();
        
        // ⚠️ สำคัญ: ต้องใช้ header Location เพื่อดีดกลับหน้า Login
        header("Location: ../admin/login.php?timeout=1"); 
        exit;
    }
}

// 3. อัปเดตเวลาล่าสุด
$_SESSION['LAST_ACTIVITY'] = time();

// 4. ถ้ายังไม่ได้ Login เลย
if (!isset($_SESSION['user_id'])) {
    header("Location: ../admin/login.php");
    exit;
}
?>