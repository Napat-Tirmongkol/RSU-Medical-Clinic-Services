<?php
// asset/ajax/scan_mark.php — Mark stock-take item by scanning asset_code
require_once __DIR__ . '/../includes/check_session_ajax.php';
require_once __DIR__ . '/../includes/helpers.php';

asset_validate_csrf_ajax();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'method_not_allowed']);
    exit;
}

$code   = trim((string)($_POST['asset_code'] ?? ''));
$takeId = (int)($_POST['stock_take_id'] ?? 0);
$status = (string)($_POST['status'] ?? 'found');

if ($code === '' || $takeId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'ข้อมูลไม่ครบ']);
    exit;
}
if (!in_array($status, ['found', 'not_found', 'wrong_location', 'damaged'], true)) {
    echo json_encode(['ok' => false, 'message' => 'สถานะไม่ถูกต้อง']);
    exit;
}

try {
    $pdo = db();

    // 1. รอบยังเปิดอยู่ไหม
    $chk = $pdo->prepare("SELECT status FROM asset_stock_takes WHERE id = ?");
    $chk->execute([$takeId]);
    $takeStatus = $chk->fetchColumn();
    if (!$takeStatus) { echo json_encode(['ok' => false, 'message' => 'ไม่พบรอบตรวจนับ']); exit; }
    if ($takeStatus === 'closed') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'รอบนี้ปิดแล้ว']);
        exit;
    }

    // 2. หา asset
    $a = $pdo->prepare(
        "SELECT id, name FROM assets
         WHERE asset_code = ? OR rsu_asset_code = ? OR serial_number = ?
         LIMIT 1"
    );
    $a->execute([$code, $code, $code]);
    $asset = $a->fetch(PDO::FETCH_ASSOC);
    if (!$asset) {
        echo json_encode(['ok' => false, 'message' => "ไม่พบครุภัณฑ์รหัส {$code}"]);
        exit;
    }

    // 3. หา stock_take_item
    $i = $pdo->prepare("SELECT id, found_status FROM asset_stock_take_items WHERE stock_take_id = ? AND asset_id = ?");
    $i->execute([$takeId, (int)$asset['id']]);
    $item = $i->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        echo json_encode([
            'ok' => false,
            'message' => 'ครุภัณฑ์นี้ไม่อยู่ในรอบ',
            'name' => $asset['name'],
        ]);
        exit;
    }

    // 4. update
    $upd = $pdo->prepare(
        "UPDATE asset_stock_take_items
         SET found_status = ?, checked_by = ?, checked_at = NOW()
         WHERE id = ?"
    );
    $upd->execute([$status, $_SESSION['user_id'] ?? null, (int)$item['id']]);

    echo json_encode([
        'ok'         => true,
        'name'       => $asset['name'],
        'message'    => 'บันทึก "' . ($status === 'found' ? 'เจอ' : $status) . '" สำเร็จ',
        'was_status' => $item['found_status'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'ระบบขัดข้อง กรุณาลองอีกครั้ง']);
}
