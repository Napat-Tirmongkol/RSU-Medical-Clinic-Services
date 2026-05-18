<?php
// consumables/ajax/stock_take_count.php — บันทึกจำนวนนับจริง (per item, AJAX)
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'CSRF token ไม่ถูกต้อง']));
}

$itemId    = (int)($_POST['item_id'] ?? 0);
$actualRaw = $_POST['actual_qty'] ?? '';
$actualQty = $actualRaw === '' ? null : (int)$actualRaw;

if ($itemId <= 0) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'item_id ไม่ถูกต้อง']));
}
if ($actualQty !== null && $actualQty < 0) {
    exit(json_encode(['ok' => false, 'error' => 'จำนวนต้องไม่ติดลบ']));
}

$pdo = db();
try {
    // ตรวจว่ารอบยังเปิดอยู่
    $chk = $pdo->prepare("SELECT t.status FROM consumable_stock_take_items i
        LEFT JOIN consumable_stock_takes t ON t.id = i.stock_take_id WHERE i.id = ?");
    $chk->execute([$itemId]);
    $status = $chk->fetchColumn();
    if (!$status) exit(json_encode(['ok' => false, 'error' => 'ไม่พบรายการ']));
    if ($status === 'closed') exit(json_encode(['ok' => false, 'error' => 'รอบนี้ปิดแล้ว']));

    $newStatus = $actualQty === null ? 'pending' : 'counted';
    $up = $pdo->prepare("UPDATE consumable_stock_take_items
        SET actual_qty = ?, check_status = ?, checked_by = ?, checked_at = NOW()
        WHERE id = ?");
    $up->execute([$actualQty, $newStatus, $_SESSION['user_id'] ?? null, $itemId]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
