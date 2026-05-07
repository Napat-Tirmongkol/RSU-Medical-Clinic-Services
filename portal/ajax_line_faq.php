<?php
/**
 * portal/ajax_line_faq.php
 * อ่าน/เขียนการตั้งค่า "FAQ Auto-reply" (เวลาเปิด/ปิด) ของ LINE bot
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/clinic_status_helper.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

if ($action !== 'get') {
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token']);
        exit;
    }
}

$pdo = db();

try {
    switch ($action) {
        case 'get': {
            $settings = get_clinic_faq_settings($pdo);
            echo json_encode(['ok' => true, 'settings' => $settings, 'defaults' => CLINIC_FAQ_DEFAULTS], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'save': {
            $data = [
                'enabled'                => isset($_POST['enabled']) ? (int)!!$_POST['enabled'] : 0,
                'rate_limit_hours'       => (int)($_POST['rate_limit_hours'] ?? 24),
                'msg_open_now_title'     => (string)($_POST['msg_open_now_title']     ?? ''),
                'msg_open_now_sub'       => (string)($_POST['msg_open_now_sub']       ?? ''),
                'msg_before_open_title'  => (string)($_POST['msg_before_open_title']  ?? ''),
                'msg_before_open_sub'    => (string)($_POST['msg_before_open_sub']    ?? ''),
                'msg_after_close_title'  => (string)($_POST['msg_after_close_title']  ?? ''),
                'msg_after_close_sub'    => (string)($_POST['msg_after_close_sub']    ?? ''),
                'msg_closed_today_title' => (string)($_POST['msg_closed_today_title'] ?? ''),
                'msg_closed_today_sub'   => (string)($_POST['msg_closed_today_sub']   ?? ''),
            ];
            $ok = save_clinic_faq_settings($pdo, $data);
            echo json_encode([
                'ok'       => $ok,
                'message'  => $ok ? 'บันทึกการตั้งค่าแล้ว' : 'บันทึกไม่สำเร็จ',
                'settings' => get_clinic_faq_settings($pdo),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'reset': {
            $ok = save_clinic_faq_settings($pdo, CLINIC_FAQ_DEFAULTS);
            echo json_encode([
                'ok'       => $ok,
                'message'  => $ok ? 'รีเซ็ตเป็นค่าเริ่มต้นแล้ว' : 'รีเซ็ตไม่สำเร็จ',
                'settings' => get_clinic_faq_settings($pdo),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        case 'purge_log': {
            purge_clinic_faq_log($pdo, 30);
            echo json_encode(['ok' => true, 'message' => 'ลบ log เก่ากว่า 30 วันแล้ว']);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'error' => 'unknown action']);
            return;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
