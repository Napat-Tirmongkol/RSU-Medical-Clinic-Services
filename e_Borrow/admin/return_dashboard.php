<?php
// return_dashboard.php (อัปเดต V3.2 - แก้ไข SQL และค่าคงที่ป้องกัน 500 Error)

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../config/db_connect.php'); 

// บังคับกำหนดค่าปรับรายวัน (ป้องกัน Undefined Constant)
if (!defined('FINE_RATE_PER_DAY')) {
    define('FINE_RATE_PER_DAY', 10); 
}

$pdo = db();

// 2. ตรวจสอบสิทธิ์ (อนุญาต Admin, Employee และ Editor)
$allowed_roles = ['admin', 'employee', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 3. (SQL) ดึงข้อมูลอุปกรณ์ที่ถูกยืม
$borrowed_items = [];
try {
    // แก้ไข SQL: Join borrow_categories เพื่อเอาชื่ออุปกรณ์
    $sql = "SELECT 
                t.id as transaction_id, 
                t.equipment_id, 
                t.due_date, 
                t.fine_status,
                bc.name as equipment_name, 
                ei.serial_number as equipment_serial,
                s.id as student_id, 
                s.full_name as borrower_name, 
                s.phone_number as borrower_contact,
                t.borrow_date, 
                DATEDIFF(CURDATE(), t.due_date) AS days_overdue
            FROM borrow_records t
            JOIN borrow_categories bc ON t.type_id = bc.id
            JOIN borrow_items ei ON t.equipment_id = ei.id
            LEFT JOIN sys_users s ON t.borrower_student_id = s.id
            WHERE t.status = 'borrowed'
              AND t.approval_status IN ('approved', 'staff_added') 
            ORDER BY t.due_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $borrowed_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // บันทึกลง Log เพื่อตรวจสอบ
    error_log("DB Error in return_dashboard: " . $e->getMessage());
    die("เกิดข้อผิดพลาดในการดึงข้อมูล โปรดตรวจสอบ Log ของเซิร์ฟเวอร์");
}

// 4. ตั้งค่าตัวแปรสำหรับ Header
$page_title = "คืนอุปกรณ์";
$current_page = "return"; 

// 5. เรียกใช้ Header
include('../includes/header.php'); 
?>

<div class="admin-wrap">
    <div class="header-row" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2><i class="fas fa-undo-alt"></i> 📦 รายการอุปกรณ์ที่ต้องรับคืน</h2>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>อุปกรณ์</th>
                    <th>เลขซีเรียล</th>
                    <th>ผู้ยืม</th>
                    <th>วันที่ยืม</th>
                    <th>วันที่กำหนดคืน</th>
                    <th>จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($borrowed_items)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px;">ไม่มีอุปกรณ์ที่กำลังถูกยืมในขณะนี้</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($borrowed_items as $row): ?>
                        <?php
                            $days_overdue = (int)$row['days_overdue'];
                            if ($days_overdue < 0) $days_overdue = 0;
                            $is_overdue = ($days_overdue > 0);
                            $is_fine_paid = ($row['fine_status'] == 'paid');
                            $calculated_fine = $days_overdue * FINE_RATE_PER_DAY;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['equipment_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['equipment_serial'] ?? '-'); ?></td>
                            <td>
                                <?php echo htmlspecialchars($row['borrower_name'] ?? '[N/A]'); ?><br>
                                <small class="text-muted"><?php echo htmlspecialchars($row['borrower_contact'] ?? '-'); ?></small>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($row['borrow_date'])); ?></td>
                            <td style="color: <?php echo $is_overdue ? '#dc3545' : 'inherit'; ?>; font-weight: <?php echo $is_overdue ? 'bold' : 'normal'; ?>;">
                                <?php echo date('d/m/Y', strtotime($row['due_date'])); ?>
                                <?php if($is_overdue): ?> <br><span style="font-size: 10px;">(เกิน <?php echo $days_overdue; ?> วัน)</span> <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                <?php if ($is_overdue && !$is_fine_paid): ?>
                                    <button type="button" class="btn btn-danger"
                                            onclick="openFineAndReturnPopup(
                                                <?php echo $row['transaction_id']; ?>,
                                                <?php echo $row['student_id'] ?? 0; ?>,
                                                '<?php echo htmlspecialchars(addslashes($row['borrower_name'] ?? '[N/A]')); ?>',
                                                '<?php echo htmlspecialchars(addslashes($row['equipment_name'] ?? 'N/A')); ?>',
                                                <?php echo $days_overdue; ?>,
                                                <?php echo $calculated_fine; ?>,
                                                <?php echo $row['equipment_id']; ?> 
                                            )">
                                        <i class="fas fa-dollar-sign"></i> ชำระค่าปรับ
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-return"
                                            onclick="openReturnPopup(<?php echo $row['equipment_id']; ?>)">
                                        <i class="fas fa-undo"></i> รับคืน
                                    </button>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
include('../includes/footer.php'); 
?>
