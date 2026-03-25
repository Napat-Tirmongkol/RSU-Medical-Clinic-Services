<?php
// includes/check_student_session_ajax.php
// "ยาม" สำหรับไฟล์ AJAX ของ Student
// จะตอบกลับเป็น JSON Error แทนการ Redirect

@session_start();

if (!isset($_SESSION['student_id']) || $_SESSION['student_id'] == 0) {
    // ถ้า Session Student ไม่มี หรือเป็น 0
    header('Content-Type: application/json');
    http_response_code(401); // 401 Unauthorized
    echo json_encode(['status' => 'error', 'message' => 'Session หมดอายุ, กรุณา Log in ใหม่อีกครั้ง']);
    exit;
}

// ถ้ามี Session ให้ทำงานต่อไป
?>