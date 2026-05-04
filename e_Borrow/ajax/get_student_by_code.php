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
    $student = null;
    $cols = "id, full_name, student_personnel_id, member_id, department, status";

    // 1. Most accurate: PK lookup
    if (!empty($db_id)) {
        $stmt = $pdo->prepare("SELECT {$cols} FROM sys_users WHERE id = ? LIMIT 1");
        $stmt->execute([$db_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // 2. Try member_id (universal — works for students/staff/external)
    if (!$student && !empty($student_code)) {
        try {
            $stmt = $pdo->prepare("SELECT {$cols} FROM sys_users WHERE member_id = ? LIMIT 1");
            $stmt->execute([$student_code]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException) {
            // member_id column may not exist on legacy DBs — fall through
        }
    }

    // 3. Legacy fallback: student_personnel_id (printed cards / old QRs)
    if (!$student && !empty($student_code)) {
        $stmt = $pdo->prepare("SELECT {$cols} FROM sys_users WHERE student_personnel_id = ? LIMIT 1");
        $stmt->execute([$student_code]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($student) {
        echo json_encode(['status' => 'success', 'student' => $student]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลนักศึกษา']);
    }
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
