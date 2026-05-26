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
            ['scholarship','finance','edms','clinic','inventory'],
            (array)($_POST['modules'] ?? [])
        ));
        if (!$modules) $modules = ['scholarship','finance','edms','clinic','inventory'];
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

    default:
        _mb_send(['ok'=>false,'error'=>'unknown action: ' . $action]);
}
