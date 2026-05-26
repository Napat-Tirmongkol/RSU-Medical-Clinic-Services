<?php
/**
 * portal/ajax_morning_brief.php — Morning Brief AJAX
 * Actions:
 *   get        → คืน brief ของ date ที่ระบุ (default = วันนี้) — สร้างใหม่ถ้ายังไม่มีและ auto=1
 *   generate   → บังคับสร้าง/อัปเดต brief ใหม่ (POST + CSRF)
 *   pref:get   → คืน preference ของ user ปัจจุบัน
 *   pref:save  → บันทึก preference (POST + CSRF)
 *   mark_read  → mark brief วันนี้ว่าอ่านแล้ว
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/morning_brief_delivery.php';
require_once __DIR__ . '/queries/morning_brief_queries.php';
require_once __DIR__ . '/services/morning_brief_ai.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminName = (string)($_SESSION['admin_username'] ?? $_SESSION['full_name'] ?? 'system');
if (!$adminId) { echo json_encode(['ok'=>false,'error'=>'unauthenticated']); exit; }

$pdo = db();
ensure_morning_brief_schema($pdo);

$action = (string)($_REQUEST['action'] ?? 'get');

function _mb_send(array $r): void { echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; }

switch ($action) {

    case 'get': {
        $date = (string)($_GET['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) _mb_send(['ok'=>false,'error'=>'bad date']);
        $auto = !empty($_GET['auto']);
        $brief = morning_brief_get_for_date($pdo, $date);
        if (!$brief && $auto && $date === date('Y-m-d')) {
            // auto-generate on first visit of the day
            $data = morning_brief_collect_all($pdo, $date);
            $narrative = morning_brief_generate_narrative($data);
            $id = morning_brief_save($pdo, $date, $data, $narrative['narrative'] ?? null,
                                     $narrative['model'] ?? null, 'auto:' . $adminName, $narrative['urgency'] ?? 'normal');
            $brief = morning_brief_get_for_date($pdo, $date);
            $brief['ai_priorities'] = $narrative['priorities'] ?? [];
            $brief['ai_error'] = $narrative['error'] ?? null;
        } elseif ($brief) {
            // try to re-extract priorities from data_json — they were stored alongside narrative
            $brief['ai_priorities'] = $brief['data']['_ai_priorities'] ?? [];
        }
        $pref = morning_brief_get_or_create_pref($pdo, $adminId, 'admin');
        $unread = !$pref['last_read_date'] || $pref['last_read_date'] < $date;
        _mb_send(['ok'=>true, 'brief'=>$brief, 'unread'=>$unread]);
    }

    case 'generate': {
        validate_csrf_or_die();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') _mb_send(['ok'=>false,'error'=>'POST only']);
        $date = (string)($_POST['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) _mb_send(['ok'=>false,'error'=>'bad date']);

        $data = morning_brief_collect_all($pdo, $date);
        $narrative = morning_brief_generate_narrative($data);
        // attach priorities into data_json so future reads have them
        $data['_ai_priorities'] = $narrative['priorities'] ?? [];
        $id = morning_brief_save($pdo, $date, $data,
                                  $narrative['narrative'] ?? null,
                                  $narrative['model'] ?? null,
                                  $adminName,
                                  $narrative['urgency'] ?? 'normal');
        $brief = morning_brief_get_for_date($pdo, $date);
        $brief['ai_priorities'] = $narrative['priorities'] ?? [];
        $brief['ai_error'] = $narrative['error'] ?? null;
        _mb_send(['ok'=>true, 'brief'=>$brief, 'message'=>'สร้าง brief สำเร็จ']);
    }

    case 'pref:get': {
        $pref = morning_brief_get_or_create_pref($pdo, $adminId, 'admin');
        $pref['modules'] = json_decode($pref['modules_json'] ?? '[]', true) ?: [];
        unset($pref['modules_json']);
        _mb_send(['ok'=>true, 'pref'=>$pref]);
    }

    case 'pref:save': {
        validate_csrf_or_die();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') _mb_send(['ok'=>false,'error'=>'POST only']);
        morning_brief_get_or_create_pref($pdo, $adminId, 'admin');
        $cp = isset($_POST['channel_portal']) ? (int)!!$_POST['channel_portal'] : 1;
        $cl = isset($_POST['channel_line'])   ? (int)!!$_POST['channel_line']   : 0;
        $ce = isset($_POST['channel_email'])  ? (int)!!$_POST['channel_email']  : 0;
        $hr = max(0, min(23, (int)($_POST['delivery_hour'] ?? 8)));
        $modules = array_values(array_intersect(
            ['campaign','scholarship','finance','edms','clinic','inventory'],
            (array)($_POST['modules'] ?? [])
        ));
        if (!$modules) $modules = ['campaign','scholarship','finance','edms','clinic','inventory'];
        $line  = trim((string)($_POST['line_user_id'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            _mb_send(['ok'=>false,'error'=>'email ไม่ถูกต้อง']);
        }
        $st = $pdo->prepare("UPDATE sys_morning_brief_prefs
            SET channel_portal=:cp, channel_line=:cl, channel_email=:ce, delivery_hour=:h,
                modules_json=:m, line_user_id=:lu, email=:em, updated_at=NOW()
            WHERE staff_id=:sid AND staff_type='admin'");
        $st->execute([':cp'=>$cp, ':cl'=>$cl, ':ce'=>$ce, ':h'=>$hr,
                      ':m'=>json_encode($modules), ':lu'=>($line?:null), ':em'=>($email?:null),
                      ':sid'=>$adminId]);
        _mb_send(['ok'=>true, 'message'=>'บันทึกแล้ว']);
    }

    case 'mark_read': {
        validate_csrf_or_die();
        $date = (string)($_POST['date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) _mb_send(['ok'=>false,'error'=>'bad date']);
        morning_brief_mark_read($pdo, $adminId, 'admin', $date);
        _mb_send(['ok'=>true]);
    }

    // ── Preview brief in both LINE Flex (JSON) and Email HTML formats ──
    // ใช้ brief ของวันนี้ — ถ้ายังไม่มีจะ generate ก่อน (ไม่ถือเป็น delivery)
    case 'preview': {
        $today = date('Y-m-d');
        $brief = morning_brief_get_for_date($pdo, $today);
        if (!$brief) {
            $data = morning_brief_collect_all($pdo, $today);
            $narrative = morning_brief_generate_narrative($data);
            $data['_ai_priorities'] = $narrative['priorities'] ?? [];
            morning_brief_save($pdo, $today, $data,
                $narrative['narrative'] ?? null, $narrative['model'] ?? null,
                'preview:' . $adminName, $narrative['urgency'] ?? 'normal');
            $brief = morning_brief_get_for_date($pdo, $today);
            $brief['ai_priorities'] = $narrative['priorities'] ?? [];
        } else {
            $brief['ai_priorities'] = $brief['data']['_ai_priorities'] ?? [];
        }
        $priorities = $brief['ai_priorities'] ?? [];
        _mb_send([
            'ok' => true,
            'brief_meta' => [
                'date' => $brief['brief_date'],
                'date_thai' => $brief['data']['clinic']['date_thai'] ?? '',
                'weekday_thai' => $brief['data']['clinic']['weekday_thai'] ?? '',
                'urgency' => $brief['urgency_level'] ?? 'normal',
                'model' => $brief['ai_model'] ?? '',
                'narrative' => $brief['ai_narrative'] ?? '',
                'priorities' => $priorities,
            ],
            'line_flex' => mb_build_line_flex($brief, $priorities, false),
            'email_html' => mb_build_email_html($brief, $priorities, false),
        ]);
    }

    // ── Test send: ส่ง brief ของวันนี้ไปยัง channel ที่ user เปิดไว้ทันที (mark [TEST]) ──
    case 'test_send': {
        validate_csrf_or_die();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') _mb_send(['ok'=>false,'error'=>'POST only']);
        $today = date('Y-m-d');
        $pref = morning_brief_get_or_create_pref($pdo, $adminId, 'admin');

        $brief = morning_brief_get_for_date($pdo, $today);
        if (!$brief) {
            $data = morning_brief_collect_all($pdo, $today);
            $narrative = morning_brief_generate_narrative($data);
            $data['_ai_priorities'] = $narrative['priorities'] ?? [];
            morning_brief_save($pdo, $today, $data,
                $narrative['narrative'] ?? null, $narrative['model'] ?? null,
                'test:' . $adminName, $narrative['urgency'] ?? 'normal');
            $brief = morning_brief_get_for_date($pdo, $today);
        }
        $priorities = $brief['data']['_ai_priorities'] ?? [];

        $results = [];

        // LINE
        if (!empty($pref['channel_line']) && !empty($pref['line_user_id'])) {
            $token = mb_resolve_line_token();
            if (!$token) {
                $results['line'] = ['ok' => false, 'error' => 'ระบบยังไม่ได้ตั้ง LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'];
            } else {
                $flex = mb_build_line_flex($brief, $priorities, true);
                $ok = send_line_push($pref['line_user_id'], [$flex], $token);
                $results['line'] = $ok
                    ? ['ok' => true, 'target' => $pref['line_user_id']]
                    : ['ok' => false, 'error' => get_last_line_error() ?: 'LINE API failed'];
            }
        } else {
            $results['line'] = ['ok' => false, 'error' => 'ยังไม่ได้เปิด LINE channel หรือใส่ LINE User ID'];
        }

        // Email
        if (!empty($pref['channel_email']) && !empty($pref['email'])) {
            $subject = '[ทดสอบ] Morning Brief — ' . ($brief['data']['clinic']['date_thai'] ?? $today);
            $body = mb_build_email_html($brief, $priorities, true);
            $ok = mb_send_email($pref['email'], $subject, $body);
            $results['email'] = $ok
                ? ['ok' => true, 'target' => $pref['email']]
                : ['ok' => false, 'error' => 'PHP mail() คืนค่า false — เช็ค SMTP config'];
        } else {
            $results['email'] = ['ok' => false, 'error' => 'ยังไม่ได้เปิด Email channel หรือใส่ email'];
        }

        _mb_send(['ok' => true, 'results' => $results]);
    }

    default:
        _mb_send(['ok'=>false,'error'=>'unknown action: ' . $action]);
}
