<?php
// e_Borrow/logout.php
session_start();

// ล้าง Session ทั้งหมด (รวมทั้ง e-campaign sessions ที่ใช้ร่วมกัน)
$_SESSION = [];

// ลบ Session Cookie ใน Browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// ทำลาย Session บน Server
session_destroy();

// ส่งกลับหน้า Login ของ e_Borrow โดยตรง (ไม่ผ่าน index.php)
header("Location: login.php");
exit;