<?php
/**
 * portal/ajax_identity_line_match.php
 * Superadmin-only — ค้นหา System Users (sys_users) ที่ผูก LINE ไว้แล้ว เพื่อจับคู่กับ Staff
 *
 * ใช้คู่กับ Staff governance modal: admin เลือกผู้ใช้ที่ "ชื่อตรงกัน" → ระบบดึง line_user_id
 * มาเติมช่อง #govLinkedLineUid → บันทึกจริงผ่าน save_identity_gov (identity_actions.php)
 * ซึ่ง validate format (U+32hex) + dedupe + audit log อยู่แล้ว
 *
 * อ่านอย่างเดียว (no write).
 *
 * GET params:
 *   q        — คำค้น (ชื่อ / รหัสบุคลากร-นักศึกษา / เลขบัตร) ; ขั้นต่ำ 2 ตัวอักษร
 *   staff_id — (optional) id ของ sys_staff ปลายทาง — เพื่อยกเว้นตัวเองตอนเช็ค UID ซ้ำ
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

if (mb_strlen($q) < 2) {
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
    $picSel = $hasPicture ? "picture_url" : "'' AS picture_url";

    // เฉพาะผู้ใช้ที่ผูก LINE แล้ว (line_user_id ไม่ว่าง) และตรงคำค้น
    $like = '%' . $q . '%';
    $sql = "SELECT id, full_name, student_personnel_id, status, {$picSel}, line_user_id
            FROM sys_users
            WHERE line_user_id IS NOT NULL AND line_user_id <> ''
              AND (full_name LIKE :q1 OR student_personnel_id LIKE :q2 OR citizen_id LIKE :q3)
            ORDER BY full_name ASC
            LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // เช็คว่า line_user_id แต่ละตัวถูกผูกกับ staff อื่นแล้วหรือยัง
    // (กัน admin งงตอนกดบันทึกแล้วโดน dedupe เด้งกลับ)
    $dupStmt = $pdo->prepare("SELECT full_name FROM sys_staff WHERE linked_line_user_id = ? AND id <> ? LIMIT 1");

    $out = [];
    foreach ($rows as $r) {
        $uid         = (string)$r['line_user_id'];
        $validFormat = (bool)preg_match('/^U[0-9a-f]{32}$/', $uid);
        $linkedTo    = null;
        if ($validFormat) {
            $dupStmt->execute([$uid, $staffId]);
            $linkedTo = $dupStmt->fetchColumn() ?: null;
        }
        $out[] = [
            'user_id'      => (int)$r['id'],
            'full_name'    => (string)$r['full_name'],
            'personnel_id' => (string)($r['student_personnel_id'] ?? ''),
            'status'       => (string)($r['status'] ?? ''),
            'picture_url'  => (string)($r['picture_url'] ?? ''),
            'line_user_id' => $uid,
            'valid_format' => $validFormat,
            'linked_to'    => $linkedTo,   // ชื่อ staff ที่ผูก UID นี้อยู่ (ถ้ามี)
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
