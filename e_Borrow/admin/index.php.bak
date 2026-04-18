<?php
// e_Borrow/admin/index.php
declare(strict_types=1);
include('../includes/check_session.php');
require_once __DIR__ . '/../../../config/db_connect.php'; // ใช้ DB กลาง

try {
    $pdo = db();
    $stmt_borrowed = $pdo->query("SELECT COUNT(*) FROM borrow_items WHERE status = 'borrowed'");
    $count_borrowed = $stmt_borrowed->fetchColumn();
    $stmt_available = $pdo->query("SELECT COUNT(*) FROM borrow_items WHERE status = 'available'");
    $count_available = $stmt_available->fetchColumn();
    $stmt_maintenance = $pdo->query("SELECT COUNT(*) FROM borrow_items WHERE status = 'maintenance'");
    $count_maintenance = $stmt_maintenance->fetchColumn();
    $stmt_overdue = $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE status = 'borrowed' AND approval_status IN ('approved', 'staff_added') AND due_date < CURDATE()");
    $count_overdue = $stmt_overdue->fetchColumn();
} catch (PDOException $e) {
    $count_borrowed = $count_available = $count_maintenance = $count_overdue = 0;
    $kpi_error = "เกิดข้อผิดพลาดในการดึงข้อมูล KPI: " . $e->getMessage(); 
}

// 4. ดึงข้อมูล "รายการรออนุมัติ"
$pending_requests = [];
try {
   $sql_pending = "SELECT 
                        t.id as transaction_id, t.borrow_date, t.due_date,
                        t.reason_for_borrowing, t.attachment_url,
                        t.equipment_id, t.item_id,
                        et.name as equipment_name, ei.serial_number,  
                        s.full_name as student_name, u.full_name as staff_name
                    FROM borrow_records t
                    JOIN borrow_categories et ON t.type_id = et.id 
                    LEFT JOIN borrow_items ei ON t.equipment_id = ei.id 
                    LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                    LEFT JOIN sys_staff u ON t.lending_staff_id = u.id
                    WHERE t.approval_status = 'pending'
                    ORDER BY t.borrow_date ASC";
    
    $stmt_pending = $pdo->prepare($sql_pending);
    $stmt_pending->execute();
    $pending_requests = $stmt_pending->fetchAll();
} catch (PDOException $e) {
    $pending_error = "เกิดข้อผิดพลาดในการดึงข้อมูลคำขอ: " . $e->getMessage(); 
}

// 5. ดึงข้อมูล "รายการที่เกินกำหนดคืน"
$overdue_items = [];
try {
    $sql_overdue = "SELECT 
                        t.id as transaction_id, t.equipment_id, t.due_date, t.fine_status,
                        ei.name as equipment_name, 
                        s.id as student_id, s.full_name as student_name, s.phone_number,
                        DATEDIFF(CURDATE(), t.due_date) AS days_overdue
                    FROM borrow_records t
                    JOIN borrow_items ei ON t.equipment_id = ei.id
                    LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                    WHERE t.status = 'borrowed' 
                      AND t.approval_status IN ('approved', 'staff_added') 
                      AND t.due_date < CURDATE()
                      AND t.fine_status = 'none'
                    ORDER BY t.due_date ASC";
    $stmt_overdue = $pdo->prepare($sql_overdue);
    $stmt_overdue->execute();
    $overdue_items = $stmt_overdue->fetchAll();
} catch (PDOException $e) {
    $overdue_error = "เกิดข้อผิดพลาดในการดึงข้อมูลเกินกำหนด: " . $e->getMessage(); 
}

// 6. ดึงข้อมูล "รายการเคลื่อนไหวล่าสุด" (5 รายการ)
$recent_activity = [];
try {
    $sql_activity = "SELECT 
                        t.approval_status, t.status, t.borrow_date, t.return_date,
                        et.name as equipment_name,
                        s.full_name as student_name
                    FROM borrow_records t
                    JOIN borrow_categories et ON t.type_id = et.id
                    LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                    ORDER BY t.id DESC
                    LIMIT 5";
    $stmt_activity = $pdo->prepare($sql_activity);
    $stmt_activity->execute();
    $recent_activity = $stmt_activity->fetchAll();
} catch (PDOException $e) {
    $activity_error = "เกิดข้อผิดพลาดในการดึงข้อมูลเคลื่อนไหว: " . $e->getMessage(); 
}

