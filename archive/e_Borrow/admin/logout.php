<?php
// logout.php
// ไฟล์สำหรับออกจากระบบของ Admin / พนักงาน

// 1. เริ่ม Session ก่อนเสมอ
session_start();

// 2. ล้างข้อมูล Session ทั้งหมด (เช่น $_SESSION['user_id'], $_SESSION['role'])
session_unset();

// 3. ทำลาย Session ที่ค้างอยู่
session_destroy();

// 4. ส่งผู้ใช้กลับไปหน้า Log in ของพนักงาน (หน้ากรอกรหัสผ่าน)
header("Location: ../login.php");
exit;
?>