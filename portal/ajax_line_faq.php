<?php
/**
 * portal/ajax_line_faq.php
 * อ่าน/เขียนการตั้งค่า "FAQ Auto-reply" (เวลาเปิด/ปิด) ของ LINE bot
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/clinic_status_helper.php';
require_once __DIR__ . '/../includes/line_helper.php';

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

        case 'test_send': {
            // ส่ง flex test ไป LINE — ใช้ settings ปัจจุบัน + state ที่เลือก
            $allowedStates = ['open_now', 'before_open', 'after_close', 'closed_today'];
            $state = (string)($_POST['state'] ?? 'open_now');
            if (!in_array($state, $allowedStates, true)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid state']);
                return;
            }

            $toUserId = trim((string)($_POST['to_user_id'] ?? ($_SESSION['line_user_id'] ?? '')));
            if ($toUserId === '') {
                echo json_encode(['ok' => false, 'error' => 'ไม่มี LINE User ID — กรุณา login LINE หรือกรอก User ID ผู้รับ']);
                return;
            }

            $secrets = require __DIR__ . '/../config/secrets.php';
            $accessToken = (string)($secrets['LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'] ?? '');
            if ($accessToken === '') {
                echo json_encode(['ok' => false, 'error' => 'ยังไม่ตั้งค่า LINE Channel Access Token']);
                return;
            }

            // ถ้าผู้ใช้แก้ฟอร์มแต่ยังไม่กดบันทึก → preview ตามค่าในฟอร์มแทน (ไม่บังคับให้ save ก่อน)
            $previewKeys = [
                'msg_open_now_title','msg_open_now_sub',
                'msg_before_open_title','msg_before_open_sub',
                'msg_after_close_title','msg_after_close_sub',
                'msg_closed_today_title','msg_closed_today_sub',
            ];
            $override = null;
            if (!empty($_POST['use_form_values'])) {
                $override = [];
                foreach ($previewKeys as $k) {
                    if (isset($_POST[$k]) && trim((string)$_POST[$k]) !== '') {
                        $override[$k] = trim((string)$_POST[$k]);
                    }
                }
                if (!$override) $override = null;
            }

            $flex = build_clinic_test_flex($pdo, $state, $override);
            $ok = send_line_push($toUserId, [$flex], $accessToken);
            echo json_encode([
                'ok'      => $ok,
                'message' => $ok ? 'ส่งข้อความทดสอบไปยัง LINE แล้ว — กรุณาตรวจในแอป' : ('ส่งไม่สำเร็จ: ' . get_last_line_error()),
                'state'   => $state,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        default:
            echo json_encode(['ok' => false, 'error' => 'unknown action']);
            return;
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
