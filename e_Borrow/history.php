<?php
// e_Borrow/history.php
declare(strict_types=1);
@session_start();
include('includes/check_student_session.php');

require_once __DIR__ . '/includes/db_connect.php';

$student_id = (int)$_SESSION['student_id'];

// Pagination per CLAUDE.md (20/page default)
$perPage = 20;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;
$total   = 0;
$history = [];
$history_error = '';

try {
    $pdo = db();

    $countSql = "SELECT COUNT(*)
                 FROM borrow_records t
                 WHERE t.borrower_student_id = ?
                   AND (t.status IN ('returned','cancelled') OR t.approval_status IN ('pending','rejected'))";
    $cs = $pdo->prepare($countSql);
    $cs->execute([$student_id]);
    $total = (int) $cs->fetchColumn();

    $sql_history = "SELECT t.id, t.borrow_date, t.due_date, t.return_date,
                           t.status, t.approval_status,
                           et.name as type_name, et.image_url,
                           ei.name as eq_name
                    FROM borrow_records t
                    JOIN borrow_categories et ON t.type_id = et.id
                    LEFT JOIN borrow_items ei ON t.item_id = ei.id
                    WHERE t.borrower_student_id = ?
                      AND (t.status IN ('returned','cancelled') OR t.approval_status IN ('pending','rejected'))
                    ORDER BY t.borrow_date DESC, t.id DESC
                    LIMIT $perPage OFFSET $offset";
    $stmt_history = $pdo->prepare($sql_history);
    $stmt_history->execute([$student_id]);
    $history = $stmt_history->fetchAll();
} catch (PDOException $e) {
    $history_error = "เกิดข้อผิดพลาด: " . $e->getMessage();
}

$totalPages = max(1, (int) ceil($total / $perPage));

$page_title  = 'ประวัติคำขอ';
$active_page = 'history';
include('includes/student_header.php');
?>

<?php if ($history_error !== ''): ?>
<div class="rounded-2xl bg-rose-50 border border-rose-100 text-rose-700 px-4 py-3 text-sm font-bold mb-4 flex items-center gap-2">
    <i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($history_error) ?>
</div>
<?php endif; ?>

<?php if (empty($history)): ?>
<div class="bg-white rounded-[2rem] p-10 border border-slate-100 shadow-sm text-center">
    <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-slate-50 text-slate-400 flex items-center justify-center text-2xl">
        <i class="fa-solid fa-clipboard-list"></i>
    </div>
    <p class="text-sm font-black text-slate-900 mb-1">ยังไม่มีประวัติการทำรายการ</p>
    <a href="borrow.php" class="inline-flex items-center gap-2 mt-4 h-12 px-6 rounded-2xl bg-[#2e9e63] text-white font-black text-sm shadow-[0_10px_20px_rgba(46,158,99,0.25)] active:scale-95 transition-all">
        <i class="fa-solid fa-plus"></i> ไปยืมอุปกรณ์
    </a>
</div>
<?php else: ?>

<div class="flex items-center justify-between mb-3 px-1">
    <p class="text-[11px] font-black text-slate-500">หน้า <?= $page ?> / <?= $totalPages ?> · รวม <?= number_format($total) ?> รายการ</p>
</div>

<div class="space-y-3">
<?php foreach ($history as $row):
    $status     = $row['status'];
    $app_status = $row['approval_status'];

    $badgeClass = 'bg-slate-100 text-slate-600 border-slate-200';
    $accent     = 'bg-slate-300';
    $badgeText  = 'ไม่ทราบสถานะ';
    $badgeIcon  = 'fa-question-circle';
    $isPending  = false;

    if ($status === 'returned') {
        $badgeClass = 'bg-emerald-50 text-emerald-700 border-emerald-100';
        $accent     = 'bg-emerald-500';
        $badgeText  = 'คืนแล้ว';
        $badgeIcon  = 'fa-circle-check';
    } elseif ($app_status === 'pending') {
        $badgeClass = 'bg-amber-50 text-amber-700 border-amber-100';
        $accent     = 'bg-amber-400';
        $badgeText  = 'รอดำเนินการ';
        $badgeIcon  = 'fa-hourglass-half';
        $isPending  = true;
    } elseif ($app_status === 'rejected') {
        $badgeClass = 'bg-slate-100 text-slate-600 border-slate-200';
        $accent     = 'bg-slate-400';
        $badgeText  = 'ถูกปฏิเสธ';
        $badgeIcon  = 'fa-ban';
    } elseif ($status === 'cancelled') {
        $badgeClass = 'bg-rose-50 text-rose-700 border-rose-100';
        $accent     = 'bg-rose-500';
        $badgeText  = 'ยกเลิกแล้ว';
        $badgeIcon  = 'fa-circle-xmark';
    }

    $displayName = !empty($row['eq_name']) ? $row['eq_name'] : $row['type_name'];
    $displayType = !empty($row['eq_name']) ? $row['type_name'] : 'ประเภทอุปกรณ์';
