<?php
// e_Borrow/includes/check_student_session.php
// ����Ѻ˹����纹ѡ�֡�� -> ���������Է��� ���մ�˹�� Login
@session_start();
require_once __DIR__ . '/../../config.php';
check_maintenance('e_borrow');

$timeout_duration = 1800; // 30 นาที

if (isset($_SESSION['LAST_ACTIVITY_STUDENT'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY_STUDENT']) > $timeout_duration) {
        session_unset();
        session_destroy();
        header('Location: ../index.php?reason=timeout');
        exit;
    }
}
$_SESSION['LAST_ACTIVITY_STUDENT'] = time();

// SSO Bridge: ถ้ายังไม่มี student_id แต่มี line_user_id จาก main system → lookup อัตโนมัติ
if (empty($_SESSION['student_id']) && !empty($_SESSION['line_user_id'])) {
    try {
        $pdo = db();
        $s = $pdo->prepare("SELECT id, full_name FROM sys_users WHERE line_user_id = :line LIMIT 1");
        $s->execute([':line' => $_SESSION['line_user_id']]);
        $row = $s->fetch();
        if ($row) {
            $_SESSION['student_id']        = $row['id'];
            $_SESSION['student_full_name'] = $row['full_name'];
        }
    } catch (Exception $e) { /* ไม่ block user */ }
}

// ยังไม่ login → ส่งกลับ main app login
if (empty($_SESSION['student_id'])) {
    header('Location: ../index.php');
    exit;
}