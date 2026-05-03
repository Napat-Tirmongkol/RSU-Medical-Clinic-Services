<?php
/**
 * insurance_partner/batches.php
 * รายการเอกสาร (batch) ของบริษัทประกัน — เห็นเฉพาะของตัวเอง
 * แสดงเฉพาะ batch ที่ผ่านการอนุมัติจาก RSU Medical Clinic แล้ว
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_ins_partner_login();
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/../portal/includes/insurance_batch.php';

$partner = current_ins_partner();
$companyCode = $partner['company_code'];
$pdo = db();

const PER_PAGE = 20;

$page = max(1, (int)($_GET['page'] ?? 1));
$status = trim((string)($_GET['status'] ?? ''));

// Partner only sees batches that have been approved by clinic
// (uploaded/pending_review/rejected = invisible to partner)
$visibleStatuses = ['approved', 'downloaded', 'in_progress', 'partial', 'completed'];

$where = ['b.insurance_company = :cc', 'b.status IN (\'' . implode("','", $visibleStatuses) . '\')'];
$params = [':cc' => $companyCode];
if ($status !== '' && in_array($status, $visibleStatuses, true)) {
    $where = ['b.insurance_company = :cc', 'b.status = :st'];
    $params[':st'] = $status;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM insurance_batch b $whereSql");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / PER_PAGE));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * PER_PAGE;

$stmt = $pdo->prepare("
    SELECT * FROM insurance_batch b
    $whereSql
    ORDER BY b.id DESC
    LIMIT " . PER_PAGE . " OFFSET " . $offset . "
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// recalc cached counts for visible rows
foreach ($rows as $r) ins_batch_recalc($pdo, (int)$r['id']);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Detail mode ──────────────────────────────────────────────────────────────
$detailId = (int)($_GET['id'] ?? 0);
$detail = null;
$detailEvents = [];
$detailMembers = [];
$detailMemPage = max(1, (int)($_GET['mp'] ?? 1));
$detailMemTotal = 0;
$detailMemTotalPages = 1;

if ($detailId > 0) {
    $dStmt = $pdo->prepare("SELECT * FROM insurance_batch WHERE id = :id AND insurance_company = :cc");
    $dStmt->execute([':id' => $detailId, ':cc' => $companyCode]);
    $detail = $dStmt->fetch(PDO::FETCH_ASSOC);
    if ($detail && in_array($detail['status'], $visibleStatuses, true)) {
        ins_batch_recalc($pdo, $detailId);
        $dStmt->execute([':id' => $detailId, ':cc' => $companyCode]);
        $detail = $dStmt->fetch(PDO::FETCH_ASSOC);

        $eStmt = $pdo->prepare("SELECT * FROM insurance_batch_event WHERE batch_id = :id ORDER BY id DESC LIMIT 60");
        $eStmt->execute([':id' => $detailId]);
        $detailEvents = $eStmt->fetchAll(PDO::FETCH_ASSOC);

        $sid = (int)$detail['sync_id'];
        $mTotalStmt = $pdo->prepare("SELECT COUNT(DISTINCT member_id) FROM insurance_member_history WHERE sync_id = :sid");
        $mTotalStmt->execute([':sid' => $sid]);
        $detailMemTotal = (int)$mTotalStmt->fetchColumn();
        $detailMemTotalPages = max(1, (int)ceil($detailMemTotal / PER_PAGE));
        if ($detailMemPage > $detailMemTotalPages) $detailMemPage = $detailMemTotalPages;
        $mOff = ($detailMemPage - 1) * PER_PAGE;

        $mStmt = $pdo->prepare("
            SELECT m.member_id, m.full_name, m.member_status, m.position,
                   m.policy_number, m.coverage_start, m.coverage_end,
                   m.insurance_status
            FROM (SELECT DISTINCT member_id FROM insurance_member_history WHERE sync_id = :sid) h
            LEFT JOIN insurance_members m ON m.member_id = h.member_id
            ORDER BY m.full_name
            LIMIT " . PER_PAGE . " OFFSET " . $mOff . "
        ");
        $mStmt->execute([':sid' => $sid]);
        $detailMembers = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $detail = null;
    }
}

$labels = ins_batch_status_labels();
$stages = ins_batch_stepper_stages();

ins_partner_log('view_batches', "company={$companyCode}, page={$page}");

ins_partner_layout_start('สถานะเอกสาร', 'batches');

$qs = function (array $overrides = []) use ($page, $status, $detailId) {
    $q = array_merge(['page' => $page, 'status' => $status, 'id' => $detailId], $overrides);
    return 'batches.php?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null && $v !== 0));
};

$stepperHtml = function (string $st) use ($stages): string {
    $idx = ins_batch_stage_index($st);
    $isReject = $st === 'rejected';
    $html = '<div style="display:flex; align-items:center; gap:.25rem; flex-wrap:wrap;">';
    $i = 0;
    foreach ($stages as $key => [$label, $icon]) {
        $cls = 'background:#e2e8f0; color:#94a3b8;';
        $content = '<i class="fa-solid fa-' . $icon . '"></i>';
        if ($i < $idx) { $cls = 'background:#10b981; color:#fff;'; $content = '<i class="fa-solid fa-check"></i>'; }
        elseif ($i === $idx) {
            if ($isReject) { $cls = 'background:#ef4444; color:#fff;'; $content = '<i class="fa-solid fa-xmark"></i>'; }
            else $cls = 'background:#06b6d4; color:#fff; box-shadow:0 0 0 3px rgba(6,182,212,.3);';
        }
        $html .= '<div title="' . htmlspecialchars($label) . '" style="width:1.6rem; height:1.6rem; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:.7rem; font-weight:800; ' . $cls . '">' . $content . '</div>';
        if ($i < count($stages) - 1) {
            $lineCls = $i < $idx ? 'background:#10b981;' : 'background:#e2e8f0;';
            $html .= '<div style="width:18px; height:2px; ' . $lineCls . '"></div>';
        }
        $i++;
    }
    $html .= '</div>';
    return $html;
};
?>

<h1 class="ipp-page-title">สถานะเอกสาร</h1>
<p class="ipp-page-sub">เอกสาร (batch) ที่ผ่านการอนุมัติจาก RSU Medical Clinic — บริษัท <?= htmlspecialchars($partner['company_name']) ?></p>

<div class="ipp-card">
    <form method="GET" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end; margin-bottom:1rem;">
        <div style="min-width:220px;">
            <label style="font-size:.75rem; font-weight:700; color:#064e3b;">สถานะ</label>
            <select name="status" onchange="this.form.submit()" style="width:100%; padding:.55rem .75rem; border:1.5px solid #d1fae5; border-radius:.55rem; font-size:.85rem; font-family:Prompt,sans-serif;">
                <option value="">-- ทุกสถานะที่อนุมัติแล้ว --</option>
                <?php foreach ($visibleStatuses as $vs): if (!isset($labels[$vs])) continue; [$lab, $color] = $labels[$vs]; ?>
                <option value="<?= htmlspecialchars($vs) ?>" <?= $status === $vs ? 'selected' : '' ?>><?= htmlspecialchars($lab) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($status !== ''): ?>
        <a href="batches.php" class="ipp-btn secondary"><i class="fa-solid fa-xmark"></i> ล้าง</a>
        <?php endif; ?>
    </form>

    <div style="font-size:.8rem; color:#047857; margin-bottom:.65rem;">
        หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= number_format($total) ?> เอกสาร
    </div>

    <table class="ipp-table">
        <thead>
            <tr>
                <th style="width:160px;">รหัสเอกสาร</th>
                <th>ความคืบหน้า</th>
                <th style="width:120px;">รายชื่อ</th>
                <th style="width:140px;">อนุมัติเมื่อ</th>
                <th style="width:120px;">การจัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="5" style="text-align:center; color:#6b7280; padding:1.5rem;">
                ยังไม่มีเอกสารที่อนุมัติแล้ว
            </td></tr>
            <?php else: foreach ($rows as $r):
                [$lab, $color, $icon] = $labels[$r['status']] ?? ['-', '#64748b', 'circle'];
            ?>
            <tr>
                <td>
                    <div style="font-weight:700; color:#064e3b;"><code><?= htmlspecialchars($r['batch_code']) ?></code></div>
                    <div style="margin-top:.3rem;">
                        <span style="display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .55rem; border-radius:999px; font-size:.7rem; font-weight:700; background:<?= $color ?>22; color:<?= $color ?>;">
                            <i class="fa-solid fa-<?= $icon ?>"></i> <?= htmlspecialchars($lab) ?>
                        </span>
                    </div>
                </td>
                <td>
                    <?= $stepperHtml($r['status']) ?>
                    <div style="font-size:.72rem; color:#6b7280; margin-top:.4rem;">
                        ออกกรมธรรม์: <?= number_format((int)$r['members_with_policy']) ?>/<?= number_format((int)$r['total_members']) ?>
                    </div>
                </td>
                <td>
                    <strong><?= number_format((int)$r['total_members']) ?></strong>
                    <div style="font-size:.7rem; color:#6b7280;">
                        +<?= (int)$r['members_inserted'] ?> / ↻<?= (int)$r['members_updated'] ?>
                    </div>
                </td>
                <td style="font-size:.78rem; color:#475569;">
                    <?= $r['reviewed_at'] ? htmlspecialchars($r['reviewed_at']) : '<em style="color:#94a3b8;">-</em>' ?>
                </td>
                <td>
                    <a href="?id=<?= (int)$r['id'] ?>&page=<?= $page ?>&status=<?= urlencode($status) ?>" class="ipp-btn">
                        <i class="fa-solid fa-eye"></i> ดู
                    </a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div class="ipp-pagination">
        <div class="ipp-pagination-info">หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= number_format($total) ?> เอกสาร</div>
        <div class="ipp-pagination-controls">
            <?php $first = max(1, $page - 2); $last = min($totalPages, $page + 2); ?>
            <a class="ipp-page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : htmlspecialchars($qs(['page' => 1])) ?>">«</a>
            <a class="ipp-page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : htmlspecialchars($qs(['page' => $page - 1])) ?>">‹</a>
            <?php for ($i = $first; $i <= $last; $i++): ?>
            <a class="ipp-page-btn <?= $i === $page ? 'active' : '' ?>" href="<?= htmlspecialchars($qs(['page' => $i])) ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a class="ipp-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($qs(['page' => $page + 1])) ?>">›</a>
            <a class="ipp-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($qs(['page' => $totalPages])) ?>">»</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($detail):
    [$lab, $color, $icon] = $labels[$detail['status']] ?? ['-', '#64748b', 'circle'];
?>
<div class="ipp-card">
    <h3>
        <i class="fa-solid fa-file-lines mr-1"></i> รายละเอียดเอกสาร
        <span style="display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .55rem; border-radius:999px; font-size:.7rem; font-weight:700; background:<?= $color ?>22; color:<?= $color ?>; margin-left:.5rem;">
            <i class="fa-solid fa-<?= $icon ?>"></i> <?= htmlspecialchars($lab) ?>
        </span>
    </h3>

    <div style="background:#f0fdfa; border-radius:.85rem; padding:1.25rem; margin-bottom:1.25rem;">
        <div style="font-size:.78rem; color:#047857; font-weight:700; text-transform:uppercase; margin-bottom:.65rem;">ความคืบหน้า</div>
        <?= $stepperHtml($detail['status']) ?>
    </div>

    <div style="display:grid; grid-template-columns:auto 1fr; gap:.45rem .85rem; font-size:.85rem; margin-bottom:1.25rem;">
        <div style="color:#6b7280;">รหัสเอกสาร:</div><div><strong><code><?= htmlspecialchars($detail['batch_code']) ?></code></strong></div>
        <div style="color:#6b7280;">รายชื่อทั้งหมด:</div><div><strong><?= number_format((int)$detail['total_members']) ?></strong> ราย</div>
        <div style="color:#6b7280;">ออกกรมธรรม์แล้ว:</div><div><strong style="color:#059669;"><?= number_format((int)$detail['members_with_policy']) ?>/<?= number_format((int)$detail['total_members']) ?></strong></div>
        <div style="color:#6b7280;">อนุมัติเมื่อ:</div><div><?= htmlspecialchars((string)$detail['reviewed_at']) ?></div>
        <?php if ($detail['review_note']): ?>
        <div style="color:#6b7280;">หมายเหตุคลินิก:</div><div><?= htmlspecialchars((string)$detail['review_note']) ?></div>
        <?php endif; ?>
        <?php if ($detail['first_downloaded_at']): ?>
        <div style="color:#6b7280;">ดาวน์โหลดครั้งแรก:</div><div><?= htmlspecialchars((string)$detail['first_downloaded_at']) ?> (<?= (int)$detail['download_count'] ?> ครั้ง)</div>
        <?php endif; ?>
        <?php if ($detail['completed_at']): ?>
        <div style="color:#6b7280;">เสร็จสิ้น:</div><div style="color:#059669; font-weight:700;"><?= htmlspecialchars((string)$detail['completed_at']) ?></div>
        <?php endif; ?>
    </div>

    <div style="display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.5rem;">
        <a href="export.php?download=csv&batch_id=<?= (int)$detail['id'] ?>" class="ipp-btn">
            <i class="fa-solid fa-download"></i> ดาวน์โหลด CSV (ทั้งหมด)
        </a>
        <a href="export.php?download=csv&batch_id=<?= (int)$detail['id'] ?>&only_missing_policy=1" class="ipp-btn secondary">
            <i class="fa-solid fa-download"></i> เฉพาะที่ยังไม่มีเลขกรมธรรม์
        </a>
        <a href="import_policy.php" class="ipp-btn secondary">
            <i class="fa-solid fa-cloud-arrow-up"></i> อัปโหลดเลขกรมธรรม์
        </a>
    </div>

    <h3><i class="fa-solid fa-clock-rotate-left mr-1"></i> Timeline</h3>
    <?php if (!$detailEvents): ?>
        <p style="color:#94a3b8;">ยังไม่มีกิจกรรม</p>
    <?php else: ?>
    <div style="border-top:1px solid #d1fae5;">
        <?php foreach ($detailEvents as $ev): ?>
        <div style="display:flex; gap:.85rem; padding:.85rem 0; border-bottom:1px dashed #d1fae5;">
            <div style="width:2rem; height:2rem; border-radius:50%; background:#d1fae5; color:#047857; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <i class="fa-solid fa-circle-info"></i>
            </div>
            <div style="flex:1;">
                <div style="font-weight:700; color:#064e3b; font-size:.85rem;">
                    <?= htmlspecialchars($ev['event_type']) ?>
                    <?php if ($ev['from_status'] && $ev['to_status']): ?>
                    <span style="color:#94a3b8; font-weight:500;"><?= htmlspecialchars($ev['from_status']) ?> → <?= htmlspecialchars($ev['to_status']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.75rem; color:#6b7280; margin-top:.15rem;">
                    <i class="fa-regular fa-user mr-1"></i><?= htmlspecialchars($ev['actor_name'] ?: $ev['actor_type']) ?>
                    · <i class="fa-regular fa-clock mr-1"></i><?= htmlspecialchars($ev['created_at']) ?>
                </div>
                <?php if ($ev['details']): ?>
                <div style="font-size:.78rem; color:#475569; margin-top:.35rem; padding:.5rem .65rem; background:#f0fdfa; border-radius:.4rem;"><?= htmlspecialchars($ev['details']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h3 style="margin-top:1.5rem;"><i class="fa-solid fa-users mr-1"></i> รายชื่อใน batch (<?= number_format($detailMemTotal) ?> ราย)</h3>
    <table class="ipp-table">
        <thead>
            <tr><th>รหัส</th><th>ชื่อ-สกุล</th><th>เลขกรมธรรม์</th><th>วันคุ้มครอง</th></tr>
        </thead>
        <tbody>
            <?php if (!$detailMembers): ?>
            <tr><td colspan="4" style="text-align:center; color:#6b7280; padding:1rem;">ไม่มีข้อมูล</td></tr>
            <?php else: foreach ($detailMembers as $m): ?>
            <tr>
                <td><code><?= htmlspecialchars((string)$m['member_id']) ?></code></td>
                <td><?= htmlspecialchars((string)($m['full_name'] ?? '-')) ?></td>
                <td><?= $m['policy_number'] ? '<code style="color:#059669;">' . htmlspecialchars($m['policy_number']) . '</code>' : '<span style="color:#94a3b8;">รอออก</span>' ?></td>
                <td style="font-size:.78rem; color:#475569;">
                    <?= $m['coverage_start'] ? htmlspecialchars($m['coverage_start']) : '-' ?>
                    →
                    <?= $m['coverage_end'] ? htmlspecialchars($m['coverage_end']) : '-' ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php if ($detailMemTotalPages > 1): ?>
    <div class="ipp-pagination">
        <div class="ipp-pagination-info">หน้า <?= $detailMemPage ?> / <?= $detailMemTotalPages ?> · รวม <?= number_format($detailMemTotal) ?> ราย</div>
        <div class="ipp-pagination-controls">
            <?php $first = max(1, $detailMemPage - 2); $last = min($detailMemTotalPages, $detailMemPage + 2); ?>
            <a class="ipp-page-btn <?= $detailMemPage <= 1 ? 'disabled' : '' ?>" href="<?= $detailMemPage <= 1 ? '#' : htmlspecialchars($qs(['mp' => 1])) ?>">«</a>
            <a class="ipp-page-btn <?= $detailMemPage <= 1 ? 'disabled' : '' ?>" href="<?= $detailMemPage <= 1 ? '#' : htmlspecialchars($qs(['mp' => $detailMemPage - 1])) ?>">‹</a>
            <?php for ($i = $first; $i <= $last; $i++): ?>
            <a class="ipp-page-btn <?= $i === $detailMemPage ? 'active' : '' ?>" href="<?= htmlspecialchars($qs(['mp' => $i])) ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a class="ipp-page-btn <?= $detailMemPage >= $detailMemTotalPages ? 'disabled' : '' ?>" href="<?= $detailMemPage >= $detailMemTotalPages ? '#' : htmlspecialchars($qs(['mp' => $detailMemPage + 1])) ?>">›</a>
            <a class="ipp-page-btn <?= $detailMemPage >= $detailMemTotalPages ? 'disabled' : '' ?>" href="<?= $detailMemPage >= $detailMemTotalPages ? '#' : htmlspecialchars($qs(['mp' => $detailMemTotalPages])) ?>">»</a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($detailId > 0): ?>
<div class="ipp-alert error">ไม่พบเอกสารนี้ หรือยังไม่ได้รับการอนุมัติ</div>
<?php endif; ?>

<?php
ins_partner_layout_end();
