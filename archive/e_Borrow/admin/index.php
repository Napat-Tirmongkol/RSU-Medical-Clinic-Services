<?php
// e_Borrow/admin/index.php
declare(strict_types=1);
include('../includes/check_session.php');
require_once __DIR__ . '/../../../config/db_connect.php'; // เนเธเน DB เธเธฅเธฒเธ

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
    $kpi_error = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅ KPI: " . $e->getMessage(); 
}

// 4. เธ”เธถเธเธเนเธญเธกเธนเธฅ "เธฃเธฒเธขเธเธฒเธฃเธฃเธญเธญเธเธธเธกเธฑเธ•เธด"
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
    $pending_error = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅเธเธณเธเธญ: " . $e->getMessage(); 
}

// 5. เธ”เธถเธเธเนเธญเธกเธนเธฅ "เธฃเธฒเธขเธเธฒเธฃเธ—เธตเนเน€เธเธดเธเธเธณเธซเธเธ”เธเธทเธ"
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
    $overdue_error = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅเน€เธเธดเธเธเธณเธซเธเธ”: " . $e->getMessage(); 
}

// 6. เธ”เธถเธเธเนเธญเธกเธนเธฅ "เธฃเธฒเธขเธเธฒเธฃเน€เธเธฅเธทเนเธญเธเนเธซเธงเธฅเนเธฒเธชเธธเธ”" (5 เธฃเธฒเธขเธเธฒเธฃ)
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
    $activity_error = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅเน€เธเธฅเธทเนเธญเธเนเธซเธง: " . $e->getMessage(); 
}

$page_title = "Dashboard - เธ เธฒเธเธฃเธงเธก";
$current_page = "index";
include('../includes/header.php'); 
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ===== PAGE WRAPPER ===== */
.admin-wrap { padding: 20px 24px 80px; max-width: 1400px; margin: 0 auto; }

