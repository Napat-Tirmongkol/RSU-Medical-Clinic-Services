<?php
/**
 * insurance_partner/export.php
 * - GET (ปกติ)            → แสดงหน้าเลือก export + preview ข้อมูล
 * - GET ?download=csv      → stream CSV รายชื่อ Active ของบริษัทนี้
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_ins_partner_login();
require_once __DIR__ . '/../portal/includes/insurance_batch.php';

$partner = current_ins_partner();
$companyCode = $partner['company_code'];
$pdo = db();

// ── Mode: download CSV ────────────────────────────────────────────────────────
if (($_GET['download'] ?? '') === 'csv') {
    $onlyMissing = !empty($_GET['only_missing_policy']);
    $batchId = (int)($_GET['batch_id'] ?? 0);

    // Batch-scoped download (preferred): verify batch exists, belongs to company,
    // and has been approved by clinic
    $batch = null;
    if ($batchId > 0) {
        $bStmt = $pdo->prepare("SELECT * FROM insurance_batch WHERE id = :id AND insurance_company = :cc");
        $bStmt->execute([':id' => $batchId, ':cc' => $companyCode]);
        $batch = $bStmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            http_response_code(404);
            exit('ไม่พบเอกสาร batch นี้ หรือไม่ใช่ของบริษัทคุณ');
        }
        if (!in_array($batch['status'], ['approved', 'downloaded', 'in_progress', 'partial'], true)) {
            http_response_code(403);
            exit('เอกสารนี้ยังไม่ได้รับการอนุมัติจาก RSU Medical Clinic (สถานะ: ' . htmlspecialchars($batch['status']) . ')');
        }
    }

    if ($batch) {
        // Members in this specific batch
        $where = "m.insurance_company = :cc
                  AND m.member_id IN (
                      SELECT DISTINCT member_id FROM insurance_member_history WHERE sync_id = :sid
                  )";
        $params = [':cc' => $companyCode, ':sid' => (int)$batch['sync_id']];
        if ($onlyMissing) {
            $where .= " AND (m.policy_number IS NULL OR m.policy_number = '')";
        }
        $sql = "
            SELECT m.member_id, m.full_name, m.member_status, m.position, m.citizen_id, m.date_of_birth,
                   m.insurance_status, m.coverage_start, m.coverage_end, m.policy_number, m.remarks, m.updated_at
            FROM insurance_members m
            WHERE $where
            ORDER BY m.full_name ASC
        ";
    } else {
        // Legacy: full company export (no batch scope)
        $where = "insurance_company = :cc AND insurance_status = 'Active'";
        $params = [':cc' => $companyCode];
        if ($onlyMissing) {
            $where .= " AND (policy_number IS NULL OR policy_number = '')";
        }
        $sql = "
            SELECT member_id, full_name, member_status, position, citizen_id, date_of_birth,
                   insurance_status, coverage_start, coverage_end, policy_number, remarks, updated_at
            FROM insurance_members
            WHERE $where
            ORDER BY full_name ASC
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $filename = $batch
        ? ($batch['batch_code'] . '_' . date('Ymd_His') . '.csv')
        : ('rsu_active_' . $companyCode . '_' . date('Ymd_His') . '.csv');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');

    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

    $headers = [
        'รหัสบุคลากร/นักศึกษา', 'ชื่อ-สกุล', 'สถานะสมาชิก', 'ตำแหน่ง',
        'เลขบัตรประชาชน', 'วันเกิด',
        'สถานะประกัน', 'วันเริ่มต้นสิทธิ์', 'วันสิ้นสุดสิทธิ์',
        'เลขกรมธรรม์', 'หมายเหตุ', 'อัปเดตล่าสุด',
    ];
    $csvRow = function (array $cols): void {
        echo implode(',', array_map(fn($c) => '"' . str_replace('"', '""', (string)$c) . '"', $cols)) . "\r\n";
    };
    $csvRow($headers);

    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $csvRow([
            $row['member_id'], $row['full_name'], $row['member_status'], $row['position'],
            $row['citizen_id'], $row['date_of_birth'] ?? '',
            $row['insurance_status'], $row['coverage_start'] ?? '', $row['coverage_end'] ?? '',
            $row['policy_number'], $row['remarks'] ?? '', $row['updated_at'],
        ]);
        $count++;
    }

    ins_partner_log('export_csv',
        "company={$companyCode}, rows={$count}, only_missing=" . ($onlyMissing ? '1' : '0')
        . ($batch ? ", batch={$batch['batch_code']}" : ''));

    // Mark batch as downloaded + emit event
    if ($batch) {
        $now = date('Y-m-d H:i:s');
        $newStatus = in_array($batch['status'], ['approved'], true) ? 'downloaded' : $batch['status'];
        $pdo->prepare("
            UPDATE insurance_batch
            SET status = :st,
                first_downloaded_at = COALESCE(first_downloaded_at, :now1),
                last_downloaded_at = :now2,
                download_count = download_count + 1
            WHERE id = :id
        ")->execute([':st' => $newStatus, ':now1' => $now, ':now2' => $now, ':id' => (int)$batch['id']]);
        ins_batch_log_event(
            $pdo, (int)$batch['id'], 'downloaded',
            $batch['status'], $newStatus,
            'partner', (int)$partner['id'], $partner['username'],
            "rows={$count}, only_missing=" . ($onlyMissing ? '1' : '0')
        );
    }
    exit;
}

// ── Mode: page view ───────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/layout.php';

$counts = $pdo->prepare("
    SELECT
        SUM(insurance_status = 'Active') AS active_total,
        SUM(insurance_status = 'Active' AND (policy_number IS NULL OR policy_number = '')) AS active_missing
    FROM insurance_members
    WHERE insurance_company = :cc
");
$counts->execute([':cc' => $companyCode]);
$c = $counts->fetch(PDO::FETCH_ASSOC) ?: ['active_total' => 0, 'active_missing' => 0];

ins_partner_layout_start('ดาวน์โหลดรายชื่อ', 'export');
?>

<h1 class="ipp-page-title">ดาวน์โหลดรายชื่อ Active</h1>
<p class="ipp-page-sub">รายชื่อนักศึกษา/บุคลากรที่สถานะ Active ของบริษัท <?= htmlspecialchars($partner['company_name']) ?></p>

<div class="ipp-stat-grid" style="margin-bottom:1.25rem;">
    <div class="ipp-stat">
        <div class="label">Active ทั้งหมด</div>
        <div class="value"><?= number_format((int)$c['active_total']) ?></div>
    </div>
    <div class="ipp-stat" style="border-left-color:#f59e0b;">
        <div class="label">รอออกเลขกรมธรรม์</div>
        <div class="value" style="color:#b45309;"><?= number_format((int)$c['active_missing']) ?></div>
    </div>
</div>

<div class="ipp-card">
    <h3><i class="fa-solid fa-file-arrow-down mr-1"></i> เลือกรูปแบบไฟล์</h3>

    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:1rem;">
        <div style="border:1.5px solid #d1fae5; border-radius:.75rem; padding:1.1rem;">
            <div style="font-weight:700; color:#064e3b; margin-bottom:.5rem;">
                <i class="fa-solid fa-list mr-1"></i> รายชื่อ Active ทั้งหมด
            </div>
            <p style="font-size:.8rem; color:#6b7280; margin-bottom:.85rem;">
                ไฟล์ CSV รายชื่อทั้งหมดที่สถานะ Active (รวมทั้งคนที่มีและไม่มีเลขกรมธรรม์)
            </p>
            <a href="export.php?download=csv" class="ipp-btn">
                <i class="fa-solid fa-download"></i> ดาวน์โหลด (<?= number_format((int)$c['active_total']) ?> ราย)
            </a>
        </div>

        <div style="border:1.5px solid #fef3c7; border-radius:.75rem; padding:1.1rem; background:#fffbeb;">
            <div style="font-weight:700; color:#92400e; margin-bottom:.5rem;">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i> เฉพาะที่ยังไม่มีเลขกรมธรรม์
            </div>
            <p style="font-size:.8rem; color:#78350f; margin-bottom:.85rem;">
                CSV เฉพาะรายที่ Active แต่ยังไม่มี policy_number — เหมาะกับการออกกรมธรรม์ใหม่
            </p>
            <a href="export.php?download=csv&only_missing_policy=1" class="ipp-btn">
                <i class="fa-solid fa-download"></i> ดาวน์โหลด (<?= number_format((int)$c['active_missing']) ?> ราย)
            </a>
        </div>
    </div>
</div>

<div class="ipp-alert info">
    <i class="fa-solid fa-circle-info mr-1"></i>
    เมื่อออกกรมธรรม์เรียบร้อยแล้ว กรุณาอัปโหลดไฟล์เลขกรมธรรม์กลับที่หน้า
    <a href="import_policy.php" style="font-weight:700; text-decoration:underline;">อัปโหลดเลขกรมธรรม์</a>
</div>

<?php
ins_partner_layout_end();
