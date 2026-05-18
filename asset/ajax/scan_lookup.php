<?php
// asset/ajax/scan_lookup.php — ค้นหา asset จาก asset_code (สำหรับ scan)
require_once __DIR__ . '/../includes/check_session_ajax.php';

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    echo json_encode(['ok' => false, 'message' => 'ไม่มีรหัส']);
    exit;
}

try {
    $pdo = db();
    // ค้นหาจาก asset_code, rsu_asset_code, serial_number
    $stmt = $pdo->prepare(
        "SELECT id, asset_code, name FROM assets
         WHERE asset_code = ? OR rsu_asset_code = ? OR serial_number = ?
         LIMIT 1"
    );
    $stmt->execute([$code, $code, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['ok' => false, 'message' => 'ไม่พบครุภัณฑ์รหัสนี้']);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => (int)$row['id'], 'asset_code' => $row['asset_code'], 'name' => $row['name']]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'ระบบขัดข้อง กรุณาลองอีกครั้ง']);
}
