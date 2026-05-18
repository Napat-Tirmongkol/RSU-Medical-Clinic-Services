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
        $guest    = trim((string)($_POST['guest_id']  ?? ''));
        $member   = trim((string)($_POST['member_id'] ?? ''));
        $skipVerify = !empty($_POST['skip_verify']);

        // 1) Format check — ทั้งคู่ต้องตรงรูปแบบ "richmenu-" + 32 hex (หรือเว้นไว้ก็ได้)
        if (!line_richmenu_validate_id_format($guest)) {
            echo json_encode(['ok' => false, 'field' => 'guest', 'message' => 'รูปแบบ guest richMenuId ไม่ถูกต้อง — ต้องขึ้นต้นด้วย "richmenu-" แล้วตามด้วย 32 hex characters']);
            exit;
        }
        if (!line_richmenu_validate_id_format($member)) {
            echo json_encode(['ok' => false, 'field' => 'member', 'message' => 'รูปแบบ member richMenuId ไม่ถูกต้อง — ต้องขึ้นต้นด้วย "richmenu-" แล้วตามด้วย 32 hex characters']);
            exit;
        }

        // 2) Verify ID มีอยู่จริงบน LINE (ข้ามได้ถ้า admin ติ๊ก skip_verify)
        $warnings = [];
        if (!$skipVerify) {
            if ($guest !== '') {
                $vg = line_richmenu_verify_id_exists($guest);
                if (!$vg['ok']) {
                    echo json_encode(['ok' => false, 'field' => 'guest', 'message' => 'guest: '.$vg['error']]);
                    exit;
                }
                $warnings[] = "guest=$guest (" . ($vg['name'] ?: '-') . ")";
            }
            if ($member !== '') {
                $vm = line_richmenu_verify_id_exists($member);
                if (!$vm['ok']) {
                    echo json_encode(['ok' => false, 'field' => 'member', 'message' => 'member: '.$vm['error']]);
                    exit;
                }
                $warnings[] = "member=$member (" . ($vm['name'] ?: '-') . ")";
            }
        }

        $ok = line_richmenu_save_ids($guest, $member);
        if ($ok) log_activity('LINE Rich Menu', 'อัปเดต IDs: ' . implode(' · ', $warnings ?: ["guest=$guest", "member=$member"]));
        echo json_encode([
            'ok'      => $ok,
            'message' => $ok ? 'บันทึกแล้ว' . ($warnings ? ' · ตรวจกับ LINE แล้ว' : '') : 'บันทึกไม่สำเร็จ',
        ]);
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
            $r = line_richmenu_unlink_user($uid, 'admin:test_unlink');
            echo json_encode(['ok' => $r['ok'], 'state' => 'unlinked', 'message' => $r['ok'] ? 'ลบ link → user จะเห็น default' : ($r['error'] ?? 'ล้มเหลว')]);
            exit;
        }

        $force = null;
        if ($mode === 'guest')  $force = false;
        if ($mode === 'member') $force = true;

        $r = line_richmenu_sync_user($uid, $force, 'admin:test_sync');
        echo json_encode(['ok' => $r['ok'], 'state' => $r['state'], 'message' => $r['ok'] ? "Linked → {$r['state']}" : ($r['error'] ?? 'ล้มเหลว')]);
        exit;
    }

    if ($action === 'sync_all') {
        // Chunked sync — UI calls repeatedly with ?offset=N until done=true
        // Avoids long-running request + lets UI show progress %
        $ids = line_richmenu_get_ids();
        if ($ids['member'] === '') {
            echo json_encode(['ok' => false, 'message' => 'ยังไม่ได้ตั้ง member richMenuId']);
            exit;
        }
        $pdo = db();
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $batch  = 50; // sync 50 users per call

        $totalRow = $pdo->query("SELECT COUNT(DISTINCT COALESCE(line_user_id_new, line_user_id))
                                 FROM sys_users
                                 WHERE (line_user_id IS NOT NULL AND line_user_id != '')
                                    OR (line_user_id_new IS NOT NULL AND line_user_id_new != '')");
        $total = (int)($totalRow->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT DISTINCT COALESCE(line_user_id_new, line_user_id) AS uid
                               FROM sys_users
                               WHERE (line_user_id IS NOT NULL AND line_user_id != '')
                                  OR (line_user_id_new IS NOT NULL AND line_user_id_new != '')
                               ORDER BY id ASC
                               LIMIT $batch OFFSET $offset");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $ok = 0; $fail = 0;
        foreach ($rows as $uid) {
            if (!$uid) continue;
            $r = line_richmenu_link_user((string)$uid, $ids['member']);
            $r['ok'] ? $ok++ : $fail++;
            line_richmenu_audit_log(
                (string)$uid,
                $r['ok'] ? 'sync_ok' : 'sync_failed',
                'member',
                $ids['member'],
                $r['ok'] ? null : $r['error'],
                'admin:sync_all'
            );
        }

        $processedAfter = $offset + count($rows);
        $done = $processedAfter >= $total || count($rows) < $batch;
        if ($done) {
            log_activity('LINE Rich Menu', "Sync all → member (chunked done): total=$total");
        }
        echo json_encode([
            'ok'        => true,
            'total'     => $total,
            'processed' => $processedAfter,
            'batch_ok'  => $ok,
            'batch_fail'=> $fail,
            'done'      => $done,
            'next_offset' => $done ? null : $processedAfter,
        ]);
        exit;
    }

    if ($action === 'audit_recent') {
        // ดู log การ link/unlink 50 รายการล่าสุด — เปิด details ใน UI
        try {
            $pdo = db();
            // Try to create table (idempotent — same definition as helper)
            $pdo->exec("CREATE TABLE IF NOT EXISTS sys_line_richmenu_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                line_user_id VARCHAR(64) NOT NULL,
                action VARCHAR(20) NOT NULL,
                state VARCHAR(20) NOT NULL DEFAULT '',
                rich_menu_id VARCHAR(80) NOT NULL DEFAULT '',
                source VARCHAR(40) NOT NULL DEFAULT '',
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_line_user (line_user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $rows = $pdo->query("SELECT line_user_id, action, state, rich_menu_id, source, error_message, created_at
                                 FROM sys_line_richmenu_audit
                                 ORDER BY id DESC
                                 LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['ok' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[ajax_line_richmenu audit] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'message' => 'อ่าน audit log ไม่ได้']);
        }
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
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
