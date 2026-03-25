<?php
// admin/manage_fines.php
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 1. เธชเนเธงเธ PHP: เธเธฑเธ”เธเธฒเธฃเธเนเธญเธกเธนเธฅ & AJAX Handler
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;
$status_filter = $_GET['status'] ?? 'all'; 

function renderOverdueRows($data) {
    if (empty($data)) return '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #999;">เนเธกเนเธกเธตเธฃเธฒเธขเธเธฒเธฃเน€เธเธดเธเธเธณเธซเธเธ”เธ—เธตเนเธ•เนเธญเธเธเธฑเธ”เธเธฒเธฃ</td></tr>';
    $html = '';
    foreach ($data as $item) {
        $days_overdue = (int)$item['days_overdue'];
        if ($days_overdue < 0) $days_overdue = 0;
        $calculated_fine = $days_overdue * FINE_RATE_PER_DAY; 
        $s_name = htmlspecialchars(addslashes($item['student_name'] ?? '[เธเธนเนเนเธเนเธ–เธนเธเธฅเธ]'));
        $e_name = htmlspecialchars(addslashes($item['equipment_name']));

        $html .= '<tr>
            <td>'.htmlspecialchars($item['student_name'] ?? '[เธเธนเนเนเธเนเธ–เธนเธเธฅเธ]').'</td>
            <td>'.htmlspecialchars($item['equipment_name']).'</td>
            <td style="color: var(--color-danger); font-weight: bold;">'.date('d/m/Y', strtotime($item['due_date'])).'</td>
            <td style="text-align: center; font-weight: bold; font-size: 1.1em;">'.$days_overdue.'</td>
            <td style="text-align: right; font-weight: bold; font-size: 1.1em; color: var(--color-danger);">'.number_format($calculated_fine, 2).'</td>
            <td class="action-buttons">
                <button type="button" class="btn btn-success" 
                    onclick="openDirectPaymentPopup('.$item['transaction_id'].', '.($item['student_id'] ?? 0).', \''.$s_name.'\', \''.$e_name.'\', '.$days_overdue.', '.$calculated_fine.')">
                    <i class="fas fa-hand-holding-usd"></i> เธเธณเธฃเธฐเน€เธเธดเธ
                </button>
            </td>
        </tr>';
    }
    return $html;
}

function renderHistoryRows($data) {
    if (empty($data)) return '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #999;">เนเธกเนเธเธเธเธฃเธฐเธงเธฑเธ•เธดเนเธเธเนเธงเธเน€เธงเธฅเธฒเธเธตเน</td></tr>';
    $html = '';
    foreach ($data as $fine) {
        $has_line = !empty($fine['line_user_id']);
        $line_btn_class = $has_line ? 'btn-line' : 'btn-secondary disabled';
        $line_onclick = $has_line ? 'onclick="sendLineReceipt('.$fine['payment_id'].')"' : 'disabled title="เธเธนเนเนเธเนเธเธตเนเนเธกเนเนเธ”เนเธเธนเธ LINE"';

        $html .= '<tr>
            <td>'.htmlspecialchars($fine['student_name'] ?? '[N/A]').'</td>
            <td>'.htmlspecialchars($fine['equipment_name']).'</td>
            <td><strong>'.number_format($fine['amount'], 2).'</strong></td>
            <td><span class="status-badge returned"><i class="fas fa-check-circle"></i> เธเธณเธฃเธฐเนเธฅเนเธง</span>
                <div style="font-size: 0.9em; margin-top: 5px; color: #555;">('.date('d/m/Y', strtotime($fine['payment_date'])).')</div></td>
            <td>'.htmlspecialchars($fine['staff_name'] ?? '[N/A]').'
                <div style="font-size: 0.9em; margin-top: 5px; color: #555;">('.date('d/m/Y H:i', strtotime($fine['created_at'])).')</div></td>
            <td class="action-buttons">
                <a href="admin/print_receipt.php?payment_id='.$fine['payment_id'].'" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i></a>
                <button type="button" class="btn '.$line_btn_class.' btn-sm" '.$line_onclick.'><i class="fab fa-line"></i></button>
            </td>
        </tr>';
    }
    return $html;
}

$sql_overdue = "SELECT t.id as transaction_id, t.due_date, t.return_date, ei.name as equipment_name, s.id as student_id, s.full_name as student_name, DATEDIFF(COALESCE(t.return_date, CURDATE()), t.due_date) AS days_overdue FROM borrow_records t JOIN borrow_items ei ON t.equipment_id = ei.id LEFT JOIN sys_users s ON t.borrower_student_id = s.id WHERE t.fine_status = 'none' AND t.approval_status IN ('approved', 'staff_added') AND t.due_date < COALESCE(t.return_date, CURDATE()) ORDER BY t.due_date ASC";

