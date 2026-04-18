<?php
// e_Borrow/auth_bridge.php
// SSO bridge: ถ้า login ผ่าน e-Campaign (evax_student_id) อยู่แล้ว → set student_id อัตโนมัติ
declare(strict_types=1);
session_start();

$to = $_GET['to'] ?? 'index.php';

// Whitelist: อนุญาตเฉพาะ path ภายใน e_Borrow (ป้องกัน open redirect)
$allowed = ['index.php', 'borrow.php', 'history.php', 'profile.php', 'terms.php'];
if (!in_array(basename(strtok($to, '?')), $allowed, true)) {
    $to = 'index.php';
}

// ถ้ามี student_id อยู่แล้ว → ข้ามได้เลย
if (!empty($_SESSION['student_id'])) {
    header('Location: ' . $to);
    exit;
}

// ถ้า login ผ่าน e-Campaign → sync session ข้าม
if (!empty($_SESSION['evax_student_id'])) {
    $_SESSION['student_id']        = (int)$_SESSION['evax_student_id'];
    $_SESSION['student_full_name'] = $_SESSION['evax_full_name'] ?? '';
    $_SESSION['student_line_id']   = $_SESSION['line_user_id']   ?? '';
    $_SESSION['LAST_ACTIVITY_STUDENT'] = time();

    header('Location: ' . $to);
    exit;
}

// ไม่ได้ login เลย → ส่งไป login page ของ hub
header('Location: ../user/index.php');
exit;
