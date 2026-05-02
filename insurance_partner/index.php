<?php
/**
 * insurance_partner/index.php — Dashboard for Insurance Partner Portal
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_ins_partner_login();
require_once __DIR__ . '/includes/layout.php';

$partner = current_ins_partner();
$companyCode = $partner['company_code'];

$pdo = db();

// สถิติ scope เฉพาะบริษัทของ partner
$stats = $pdo->prepare("
    SELECT
        COUNT(*)                                                     AS total,
        SUM(insurance_status = 'Active')                             AS active,
        SUM(insurance_status = 'Inactive')                           AS inactive,
        SUM(insurance_status = 'Active' AND (policy_number IS NULL OR policy_number = '')) AS active_no_policy,
        SUM(insurance_status = 'Active' AND policy_number <> '')     AS active_with_policy,
        SUM(insurance_status = 'Active' AND coverage_end IS NOT NULL
            AND coverage_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS expiring_soon
    FROM insurance_members
    WHERE insurance_company = :cc
");
$stats->execute([':cc' => $companyCode]);
$s = $stats->fetch(PDO::FETCH_ASSOC) ?: [];

// 5 รายการล่าสุดของ partner
$recent = $pdo->prepare("
    SELECT action, details, created_at
    FROM insurance_partner_activity_log
    WHERE partner_user_id = :uid
    ORDER BY id DESC
    LIMIT 5
");
$recent->execute([':uid' => $partner['id']]);
$recentRows = $recent->fetchAll(PDO::FETCH_ASSOC);

// Batch counts (เห็นเฉพาะที่ approved+)
$batchCnt = $pdo->prepare("
    SELECT
        SUM(status = 'approved')                               AS pending_download,
        SUM(status IN ('downloaded','in_progress','partial'))  AS in_progress,
        SUM(status = 'completed')                              AS completed
    FROM insurance_batch
    WHERE insurance_company = :cc
      AND status IN ('approved','downloaded','in_progress','partial','completed')
");
$batchCnt->execute([':cc' => $companyCode]);
$bc = $batchCnt->fetch(PDO::FETCH_ASSOC) ?: [];

ins_partner_layout_start('Dashboard', 'dashboard');
?>

<h1 class="ipp-page-title">ภาพรวม</h1>
<p class="ipp-page-sub">บริษัท: <strong><?= htmlspecialchars($partner['company_name']) ?></strong></p>

<div class="ipp-stat-grid" style="margin-bottom:1.25rem;">
    <div class="ipp-stat">
        <div class="label">รายชื่อทั้งหมด</div>
        <div class="value"><?= number_format((int)($s['total'] ?? 0)) ?></div>
    </div>
    <div class="ipp-stat">
        <div class="label">สถานะ Active</div>
        <div class="value" style="color:#059669;"><?= number_format((int)($s['active'] ?? 0)) ?></div>
    </div>
    <div class="ipp-stat" style="border-left-color:#f59e0b;">
        <div class="label">รอออกเลขกรมธรรม์</div>
        <div class="value" style="color:#b45309;"><?= number_format((int)($s['active_no_policy'] ?? 0)) ?></div>
    </div>
    <div class="ipp-stat" style="border-left-color:#3b82f6;">
        <div class="label">มีเลขกรมธรรม์แล้ว</div>
        <div class="value" style="color:#1d4ed8;"><?= number_format((int)($s['active_with_policy'] ?? 0)) ?></div>
    </div>
    <div class="ipp-stat" style="border-left-color:#ef4444;">
        <div class="label">ใกล้หมดอายุ (30 วัน)</div>
        <div class="value" style="color:#b91c1c;"><?= number_format((int)($s['expiring_soon'] ?? 0)) ?></div>
    </div>
</div>

<div class="ipp-card">
    <h3><i class="fa-solid fa-list-check mr-1"></i> เอกสาร (Batch)</h3>
    <div class="ipp-stat-grid" style="margin-bottom:.75rem;">
        <div class="ipp-stat" style="border-left-color:#10b981;">
            <div class="label">รออัพโหลดเลขกรมธรรม์</div>
            <div class="value" style="color:#059669;"><?= number_format((int)($bc['pending_download'] ?? 0)) ?></div>
        </div>
        <div class="ipp-stat" style="border-left-color:#a855f7;">
            <div class="label">กำลังดำเนินการ</div>
            <div class="value" style="color:#7c3aed;"><?= number_format((int)($bc['in_progress'] ?? 0)) ?></div>
        </div>
        <div class="ipp-stat" style="border-left-color:#06b6d4;">
            <div class="label">เสร็จสิ้นแล้ว</div>
            <div class="value" style="color:#0891b2;"><?= number_format((int)($bc['completed'] ?? 0)) ?></div>
        </div>
    </div>
    <a href="batches.php" class="ipp-btn">
        <i class="fa-solid fa-list-check"></i> ดูสถานะเอกสารทั้งหมด
    </a>
</div>

<div class="ipp-card">
    <h3><i class="fa-solid fa-bolt mr-1"></i> การดำเนินการด่วน</h3>
    <div style="display:flex; gap:.75rem; flex-wrap:wrap;">
        <a href="batches.php" class="ipp-btn">
            <i class="fa-solid fa-file-arrow-down"></i> ดาวน์โหลดตามเอกสาร (แนะนำ)
        </a>
        <a href="export.php" class="ipp-btn secondary">
            <i class="fa-solid fa-file-arrow-down"></i> ดาวน์โหลดรายชื่อ Active ทั้งหมด
        </a>
        <a href="import_policy.php" class="ipp-btn secondary">
            <i class="fa-solid fa-file-arrow-up"></i> อัปโหลดเลขกรมธรรม์
        </a>
        <a href="history.php" class="ipp-btn secondary">
            <i class="fa-solid fa-clock-rotate-left"></i> ประวัติ
        </a>
    </div>
</div>

<div class="ipp-card">
    <h3><i class="fa-solid fa-clock-rotate-left mr-1"></i> กิจกรรมล่าสุดของฉัน</h3>
    <?php if (!$recentRows): ?>
        <p style="color:#6b7280; font-size:.85rem;">ยังไม่มีกิจกรรม</p>
    <?php else: ?>
    <table class="ipp-table">
        <thead><tr><th>เวลา</th><th>การกระทำ</th><th>รายละเอียด</th></tr></thead>
        <tbody>
            <?php foreach ($recentRows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td><strong><?= htmlspecialchars($r['action']) ?></strong></td>
                <td style="color:#6b7280;"><?= htmlspecialchars((string)$r['details']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="ipp-alert info">
    <i class="fa-solid fa-circle-info mr-1"></i>
    คุณเห็นและจัดการเฉพาะข้อมูลของบริษัท <strong><?= htmlspecialchars($partner['company_name']) ?></strong> เท่านั้น
    ทุกการดำเนินการจะถูกบันทึกใน Activity Log เพื่อการตรวจสอบตามมาตรฐาน ISO 27001
</div>

<?php
ins_partner_layout_end();
ins_partner_log('view_dashboard', "company={$companyCode}");
