<?php
// consumables/ajax/delete_consumable.php — ลบวัสดุ + ประวัติ + ไฟล์รูป
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
csm_require_manage();

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('CSRF token ไม่ถูกต้อง');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('id ไม่ถูกต้อง');
}

$pdo = db();
try {
    $pdo->beginTransaction();

    // หาไฟล์รูปก่อนลบ
    $imgStmt = $pdo->prepare("SELECT image FROM consumables WHERE id = ?");
    $imgStmt->execute([$id]);
    $image = $imgStmt->fetchColumn();

    // ลบ transactions ก่อน
    $pdo->prepare("DELETE FROM consumable_transactions WHERE consumable_id = ?")->execute([$id]);

    // ลบ consumable
    $del = $pdo->prepare("DELETE FROM consumables WHERE id = ?");
    $del->execute([$id]);

    $pdo->commit();

    // ลบไฟล์รูป (เผื่ออัปโหลดไว้)
    if ($image && str_starts_with($image, 'uploads/')) {
        $abs = __DIR__ . '/../' . $image;
        if (is_file($abs)) @unlink($abs);
    }

    header('Location: ../admin/manage_consumables.php?deleted=1');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit('ลบไม่สำเร็จ: ' . $e->getMessage());
}
