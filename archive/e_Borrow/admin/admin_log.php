<?php
// admin/admin_log.php (แก้ไข V3.5 - แก้ไขปัญหา LIMIT OFFSET ใน SQL)
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

$pdo = db();

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit;
}

$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$log_type = $_GET['log_type'] ?? 'signin';
$limit = 20; 
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

function renderTableHeaders($type) {
    if ($type == 'signin') {
        return '<tr><th>เวลา</th><th>ผู้เข้าใช้งาน</th><th>ประเภท</th><th>IP / รายละเอียด</th></tr>';
    } else {
        return '<tr><th>เวลา</th><th>ผู้ดำเนินการ</th><th>Action</th><th>รายละเอียด</th></tr>';
    }
}

function renderTableRows($data, $type) {
    if (empty($data)) return '<tr><td colspan="4" style="text-align: center; padding: 20px; color: #999;">ไม่พบข้อมูลในช่วงเวลานี้</td></tr>';
    $html = '';
    foreach ($data as $log) {
        $badge_color = (strpos($log['action'], 'line') !== false) ? '#06c755' : '#6c757d';
        $badge = '<span class="badge" style="background:'.$badge_color.'; color:white; padding:4px 8px; border-radius:4px;">'.htmlspecialchars($log['action']).'</span>';
        
        $html .= '<tr>
            <td>'.date('d/m/Y H:i:s', strtotime($log['created_at'])).'</td>
            <td>'.htmlspecialchars($log['admin_name'] ?? 'System').'</td>
            <td>'.$badge.'</td>
            <td style="font-size: 0.9em; color: #555;">'.htmlspecialchars($log['description'] ?? '-').'</td>
        </tr>';
    }
    return $html;
}

function renderPagination($current_page, $total_pages) {
    if ($total_pages <= 1) return '';
    $html = '<div class="d-flex justify-content-between align-items-center mt-3">';
    $html .= '<span>หน้า '.$current_page.' / '.$total_pages.'</span>';
    $html .= '<div class="btn-group">';
    if($current_page > 1) $html .= '<button class="btn btn-outline-primary btn-sm" onclick="changePage('.($current_page-1).')">ก่อนหน้า</button>';
    if($current_page < $total_pages) $html .= '<button class="btn btn-outline-primary btn-sm" onclick="changePage('.($current_page+1).')">ถัดไป</button>';
    $html .= '</div></div>';
    return $html;
}

$date_condition = ""; 
$date_params = [];
if (!empty($start_date)) { $date_condition .= " AND DATE(l.created_at) >= ?"; $date_params[] = $start_date; }
if (!empty($end_date)) { $date_condition .= " AND DATE(l.created_at) <= ?"; $date_params[] = $end_date; }

$base_where = ($log_type == 'signin') ? "WHERE l.action LIKE 'login%'" : "WHERE l.action NOT LIKE 'login%'";

$sql_count = "SELECT COUNT(*) FROM sys_activity_logs l $base_where $date_condition";

// ปัดเป่าปัญหา LIMIT: ใส่เป็นตัวเลขตรงๆ หลังจากผ่าน (int) casting เพื่อความชัวร์
$sql_data = "SELECT l.*, COALESCE(u.full_name, 'System') as admin_name 
             FROM sys_activity_logs l 
             LEFT JOIN sys_staff u ON l.user_id = u.id 
             $base_where $date_condition 
             ORDER BY l.created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

if (isset($_GET['ajax_update'])) {
    try {
        $stmt_count = $pdo->prepare($sql_count); 
        $stmt_count->execute($date_params); 
        $total_logs = $stmt_count->fetchColumn(); 
        $total_pages = ceil($total_logs / $limit);

        $stmt_data = $pdo->prepare($sql_data);
        $stmt_data->execute($date_params); // ส่งเฉพาะ Date Params
        $logs_data = $stmt_data->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode([
            'headers_html' => renderTableHeaders($log_type),
            'body_html' => renderTableRows($logs_data, $log_type),
            'pagination_html' => renderPagination($page, $total_pages)
        ]);
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

$page_title = "บันทึก Log (Admin)";
$current_page = "admin_log"; 
include('../includes/header.php');
?>
<div class="admin-wrap" style="padding:20px;">
    <h2><i class="fas fa-history"></i> บันทึก Log การใช้งาน</h2>
    <div style="margin: 20px 0; display:flex; gap:10px;">
        <select class="form-select" id="logTypeSelect" style="max-width:250px;" onchange="refreshLogData(1)">
            <option value="signin">ประวัติการเข้าสู่ระบบ</option>
            <option value="actions">ประวัติการทำงานอื่นๆ</option>
        </select>
        <button onclick="refreshLogData(1)" class="btn btn-outline-primary"><i class="fas fa-sync"></i> Refresh</button>
    </div>

    <div class="card" style="border-radius:12px; border:none; box-shadow:0 4px 15px rgba(0,0,0,0.05); overflow:hidden;">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light" id="logTableHead"></thead>
            <tbody id="logTableBody"></tbody>
        </table>
    </div>
    <div id="paginationContainer"></div>
</div>

<script>
function refreshLogData(page = 1) {
    const type = document.getElementById('logTypeSelect').value;
    fetch(`admin/admin_log.php?ajax_update=1&page=${page}&log_type=${type}`)
        .then(r => r.json())
        .then(d => {
            document.getElementById('logTableHead').innerHTML = d.headers_html;
            document.getElementById('logTableBody').innerHTML = d.body_html;
            document.getElementById('paginationContainer').innerHTML = d.pagination_html;
        });
}
function changePage(p) { refreshLogData(p); }
document.addEventListener('DOMContentLoaded', () => refreshLogData(1));
</script>
<?php include('../includes/footer.php'); ?>
