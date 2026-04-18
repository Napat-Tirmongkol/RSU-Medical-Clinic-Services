<?php
// admin/manage_fines.php (แก้ไข V3.3 - กู้ชีพหน้าจัดการค่าปรับ)
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../config/db_connect.php');

$pdo = db();

if (!defined('FINE_RATE_PER_DAY')) {
    define('FINE_RATE_PER_DAY', 10); 
}

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 1. ฟังก์ชัน Render แถวข้อมูล (แก้ไข SQL Join ใน Query แทนการแก้วนลูป)
function renderOverdueRows($data) {
    if (empty($data)) return '<tr><td colspan="6" style="text-align: center; padding: 20px;" class="text-muted">ไม่มีรายการเกินกำหนดที่ต้องจัดการ</td></tr>';
    $html = '';
    foreach ($data as $item) {
        $days_overdue = (int)$item['days_overdue'];
        if ($days_overdue < 0) $days_overdue = 0;
        $calculated_fine = $days_overdue * FINE_RATE_PER_DAY; 
        $s_name = htmlspecialchars(addslashes($item['student_name'] ?? '[N/A]'));
        $e_name = htmlspecialchars(addslashes($item['equipment_name'] ?? 'N/A'));

        $html .= '<tr>
            <td>'.htmlspecialchars($item['student_name'] ?? '[N/A]').'</td>
            <td>'.htmlspecialchars($item['equipment_name'] ?? 'N/A').'</td>
            <td style="color: #dc3545; font-weight: bold;">'.date('d/m/Y', strtotime($item['due_date'])).'</td>
            <td style="text-align: center; font-weight: bold;">'.$days_overdue.'</td>
            <td style="text-align: right; font-weight: bold; color: #dc3545;">'.number_format($calculated_fine, 2).'</td>
            <td class="action-buttons">
                <button type="button" class="btn btn-return"
                    onclick="openDirectPaymentPopup('.$item['transaction_id'].', '.($item['student_id'] ?? 0).', \''.$s_name.'\', \''.$e_name.'\', '.$days_overdue.', '.$calculated_fine.')">
                    <i class="fas fa-hand-holding-usd"></i> ชำระเงิน
                </button>
            </td>
        </tr>';
    }
    return $html;
}

function renderHistoryRows($data) {
    if (empty($data)) return '<tr><td colspan="6" style="text-align: center; padding: 20px;" class="text-muted">ไม่พบประวัติในช่วงเวลานี้</td></tr>';
    $html = '';
    foreach ($data as $fine) {
        $html .= '<tr>
            <td>'.htmlspecialchars($fine['student_name'] ?? '[N/A]').'</td>
            <td>'.htmlspecialchars($fine['equipment_name'] ?? 'N/A').'</td>
            <td><strong>'.number_format((float)$fine['amount_paid'], 2).'</strong></td>
            <td><span class="badge status-badge borrowed-ok"><i class="fas fa-check-circle"></i> ชำระแล้ว</span></td>
            <td>'.htmlspecialchars($fine['staff_name'] ?? '[N/A]').'<br><small class="text-muted">'.date('d/m/Y H:i', strtotime($fine['payment_date'])).'</small></td>
            <td>
                <a href="admin/print_receipt.php?payment_id='.$fine['payment_id'].'" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i></a>
            </td>
        </tr>';
    }
    return $html;
}

// 2. เตรียม SQL (แก้ไข JOIN ให้ดึงชื่อจาก borrow_categories)
$sql_overdue = "SELECT 
                    t.id as transaction_id, t.due_date, t.return_date, 
                    bc.name as equipment_name, 
                    s.id as student_id, s.full_name as student_name, 
                    DATEDIFF(COALESCE(t.return_date, CURDATE()), t.due_date) AS days_overdue 
                FROM borrow_records t 
                JOIN borrow_categories bc ON t.type_id = bc.id
                JOIN borrow_items ei ON t.equipment_id = ei.id 
                LEFT JOIN sys_users s ON t.borrower_student_id = s.id 
                WHERE t.fine_status = 'none' 
                  AND t.approval_status IN ('approved', 'staff_added') 
                  AND t.due_date < COALESCE(t.return_date, CURDATE()) 
                ORDER BY t.due_date ASC";

