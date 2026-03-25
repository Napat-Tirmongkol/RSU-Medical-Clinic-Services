<?php
include('../includes/check_session.php'); 
require_once(__DIR__ . '/../../../config/db_connect.php');

// โ… เนเธเนเธ”เนเธซเธกเน: START (เน€เธเธดเนเธก Guard)
$allowed_roles = ['admin', 'editor'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    header("Location: index.php"); // (เธ–เนเธฒเนเธกเนเนเธเน Admin เธซเธฃเธทเธญ Editor เนเธซเนเน€เธ”เนเธเธเธฅเธฑเธ)
    exit;
}


// (เนเธเนเธ”เธชเนเธงเธเธ•เธฃเธงเธเธชเธญเธ $_GET message ... เธขเธฑเธเธเธเน€เธ”เธดเธก)
$message = '';
$message_type = '';
if (isset($_GET['add']) && $_GET['add'] == 'success') {
    $message = 'เน€เธเธดเนเธกเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเนเธซเธกเนเธชเธณเน€เธฃเนเธ!';
    $message_type = 'success';
} elseif (isset($_GET['edit']) && $_GET['edit'] == 'success') {
    $message = 'เนเธเนเนเธเธเนเธญเธกเธนเธฅเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเธชเธณเน€เธฃเนเธ!';
    $message_type = 'success';
} 
// ( ... เนเธเนเธ” Error handling เธญเธทเนเธเน ... )

// 4. เธ•เธฑเนเธเธเนเธฒเธ•เธฑเธงเนเธเธฃเธชเธณเธซเธฃเธฑเธเธซเธเนเธฒเธเธตเน
$page_title = "เธเธฑเธ”เธเธฒเธฃเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน"; 
$current_page = "manage_equip";
// 5. เน€เธฃเธตเธขเธเนเธเนเนเธเธฅเน Header
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/header.php');

// 6. โ—€๏ธ (เนเธเนเนเธ) เธ”เธถเธเธเนเธญเธกเธนเธฅเธเธฒเธเธ•เธฒเธฃเธฒเธ "เธเธฃเธฐเน€เธ เธ—" (types)
try {
    // โ—€๏ธ (SQL เนเธเนเนเธ) เน€เธเธฅเธตเนเธขเธเธเธฒเธ med_equipment เน€เธเนเธ borrow_categories
    $sql = "SELECT * FROM borrow_categories";

    $conditions = [];
    $params = [];

    $search_query = $_GET['search'] ?? '';
    // $status_query = $_GET['status'] ?? ''; // (เธ•เธฒเธฃเธฒเธ Types เนเธกเนเธกเธต status)

    if (!empty($search_query)) {
        $search_term = '%' . $search_query . '%';
        $conditions[] = "(name LIKE ? OR description LIKE ?)"; // โ—€๏ธ (SQL เนเธเนเนเธ)
        $params[] = $search_term;
        $params[] = $search_term;
    }

    if (count($conditions) > 0) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $equipment_types = $stmt->fetchAll(PDO::FETCH_ASSOC); // โ—€๏ธ (เนเธเนเนเธเธเธทเนเธญเธ•เธฑเธงเนเธเธฃ)

} catch (PDOException $e) {
    echo "เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเธ”เธถเธเธเนเธญเธกเธนเธฅ: " . $e->getMessage();
    $equipment_types = [];
}
?>

<?php if ($message): ?>
 ย  ย <div style="padding: 15px; margin-bottom: 20px; border-radius: 4px; color: #fff; background-color: <?php echo ($message_type == 'success') ? 'var(--color-success)' : 'var(--color-danger)'; ?>;">
 ย  ย  ย  ย <?php echo $message; ?>
 ย  ย </div>
<?php endif; ?>


<?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'editor'])): ?>

<div class="header-row">
    <h2><i class="fas fa-tools"></i> เธเธฑเธ”เธเธฒเธฃเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน</h2>
    
    <div style="display: flex; gap: 10px;">
        <button class="add-btn" onclick="openBulkBarcodeForm()" style="background-color: var(--color-info);">
            <i class="fas fa-barcode"></i> เธเธดเธกเธเนเธเธฒเธฃเนเนเธเนเธ”
        </button>
        <button class="add-btn" onclick="openAddTypePopup()">
            <i class="fas fa-plus"></i> เน€เธเธดเนเธกเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน
        </button>
    </div>
