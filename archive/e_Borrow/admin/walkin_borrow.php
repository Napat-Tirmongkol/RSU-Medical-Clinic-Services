<?php
// [เนเธเนเนเธเนเธเธฅเน: admin/walkin_borrow.php]
// (Version: เธชเธกเธเธนเธฃเธ“เนเธ—เธตเนเธชเธธเธ” - เธเธฃเธญเธเธฃเธซเธฑเธชเน€เธญเธเนเธ”เน + เธชเนเธเธ QR เนเธ”เน + เธเธฑเธเธ—เธถเธเนเธกเน Error)

include('../includes/check_session.php');
require_once(__DIR__ . '/../../../config/db_connect.php');

if (!in_array($_SESSION['role'], ['admin', 'employee', 'editor'])) {
    header("Location: index.php");
    exit;
}

$page_title = "เธขเธทเธกเธญเธธเธเธเธฃเธ“เน (Walk-in)";
include('../includes/header.php');
?>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<style>
    .dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 1.5fr;
        gap: 20px;
    }
    
    /* เธชเนเธ•เธฅเนเธชเธณเธซเธฃเธฑเธเธเนเธญเธเธเนเธเธซเธฒเธเธนเนเธขเธทเธก */
    .search-input-group {
        display: flex;
        gap: 5px;
    }
    .search-input-group input {
        flex: 1;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 4px;
        font-size: 1rem;
    }
    .search-input-group button {
        padding: 0 15px;
    }

    /* Dark Mode */
    body.dark-mode #student-info-box,
    body.dark-mode #item-info-box {
        background-color: #2d3748 !important; 
        border-left-color: #4a5568 !important; 
        color: #e2e8f0 !important;
    }
    body.dark-mode .search-input-group input {
        background-color: #4a5568;
        border-color: #718096;
        color: #fff;
    }

    /* Mobile */
    @media (max-width: 768px) {
        .dashboard-grid { grid-template-columns: 1fr !important; display: flex !important; flex-direction: column; gap: 15px; }
        body { display: flex; flex-direction: column; min-height: 100vh; padding-bottom: 0 !important; }
        .main-container { flex: 1; padding-bottom: 80px !important; overflow-y: auto; }
        .footer-nav { position: fixed !important; bottom: 0 !important; left: 0 !important; right: 0 !important; width: 100% !important; height: 60px; z-index: 9999 !important; display: flex !important; }
    }
</style>

