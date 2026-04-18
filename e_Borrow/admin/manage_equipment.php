<?php
// manage_equipment.php (กู้ชีพ: แก้ไข SQL ให้คำนวณจำนวนอุปกรณ์อัตโนมัติ)
include('../includes/check_session.php');
require_once(__DIR__ . '/../../config/db_connect.php');

$pdo = db();

// 1. Guard สิทธิ์การเข้าถึง
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}

// 2. จัดการข้อความแจ้งเตือน
$message = $_GET['message'] ?? '';
$message_type = $_GET['type'] ?? 'success';

// 3. ตั้งค่าหน้าเพจ
$page_title = "จัดการประเภทอุปกรณ์";
$current_page = "manage_equip";

include('../includes/header.php');

// 4. ดึงข้อมูลประเภทอุปกรณ์ พร้อมนับจำนวน (Sub-queries)
try {
    $search_query = $_GET['search'] ?? '';

    // SQL อัจฉริยะ: นับจำนวนอุปกรณ์ทั้งหมด และอุปกรณ์ที่ว่าง (Status = 'available')
    $sql = "SELECT 
                bc.*,
                (SELECT COUNT(*) FROM borrow_items bi WHERE bi.type_id = bc.id) as total_quantity,
                (SELECT COUNT(*) FROM borrow_items bi WHERE bi.type_id = bc.id AND bi.status = 'available') as available_quantity
            FROM borrow_categories bc";

    $params = [];
    if (!empty($search_query)) {
        $sql .= " WHERE bc.name LIKE ? OR bc.description LIKE ?";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    $sql .= " ORDER BY bc.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("SQL Error in manage_equipment: " . $e->getMessage());
    $equipment_types = [];
    $error_msg = "ไม่สามารถเชื่อมต่อฐานข้อมูลได้ในขณะนี้";
}


?>

<div class="admin-wrap" style="padding: 20px;">
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="header-row"
        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <h2><i class="fas fa-tools"></i> จัดการประเภทอุปกรณ์</h2>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-primary" onclick="openAddEquipmentTypePopup()">
                <i class="fas fa-plus"></i> เพิ่มประเภทอุปกรณ์
            </button>
        </div>
    </div>

    <!-- ส่วนเนื้อหาหลัก (ตาราง) -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>ชื่อประเภทอุปกรณ์</th>
                    <th>รายละเอียด</th>
                    <th style="text-align: center;">จำนวน (ว่าง / ทั้งหมด)</th>
                    <th style="text-align: center;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($equipment_types)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px;">ไม่พบข้อมูลประเภทอุปกรณ์</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($equipment_types as $type): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($type['name']); ?></strong></td>
                            <td><span class="text-muted"><?php echo htmlspecialchars($type['description'] ?? '-'); ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge status-badge borrowed-ok">
                                    <?php echo $type['available_quantity']; ?> / <?php echo $type['total_quantity']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="admin/manage_items.php?type_id=<?php echo $type['id']; ?>" class="btn btn-return">
                                        <i class="fas fa-list-ol"></i> อุปกรณ์รายชิ้น
                                    </a>
                                    <button class="btn btn-secondary" onclick="openEditTypePopup(<?php echo $type['id']; ?>)">
                                        <i class="fas fa-edit"></i> แก้ไข
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include('../includes/footer.php'); ?>