<?php
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

// ✅ โค้ดใหม่: START (เพิ่ม Guard)
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php"); // (ถ้าไม่ใช่ Admin หรือ Editor ให้เด้งกลับ)
    exit;
}


// (โค้ดส่วนตรวจสอบ $_GET message ... ยังคงเดิม)
$message = '';
$message_type = '';
if (isset($_GET['add']) && $_GET['add'] == 'success') {
    $message = 'เพิ่มประเภทอุปกรณ์ใหม่สำเร็จ!';
    $message_type = 'success';
} elseif (isset($_GET['edit']) && $_GET['edit'] == 'success') {
    $message = 'แก้ไขข้อมูลประเภทอุปกรณ์สำเร็จ!';
    $message_type = 'success';
} 
// ( ... โค้ด Error handling อื่นๆ ... )

// 4. ตั้งค่าตัวแปรสำหรับหน้านี้
$page_title = "จัดการประเภทอุปกรณ์"; 
$current_page = "manage_equip";
// 5. เรียกใช้ไฟล์ Header
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/header.php');

// 6. ◀️ (แก้ไข) ดึงข้อมูลจากตาราง "ประเภท" (types)
try {
    // ◀️ (SQL แก้ไข) เปลี่ยนจาก med_equipment เป็น borrow_categories
    $sql = "SELECT * FROM borrow_categories";

    $conditions = [];
    $params = [];

    $search_query = $_GET['search'] ?? '';
    // $status_query = $_GET['status'] ?? ''; // (ตาราง Types ไม่มี status)

    if (!empty($search_query)) {
        $search_term = '%' . $search_query . '%';
        $conditions[] = "(name LIKE ? OR description LIKE ?)"; // ◀️ (SQL แก้ไข)
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipment_types = $stmt->fetchAll(PDO::FETCH_ASSOC); // ◀️ (แก้ไขชื่อตัวแปร)

} catch (PDOException $e) {
    echo "เกิดข้อผิดพลาดในการดึงข้อมูล: " . $e->getMessage();
    $equipment_types = [];
}
?>

<?php if ($message): ?>
    <div style="padding: 15px; margin-bottom: 20px; border-radius: 4px; color: #fff; background-color: <?php echo ($message_type == 'success') ? 'var(--color-success)' : 'var(--color-danger)'; ?>;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>


<?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor'])): ?>

<div class="header-row">
    <h2><i class="fas fa-tools"></i> จัดการประเภทอุปกรณ์</h2>
    
    <div style="display: flex; gap: 10px;">
        <button class="add-btn" onclick="openBulkBarcodeForm()" style="background-color: var(--color-info);">
            <i class="fas fa-barcode"></i> พิมพ์บาร์โค้ด
        </button>
        <button class="add-btn" onclick="openAddTypePopup()">
            <i class="fas fa-plus"></i> เพิ่มประเภทอุปกรณ์
        </button>
    </div>
</div>
<?php else: // (สำหรับ Role อื่นที่ไม่ใช่ Admin หรือ Editor) ?>
<div class="header-row" style="cursor: default;"> 
    <h2><i class="fas fa-tools"></i> จัดการประเภทอุปกรณ์</h2>
    </div>
<?php endif; ?>


<div class="filter-row">
    <form action="admin/manage_equipment.php" method="GET" style="display: contents;">
        <label for="search_term">ค้นหา:</label>
        <input type="text" name="search" id="search_term" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="ชื่อประเภท/รายละเอียด">

        <button type="submit" class="btn btn-return"><i class="fas fa-filter"></i> กรอง</button>
        <a href="admin/manage_equipment.php" class="btn btn-secondary"><i class="fas fa-times"></i> ล้างค่า</a>
    </form>
</div>


<div class="table-container desktop-only">
    <table>
        <thead>
            <tr>
                <th style="width: 70px;">รูปภาพ</th>
                <th>ชื่อประเภทอุปกรณ์</th>
                <th>รายละเอียด</th>
                <th>จำนวน (ว่าง/ทั้งหมด)</th>
                <th style="width: 250px;">จัดการ</th> </tr>
        </thead>
        <tbody>
            <?php if (empty($equipment_types)): ?>
                <tr>
                    <td colspan="5" style="text-align: center;">ไม่พบข้อมูลประเภทอุปกรณ์</td>
                </tr>
            <?php else: ?>
                <?php foreach ($equipment_types as $type): ?>
                    <tr>
                        <td>
                            <?php if (!empty($type['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($type['image_url']); ?>"
                                    alt="รูป"
                                    style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px;"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                                <div class="equipment-card-image-placeholder" style="display: none; width: 50px; height: 50px; font-size: 1.5rem;"><i class="fas fa-image"></i></div>
                            <?php else: ?>
                                <div class="equipment-card-image-placeholder" style="width: 50px; height: 50px; font-size: 1.5rem;">
                                    <i class="fas fa-camera"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($type['name']); ?></td>
                        <td style="white-space: pre-wrap;"><?php echo htmlspecialchars($type['description'] ?? '-'); ?></td>
                        <td>
                            <strong style="color: var(--color-success);"><?php echo $type['available_quantity']; ?></strong> / <?php echo $type['total_quantity']; ?>
                        </td>
                        <td class="action-buttons">
                            
                            <a href="admin/manage_items.php?type_id=<?php echo $type['id']; ?>" class="btn btn-borrow">
                                <i class="fas fa-list-ol"></i> จัดการรายชิ้น
                            </a>
                            
                            <?php if ($_SESSION['role'] == 'admin'): ?>
                                <button type="button" class="btn btn-manage" style="margin-left: 5px;" onclick="openEditTypePopup(<?php echo $type['id']; ?>)">แก้ไข</button>

                                <button type="button"
                                    class="btn btn-danger"
                                    style="margin-left: 5px;"
                                    onclick="confirmDeleteType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars(addslashes($type['name'])); ?>')">ลบ</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="student-card-list">
    <?php if (empty($equipment_types)): ?>
        <div class="history-card">
            <p style="text-align: center; width: 100%;">ไม่พบข้อมูลประเภทอุปกรณ์</p>
        </div>
    <?php else: ?>
        <?php foreach ($equipment_types as $type): ?>
            <div class="history-card">

                <div class="history-card-icon">
                    <?php if (!empty($type['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($type['image_url']); ?>" alt="รูป" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                        <div class="equipment-card-image-placeholder" style="display: none; width: 40px; height: 40px; font-size: 1.2rem;"><i class="fas fa-image"></i></div>
                    <?php else: ?>
                        <div class="equipment-card-image-placeholder" style="width: 40px; height: 40px; font-size: 1.2rem;">
                            <i class="fas fa-camera"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="history-card-info">
                    <h4 class="truncate-text" title="<?php echo htmlspecialchars($type['name']); ?>">
                        <?php echo htmlspecialchars($type['name']); ?>
                    </h4>
                    <p>จำนวน: 
                        <strong style="color: var(--color-success);"><?php echo $type['available_quantity']; ?></strong> / <?php echo $type['total_quantity']; ?>
                    </p>
                </div>

               <div class="pending-card-actions">

                    <a href="admin/manage_items.php?type_id=<?php echo $type['id']; ?>" class="btn btn-borrow" style="margin-left: 0;">
                        <i class="fas fa-list-ol"></i> จัดการ
                    </a>

                    <?php if ($_SESSION['role'] == 'admin'): ?>
                        <button type="button" class="btn btn-manage" onclick="openEditTypePopup(<?php echo $type['id']; ?>)">แก้ไข</button>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars(addslashes($type['name'])); ?>')">ลบ</button>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>


<script>
    // [ใหม่] Export ข้อมูลอุปกรณ์ที่ดึงมา ให้ JS สามารถใช้ได้
    const equipmentTypesData = <?php echo json_encode($equipment_types); ?>;
</script>

<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // (ฟังก์ชัน Add ที่แก้ไขแล้ว)
    function openAddTypePopup() {
        Swal.fire({
            title: '➕ เพิ่มประเภทอุปกรณ์ใหม่',
            html: `
            <form id="swalAddTypeForm" style="text-align: left; margin-top: 20px;">
                <div style="margin-bottom: 15px;">
                    <label for="swal_type_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อประเภท:</label>
                    <input type="text" name="name" id="swal_type_name" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_type_desc" style="font-weight: bold; display: block; margin-bottom: 5px;">รายละเอียด:</label>
                    <textarea name="description" id="swal_type_desc" rows="3" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;"></textarea>
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_type_image_file" style="font-weight: bold; display: block; margin-bottom: 5px;">แนบรูปภาพ (ถ้ามี):</label>
                    <input type="file" name="image_file" id="swal_type_image_file" accept="image/*" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                </form>`,
            width: '600px',
            showCancelButton: true,
            confirmButtonText: 'บันทึกประเภทใหม่',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: 'var(--color-success, #28a745)',
            focusConfirm: false,
            preConfirm: () => {
                const form = document.getElementById('swalAddTypeForm');
                const name = form.querySelector('#swal_type_name').value;
                if (!name) {
                    Swal.showValidationMessage('กรุณากรอกชื่อประเภทอุปกรณ์');
                    return false;
                }
                
                // ◀️ (แก้ไข) เพิ่ม "process/" (สัมพันธ์กับ <base href>) ◀️
                return fetch('process/add_equipment_type_process.php', {
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') throw new Error(data.message);
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // ◀️ (แก้ไข) แก้ไข location.href ◀️
                Swal.fire('เพิ่มสำเร็จ!', 'เพิ่มประเภทอุปกรณ์ใหม่เรียบร้อย', 'success').then(() => location.href = 'admin/manage_equipment.php?add=success');
            }
        });
    }
    
    // ◀️ (ฟังก์ชัน "ลบ" ประเภท)
    function confirmDeleteType(typeId, typeName) {
        Swal.fire({
            title: "คุณแน่ใจหรือไม่?",
            text: `คุณกำลังจะลบประเภท "${typeName}" (จะลบได้ต่อเมื่อไม่มีอุปกรณ์รายชิ้นในประเภทนี้)`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "ใช่, ลบเลย",
            cancelButtonText: "ยกเลิก"
        }).then((result) => {
            if (result.isConfirmed) {
                // (ส่งข้อมูลแบบ POST ไปยังไฟล์ลบ)
                const formData = new FormData();
                formData.append('id', typeId);

                // ◀️ (แก้ไข) เพิ่ม "process/" ◀️
                fetch('process/delete_equipment_type_process.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('ลบสำเร็จ!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('เกิดข้อผิดพลาด AJAX', error.message, 'error');
                });
            }
        });
    }

    // ◀️ (ฟังก์ชัน "แก้ไข" ประเภท)
    function openEditTypePopup(typeId) {
        Swal.fire({ title: 'กำลังโหลดข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
        
        // 1. ดึงข้อมูลเดิมมาแสดง
        // ◀️ (แก้ไข) เพิ่ม "ajax/" ◀️
        fetch(`ajax/get_equipment_type_data.php?id=${typeId}`)
            .then(response => response.json())
            .then(data => {
                if (data.status !== 'success') throw new Error(data.message);
                const type = data.equipment_type;
                
                let imagePreviewHtml = `
                    <div class="equipment-card-image-placeholder" style="width: 100%; height: 150px; font-size: 3rem; margin-bottom: 15px; display: flex; justify-content: center; align-items: center; background-color: #f0f0f0; color: #cccccc; border-radius: 6px;">
                        <i class="fas fa-camera"></i>
                    </div>`;
                if (type.image_url) {
                    // ◀️ (แก้ไข) Path รูปภาพถูกต้องแล้ว (เพราะ <base href>) ◀️
                    imagePreviewHtml = `
                        <img src="${type.image_url}?t=${new Date().getTime()}" 
                             alt="รูปตัวอย่าง" 
                             style="width: 100%; height: 150px; object-fit: cover; border-radius: 6px; margin-bottom: 15px;"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                        <div class="equipment-card-image-placeholder" style="display: none; width: 100%; height: 150px; font-size: 3rem; margin-bottom: 15px; justify-content: center; align-items: center; background-color: #f0f0f0; color: #cccccc; border-radius: 6px;"><i class="fas fa-image"></i></div>`;
                }

                // 2. แสดง Popup
                Swal.fire({
                    title: '🔧 แก้ไขประเภทอุปกรณ์',
                    html: `
                    <form id="swalEditForm" style="text-align: left; margin-top: 20px;">
                        
                        ${imagePreviewHtml}
                        <input type="hidden" name="type_id" value="${type.id}">
                        
                        <div style="margin-bottom: 15px;">
                            <label for="swal_eq_image_file" style="font-weight: bold; display: block; margin-bottom: 5px;">แนบรูปภาพใหม่ (เพื่อแทนที่):</label>
                            <input type="file" name="image_file" id="swal_eq_image_file" accept="image/*" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                            <small style="color: #6c757d;">(หากไม่ต้องการเปลี่ยนรูป ให้เว้นว่างไว้)</small>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label for="swal_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อประเภทอุปกรณ์:</label>
                            <input type="text" name="name" id="swal_name" value="${type.name}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label for="swal_desc" style="font-weight: bold; display: block; margin-bottom: 5px;">รายละเอียด:</label>
                            <textarea name="description" id="swal_desc" rows="3" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">${type.description || ''}</textarea>
                        </div>
                    </form>`,
                    width: '600px',
                    showCancelButton: true,
                    confirmButtonText: 'บันทึกการเปลี่ยนแปลง',
                    cancelButtonText: 'ยกเลิก',
                    confirmButtonColor: 'var(--color-primary, #0B6623)',
                    focusConfirm: false,
                    preConfirm: () => {
                        const form = document.getElementById('swalEditForm');
                        const name = form.querySelector('#swal_name').value;
                        if (!name) {
                            Swal.showValidationMessage('กรุณากรอกชื่อประเภทอุปกรณ์');
                            return false;
                        }
                        // 3. ส่งข้อมูลไปที่
                        // ◀️ (แก้ไข) เพิ่ม "process/" ◀️
                        return fetch('process/edit_equipment_type_process.php', { method: 'POST', body: new FormData(form) })
                            .then(response => response.json())
                            .then(data => {
                                if (data.status !== 'success') throw new Error(data.message);
                                return data;
                            })
                            .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // ◀️ (แก้ไข) แก้ไข location.href ◀️
                        Swal.fire('บันทึกสำเร็จ!', 'แก้ไขข้อมูลประเภทอุปกรณ์เรียบร้อย', 'success').then(() => location.href = 'admin/manage_equipment.php?edit=success');
                    }
                });
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
            });
    }
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
<?php
// 7. เรียกใช้ไฟล์ Footer
// ◀️ (แก้ไข) เพิ่ม ../ ◀️
include('../includes/footer.php');
?>