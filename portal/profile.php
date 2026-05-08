<?php
/**
 * portal/profile.php
 * Legacy URL — เนื้อหาจริงย้ายไปเป็น section ใน portal/index.php?section=profile
 * คงไฟล์ไว้เพื่อให้ลิงก์/บุ๊กมาร์กเก่าใช้งานต่อได้
 */
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ../admin/auth/login.php');
    exit;
}

header('Location: index.php?section=profile', true, 302);
exit;
