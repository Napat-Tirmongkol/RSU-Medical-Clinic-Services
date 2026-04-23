<?php
// e_Borrow/admin/ajax_profile.php
session_start();
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$pdo = db();
$adminId = $_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT username, full_name, ecampaign_role FROM sys_staff WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $adminId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo json_encode(['status' => 'success', 'data' => $user]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกชื่อ-นามสกุล']);
        exit;
    }

    if (!empty($new_password) && $new_password !== $confirm_password) {
        echo json_encode(['status' => 'error', 'message' => 'รหัสผ่านและการยืนยันไม่ตรงกัน']);
        exit;
    }

    try {
        if (!empty($new_password)) {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE sys_staff SET full_name = :fname, password_hash = :pwd WHERE id = :id");
            $stmt->execute([':fname' => $full_name, ':pwd' => $hash, ':id' => $adminId]);
        } else {
            $stmt = $pdo->prepare("UPDATE sys_staff SET full_name = :fname WHERE id = :id");
            $stmt->execute([':fname' => $full_name, ':id' => $adminId]);
        }
        
        $_SESSION['admin_username'] = $full_name;
        $_SESSION['full_name'] = $full_name;
        
        echo json_encode(['status' => 'success', 'message' => 'อัปเดตโปรไฟล์สำเร็จ', 'new_name' => $full_name]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล']);
    }
    exit;
}
