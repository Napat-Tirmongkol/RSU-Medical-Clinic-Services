<?php
// e_Borrow/admin/login.php
// ปิด login แยก — ใช้ระบบ login กลางของ Portal แทน
session_start();

// redirect ไปที่ index เฉพาะเมื่อ e-Borrow session sync สำเร็จแล้ว (user_id ถูก set โดย check_session.php)
// ไม่ใช้ admin_logged_in เพราะเป็น portal session ต่างระบบ — SSO ต้องผ่าน check_session.php ก่อน
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ยังไม่ได้ login หรือ SSO sync ยังไม่สำเร็จ → redirect ไปหน้า login กลาง
header('Location: ../../../admin/login.php');
exit;
