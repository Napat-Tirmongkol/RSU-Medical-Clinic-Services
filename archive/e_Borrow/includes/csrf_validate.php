<?php
// includes/csrf_validate.php
// ใช้ include ไฟล์นี้หลังจาก session_start() แล้ว
// สำหรับ process files ที่ไม่ได้ใช้ check_session_ajax.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !hash_equals($expected, $submitted)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'คำขอไม่ถูกต้อง (CSRF token ผิดพลาด)']);
        exit;
    }
}
