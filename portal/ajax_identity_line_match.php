<?php
/**
 * portal/ajax_identity_line_match.php
 * Superadmin-only — หา System Users (sys_users) ที่ผูก LINE ไว้แล้ว เพื่อจับคู่กับ Staff
 *
 * แหล่งอ้างอิง 2 ทาง (เรียงความมั่นใจ):
 *   1) ผังองค์กร (sys_org_members) — สมาชิกที่ "เชื่อมโยงผู้ใช้" (user_id) ไว้แล้ว
 *        bridge ไป staff ด้วย personnel-id:  sys_staff.username = sys_users.student_personnel_id
 *        (fallback: ชื่อตรงกัน) — แล้วดึง sys_users.line_user_id
 *      หมายเหตุ: org chart เก็บแค่ user_id (ไม่เก็บ staff_id) จึงต้อง bridge ผ่าน personnel-id/ชื่อ
 *   2) ค้นตามชื่อ/รหัส/เลขบัตร (fallback) — เผื่อยังไม่ได้จัดผังองค์กร
 *
 * อ่านอย่างเดียว (no write). บันทึกจริงผ่าน save_identity_gov (validate U+32hex + dedupe + audit)
 *
 * GET: staff_id, q (≥2)
 * Security: superadmin เท่านั้น · audit ทุกครั้ง (เปิดเผย LINE UID)
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$adminRole = $_SESSION['admin_role'] ?? '';
if ($adminRole !== 'superadmin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'superadmin เท่านั้น']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'GET only']);
    exit;
}

$q       = trim((string)($_GET['q'] ?? ''));
$staffId = (int)($_GET['staff_id'] ?? 0);

if ($staffId <= 0 && mb_strlen($q) < 2) {
    echo json_encode(['status' => 'success', 'data' => [], 'message' => 'พิมพ์อย่างน้อย 2 ตัวอักษร'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

try {
    $hasPicture = false;
    try {
        $hasPicture = (bool)$pdo->query("SHOW COLUMNS FROM sys_users LIKE 'picture_url'")->fetch();
    } catch (PDOException) {}
    $picOrg  = $hasPicture ? "u.picture_url" : "'' AS picture_url";
    $picName = $hasPicture ? "picture_url"   : "'' AS picture_url";

    $merged = [];   // user_id => row (+ _source, org_position)
    $order  = [];

    // ── 1) ผังองค์กร — bridge ผ่าน personnel-id / ชื่อ (org chart เก็บแค่ user_id) ──
    if ($staffId > 0) {
        try {
            $st = $pdo->prepare("SELECT username, full_name FROM sys_staff WHERE id = ? LIMIT 1");
            $st->execute([$staffId]);
            $staff = $st->fetch(PDO::FETCH_ASSOC) ?: ['username' => '', 'full_name' => ''];
            $uname = trim((string)($staff['username'] ?? ''));
            $fname = trim((string)($staff['full_name'] ?? ''));

            $orgSql = "SELECT u.id, u.full_name, u.student_personnel_id, u.status, {$picOrg}, u.line_user_id,
                              COALESCE(p.title, '') AS org_position
                       FROM sys_org_members m
                       JOIN sys_users u ON u.id = m.user_id
                       LEFT JOIN sys_org_positions p ON p.id = m.position_id
                       WHERE m.is_active = 1 AND m.user_id IS NOT NULL
                         AND u.line_user_id IS NOT NULL AND u.line_user_id <> ''
                         AND ( (:uname <> '' AND u.student_personnel_id = :uname2)
                               OR (:fname <> '' AND u.full_name = :fname2)
                               OR (:fname3 <> '' AND m.full_name = :fname4) )
                       ORDER BY (u.student_personnel_id = :uname3) DESC, m.display_order ASC";
            $os = $pdo->prepare($orgSql);
            $os->execute([
                ':uname' => $uname, ':uname2' => $uname, ':uname3' => $uname,
                ':fname' => $fname, ':fname2' => $fname, ':fname3' => $fname, ':fname4' => $fname,
            ]);
            foreach ($os->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $uid = (int)$r['id'];
                if (isset($merged[$uid])) continue;
                $r['_source'] = 'org_chart';
                $merged[$uid] = $r;
                $order[] = $uid;
            }
        } catch (PDOException $e) {
            error_log('[ajax_identity_line_match] org lookup skipped: ' . $e->getMessage());
        }
    }

    // ── 2) ค้นหาตามชื่อ/รหัส/เลขบัตร (fallback) ──
    if (mb_strlen($q) >= 2) {
        $like = '%' . $q . '%';
        $nameSql = "SELECT id, full_name, student_personnel_id, status, {$picName}, line_user_id,
                           '' AS org_position
                    FROM sys_users
                    WHERE line_user_id IS NOT NULL AND line_user_id <> ''
                      AND (full_name LIKE :q1 OR student_personnel_id LIKE :q2 OR citizen_id LIKE :q3)
                    ORDER BY full_name ASC
                    LIMIT 20";
        $ns = $pdo->prepare($nameSql);
        $ns->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
        foreach ($ns->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['id'];
            if (isset($merged[$uid])) continue;
            $r['_source'] = 'name';
            $merged[$uid] = $r;
            $order[] = $uid;
        }
    }

    // ── 3) เติม valid_format + linked_to ──
    $dupStmt = $pdo->prepare("SELECT full_name FROM sys_staff WHERE linked_line_user_id = ? AND id <> ? LIMIT 1");
    $out = [];
    foreach ($order as $uid) {
        $r           = $merged[$uid];
        $lineUid     = (string)$r['line_user_id'];
        $validFormat = (bool)preg_match('/^U[0-9a-f]{32}$/', $lineUid);
        $linkedTo    = null;
        if ($validFormat) {
            $dupStmt->execute([$lineUid, $staffId]);
            $linkedTo = $dupStmt->fetchColumn() ?: null;
        }
        $out[] = [
            'user_id'      => (int)$r['id'],
            'full_name'    => (string)$r['full_name'],
            'personnel_id' => (string)($r['student_personnel_id'] ?? ''),
            'status'       => (string)($r['status'] ?? ''),
            'picture_url'  => (string)($r['picture_url'] ?? ''),
            'line_user_id' => $lineUid,
            'valid_format' => $validFormat,
            'linked_to'    => $linkedTo,
            'source'       => (string)$r['_source'],
            'org_position' => (string)($r['org_position'] ?? ''),
        ];
    }

    if (function_exists('log_activity')) {
        log_activity('identity_line_match',
            'q=' . mb_substr($q, 0, 64) . ' staff_id=' . $staffId . ' rows=' . count($out));
    }

    echo json_encode(['status' => 'success', 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[ajax_identity_line_match] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'ระบบขัดข้อง'], JSON_UNESCAPED_UNICODE);
}