$page_title = "Dashboard - ภาพรวม";
$current_page = "index";
include('../includes/header.php'); 
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ===== PAGE WRAPPER ===== */
.admin-wrap { padding: 20px 24px 80px; max-width: 1400px; margin: 0 auto; }

/* ===== DASH HEADER ===== */
.dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.dash-title h2 { font-size: 1.5rem; font-weight: 700; color: var(--color-text-dark); margin: 0; display: flex; align-items: center; gap: 10px; }
.dash-title i { color: var(--color-primary); }

.btn-scan {
    background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark)); color: #fff;
    padding: 10px 20px; border-radius: 12px; text-decoration: none;
    font-weight: 700; font-size: .95rem; display: inline-flex; align-items: center; gap: 8px;
    box-shadow: 0 4px 12px rgba(11,102,35,.2); transition: all .2s;
}
.btn-scan:hover { opacity: .9; transform: translateY(-2px); color: #fff;}

/* ===== KPI ROW ===== */
.kpi-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;
}
.kpi-card {
    background: var(--color-content-bg); border-radius: 16px; padding: 20px;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: var(--box-shadow-main); border: 1px solid var(--border-color);
    position: relative; overflow: hidden;
}
.kpi-card::after {
    content:''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: var(--border-color);
}
.kpi-card.avail::after { background: #22c55e; }
.kpi-card.borrow::after { background: #3b82f6; }
.kpi-card.maint::after { background: #f59e0b; }
.kpi-card.overdue::after { background: #ef4444; }

.kpi-data h4 { font-size: .85rem; color: var(--color-text-muted); margin: 0 0 4px; font-weight: 600; }
.kpi-data .val { font-size: 1.8rem; font-weight: 800; color: var(--color-text-dark); line-height: 1; }
.kpi-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
}
.kpi-card.avail .kpi-icon { background: rgba(22,163,74,.15); color: #22c55e; }
.kpi-card.borrow .kpi-icon { background: rgba(59,130,246,.15); color: #3b82f6; }
.kpi-card.maint .kpi-icon { background: rgba(245,158,11,.15); color: #f59e0b; }
.kpi-card.overdue .kpi-icon { background: rgba(239,68,68,.15); color: #ef4444; }

/* ===== MAIN GRID ===== */
.dash-grid {
    display: grid; grid-template-columns: 2fr 1fr; gap: 20px;
}
@media(max-width: 992px) { .dash-grid { grid-template-columns: 1fr; } }

/* ===== PANELS ===== */
.panel {
    background: var(--color-content-bg); border-radius: 18px; padding: 20px;
    box-shadow: var(--box-shadow-main); border: 1px solid var(--border-color);
    margin-bottom: 20px;
}
.panel-head {
    display: flex; align-items: center; gap: 10px; margin-bottom: 16px;
    padding-bottom: 12px; border-bottom: 1px solid var(--border-color);
}
.panel-head h3 { font-size: 1.1rem; font-weight: 700; color: var(--color-text-dark); margin: 0; }
.panel-head .badge { background: var(--border-color); color: var(--color-text-muted); padding: 2px 8px; border-radius: 12px; font-size: .8rem; }

/* ===== LIST CARDS (Pending, Overdue) ===== */
.list-card {
    border: 1px solid var(--border-color); border-radius: 12px; padding: 14px;
    display: flex; align-items: flex-start; gap: 14px; margin-bottom: 10px;
    transition: background .2s; background: var(--color-content-bg);
}
.list-card:hover { background: var(--color-page-bg); }
.list-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;
}
.list-icon.yellow { background: rgba(202,138,4,.15); color: #ca8a04; }
.list-icon.red { background: rgba(220,38,38,.15); color: #dc2626; }

.list-info { flex: 1; min-width: 0; }
.list-info h4 { font-size: .95rem; font-weight: 700; color: var(--color-text-dark); margin: 0 0 4px; }
.list-info p { font-size: .8rem; color: var(--color-text-muted); margin: 0 0 2px; }
.list-info strong { color: var(--color-text-dark); }
.list-actions {
    display: flex; flex-direction: column; gap: 6px; flex-shrink: 0;
}
.btn-sm-action {
    border: none; padding: 6px 12px; border-radius: 8px;
    font-size: .75rem; font-weight: 700; cursor: pointer; transition: opacity .2s;
    display: flex; align-items: center; gap: 6px; justify-content: center; width: 100px;
}
.btn-approve { background: var(--color-primary); color: #fff; }
.btn-reject { background: transparent; color: #ef4444; border: 1px solid #ef4444; }
.btn-fine { background: #ef4444; color: #fff; }

.link-detail { font-size: .75rem; color: #3b82f6; text-decoration: none; font-weight: 600; margin-top: 6px; display: inline-block; }

/* ===== ACTIVITY LOG ===== */
.act-item {
    display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px dashed var(--border-color);
}
.act-item:last-child { border: none; padding-bottom: 0; }
.act-icon { font-size: 1.2rem; flex-shrink: 0; margin-top: 2px; }
.act-text { font-size: .8rem; color: var(--color-text-muted); line-height: 1.4; margin: 0; }
.act-text strong { color: var(--color-text-dark); }

.empty-msg { text-align: center; color: var(--color-text-muted); font-size: .85rem; padding: 20px 0; }
</style>

<div class="admin-wrap">

    <?php if (isset($kpi_error)) echo "<div class='alert alert-danger'>$kpi_error</div>"; ?>
    <?php if (isset($pending_error)) echo "<div class='alert alert-danger'>$pending_error</div>"; ?>
    <?php if (isset($overdue_error)) echo "<div class='alert alert-danger'>$overdue_error</div>"; ?>

    <div class="dash-header">
        <div class="dash-title">
            <h2><i class="fas fa-tachometer-alt"></i> Dashboard ภาพรวม</h2>
        </div>
        <a href="admin/walkin_borrow.php" class="btn-scan">
            <i class="fas fa-qrcode"></i> สแกนยืม/คืน
        </a>
    </div>

    <!-- KPI ROW -->
    <div class="kpi-row">
        <div class="kpi-card avail">
            <div class="kpi-data">
                <h4>พร้อมใช้งาน</h4>
                <div class="val"><?php echo $count_available; ?></div>
            </div>
            <div class="kpi-icon"><i class="fas fa-box-open"></i></div>
        </div>
        <div class="kpi-card borrow">
            <div class="kpi-data">
                <h4>กำลังถูกยืม</h4>
                <div class="val"><?php echo $count_borrowed; ?></div>
            </div>
            <div class="kpi-icon"><i class="fas fa-hand-holding-medical"></i></div>
        </div>
        <div class="kpi-card maint">
            <div class="kpi-data">
                <h4>ส่งซ่อมบำรุง</h4>
                <div class="val"><?php echo $count_maintenance; ?></div>
            </div>
            <div class="kpi-icon"><i class="fas fa-tools"></i></div>
        </div>
        <?php if ($count_overdue > 0): ?>
        <div class="kpi-card overdue">
            <div class="kpi-data">
                <h4 style="color:#ef4444;">เกินกำหนดคืน (ยังไม่คืน)</h4>
                <div class="val" style="color:#ef4444;"><?php echo $count_overdue; ?></div>
            </div>
            <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- MAIN GRID -->
    <div class="dash-grid">
        <!-- LEFT COLUMN -->
        <div class="col-left">

            <!-- รอดำเนินการ -->
            <div class="panel">
                <div class="panel-head">
                    <i class="fas fa-bell" style="color:#f59e0b; font-size:1.2rem;"></i>
                    <h3>รออนุมัติ</h3>
                    <span class="badge"><?php echo count($pending_requests); ?></span>
                </div>
                
                <?php if (empty($pending_requests)): ?>
                    <p class="empty-msg">ไม่มีคำขอยืมที่รอดำเนินการ</p>
                <?php else: ?>
                    <?php foreach ($pending_requests as $req): ?>
                        <div class="list-card">
                            <div class="list-icon yellow"><i class="fas fa-hourglass-half"></i></div>
                            <div class="list-info">
                                <h4><?php echo htmlspecialchars($req['equipment_name']); ?></h4>
                                <p>ผู้ขอ: <strong><?php echo htmlspecialchars($req['student_name'] ?? '-'); ?></strong></p>
                                <p>คืนวันที่: <strong><?php echo date('d/m/Y', strtotime($req['due_date'])); ?></strong></p>
                                
                                <div style="display:flex; gap:12px; align-items:center;">
                                    <a href="javascript:void(0)" class="link-detail"
                                       onclick="openDetailModal(this)"
                                       data-item="<?php echo htmlspecialchars($req['equipment_name']); ?>"
                                       data-serial="<?php echo htmlspecialchars($req['serial_number'] ?? '-'); ?>"
                                       data-requester="<?php echo htmlspecialchars($req['student_name'] ?? '-'); ?>"
                                       data-borrow="<?php echo date('d/m/Y', strtotime($req['borrow_date'])); ?>"
                                       data-due="<?php echo date('d/m/Y', strtotime($req['due_date'])); ?>"
                                       data-reason="<?php echo htmlspecialchars($req['reason_for_borrowing']); ?>"
                                       data-attachment="<?php echo htmlspecialchars($req['attachment_url'] ?? ''); ?>">
                                       <i class="fas fa-file-alt"></i> ดูรายละเอียด
                                    </a>
                                    <?php if (!empty($req['attachment_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($req['attachment_url']); ?>" target="_blank" class="link-detail" style="color:#10b981;">
                                            <i class="fas fa-paperclip"></i> ไฟล์แนบ
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="list-actions">
                                <button class="btn-sm-action btn-approve" onclick="openApproveSelectionModal(<?php echo $req['transaction_id']; ?>, <?php echo $req['item_id'] ?? 0; ?>, '<?php echo htmlspecialchars($req['equipment_name'], ENT_QUOTES); ?>')"><i class="fas fa-check"></i> อนุมัติ</button>
                                <button class="btn-sm-action btn-reject" onclick="openRejectPopup(<?php echo $req['transaction_id']; ?>)"><i class="fas fa-times"></i> ปฏิเสธ</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- เกินกำหนดชลอ -->
            <div class="panel">
                <div class="panel-head">
                    <i class="fas fa-exclamation-circle" style="color:#ef4444; font-size:1.2rem;"></i>
                    <h3>เกินกำหนดคืน</h3>
                    <span class="badge"><?php echo count($overdue_items); ?></span>
                </div>
                
                <?php if (empty($overdue_items)): ?>
                    <p class="empty-msg">ยอดเยี่ยม! ไม่มีรายการเกินกำหนด</p>
                <?php else: ?>
                    <?php foreach ($overdue_items as $item): 
                        $days_overdue = max(0, (int)$item['days_overdue']);
                        $fine = $days_overdue * (defined('FINE_RATE_PER_DAY') ? FINE_RATE_PER_DAY : 0);
                    ?>
                        <div class="list-card">
                            <div class="list-icon red"><i class="fas fa-calendar-times"></i></div>
                            <div class="list-info">
                                <h4><?php echo htmlspecialchars($item['equipment_name']); ?></h4>
                                <p>ผู้ยืม: <strong><?php echo htmlspecialchars($item['student_name'] ?? '-'); ?></strong></p>
                                <p>เบอร์โทร: <?php echo htmlspecialchars($item['phone_number'] ?? '-'); ?></p>
                                <p style="color:#ef4444; font-weight:700; margin-top:2px;">เลยกำหนดมาแล้ว <?php echo $days_overdue; ?> วัน</p>
                            </div>
                            <div class="list-actions">
                                <button class="btn-sm-action btn-fine" style="width:115px;"
                                    onclick="openFineAndReturnPopup(
                                        <?php echo $item['transaction_id']; ?>, <?php echo $item['student_id'] ?? 0; ?>,
                                        '<?php echo htmlspecialchars(addslashes($item['student_name'] ?? '-')); ?>',
                                        '<?php echo htmlspecialchars(addslashes($item['equipment_name'])); ?>',
                                        <?php echo $days_overdue; ?>, <?php echo $fine; ?>, <?php echo $item['equipment_id']; ?>
                                    )">
                                    <i class="fas fa-coins"></i> คืน/ชำระปรับ
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
        
        <!-- RIGHT COLUMN -->
        <div class="col-right">
            
            <!-- สัดส่วน Chart -->
            <div class="panel">
                <div class="panel-head">
                    <i class="fas fa-chart-pie" style="color:#3b82f6; font-size:1.2rem;"></i>
                    <h3>สัดส่วนอุปกรณ์</h3>
                </div>
                <div style="width: 100%; max-width: 300px; margin: 0 auto;">
                    <canvas id="equipmentStatusChart"></canvas>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="panel">
                <div class="panel-head">
                    <i class="fas fa-history" style="color:#64748b; font-size:1.2rem;"></i>
                    <h3>ความเคลื่อนไหวล่าสุด</h3>
                </div>
                <?php if (empty($recent_activity)): ?>
                    <p class="empty-msg">ยังไม่มีความเคลื่อนไหว</p>
                <?php else: ?>
                    <div class="act-list">
                        <?php foreach ($recent_activity as $act):
                            $icon = '🔵'; 
                            $name = htmlspecialchars($act['student_name'] ?? 'N/A');
                            $eq = htmlspecialchars($act['equipment_name']);
                            if ($act['approval_status'] == 'pending') { $icon='🟡'; $txt="<strong>$name</strong> ขอยืม $eq"; }
                            elseif ($act['approval_status'] == 'rejected') { $icon='⚪'; $txt="ปฏิเสธคำขอของ <strong>$name</strong> ($eq)"; }
                            elseif ($act['status'] == 'returned') { $icon='🟢'; $txt="<strong>$name</strong> คืน $eq แล้ว"; }
                            elseif ($act['approval_status'] == 'approved') { $icon='🔵'; $txt="อนุมัติให้ <strong>$name</strong> ยืม $eq"; }
                            elseif ($act['approval_status'] == 'staff_added') { $icon='🟣'; $txt="บันทึก <strong>$name</strong> ยืม $eq (Walk-in)"; }
                            else { $txt = "อัปเดตสถานะ $eq"; }
                        ?>
                            <div class="act-item">
                                <div class="act-icon"><?= $icon ?></div>
                                <p class="act-text"><?= $txt ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<script>
// ฟังก์ชันเปิด Modal รายละเอียด (คอยป้อนค่าให้ Swal)
function openDetailModal(el) {
    const item = el.getAttribute('data-item');
    const serial = el.getAttribute('data-serial');
    const req = el.getAttribute('data-requester');
    const bDate = el.getAttribute('data-borrow');
    const dDate = el.getAttribute('data-due');
    const reason = el.getAttribute('data-reason');
    const attachment = el.getAttribute('data-attachment');

    Swal.fire({
        title: 'รายละเอียดการยืม',
        html: `
            <div style="text-align: left; padding: 10px; font-size:.9rem;">
                <p><strong>ชื่ออุปกรณ์:</strong> ${item}</p>
                <p><strong>Serial Number:</strong> ${serial !== '-' ? serial : 'ยังไม่ระบุ'}</p>
                <p><strong>ผู้ขอ:</strong> ${req}</p>
                <p><strong>วันที่ยืม:</strong> ${bDate}</p>
                <p><strong>กำหนดคืน:</strong> <span style="color:#ef4444">${dDate}</span></p>
                <hr style="margin:10px 0;">
                <p><strong>เหตุผล:</strong></p>
                <div style="background:var(--color-page-bg, #f1f5f9); padding:10px; border-radius:8px; white-space:pre-wrap;">${reason}</div>
                ${attachment ? `<div class="mt-3"><strong><i class="fas fa-paperclip"></i> เอกสาร:</strong> <a href="${attachment}" target="_blank" class="btn btn-sm" style="background:#0ea5e9; color:#fff;">ดูไฟล์แนบ</a></div>` : ''}
            </div>
        `,
        confirmButtonText: 'ปิด', width: '500px',
        customClass: { popup: document.body.classList.contains('dark-mode') ? 'dark-swal' : '' }
    });
}

document.addEventListener("DOMContentLoaded", function() {
    // Chart.js
    const ctx = document.getElementById('equipmentStatusChart');
    if(ctx) {
        const isDark = document.body.classList.contains('dark-mode');
        const equipmentChart = new Chart(ctx.getContext('2d'), {
           type: 'doughnut', 
           data: {
               labels: ['พร้อมใช้', 'ถูกยืม', 'ส่งซ่อม'],
               datasets: [{
                   data: [<?= $count_available ?>, <?= $count_borrowed ?>, <?= $count_maintenance ?>],
                   backgroundColor: ['#22c55e', '#3b82f6', '#f59e0b'],
                   borderWidth: 0,
                   hoverOffset: 4
               }]
           },
           options: { 
               responsive: true, cutout: '70%',
               plugins: { legend: { position: 'bottom', labels: { color: isDark ? '#e2e8f0' : '#475569', font:{size:13} } } } 
           }
        });

        const toggleBtn = document.getElementById('theme-toggle-btn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                setTimeout(() => {
                    const darkNow = document.body.classList.contains('dark-mode');
                    equipmentChart.options.plugins.legend.labels.color = darkNow ? '#e2e8f0' : '#475569';
                    equipmentChart.update();
                }, 50);
            });
        }
    }
});
</script>

<?php include('../includes/footer.php'); ?>
