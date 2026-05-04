<?php
// e_Borrow/index.php
declare(strict_types=1);
@session_start();
include('includes/check_student_session.php');

require_once __DIR__ . '/../config.php';
check_maintenance('e_borrow');

$student_id = (int)$_SESSION['student_id'];

try {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT student_personnel_id, full_name FROM sys_users WHERE id = ? LIMIT 1");
    $stmt->execute([$student_id]);
    $student_data = $stmt->fetch();

    $stmt2 = $pdo->prepare(
        "SELECT t.id AS transaction_id, t.borrow_date, t.due_date,
                t.approval_status,
                ei.name AS equipment_name,
                et.image_url,
                et.name AS type_name
         FROM borrow_records t
         JOIN borrow_items ei ON t.item_id = ei.id
         JOIN borrow_categories et ON t.type_id = et.id
         WHERE t.borrower_student_id = ?
           AND t.status = 'borrowed'
           AND t.approval_status IN ('approved','pending')
         ORDER BY t.borrow_date DESC"
    );
    $stmt2->execute([$student_id]);
    $borrowed_items = $stmt2->fetchAll();

    $stmt3 = $pdo->prepare(
        "SELECT SUM(f.amount) AS total
         FROM borrow_fines f
         JOIN borrow_records t ON f.transaction_id = t.id
         WHERE t.borrower_student_id = ? AND f.status = 'pending'"
    );
    $stmt3->execute([$student_id]);
    $fine_row  = $stmt3->fetch();
    $total_fine = (float)($fine_row['total'] ?? 0);

    $pending_count  = count(array_filter($borrowed_items, fn($i) => $i['approval_status'] === 'pending'));
    $approved_count = count(array_filter($borrowed_items, fn($i) => $i['approval_status'] === 'approved'));
    $overdue_count  = count(array_filter($borrowed_items, fn($i) =>
        $i['approval_status'] === 'approved' && strtotime($i['due_date'] . ' 23:59:59') < time()
    ));

} catch (PDOException $e) {
    $error_message  = "เกิดข้อผิดพลาด: " . $e->getMessage();
    $borrowed_items = [];
    $total_fine     = 0;
    $pending_count  = $approved_count = $overdue_count = 0;
}

$fullName     = trim($student_data['full_name'] ?? 'ผู้ใช้');
$cleanName    = preg_replace('/^(นาย|นางสาว|นาง|ว่าที่ร้อยตรี|ด\.ช\.|ด\.ญ\.|Mr\.|Mrs\.|Ms\.|Miss\s?)\s*/u', '', $fullName);
$firstName    = explode(' ', trim($cleanName))[0] ?: 'ผู้ใช้';
$avatarLetter = mb_substr($firstName, 0, 1);
$page_title   = 'หน้าแรก';
$active_page  = 'home';
include('includes/student_header.php');
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<!-- ── Greeting card ── -->
<div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-[2.5rem] p-7 text-white shadow-[0_15px_40px_rgba(46,158,99,0.25)] mb-6 relative overflow-hidden">
    <div class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full blur-2xl"></div>
    <div class="relative z-10">
        <div class="flex items-center gap-4 mb-5">
            <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur-md border border-white/30 flex items-center justify-center text-2xl font-black">
                <?= htmlspecialchars($avatarLetter) ?>
            </div>
            <div class="min-w-0">
                <p class="text-white/70 text-[10px] font-black uppercase tracking-[0.2em]">สวัสดี 👋</p>
                <h2 class="text-lg font-black truncate"><?= htmlspecialchars($firstName) ?></h2>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-2">
            <div class="bg-white/15 backdrop-blur-md border border-white/20 rounded-2xl py-3 text-center">
                <p class="text-2xl font-black"><?= $approved_count ?></p>
                <p class="text-[9px] font-black uppercase tracking-widest text-white/70 mt-0.5">ยืมอยู่</p>
            </div>
            <div class="bg-white/15 backdrop-blur-md border border-white/20 rounded-2xl py-3 text-center">
                <p class="text-2xl font-black <?= $pending_count > 0 ? 'text-amber-200' : '' ?>"><?= $pending_count ?></p>
                <p class="text-[9px] font-black uppercase tracking-widest text-white/70 mt-0.5">รออนุมัติ</p>
            </div>
            <div class="bg-white/15 backdrop-blur-md border border-white/20 rounded-2xl py-3 text-center">
                <p class="text-2xl font-black <?= $overdue_count > 0 ? 'text-rose-200' : '' ?>"><?= $overdue_count ?></p>
                <p class="text-[9px] font-black uppercase tracking-widest text-white/70 mt-0.5">เกินกำหนด</p>
            </div>
        </div>
    </div>
