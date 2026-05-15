<?php
// admin/manage_fines.php (แก้ไข V3.3 - กู้ชีพหน้าจัดการค่าปรับ)
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../config.php');
$pdo = db();

$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// Access check สำหรับปุ่ม "ส่งเข้าระบบการเงิน"
$_eb_role = $_SESSION['admin_role'] ?? $_SESSION['role'] ?? '';
$GLOBALS['_eb_hasFinance'] = in_array($_eb_role, ['admin', 'superadmin'], true) || !empty($_SESSION['access_finance']);

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
    $hasFinance = !empty($GLOBALS['_eb_hasFinance']);
    $html = '';
    foreach ($data as $fine) {
        $financeBtn = '';
        if ($hasFinance) {
            $payload = json_encode([
                'pid'    => (int)$fine['payment_id'],
                'amount' => (float)$fine['amount_paid'],
                'date'   => date('Y-m-d', strtotime($fine['payment_date'])),
                'desc'   => 'ค่าปรับยืมเกินกำหนด: ' . ($fine['student_name'] ?? '-') . ' (' . ($fine['equipment_name'] ?? '-') . ')',
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
            // Auto-sync ทำงานตอนรับชำระแล้ว — ปุ่มนี้สำหรับ re-sync เผื่อกรณีพิเศษ
            $financeBtn = ' <button type="button" class="btn btn-success btn-sm" style="background:#059669;border:0;color:#fff;padding:4px 8px;border-radius:6px;font-size:11px;cursor:pointer" onclick=\'eborrowFineSendToFinance(' . $payload . ')\' title="ซิงค์ Cash Book ใหม่ (auto-sync ตอนรับชำระแล้ว)"><i class="fa-solid fa-rotate"></i></button>';
        }
        $html .= '<tr>
            <td>'.htmlspecialchars($fine['student_name'] ?? '[N/A]').'</td>
            <td>'.htmlspecialchars($fine['equipment_name'] ?? 'N/A').'</td>
            <td><strong>'.number_format((float)$fine['amount_paid'], 2).'</strong></td>
            <td><span class="badge status-badge borrowed-ok"><i class="fas fa-check-circle"></i> ชำระแล้ว</span></td>
            <td>'.htmlspecialchars($fine['staff_name'] ?? '[N/A]').'<br><small class="text-muted">'.date('d/m/Y H:i', strtotime($fine['payment_date'])).'</small></td>
            <td>
                <a href="admin/print_receipt.php?payment_id='.$fine['payment_id'].'" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i></a>'.$financeBtn.'
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
                    COALESCE(stf.full_name, adm.full_name, '[N/A]') as staff_name 
                FROM borrow_payments p
                LEFT JOIN borrow_fines f ON p.fine_id = f.id
                LEFT JOIN borrow_records t ON f.transaction_id = t.id
                LEFT JOIN borrow_categories bc ON t.type_id = bc.id
                LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                LEFT JOIN sys_staff stf ON p.received_by_staff_id = stf.id
                LEFT JOIN sys_admins adm ON p.received_by_staff_id = adm.id
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
// ส่งค่าปรับเข้าระบบการเงิน (Cash Book) เป็นรายได้
async function eborrowFineSendToFinance(data) {
    const r = await Swal.fire({
        title: 'ส่งค่าปรับเข้าระบบการเงิน',
        html: `<div class="text-left" style="text-align:left">
            <div style="margin-bottom:8px"><b>${data.desc}</b></div>
            <div>ยอดเงิน: <b style="color:#059669">${data.amount.toLocaleString('th-TH', { minimumFractionDigits: 2 })} บาท</b></div>
            <div>วันที่ชำระ: <b>${data.date}</b></div>
            <hr style="margin:8px 0">
            <div style="font-size:12px;color:#64748b">บันทึกเป็น "รายได้" หมวด "รายรับอื่นๆ" — กดซ้ำจะอัปเดต ไม่สร้างซ้ำ</div>
        </div>`,
        showCancelButton: true,
        confirmButtonText: 'ส่ง', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#059669',
    });
    if (!r.isConfirmed) return;
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('meta[name=csrf-token]')?.content || '');
    fd.append('action', 'txn:upsert_from_source');
    fd.append('source_module', 'eborrow_payment');
    fd.append('source_id', String(data.pid));
    fd.append('kind', 'income');
    fd.append('amount', String(data.amount));
    fd.append('txn_date', data.date);
    fd.append('description', data.desc);
    fd.append('category_name', 'รายรับอื่นๆ');
    fd.append('reference', `e-Borrow Payment #${data.pid}`);
    try {
        // Path resolves against <base href="/.../e_Borrow/"> so we need the admin/ prefix
        const res = await fetch('admin/ajax_finance_sync.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json();
        if (!j.ok) { Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: j.message || '' }); return; }

        // If the payment date is outside the current month, the record won't appear
        // in Cash Book's default view — surface that so user doesn't think it failed.
        const txnDate = j.txn_date || data.date;
        const txnYM = txnDate.slice(0, 7);
        const nowYM = new Date().toISOString().slice(0, 7);
        const monthHint = (txnYM === nowYM)
            ? ''
            : `<div style="font-size:11px;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 10px;margin-top:8px">
                   <i class="fa-solid fa-circle-info"></i> วันชำระอยู่นอกเดือนปัจจุบัน — ใน Cash Book ต้องเลือกช่วงวันที่ครอบคลุม <b>${txnDate}</b>
               </div>`;

        Swal.fire({
            icon: 'success',
            title: j.mode === 'updated' ? 'อัปเดตในระบบการเงินแล้ว' : 'บันทึกในระบบการเงินแล้ว',
            html: `<div style="font-size:13px;text-align:left;display:inline-block">
                       <div>Transaction ID: <b>#${j.id}</b></div>
                       <div>วันที่: <b>${txnDate}</b></div>
                       <div>ยอด: <b>${Number(j.amount).toLocaleString('th-TH', {minimumFractionDigits: 2})} บาท</b></div>
                       ${monthHint}
                       <div style="margin-top:10px"><a href="../portal/index.php?section=finance" target="_blank" style="color:#059669;text-decoration:underline">เปิดดู Cash Book →</a></div>
                   </div>`,
            confirmButtonColor: '#059669'
        });
    } catch (e) { Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: String(e) }); }
}

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