/* ===== DASH HEADER ===== */
.dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.dash-title h2 { font-size: 1.5rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px; }
.dash-title i { color: #0B6623; }

.btn-scan {
    background: linear-gradient(135deg, #0B6623, #1a8c35); color: #fff;
    padding: 10px 20px; border-radius: 12px; text-decoration: none;
    font-weight: 700; font-size: .95rem; display: inline-flex; align-items: center; gap: 8px;
    box-shadow: 0 4px 12px rgba(11,102,35,.2); transition: all .2s;
}
.btn-scan:hover { opacity: .9; transform: translateY(-2px); color: #fff;}
.btn-scan:active { transform: translateY(0); }

/* ===== KPI ROW ===== */
.kpi-row {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;
}
.kpi-card {
    background: #fff; border-radius: 16px; padding: 20px;
    display: flex; align-items: center; justify-content: space-between;
    box-shadow: 0 2px 10px rgba(0,0,0,.04); border: 1px solid rgba(0,0,0,.03);
    position: relative; overflow: hidden;
}
.kpi-card::after {
    content:''; position: absolute; left: 0; top: 0; bottom: 0; width: 5px; background: #e2e8f0;
}
.kpi-card.avail::after { background: #22c55e; }
.kpi-card.borrow::after { background: #3b82f6; }
.kpi-card.maint::after { background: #f59e0b; }
.kpi-card.overdue::after { background: #ef4444; }

.kpi-data h4 { font-size: .85rem; color: #64748b; margin: 0 0 4px; font-weight: 600; }
.kpi-data .val { font-size: 1.8rem; font-weight: 800; color: #1e293b; line-height: 1; }
.kpi-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center; font-size: 1.4rem;
}
.kpi-card.avail .kpi-icon { background: #dcfce7; color: #16a34a; }
.kpi-card.borrow .kpi-icon { background: #dbeafe; color: #2563eb; }
.kpi-card.maint .kpi-icon { background: #fef3c7; color: #d97706; }
.kpi-card.overdue .kpi-icon { background: #fee2e2; color: #dc2626; }

/* ===== MAIN GRID ===== */
.dash-grid {
    display: grid; grid-template-columns: 2fr 1fr; gap: 20px;
}
@media(max-width: 992px) { .dash-grid { grid-template-columns: 1fr; } }

/* ===== PANELS ===== */
.panel {
    background: #fff; border-radius: 18px; padding: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,.03); border: 1px solid rgba(0,0,0,.03);
    margin-bottom: 20px;
}
.panel-head {
    display: flex; align-items: center; gap: 10px; margin-bottom: 16px;
    padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;
}
.panel-head h3 { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin: 0; }
.panel-head .badge { background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 12px; font-size: .8rem; }

/* ===== LIST CARDS (Pending, Overdue) ===== */
.list-card {
    border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px;
    display: flex; align-items: flex-start; gap: 14px; mb-margin: 10px;
    transition: background .2s; margin-bottom: 10px;
}
.list-card:hover { background: #f8fafc; }
.list-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;
}
.list-icon.yellow { background: #fef9c3; color: #ca8a04; }
.list-icon.red { background: #fee2e2; color: #dc2626; }

.list-info { flex: 1; min-width: 0; }
.list-info h4 { font-size: .95rem; font-weight: 700; color: #1e293b; margin: 0 0 4px; }
.list-info p { font-size: .8rem; color: #64748b; margin: 0 0 2px; }
.list-info strong { color: #334155; }
.list-actions {
    display: flex; flex-direction: column; gap: 6px; flex-shrink: 0;
}
.btn-sm-action {
    border: none; padding: 6px 12px; border-radius: 8px;
    font-size: .75rem; font-weight: 700; cursor: pointer; transition: opacity .2s;
    display: flex; align-items: center; gap: 6px; justify-content: center; width: 100px;
}
.btn-sm-action:hover { opacity: .8; }
.btn-approve { background: #0B6623; color: #fff; }
.btn-reject { background: #f1f5f9; color: #dc2626; border: 1px solid #e2e8f0; }
.btn-fine { background: #ef4444; color: #fff; }

.link-detail { font-size: .75rem; color: #3b82f6; text-decoration: none; font-weight: 600; margin-top: 6px; display: inline-block; }
.link-detail:hover { text-decoration: underline; }

/* ===== ACTIVITY LOG ===== */
.act-item {
    display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px dashed #e2e8f0;
}
.act-item:last-child { border: none; padding-bottom: 0; }
.act-icon { font-size: 1.2rem; flex-shrink: 0; margin-top: 2px; }
.act-text { font-size: .8rem; color: #475569; line-height: 1.4; margin: 0; }
.act-text strong { color: #1e293b; }

.empty-msg { text-align: center; color: #94a3b8; font-size: .85rem; padding: 20px 0; }

/* ===== DARK MODE OVERRIDES ===== */
body.dark-mode .dash-title h2 { color: #e2e8f0; }
body.dark-mode .kpi-card { background: #1e2d25; border-color: rgba(255,255,255,.05); box-shadow: 0 4px 12px rgba(0,0,0,.2); }
body.dark-mode .kpi-card::after { opacity: 0.5; }
body.dark-mode .kpi-data h4 { color: #94a3b8; }
body.dark-mode .kpi-data .val { color: #f8fafc; }
body.dark-mode .kpi-card.avail .kpi-icon { background: rgba(22,163,74,.2); color: #4ade80; }
body.dark-mode .kpi-card.borrow .kpi-icon { background: rgba(59,130,246,.2); color: #60a5fa; }
body.dark-mode .kpi-card.maint .kpi-icon { background: rgba(245,158,11,.2); color: #fbbf24; }
body.dark-mode .kpi-card.overdue .kpi-icon { background: rgba(239,68,68,.2); color: #f87171; }

body.dark-mode .panel { background: #1e2d25; border-color: rgba(255,255,255,.05); box-shadow: 0 4px 12px rgba(0,0,0,.2); }
body.dark-mode .panel-head { border-color: rgba(255,255,255,.05); }
body.dark-mode .panel-head h3 { color: #e2e8f0; }
body.dark-mode .panel-head .badge { background: rgba(255,255,255,.1); color: #cbd5e1; }

body.dark-mode .list-card { border-color: rgba(255,255,255,.08); }
body.dark-mode .list-card:hover { background: rgba(255,255,255,.03); }
body.dark-mode .list-icon.yellow { background: rgba(202,138,4,.2); color: #facc15; }
body.dark-mode .list-icon.red { background: rgba(220,38,38,.2); color: #f87171; }
body.dark-mode .list-info h4 { color: #f8fafc; }
body.dark-mode .list-info p { color: #94a3b8; }
body.dark-mode .list-info strong { color: #e2e8f0; }
body.dark-mode .btn-reject { background: transparent; border-color: #ef4444; color: #ef4444; }

body.dark-mode .act-item { border-color: rgba(255,255,255,.08); }
body.dark-mode .act-text { color: #cbd5e1; }
body.dark-mode .act-text strong { color: #f8fafc; }
body.dark-mode .link-detail { color: #60a5fa; }
</style>

<div class="admin-wrap">

    <?php if (isset($kpi_error)) echo "<div class='alert alert-danger'>$kpi_error</div>"; ?>
    <?php if (isset($pending_error)) echo "<div class='alert alert-danger'>$pending_error</div>"; ?>
    <?php if (isset($overdue_error)) echo "<div class='alert alert-danger'>$overdue_error</div>"; ?>

    <div class="dash-header">
        <div class="dash-title">
            <h2><i class="fas fa-tachometer-alt"></i> Dashboard เธ เธฒเธเธฃเธงเธก</h2>
        </div>
        <a href="admin/walkin_borrow.php" class="btn-scan">
            <i class="fas fa-qrcode"></i> เธชเนเธเธเธขเธทเธก/เธเธทเธ
        </a>
    </div>

    <!-- KPI ROW -->
    <div class="kpi-row">
        <div class="kpi-card avail">
            <div class="kpi-data">
                <h4>เธเธฃเนเธญเธกเนเธเนเธเธฒเธ</h4>
                <div class="val"><?php echo $count_available; ?></div>
            </div>
            <div class="kpi-icon"><i class="fas fa-box-open"></i></div>
        </div>
        <div class="kpi-card borrow">
            <div class="kpi-data">
                <h4>เธเธณเธฅเธฑเธเธ–เธนเธเธขเธทเธก</h4>
                <div class="val"><?php echo $count_borrowed; ?></div>
            </div>
            <div class="kpi-icon"><i class="fas fa-hand-holding-medical"></i></div>
        </div>
        <div class="kpi-card maint">
            <div class="kpi-data">
                <h4>เธชเนเธเธเนเธญเธกเธเธณเธฃเธธเธ</h4>
                <div class="val"><?php echo $count_maintenance; ?></div>
            </div>
            <div class="kpi-icon"><i class="fas fa-tools"></i></div>
        </div>
        <?php if ($count_overdue > 0): ?>
        <div class="kpi-card overdue">
            <div class="kpi-data">
                <h4 style="color:#ef4444;">เน€เธเธดเธเธเธณเธซเธเธ”เธเธทเธ (เธขเธฑเธเนเธกเนเธเธทเธ)</h4>
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

            <!-- เธฃเธญเธ”เธณเน€เธเธดเธเธเธฒเธฃ -->
            <div class="panel">
                <div class="panel-head">
                    <i class="fas fa-bell" style="color:#f59e0b; font-size:1.2rem;"></i>
                    <h3>เธฃเธญเธญเธเธธเธกเธฑเธ•เธด</h3>
                    <span class="badge"><?php echo count($pending_requests); ?></span>
                </div>
                
                <?php if (empty($pending_requests)): ?>
                    <p class="empty-msg">เนเธกเนเธกเธตเธเธณเธเธญเธขเธทเธกเธ—เธตเนเธฃเธญเธ”เธณเน€เธเธดเธเธเธฒเธฃ</p>
                <?php else: ?>
                    <?php foreach ($pending_requests as $req): ?>
                        <div class="list-card">
                            <div class="list-icon yellow"><i class="fas fa-hourglass-half"></i></div>
                            <div class="list-info">
                                <h4><?php echo htmlspecialchars($req['equipment_name']); ?></h4>
                                <p>เธเธนเนเธเธญ: <strong><?php echo htmlspecialchars($req['student_name'] ?? '-'); ?></strong></p>
                                <p>เธเธทเธเธงเธฑเธเธ—เธตเน: <strong><?php echo date('d/m/Y', strtotime($req['due_date'])); ?></strong></p>
                                
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
                                       <i class="fas fa-file-alt"></i> เธ”เธนเธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”
                                    </a>
                                    <?php if (!empty($req['attachment_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($req['attachment_url']); ?>" target="_blank" class="link-detail" style="color:#10b981;">
                                            <i class="fas fa-paperclip"></i> เนเธเธฅเนเนเธเธ
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="list-actions">
                                <button class="btn-sm-action btn-approve" onclick="openApproveSelectionModal(<?php echo $req['transaction_id']; ?>, <?php echo $req['item_id'] ?? 0; ?>, '<?php echo htmlspecialchars($req['equipment_name'], ENT_QUOTES); ?>')"><i class="fas fa-check"></i> เธญเธเธธเธกเธฑเธ•เธด</button>
                                <button class="btn-sm-action btn-reject" onclick="openRejectPopup(<?php echo $req['transaction_id']; ?>)"><i class="fas fa-times"></i> เธเธเธดเน€เธชเธ</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- เน€เธเธดเธเธเธณเธซเธเธ”เธเธฅเธญ -->
            <div class="panel">
                <div class="panel-head">
                    <i class="fas fa-exclamation-circle" style="color:#ef4444; font-size:1.2rem;"></i>
                    <h3>เน€เธเธดเธเธเธณเธซเธเธ”เธเธทเธ</h3>
                    <span class="badge"><?php echo count($overdue_items); ?></span>
                </div>
                
                <?php if (empty($overdue_items)): ?>
                    <p class="empty-msg">เธขเธญเธ”เน€เธขเธตเนเธขเธก! เนเธกเนเธกเธตเธฃเธฒเธขเธเธฒเธฃเน€เธเธดเธเธเธณเธซเธเธ”</p>
                <?php else: ?>
                    <?php foreach ($overdue_items as $item): 
                        $days_overdue = max(0, (int)$item['days_overdue']);
                        $fine = $days_overdue * (defined('FINE_RATE_PER_DAY') ? FINE_RATE_PER_DAY : 0);
                    ?>
                        <div class="list-card">
                            <div class="list-icon red"><i class="fas fa-calendar-times"></i></div>
                            <div class="list-info">
                                <h4><?php echo htmlspecialchars($item['equipment_name']); ?></h4>
                                <p>เธเธนเนเธขเธทเธก: <strong><?php echo htmlspecialchars($item['student_name'] ?? '-'); ?></strong></p>
                                <p>เน€เธเธญเธฃเนเนเธ—เธฃ: <?php echo htmlspecialchars($item['phone_number'] ?? '-'); ?></p>
                                <p style="color:#ef4444; font-weight:700; margin-top:2px;">เน€เธฅเธขเธเธณเธซเธเธ”เธกเธฒเนเธฅเนเธง <?php echo $days_overdue; ?> เธงเธฑเธ</p>
                            </div>
                            <div class="list-actions">
                                <button class="btn-sm-action btn-fine" style="width:115px;"
                                    onclick="openFineAndReturnPopup(
                                        <?php echo $item['transaction_id']; ?>, <?php echo $item['student_id'] ?? 0; ?>,
                                        '<?php echo htmlspecialchars(addslashes($item['student_name'] ?? '-')); ?>',
                                        '<?php echo htmlspecialchars(addslashes($item['equipment_name'])); ?>',
                                        <?php echo $days_overdue; ?>, <?php echo $fine; ?>, <?php echo $item['equipment_id']; ?>
                                    )">
                                    <i class="fas fa-coins"></i> เธเธทเธ/เธเธณเธฃเธฐเธเธฃเธฑเธ
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
        
        <!-- RIGHT COLUMN -->
        <div class="col-right">
            
            <!-- เธชเธฑเธ”เธชเนเธงเธ Chart -->
            <div class="panel">
                <div class="panel-head">
                    <i class="fas fa-chart-pie" style="color:#3b82f6; font-size:1.2rem;"></i>
                    <h3>เธชเธฑเธ”เธชเนเธงเธเธญเธธเธเธเธฃเธ“เน</h3>
                </div>
                <div style="width: 100%; max-width: 300px; margin: 0 auto;">
                    <canvas id="equipmentStatusChart"></canvas>
                </div>
            </div>

            <!-- Activity Log -->
            <div class="panel">
                <div class="panel-head">
                    <i class="fas fa-history" style="color:#64748b; font-size:1.2rem;"></i>
                    <h3>เธเธงเธฒเธกเน€เธเธฅเธทเนเธญเธเนเธซเธงเธฅเนเธฒเธชเธธเธ”</h3>
                </div>
                <?php if (empty($recent_activity)): ?>
                    <p class="empty-msg">เธขเธฑเธเนเธกเนเธกเธตเธเธงเธฒเธกเน€เธเธฅเธทเนเธญเธเนเธซเธง</p>
                <?php else: ?>
                    <div class="act-list">
                        <?php foreach ($recent_activity as $act):
                            $icon = '๐”ต'; 
                            $name = htmlspecialchars($act['student_name'] ?? 'N/A');
                            $eq = htmlspecialchars($act['equipment_name']);
                            if ($act['approval_status'] == 'pending') { $icon='๐ก'; $txt="<strong>$name</strong> เธเธญเธขเธทเธก $eq"; }
                            elseif ($act['approval_status'] == 'rejected') { $icon='โช'; $txt="เธเธเธดเน€เธชเธเธเธณเธเธญเธเธญเธ <strong>$name</strong> ($eq)"; }
                            elseif ($act['status'] == 'returned') { $icon='๐ข'; $txt="<strong>$name</strong> เธเธทเธ $eq เนเธฅเนเธง"; }
                            elseif ($act['approval_status'] == 'approved') { $icon='๐”ต'; $txt="เธญเธเธธเธกเธฑเธ•เธดเนเธซเน <strong>$name</strong> เธขเธทเธก $eq"; }
                            elseif ($act['approval_status'] == 'staff_added') { $icon='๐ฃ'; $txt="เธเธฑเธเธ—เธถเธ <strong>$name</strong> เธขเธทเธก $eq (Walk-in)"; }
                            else { $txt = "เธญเธฑเธเน€เธ”เธ•เธชเธ–เธฒเธเธฐ $eq"; }
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
// เธเธฑเธเธเนเธเธฑเธเน€เธเธดเธ” Modal เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ” (เธเธญเธขเธเนเธญเธเธเนเธฒเนเธซเน Swal)
function openDetailModal(el) {
    const item = el.getAttribute('data-item');
    const serial = el.getAttribute('data-serial');
    const req = el.getAttribute('data-requester');
    const bDate = el.getAttribute('data-borrow');
    const dDate = el.getAttribute('data-due');
    const reason = el.getAttribute('data-reason');
    const attachment = el.getAttribute('data-attachment');

    Swal.fire({
        title: 'เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”เธเธฒเธฃเธขเธทเธก',
        html: `
            <div style="text-align: left; padding: 10px; font-size:.9rem;">
                <p><strong>เธเธทเนเธญเธญเธธเธเธเธฃเธ“เน:</strong> ${item}</p>
                <p><strong>Serial Number:</strong> ${serial !== '-' ? serial : 'เธขเธฑเธเนเธกเนเธฃเธฐเธเธธ'}</p>
                <p><strong>เธเธนเนเธเธญ:</strong> ${req}</p>
                <p><strong>เธงเธฑเธเธ—เธตเนเธขเธทเธก:</strong> ${bDate}</p>
                <p><strong>เธเธณเธซเธเธ”เธเธทเธ:</strong> <span style="color:#ef4444">${dDate}</span></p>
                <hr style="margin:10px 0;">
                <p><strong>เน€เธซเธ•เธธเธเธฅ:</strong></p>
                <div style="background:var(--color-page-bg, #f1f5f9); padding:10px; border-radius:8px; white-space:pre-wrap;">${reason}</div>
                ${attachment ? `<div class="mt-3"><strong><i class="fas fa-paperclip"></i> เน€เธญเธเธชเธฒเธฃ:</strong> <a href="${attachment}" target="_blank" class="btn btn-sm" style="background:#0ea5e9; color:#fff;">เธ”เธนเนเธเธฅเนเนเธเธ</a></div>` : ''}
            </div>
        `,
        confirmButtonText: 'เธเธดเธ”', width: '500px',
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
               labels: ['เธเธฃเนเธญเธกเนเธเน', 'เธ–เธนเธเธขเธทเธก', 'เธชเนเธเธเนเธญเธก'],
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
