<?php
// e_Borrow/history.php
declare(strict_types=1);
@session_start();
include('includes/check_student_session.php');

// ใช้ DB กลาง (Correct path to Root)
require_once __DIR__ . '/includes/db_connect.php';

$student_id = (int)$_SESSION['student_id'];

// ดึงประวัติเฉพาะรายการที่คืนแล้ว, รออนุมัติ, หรือถูกปฏิเสธ/ยกเลิก
try {
    $pdo = db();
    $sql_history = "SELECT t.id, t.borrow_date, t.due_date, t.return_date, 
                           t.status, t.approval_status,
                           et.name as type_name, et.image_url,
                           ei.name as eq_name
                    FROM borrow_records t
                    JOIN borrow_categories et ON t.type_id = et.id
                    LEFT JOIN borrow_items ei ON t.item_id = ei.id
                    WHERE t.borrower_student_id = ? 
                      AND (t.status IN ('returned', 'cancelled') OR t.approval_status IN ('pending', 'rejected'))
                    ORDER BY t.borrow_date DESC, t.id DESC";
    
    $stmt_history = $pdo->prepare($sql_history);
    $stmt_history->execute([$student_id]);
    $history = $stmt_history->fetchAll();
} catch (PDOException $e) {
    $history = [];
    $history_error = "เกิดข้อผิดพลาด: " . $e->getMessage();
}

$page_title  = "ประวัติคำขอ";
$active_page = 'history';
include('includes/student_header.php');
?>

<style>
/* ===== PAGE WRAPPER ===== */
.page-wrap { padding: 0 0 80px; }