</div>
<?php else: // (เธชเธณเธซเธฃเธฑเธ Role เธญเธทเนเธเธ—เธตเนเนเธกเนเนเธเน Admin เธซเธฃเธทเธญ Editor) ?>
<div class="header-row" style="cursor: default;"> 
    <h2><i class="fas fa-tools"></i> เธเธฑเธ”เธเธฒเธฃเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน</h2>
    </div>
<?php endif; ?>


<div class="filter-row">
    <form action="admin/manage_equipment.php" method="GET" style="display: contents;">
        <label for="search_term">เธเนเธเธซเธฒ:</label>
        <input type="text" name="search" id="search_term" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="เธเธทเนเธญเธเธฃเธฐเน€เธ เธ—/เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”">

        <button type="submit" class="btn btn-return"><i class="fas fa-filter"></i> เธเธฃเธญเธ</button>
        <a href="admin/manage_equipment.php" class="btn btn-secondary"><i class="fas fa-times"></i> เธฅเนเธฒเธเธเนเธฒ</a>
    </form>
</div>


<div class="table-container desktop-only">
    <table>
        <thead>
            <tr>
                <th style="width: 70px;">เธฃเธนเธเธ เธฒเธ</th>
                <th>เธเธทเนเธญเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน</th>
                <th>เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”</th>
                <th>เธเธณเธเธงเธ (เธงเนเธฒเธ/เธ—เธฑเนเธเธซเธกเธ”)</th>
                <th style="width: 250px;">เธเธฑเธ”เธเธฒเธฃ</th> </tr>
        </thead>
        <tbody>
            <?php if (empty($equipment_types)): ?>
                <tr>
                    <td colspan="5" style="text-align: center;">เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน</td>
                </tr>
            <?php else: ?>
                <?php foreach ($equipment_types as $type): ?>
                    <tr>
                        <td>
                            <?php if (!empty($type['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($type['image_url']); ?>"
                                    alt="เธฃเธนเธ"
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
                                <i class="fas fa-list-ol"></i> เธเธฑเธ”เธเธฒเธฃเธฃเธฒเธขเธเธดเนเธ
                            </a>
                            
                            <?php if ($_SESSION['role'] == 'admin'): ?>
                                <button type="button" class="btn btn-manage" style="margin-left: 5px;" onclick="openEditTypePopup(<?php echo $type['id']; ?>)">เนเธเนเนเธ</button>

                                <button type="button"
                                    class="btn btn-danger"
                                    style="margin-left: 5px;"
                                    onclick="confirmDeleteType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars(addslashes($type['name'])); ?>')">เธฅเธ</button>
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
            <p style="text-align: center; width: 100%;">เนเธกเนเธเธเธเนเธญเธกเธนเธฅเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน</p>
        </div>
    <?php else: ?>
        <?php foreach ($equipment_types as $type): ?>
            <div class="history-card">

                <div class="history-card-icon">
                    <?php if (!empty($type['image_url'])): ?>
                        <img src="<?php echo htmlspecialchars($type['image_url']); ?>" alt="เธฃเธนเธ" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
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
                    <p>เธเธณเธเธงเธ: 
                        <strong style="color: var(--color-success);"><?php echo $type['available_quantity']; ?></strong> / <?php echo $type['total_quantity']; ?>
                    </p>
                </div>

               <div class="pending-card-actions">

                    <a href="admin/manage_items.php?type_id=<?php echo $type['id']; ?>" class="btn btn-borrow" style="margin-left: 0;">
                        <i class="fas fa-list-ol"></i> เธเธฑเธ”เธเธฒเธฃ
                    </a>

                    <?php if ($_SESSION['role'] == 'admin'): ?>
                        <button type="button" class="btn btn-manage" onclick="openEditTypePopup(<?php echo $type['id']; ?>)">เนเธเนเนเธ</button>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteType(<?php echo $type['id']; ?>, '<?php echo htmlspecialchars(addslashes($type['name'])); ?>')">เธฅเธ</button>
                    <?php endif; ?>
                </div>

            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>


<script>
    // [เนเธซเธกเน] Export เธเนเธญเธกเธนเธฅเธญเธธเธเธเธฃเธ“เนเธ—เธตเนเธ”เธถเธเธกเธฒ เนเธซเน JS เธชเธฒเธกเธฒเธฃเธ–เนเธเนเนเธ”เน
    const equipmentTypesData = <?php echo json_encode($equipment_types); ?>;
</script>

<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // (เธเธฑเธเธเนเธเธฑเธ Add เธ—เธตเนเนเธเนเนเธเนเธฅเนเธง)
    function openAddTypePopup() {
        Swal.fire({
            title: 'โ• เน€เธเธดเนเธกเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเนเธซเธกเน',
            html: `
            <form id="swalAddTypeForm" style="text-align: left; margin-top: 20px;">
                <div style="margin-bottom: 15px;">
                    <label for="swal_type_name" style="font-weight: bold; display: block; margin-bottom: 5px;">เธเธทเนเธญเธเธฃเธฐเน€เธ เธ—:</label>
                    <input type="text" name="name" id="swal_type_name" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_type_desc" style="font-weight: bold; display: block; margin-bottom: 5px;">เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”:</label>
                    <textarea name="description" id="swal_type_desc" rows="3" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;"></textarea>
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_type_image_file" style="font-weight: bold; display: block; margin-bottom: 5px;">เนเธเธเธฃเธนเธเธ เธฒเธ (เธ–เนเธฒเธกเธต):</label>
                    <input type="file" name="image_file" id="swal_type_image_file" accept="image/*" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                </form>`,
            width: '600px',
            showCancelButton: true,
            confirmButtonText: 'เธเธฑเธเธ—เธถเธเธเธฃเธฐเน€เธ เธ—เนเธซเธกเน',
            cancelButtonText: 'เธขเธเน€เธฅเธดเธ',
            confirmButtonColor: 'var(--color-success, #28a745)',
            focusConfirm: false,
            preConfirm: () => {
                const form = document.getElementById('swalAddTypeForm');
                const name = form.querySelector('#swal_type_name').value;
                if (!name) {
                    Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเธทเนเธญเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน');
                    return false;
                }
                
                // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก "process/" (เธชเธฑเธกเธเธฑเธเธเนเธเธฑเธ <base href>) โ—€๏ธ
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
                        Swal.showValidationMessage(`เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ${error.message}`);
                    });
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // โ—€๏ธ (เนเธเนเนเธ) เนเธเนเนเธ location.href โ—€๏ธ
                Swal.fire('เน€เธเธดเนเธกเธชเธณเน€เธฃเนเธ!', 'เน€เธเธดเนเธกเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเนเธซเธกเนเน€เธฃเธตเธขเธเธฃเนเธญเธข', 'success').then(() => location.href = 'admin/manage_equipment.php?add=success');
            }
        });
    }
    
    // โ—€๏ธ (เธเธฑเธเธเนเธเธฑเธ "เธฅเธ" เธเธฃเธฐเน€เธ เธ—)
    function confirmDeleteType(typeId, typeName) {
        Swal.fire({
            title: "เธเธธเธ“เนเธเนเนเธเธซเธฃเธทเธญเนเธกเน?",
            text: `เธเธธเธ“เธเธณเธฅเธฑเธเธเธฐเธฅเธเธเธฃเธฐเน€เธ เธ— "${typeName}" (เธเธฐเธฅเธเนเธ”เนเธ•เนเธญเน€เธกเธทเนเธญเนเธกเนเธกเธตเธญเธธเธเธเธฃเธ“เนเธฃเธฒเธขเธเธดเนเธเนเธเธเธฃเธฐเน€เธ เธ—เธเธตเน)`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "เนเธเน, เธฅเธเน€เธฅเธข",
            cancelButtonText: "เธขเธเน€เธฅเธดเธ"
        }).then((result) => {
            if (result.isConfirmed) {
                // (เธชเนเธเธเนเธญเธกเธนเธฅเนเธเธ POST เนเธเธขเธฑเธเนเธเธฅเนเธฅเธ)
                const formData = new FormData();
                formData.append('id', typeId);

                // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก "process/" โ—€๏ธ
                fetch('process/delete_equipment_type_process.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('เธฅเธเธชเธณเน€เธฃเนเธ!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”!', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ” AJAX', error.message, 'error');
                });
            }
        });
    }

    // โ—€๏ธ (เธเธฑเธเธเนเธเธฑเธ "เนเธเนเนเธ" เธเธฃเธฐเน€เธ เธ—)
    function openEditTypePopup(typeId) {
        Swal.fire({ title: 'เธเธณเธฅเธฑเธเนเธซเธฅเธ”เธเนเธญเธกเธนเธฅ...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
        
        // 1. เธ”เธถเธเธเนเธญเธกเธนเธฅเน€เธ”เธดเธกเธกเธฒเนเธชเธ”เธ
        // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก "ajax/" โ—€๏ธ
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
                    // โ—€๏ธ (เนเธเนเนเธ) Path เธฃเธนเธเธ เธฒเธเธ–เธนเธเธ•เนเธญเธเนเธฅเนเธง (เน€เธเธฃเธฒเธฐ <base href>) โ—€๏ธ
                    imagePreviewHtml = `
                        <img src="${type.image_url}?t=${new Date().getTime()}" 
                             alt="เธฃเธนเธเธ•เธฑเธงเธญเธขเนเธฒเธ" 
                             style="width: 100%; height: 150px; object-fit: cover; border-radius: 6px; margin-bottom: 15px;"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                        <div class="equipment-card-image-placeholder" style="display: none; width: 100%; height: 150px; font-size: 3rem; margin-bottom: 15px; justify-content: center; align-items: center; background-color: #f0f0f0; color: #cccccc; border-radius: 6px;"><i class="fas fa-image"></i></div>`;
                }

                // 2. เนเธชเธ”เธ Popup
                Swal.fire({
                    title: '๐”ง เนเธเนเนเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน',
                    html: `
                    <form id="swalEditForm" style="text-align: left; margin-top: 20px;">
                        
                        ${imagePreviewHtml}
                        <input type="hidden" name="type_id" value="${type.id}">
                        
                        <div style="margin-bottom: 15px;">
                            <label for="swal_eq_image_file" style="font-weight: bold; display: block; margin-bottom: 5px;">เนเธเธเธฃเธนเธเธ เธฒเธเนเธซเธกเน (เน€เธเธทเนเธญเนเธ—เธเธ—เธตเน):</label>
                            <input type="file" name="image_file" id="swal_eq_image_file" accept="image/*" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                            <small style="color: #6c757d;">(เธซเธฒเธเนเธกเนเธ•เนเธญเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเธฃเธนเธ เนเธซเนเน€เธงเนเธเธงเนเธฒเธเนเธงเน)</small>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label for="swal_name" style="font-weight: bold; display: block; margin-bottom: 5px;">เธเธทเนเธญเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน:</label>
                            <input type="text" name="name" id="swal_name" value="${type.name}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label for="swal_desc" style="font-weight: bold; display: block; margin-bottom: 5px;">เธฃเธฒเธขเธฅเธฐเน€เธญเธตเธขเธ”:</label>
                            <textarea name="description" id="swal_desc" rows="3" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">${type.description || ''}</textarea>
                        </div>
                    </form>`,
                    width: '600px',
                    showCancelButton: true,
                    confirmButtonText: 'เธเธฑเธเธ—เธถเธเธเธฒเธฃเน€เธเธฅเธตเนเธขเธเนเธเธฅเธ',
                    cancelButtonText: 'เธขเธเน€เธฅเธดเธ',
                    confirmButtonColor: 'var(--color-primary, #0B6623)',
                    focusConfirm: false,
                    preConfirm: () => {
                        const form = document.getElementById('swalEditForm');
                        const name = form.querySelector('#swal_name').value;
                        if (!name) {
                            Swal.showValidationMessage('เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธเธทเนเธญเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน');
                            return false;
                        }
                        // 3. เธชเนเธเธเนเธญเธกเธนเธฅเนเธเธ—เธตเน
                        // โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก "process/" โ—€๏ธ
                        return fetch('process/edit_equipment_type_process.php', { method: 'POST', body: new FormData(form) })
                            .then(response => response.json())
                            .then(data => {
                                if (data.status !== 'success') throw new Error(data.message);
                                return data;
                            })
                            .catch(error => { Swal.showValidationMessage(`เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”: ${error.message}`); });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // โ—€๏ธ (เนเธเนเนเธ) เนเธเนเนเธ location.href โ—€๏ธ
                        Swal.fire('เธเธฑเธเธ—เธถเธเธชเธณเน€เธฃเนเธ!', 'เนเธเนเนเธเธเนเธญเธกเธนเธฅเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เนเน€เธฃเธตเธขเธเธฃเนเธญเธข', 'success').then(() => location.href = 'admin/manage_equipment.php?edit=success');
                    }
                });
            })
            .catch(error => {
                Swal.fire('เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”', error.message, 'error');
            });
    }
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
<?php
// 7. เน€เธฃเธตเธขเธเนเธเนเนเธเธฅเน Footer
// โ—€๏ธ (เนเธเนเนเธ) เน€เธเธดเนเธก ../ โ—€๏ธ
include('../includes/footer.php');
?>