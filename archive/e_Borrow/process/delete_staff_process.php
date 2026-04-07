<?php
// [���: process/delete_staff_process.php]
// �������Ѻ��� user_id_to_delete �������ҧ sys_staff

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

    // 3. �Ѻ��Ҩҡ AJAX (���͵���õ�ͧ�ç�Ѻ admin_app.js: formData.append('user_id_to_delete', ...))
    $target_id = $_POST['user_id_to_delete'] ?? null;

    if (!$target_id) {
        throw new Exception('��辺������ ID �����ҹ����ź');
    }

    // 4. ��ͧ�ѹ���ź����ͧ
    if ($target_id == $_SESSION['user_id']) {
        throw new Exception('�������öź�ѭ�բͧ����ͧ��');
    }

    // 5. ��Ǩ�ͺ�������¡�ä�ҧ����������� (Optional Check)
    // �������͹��ѵ���¡�� (approver_id) �����Ѻ�ͧ�׹ (return_staff_id) �������
    // ��ҫ����������ͧ Data Integrity ������� "�ЧѺ" ᷹��� "ź" 
    // ���ҵ�ͧ���ź��ԧ� SQL �зӧҹ��� Constraint (�� ON DELETE SET NULL ���� RESTRICT)
    
    // �ͧź������
    $sql = "DELETE FROM sys_staff WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    
    // �� Try-Catch ੾�Шش Execute ���ʹѡ�Ѻ Error �ҡ Foreign Key (�� �Դ Constraint)
    try {
        $result = $stmt->execute([':id' => $target_id]);
        
        if ($result) {
            $response = [
                'status' => 'success', 
                'message' => 'ź�ѭ�վ�ѡ�ҹ���º��������'
            ];
        } else {
            throw new Exception('�������öź�������� (Execute Failed)');
        }

    } catch (PDOException $e) {
        // �ó�ź��������еԴ Foreign Key Constraint
        if ($e->getCode() == '23000') {
            throw new Exception('�������öź�� ���ͧ�ҡ��ѡ�ҹ������ջ���ѵԡ�÷���¡����к� (�й�������Ը� "�ЧѺ�����ҹ" ᷹)');
        } else {
            throw $e; // Error ���� �¹����
        }
    }

} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>
