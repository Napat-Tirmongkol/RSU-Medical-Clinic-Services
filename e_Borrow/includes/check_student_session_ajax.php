<?php
// includes/check_student_session_ajax.php
// สำหรับคำขอ AJAX ของผู้ใช้งาน/นักศึกษา (ระบบยืมคืนเดิม)
// ปรับปรุงใหม่: รองรับ SSO จากระบบ LINE Login ของ e-Campaign V2

@session_start();

// --- [NEW] Sync จากระบบ LINE Login (e-Campaign V2) ---
if (!isset($_SESSION['student_id']) && isset($_SESSION['line_user_id'])) {
    try {
        require_once __DIR__ . '/../../config/db_connect.php';
        $p = db();
        $lineId = $_SESSION['line_user_id'];
        
        // ค้นหา ID ของนักศึกษาใน sys_users ตาม LINE User ID
        $s = $p->prepare("SELECT id, full_name FROM sys_users WHERE line_user_id = :line LIMIT 1");
        $s->execute([':line' => $lineId]);
        $row = $s->fetch();
        
        if ($row) {
            // "ฝากสิทธิ์" ไปให้ระบบเดิมจำได้
            $_SESSION['student_id'] = $row['id'];
            $_SESSION['full_name'] = $row['full_name'];
        }
    } catch (Exception $e) {
        // เงียบไว้ถ้า DB มีการอัปเดตอยู่
    }
}

// 🚩 ตรวจสอบสิทธิ์ขั้นสุดท้าย
if (!isset($_SESSION['student_id']) || $_SESSION['student_id'] == 0) {
    header('Content-Type: application/json');
    http_response_code(401); // Unauthorized
    echo json_encode([
        'status' => 'error', 
        'message' => 'เซสชันนักศึกษาหมดอายุ หรือยังไม่ได้เข้าสู่ระบบ (ผ่าน LINE)',
        'debug' => 'Missing valid student_id'
    ]);
    exit;
}
?>