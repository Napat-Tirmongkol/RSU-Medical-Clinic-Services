<?php
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../includes/db_connect.php');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'employee'])) {
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์เข้าถึง']);
    exit;
}

$student_code = $_GET['id'] ?? '';
$db_id = $_GET['db_id'] ?? ''; 

if (empty($student_code) && empty($db_id)) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัส']);
    exit;
}

try {
    if (!empty($db_id)) {
        // ค้นหาด้วย ID (แม่นยำสุด)
        $stmt = $pdo->prepare("SELECT id, full_name, student_personnel_id, department, status FROM sys_users WHERE id = ? LIMIT 1");
        $stmt->execute([$db_id]);
    } else {
        // Fallback ค้นหาด้วยรหัสนักศึกษา
        $stmt = $pdo->prepare("SELECT id, full_name, student_personnel_id, department, status FROM sys_users WHERE student_personnel_id = ? LIMIT 1");
        $stmt->execute([$student_code]);
    }
    
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($student) {
        echo json_encode(['status' => 'success', 'student' => $student]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลนักศึกษา']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
