<?php
/**
 * insurance_partner/ajax_return_batch.php
 * Partner ตีเอกสารกลับ — ส่ง batch คืนคลินิกเพื่อตรวจสอบใหม่
 * เปลี่ยน status → pending_review พร้อม event log
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_ins_partner_login();
require_once __DIR__ . '/../portal/includes/insurance_batch.php';

header('Content-Type: application/json; charset=utf-8');

$partner     = current_ins_partner();
$companyCode = $partner['company_code'];
$pdo         = db();

$id     = (int)($_POST['id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}
if ($reason === '') {
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุเหตุผลการตีเอกสารกลับ']);
    exit;
}

// ตรวจสอบ ownership และดึง batch
$stmt = $pdo->prepare("SELECT * FROM insurance_batch WHERE id = :id AND insurance_company = :cc");
$stmt->execute([':id' => $id, ':cc' => $companyCode]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบเอกสาร']);
    exit;
}

$returnableStatuses = ['approved', 'downloaded', 'in_progress', 'partial'];
if (!in_array($batch['status'], $returnableStatuses, true)) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถตีเอกสารกลับในสถานะ "' . $batch['status'] . '" ได้']);
    exit;
}

// เปลี่ยนสถานะกลับเป็น pending_review + บันทึกเหตุผล
$pdo->prepare("
    UPDATE insurance_batch
    SET status      = 'pending_review',
        review_note = :note
    WHERE id = :id
")->execute([
    ':note' => 'ตีเอกสารกลับโดย Partner (' . $partner['full_name'] . '): ' . $reason,
    ':id'   => $id,
]);

ins_batch_log_event(
    $pdo,
    $id,
    'partner_returned',
    $batch['status'],
    'pending_review',
    'partner',
    $partner['id'],
    $partner['full_name'],
    $reason
);

ins_partner_log('return_batch', "batch_id={$id}, batch_code={$batch['batch_code']}, reason=" . mb_substr($reason, 0, 200));

echo json_encode(['status' => 'ok', 'message' => 'ตีเอกสารกลับเรียบร้อย']);
