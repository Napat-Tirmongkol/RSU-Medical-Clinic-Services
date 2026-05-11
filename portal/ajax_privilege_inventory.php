<?php
// portal/ajax_privilege_inventory.php — บันทึกการถือสิทธิ์ระดับสูง (ISO Governance)
declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authorization: superadmin only (ตรงกับ section gate ใน portal/index.php)
if (!isset($_SESSION['admin_id']) || ($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$pdo = db();

try {
    $user_id       = $_POST['user_id'] ?? null;
    $role_assigned = trim((string)($_POST['role_assigned'] ?? ''));
    $justification = trim((string)($_POST['justification'] ?? ''));
    $approved_by   = trim((string)($_POST['approved_by'] ?? ''));
    $expiry_date   = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $document_path = null;

    if (!$user_id || !$role_assigned || !$justification || !$approved_by) {
        throw new Exception('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
    }

    // Handle File Upload
    if (isset($_FILES['approval_doc']) && $_FILES['approval_doc']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../storage/access_requests/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileTmpPath   = $_FILES['approval_doc']['tmp_name'];
        $fileName      = $_FILES['approval_doc']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($fileExtension, $allowedExtensions, true)) {
            throw new Exception('รองรับเฉพาะไฟล์ PDF หรือรูปภาพเท่านั้น');
        }

        $newFileName = 'ISO_REQ_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExtension;
        $destPath    = $uploadDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $destPath)) {
            $document_path = 'storage/access_requests/' . $newFileName;
        } else {
            throw new Exception('ไม่สามารถอัปโหลดไฟล์ได้');
        }
    }

    $sql = "INSERT INTO sys_admin_privilege_inventory
            (user_id, role_assigned, justification, approved_by, assigned_at, expiry_date, document_path, status)
            VALUES (?, ?, ?, ?, NOW(), ?, ?, 1)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $role_assigned, $justification, $approved_by, $expiry_date, $document_path]);

    // Log this activity (ISO requirement)
    $adminName = $_SESSION['admin_full_name'] ?? 'System';
    $log_sql = "INSERT INTO sys_activity_logs (admin_id, admin_name, action, description, timestamp)
                VALUES (?, ?, ?, ?, NOW())";
    $log_stmt = $pdo->prepare($log_sql);
    $log_stmt->execute([
        $_SESSION['admin_id'],
        $adminName,
        'ISO_PRIVILEGE_RECORD',
        "Recorded privileged access for Admin ID: $user_id ($role_assigned)"
    ]);

    echo json_encode(['status' => 'success', 'message' => 'บันทึกข้อมูลสิทธิ์เรียบร้อยแล้ว']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