$sql_history = "SELECT 
                    p.id as payment_id, p.payment_date, p.amount_paid,
                    bc.name as equipment_name, 
                    s.full_name as student_name, 
                    stf.full_name as staff_name 
                FROM borrow_payments p
                JOIN borrow_records t ON p.transaction_id = t.id
                JOIN borrow_categories bc ON t.type_id = bc.id
                LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                LEFT JOIN sys_staff stf ON p.staff_id = stf.id
                ORDER BY p.payment_date DESC";

// AJAX Handler
if (isset($_GET['ajax_update'])) {
    $stmt1 = $pdo->query($sql_overdue);
    $overdue_data = $stmt1->fetchAll();
    $stmt2 = $pdo->query($sql_history);
    $history_data = $stmt2->fetchAll();
    
    header('Content-Type: application/json');
    echo json_encode([
        'overdue_html' => renderOverdueRows($overdue_data),
        'history_html' => renderHistoryRows($history_data)
    ]);
    exit;
}

try {
    $overdue_unfined = $pdo->query($sql_overdue)->fetchAll();
    $fines_list = $pdo->query($sql_history)->fetchAll();
} catch (PDOException $e) { $error_msg = "Error: " . $e->getMessage(); }

$page_title = "จัดการค่าปรับ";
$current_page = "manage_fines"; 
include('../includes/header.php');
?>

<div class="admin-wrap" style="padding:20px;">
    <div class="header-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2><i class="fas fa-file-invoice-dollar"></i> จัดการค่าปรับ</h2>
        <button onclick="location.reload()" class="btn btn-outline-primary btn-sm"><i class="fas fa-sync"></i> รีเฟรชข้อมูล</button>
    </div>

    <!-- ส่วนที่ 1: รายการค้างจ่าย -->
    <div class="table-container mb-4">
        <div class="header-row" style="margin-bottom: 5px;">
            <h3 class="mb-0" style="color:#ef4444;"><i class="fas fa-exclamation-circle"></i> รายการค้างชำระ (Overdue)</h3>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ผู้ยืม</th><th>อุปกรณ์</th><th>กำหนดคืน</th><th>เกินกำหนด</th><th>ค่าปรับ</th><th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php echo renderOverdueRows($overdue_unfined); ?>
            </tbody>
        </table>
    </div>

    <!-- ส่วนที่ 2: ประวัติการชำระ -->
    <div class="table-container">
        <div class="header-row" style="margin-bottom: 5px;">
            <h3 class="mb-0" style="color:#22c55e;"><i class="fas fa-history"></i> ประวัติการรับชำระเงิน</h3>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ผู้ยืม</th><th>อุปกรณ์</th><th>ยอดเงิน</th><th>สถานะ</th><th>ผู้รับชำระ</th><th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php echo renderHistoryRows($fines_list); ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function openDirectPaymentPopup(transactionId, studentId, studentName, equipName, daysOverdue, calculatedFine) {
    Swal.fire({
        title: 'บันทึกชำระเงิน',
        html: `
            <div style="text-align:left; background:#f8f9fa; padding:15px; border-radius:8px; margin-bottom:15px;">
                <p><strong>ผู้ยืม:</strong> ${studentName}</p>
                <p><strong>อุปกรณ์:</strong> ${equipName}</p>
                <p class="text-danger"><strong>ค้างชำระ:</strong> ${calculatedFine} บาท (${daysOverdue} วัน)</p>
            </div>
            <input type="number" id="pay_amount" class="swal2-input" value="${calculatedFine}" placeholder="ระบุจำนวนเงินที่รับจริง">
        `,
        showCancelButton: true,
        confirmButtonText: 'บันทึกชำระเงิน',
        preConfirm: () => {
            const amount = document.getElementById('pay_amount').value;
            if(!amount) return Swal.showValidationMessage('กรุณาระบุจำนวนเงิน');
            
            const formData = new FormData();
            formData.append('transaction_id', transactionId);
            formData.append('amount_paid', amount);
            formData.append('student_id', studentId);

            return fetch('process/direct_payment_process.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(d => { if(d.status !== 'success') throw new Error(d.message); return d; })
                .catch(e => Swal.showValidationMessage(e.message));
        }
    }).then(r => {
        if(r.isConfirmed) Swal.fire('สำเร็จ', 'บันทึกการชำระเงินเรียบร้อย', 'success').then(() => location.reload());
    });
}
</script>

<?php include('../includes/footer.php'); ?>