$sql_history = "SELECT f.id as fine_id, f.amount, f.status as fine_status, f.notes, f.created_at, t.id as transaction_id, ei.name as equipment_name, s.full_name as student_name, s.line_user_id, p.id as payment_id, p.payment_date, p.amount_paid, u_staff.full_name as staff_name FROM borrow_fines f LEFT JOIN borrow_records t ON f.transaction_id = t.id LEFT JOIN borrow_items ei ON t.equipment_id = ei.id LEFT JOIN sys_users s ON f.student_id = s.id LEFT JOIN sys_staff u_staff ON f.created_by_staff_id = u_staff.id LEFT JOIN borrow_payments p ON f.id = p.fine_id WHERE f.status = 'paid'";

$params_history = [];
if (!empty($start_date)) { $sql_history .= " AND DATE(f.created_at) >= ?"; $params_history[] = $start_date; }
if (!empty($end_date)) { $sql_history .= " AND DATE(f.created_at) <= ?"; $params_history[] = $end_date; }
$sql_history .= " ORDER BY f.created_at DESC";

if (isset($_GET['ajax_update']) && $_GET['ajax_update'] == '1') {
    $overdue_data = [];
    $history_data = [];
    if ($status_filter == 'all' || $status_filter == 'unpaid') {
        $stmt1 = $pdo->prepare($sql_overdue); $stmt1->execute(); $overdue_data = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($status_filter == 'all' || $status_filter == 'paid') {
        $stmt2 = $pdo->prepare($sql_history); $stmt2->execute($params_history); $history_data = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode([
        'overdue_html' => renderOverdueRows($overdue_data),
        'history_html' => renderHistoryRows($history_data),
        'show_overdue' => ($status_filter == 'all' || $status_filter == 'unpaid'),
        'show_history' => ($status_filter == 'all' || $status_filter == 'paid')
    ]);
    exit;
}

try {
    $stmt1 = $pdo->prepare($sql_overdue); $stmt1->execute(); $overdue_unfined = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    $stmt2 = $pdo->prepare($sql_history); $stmt2->execute($params_history); $fines_list = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $error_msg = "Error: " . $e->getMessage(); }

$page_title = "เธเธฑเธ”เธเธฒเธฃเธเนเธฒเธเธฃเธฑเธ";
$current_page = "manage_fines"; 
include('../includes/header.php');
?>
<style>
    .time-filter-group { display: flex; align-items: center; background-color: #1e1e1e; padding: 6px 15px; border-radius: 8px; gap: 15px; color: #e0e0e0; font-size: 0.9rem; box-shadow: 0 2px 5px rgba(0,0,0,0.2); flex-wrap: wrap; }
    .time-filter-btn { background: none; border: none; color: #4dabf7; cursor: pointer; display: flex; align-items: center; gap: 6px; padding: 4px 8px; transition: all 0.2s; border-radius: 4px; font-family: 'RSU', sans-serif; font-weight: bold; font-size: 1rem; }
    .time-filter-btn:hover { background-color: rgba(255,255,255,0.1); color: #fff; }
    .time-filter-select { background-color: transparent !important; color: #fff !important; border: 1px solid rgba(255, 255, 255, 0.2) !important; border-radius: 4px; cursor: pointer; font-family: 'RSU', sans-serif; font-size: 0.95rem; outline: none; padding: 4px 8px; box-shadow: none !important; }
    .time-filter-select option { color: #333; background: #fff; }
    .toolbar-separator { width: 1px; height: 20px; background-color: #555; display: inline-block; }
    .data-section-wrapper { position: relative; min-height: 100px; }
    .loading-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.7); z-index: 10; display: none; justify-content: center; align-items: flex-start; padding-top: 50px; border-radius: var(--border-radius-main); }
    body.dark-mode .loading-overlay { background: rgba(45, 55, 72, 0.8); }
    .spinner { width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid var(--color-primary); border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .btn-line { background-color: #06c755 !important; color: white !important; }
    .btn-line:hover { background-color: #05a546 !important; }
    .btn.disabled { opacity: 0.6; cursor: not-allowed; }
</style>

<div class="header-row" style="flex-wrap: wrap; gap: 15px; align-items: center;">
    <h2><i class="fas fa-file-invoice-dollar"></i> เธเธฑเธ”เธเธฒเธฃเธเนเธฒเธเธฃเธฑเธ</h2>
    <div class="time-filter-group">
        <button type="button" class="time-filter-btn" onclick="refreshFinesData()"><i class="fas fa-sync-alt"></i> Refresh</button>
        <span class="toolbar-separator"></span>
        <div style="display: flex; align-items: center; gap: 8px;">
            <i class="far fa-clock" style="color: #aaa;"></i>
            <select class="time-filter-select" id="timeRangeSelect" onchange="handleTimeRangeChange(this.value)">
                <option value="" disabled selected>เน€เธฅเธทเธญเธเธเนเธงเธเน€เธงเธฅเธฒ (เธเธฃเธฐเธงเธฑเธ•เธด)</option>
                <option value="today">เธงเธฑเธเธเธตเน (Today)</option>
                <option value="48h">2 เธงเธฑเธเธขเนเธญเธเธซเธฅเธฑเธ</option>
                <option value="7d">7 เธงเธฑเธเธขเนเธญเธเธซเธฅเธฑเธ</option>
                <option value="30d">30 เธงเธฑเธเธขเนเธญเธเธซเธฅเธฑเธ</option>
                <option value="custom">เธเธณเธซเธเธ”เน€เธญเธ (Custom range)...</option>
            </select>
        </div>
        <span class="toolbar-separator"></span>
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="color: #aaa; font-size: 0.9rem;">เธชเธ–เธฒเธเธฐ:</span>
            <select class="time-filter-select" id="finesStatusFilter" onchange="refreshFinesData()">
                <option value="all">เธ—เธฑเนเธเธซเธกเธ”</option>
                <option value="unpaid">เธขเธฑเธเนเธกเนเธเธณเธฃเธฐ (Overdue)</option>
                <option value="paid">เธเธณเธฃเธฐเนเธฅเนเธง (History)</option>
            </select>
        </div>
    </div>
</div>

<input type="hidden" id="hidden_start_date" value="">
<input type="hidden" id="hidden_end_date" value="">

<div class="data-section-wrapper">
    <div id="mainLoader" class="loading-overlay"><div class="spinner"></div></div>

    <div id="overdueSection">
        <div class="header-row" style="margin-top: 0; background: none; box-shadow: none; padding-left: 0;">
            <h3 style="font-size: 1.1rem;"><i class="fas fa-exclamation-triangle" style="color: var(--color-danger);"></i> 1. เธฃเธฒเธขเธเธฒเธฃเน€เธเธดเธเธเธณเธซเธเธ” (เธฃเธญเธเธณเธฃเธฐ)</h3>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>เธเธนเนเธขเธทเธก</th><th>เธญเธธเธเธเธฃเธ“เน</th><th>เธเธณเธซเธเธ”เธเธทเธ</th><th>เน€เธเธดเธเธเธณเธซเธเธ” (เธงเธฑเธ)</th><th>เธเนเธฒเธเธฃเธฑเธ (เธเธฒเธ—)</th><th>เธเธฑเธ”เธเธฒเธฃ</th></tr>
                </thead>
                <tbody id="overdueTableBody">
                    <?php echo renderOverdueRows($overdue_unfined); ?>
                </tbody>
            </table>
        </div>
        <hr style="margin: 2rem 0; border: 0; border-top: 1px solid var(--border-color);">
    </div>

    <div id="historySection">
        <div class="header-row" style="margin-top: 0; background: none; box-shadow: none; padding-left: 0;">
            <h3 style="font-size: 1.1rem;"><i class="fas fa-history" style="color: var(--color-primary);"></i> 2. เธเธฃเธฐเธงเธฑเธ•เธดเธเธฒเธฃเธเธณเธฃเธฐเธเนเธฒเธเธฃเธฑเธ</h3>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>เธเธนเนเธขเธทเธก</th><th>เธญเธธเธเธเธฃเธ“เน</th><th>เธเธณเธเธงเธเน€เธเธดเธ (เธเธฒเธ—)</th><th>เธชเธ–เธฒเธเธฐ</th><th>เธเธนเนเธฃเธฑเธเธเธณเธฃเธฐ/เธงเธฑเธเธ—เธตเน</th><th>เธเธฑเธ”เธเธฒเธฃ</th></tr>
                </thead>
                <tbody id="historyTableBody">
                    <?php echo renderHistoryRows($fines_list); ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function formatDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}
function refreshFinesData(customStart = null, customEnd = null) {
    let start = customStart || document.getElementById('hidden_start_date').value;
    let end = customEnd || document.getElementById('hidden_end_date').value;
    let status = document.getElementById('finesStatusFilter').value;
    const loader = document.getElementById('mainLoader');
    loader.style.display = 'flex';
    const params = new URLSearchParams({ ajax_update: '1', start_date: start, end_date: end, status: status });
    fetch(`admin/manage_fines.php?${params.toString()}`).then(response => response.json()).then(data => {
        setTimeout(() => {
            document.getElementById('overdueTableBody').innerHTML = data.overdue_html;
            document.getElementById('historyTableBody').innerHTML = data.history_html;
            const overdueSec = document.getElementById('overdueSection');
            const historySec = document.getElementById('historySection');
            if (data.show_overdue) overdueSec.style.display = 'block'; else overdueSec.style.display = 'none';
            if (data.show_history) historySec.style.display = 'block'; else historySec.style.display = 'none';
            loader.style.display = 'none';
            if(customStart) document.getElementById('hidden_start_date').value = customStart;
            if(customEnd) document.getElementById('hidden_end_date').value = customEnd;
        }, 500); 
    }).catch(err => { console.error(err); loader.style.display = 'none'; Swal.fire('Error', 'เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เนเธซเธฅเธ”เธเนเธญเธกเธนเธฅเนเธ”เน', 'error'); });
}
function handleTimeRangeChange(value) {
    const today = new Date();
    let start = new Date();
    let end = new Date(); 
    if (value === 'custom') { openCustomRangePopup(); document.getElementById('timeRangeSelect').value = ""; return; }
    switch(value) {
        case 'today': break;
        case '48h': start.setDate(today.getDate() - 1); break;
        case '7d': start.setDate(today.getDate() - 7); break;
        case '30d': start.setDate(today.getDate() - 30); break;
        default: return;
    }
    refreshFinesData(formatDate(start), formatDate(end));
}
function openCustomRangePopup() {
    Swal.fire({
        title: '<span style="font-size: 1.1rem;">Select a custom date range</span>',
        background: '#222', color: '#fff',
        html: `
            <div style="text-align: left; padding: 0 10px;">
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 5px; font-size: 0.9rem; color: #ccc;">From:</label>
                    <input type="date" id="swal-start-date" class="swal2-input" style="width: 100%; margin: 0; background: #333; color: #fff; border: 1px solid #555;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 5px; font-size: 0.9rem; color: #ccc;">To:</label>
                    <input type="date" id="swal-end-date" class="swal2-input" style="width: 100%; margin: 0; background: #333; color: #fff; border: 1px solid #555;">
                </div>
            </div>`,
        showCancelButton: true, confirmButtonText: 'OK', confirmButtonColor: '#0078d4', cancelButtonText: 'Cancel', cancelButtonColor: '#333',
        preConfirm: () => {
            const s = document.getElementById('swal-start-date').value;
            const e = document.getElementById('swal-end-date').value;
            if (!s || !e) { Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเน€เธฅเธทเธญเธเธงเธฑเธเธ—เธตเนเนเธซเนเธเธฃเธเธ–เนเธงเธ'); return false; }
            if (s > e) { Swal.showValidationMessage('เธงเธฑเธเธ—เธตเนเน€เธฃเธดเนเธกเธ•เนเธ เธ•เนเธญเธเนเธกเนเธกเธฒเธเธเธงเนเธฒเธงเธฑเธเธ—เธตเนเธชเธดเนเธเธชเธธเธ”'); return false; }
            return { start: s, end: e };
        }
    }).then((result) => { if (result.isConfirmed) refreshFinesData(result.value.start, result.value.end); });
}
function openDirectPaymentPopup(transactionId, studentId, studentName, equipName, daysOverdue, calculatedFine) {
    const setupPaymentMethodToggle = () => {
        try {
            const cashRadio = Swal.getPopup().querySelector('#swal_pm_cash_1');
            const bankRadio = Swal.getPopup().querySelector('#swal_pm_bank_1');
            const slipGroup = Swal.getPopup().querySelector('#slipUploadGroup');
            const slipInput = Swal.getPopup().querySelector('#swal_payment_slip');
            const toggleLogic = (method) => {
                if (method === 'bank_transfer') { slipGroup.style.display = 'block'; slipInput.required = true; } 
                else { slipGroup.style.display = 'none'; slipInput.required = false; }
            };
            cashRadio.addEventListener('change', () => toggleLogic('cash'));
            bankRadio.addEventListener('change', () => toggleLogic('bank_transfer'));
            toggleLogic('cash');
        } catch (e) { console.error(e); }
    };
    Swal.fire({
        title: '๐’ต เธเธฑเธเธ—เธถเธเธเธฒเธฃเธเธณเธฃเธฐเน€เธเธดเธ',
        html: `
        <div class="swal-info-box">
            <p><strong>เธเธนเนเธขเธทเธก:</strong> ${studentName}</p>
            <p><strong>เธญเธธเธเธเธฃเธ“เน:</strong> ${equipName}</p>
            <p class="swal-info-danger"><strong>เน€เธเธดเธเธเธณเธซเธเธ”:</strong> ${daysOverdue} เธงเธฑเธ</p>
        </div>
        <form id="swalDirectPaymentForm" style="text-align: left; margin-top: 20px;" enctype="multipart/form-data">
            <input type="hidden" name="transaction_id" value="${transactionId}">
            <input type="hidden" name="student_id" value="${studentId}">
            <input type="hidden" name="amount" value="${calculatedFine.toFixed(2)}">
            <div style="margin-bottom: 15px;"><label style="font-weight: bold;">เธขเธญเธ”เธเธณเธฃเธฐ:</label><input type="number" name="amount_paid" value="${calculatedFine.toFixed(2)}" step="0.01" required class="swal2-input"></div>
            <div style="margin-bottom: 15px;"><label style="font-weight: bold;">เธงเธดเธเธตเธเธณเธฃเธฐ:</label><div style="display: flex; gap: 1rem;"><label><input type="radio" name="payment_method" id="swal_pm_cash_1" value="cash" checked> เน€เธเธดเธเธชเธ”</label><label><input type="radio" name="payment_method" id="swal_pm_bank_1" value="bank_transfer"> เนเธญเธเน€เธเธดเธ</label></div></div>
            <div id="slipUploadGroup" style="display: none; margin-bottom: 15px;"><label style="font-weight: bold;">เนเธเธเธชเธฅเธดเธ: <span style="color:red;">*</span></label><input type="file" name="payment_slip" id="swal_payment_slip" accept="image/*" class="custom-file-input"></div>
        </form>`,
        didOpen: setupPaymentMethodToggle,
        showCancelButton: true, confirmButtonText: 'เธขเธทเธเธขเธฑเธ',
        preConfirm: () => {
            const form = document.getElementById('swalDirectPaymentForm');
            const formData = new FormData(form);
            if (formData.get('payment_method') === 'bank_transfer' && (!formData.get('payment_slip') || formData.get('payment_slip').size === 0)) {
                Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเนเธเธเธชเธฅเธดเธเธเธฒเธฃเนเธญเธ'); return false;
            }
            return fetch('process/direct_payment_process.php', { method: 'POST', body: formData }).then(r => r.json()).then(d => { if (d.status !== 'success') throw new Error(d.message); return d; }).catch(e => { Swal.showValidationMessage(e.message); });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire('เธชเธณเน€เธฃเนเธ!', 'เธเธฑเธเธ—เธถเธเน€เธฃเธตเธขเธเธฃเนเธญเธข', 'success').then(() => {
                window.open(`admin/print_receipt.php?payment_id=${result.value.new_payment_id}`, '_blank');
                refreshFinesData();
            });
        }
    });
}
function sendLineReceipt(paymentId) {
    Swal.fire({
        title: 'เธขเธทเธเธขเธฑเธเธเธฒเธฃเธชเนเธ?', text: "เธ•เนเธญเธเธเธฒเธฃเธชเนเธเนเธเน€เธชเธฃเนเธเธเธตเนเนเธเธ—เธฒเธ LINE เธเธญเธเธเธนเนเนเธเนเธเธฒเธเนเธเนเธซเธฃเธทเธญเนเธกเน?", icon: 'question',
        showCancelButton: true, confirmButtonText: 'เธชเนเธเน€เธฅเธข', cancelButtonText: 'เธขเธเน€เธฅเธดเธ', confirmButtonColor: '#06c755'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'เธเธณเธฅเธฑเธเธชเนเธ...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const formData = new FormData(); formData.append('payment_id', paymentId);
            fetch('process/send_receipt_manual.php', { method: 'POST', body: formData }).then(r => r.json()).then(d => {
                if (d.status === 'success') Swal.fire('เธชเธณเน€เธฃเนเธ!', 'เธชเนเธเนเธเน€เธชเธฃเนเธเน€เธเนเธฒ LINE เน€เธฃเธตเธขเธเธฃเนเธญเธขเนเธฅเนเธง', 'success');
                else Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”', d.message, 'error');
            }).catch(e => Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”', 'เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เน€เธเธทเนเธญเธกเธ•เนเธญ Server เนเธ”เน', 'error'));
        }
    });
}
</script>
<?php include('../includes/footer.php'); ?>
