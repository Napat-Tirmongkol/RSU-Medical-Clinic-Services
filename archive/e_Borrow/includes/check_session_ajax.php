<?php
// [���: includes/check_session_ajax.php]
// �����к���Ǩ�ͺ Timeout �������͹�Ѻ check_session.php ����

@session_start();

// 1. ��駤������ Timeout (�Թҷ�) - ��ͧ��������ҡѺ��� check_session.php
$timeout_duration = 18000; // 30 �ҷ� (���� 60 �͹���ͺ)

// 2. ��Ǩ�ͺ Timeout
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
        // ����������: ��ҧ Session
        session_unset();     
        session_destroy();
        
        // �� Error 401 ��Ѻ���� JS �����ҵ�ͧ���͡
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Session ������� (Timeout), ��س� Log in ����']);
        exit;
    }
}

// 3. �ѻവ��������ش (��������á�������ҧ� �������ѧ��ҹ����)
$_SESSION['LAST_ACTIVITY'] = time();

// 4. ��Ǩ�ͺ����� User ID ��� (���͡ó������ Login ���)
if (empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401); 
    echo json_encode(['status' => 'error', 'message' => '��س��������к���͹��ҹ']);
    exit;
}
?>