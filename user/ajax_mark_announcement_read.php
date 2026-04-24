<?php
/**
 * user/ajax_mark_announcement_read.php
 * รับ POST เมื่อ User กด "รับทราบ" แล้วบันทึกว่าอ่านแล้ว
 */
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

// ── ตรวจสอบ Session ─────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../config.php';
validate_csrf_or_die();

$announcementId = (int)($_POST['announcement_id'] ?? 0);
$userId         = (int)$_SESSION['user_id'];

if ($announcementId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid announcement ID']);
    exit;
}

try {
    $pdo = db();

    // ตรวจว่าประกาศนี้มีอยู่จริงและยัง active
    $check = $pdo->prepare("SELECT id FROM sys_announcements WHERE id = ? AND is_active = 1 LIMIT 1");
    $check->execute([$announcementId]);
    if (!$check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Announcement not found']);
        exit;
    }

    // INSERT IGNORE เพื่อป้องกันข้อมูลซ้ำ (UNIQUE constraint)
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO sys_announcement_reads (announcement_id, user_id)
        VALUES (:aid, :uid)
    ");
    $stmt->execute([':aid' => $announcementId, ':uid' => $userId]);

    echo json_encode(['status' => 'ok']);
} catch (PDOException $e) {
    error_log("Mark announcement read error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error']);
}
