<?php
// e_Borrow/process/edit_profile_process.php
// ไม่ใช้แล้ว — form submit ไปที่ profile.php โดยตรง
// เปลี่ยน redirect ไปหน้า profile เพื่อความปลอดภัย
session_start();
header("Location: ../profile.php");
exit;