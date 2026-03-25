<?php
// admin/report_borrowed.php
include('../includes/check_session.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

// 1. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

// 2. เธชเนเธงเธ PHP: เธเธฑเธ”เธเธฒเธฃเธเนเธญเธกเธนเธฅ (เธฃเธญเธเธฃเธฑเธเธ—เธฑเนเธเนเธซเธฅเธ”เธเธเธ•เธด เนเธฅเธฐ AJAX Request)
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;
$status_filter = $_GET['status'] ?? '';

$sql = "SELECT 
            t.id as transaction_id, t.borrow_date, t.due_date, t.return_date, t.status,
            et.name as type_name, et.image_url, 
            ei.name as item_name, ei.serial_number,
            s.full_name as student_name, s.student_personnel_id, s.phone_number as student_phone,
            s.status as student_status, s.status_other,
            f.amount as fine_amount
        FROM borrow_records t
        LEFT JOIN borrow_categories et ON t.type_id = et.id
        LEFT JOIN borrow_items ei ON t.item_id = ei.id
        LEFT JOIN sys_users s ON t.borrower_student_id = s.id
        LEFT JOIN borrow_fines f ON t.id = f.transaction_id
        WHERE 1=1 ";

$params = [];

if (!empty($start_date)) {
    $sql .= " AND DATE(t.borrow_date) >= ?";
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $sql .= " AND DATE(t.borrow_date) <= ?";
    $params[] = $end_date;
}
if (!empty($status_filter)) {
    $sql .= " AND t.status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY t.borrow_date DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $transactions = [];
    $error_message = $e->getMessage();
}

// --- เธ•เธฃเธงเธเธชเธญเธเธงเนเธฒเน€เธเนเธ AJAX Request เธซเธฃเธทเธญเนเธกเน ---
if (isset($_GET['ajax_table']) && $_GET['ajax_table'] == '1') {
    if (empty($transactions)) {
        echo '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #888;">เนเธกเนเธเธเธเนเธญเธกเธนเธฅเนเธเธเนเธงเธเน€เธงเธฅเธฒเธเธตเน</td></tr>';
    } else {
        foreach ($transactions as $item) {
            $image_path = $item['image_url'] ?? null;
            $img_html = $image_path 
                ? '<img src="'.htmlspecialchars($image_path).'" alt="เธฃเธนเธ" class="item-thumbnail" onerror="this.style.display=\'none\'">' 
                : '<div class="equipment-card-image-placeholder item-thumbnail"><i class="fas fa-camera"></i></div>';

            $status_badge = '';
            if ($item['status'] == 'returned') {
                $status_badge = '<span class="status-badge returned"><i class="fas fa-check-circle"></i> เธเธทเธเนเธฅเนเธง</span>';
            } else {
                $status_badge = '<span class="status-badge borrowed"><i class="fas fa-hourglass-half"></i> เธขเธทเธกเธญเธขเธนเน</span>';
            }
            
            $return_date_txt = $item['return_date'] ? date('d/m/Y', strtotime($item['return_date'])) : '<span class="text-muted">เธขเธฑเธเนเธกเนเธเธทเธ</span>';

            echo '<tr>
                <td>
                    <div class="item-cell">
                        '.$img_html.'
                        <div class="item-details">
                            <strong>'.htmlspecialchars($item['item_name']).'</strong>
                            <small>('.htmlspecialchars($item['type_name']).')</small>
                            <small>S/N: '.htmlspecialchars($item['serial_number'] ?? 'N/A').'</small>
                        </div>
                    </div>
                </td>
                <td>
                    <strong>'.htmlspecialchars($item['student_name']).'</strong><br>
                    <small>'.htmlspecialchars($item['student_personnel_id'] ?? 'N/A').'</small>
                </td>
                <td>'.date('d/m/Y', strtotime($item['borrow_date'])).'</td>
                <td>'.date('d/m/Y', strtotime($item['due_date'])).'</td>
                <td>'.$return_date_txt.'</td>
                <td>'.$status_badge.'</td>
            </tr>';
        }
    }
    exit;
}

$page_title = "เธฃเธฒเธขเธเธฒเธเธเธฒเธฃเธขเธทเธก-เธเธทเธ";
$current_page = "report";
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
</style>

<div class="main-container"> 
    <div class="header-row" style="flex-wrap: wrap; gap: 15px; align-items: center;">
        <h2><i class="fas fa-chart-line"></i> เธฃเธฒเธขเธเธฒเธเธเธฒเธฃเธขเธทเธก-เธเธทเธ</h2>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <div class="time-filter-group">
                <button type="button" class="time-filter-btn" onclick="refreshTable()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <span class="toolbar-separator"></span>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="far fa-clock" style="color: #aaa;"></i>
                    <select class="time-filter-select" id="timeRangeSelect" onchange="handleTimeRangeChange(this.value)">
                        <option value="" disabled selected>เน€เธฅเธทเธญเธเธเนเธงเธเน€เธงเธฅเธฒ</option>
                        <option value="today">เธงเธฑเธเธเธตเน (Today)</option>
                        <option value="48h">2 เธงเธฑเธเธขเนเธญเธเธซเธฅเธฑเธ (Last 48h)</option>
                        <option value="7d">7 เธงเธฑเธเธขเนเธญเธเธซเธฅเธฑเธ</option>
                        <option value="30d">30 เธงเธฑเธเธขเนเธญเธเธซเธฅเธฑเธ</option>
                        <option value="custom">เธเธณเธซเธเธ”เน€เธญเธ (Custom range)...</option>
                    </select>
                </div>
                <span class="toolbar-separator"></span>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="color: #aaa; font-size: 0.9rem;">เธชเธ–เธฒเธเธฐ:</span>
                    <select class="time-filter-select" id="statusFilter" onchange="refreshTable()" style="min-width: 100px;">
                        <option value="">เธ—เธฑเนเธเธซเธกเธ”</option>
                        <option value="borrowed" <?php echo ($status_filter == 'borrowed') ? 'selected' : ''; ?>>เธเธณเธฅเธฑเธเธขเธทเธก</option>
                        <option value="returned" <?php echo ($status_filter == 'returned') ? 'selected' : ''; ?>>เธเธทเธเนเธฅเนเธง</option>
                    </select>
                    <i class="fas fa-chevron-down" style="font-size: 0.7rem; color: #aaa; margin-left: -20px; pointer-events: none;"></i>
                </div>
            </div>
            <a href="admin/report_borrowed.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Export
            </a>
        </div>
    </div>

    <input type="hidden" id="hidden_start_date" value="<?php echo htmlspecialchars($start_date ?? ''); ?>">
    <input type="hidden" id="hidden_end_date" value="<?php echo htmlspecialchars($end_date ?? ''); ?>">
    
    <div class="section-card desktop-only" style="padding: 0;"> 
        <div class="table-wrapper">
            <div id="tableLoader" class="loading-overlay">
                <div class="spinner"></div>
                <div style="font-weight: bold; color: var(--color-primary);">เธเธณเธฅเธฑเธเนเธซเธฅเธ”เธเนเธญเธกเธนเธฅ...</div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>เธญเธธเธเธเธฃเธ“เน</th>
                            <th>เธเธนเนเธขเธทเธก</th>
                            <th>เธงเธฑเธเธ—เธตเนเธขเธทเธก</th>
                            <th>เธเธณเธซเธเธ”เธเธทเธ</th>
                            <th>เธงเธฑเธเธ—เธตเนเธเธทเธเธเธฃเธดเธ</th>
                            <th>เธชเธ–เธฒเธเธฐ</th>
                        </tr>
                    </thead>
                    <tbody id="reportTableBody">
                        <?php 
                        if (empty($transactions)) {
                            echo '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #999;">เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธฒเธฃเธ—เธณเธฃเธฒเธขเธเธฒเธฃ</td></tr>';
                        } else {
                            foreach ($transactions as $item) {
                                // (เน€เธซเธกเธทเธญเธเธเนเธฒเธเธเธ) ...
                                $image_path = $item['image_url'] ?? null;
                                if ($image_path) {
                                    $img_html = '<img src="'.htmlspecialchars($image_path).'" alt="เธฃเธนเธ" class="item-thumbnail" onerror="this.style.display=\'none\'">';
                                } else {
                                    $img_html = '<div class="equipment-card-image-placeholder item-thumbnail"><i class="fas fa-camera"></i></div>';
                                }
                                $status_badge = ($item['status'] == 'returned') ? '<span class="status-badge returned"><i class="fas fa-check-circle"></i> เธเธทเธเนเธฅเนเธง</span>' : '<span class="status-badge borrowed"><i class="fas fa-hourglass-half"></i> เธขเธทเธกเธญเธขเธนเน</span>';
                                $return_date_txt = $item['return_date'] ? date('d/m/Y', strtotime($item['return_date'])) : '<span class="text-muted">เธขเธฑเธเนเธกเนเธเธทเธ</span>';

                                echo '<tr>
                                    <td>
                                        <div class="item-cell">
                                            '.$img_html.'
                                            <div class="item-details">
                                                <strong>'.htmlspecialchars($item['item_name']).'</strong>
                                                <small>('.htmlspecialchars($item['type_name']).')</small>
                                                <small>S/N: '.htmlspecialchars($item['serial_number'] ?? 'N/A').'</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong>'.htmlspecialchars($item['student_name']).'</strong><br>
                                        <small>'.htmlspecialchars($item['student_personnel_id'] ?? 'N/A').'</small>
                                    </td>
                                    <td>'.date('d/m/Y', strtotime($item['borrow_date'])).'</td>
                                    <td>'.date('d/m/Y', strtotime($item['due_date'])).'</td>
                                    <td>'.$return_date_txt.'</td>
                                    <td>'.$status_badge.'</td>
                                </tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="student-card-list mobile-only">
         <p style="text-align: center; padding: 20px; color: #999;">(เธเธฃเธธเธ“เธฒเธ”เธนเนเธเธกเธธเธกเธกเธญเธ Desktop เน€เธเธทเนเธญเนเธเนเธเธตเน€เธเธญเธฃเน Refresh เธ•เธฒเธฃเธฒเธเนเธเธ Real-time)</p>
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

function refreshTable(customStartDate = null, customEndDate = null) {
    let startDate = customStartDate || document.getElementById('hidden_start_date').value;
    let endDate = customEndDate || document.getElementById('hidden_end_date').value;
    let status = document.getElementById('statusFilter').value;

    const loader = document.getElementById('tableLoader');
    loader.style.display = 'flex';

    const params = new URLSearchParams({ ajax_table: '1', start_date: startDate, end_date: endDate, status: status });

    fetch(`admin/report_borrowed.php?${params.toString()}`)
        .then(response => response.text())
        .then(html => {
            setTimeout(() => {
                document.getElementById('reportTableBody').innerHTML = html;
                loader.style.display = 'none';
                if(customStartDate) document.getElementById('hidden_start_date').value = customStartDate;
                if(customEndDate) document.getElementById('hidden_end_date').value = customEndDate;
            }, 300);
        })
        .catch(err => {
            console.error(err);
            loader.style.display = 'none';
            Swal.fire('Error', 'เนเธกเนเธชเธฒเธกเธฒเธฃเธ–เนเธซเธฅเธ”เธเนเธญเธกเธนเธฅเนเธ”เน', 'error');
        });
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
    refreshTable(formatDate(start), formatDate(end));
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
    }).then((result) => {
        if (result.isConfirmed) { refreshTable(result.value.start, result.value.end); }
    });
}
</script>
<?php include('../includes/footer.php'); ?>
