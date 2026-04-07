<?php
// [���: process/toggle_staff_status.php]
// ��Ѻ���͵�������ç�Ѻ admin_app.js (user_id, new_status)

// 1. ��駤�� Error Reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once '../includes/db_connect.php';

$response = ['status' => 'error', 'message' => '�Դ��ͼԴ��Ҵ�������Һ���˵�'];

try {
    session_start();
    require_once '../includes/csrf_validate.php';

    // 2. ตรวจสอบสิทธิ์ Admin
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('�س������Է�����Թ��ù�� (Access Denied)');
    }

    // 3. �Ѻ��Ҩҡ AJAX (�������ͧ�Ѻ���͵���èҡ admin_app.js)
    // JS ��: formData.append('user_id', userId);
    // JS ��: formData.append('new_status', newStatus);
    
    $target_id = $_POST['user_id'] ?? $_POST['id'] ?? null; 
    $target_status = $_POST['new_status'] ?? $_POST['status'] ?? null;

    // 4. Debug: �������� ID ����駡�Ѻ�������Ѻ�����Һ�ҧ
    if (!$target_id) {
        $received_data = json_encode($_POST); // �ŧ�����ŷ�����Ѻ�繢�ͤ���
        throw new Exception("��辺������ ID �����ҹ (Server ���Ѻ: $received_data)");
    }

    // 5. ��ͧ�ѹ����ЧѺ����ͧ
    if ($target_id == $_SESSION['user_id']) {
        throw new Exception('�������ö�ЧѺ�ѭ�բͧ����ͧ��');
    }

    // 6. ��˹����ʶҹз��кѹ�֡ (active/disabled)
    // admin_app.js �觤������ 'active' ���� 'disabled' �ç� ��������
    $final_status = ($target_status === 'disabled') ? 'disabled' : 'active';

    // 7. �ѻവ������ (�� sys_staff ��� account_status)
    $sql = "UPDATE sys_staff SET account_status = :status WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':status' => $final_status,
        ':id' => $target_id
    ]);

    if ($result) {
        $response = [
            'status' => 'success',
            'message' => '�ѻവʶҹ��� ' . ($final_status == 'active' ? '���� (Active)' : '�ЧѺ (Disabled)') . ' ���º��������',
            'new_status' => $final_status
        ];
    } else {
        throw new Exception('�Դ��ͼԴ��Ҵ㹡���ѻവ�ҹ������');
    }

} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>
