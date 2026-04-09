<?php
// [แก้ไขไฟล์: napat-tirmongkol/e-borrow/E-Borrow-c4df732f98db10bf52a8e9d7299e212b6f2abd37/admin/manage_items.php]

// 1. "จ้างยาม" และ "เชื่อมต่อ DB"
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');
$pdo = db();

// 2. ตรวจสอบสิทธิ์ Admin
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 3. รับ Type ID
$type_id = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
if ($type_id == 0) {
    header("Location: manage_equipment.php"); // (ถ้าไม่มี ID ให้เด้งกลับ)
    exit;
}

// 4. (Query ที่ 1) ดึงข้อมูล "ประเภท"
try {
    $stmt_type = $pdo->prepare("SELECT * FROM borrow_categories WHERE id = ?");
    $stmt_type->execute([$type_id]);
    $type_info = $stmt_type->fetch(PDO::FETCH_ASSOC);

    if (!$type_info) {
        header("Location: manage_equipment.php"); // (ถ้า ID ผิด ให้เด้งกลับ)
        exit;
    }
} catch (PDOException $e) {
    error_log("manage_items error: " . $e->getMessage()); $categories = [];
}

// 5. (Query ที่ 2) ดึงข้อมูล "ชิ้น" อุปกรณ์ (items)
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
    $items_error = "เกิดข้อผิดพลาดในการดึงข้อมูลอุปกรณ์: " . $e->getMessage();
    $items = [];
}

// 6. ตั้งค่าตัวแปรสำหรับ Header
$page_title = "จัดการรายชิ้น: " . htmlspecialchars($type_info['name']);
$current_page = "manage_equip"; // (ให้เมนู "จัดการอุปกรณ์" Active)
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/header.php');
?>

<div class="main-container">

    <div class="header-row">
        <h2><i class="fas fa-tools"></i> <?php echo $page_title; ?></h2>
        <div class="header-actions">
            <a href="admin/manage_equipment.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> กลับไปหน้าประเภท
            </a>
            <button type="button" class="btn btn-primary" onclick="openAddItemPopup(<?php echo $type_id; ?>, '<?php echo htmlspecialchars(addslashes($type['name'])); ?>')">
                <i class="fas fa-plus"></i> เพิ่มอุปกรณ์ชิ้นใหม่
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
                        <th>ชื่อ/รุ่น</th>
                        <th>Serial Number</th>
                        <th style="width: 120px;">สถานะ</th>
                        <th style="width: 180px;">ข้อมูลการยืม</th>
                        <th style="width: 210px;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center;">ยังไม่มีอุปกรณ์รายชิ้นในประเภทนี้</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td data-label="ID"><strong><?php echo $item['id']; ?></strong></td>
                                <td data-label="ชื่อเฉพาะ" class="truncate-text" title="<?php echo htmlspecialchars($item['name']); ?>">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </td>
                                <td data-label="Serial"><?php echo htmlspecialchars($item['serial_number'] ?? '-'); ?></td>
                                <td data-label="สถานะ">
                                    <?php 
                                    $status = $item['status'];
                                    $status_class = ($status == 'available') ? 'green' : (($status == 'borrowed') ? 'blue' : 'yellow');
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </td>
                                <td data-label="ข้อมูลยืม">
                                    <?php if ($status == 'borrowed' && $item['student_name']): ?>
                                        <strong>ผู้ยืม:</strong> <?php echo htmlspecialchars($item['student_name']); ?><br>
                                        <small>กำหนดคืน: <?php echo date('d/m/Y', strtotime($item['due_date'])); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="จัดการ" class="action-buttons">
                                    <button type="button" class="btn btn-manage btn-sm" title="แก้ไข" onclick="openEditItemPopup(<?php echo $item['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" title="ดูประวัติการยืม"
                                            onclick="openItemHistoryPopup(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')">
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <button type="button" class="btn btn-dark btn-sm" title="ดู/พิมพ์บาร์โค้ด"
                                            onclick="openItemBarcodePopup(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>', '<?php echo htmlspecialchars(addslashes($item['serial_number'] ?? '-')); ?>')">
                                        <i class="fas fa-barcode"></i>
                                    </button>
                                    <?php if ($status != 'borrowed'): ?>
                                    <button type="button" class="btn btn-danger btn-sm" title="ลบ" 
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
                <p style="text-align: center; width: 100%;">ยังไม่มีอุปกรณ์รายชิ้นในประเภทนี้</p>
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
                        <p>สถานะ: <strong><?php echo htmlspecialchars($status); ?></strong></p>
                        <?php if ($status == 'borrowed' && $item['student_name']): ?>
                            <p style="font-size: 0.9em; color: var(--color-text-dark);">
                                ผู้ยืม: <strong><?php echo htmlspecialchars($item['student_name']); ?></strong>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="action-buttons" style="flex-wrap: wrap;">
                        <button type="button" class="btn btn-manage btn-sm" title="แก้ไข" onclick="openEditItemPopup(<?php echo $item['id']; ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="btn btn-dark btn-sm" title="ดู/พิมพ์บาร์โค้ด"
                                onclick="openItemBarcodePopup(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>', '<?php echo htmlspecialchars(addslashes($item['serial_number'] ?? '-')); ?>')">
                            <i class="fas fa-barcode"></i>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" title="ดูประวัติ"
                                onclick="openItemHistoryPopup(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['name'])); ?>')">
                            <i class="fas fa-history"></i>
                        </button>

                        <?php if ($status != 'borrowed'): ?>
                        <button type="button" class="btn btn-danger btn-sm" title="ลบ" 
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
