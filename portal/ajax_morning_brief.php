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
require_once __DIR__ . '/../includes/line_helper.php';
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

    // ── List LINE groups/rooms ที่ OA join อยู่ (อ่านจาก line_groups_list registry) ──
    case 'groups:list': {
        $groups = line_groups_list($pdo);
        // Normalize → คืน array ของ { id, name, type, member_count }
        $out = [];
        foreach ($groups as $g) {
            $out[] = [
                'id'           => (string)($g['group_id'] ?? $g['id'] ?? ''),
                'name'         => (string)($g['name'] ?? $g['group_name'] ?? ''),
                'type'         => (string)($g['type'] ?? 'group'),
                'member_count' => (int)($g['member_count'] ?? 0),
            ];
        }
        // Filter out blank IDs
        $out = array_values(array_filter($out, fn($g) => !empty($g['id'])));
        _mb_send(['ok'=>true, 'groups'=>$out]);
    }

    case 'pref:save': {
        validate_csrf_or_die();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') _mb_send(['ok'=>false,'error'=>'POST only']);
        morning_brief_get_or_create_pref($pdo, $adminId, 'admin');
        $cp  = isset($_POST['channel_portal'])     ? (int)!!$_POST['channel_portal']     : 1;
        $cl  = isset($_POST['channel_line'])       ? (int)!!$_POST['channel_line']       : 0;
        $clg = isset($_POST['channel_line_group']) ? (int)!!$_POST['channel_line_group'] : 0;
        $ce  = isset($_POST['channel_email'])      ? (int)!!$_POST['channel_email']      : 0;
        $hr  = max(0, min(23, (int)($_POST['delivery_hour'] ?? 8)));
        $rcc = isset($_POST['respect_clinic_calendar']) ? (int)!!$_POST['respect_clinic_calendar'] : 0;
        $modules = array_values(array_intersect(
            ['campaign','scholarship','finance','edms','clinic','inventory'],
            (array)($_POST['modules'] ?? [])
        ));
        if (!$modules) $modules = ['campaign','scholarship','finance','edms','clinic','inventory'];
        $line   = trim((string)($_POST['line_user_id'] ?? ''));
        $lgroup = trim((string)($_POST['line_group_id'] ?? ''));
        $email  = trim((string)($_POST['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            _mb_send(['ok'=>false,'error'=>'email ไม่ถูกต้อง']);
        }
        if ($lgroup !== '' && !preg_match('/^[CR][0-9a-f]{32}$/i', $lgroup)) {
            _mb_send(['ok'=>false,'error'=>'LINE Group/Room ID ต้องขึ้นต้นด้วย C หรือ R + 32 ตัว hex']);
        }
        $st = $pdo->prepare("UPDATE sys_morning_brief_prefs
            SET channel_portal=:cp, channel_line=:cl, channel_line_group=:clg, channel_email=:ce,
                delivery_hour=:h, respect_clinic_calendar=:rcc,
                modules_json=:m, line_user_id=:lu, line_group_id=:lg, email=:em, updated_at=NOW()
            WHERE staff_id=:sid AND staff_type='admin'");
        $st->execute([':cp'=>$cp, ':cl'=>$cl, ':clg'=>$clg, ':ce'=>$ce, ':h'=>$hr, ':rcc'=>$rcc,
                      ':m'=>json_encode($modules), ':lu'=>($line?:null),
                      ':lg'=>($lgroup?:null), ':em'=>($email?:null),
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
    // Force-regenerate brief ใหม่ทุกครั้ง — กัน cache เก่าที่มี bug
    case 'preview': {
        $today = date('Y-m-d');
        $data = morning_brief_collect_all($pdo, $today);
        $narrative = morning_brief_generate_narrative($data);
        $data['_ai_priorities'] = $narrative['priorities'] ?? [];
        if (!empty($narrative['error'])) $data['_ai_error'] = $narrative['error'];
        morning_brief_save($pdo, $today, $data,
            $narrative['narrative'] ?? null, $narrative['model'] ?? null,
            'preview:' . $adminName, $narrative['urgency'] ?? 'normal');
        $brief = morning_brief_get_for_date($pdo, $today);
        $brief['ai_priorities'] = $narrative['priorities'] ?? [];
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
                'ai_error' => $narrative['error'] ?? null,
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

        // Force-regenerate ก่อนส่ง — ให้แน่ใจว่าข้อมูลใน brief ตรง ณ ปัจจุบัน
        $data = morning_brief_collect_all($pdo, $today);
        $narrative = morning_brief_generate_narrative($data);
        $data['_ai_priorities'] = $narrative['priorities'] ?? [];
        if (!empty($narrative['error'])) $data['_ai_error'] = $narrative['error'];
        morning_brief_save($pdo, $today, $data,
            $narrative['narrative'] ?? null, $narrative['model'] ?? null,
            'test:' . $adminName, $narrative['urgency'] ?? 'normal');
        $brief = morning_brief_get_for_date($pdo, $today);
        $priorities = $brief['data']['_ai_priorities'] ?? [];

        $results = [];

        // ── LINE (ส่วนตัว) ──────────────────────────────────────────────
        if (empty($pref['channel_line'])) {
            $results['line'] = ['ok' => false, 'error' => 'ยังไม่ได้เปิด channel LINE ส่วนตัว', 'skipped' => true];
        } elseif (empty($pref['line_user_id'])) {
            $results['line'] = ['ok' => false, 'error' => 'เปิด channel LINE แล้ว แต่ยังไม่ได้ใส่ LINE User ID (รูปแบบ U + 32 ตัวอักษร)'];
        } elseif (!preg_match('/^U[0-9a-f]{32}$/i', $pref['line_user_id'])) {
            $results['line'] = ['ok' => false, 'error' => 'รูปแบบ LINE User ID ไม่ถูกต้อง · ต้องขึ้นต้นด้วย U + 32 ตัว hex'];
        } else {
            $token = mb_resolve_line_token();
            if (!$token) {
                $results['line'] = ['ok' => false, 'error' => 'ระบบยังไม่ได้ตั้ง LINE_MESSAGING_CHANNEL_ACCESS_TOKEN ใน config/secrets.php'];
            } else {
                $flex = mb_build_line_flex($brief, $priorities, true);
                $ok = send_line_push($pref['line_user_id'], [$flex], $token);
                $results['line'] = $ok
                    ? ['ok' => true, 'target' => $pref['line_user_id']]
                    : ['ok' => false, 'error' => 'LINE API ปฏิเสธ: ' . (get_last_line_error() ?: 'unknown') . ' · ตรวจว่า user ได้ add LINE OA แล้วหรือยัง'];
            }
        }

        // ── LINE Group ──────────────────────────────────────────────────
        if (empty($pref['channel_line_group'])) {
            $results['line_group'] = ['ok' => false, 'error' => 'ยังไม่ได้เปิด channel LINE Group', 'skipped' => true];
        } elseif (empty($pref['line_group_id'])) {
            $results['line_group'] = ['ok' => false, 'error' => 'เปิด LINE Group แล้ว แต่ยังไม่ได้เลือกกลุ่ม'];
        } else {
            $token = mb_resolve_line_token();
            if (!$token) {
                $results['line_group'] = ['ok' => false, 'error' => 'ระบบยังไม่ได้ตั้ง LINE_MESSAGING_CHANNEL_ACCESS_TOKEN'];
            } else {
                $flex = mb_build_line_flex($brief, $priorities, true);
                $ok = send_line_group_push($pref['line_group_id'], [$flex], $token);
                $results['line_group'] = $ok
                    ? ['ok' => true, 'target' => $pref['line_group_id']]
                    : ['ok' => false, 'error' => 'LINE Group push ปฏิเสธ: ' . (get_last_line_error() ?: 'unknown') . ' · ตรวจว่า OA อยู่ในกลุ่มแล้วหรือยัง'];
            }
        }

        // ── Email ───────────────────────────────────────────────────────
        if (empty($pref['channel_email'])) {
            $results['email'] = ['ok' => false, 'error' => 'ยังไม่ได้เปิด channel Email ในการตั้งค่า', 'skipped' => true];
        } elseif (empty($pref['email'])) {
            $results['email'] = ['ok' => false, 'error' => 'เปิด channel Email แล้ว แต่ยังไม่ได้ใส่ email address'];
        } else {
            $cfgErr = mb_check_email_config();
            if ($cfgErr) {
                $results['email'] = ['ok' => false, 'error' => $cfgErr];
            } else {
                $subject = '[ทดสอบ] Morning Brief — ' . ($brief['data']['clinic']['date_thai'] ?? $today);
                $body = mb_build_email_html($brief, $priorities, true);
                $ok = mb_send_email($pref['email'], $subject, $body);
                $results['email'] = $ok
                    ? ['ok' => true, 'target' => $pref['email']]
                    : ['ok' => false, 'error' => 'SMTP/mail() ส่งไม่ผ่าน · เปิด portal/smtp_settings.php เพื่อตรวจค่า + ดู error_log'];
            }
        }

        _mb_send(['ok' => true, 'results' => $results]);
    }

    default:
        _mb_send(['ok'=>false,'error'=>'unknown action: ' . $action]);
}
