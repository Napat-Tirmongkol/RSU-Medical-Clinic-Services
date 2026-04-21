<?php
// e_Borrow/index.php
declare(strict_types=1);
@session_start();
include('includes/check_student_session.php');

// ใช้ไฟล์ Config กลาง
require_once __DIR__ . '/../config.php';

// ตรวจสอบ Maintenance Mode
check_maintenance('e_borrow');

$student_id = (int)$_SESSION['student_id'];

try {
    $pdo = db();

    // ข้อมูลนักศึกษา
    $stmt = $pdo->prepare("SELECT student_personnel_id, full_name FROM sys_users WHERE id = ? LIMIT 1");
    $stmt->execute([$student_id]);
    $student_data = $stmt->fetch();

    // รายการ "ยืมอยู่" และ "รออนุมัติ"
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

    // ค่าปรับค้างชำระ
    $stmt3 = $pdo->prepare(
        "SELECT SUM(f.amount) AS total
         FROM borrow_fines f
         JOIN borrow_records t ON f.transaction_id = t.id
         WHERE t.borrower_student_id = ? AND f.status = 'pending'"
    );
    $stmt3->execute([$student_id]);
    $fine_row  = $stmt3->fetch();
    $total_fine = (float)($fine_row['total'] ?? 0);

    // สรุปตัวเลข
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
// ตัดคำนำหน้าชื่อเพื่อเอาชื่อจริง
$cleanName    = preg_replace('/^(นาย|นางสาว|นาง|ว่าที่ร้อยตรี|ด\.ช\.|ด\.ญ\.|Mr\.|Mrs\.|Ms\.|Miss\s?)\s*/u', '', $fullName);
$firstName    = explode(' ', trim($cleanName))[0] ?: 'ผู้ใช้';
$avatarLetter = mb_substr($firstName, 0, 1);
$page_title   = 'หน้าแรก';
$active_page  = 'home';
include('includes/student_header.php');
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<style>
/* ===== RESET & BASE ===== */
.page-wrap { padding: 0 0 80px; }

/* ===== HERO ===== */
.hero {
    background: linear-gradient(135deg, #0052CC 0%, #0070f3 100%);
    padding: 28px 20px 64px;
    position: relative; overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='28'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.hero-content { position: relative; z-index: 1; }
.hero-greeting { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
.hero-avatar {
    width: 50px; height: 50px; border-radius: 14px;
    background: rgba(255,255,255,.2); backdrop-filter: blur(8px);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; font-weight: 700; color: #fff;
    border: 2px solid rgba(255,255,255,.3);
    flex-shrink: 0;
}
.hero-text p  { color: rgba(255,255,255,.75); font-size: .8rem; margin: 0 0 2px; }
.hero-text h2 { color: #fff; font-size: 1.15rem; font-weight: 700; margin: 0; }

/* Stats row */
.stats-row {
    display: grid; grid-template-columns: repeat(3,1fr); gap: 10px;
}
.stat-chip {
    background: rgba(255,255,255,.12); backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 14px; padding: 12px 10px; text-align: center;
}
.stat-chip .num { font-size: 1.5rem; font-weight: 800; color: #fff; line-height: 1; }
.stat-chip .lbl { font-size: .68rem; color: rgba(255,255,255,.7); margin-top: 3px; }
.stat-chip.warn .num { color: #fbbf24; }
.stat-chip.danger .num { color: #f87171; }

/* ===== QR FLOATING CARD ===== */
.qr-float {
    margin: -38px 16px 0;
    position: relative; z-index: 10;
}
.qr-card {
    background: #fff;
    border-radius: 20px;
    padding: 16px 20px;
    display: flex; align-items: center; gap: 16px;
    box-shadow: 0 8px 24px rgba(0,0,0,.10);
    cursor: pointer;
    transition: transform .2s, box-shadow .2s;
    border: 1px solid rgba(0,82,204,.08);
}
.qr-card:active { transform: scale(.97); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.qr-icon-box {
    width: 52px; height: 52px; border-radius: 14px;
    background: linear-gradient(135deg, #0052CC, #0070f3);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.qr-icon-box i { font-size: 1.6rem; color: #fff; }
.qr-info h4 { font-size: .95rem; font-weight: 700; color: #1a1a1a; margin: 0 0 2px; }
.qr-info p  { font-size: .75rem; color: #888; margin: 0; }
.qr-arrow   { margin-left: auto; color: #0052CC; font-size: 1rem; }

/* ===== SECTION ===== */
.section { padding: 20px 16px 0; }
.section-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.section-head h3 { font-size: .95rem; font-weight: 700; color: #1a1a1a; margin: 0; }
.section-head a  { font-size: .78rem; color: #0052CC; font-weight: 600; text-decoration: none; }

/* ===== FINE BANNER ===== */
.fine-banner {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    border-radius: 16px; padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 16px; box-shadow: 0 4px 12px rgba(220,38,38,.2);
}
.fine-banner i  { font-size: 1.5rem; color: #fca5a5; flex-shrink: 0; }
.fine-banner .fine-text h4 { color: #fff; font-size: .9rem; font-weight: 700; margin: 0 0 2px; }
.fine-banner .fine-text p  { color: rgba(255,255,255,.8); font-size: .8rem; margin: 0; }

/* ===== DARK MODE OVERRIDES ===== */
body.dark-mode .qr-card {
    background: #162040;
    border-color: rgba(255,255,255,.08);
    box-shadow: 0 8px 24px rgba(0,0,0,.3);
}
body.dark-mode .qr-info h4 { color: #e2e8f0; }
body.dark-mode .qr-info p  { color: #94a3b8; }
body.dark-mode .qr-arrow   { color: #60a5fa; }

body.dark-mode .item-card {
    background: #162040;
    border-color: rgba(255,255,255,.05);
    box-shadow: 0 2px 10px rgba(0,0,0,.25);
}
body.dark-mode .item-info h4   { color: #e2e8f0; }
body.dark-mode .item-info .type-tag { color: #64748b; }
body.dark-mode .due-text       { color: #94a3b8; }
body.dark-mode .item-thumb     { background: #0f1a35; }

body.dark-mode .pill-ok      { background: rgba(22,163,74,.2);  color: #60a5fa; }
body.dark-mode .pill-pending { background: rgba(245,158,11,.15); color: #fbbf24; }
body.dark-mode .pill-overdue { background: rgba(220,38,38,.2);  color: #f87171; }

body.dark-mode .section-head h3 { color: #e2e8f0; }
body.dark-mode .section-head a  { color: #60a5fa; }

body.dark-mode .empty-state {
    background: #162040;
    box-shadow: 0 2px 10px rgba(0,0,0,.25);
}
body.dark-mode .empty-state h4 { color: #e2e8f0; }
body.dark-mode .empty-state p  { color: #64748b; }
body.dark-mode .empty-icon     { background: linear-gradient(135deg, #0f1a35, #162040); }

body.dark-mode .error-banner {
    background: rgba(220,38,38,.15);
    color: #fca5a5;
}

body.dark-mode .btn-cancel-sm {
    background: rgba(220,38,38,.2);
    color: #f87171;
}
body.dark-mode .btn-cancel-sm:active { background: rgba(220,38,38,.3); }

/* ===== ITEM CARDS ===== */
.item-card {
    background: #fff;
    border-radius: 18px;
    padding: 14px 16px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    margin-bottom: 10px;
    border: 1px solid rgba(0,0,0,.05);
    position: relative; overflow: hidden;
    transition: transform .15s, box-shadow .15s;
}
.item-card:active { transform: scale(.99); }
.item-card::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
    background: #16a34a; border-radius: 4px 0 0 4px;
}
.item-card.pending::before  { background: #f59e0b; }
.item-card.overdue::before  { background: #dc2626; }

.item-thumb {
    width: 52px; height: 52px; border-radius: 12px;
    object-fit: cover; flex-shrink: 0;
    background: #eff6ff;
    display: flex; align-items: center; justify-content: center;
}
.item-thumb img { width: 100%; height: 100%; border-radius: 12px; object-fit: cover; }
.item-thumb i   { font-size: 1.4rem; color: #aaa; }

.item-info { flex: 1; min-width: 0; }
.item-info h4 {
    font-size: .88rem; font-weight: 700; color: #1a1a1a; margin: 0 0 3px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.item-info .type-tag {
    font-size: .7rem; color: #888; margin: 0 0 5px;
}
.item-info .due-row { display: flex; align-items: center; gap: 5px; }

.status-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px; border-radius: 20px;
    font-size: .7rem; font-weight: 700;
}
.pill-ok      { background: #dcfce7; color: #15803d; }
.pill-pending { background: #fef3c7; color: #b45309; }
.pill-overdue { background: #fee2e2; color: #dc2626; }

.due-text { font-size: .72rem; color: #666; }
.due-text.overdue { color: #dc2626; font-weight: 700; }

.item-action { flex-shrink: 0; }
.btn-cancel-sm {
    padding: 6px 12px; border-radius: 10px; border: none; cursor: pointer;
    font-size: .72rem; font-weight: 700; font-family: inherit;
    background: #fee2e2; color: #dc2626;
    transition: background .2s, transform .15s;
}
.btn-cancel-sm:active { transform: scale(.95); background: #fecaca; }

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center; padding: 36px 20px;
    background: #fff; border-radius: 18px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
}
.empty-icon {
    width: 72px; height: 72px; border-radius: 20px;
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; margin: 0 auto 14px;
}
.empty-state h4 { font-size: 1rem; font-weight: 700; color: #1a1a1a; margin: 0 0 6px; }
.empty-state p  { font-size: .83rem; color: #888; margin: 0 0 18px; line-height: 1.5; }
.btn-borrow {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 24px; border-radius: 14px;
    background: linear-gradient(135deg, #0052CC, #0070f3);
    color: #fff; font-weight: 700; font-size: .88rem;
    text-decoration: none; box-shadow: 0 4px 14px rgba(0,82,204,.25);
    transition: opacity .2s, transform .15s;
}
.btn-borrow:hover  { opacity: .9; }
.btn-borrow:active { transform: scale(.97); }

/* Error banner */
.error-banner {
    background: #fee2e2; color: #991b1b;
    border-radius: 14px; padding: 14px 16px; margin-bottom: 12px;
    font-size: .85rem; font-weight: 600;
    display: flex; align-items: center; gap: 10px;
}
</style>

<div class="page-wrap">

    <!-- ===== HERO ===== -->
    <div class="hero">
        <div class="hero-content">
            <div class="hero-greeting">
                <div class="hero-avatar"><?= $avatarLetter ?></div>
                <div class="hero-text">
                    <p>สวัสดี 👋</p>
                    <h2><?= htmlspecialchars($firstName) ?></h2>
                </div>
            </div>

            <!-- Stats -->
            <div class="stats-row">
                <div class="stat-chip">
                    <div class="num"><?= $approved_count ?></div>
                    <div class="lbl">ยืมอยู่</div>
                </div>
                <div class="stat-chip <?= $pending_count > 0 ? 'warn' : '' ?>">
                    <div class="num"><?= $pending_count ?></div>
                    <div class="lbl">รออนุมัติ</div>
                </div>
                <div class="stat-chip <?= $overdue_count > 0 ? 'danger' : '' ?>">
                    <div class="num"><?= $overdue_count ?></div>
                    <div class="lbl">เกินกำหนด</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== QR FLOATING CARD ===== -->
    <div class="qr-float">
        <div class="qr-card" onclick="showHomeQRCode()">
            <div class="qr-icon-box">
                <i class="fas fa-qrcode"></i>
            </div>
            <div class="qr-info">
                <h4>บัตรประจำตัวดิจิทัล</h4>
                <p>แสดง QR ให้เจ้าหน้าที่สแกนยืมอุปกรณ์</p>
            </div>
            <i class="fas fa-chevron-right qr-arrow"></i>
        </div>
    </div>

    <!-- ===== MAIN SECTION ===== -->
    <div class="section" style="margin-top: 20px;">

        <?php if (isset($error_message)): ?>
        <div class="error-banner">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error_message) ?>
        </div>
        <?php endif; ?>

        <?php if ($total_fine > 0): ?>
        <div class="fine-banner">
            <i class="fas fa-exclamation-triangle"></i>
            <div class="fine-text">
                <h4>มีค่าปรับค้างชำระ</h4>
                <p><?= number_format($total_fine, 2) ?> บาท — กรุณาติดต่อเจ้าหน้าที่</p>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-head">
            <h3><i class="fas fa-hand-holding-medical" style="color:#0052CC; margin-right:6px;"></i>อุปกรณ์ที่ยืมอยู่</h3>
            <a href="history.php">ดูประวัติ →</a>
        </div>

        <?php if (empty($borrowed_items)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-boxes-stacked" style="color:#16a34a;"></i></div>
            <h4>ยังไม่มีการยืมอุปกรณ์</h4>
            <p>คุณยังไม่มีรายการยืมอุปกรณ์<br>ในขณะนี้</p>
            <a href="borrow.php" class="btn-borrow">
                <i class="fas fa-plus"></i> ยืมอุปกรณ์
            </a>
        </div>

        <?php else: ?>
        <?php foreach ($borrowed_items as $item):
            $isPending  = $item['approval_status'] === 'pending';
            $isOverdue  = !$isPending && strtotime($item['due_date'] . ' 23:59:59') < time();
            $cardClass  = $isPending ? 'pending' : ($isOverdue ? 'overdue' : '');
            $dueDateFmt = date('d/m/Y', strtotime($item['due_date']));
        ?>
        <div class="item-card <?= $cardClass ?>">

            <!-- Thumbnail -->
            <div class="item-thumb">
                <?php if ($isPending): ?>
                    <i class="fas fa-hourglass-half" style="color:#f59e0b;"></i>
                <?php elseif (!empty($item['image_url'])): ?>
                    <img src="<?= htmlspecialchars($item['image_url']) ?>"
                         alt="รูปอุปกรณ์"
                         onerror="this.parentElement.innerHTML='<i class=\'fas fa-image\'></i>'">
                <?php else: ?>
                    <i class="fas fa-stethoscope" style="color:#0052CC;"></i>
                <?php endif; ?>
            </div>

            <!-- Info -->
            <div class="item-info">
                <h4><?= htmlspecialchars($item['equipment_name']) ?></h4>
                <p class="type-tag"><?= htmlspecialchars($item['type_name']) ?></p>

                <div class="due-row">
                    <?php if ($isPending): ?>
                        <span class="status-pill pill-pending">
                            <i class="fas fa-hourglass-half" style="font-size:.6rem;"></i> รออนุมัติ
                        </span>
                    <?php elseif ($isOverdue): ?>
                        <span class="status-pill pill-overdue">
                            <i class="fas fa-circle-exclamation" style="font-size:.6rem;"></i> เกินกำหนด
                        </span>
                        <span class="due-text overdue"><?= $dueDateFmt ?></span>
                    <?php else: ?>
                        <span class="status-pill pill-ok">
                            <i class="fas fa-circle-check" style="font-size:.6rem;"></i> ยืมอยู่
                        </span>
                        <span class="due-text">คืน <?= $dueDateFmt ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action -->
            <?php if ($isPending): ?>
            <div class="item-action">
                <button class="btn-cancel-sm" onclick="confirmCancelRequest(<?= $item['transaction_id'] ?>)">
                    <i class="fas fa-times"></i> ยกเลิก
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

    </div><!-- /section -->
</div><!-- /page-wrap -->

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
                <div style="padding:12px;background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.08);">
                    <div id="qrcode-home-container"></div>
                </div>
                <div>
                    <h3 style="margin:0 0 4px;font-size:1.1rem;">${studentCode}</h3>
                    <p style="margin:0;color:#666;font-size:.85rem;">${studentName}</p>
                </div>
                <p style="margin:0;font-size:.75rem;color:#0052CC;background:#eff6ff;padding:8px 14px;border-radius:8px;">
                    <i class="fas fa-info-circle"></i> ยื่นให้เจ้าหน้าที่สแกนเพื่อยืมอุปกรณ์
                </p>
            </div>`,
        didOpen: () => {
            new QRCode(document.getElementById("qrcode-home-container"), {
                text: qrData, width: 200, height: 200,
                correctLevel: QRCode.CorrectLevel.H
            });
        },
        confirmButtonText: 'ปิด',
        confirmButtonColor: '#0052CC',
        showClass: { popup: 'animate__animated animate__fadeInUp animate__faster' }
    });
}


</script>

<?php include('includes/student_footer.php'); ?>
