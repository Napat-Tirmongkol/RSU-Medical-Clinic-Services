<?php
// e_Borrow/admin/login.php
// ปิด login แยก — ใช้ระบบ login กลางของ Portal แทน
session_start();

// ถ้า login ผ่าน portal อยู่แล้ว ให้เข้า e_Borrow admin ได้เลย
if (isset($_SESSION['user_id']) || isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

// ยังไม่ได้ login → redirect ไปหน้า login กลาง
header('Location: ../../../admin/login.php');
exit;
