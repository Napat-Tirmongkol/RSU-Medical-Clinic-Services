<?php
// [แก้ไข: includes/check_session_ajax.php]
// เพิ่มระบบตรวจสอบ Timeout ให้เหมือนกับ check_session.php ปกติ

@session_start();

// 1. ตั้งค่าเวลา Timeout (วินาที) - ต้องตั้งให้เท่ากับไฟล์ check_session.php
$timeout_duration = 18000; // 30 นาที (หรือ 60 ตอนทดสอบ)

// 2. ตรวจสอบ Timeout
if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
        // ถ้าหมดเวลา: ล้าง Session
        session_unset();     
        session_destroy();
        
        // ส่ง Error 401 กลับไปให้ JS รู้ว่าต้องเด้งออก
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Session หมดอายุ (Timeout), กรุณา Log in ใหม่']);
        exit;
    }
}

// 3. อัปเดตเวลาล่าสุด (เพื่อให้การกดปุ่มต่างๆ ถือว่ายังใช้งานอยู่)
$_SESSION['LAST_ACTIVITY'] = time();

// 4. ตรวจสอบว่ามี User ID ไหม (เผื่อกรณีไม่ได้ Login เลย)
if (empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401); 
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบก่อนใช้งาน']);
    exit;
}
?>