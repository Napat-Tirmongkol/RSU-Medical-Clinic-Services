<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/maintenance_helper.php';

header('Content-Type: application/json');

$ALLOWED_PROJECTS = ['e_campaign', 'e_borrow', 'gold_card_apply'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// GET: ดึงสถานะทั้งหมด
if ($action === 'get') {
    echo json_encode(['ok' => true, 'data' => maint_load()]);
    exit;
}

// POST: อัปเดตสถานะโปรเจกต์
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set') {
    validate_csrf_or_die();

    $project = trim($_POST['project'] ?? '');
    $active  = filter_var($_POST['active'] ?? '1', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if (!in_array($project, $ALLOWED_PROJECTS, true) || $active === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'invalid input']);
        exit;
    }

    $data = maint_load();
    $data[$project] = $active;
    maint_save($data);

    $label = $active ? 'เปิดใช้งาน' : 'ปิดปรับปรุง';
    log_activity('Maintenance Toggle', "$project → $label");

    echo json_encode(['ok' => true, 'project' => $project, 'active' => $active]);
    exit;
}

// POST: อัปเดตข้อความประกาศ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_announcement') {
    validate_csrf_or_die();

    $message = trim($_POST['message'] ?? '');
    $active  = (bool)($_POST['active'] ?? false);

    $data = maint_load();
    $data['announcement_message'] = $message;
    $data['announcement_active']  = $active;
    maint_save($data);

    log_activity('Maintenance Announcement', ($active ? "เปิดประกาศ: $message" : "ปิดประกาศ"));

    echo json_encode(['ok' => true, 'message' => 'บันทึกประกาศเรียบร้อย']);
    exit;
}

// POST: อัปเดต Whitelist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_whitelist') {
    validate_csrf_or_die();

    $idsText = trim($_POST['ids'] ?? '');
    $whitelist = array_filter(array_map('trim', preg_split('/[\n,]+/', $idsText)));

    $data = maint_load();
    $data['whitelist'] = array_values(array_unique($whitelist));
    maint_save($data);

    log_activity('Maintenance Whitelist', "อัปเดตรายชื่อผู้ได้รับอนุญาต (" . count($whitelist) . " รายการ)");

    echo json_encode(['ok' => true, 'message' => 'อัปเดต Whitelist เรียบร้อย']);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'message' => 'bad request']);
