<?php
include('../includes/check_session_ajax.php');
require_once(__DIR__ . '/../../../config/db_connect.php');
require_once('../includes/log_function.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ดำเนินการ']);
    exit;
}
header('Content-Type: application/json');

$response = ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ'];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $item_id = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $type_id = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0; // (เราส่ง type_id มาด้วย)

    if ($item_id == 0 || $type_id == 0) {
        $response['message'] = 'ID อุปกรณ์ หรือ ID ประเภท ไม่ถูกต้อง';
        echo json_encode($response);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. ดึงข้อมูลสถานะของ Item ที่จะลบ
        $stmt_get = $pdo->prepare("SELECT status FROM borrow_items WHERE id = ? AND type_id = ?");
        $stmt_get->execute([$item_id, $type_id]);
        $item = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new Exception("ไม่พบอุปกรณ์ชิ้นนี้");
        }

        if ($item['status'] == 'borrowed') {
            throw new Exception("ไม่สามารถลบอุปกรณ์ที่กำลังถูกยืมได้");
        }

        // 1b. ตรวจสอบประวัติการยืม (FK constraint กับ borrow_records)
        $stmt_check_records = $pdo->prepare("SELECT COUNT(*) FROM borrow_records WHERE equipment_id = ?");
        $stmt_check_records->execute([$item_id]);
        if ((int)$stmt_check_records->fetchColumn() > 0) {
            throw new Exception("ไม่สามารถลบอุปกรณ์ได้ เนื่องจากมีประวัติการยืมที่เชื่อมโยงอยู่");
        }

        // 2. ดำเนินการลบ
        $stmt_delete = $pdo->prepare("DELETE FROM borrow_items WHERE id = ?");
        $stmt_delete->execute([$item_id]);

        if ($stmt_delete->rowCount() > 0) {
            
            // 3. อัปเดตจำนวนใน borrow_categories
            // (ถ้าลบของที่ 'available' ให้ลดทั้ง total และ available)
            if ($item['status'] == 'available') {
                $sql_update_type = "UPDATE borrow_categories SET total_quantity = total_quantity - 1, available_quantity = available_quantity - 1 WHERE id = ? AND total_quantity > 0 AND available_quantity > 0";
            }
            // (ถ้าลบของที่ 'maintenance' ให้ลดแค่ total)
            else {
                $sql_update_type = "UPDATE borrow_categories SET total_quantity = total_quantity - 1 WHERE id = ? AND total_quantity > 0";
            }
            $stmt_update = $pdo->prepare($sql_update_type);
            $stmt_update->execute([$type_id]);

            // 4. บันทึก Log
            $admin_user_id = $_SESSION['user_id'] ?? null;
            $admin_user_name = $_SESSION['full_name'] ?? 'System';
            $log_desc = "Admin '{$admin_user_name}' ได้ลบอุปกรณ์ (ItemID: {$item_id}) ออกจากประเภท (TypeID: {$type_id})";
            log_action($pdo, $admin_user_id, 'delete_equipment_item', $log_desc);

            $pdo->commit();
            $response['status'] = 'success';
            $response['message'] = 'ลบอุปกรณ์สำเร็จ';
            
        } else {
            throw new Exception("ลบข้อมูลไม่สำเร็จ (rowCount = 0)");
        }

    } catch (Throwable $e) {
        $pdo->rollBack();
        $response['message'] = $e->getMessage();
    }
}

echo json_encode($response);
exit;
?>