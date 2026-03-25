<?php
// [เนเธเนเนเธเนเธเธฅเน: napat-tirmongkol/e-borrow/E-Borrow-c4df732f98db10bf52a8e9d7299e212b6f2abd37/admin/manage_items.php]

// 1. "เธเนเธฒเธเธขเธฒเธก" เนเธฅเธฐ "เน€เธเธทเนเธญเธกเธ•เนเธญ DB"
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

// 2. เธ•เธฃเธงเธเธชเธญเธเธชเธดเธ—เธเธดเน Admin
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 3. เธฃเธฑเธ Type ID
$type_id = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
if ($type_id == 0) {
    header("Location: manage_equipment.php"); // (เธ–เนเธฒเนเธกเนเธกเธต ID เนเธซเนเน€เธ”เนเธเธเธฅเธฑเธ)
    exit;
}

// 4. (Query เธ—เธตเน 1) เธ”เธถเธเธเนเธญเธกเธนเธฅ "เธเธฃเธฐเน€เธ เธ—"
try {
    $stmt_type = $pdo->prepare("SELECT * FROM borrow_categories WHERE id = ?");
    $stmt_type->execute([$type_id]);
    $type_info = $stmt_type->fetch(PDO::FETCH_ASSOC);

    if (!$type_info) {
        header("Location: manage_equipment.php"); // (เธ–เนเธฒ ID เธเธดเธ” เนเธซเนเน€เธ”เนเธเธเธฅเธฑเธ)
        exit;
    }
} catch (PDOException $e) {
    die("เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฃเธฐเน€เธ เธ—: " . $e->getMessage());
}

// 5. (Query เธ—เธตเน 2) เธ”เธถเธเธเนเธญเธกเธนเธฅ "เธเธดเนเธ" เธญเธธเธเธเธฃเธ“เน (items)
try {
    $sql_items = "SELECT 
                    i.*, 
                    s.full_name as student_name, 
                    t.borrow_date, t.due_date
                  FROM borrow_items i
                  LEFT JOIN borrow_records t ON i.id = t.item_id AND t.status = 'borrowed'
                  LEFT JOIN sys_users s ON t.borrower_student_id = s.id
                  WHERE i.type_id = ?
                  ORDER BY i.status ASC, i.id ASC";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute([$type_id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $items_error = "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅเธญเธธเธเธเธฃเธ“เน: " . $e->getMessage();
    $items = [];
}

// 6. เธ•เธฑเนเธเธเนเธฒเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธ Header
$page_title = "เธเธฑเธ”เธเธฒเธฃเธฃเธฒเธขเธเธดเนเธ: " . htmlspecialchars($type_info['name']);
$current_page = "manage_equip"; // (เนเธซเนเน€เธกเธเธน "เธเธฑเธ”เธเธฒเธฃเธญเธธเธเธเธฃเธ“เน" Active)
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/header.php');
?>

