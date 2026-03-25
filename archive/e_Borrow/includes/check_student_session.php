<?php
// e_Borrow/includes/check_student_session.php
// ����Ѻ˹����纹ѡ�֡�� -> ���������Է��� ���մ�˹�� Login
@session_start();

$timeout_duration = 1800; // 30 �ҷ�

if (isset($_SESSION['LAST_ACTIVITY_STUDENT'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY_STUDENT']) > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: " . dirname($_SERVER['PHP_SELF']) . "/login.php?timeout=1");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY_STUDENT'] = time();

// ��Ǩ�ͺ��� Session �ͧ e_Borrow ���������
// Session 'student_id' �١ set �� line_api/callback.php ��ǡ�ҧ
if (empty($_SESSION['student_id'])) {
    // �ѧ����� Login -> ���˹�� Login �ͧ e_Borrow
    header("Location: login.php");
    exit;
}