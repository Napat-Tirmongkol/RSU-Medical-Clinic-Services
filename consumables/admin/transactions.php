<?php
// consumables/admin/transactions.php — ประวัติการเคลื่อนไหวทั้งหมด (กรอง+pagination)
require_once __DIR__ . '/../includes/check_session.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = db();

$filterType    = (string)($_GET['txn_type']     ?? '');
$filterFaculty = (int)   ($_GET['faculty_id']   ?? 0);
$filterItem    = (int)   ($_GET['consumable_id']?? 0);
$dateFrom      = (string)($_GET['date_from']    ?? '');
$dateTo        = (string)($_GET['date_to']      ?? '');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($filterType !== '' && array_key_exists($filterType, csm_txn_options())) {
    $where[]  = 't.txn_type = ?';
    $params[] = $filterType;
}
if ($filterFaculty > 0) {
    $where[]  = 't.faculty_id = ?';
    $params[] = $filterFaculty;
}
if ($filterItem > 0) {
    $where[]  = 't.consumable_id = ?';
    $params[] = $filterItem;
}
if ($dateFrom !== '') { $where[] = 't.txn_date >= ?'; $params[] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 't.txn_date <= ?'; $params[] = $dateTo;   }

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM consumable_transactions t {$whereSql}");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();
    $totalPages = (int)max(1, ceil($total / $perPage));

    $sql = "SELECT t.*, c.name AS item_name, c.code AS item_code, c.unit_piece, c.unit_pack,
                   f.name_th AS faculty_name, f.type AS faculty_type,
                   s.full_name AS created_by_name
            FROM consumable_transactions t
            LEFT JOIN consumables    c ON c.id = t.consumable_id
            LEFT JOIN sys_faculties  f ON f.id = t.faculty_id
            LEFT JOIN sys_staff      s ON s.id = t.created_by
            {$whereSql}
            ORDER BY t.id DESC
            LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // สรุปยอดในช่วงที่กรอง
    $sumStmt = $pdo->prepare("SELECT
            SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END) AS total_in,
            SUM(CASE WHEN qty_change < 0 THEN -qty_change ELSE 0 END) AS total_out
        FROM consumable_transactions t {$whereSql}");
    $sumStmt->execute($params);
    $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_in' => 0, 'total_out' => 0];

    $faculties = csm_faculty_list($pdo);
    $consumables = $pdo->query("SELECT id, code, name FROM consumables ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = []; $total = 0; $totalPages = 1; $sumRow = ['total_in'=>0,'total_out'=>0];
    $faculties = ['faculty'=>[],'department'=>[]]; $consumables = [];
    $error = 'โหลดข้อมูลไม่สำเร็จ: ' . $e->getMessage();
}

$page_title   = 'ประวัติการเคลื่อนไหว';
$current_page = 'transactions';
$extraQuery = array_filter([
    'txn_type'      => $filterType,
    'faculty_id'    => $filterFaculty ?: null,
    'consumable_id' => $filterItem ?: null,
    'date_from'     => $dateFrom,
    'date_to'       => $dateTo,
], fn($v) => $v !== null && $v !== '' && $v !== 0);

include __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($error)): ?>
    <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
    <div>
        <h2 class="asset-sec-title">ประวัติการเคลื่อนไหว</h2>
        <p class="text-sm text-slate-500 mt-1 ml-[14px]">รับเข้า · เบิกออก · ปรับยอด · จำหน่าย</p>
    </div>
</div>

<!-- Summary -->
<div class="grid grid-cols-2 gap-3 mb-4">
    <div class="asset-card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center"><i class="fa-solid fa-download"></i></div>
        <div>
            <div class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">รวมรับเข้า</div>
            <div class="text-xl font-extrabold text-emerald-700"><?= number_format((int)$sumRow['total_in']) ?></div>
        </div>
    </div>
    <div class="asset-card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center"><i class="fa-solid fa-hand-holding"></i></div>
        <div>
            <div class="text-[11px] uppercase tracking-wider text-slate-400 font-bold">รวมเบิกออก</div>
            <div class="text-xl font-extrabold text-rose-700"><?= number_format((int)$sumRow['total_out']) ?></div>
        </div>
    </div>
</div>

