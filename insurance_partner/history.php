<?php
/**
 * insurance_partner/history.php
 * ประวัติการดำเนินการของ partner ปัจจุบัน (login, export, import)
 * ตาราง: paginated 20 รายการ/หน้า ตาม CLAUDE.md
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_ins_partner_login();
require_once __DIR__ . '/includes/layout.php';

$partner = current_ins_partner();
$pdo = db();

const PER_PAGE = 20;

$page = max(1, (int)($_GET['page'] ?? 1));
$action = trim((string)($_GET['action'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));

$where = ['partner_user_id = :uid'];
$params = [':uid' => $partner['id']];
if ($action !== '') {
    $where[] = 'action = :act';
    $params[':act'] = $action;
}
if ($search !== '') {
    $where[] = '(details LIKE :q OR action LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM insurance_partner_activity_log $whereSql");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / PER_PAGE));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * PER_PAGE;

$listStmt = $pdo->prepare("
    SELECT id, action, details, ip_address, created_at
    FROM insurance_partner_activity_log
    $whereSql
    ORDER BY id DESC
    LIMIT " . PER_PAGE . " OFFSET " . $offset . "
");
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// ดึง action distinct สำหรับ filter dropdown
$actsStmt = $pdo->prepare("SELECT DISTINCT action FROM insurance_partner_activity_log WHERE partner_user_id = :uid ORDER BY action");
$actsStmt->execute([':uid' => $partner['id']]);
$availableActions = $actsStmt->fetchAll(PDO::FETCH_COLUMN);

// helper สำหรับ pagination url
$qs = function (array $overrides = []) use ($page, $action, $search): string {
    $q = array_merge(['page' => $page, 'action' => $action, 'q' => $search], $overrides);
    return 'history.php?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
};

ins_partner_layout_start('ประวัติการดำเนินการ', 'history');
?>

<h1 class="ipp-page-title">ประวัติการดำเนินการ</h1>
<p class="ipp-page-sub">Activity log ของฉัน (Audit Trail ตามมาตรฐาน ISO 27001)</p>

<div class="ipp-card">
    <form method="GET" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end; margin-bottom:1rem;">
        <div style="flex:1; min-width:200px;">
            <label style="font-size:.75rem; font-weight:700; color:#064e3b;">ค้นหา</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาในรายละเอียด..."
                style="width:100%; padding:.55rem .75rem; border:1.5px solid #d1fae5; border-radius:.55rem; font-size:.85rem; font-family:Sarabun,sans-serif;">
        </div>
        <div style="min-width:180px;">
            <label style="font-size:.75rem; font-weight:700; color:#064e3b;">การกระทำ</label>
            <select name="action" style="width:100%; padding:.55rem .75rem; border:1.5px solid #d1fae5; border-radius:.55rem; font-size:.85rem; font-family:Sarabun,sans-serif;">
                <option value="">ทั้งหมด</option>
                <?php foreach ($availableActions as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>" <?= $a === $action ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="ipp-btn"><i class="fa-solid fa-magnifying-glass"></i> ค้นหา</button>
        <?php if ($search !== '' || $action !== ''): ?>
        <a href="history.php" class="ipp-btn secondary"><i class="fa-solid fa-xmark"></i> ล้าง</a>
        <?php endif; ?>
    </form>

    <div style="font-size:.8rem; color:#047857; margin-bottom:.65rem;">
        หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= number_format($total) ?> รายการ
    </div>

    <table class="ipp-table">
        <thead>
            <tr>
                <th style="width:170px;">เวลา</th>
                <th style="width:160px;">การกระทำ</th>
                <th>รายละเอียด</th>
                <th style="width:140px;">IP</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="4" style="text-align:center; color:#6b7280; padding:1.5rem;">ไม่พบข้อมูล</td></tr>
            <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td><strong><?= htmlspecialchars($r['action']) ?></strong></td>
                <td style="color:#374151; font-size:.8rem;"><?= htmlspecialchars((string)$r['details']) ?></td>
                <td style="color:#6b7280; font-size:.8rem;"><?= htmlspecialchars((string)$r['ip_address']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div class="ipp-pagination">
        <div class="ipp-pagination-info">
            หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= number_format($total) ?> รายการ
        </div>
        <div class="ipp-pagination-controls">
            <?php
            $first = max(1, $page - 2);
            $last  = min($totalPages, $page + 2);
            ?>
            <a class="ipp-page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : htmlspecialchars($qs(['page' => 1])) ?>" title="หน้าแรก">«</a>
            <a class="ipp-page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : htmlspecialchars($qs(['page' => $page - 1])) ?>" title="ก่อนหน้า">‹</a>
            <?php for ($i = $first; $i <= $last; $i++): ?>
            <a class="ipp-page-btn <?= $i === $page ? 'active' : '' ?>" href="<?= htmlspecialchars($qs(['page' => $i])) ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a class="ipp-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($qs(['page' => $page + 1])) ?>" title="ถัดไป">›</a>
            <a class="ipp-page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($qs(['page' => $totalPages])) ?>" title="หน้าสุดท้าย">»</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
ins_partner_layout_end();
