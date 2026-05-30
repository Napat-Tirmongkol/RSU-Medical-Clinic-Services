<?php
/**
 * portal/ajax_identity_line_match.php
 * Superadmin-only — หา System Users (sys_users) ที่ผูก LINE ไว้แล้ว เพื่อจับคู่กับ Staff
 *
 * แหล่งอ้างอิง 2 ทาง (เรียงความมั่นใจ):
 *   1) ผังองค์กร (deterministic) — sys_staff.id → sys_org_members.staff_id → user_id
 *        → sys_users.line_user_id   (admin จับคู่ไว้แล้วในผังองค์กร = แม่นสุด)
 *   2) ค้นตามชื่อ/รหัส/เลขบัตร (fallback) — เผื่อยังไม่ได้จัดผังองค์กร
 *
 * ดึง line_user_id มาเติมช่อง #govLinkedLineUid → บันทึกจริงผ่าน save_identity_gov
 * (identity_actions.php) ซึ่ง validate format (U+32hex) + dedupe + audit log อยู่แล้ว
 *
 * อ่านอย่างเดียว (no write).
 *
 * GET params:
 *   staff_id — id ของ sys_staff ปลายทาง (ใช้ดึงจากผังองค์กร + ยกเว้นตัวเองตอนเช็ค UID ซ้ำ)
 *   q        — คำค้น (ชื่อ / รหัสบุคลากร-นักศึกษา / เลขบัตร) ; ขั้นต่ำ 2 ตัวอักษร
 *
 * Security: superadmin เท่านั้น (การเขียน linked_line_user_id เป็น superadmin-only เช่นกัน)
 *           response มี LINE UID (forensic identifier) → จึง gate แน่นและ audit ทุกครั้ง
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

// ต้องมีอย่างน้อย: staff_id (ดึงจากผังองค์กร) หรือคำค้น q ≥ 2 ตัว
if ($staffId <= 0 && mb_strlen($q) < 2) {
    echo json_encode(['status' => 'success', 'data' => [], 'message' => 'พิมพ์อย่างน้อย 2 ตัวอักษร'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();

try {
    // picture_url อาจยังไม่มีใน install เก่า — ตรวจก่อนเพื่อกัน SQL error
    $hasPicture = false;
    try {
        $hasPicture = (bool)$pdo->query("SHOW COLUMNS FROM sys_users LIKE 'picture_url'")->fetch();
    } catch (PDOException) {}
    $picOrg  = $hasPicture ? "u.picture_url" : "'' AS picture_url";
    $picName = $hasPicture ? "picture_url"   : "'' AS picture_url";

    $merged = [];   // user_id => row (+ _source, org_position)
    $order  = [];   // รักษาลำดับ: org chart ก่อน, name ทีหลัง

    // ── 1) ผังองค์กร (deterministic) ─────────────────────────────────────────
    if ($staffId > 0) {
        try {
            $orgSql = "SELECT u.id, u.full_name, u.student_personnel_id, u.status, {$picOrg}, u.line_user_id,
                              COALESCE(p.title, '') AS org_position
                       FROM sys_org_members m
                       JOIN sys_users u ON u.id = m.user_id
                       LEFT JOIN sys_org_positions p ON p.id = m.position_id
                       WHERE m.staff_id = :sid AND m.is_active = 1
                         AND u.line_user_id IS NOT NULL AND u.line_user_id <> ''
                       ORDER BY m.display_order ASC, m.id ASC";
            $os = $pdo->prepare($orgSql);
            $os->execute([':sid' => $staffId]);
            foreach ($os->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $uid = (int)$r['id'];
                if (isset($merged[$uid])) continue;
                $r['_source'] = 'org_chart';
                $merged[$uid] = $r;
                $order[] = $uid;
            }
        } catch (PDOException $e) {
            // ตารางผังองค์กรอาจยังไม่ถูกสร้าง — ข้ามไปใช้ name search
            error_log('[ajax_identity_line_match] org lookup skipped: ' . $e->getMessage());
        }
    }

    // ── 2) ค้นหาตามชื่อ/รหัส/เลขบัตร (fallback / เพิ่มเติม) ───────────────────
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
            if (isset($merged[$uid])) continue;   // org chart ชนะ (มาก่อนแล้ว)
            $r['_source'] = 'name';
            $merged[$uid] = $r;
            $order[] = $uid;
        }
    }

    // ── 3) เติม valid_format + linked_to (กัน dedupe เด้งตอนบันทึก) ──────────
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
            'linked_to'    => $linkedTo,            // ชื่อ staff ที่ผูก UID นี้อยู่ (ถ้ามี)
            'source'       => (string)$r['_source'], // 'org_chart' | 'name'
            'org_position' => (string)($r['org_position'] ?? ''),
        ];
    }

    // Audit — เปิดเผย LINE UID จึงต้องทิ้งร่องรอยทุกครั้ง (PDPA Art. 39)
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
