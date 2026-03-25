<?php
// includes/check_student_session_ajax.php
// "���" ����Ѻ��� AJAX �ͧ Student
// �еͺ��Ѻ�� JSON Error ᷹��� Redirect

@session_start();

if (!isset($_SESSION['student_id']) || $_SESSION['student_id'] == 0) {
    // ��� Session Student ����� ������ 0
    header('Content-Type: application/json');
    http_response_code(401); // 401 Unauthorized
    echo json_encode(['status' => 'error', 'message' => 'Session �������, ��س� Log in �����ա����']);
    exit;
}

// ����� Session ���ӧҹ����
?>