<form method="get" class="asset-card p-4 mb-4">
    <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
        <div class="md:col-span-2">
            <label class="asset-label">ประเภท</label>
            <select name="txn_type" class="asset-input">
                <option value="">— ทั้งหมด —</option>
                <?php foreach (csm_txn_options() as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $filterType === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-3">
            <label class="asset-label">หน่วยงาน</label>
            <select name="faculty_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php if (!empty($faculties['department'])): ?>
                    <optgroup label="หน่วยงาน">
                        <?php foreach ($faculties['department'] as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= $filterFaculty === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['name_th']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if (!empty($faculties['faculty'])): ?>
                    <optgroup label="คณะ">
                        <?php foreach ($faculties['faculty'] as $f): ?>
                            <option value="<?= $f['id'] ?>" <?= $filterFaculty === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['name_th']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
        </div>
        <div class="md:col-span-3">
            <label class="asset-label">วัสดุ</label>
            <select name="consumable_id" class="asset-input">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($consumables as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterItem === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">จากวันที่</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="asset-input">
        </div>
        <div class="md:col-span-2">
            <label class="asset-label">ถึงวันที่</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="asset-input">
        </div>
    </div>
    <div class="mt-3 flex items-center gap-3">
        <button type="submit" class="btn-asset btn-asset-primary">
            <i class="fas fa-filter"></i> ค้นหา
        </button>
        <?php if (!empty($extraQuery)): ?>
            <a href="admin/transactions.php" class="text-xs text-slate-500 hover:text-[#2e9e63]">
                <i class="fas fa-times-circle"></i> ล้างตัวกรอง
            </a>
        <?php endif; ?>
    </div>
</form>

<div class="asset-card p-2 sm:p-4">
    <div class="overflow-x-auto">
        <table class="asset-table">
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th>วัสดุ</th>
                    <th>ประเภท</th>
                    <th class="text-center">จำนวน</th>
                    <th class="text-center">คงเหลือ</th>
                    <th>หน่วยงาน</th>
                    <th>ผู้รับ / วัตถุประสงค์</th>
                    <th>โดย</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-slate-400 py-12 text-sm">
                        <i class="fas fa-folder-open text-3xl text-slate-300 mb-2 block"></i>
                        ไม่พบรายการตรงกับเงื่อนไข
                    </td></tr>
                <?php else: foreach ($rows as $r):
                    $tl = csm_txn_label($r['txn_type']);
                    $facType = ($r['faculty_type'] ?? '') === 'department' ? 'หน่วยงาน' : 'คณะ';
                    $unitLabel = $r['unit_input'] === 'pack' ? ($r['unit_pack'] ?: 'บรรจุภัณฑ์') : ($r['unit_piece'] ?: 'ชิ้น');
                ?>
                    <tr>
                        <td data-label="วันที่" class="text-sm text-slate-600 whitespace-nowrap">
                            <?= date('d/m/Y', strtotime($r['txn_date'])) ?>
                            <div class="text-[10px] text-slate-400"><?= date('H:i', strtotime($r['created_at'])) ?></div>
                        </td>
                        <td data-label="วัสดุ">
                            <a href="admin/consumable_view.php?id=<?= (int)$r['consumable_id'] ?>" class="font-bold text-slate-800 hover:text-[#2e9e63]">
                                <?= htmlspecialchars($r['item_name'] ?? '-') ?>
                            </a>
                            <div class="font-mono text-[10px] text-slate-400"><?= htmlspecialchars($r['item_code'] ?? '') ?></div>
                        </td>
                        <td data-label="ประเภท"><span class="badge-status <?= $tl['class'] ?>"><?= $tl['label'] ?></span></td>
                        <td data-label="จำนวน" class="text-center">
                            <div class="font-extrabold <?= $r['qty_change'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
                                <?= ($r['qty_change'] >= 0 ? '+' : '') . number_format((int)$r['qty_change']) ?>
                            </div>
                            <div class="text-[10px] text-slate-400">(<?= (int)$r['qty_input'] ?> <?= htmlspecialchars($unitLabel) ?>)</div>
                        </td>
                        <td data-label="คงเหลือ" class="text-center font-bold text-slate-700"><?= number_format((int)$r['balance_after']) ?></td>
                        <td data-label="หน่วยงาน" class="text-sm">
                            <?php if (!empty($r['faculty_name'])): ?>
                                <div class="font-bold text-slate-700"><?= htmlspecialchars($r['faculty_name']) ?></div>
                                <div class="text-[10px] text-slate-400"><?= $facType ?></div>
                            <?php else: ?>
                                <span class="text-slate-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="ผู้รับ" class="text-xs text-slate-600">
                            <?php if (!empty($r['requester_name'])): ?><div class="font-bold text-slate-700"><?= htmlspecialchars($r['requester_name']) ?></div><?php endif; ?>
                            <?php if (!empty($r['purpose'])): ?><div class="text-slate-500"><?= htmlspecialchars($r['purpose']) ?></div><?php endif; ?>
                            <?php if (!empty($r['reference'])): ?><div class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($r['reference']) ?></div><?php endif; ?>
                        </td>
                        <td data-label="โดย" class="text-xs text-slate-500"><?= htmlspecialchars($r['created_by_name'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?= csm_pagination_html($page, $totalPages, $total, $extraQuery) ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
