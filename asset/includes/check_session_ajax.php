<?php
/**
 * asset/includes/check_session_ajax.php
 * เช็ค session สำหรับ AJAX endpoint — ตอบ JSON เมื่อ unauthorized
 */
@session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'กรุณาเข้าสู่ระบบใหม่']);
    exit;
}

require_once __DIR__ . '/db_connect.php';

if (!function_exists('asset_user_role')) {
    function asset_user_role(): string { return $_SESSION['role'] ?? 'guest'; }
}
if (!function_exists('asset_can_manage')) {
    function asset_can_manage(): bool {
        return in_array(asset_user_role(), ['admin', 'editor'], true);
    }
}
if (!function_exists('asset_require_manage_ajax')) {
    function asset_require_manage_ajax(): void {
        if (!asset_can_manage()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden', 'message' => 'ต้องเป็น admin/editor เท่านั้น']);
            exit;
        }
    }
}
if (!function_exists('asset_validate_csrf_ajax')) {
    function asset_validate_csrf_ajax(): void {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!verify_csrf_token($token)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'csrf_invalid', 'message' => 'CSRF token ไม่ถูกต้อง']);
            exit;
        }
    }
}
