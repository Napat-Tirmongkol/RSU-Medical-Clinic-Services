<?php
// asset/ajax/stock_take_check.php — บันทึกผลการตรวจรายชิ้น
require_once __DIR__ . '/../includes/check_session_ajax.php';
require_once __DIR__ . '/../includes/helpers.php';

asset_validate_csrf_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'method_not_allowed']);
    exit;
}

$itemId  = (int)($_POST['item_id'] ?? 0);
$status  = (string)($_POST['status'] ?? '');
$foundLoc = (int)($_POST['found_location_id'] ?? 0) ?: null;
$note     = trim((string)($_POST['note'] ?? '')) ?: null;

$allowed = ['pending', 'found', 'not_found', 'wrong_location', 'damaged'];
if ($itemId <= 0 || !in_array($status, $allowed, true)) {
    echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}
if ($status === 'wrong_location' && !$foundLoc) {
    echo json_encode(['ok' => false, 'message' => 'wrong_location ต้องระบุจุดที่เจอจริง']);
    exit;
}

try {
    $pdo = db();

    // เช็ครอบยังไม่ปิด
    $chk = $pdo->prepare("SELECT t.status FROM asset_stock_take_items i
                           JOIN asset_stock_takes t ON t.id = i.stock_take_id
                           WHERE i.id = ?");
    $chk->execute([$itemId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok' => false, 'message' => 'ไม่พบรายการ']);
        exit;
    }
    if ($row['status'] === 'closed') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'รอบนี้ปิดแล้ว แก้ไขไม่ได้']);
        exit;
    }

    $stmt = $pdo->prepare(
        "UPDATE asset_stock_take_items
         SET found_status = ?, found_location_id = ?, note = ?, checked_by = ?, checked_at = NOW()
         WHERE id = ?"
    );
    $stmt->execute([$status, $foundLoc, $note, $_SESSION['user_id'] ?? null, $itemId]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'ระบบขัดข้อง กรุณาลองอีกครั้ง']);
}