?>
<div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden flex">
    <div class="w-1 <?= $accent ?> shrink-0"></div>
    <div class="p-4 flex-1 min-w-0">
        <div class="flex items-start gap-3 mb-3">
            <div class="w-12 h-12 rounded-xl bg-slate-50 flex items-center justify-center shrink-0 overflow-hidden">
                <?php if (!empty($row['image_url'])): ?>
                    <img src="<?= htmlspecialchars($row['image_url']) ?>" alt="" class="w-full h-full object-cover" onerror="this.outerHTML='<i class=\'fa-solid fa-stethoscope text-slate-300\'></i>'">
                <?php else: ?>
                    <i class="fa-solid fa-stethoscope text-slate-300"></i>
                <?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-black text-slate-900 truncate" title="<?= htmlspecialchars($displayName) ?>"><?= htmlspecialchars($displayName) ?></p>
                <p class="text-[11px] font-bold text-slate-400"><?= htmlspecialchars($displayType) ?></p>
            </div>
        </div>

        <div class="bg-slate-50 rounded-xl px-3 py-2 mb-3 space-y-1">
            <div class="flex justify-between text-[11px]">
                <span class="text-slate-400 font-bold">ส่งคำขอ</span>
                <strong class="text-slate-700 font-black"><?= date('d/m/Y H:i', strtotime($row['borrow_date'])) ?></strong>
            </div>
            <?php if ($status === 'returned' && $row['return_date']): ?>
            <div class="flex justify-between text-[11px]">
                <span class="text-slate-400 font-bold">คืนเมื่อ</span>
                <strong class="text-emerald-600 font-black"><?= date('d/m/Y H:i', strtotime($row['return_date'])) ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <div class="flex items-center justify-between">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full border text-[10px] font-black <?= $badgeClass ?>">
                <i class="fa-solid <?= $badgeIcon ?> text-[9px]"></i><?= $badgeText ?>
            </span>
            <?php if ($isPending): ?>
            <button type="button" onclick="confirmCancelRequest(<?= (int)$row['id'] ?>)"
                class="px-3 py-1.5 rounded-xl bg-rose-50 text-rose-600 text-[11px] font-black border border-rose-100 active:scale-95 transition-all">
                ยกเลิก
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex flex-wrap justify-center gap-2 mt-5">
    <?php
    $btn = function (string $label, int $target, bool $disabled = false, bool $active = false) use ($page) {
        $base = 'min-w-9 h-9 px-3 rounded-xl text-xs font-black flex items-center justify-center transition-all';
        if ($active) return "<span class='{$base} bg-[#2e9e63] text-white'>{$label}</span>";
        if ($disabled) return "<span class='{$base} bg-white border border-slate-200 text-slate-300 opacity-50 cursor-not-allowed'>{$label}</span>";
        return "<a href='?page={$target}' class='{$base} bg-white border border-slate-200 text-slate-500 hover:border-[#2e9e63] hover:text-[#2e9e63]'>{$label}</a>";
    };
    echo $btn('&laquo;', 1, $page === 1);
    echo $btn('&lsaquo;', max(1, $page - 1), $page === 1);
    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
        echo $btn((string) $i, $i, false, $i === $page);
    }
    echo $btn('&rsaquo;', min($totalPages, $page + 1), $page === $totalPages);
    echo $btn('&raquo;', $totalPages, $page === $totalPages);
    ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include('includes/student_footer.php'); ?>