</div>

<!-- ── QR card ── -->
<button onclick="showHomeQRCode()" class="w-full bg-white rounded-[1.8rem] p-5 border border-slate-100 shadow-sm flex items-center gap-4 active:scale-95 transition-all text-left mb-6">
    <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-[#2e9e63] flex items-center justify-center shrink-0">
        <i class="fa-solid fa-qrcode text-xl"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="text-sm font-black text-slate-900">บัตรประจำตัวดิจิทัล</p>
        <p class="text-[11px] font-bold text-slate-400">แสดง QR ให้เจ้าหน้าที่สแกน</p>
    </div>
    <i class="fa-solid fa-chevron-right text-slate-300"></i>
</button>

<?php if (isset($error_message)): ?>
<div class="rounded-2xl bg-rose-50 border border-rose-100 text-rose-700 px-4 py-3 text-sm font-bold mb-4 flex items-center gap-2">
    <i class="fa-solid fa-circle-exclamation"></i>
    <?= htmlspecialchars($error_message) ?>
</div>
<?php endif; ?>

<?php if ($total_fine > 0): ?>
<div class="rounded-2xl bg-gradient-to-br from-rose-500 to-rose-600 text-white px-5 py-4 shadow-[0_10px_25px_rgba(225,29,72,0.2)] mb-4 flex items-center gap-3">
    <div class="w-10 h-10 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
        <i class="fa-solid fa-triangle-exclamation"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="text-sm font-black">มีค่าปรับค้างชำระ</p>
        <p class="text-[11px] font-bold text-white/85">฿<?= number_format($total_fine, 2) ?> · กรุณาติดต่อเจ้าหน้าที่</p>
    </div>
</div>
<?php endif; ?>

<!-- ── Active items section ── -->
<div class="flex items-center justify-between mb-3 px-1">
    <h3 class="text-sm font-black text-slate-900 tracking-tight"><i class="fa-solid fa-hand-holding-medical text-[#2e9e63] mr-1.5"></i>อุปกรณ์ที่ยืมอยู่</h3>
    <a href="history.php" class="text-[11px] font-black text-[#2e9e63]">ดูประวัติ <i class="fa-solid fa-arrow-right text-[9px]"></i></a>
</div>

<?php if (empty($borrowed_items)): ?>
<div class="bg-white rounded-[2rem] p-8 border border-slate-100 shadow-sm text-center">
    <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-emerald-50 text-[#2e9e63] flex items-center justify-center text-2xl">
        <i class="fa-solid fa-boxes-stacked"></i>
    </div>
    <p class="text-sm font-black text-slate-900 mb-1">ยังไม่มีการยืมอุปกรณ์</p>
    <p class="text-[11px] font-bold text-slate-400 mb-5">คุณยังไม่มีรายการยืมอุปกรณ์ในขณะนี้</p>
    <a href="borrow.php" class="inline-flex items-center gap-2 h-12 px-6 rounded-2xl bg-[#2e9e63] text-white font-black text-sm shadow-[0_10px_20px_rgba(46,158,99,0.25)] active:scale-95 transition-all">
        <i class="fa-solid fa-plus"></i> ยืมอุปกรณ์
    </a>
</div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($borrowed_items as $item):
    $isPending  = $item['approval_status'] === 'pending';
    $isOverdue  = !$isPending && strtotime($item['due_date'] . ' 23:59:59') < time();
    $accent     = $isPending ? 'bg-amber-400' : ($isOverdue ? 'bg-rose-500' : 'bg-emerald-500');
    $dueDateFmt = date('d/m/Y', strtotime($item['due_date']));
?>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm flex items-stretch overflow-hidden">
        <div class="w-1 <?= $accent ?> shrink-0"></div>
        <div class="flex items-center gap-3 p-4 flex-1 min-w-0">
            <div class="w-12 h-12 rounded-xl bg-slate-50 flex items-center justify-center shrink-0 overflow-hidden">
                <?php if ($isPending): ?>
                    <i class="fa-solid fa-hourglass-half text-amber-500"></i>
                <?php elseif (!empty($item['image_url'])): ?>
                    <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" class="w-full h-full object-cover" onerror="this.outerHTML='<i class=\'fa-solid fa-image text-slate-300\'></i>'">
                <?php else: ?>
                    <i class="fa-solid fa-stethoscope text-[#2e9e63]"></i>
                <?php endif; ?>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-black text-slate-900 truncate"><?= htmlspecialchars($item['equipment_name']) ?></p>
                <p class="text-[11px] font-bold text-slate-400 mb-1.5"><?= htmlspecialchars($item['type_name']) ?></p>
                <div class="flex items-center gap-2 flex-wrap">
                    <?php if ($isPending): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-100 text-[10px] font-black">
                            <i class="fa-solid fa-hourglass-half text-[8px]"></i> รออนุมัติ
                        </span>
                    <?php elseif ($isOverdue): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-rose-50 text-rose-700 border border-rose-100 text-[10px] font-black">
                            <i class="fa-solid fa-circle-exclamation text-[8px]"></i> เกินกำหนด
                        </span>
                        <span class="text-[10px] font-bold text-rose-600"><?= $dueDateFmt ?></span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100 text-[10px] font-black">
                            <i class="fa-solid fa-circle-check text-[8px]"></i> ยืมอยู่
                        </span>
                        <span class="text-[10px] font-bold text-slate-500">คืน <?= $dueDateFmt ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($isPending): ?>
                <button class="shrink-0 px-3 py-2 rounded-xl bg-rose-50 text-rose-600 text-[11px] font-black active:scale-95 transition-all" onclick="confirmCancelRequest(<?= (int)$item['transaction_id'] ?>)">
                    <i class="fa-solid fa-xmark"></i> ยกเลิก
                </button>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function showHomeQRCode() {
    const studentCode = "<?= htmlspecialchars($student_data['student_personnel_id'] ?? '', ENT_QUOTES) ?>";
    const studentName = "<?= htmlspecialchars($student_data['full_name'] ?? '', ENT_QUOTES) ?>";
    const studentDbId = "<?= $student_id ?>";
    const qrData = "MEDLOAN_STUDENT:" + studentCode + ":" + studentDbId;

    Swal.fire({
        title: 'บัตรประจำตัวดิจิทัล',
        html: `
            <div style="display:flex;flex-direction:column;align-items:center;gap:12px;">
                <div style="padding:12px;background:#fff;border-radius:16px;box-shadow:0 2px 10px rgba(0,0,0,.06);">
                    <div id="qrcode-home-container"></div>
                </div>
                <div>
                    <h3 style="margin:0 0 4px;font-size:1.1rem;font-weight:800;">${studentCode}</h3>
                    <p style="margin:0;color:#666;font-size:.85rem;">${studentName}</p>
                </div>
                <p style="margin:0;font-size:.75rem;color:#2e9e63;background:#ecfdf5;padding:8px 14px;border-radius:10px;">
                    <i class="fa-solid fa-circle-info"></i> ยื่นให้เจ้าหน้าที่สแกนเพื่อยืมอุปกรณ์
                </p>
            </div>`,
        didOpen: () => {
            new QRCode(document.getElementById("qrcode-home-container"), {
                text: qrData, width: 200, height: 200,
                correctLevel: QRCode.CorrectLevel.H
            });
        },
        confirmButtonText: 'ปิด',
        confirmButtonColor: '#2e9e63'
    });
}
</script>

<?php include('includes/student_footer.php'); ?>
