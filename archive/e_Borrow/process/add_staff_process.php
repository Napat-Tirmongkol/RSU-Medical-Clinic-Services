<?php
// [���: process/add_staff_process.php]
// ��䢪��ͤ���������ç�Ѻ DB: password -> password_hash ���ź created_at �͡

// 1. ��駤�ҡ���ʴ��� Error
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. ��˹� Header �� JSON
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/db_connect.php';
session_start();
require_once '../includes/csrf_validate.php';

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ'];

try {
    // 3. ��Ǩ�ͺ�Է��� (Admin ��ҹ��)
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin'])) {
        throw new Exception('�س������Է�����Թ��ù�� (Access Denied)');
    }

    // 4. ��Ǩ�ͺ Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid Request Method');
    }

    // 5. �Ѻ��Ҩҡ Form
    $username  = trim($_POST['username'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $role      = trim($_POST['role'] ?? 'employee');

    // 6. Validation
    if (empty($username) || empty($password) || empty($full_name)) {
        throw new Exception('��سҡ�͡���������ú��ǹ (Username, Password, ����-ʡ��)');
    }

    // 7. �� Username ��� (���ҧ sys_staff)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sys_staff WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception("Username '$username' �ռ����ҹ���� ��س���������");
    }

    // 8. ���ҧ Hash Password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // 9. �ѹ�֡������ (��䢪��ͤ���������ç�Ѻ SQL)
    // - ����¹ password �� password_hash
    // - ź created_at �͡ (����㹵��ҧ�����)
    $sql = "INSERT INTO sys_staff (username, password_hash, full_name, role) 
            VALUES (:username, :password, :full_name, :role)";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':username' => $username,
        ':password' => $hashed_password, // map ��ҡѺ password_hash
        ':full_name' => $full_name,
        ':role' => $role
    ]);

    if ($result) {
        $response = [
            'status' => 'success', 
            'message' => '�����ѭ�վ�ѡ�ҹ���º��������'
        ];
    } else {
        throw new Exception('�Դ��ͼԴ��Ҵ㹡�úѹ�֡������ŧ�ҹ������');
    }

} catch (PDOException $e) {
    // �ó� Database Error
    $response['message'] = 'Database Error: ' . $e->getMessage();
} catch (Throwable $e) {
    // �ó� Error �����
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>