/* ===== TOP HEADER ===== */
.history-header {
    background: linear-gradient(135deg, #0052CC 0%, #0070f3 100%);
    padding: 30px 20px 45px;
    text-align: center;
    position: relative;
    border-bottom-left-radius: 24px;
    border-bottom-right-radius: 24px;
    box-shadow: 0 4px 12px rgba(11,102,35,.15);
}
.history-header::before {
    content: ''; position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='28'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none; border-bottom-left-radius: 24px; border-bottom-right-radius: 24px;
}
.history-header h2 { color: #fff; font-size: 1.3rem; font-weight: 700; margin: 0 0 4px; position: relative; z-index: 1;}
.history-header p  { color: rgba(255,255,255,.8); font-size: .85rem; margin: 0; position: relative; z-index: 1;}

/* ===== SECTION BODY ===== */
.section-body { padding: 20px 16px 0; margin-top: -20px; position: relative; z-index: 10; }

/* ===== HISTORY CARDS ===== */
.hist-card {
    background: #fff;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,.04);
    border: 1px solid rgba(0,0,0,.03);
    display: flex; gap: 14px; align-items: flex-start;
    transition: transform .15s, box-shadow .15s;
}
.hist-card:active { transform: scale(.98); }

/* Thumb */
.hist-thumb {
    width: 60px; height: 60px; border-radius: 14px;
    background: #f1f5f9;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
    color: #cbd5e1; font-size: 1.6rem;
}
.hist-thumb img { width: 100%; height: 100%; object-fit: cover; }

/* Content */
.hist-content { flex: 1; min-width: 0; }
.hist-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; gap: 8px;}
.hist-title {
    font-size: .95rem; font-weight: 700; color: #1e293b; margin: 0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.hist-item { font-size: .75rem; color: #64748b; margin: 0 0 6px; }

/* Dates */
.hist-dates {
    display: flex; flex-direction: column; gap: 3px;
    padding: 8px; background: #f8fafc; border-radius: 8px;
    margin-bottom: 10px;
}
.date-row { display: flex; justify-content: space-between; font-size: .72rem; }
.date-row span { color: #64748b; }
.date-row strong { color: #475569; font-weight: 600; }

/* Status Badge & Actions */
.hist-footer { display: flex; justify-content: space-between; align-items: center; }

.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px; border-radius: 20px;
    font-size: .75rem; font-weight: 700;
}
.status-badge i { font-size: .8rem; }

/* สีของ Status */
.status-returned { background: #dcfce7; color: #16a34a; }
.status-pending  { background: #fef9c3; color: #ca8a04; }
.status-rejected { background: #f1f5f9; color: #64748b; }
.status-cancelled { background: #fee2e2; color: #dc2626; }

.btn-cancel {
    background: transparent; color: #dc2626;
    border: 1px solid #fecaca; border-radius: 8px;
    padding: 5px 12px; font-size: .75rem; font-weight: 600;
    cursor: pointer; transition: all .2s;
}
.btn-cancel:hover { background: #fee2e2; }

/* Empty state */
.empty-state {
    text-align: center; padding: 40px 20px;
    background: #fff; border-radius: 18px;
    box-shadow: 0 4px 12px rgba(0,0,0,.04);
}
.empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 12px; }
.empty-state p { color: #64748b; font-size: .9rem; margin: 0 0 16px; }
.btn-primary-sm {
    display: inline-block; padding: 8px 16px;
    background: #0052CC; color: #fff; border-radius: 10px;
    text-decoration: none; font-size: .85rem; font-weight: 600;
}

/* ===== DARK MODE OVERRIDES ===== */
body.dark-mode .hist-card { background: #162040; border-color: rgba(255,255,255,.05); box-shadow: 0 4px 12px rgba(0,0,0,.2); }
body.dark-mode .hist-thumb { background: #0f1a35; color: #334155; }
body.dark-mode .hist-title { color: #e2e8f0; }
body.dark-mode .hist-item { color: #94a3b8; }
body.dark-mode .hist-dates { background: rgba(255,255,255,.03); }
body.dark-mode .date-row span { color: #94a3b8; }
body.dark-mode .date-row strong { color: #cbd5e1; }

body.dark-mode .status-returned { background: rgba(22,163,74,.2); color: #60a5fa; }
body.dark-mode .status-pending  { background: rgba(202,138,4,.2); color: #facc15; }
body.dark-mode .status-rejected { background: rgba(100,116,139,.2); color: #94a3b8; }
body.dark-mode .status-cancelled{ background: rgba(220,38,38,.2); color: #f87171; }

body.dark-mode .btn-cancel { border-color: rgba(220,38,38,.4); color: #f87171; }
body.dark-mode .btn-cancel:hover { background: rgba(220,38,38,.15); }
body.dark-mode .empty-state { background: #162040; }
</style>

<div class="page-wrap">
    
    <div class="history-header">
        <h2><i class="fas fa-history" style="margin-right:8px;"></i>ประวัติคำขอ</h2>
        <p>รายการที่คุณเคยส่งคำขอยืม ปฏิเสธ และคืนแล้ว</p>
    </div>

    <div class="section-body">
        <?php if (!empty($history_error)): ?>
            <div style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:12px; margin-bottom:16px; font-size:.85rem; font-weight:600;">
                <?= htmlspecialchars($history_error) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($history)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <p>คุณยังไม่มีประวัติการทำรายการ</p>
                <a href="borrow.php" class="btn-primary-sm">ไปยืมอุปกรณ์กันเลย</a>
            </div>
        <?php else: ?>
            <?php foreach ($history as $row): 
                
                // กำหนดสถานะ UI
                $status       = $row['status'];
                $app_status   = $row['approval_status'];
                
                $badgeClass = '';
                $badgeText  = '';
                $badgeIcon  = '';
                $isPending  = false;

                if ($status === 'returned') {
                    $badgeClass = 'status-returned';
                    $badgeText  = 'คืนแล้ว';
                    $badgeIcon  = 'fa-check-circle';
                } elseif ($app_status === 'pending') {
                    $badgeClass = 'status-pending';
                    $badgeText  = 'รอดำเนินการ';
                    $badgeIcon  = 'fa-hourglass-half';
                    $isPending  = true;
                } elseif ($app_status === 'rejected') {
                    $badgeClass = 'status-rejected';
                    $badgeText  = 'ถูกปฏิเสธ/ยกเลิก';
                    $badgeIcon  = 'fa-ban';
                } elseif ($status === 'cancelled') {
                    $badgeClass = 'status-cancelled';
                    $badgeText  = 'ยกเลิกแล้ว';
                    $badgeIcon  = 'fa-times-circle';
                } else {
                    $badgeClass = 'status-rejected';
                    $badgeText  = 'ไม่ทราบสถานะ';
                    $badgeIcon  = 'fa-question-circle';
                }

                // สลับชื่อ (เน้น item ถ้ามี ไม่งั้นใช้ type)
                $displayName = !empty($row['eq_name']) ? $row['eq_name'] : $row['type_name'];
                $displayType = !empty($row['eq_name']) ? $row['type_name'] : 'ประเภทอุปกรณ์';
            ?>
                
            <div class="hist-card">
                <div class="hist-thumb">
                    <?php if (!empty($row['image_url'])): ?>
                        <img src="<?= htmlspecialchars($row['image_url']) ?>" alt="img" onerror="this.style.display='none';">
                    <?php else: ?>
                        <i class="fas fa-stethoscope"></i>
                    <?php endif; ?>
                </div>

                <div class="hist-content">
                    <div class="hist-header">
                        <div style="min-width:0;">
                            <h3 class="hist-title" title="<?= htmlspecialchars($displayName) ?>"><?= htmlspecialchars($displayName) ?></h3>
                            <p class="hist-item"><?= htmlspecialchars($displayType) ?></p>
                        </div>
                    </div>

                    <div class="hist-dates">
                        <div class="date-row">
                            <span>วันที่ส่งคำขอ</span>
                            <strong><?= date('d/m/Y H:i', strtotime($row['borrow_date'])) ?></strong>
                        </div>
                        <?php if ($status === 'returned' && $row['return_date']): ?>
                            <div class="date-row">
                                <span>วันที่คืน</span>
                                <strong style="color:#16a34a;"><?= date('d/m/Y H:i', strtotime($row['return_date'])) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="hist-footer">
                        <div class="status-badge <?= $badgeClass ?>">
                            <i class="fas <?= $badgeIcon ?>"></i> <?= $badgeText ?>
                        </div>

                        <?php if ($isPending): ?>
                            <button type="button" class="btn-cancel" onclick="confirmCancelRequest(<?= $row['id'] ?>)">
                                ยกเลิก
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="assets/js/student_app.js"></script>

<script>
function confirmCancelRequest(transactionId) {
    Swal.fire({
        title: 'ยืนยันการยกเลิก?',
        text: 'คำขอยืมอุปกรณ์นี้จะถูกยกเลิก',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'ยืนยัน ยกเลิก',
        cancelButtonText: 'ไม่ยกเลิก',
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `process/cancel_request.php?id=${transactionId}`;
        }
    });
}
</script>

<?php include('includes/student_footer.php'); ?>