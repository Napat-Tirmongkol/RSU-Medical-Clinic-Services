<?php
// ajax/get_items_for_approve.php
require_once('../includes/check_session_ajax.php'); // ตรวจสอบ Session
require_once(__DIR__ . '/../../config/db_connect.php');

header('Content-Type: application/json');

if (!isset($_GET['transaction_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ Transaction ID']);
    exit;
}

$trans_id = $_GET['transaction_id'];

try {
    // 1. ดูว่าคำขอนี้ จองอุปกรณ์ประเภทไหน (Type ID) และตัวเดิมคือ ID อะไร
    $stmt = $pdo->prepare("SELECT type_id, item_id FROM borrow_records WHERE id = ?");
    $stmt->execute([$trans_id]);
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trans) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลคำขอ']);
        exit;
    }

    $type_id = $trans['type_id'];
    $current_item_id = $trans['item_id'];

    // 2. ดึงรายการของที่ "ว่าง" (Available) หรือ "ตัวที่จองอยู่" (เพื่อไม่ให้ตัวมันเองหายไปจาก list)
    $sql = "SELECT id, serial_number 
            FROM borrow_items 
            WHERE type_id = ? 
            AND (status = 'available' OR id = ?)";
            
    $stmt_items = $pdo->prepare($sql);
    $stmt_items->execute([$type_id, $current_item_id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'items' => $items]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>