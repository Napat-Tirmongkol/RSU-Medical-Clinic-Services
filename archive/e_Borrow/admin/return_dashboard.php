<?php
// return_dashboard.php (เธญเธฑเธเน€เธ”เธ• V3.1 - เน€เธเธดเนเธก Workflow เธเนเธฒเธเธฃเธฑเธเธเนเธญเธเธเธทเธ)

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php'); 

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน (เธญเธเธธเธเธฒเธ• Admin, Employee เนเธฅเธฐ Editor)
$allowed_roles = ['admin', 'employee', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 3. (SQL) เธ”เธถเธเธเนเธญเธกเธนเธฅเธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธ–เธนเธเธขเธทเธก
$borrowed_items = [];
try {
    
    // โ… (เนเธเนเนเธ) Query เนเธซเธกเน: เธ”เธถเธ t.id (transaction_id), s.id (student_id), 
    //    t.fine_status, เนเธฅเธฐเธเธณเธเธงเธ“ DATEDIFF
    $sql = "SELECT 
                t.id as transaction_id, 
                t.equipment_id, 
                t.due_date, 
                t.fine_status,
                ei.name as equipment_name, 
                ei.serial_number as equipment_serial,
                s.id as student_id, 
                s.full_name as borrower_name, 
                s.phone_number as borrower_contact,
                t.borrow_date, 
                DATEDIFF(CURDATE(), t.due_date) AS days_overdue
            FROM borrow_records t
            JOIN borrow_items ei ON t.equipment_id = ei.id
            LEFT JOIN sys_users s ON t.borrower_student_id = s.id
            WHERE t.status = 'borrowed'
              AND t.approval_status IN ('approved', 'staff_added') 
            ORDER BY t.due_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $borrowed_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅ: " . $e->getMessage();
}

// 4. เธ•เธฑเนเธเธเนเธฒเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธ Header
$page_title = "เธเธทเธเธญเธธเธเธเธฃเธ“เน";
$current_page = "return"; 

// 5. เน€เธฃเธตเธขเธเนเธเน Header
include('../includes/header.php'); 
?>

<div class="header-row">
    <h2><i class="fas fa-undo-alt"></i> ๐“ฆ เธฃเธฒเธขเธเธฒเธฃเธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธ•เนเธญเธเธฃเธฑเธเธเธทเธ</h2>
</div>

<div class="table-container">
    <table>
        <thead>
            <tr>
                <th>เธญเธธเธเธเธฃเธ“เน</th>
                <th>เน€เธฅเธเธเธตเน€เธฃเธตเธขเธฅ</th>
                <th>เธเธนเนเธขเธทเธก (User)</th>
                <th>เธเนเธญเธกเธนเธฅเธ•เธดเธ”เธ•เนเธญ (เธเธนเนเธขเธทเธก)</th>
                <th>เธงเธฑเธเธ—เธตเนเธขเธทเธก</th>
                <th>เธงเธฑเธเธ—เธตเนเธเธณเธซเธเธ”เธเธทเธ</th>
                <th>เธเธฑเธ”เธเธฒเธฃ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($borrowed_items)): ?>
                <tr>
                    <td colspan="7" style="text-align: center;">เนเธกเนเธกเธตเธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธเธณเธฅเธฑเธเธ–เธนเธเธขเธทเธกเนเธเธเธ“เธฐเธเธตเน</td>
                </tr>
            <?php else: ?>
                <?php foreach ($borrowed_items as $row): ?>
                    
                    <?php
                        // โ… (เนเธซเธกเน) เธ•เธฃเธฃเธเธฐเธชเธณเธซเธฃเธฑเธเธ•เธฃเธงเธเธชเธญเธเธเนเธฒเธเธฃเธฑเธ
                        $days_overdue = (int)$row['days_overdue'];
                        if ($days_overdue < 0) $days_overdue = 0;
                        
                        $is_overdue = ($days_overdue > 0);
                        $is_fine_paid = ($row['fine_status'] == 'paid');
                        $calculated_fine = $days_overdue * FINE_RATE_PER_DAY;
                    ?>

                    <tr>
                        <td><?php echo htmlspecialchars($row['equipment_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['equipment_serial'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($row['borrower_name'] ?? '[เธเธนเนเนเธเนเธ–เธนเธเธฅเธ]'); ?></td>
                        <td><?php echo htmlspecialchars($row['borrower_contact'] ?? '-'); ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($row['borrow_date'])); ?></td>
                        
                        <?php // (เน€เธเธฅเธตเนเธขเธเธชเธตเธงเธฑเธเธ—เธตเน เธ–เนเธฒเน€เธเธดเธเธเธณเธซเธเธ”) ?>
                        <td style="color: <?php echo $is_overdue ? 'var(--color-danger)' : 'inherit'; ?>; font-weight: <?php echo $is_overdue ? 'bold' : 'normal'; ?>;">
                            <?php echo date('d/m/Y', strtotime($row['due_date'])); ?>
                        </td>

                        <td class="action-buttons">
                            
                            <?php // โ… (เนเธซเธกเน) เธ•เธฃเธฃเธเธฐเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเธเธธเนเธก ?>
                            <?php if ($is_overdue && !$is_fine_paid): ?>
                                <button type="button" class="btn btn-danger" 
                                        onclick="openFineAndReturnPopup(
                                            <?php echo $row['transaction_id']; ?>,
                                            <?php echo $row['student_id'] ?? 0; ?>,
                                            '<?php echo htmlspecialchars(addslashes($row['borrower_name'] ?? '[N/A]')); ?>',
                                            '<?php echo htmlspecialchars(addslashes($row['equipment_name'])); ?>',
                                            <?php echo $days_overdue; ?>,
                                            <?php echo $calculated_fine; ?>,
                                            <?php echo $row['equipment_id']; ?> 
                                        )">
                                    <i class="fas fa-dollar-sign"></i> เธเธณเธฃเธฐเธเนเธฒเธเธฃเธฑเธ
                                </button>
                            <?php else: ?>
                                <button type="button" 
                                        class="btn btn-return" 
                                        onclick="openReturnPopup(<?php echo $row['equipment_id']; ?>)">เธฃเธฑเธเธเธทเธ</button>
                            <?php endif; ?>

                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
// 7. เน€เธฃเธตเธขเธเนเธเนเนเธเธฅเน Footer (เธเธถเนเธเธกเธต JavaScript popups เธ—เธตเนเน€เธฃเธฒเธขเนเธฒเธขเนเธ)
include('../includes/footer.php'); 
?>
