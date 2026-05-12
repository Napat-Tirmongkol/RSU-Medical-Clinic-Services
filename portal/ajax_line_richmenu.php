<?php
/**
 * portal/ajax_line_richmenu.php
 * AJAX สำหรับการตั้งค่า/ทดสอบ LINE Rich Menu (per-user binding)
 *
 * Actions:
 *   get          — ดึง ID ปัจจุบัน + list richmenus จาก API
 *   save_ids     — บันทึก guest/member IDs
 *   set_default  — ตั้ง default richmenu (POST /user/all/richmenu)
 *   sync_user    — ทดสอบ sync เมนูของ lineUserId 1 คน
 *   sync_all     — sync ทุก user ที่มี line_user_id (admin only)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../line_api/line_richmenu_helper.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
if ($adminRole !== 'superadmin' && $adminRole !== 'admin') {
    echo json_encode(['ok' => false, 'message' => 'Permission denied']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'get') {
        $ids = line_richmenu_get_ids();
        $list = line_richmenu_list();
        echo json_encode([
            'ok'        => true,
            'ids'       => $ids,
            'richmenus' => $list['richmenus'],
            'list_error'=> $list['error'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'message' => 'POST only']);
        exit;
    }
    validate_csrf_or_die();

    if ($action === 'save_ids') {
        $guest  = trim((string)($_POST['guest_id']  ?? ''));
        $member = trim((string)($_POST['member_id'] ?? ''));
        $ok = line_richmenu_save_ids($guest, $member);
        if ($ok) log_activity('LINE Rich Menu', "อัปเดต IDs: guest=$guest, member=$member");
        echo json_encode(['ok' => $ok, 'message' => $ok ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ']);
        exit;
    }

    if ($action === 'set_default') {
        $target = trim((string)($_POST['target'] ?? 'guest')); // guest|member|clear
        $ids = line_richmenu_get_ids();
        if ($target === 'clear') {
            $r = line_richmenu_clear_default();
            log_activity('LINE Rich Menu', 'ลบ default richmenu');
        } else {
            $rid = $target === 'member' ? $ids['member'] : $ids['guest'];
            if ($rid === '') {
                echo json_encode(['ok' => false, 'message' => "ยังไม่ได้ตั้ง $target richMenuId"]);
                exit;
            }
            $r = line_richmenu_set_default($rid);
            log_activity('LINE Rich Menu', "ตั้ง default = $target ($rid)");
        }
        echo json_encode(['ok' => $r['ok'], 'http' => $r['http'], 'message' => $r['ok'] ? 'สำเร็จ' : ($r['error'] ?? 'ล้มเหลว')]);
        exit;
    }

    if ($action === 'sync_user') {
        $uid  = trim((string)($_POST['line_user_id'] ?? ''));
        $mode = trim((string)($_POST['mode'] ?? 'auto'));
        if ($uid === '') { echo json_encode(['ok' => false, 'message' => 'ต้องระบุ lineUserId']); exit; }

        if ($mode === 'unlink') {
            $r = line_richmenu_unlink_user($uid);
            echo json_encode(['ok' => $r['ok'], 'state' => 'unlinked', 'message' => $r['ok'] ? 'ลบ link → user จะเห็น default' : ($r['error'] ?? 'ล้มเหลว')]);
            exit;
        }

        $force = null;
        if ($mode === 'guest')  $force = false;
        if ($mode === 'member') $force = true;

        $r = line_richmenu_sync_user($uid, $force);
        echo json_encode(['ok' => $r['ok'], 'state' => $r['state'], 'message' => $r['ok'] ? "Linked → {$r['state']}" : ($r['error'] ?? 'ล้มเหลว')]);
        exit;
    }

    if ($action === 'sync_all') {
        // sync ทุก user ที่มี line_user_id — link เมนู member ให้ทั้งหมด (เพราะถือว่าเป็น member แล้ว)
        $ids = line_richmenu_get_ids();
        if ($ids['member'] === '') {
            echo json_encode(['ok' => false, 'message' => 'ยังไม่ได้ตั้ง member richMenuId']);
            exit;
        }
        $pdo = db();
        $rows = $pdo->query("SELECT DISTINCT COALESCE(line_user_id_new, line_user_id) AS uid
                             FROM sys_users
                             WHERE (line_user_id IS NOT NULL AND line_user_id != '')
                                OR (line_user_id_new IS NOT NULL AND line_user_id_new != '')")
                    ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $ok = 0; $fail = 0;
        foreach ($rows as $uid) {
            if (!$uid) continue;
            $r = line_richmenu_link_user((string)$uid, $ids['member']);
            $r['ok'] ? $ok++ : $fail++;
            // กัน rate limit เผื่อ user เยอะ
            if (($ok + $fail) % 50 === 0) usleep(200000); // 0.2s pause every 50
        }
        log_activity('LINE Rich Menu', "Sync all → member: ok=$ok, fail=$fail");
        echo json_encode(['ok' => true, 'total' => count($rows), 'success' => $ok, 'failed' => $fail]);
        exit;
    }

    if ($action === 'create') {
        // 2-step: create config → upload image → คืน richMenuId
        $config = json_decode((string)($_POST['config'] ?? ''), true);
        if (!is_array($config)) {
            echo json_encode(['ok' => false, 'message' => 'config ไม่ใช่ JSON ที่ถูกต้อง']);
            exit;
        }
        if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'message' => 'กรุณาอัพโหลดไฟล์ภาพ']);
            exit;
        }

        $f = $_FILES['image'];
        $mime = mime_content_type($f['tmp_name']) ?: '';
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            echo json_encode(['ok' => false, 'message' => 'รองรับเฉพาะ PNG / JPEG']);
            exit;
        }
        if ((int)$f['size'] > 1024 * 1024) {
            echo json_encode(['ok' => false, 'message' => 'ขนาดไฟล์ต้องไม่เกิน 1 MB']);
            exit;
        }

        // ตรวจขนาดภาพให้ตรงกับ size ใน config (LINE บังคับ)
        [$w, $h] = getimagesize($f['tmp_name']) ?: [0, 0];
        $cfgW = (int)($config['size']['width'] ?? 0);
        $cfgH = (int)($config['size']['height'] ?? 0);
        if ($w !== $cfgW || $h !== $cfgH) {
            echo json_encode(['ok' => false, 'message' => "ขนาดภาพ ($w×$h) ไม่ตรงกับ size ใน config ($cfgW×$cfgH)"]);
            exit;
        }

        // Step 1: create
        $cr = line_richmenu_create($config);
        if (!$cr['ok'] || empty($cr['richMenuId'])) {
            echo json_encode(['ok' => false, 'step' => 'create', 'message' => $cr['error'] ?? 'create ล้มเหลว']);
            exit;
        }
        $rid = $cr['richMenuId'];

        // Step 2: upload
        $up = line_richmenu_upload_image($rid, $f['tmp_name'], $mime);
        if (!$up['ok']) {
            // rollback: ลบ rich menu ที่สร้างไปแล้วเพราะไม่มีภาพ
            line_richmenu_delete($rid);
            echo json_encode([
                'ok' => false,
                'step' => 'upload',
                'http' => $up['http'] ?? 0,
                'message' => $up['error'] ?? 'upload ล้มเหลว',
                'transport' => $up['transport'] ?? '-',
                'verbose' => $up['verbose'] ?? null,
            ]);
            exit;
        }

        log_activity('LINE Rich Menu', "Created richMenuId={$rid} (name=" . ($config['name'] ?? '-') . ")");
        echo json_encode(['ok' => true, 'richMenuId' => $rid, 'message' => 'สร้างสำเร็จ']);
        exit;
    }

    if ($action === 'delete') {
        $rid = trim((string)($_POST['richMenuId'] ?? ''));
        $r = line_richmenu_delete($rid);
        if ($r['ok']) log_activity('LINE Rich Menu', "Deleted richMenuId={$rid}");
        echo json_encode(['ok' => $r['ok'], 'message' => $r['ok'] ? 'ลบแล้ว' : ($r['error'] ?? 'ลบไม่สำเร็จ')]);
        exit;
    }

    if ($action === 'import_detail') {
        // ดึงรายละเอียดของ rich menu ตาม id เพื่อ clone areas/size/name มา fill form
        $rid = trim((string)($_POST['richMenuId'] ?? ''));
        if ($rid === '') { echo json_encode(['ok' => false, 'message' => 'ต้องระบุ richMenuId']); exit; }
        $r = line_richmenu_get_detail($rid);
        if (!$r['ok']) {
            // ถ้า "owned by another channel" — แจ้งผู้ใช้ชัดเจน
            $err = (string)$r['error'];
            if (stripos($err, 'another channel') !== false) {
                echo json_encode([
                    'ok' => false,
                    'http' => $r['http'],
                    'message' => 'Rich menu นี้เป็นของ channel อื่น (Console-managed) — API อ่าน config ไม่ได้ ต้องสร้างใหม่จาก scratch',
                ]);
            } else {
                echo json_encode(['ok' => false, 'http' => $r['http'], 'message' => $r['error'] ?? 'อ่าน detail ไม่ได้']);
            }
            exit;
        }
        echo json_encode(['ok' => true, 'data' => $r['data']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'lookup_default') {
        // ดู ID ของ default rich menu ปัจจุบัน (Console-created ก็จะปรากฏที่นี่ถ้าตั้งเป็น default)
        $r = line_richmenu_get_default();
        echo json_encode(['ok' => $r['ok'], 'richMenuId' => $r['richMenuId'], 'http' => $r['http'], 'message' => $r['ok'] ? 'พบ default richMenuId' : ($r['error'] ?? 'ไม่พบ')]);
        exit;
    }

    if ($action === 'lookup_user') {
        // ดู ID ของ rich menu ที่ผูกกับ user คนหนึ่ง (Console rich menu ที่ user เห็นในมือถือ)
        $uid = trim((string)($_POST['line_user_id'] ?? $_GET['line_user_id'] ?? ''));
        if ($uid === '') { echo json_encode(['ok' => false, 'message' => 'ต้องระบุ lineUserId']); exit; }
        $r = line_richmenu_get_user_linked($uid);
        echo json_encode(['ok' => $r['ok'], 'richMenuId' => $r['richMenuId'], 'http' => $r['http'], 'message' => $r['ok'] ? 'พบ richMenuId' : ($r['error'] ?? 'ไม่พบ')]);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown action']);
} catch (Throwable $e) {
    error_log('[ajax_line_richmenu] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