<div class="main-container">

    <div class="header-row">
        <h2><i class="fas fa-tools"></i> <?php echo $page_title; ?></h2>
        <div class="header-actions">
            <a href="admin/manage_equipment.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> เธเธฅเธฑเธเนเธเธซเธเนเธฒเธเธฃเธฐเน€เธ เธ—
            </a>
            <button type="button" class="btn btn-primary" onclick="openAddItemPopup(<?php echo $type_id; ?>, '<?php echo htmlspecialchars(addslashes($type['name'])); ?>')">
                <i class="fas fa-plus"></i> เน€เธเธดเนเธกเธญเธธเธเธเธฃเธ“เนเธเธดเนเธเนเธซเธกเน
            </button>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php else: ?>

    <div class="section-card">
        <div class="table-container desktop-only">
            <table>
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>เธเธทเนเธญ/เธฃเธธเนเธ</th>
                        <th>Serial Number</th>
                        <th style="width: 120px;">เธชเธ–เธฒเธเธฐ</th>
                        <th style="width: 180px;">เธเนเธญเธกเธนเธฅเธเธฒเธฃเธขเธทเธก</th>
                        <th style="width: 210px;">เธเธฑเธ”เธเธฒเธฃ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center;">เธขเธฑเธเนเธกเนเธกเธตเธญเธธเธเธเธฃเธ“เนเธฃเธฒเธขเธเธดเนเธเนเธเธเธฃเธฐเน€เธ เธ—เธเธตเน</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td data-label="ID"><strong><?php echo $item['id']; ?></strong></td>
                                <td data-label="เธเธทเนเธญเน€เธเธเธฒเธฐ" class="truncate-text" title="<?php echo htmlspecialchars($item['name']); ?>">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </td>
                                <td data-label="Serial"><?php echo htmlspecialchars($item['serial_number'] ?? '-'); ?></td>
                                <td data-label="เธชเธ–เธฒเธเธฐ">
                                    <?php 
                                    $status = $item['status'];
                                    $status_class = ($status == 'available') ? 'green' : (($status == 'borrowed') ? 'blue' : 'yellow');
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </td>
                                <td data-label="เธเนเธญเธกเธนเธฅเธขเธทเธก">
                                    <?php if ($status == 'borrowed' && $item['student_name']): ?>
                                        <strong>เธเธนเนเธขเธทเธก:</strong> <?php echo htmlspecialchars($item['student_name']); ?><br>
                                        <small>เธเธณเธซเธเธ”เธเธทเธ: <?php echo date('d/m/Y', strtotime($item['due_date'])); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="เธเธฑเธ”เธเธฒเธฃ" class="action-buttons">
                                    <button type="button" class="btn btn-manage btn-sm" title="เนเธเนเนเธ" onclick="openEditItemPopup(<?php echo $item['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" title="เธ”เธนเธเธฃเธฐเธงเธฑเธ•เธดเธเธฒเธฃเธขเธทเธก"
                                            onclick="openItemHistoryPopup(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')">
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <button type="button" class="btn btn-dark btn-sm" title="เธ”เธน/เธเธดเธกเธเนเธเธฒเธฃเนเนเธเนเธ”"
                                            onclick="openItemBarcodePopup(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>', '<?php echo htmlspecialchars(addslashes($item['serial_number'] ?? '-')); ?>')">
                                        <i class="fas fa-barcode"></i>
                                    </button>
                                    <?php if ($status != 'borrowed'): ?>
                                    <button type="button" class="btn btn-danger btn-sm" title="เธฅเธ" 
                                            onclick="confirmDeleteItem(<?php echo $item['id']; ?>, <?php echo $item['type_id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="student-card-list">
        <?php if (empty($items)): ?>
            <div class="history-card">
                <p style="text-align: center; width: 100%;">เธขเธฑเธเนเธกเนเธกเธตเธญเธธเธเธเธฃเธ“เนเธฃเธฒเธขเธเธดเนเธเนเธเธเธฃเธฐเน€เธ เธ—เธเธตเน</p>
            </div>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                
                <?php 
                $status = $item['status'];
                $status_class = ($status == 'available') ? 'green' : (($status == 'borrowed') ? 'blue' : 'yellow');
                $icon_class = ($status == 'available') ? 'fas fa-check-circle' : (($status == 'borrowed') ? 'fas fa-user-tag' : 'fas fa-wrench');
                ?>

                <div class="history-card">
                    
                    <div class="history-card-icon">
                        <span class="status-badge <?php echo $status_class; ?>">
                            <i class="<?php echo $icon_class; ?>"></i>
                        </span>
                    </div>

                    <div class="history-card-info">
                        <h4 class="truncate-text" title="<?php echo htmlspecialchars($item['name']); ?>">
                            <?php echo htmlspecialchars($item['name']); ?>
                        </h4>
                        <p>S/N: **<?php echo htmlspecialchars($item['serial_number'] ?? '-'); ?>**</p>
                        <p>เธชเธ–เธฒเธเธฐ: <strong><?php echo htmlspecialchars($status); ?></strong></p>
                        <?php if ($status == 'borrowed' && $item['student_name']): ?>
                            <p style="font-size: 0.9em; color: var(--color-text-dark);">
                                เธเธนเนเธขเธทเธก: <strong><?php echo htmlspecialchars($item['student_name']); ?></strong>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="action-buttons" style="flex-wrap: wrap;">
                        <button type="button" class="btn btn-manage btn-sm" title="เนเธเนเนเธ" onclick="openEditItemPopup(<?php echo $item['id']; ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-dark btn-sm" title="เธ”เธน/เธเธดเธกเธเนเธเธฒเธฃเนเนเธเนเธ”"
                                onclick="openItemBarcodePopup(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>', '<?php echo htmlspecialchars(addslashes($item['serial_number'] ?? '-')); ?>')">
                            <i class="fas fa-barcode"></i>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" title="เธ”เธนเธเธฃเธฐเธงเธฑเธ•เธด"
                                onclick="openItemHistoryPopup(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')">
                            <i class="fas fa-history"></i>
                        </button>

                        <?php if ($status != 'borrowed'): ?>
                        <button type="button" class="btn btn-danger btn-sm" title="เธฅเธ" 
                                onclick="confirmDeleteItem(<?php echo $item['id']; ?>, <?php echo $item['type_id']; ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php endif; ?>

</div>

<?php include('../includes/footer.php'); ?>