<div class="main-container">
    <div class="header-row">
        <h2><i class="fas fa-shopping-cart"></i> เธขเธทเธกเธญเธธเธเธเธฃเธ“เน (Walk-in)</h2>
    </div>

   <div class="dashboard-grid">
        
        <div class="section-card">
            <h3 style="color: var(--color-primary);"><i class="fas fa-camera"></i> เธชเนเธเธ/เน€เธฅเธทเธญเธเธญเธธเธเธเธฃเธ“เน</h3>
            
            <div id="reader" style="width: 100%; min-height: 250px; background: #000; border-radius: 8px; overflow: hidden; position: relative;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; text-align: center;">
                    <i class="fas fa-video-slash" style="font-size: 3rem; opacity: 0.5;"></i>
                    <p style="margin-top: 10px;">เธเธฅเนเธญเธเธเธดเธ”เธญเธขเธนเน</p>
                </div>
            </div>

            <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn btn-primary btn-sm" id="startCameraBtn" onclick="startCamera()"><i class="fas fa-power-off"></i> เน€เธเธดเธ”เธเธฅเนเธญเธ</button>
                <button type="button" class="btn btn-danger btn-sm" id="stopCameraBtn" onclick="stopCamera()" style="display: none;"><i class="fas fa-stop"></i> เธเธดเธ”</button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('qr-input-file').click()"><i class="fas fa-image"></i> เธฃเธนเธเธ เธฒเธ</button>
                <input type="file" id="qr-input-file" accept="image/*" style="display: none;" onchange="scanFromFile(this)">
            </div>
            
            <hr>

            <div id="item-selector-box">
                <label style="font-weight: bold; margin-bottom: 5px; display: block;">เน€เธฅเธทเธญเธเธญเธธเธเธเธฃเธ“เนเธ”เนเธงเธขเธกเธทเธญ:</label>
                <div style="margin-bottom: 10px;">
                    <select id="manual_type_id" class="form-control" onchange="loadItemsForType(this.value)">
                        <option value="">-- 1. เน€เธฅเธทเธญเธเธเธฃเธฐเน€เธ เธ—เธญเธธเธเธเธฃเธ“เน --</option>
                        <?php 
                        $stmt = $pdo->query("SELECT * FROM borrow_categories WHERE available_quantity > 0 ORDER BY name ASC");
                        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            echo "<option value='{$row['id']}' data-name='{$row['name']}'>{$row['name']} (เธงเนเธฒเธ {$row['available_quantity']})</option>";
                        }
                        ?>
                    </select>
                </div>
                <div style="display: flex; gap: 5px;">
                    <select id="manual_item_id" class="form-control" style="flex: 1;" disabled>
                        <option value="">-- 2. เน€เธฅเธทเธญเธเธเธดเนเธเธญเธธเธเธเธฃเธ“เน (Serial/ID) --</option>
                    </select>
                    <button type="button" class="btn btn-success" onclick="addManualItem()" id="btnAddManual" disabled>
                        <i class="fas fa-plus"></i> เน€เธเธดเนเธก
                    </button>
                </div>
            </div>
        </div>

        <div class="section-card">
            <h3>เธฃเธฒเธขเธเธฒเธฃเธ—เธตเนเธเธฐเธขเธทเธก</h3>
            
            <form id="walkinForm">
                
                <div id="student-info-box" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px; border-left: 4px solid #ccc;">
                    <label style="font-weight: bold; display:block; margin-bottom: 8px;">๐‘ค เธเธนเนเธขเธทเธก (เธฃเธซเธฑเธชเธเธฑเธเธจเธถเธเธฉเธฒ/เธเธธเธเธฅเธฒเธเธฃ):</label>
                    
                    <div id="student-search-mode" class="search-input-group">
                        <input type="text" id="manual_student_code" placeholder="เธชเนเธเธ QR เธซเธฃเธทเธญเธเธดเธกเธเนเธฃเธซเธฑเธช..." onkeypress="handleEnterSearch(event)">
                        <button type="button" class="btn btn-primary" onclick="manualSearchStudent()">
                            <i class="fas fa-search"></i> เธเนเธเธซเธฒ
                        </button>
                    </div>

                    <div id="student-display-mode" style="display: none; align-items: center; justify-content: space-between;">
                        <div>
                            <div id="student-name-display" style="font-weight: bold; font-size: 1.1em; color: var(--color-primary);"></div>
                            <small id="student-code-display" style="color: #666;"></small>
                        </div>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="resetStudent()">
                            <i class="fas fa-times"></i> เน€เธเธฅเธตเนเธขเธ
                        </button>
                    </div>

                    <input type="hidden" name="student_id" id="input_student_id" required>
                </div>

                <div class="table-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #eee; border-radius: 8px; margin-bottom: 15px;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="background: #f0f0f0; position: sticky; top: 0;">
                            <tr>
                                <th style="padding: 10px;">เธญเธธเธเธเธฃเธ“เน</th>
                                <th style="padding: 10px; width: 80px;">ID/Serial</th>
                                <th style="padding: 10px; width: 50px;">เธฅเธ</th>
                            </tr>
                        </thead>
                        <tbody id="cart-body">
                            <tr id="empty-cart-row">
                                <td colspan="3" style="text-align: center; padding: 20px; color: #999;">เธขเธฑเธเนเธกเนเธกเธตเธฃเธฒเธขเธเธฒเธฃ</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label>เธเธณเธซเธเธ”เธเธทเธ:</label>
                    <input type="date" name="due_date" id="input_due_date" class="form-control" required style="width: 100%; padding: 10px;" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                </div>

                <input type="hidden" name="cart_data" id="input_cart_data">

                <button type="submit" class="btn btn-primary" id="submitBtn" style="width: 100%; padding: 12px;" disabled>
                    <i class="fas fa-save"></i> เธขเธทเธเธขเธฑเธเธเธฒเธฃเธขเธทเธกเธ—เธฑเนเธเธซเธกเธ”
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    // ----------------------------------------------------
    // 1. Config Path (เธชเธณเธเธฑเธเธกเธฒเธ เธซเนเธฒเธกเธฅเธ)
    // ----------------------------------------------------
    function getSiteUrls() {
        const fullPath = window.location.pathname;
        const adminIndex = fullPath.indexOf('/admin/');
        let rootPath = '';
        if (adminIndex !== -1) rootPath = fullPath.substring(0, adminIndex);
        return { ajax: rootPath + '/ajax', process: rootPath + '/process' };
    }
    window.API_URLS = getSiteUrls(); // เธเธฃเธฐเธเธฒเธจ Global

    // เธ•เธฑเธงเนเธเธฃเธญเธทเนเธเน
    let html5QrCode = null;
    let cart = []; 
    let scanLock = false;

    // ----------------------------------------------------
    // 2. เธเธฑเธ”เธเธฒเธฃเธเธนเนเธขเธทเธก (Manual Search & Display)
    // ----------------------------------------------------

    // เธเนเธเธซเธฒเน€เธกเธทเนเธญเธเธ” Enter
    function handleEnterSearch(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            manualSearchStudent();
        }
    }

    // เธเนเธเธซเธฒเน€เธกเธทเนเธญเธเธ”เธเธธเนเธก
    function manualSearchStudent() {
        const code = document.getElementById('manual_student_code').value.trim();
        if(!code) {
            Swal.fire('เนเธเนเธเน€เธ•เธทเธญเธ', 'เธเธฃเธธเธ“เธฒเธเธฃเธญเธเธฃเธซเธฑเธชเธเธฑเธเธจเธถเธเธฉเธฒ/เธเธธเธเธฅเธฒเธเธฃ', 'warning');
            return;
        }
        fetchStudent(code);
    }

    // เธเธฑเธเธเนเธเธฑเธเธ”เธถเธเธเนเธญเธกเธนเธฅเธเธนเนเธขเธทเธก (เนเธเนเธ—เธฑเนเธ QR เนเธฅเธฐ Manual)
    function fetchStudent(code, dbId = '') {
        // เน€เธฃเธตเธขเธ AJAX เนเธเธ—เธตเน API_URLS.ajax
        fetch(`${window.API_URLS.ajax}/get_student_by_code.php?id=${code}&db_id=${dbId}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    const s = data.student;
                    
                    // 1. เน€เธเนเธ ID เธเธฃเธดเธเธฅเธ Hidden Input
                    document.getElementById('input_student_id').value = s.id;
                    
                    // 2. เนเธชเธ”เธเธเนเธญเธกเธนเธฅเนเธ UI
                    document.getElementById('student-name-display').textContent = s.full_name;
                    document.getElementById('student-code-display').textContent = `เธฃเธซเธฑเธช: ${s.student_personnel_id || '-'}`;
                    
                    // 3. เธชเธฅเธฑเธเนเธซเธกเธ”: เธเนเธญเธเธเนเธญเธเธเธฃเธญเธ -> เนเธชเธ”เธเธเธทเนเธญ
                    document.getElementById('student-search-mode').style.display = 'none';
                    document.getElementById('student-display-mode').style.display = 'flex';
                    document.getElementById('student-info-box').style.borderLeftColor = 'var(--color-success)';
                    
                    // เนเธเนเธเน€เธ•เธทเธญเธเน€เธฅเนเธเธเนเธญเธข
                    const Toast = Swal.mixin({toast: true, position: 'top-end', showConfirmButton: false, timer: 1500});
                    Toast.fire({icon: 'success', title: 'เธเธเธเนเธญเธกเธนเธฅเธเธนเนเธขเธทเธก: ' + s.full_name});
                    
                    checkFormReady();
                } else {
                    Swal.fire('เนเธกเนเธเธเธเนเธญเธกเธนเธฅ', 'เนเธกเนเธเธเธเธฑเธเธจเธถเธเธฉเธฒ/เธเธธเธเธฅเธฒเธเธฃ เธฃเธซเธฑเธชเธเธตเน', 'error');
                    // เน€เธเธฅเธตเธขเธฃเนเธเนเธฒเน€เธเธทเนเธญเนเธซเนเธเธฃเธญเธเนเธซเธกเนเธชเธฐเธ”เธงเธ
                    document.getElementById('manual_student_code').value = '';
                    document.getElementById('manual_student_code').focus();
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire('Error', 'เน€เธเธดเธ”เธเนเธญเธเธดเธ”เธเธฅเธฒเธ”เนเธเธเธฒเธฃเน€เธเธทเนเธญเธกเธ•เนเธญ Server', 'error');
            });
    }

    // เธฃเธตเน€เธเนเธ•เธเธนเนเธขเธทเธก (เธเธ”เธเธธเนเธกเน€เธเธฅเธตเนเธขเธ)
    function resetStudent() {
        document.getElementById('input_student_id').value = '';
        document.getElementById('manual_student_code').value = '';
        
        // เธชเธฅเธฑเธเนเธซเธกเธ”เธเธฅเธฑเธ: เนเธชเธ”เธเธเนเธญเธเธเธฃเธญเธ
        document.getElementById('student-display-mode').style.display = 'none';
        document.getElementById('student-search-mode').style.display = 'flex';
        document.getElementById('student-info-box').style.borderLeftColor = '#ccc';
        
        // เนเธเธเธฑเธชเนเธซเนเธเธฃเนเธญเธกเธเธดเธกเธเนเธ—เธฑเธเธ—เธต
        setTimeout(() => document.getElementById('manual_student_code').focus(), 100);
        checkFormReady();
    }

    // ----------------------------------------------------
    // 3. เธฃเธฐเธเธเธเธฑเธ”เธเธฒเธฃเธชเธดเธเธเนเธฒ (Dropdown & Cart)
    // ----------------------------------------------------
    
    function loadItemsForType(typeId) {
        const itemSelect = document.getElementById('manual_item_id');
        const addBtn = document.getElementById('btnAddManual');
        itemSelect.innerHTML = '<option value="">เธเธณเธฅเธฑเธเนเธซเธฅเธ”...</option>';
        itemSelect.disabled = true; addBtn.disabled = true;

        if (!typeId) { itemSelect.innerHTML = '<option value="">-- 2. เน€เธฅเธทเธญเธเธเธดเนเธ --</option>'; return; }

        fetch(`${window.API_URLS.ajax}/get_available_items_for_barcode.php?type_id=${typeId}`)
            .then(res => res.json())
            .then(data => {
                itemSelect.innerHTML = '<option value="">-- เน€เธฅเธทเธญเธเธเธดเนเธ --</option>';
                if (data.status === 'success' && data.items.length > 0) {
                    data.items.forEach(item => {
                        if (!cart.find(c => c.id == item.id)) {
                            const label = item.serial_number ? `${item.name} (${item.serial_number})` : `${item.name} (ID: ${item.id})`;
                            const option = document.createElement('option');
                            option.value = item.id;
                            option.text = label;
                            option.setAttribute('data-name', item.name);
                            itemSelect.appendChild(option);
                        }
                    });
                    itemSelect.disabled = false; addBtn.disabled = false;
                } else {
                    itemSelect.innerHTML = '<option value="">เนเธกเนเธกเธตเธเธญเธเธงเนเธฒเธ</option>';
                }
            });
    }

    function addManualItem() {
        const typeSelect = document.getElementById('manual_type_id');
        const itemSelect = document.getElementById('manual_item_id');
        const itemId = itemSelect.value;
        if (!itemId) { Swal.fire('เน€เธ•เธทเธญเธ', 'เน€เธฅเธทเธญเธเธเธดเนเธเธญเธธเธเธเธฃเธ“เน', 'warning'); return; }
        
        const option = itemSelect.options[itemSelect.selectedIndex];
        addToCart(itemId, option.getAttribute('data-name'), option.text, typeSelect.value);
        itemSelect.remove(itemSelect.selectedIndex);
        itemSelect.value = "";
    }

    function addToCart(itemId, itemName, itemDisplayLabel, typeId) {
        if (cart.find(i => i.id == itemId)) return;
        cart.push({ id: itemId, name: itemName, label: itemDisplayLabel, type_id: typeId });
        renderCart();
    }

    function removeFromCart(index) {
        const removedItem = cart[index];
        cart.splice(index, 1);
        renderCart();
        const currentTypeId = document.getElementById('manual_type_id').value;
        if (currentTypeId == removedItem.type_id) loadItemsForType(currentTypeId);
    }

    function renderCart() {
        const tbody = document.getElementById('cart-body');
        tbody.innerHTML = '';
        if (cart.length === 0) {
            tbody.innerHTML = `<tr><td colspan="3" style="text-align: center; color: #999;">เธขเธฑเธเนเธกเนเธกเธตเธฃเธฒเธขเธเธฒเธฃ</td></tr>`;
            checkFormReady(); return;
        }
        cart.forEach((item, index) => {
            tbody.innerHTML += `
                <tr style="border-bottom: 1px solid #eee;">
                    <td style="padding: 10px;">${item.name}</td>
                    <td style="padding: 10px; text-align: center; font-size: 0.9em;">${item.label.replace(item.name, '').trim() || item.id}</td>
                    <td style="padding: 10px; text-align: center;">
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeFromCart(${index})"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
        });
        document.getElementById('input_cart_data').value = JSON.stringify(cart);
        checkFormReady();
    }

    // ----------------------------------------------------
    // 4. เธฃเธฐเธเธเธชเนเธเธ & เธเธฅเนเธญเธ (Integrate เธเธฑเธเธฃเธฐเธเธเธเนเธเธซเธฒเนเธซเธกเน)
    // ----------------------------------------------------

    function onScanSuccess(decodedText) {
        if (scanLock) return; scanLock = true; setTimeout(() => scanLock = false, 1500);
        
        if (decodedText.startsWith("MEDLOAN_STUDENT:")) {
            // เธเธฃเธ“เธตเธชเนเธเธเธเธฑเธ•เธฃเธเธฑเธเธจเธถเธเธฉเธฒ
            const parts = decodedText.split(":");
            let studentCode = parts[1];
            let dbId = parts[2] || ''; 
            
            // เนเธชเนเธเนเธฒเธฅเธเนเธเธเนเธญเธ Input เนเธซเนเน€เธซเนเธเธ”เนเธงเธข
            document.getElementById('manual_student_code').value = studentCode;
            
            // เน€เธฃเธตเธขเธเธเธฑเธเธเนเธเธฑเธเธเนเธเธซเธฒเน€เธ”เธตเธขเธงเธเธฑเธเนเธเธ Manual
            fetchStudent(studentCode, dbId); 
        } else {
            // เธเธฃเธ“เธตเธชเนเธเธเธเธญเธ (EQ-...)
            const itemId = decodedText.replace("EQ-", "");
            fetchItemAndAdd(itemId);
        }
    }

    function fetchItemAndAdd(id) {
        fetch(`${window.API_URLS.ajax}/get_item_data.php?id=${id}`).then(r => r.json()).then(d => {
            if (d.status === 'success') {
                if (d.item.status !== 'available') return Swal.fire('เนเธกเนเธงเนเธฒเธ', 'เธชเธ–เธฒเธเธฐ: ' + d.item.status, 'warning');
                addToCart(d.item.id, d.item.name, d.item.name, d.item.type_id);
                Swal.fire({icon:'success', title:'เน€เธเธดเนเธกเนเธฅเนเธง', timer:800, showConfirmButton:false});
            } else Swal.fire('Error', 'เนเธกเนเธเธเธญเธธเธเธเธฃเธ“เน', 'error');
        });
    }

    function startCamera() {
        if (typeof Html5Qrcode === "undefined") return alert("No Lib");
        if (html5QrCode) return alert("Camera ON");
        Html5Qrcode.getCameras().then(devices => {
            if (devices && devices.length) {
                html5QrCode = new Html5Qrcode("reader");
                html5QrCode.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, onScanSuccess)
                .then(() => {
                    document.getElementById('startCameraBtn').style.display = 'none';
                    document.getElementById('stopCameraBtn').style.display = 'inline-block';
                    const placeholder = document.querySelector('#reader > div');
                    if(placeholder) placeholder.style.display = 'none';
                });
            } else alert('No Camera');
        }).catch(err => alert('Camera Error: ' + err));
    }

    function stopCamera() {
        if (html5QrCode) html5QrCode.stop().then(() => {
            html5QrCode.clear(); html5QrCode = null;
            document.getElementById('startCameraBtn').style.display = 'inline-block';
            document.getElementById('stopCameraBtn').style.display = 'none';
        });
    }
    
    function scanFromFile(input) {
        if (input.files && input.files[0]) {
            if (!html5QrCode) html5QrCode = new Html5Qrcode("reader");
            Swal.fire({ title: 'Processing...', didOpen: () => { Swal.showLoading(); } });
            html5QrCode.scanFile(input.files[0], true).then(txt => {
                Swal.close(); onScanSuccess(txt);
            }).catch(() => { Swal.close(); Swal.fire('Error', 'Scan failed', 'error'); });
            input.value = ''; 
        }
    }

    // ----------------------------------------------------
    // 5. Submit Form
    // ----------------------------------------------------

    function checkFormReady() {
        const ready = document.getElementById('input_student_id').value && cart.length > 0;
        document.getElementById('submitBtn').disabled = !ready;
    }

    document.getElementById('walkinForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> เธเธณเธฅเธฑเธเธเธฑเธเธ—เธถเธ...';
        
        const formData = new FormData(this);
        formData.append('lending_staff_id', '<?php echo $_SESSION['user_id']; ?>');

        if (!window.API_URLS) { Swal.fire('Error', 'API_URLS missing', 'error'); return; }

        fetch(`${window.API_URLS.process}/admin_direct_borrow_process.php`, {
            method: 'POST', body: formData
        }).then(r => r.text()).then(t => {
            try {
                const d = JSON.parse(t);
                if(d.status === 'success') Swal.fire('เธชเธณเน€เธฃเนเธ', d.message, 'success').then(() => location.reload());
                else Swal.fire('Error', d.message, 'error');
            } catch { Swal.fire('Error', 'Server Error', 'error'); console.error(t); }
        }).finally(() => { 
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> เธขเธทเธเธขเธฑเธเธเธฒเธฃเธขเธทเธกเธ—เธฑเนเธเธซเธกเธ”'; 
        });
    });
</script>
<?php include('../includes/footer.php'); ?>