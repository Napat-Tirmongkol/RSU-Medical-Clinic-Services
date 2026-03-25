<?php
// [���: includes/check_session.php]
// ����Ѻ "˹�����" (HTML Pages) -> ��� Redirect ��Ѻ˹�� Login

@session_start();

// 1. ��駤������ Timeout (�Թҷ�)
// ���ͺ: 60 | ��ҹ��ԧ: 1800 (30 �ҷ�)
$timeout_duration = 18000; 

// 2. ��Ǩ�ͺ Timeout
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
        // ���������� -> ��ҧ Session
        session_unset();     
        session_destroy();
        
        // ?? �Ӥѭ: ��ͧ�� header Location ���ʹմ��Ѻ˹�� Login
        header("Location: ../admin/login.php?timeout=1"); 
        exit;
    }
}

// 3. �ѻവ��������ش
$_SESSION['LAST_ACTIVITY'] = time();

// 4. ����ѧ����� Login ���
if (!isset($_SESSION['user_id'])) {
    header("Location: ../admin/login.php");
    exit;
}
?>