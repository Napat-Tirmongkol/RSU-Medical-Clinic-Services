<?php
// e_Borrow/profile.php — redirect ไปหน้า Unified Profile ของ hub
declare(strict_types=1);
@session_start();

// ถ้า login ผ่าน hub → ไปที่ user/profile.php โดยตรง
if (!empty($_SESSION['line_user_id'])) {
    header('Location: ../user/profile.php');
    exit;
}

// ถ้าไม่ได้ login เลย → ไป login
if (empty($_SESSION['student_id'])) {
    header('Location: login.php');
    exit;
}

// กรณีหายาก: student_id มีแต่ไม่มี line_user_id (login ตรงผ่านทาง e_Borrow เก่า)
// ดึง line_user_id จาก DB แล้ว set session เพื่อให้ user/profile.php ทำงานได้
require_once __DIR__ . '/includes/db_connect.php';
try {
    $pdo  = db();
    $stmt = $pdo->prepare("SELECT line_user_id FROM sys_users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => (int)$_SESSION['student_id']]);
    $row  = $stmt->fetch();
    if ($row && $row['line_user_id']) {
        $_SESSION['line_user_id'] = $row['line_user_id'];
        header('Location: ../user/profile.php');
        exit;
    }
} catch (Throwable) {}

// fallback สุดท้าย
header('Location: ../user/index.php');
exit;
