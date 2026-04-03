// [ฉบับสมบูรณ์: assets/js/admin_app.js]
// (เพิ่มฟังก์ชัน openBulkBarcodeForm ที่ขาดหายไปที่ส่วนท้ายสุดแล้ว)

// =========================================
// ✅ Global Variables & Helper Functions สำหรับ Bulk Barcode Printing
// =========================================

// ดักจับการเรียกใช้ข้อมูล (Fetch) ทั้งหมดในระบบ
const originalFetch = window.fetch;
window.fetch = function() {
    return originalFetch.apply(this, arguments).then(async response => {
        // ถ้า Server ตอบกลับมาว่า 401 (Session หมดอายุ)
        if (response.status === 401) {
            // แจ้งเตือนสวยๆ
            await Swal.fire({
                icon: 'warning',
                title: 'หมดเวลาการใช้งาน',
                text: 'กรุณาเข้าสู่ระบบใหม่อีกครั้ง',
                confirmButtonText: 'ตกลง',
                allowOutsideClick: false
            });
            // ดีดกลับหน้า Login
            window.location.href = 'login.php';
            // หยุดการทำงานต่อ
            throw new Error('Session Expired');
        }
        return response;
    });
};

let printCart = []; 

// 1. ฟังก์ชันสร้าง HTML ตะกร้า (เปลี่ยน Input เป็นปุ่ม)
const renderCartHtml = () => {
    let html = '';
    if (printCart.length === 0) {
        return '<div style="padding: 20px; color: var(--color-text-muted); text-align: center;">ยังไม่มีรายการในตะกร้า</div>';
    }
    
    html += `<table style="width: 100%; border-collapse: collapse; font-size: 0.95em;">
                <thead class="cart-table-head">
                    <tr>
                        <th style="padding: 8px; text-align: left;">รายการ</th>
                        <th style="padding: 8px; width: 140px; text-align: center;">เลือกชิ้น</th>
                        <th style="padding: 8px; width: 40px;">ลบ</th>
                    </tr>
                </thead><tbody>`;
    
    printCart.forEach((item, index) => {
        const count = item.selected_ids.length;
        const btnClass = count > 0 ? 'btn-success' : 'btn-warning';
        const btnText = count > 0 ? `เลือกแล้ว (${count})` : 'เลือกระบุชิ้น';

        html += `
            <tr class="cart-table-row">
                <td style="padding: 8px;"><strong>${item.name}</strong></td>
                <td style="padding: 8px; text-align: center;">
                    <button type="button" class="btn btn-sm ${btnClass}" 
                            onclick="openItemSelectionPopup(${index})"
                            style="width: 100%; font-size: 0.9em;">
                        <i class="fas fa-list-check"></i> ${btnText}
                    </button>
                </td>
                <td style="padding: 8px; text-align: center;">
                    <button type="button" class="btn btn-danger btn-sm" onclick="removePrintItem(${index})"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
    });
    html += '</tbody></table>';
    return html;
};

// 2. ฟังก์ชันเพิ่มรายการลงตะกร้า (Initialize selected_ids เป็น [])
function addTypeToCart() {
    const select = document.getElementById('bulk_type_id');
    const typeId = select.value;
    if (!typeId) return;

    const option = select.options[select.selectedIndex];
    const name = option.getAttribute('data-name');
    
    if (printCart.find(i => i.type_id == typeId)) {
        Swal.fire('รายการซ้ำ', 'ประเภทอุปกรณ์นี้อยู่ในตะกร้าแล้ว', 'info');
        return;
    }

    // เพิ่มเข้าตะกร้าโดยยังไม่มี ID ที่เลือก
    printCart.push({ type_id: typeId, name: name, selected_ids: [] });
    
    // อัปเดตหน้าจอ
    document.getElementById('cart-display').innerHTML = renderCartHtml();
    select.value = ''; 
}

// 3. ฟังก์ชันลบรายการ
function removePrintItem(index) {
    printCart.splice(index, 1);
    document.getElementById('cart-display').innerHTML = renderCartHtml();
}

// 4. [ใหม่] ฟังก์ชันเปิด Popup เลือก Item รายตัว
function openItemSelectionPopup(index) {
    const item = printCart[index];
    
    // ปิด Popup หลักก่อน (SweetAlert ซ้อนกันไม่ได้แบบ Direct)
    Swal.close(); 

    // โหลดข้อมูล Item ที่ว่างอยู่
    Swal.fire({
        title: 'กำลังโหลดรายชื่ออุปกรณ์...',
        didOpen: () => Swal.showLoading()
    });

    fetch(`ajax/get_available_items_for_barcode.php?type_id=${item.type_id}`)
        .then(res => res.json())
        .then(data => {
            if(data.status !== 'success') throw new Error(data.message || 'Error loading items');
            
            const availableItems = data.items;
            
            if(availableItems.length === 0) {
                Swal.fire('ไม่มีของว่าง', 'ไม่มีอุปกรณ์สถานะ Available ในประเภทนี้', 'warning')
                    .then(() => openBulkBarcodeForm()); // กลับไปหน้าหลัก
                return;
            }

            // สร้าง HTML Checkbox List
            let listHtml = `<div style="text-align: left; max-height: 300px; overflow-y: auto; border: 1px solid #eee; padding: 10px; border-radius: 4px;">`;
            
            // ปุ่ม "เลือกทั้งหมด"
            listHtml += `
                <div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                    <label style="cursor: pointer; font-weight: bold; color: var(--color-primary);">
                        <input type="checkbox" onchange="toggleAllBarcodeItems(this)"> เลือกทั้งหมด (${availableItems.length})
                    </label>
                </div>
            `;

            availableItems.forEach(i => {
                const isChecked = item.selected_ids.includes(String(i.id)) || item.selected_ids.includes(i.id) ? 'checked' : '';
                listHtml += `
                    <div style="margin-bottom: 5px;">
                        <label style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" name="barcode_item_select" value="${i.id}" ${isChecked}>
                            <span>
                                <strong>ID: ${i.id}</strong> - ${i.name} 
                                <span style="color: #777; font-size: 0.9em;">${i.serial_number ? '(S/N: '+i.serial_number+')' : ''}</span>
                            </span>
                        </label>
                    </div>
                `;
            });
            listHtml += `</div>`;

            // เปิด Popup ให้เลือก
            Swal.fire({
                title: `เลือก: ${item.name}`,
                html: listHtml,
                width: '500px',
                showCancelButton: true,
                confirmButtonText: 'ยืนยันการเลือก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-success)',
                preConfirm: () => {
                    // เก็บค่า ID ที่ถูกติ๊ก
                    const checkboxes = document.querySelectorAll('input[name="barcode_item_select"]:checked');
                    const selected = Array.from(checkboxes).map(cb => cb.value);
                    return selected;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // บันทึกค่าลงตัวแปร Global
                    printCart[index].selected_ids = result.value;
                }
                // ไม่ว่าจะยืนยันหรือยกเลิก ให้เปิดหน้าหลักกลับมาเสมอ
                openBulkBarcodeForm();
            });

        })
        .catch(err => {
            Swal.fire('Error', err.message, 'error').then(() => openBulkBarcodeForm());
        });
}

// Helper สำหรับติ๊กถูกทั้งหมด
window.toggleAllBarcodeItems = function(source) {
    const checkboxes = document.querySelectorAll('input[name="barcode_item_select"]');
    for(var i=0, n=checkboxes.length;i<n;i++) {
        checkboxes[i].checked = source.checked;
    }
}

// 5. [แก้ไข] ฟังก์ชันหลัก openBulkBarcodeForm
function openBulkBarcodeForm() {
    let options = '<option value="">-- เลือกประเภทอุปกรณ์ --</option>';
    if (typeof equipmentTypesData !== 'undefined') {
        equipmentTypesData.forEach(type => {
            options += `<option value="${type.id}" data-name="${type.name}" data-max="${type.total_quantity}">${type.name} (ทั้งหมด: ${type.total_quantity})</option>`;
        });
    }

    Swal.fire({
        title: '🖨️ พิมพ์บาร์โค้ด (ระบุชิ้น)',
        html: `
            <div style="text-align: left;">
                <div class="swal-section-box">
                    <label style="font-weight: bold;">1. เลือกประเภทอุปกรณ์:</label>
                    <div style="display: flex; gap: 10px; margin-top: 5px;">
                        <select id="bulk_type_id" class="swal2-select" style="margin: 0; flex: 1;">
                            ${options}
                        </select>
                        <button type="button" class="btn btn-primary" onclick="addTypeToCart()" style="width: 80px;">เพิ่ม</button>
                    </div>
                </div>

                <div style="border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 10px;">2. รายการที่จะพิมพ์:</label>
                    <div id="cart-display" class="swal-cart-display">
                        ${renderCartHtml()}
                    </div>
                </div>
            </div>
        `,
        width: '650px',
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-print"></i> พิมพ์บาร์โค้ด',
        cancelButtonText: 'ปิด',
        confirmButtonColor: 'var(--color-info)',
        preConfirm: () => {
            // ตรวจสอบว่ามีรายการและเลือกชิ้นแล้วหรือยัง
            if (printCart.length === 0) {
                Swal.showValidationMessage('กรุณาเพิ่มรายการอย่างน้อย 1 รายการ');
                return false;
            }
            
            // กรองเอาเฉพาะรายการที่มีการเลือก item แล้ว
            const validItems = printCart.filter(i => i.selected_ids.length > 0);
            
            if (validItems.length === 0) {
                Swal.showValidationMessage('กรุณากดปุ่ม "เลือกระบุชิ้น" และเลือกอุปกรณ์อย่างน้อย 1 ชิ้น');
                return false;
            }
            
            return validItems;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // ส่งข้อมูลเป็น JSON ที่มี selected_ids ไป
            const cartData = encodeURIComponent(JSON.stringify(result.value));
            window.open(`admin/print_barcode_bulk.php?data=${cartData}`, '_blank');
        }
    });
}


// =========================================
// ✅ 1. ฟังก์ชันชำระค่าปรับ (FINES)
// =========================================

function openDirectPaymentPopup(transactionId, studentId, studentName, equipName, daysOverdue, calculatedFine, onSuccessCallback = null) {
    const setupPaymentMethodToggle_Direct = () => {
        try {
            const cashRadio = Swal.getPopup().querySelector('#swal_pm_cash_1');
            const bankRadio = Swal.getPopup().querySelector('#swal_pm_bank_1');
            const slipGroup = Swal.getPopup().querySelector('#slipUploadGroup');
            const slipInput = Swal.getPopup().querySelector('#swal_payment_slip');
            const slipRequired = Swal.getPopup().querySelector('#slipRequired');

            const toggleLogic = (method) => {
                if (method === 'bank_transfer') {
                    slipGroup.style.display = 'block'; slipInput.required = true; slipRequired.style.display = 'inline';
                } else {
                    slipGroup.style.display = 'none'; slipInput.required = false; slipRequired.style.display = 'none';
                }
            };
            cashRadio.addEventListener('change', () => toggleLogic('cash'));
            bankRadio.addEventListener('change', () => toggleLogic('bank_transfer'));
            toggleLogic('cash');
        } catch (e) { console.error('Swal Toggle Error:', e); }
    };

    Swal.fire({
        title: '💵 บันทึกการชำระเงิน (เกินกำหนด)',
        html: `
        <div class="swal-info-box">
            <p style="margin: 0;"><strong>ผู้ยืม:</strong> ${studentName}</p>
            <p style="margin: 5px 0 0 0;"><strong>อุปกรณ์:</strong> ${equipName}</p>
            <p style="margin: 5px 0 0 0;" class="swal-info-danger">
                <strong>เกินกำหนด:</strong> ${daysOverdue} วัน
            </p>
        </div>
        
        <form id="swalDirectPaymentForm" style="text-align: left; margin-top: 20px;" enctype="multipart/form-data">
            <input type="hidden" name="transaction_id" value="${transactionId}">
            <input type="hidden" name="student_id" value="${studentId}">
            <input type="hidden" name="amount" value="${calculatedFine.toFixed(2)}">
            <input type="hidden" name="notes" value="เกินกำหนด ${daysOverdue} วัน">

            <div style="margin-bottom: 15px;">
                <label for="swal_amount_paid" style="font-weight: bold; display: block; margin-bottom: 5px;">จำนวนเงินที่รับชำระ: <span style="color:red;">*</span></label>
                <input type="number" name="amount_paid" id="swal_amount_paid" value="${calculatedFine.toFixed(2)}" step="0.01" required 
                       style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd; font-size: 1.2em; color: var(--color-primary); font-weight: bold;">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">วิธีการชำระเงิน: <span style="color:red;">*</span></label>
                <div style="display: flex; gap: 1rem;">
                    <label style="font-weight: normal;">
                        <input type="radio" name="payment_method" id="swal_pm_cash_1" value="cash" checked> เงินสด
                    </label>
                    <label style="font-weight: normal;">
                        <input type="radio" name="payment_method" id="swal_pm_bank_1" value="bank_transfer"> บัญชีธนาคาร
                    </label>
                </div>
            </div>

            <div id="slipUploadGroup" style="display: none; margin-bottom: 15px;">
                <label for="swal_payment_slip" style="font-weight: bold; display: block; margin-bottom: 5px;">แนบสลิปการโอน: <span id="slipRequired" style="color:red; display: none;">*</span></label>
                <input type="file" name="payment_slip" id="swal_payment_slip" accept="image/*"
                       style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
            </div>
        </form>`,
        didOpen: () => {
            setupPaymentMethodToggle_Direct();
        },
        showCancelButton: true,
        confirmButtonText: 'ยืนยันการชำระเงิน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: 'var(--color-success)',
        focusConfirm: false,
        preConfirm: () => {
            const form = document.getElementById('swalDirectPaymentForm');
            const formData = new FormData(form); 
            
            const paymentMethod = formData.get('payment_method');
            const slipFile = formData.get('payment_slip');

            if (paymentMethod === 'bank_transfer' && (!slipFile || slipFile.size === 0)) {
                Swal.showValidationMessage('กรุณาแนบสลิปการโอน');
                return false;
            }
            
            if (!form.checkValidity()) {
                Swal.showValidationMessage('กรุณากรอกข้อมูล * ให้ครบถ้วน');
                return false;
            }
            
            return fetch('process/direct_payment_process.php', { method: 'POST', body: formData }) 
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'success') throw new Error(data.message);
                    return data; 
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'ชำระเงินสำเร็จ!',
                text: 'บันทึกการชำระเงินเรียบร้อย',
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-print"></i> พิมพ์ใบเสร็จ',
                cancelButtonText: 'ปิดหน้าต่าง',
            }).then((finalResult) => {
                if (finalResult.isConfirmed) {
                    const newPaymentId = result.value.new_payment_id;
                    window.open(`admin/print_receipt.php?payment_id=${newPaymentId}`, '_blank');
                }
                
                if (onSuccessCallback) {
                    onSuccessCallback(); 
                } else {
                    location.reload(); 
                }
            });
        }
    });
}

function openRecordPaymentPopup(fineId, studentName, amountDue, onSuccessCallback = null) {
    const setupPaymentMethodToggle_Record = () => {
        try {
            const cashRadio = Swal.getPopup().querySelector('#swal_pm_cash_2');
            const bankRadio = Swal.getPopup().querySelector('#swal_pm_bank_2');
            const slipGroup = Swal.getPopup().querySelector('#slipUploadGroup');
            const slipInput = Swal.getPopup().querySelector('#swal_payment_slip');
            const slipRequired = Swal.getPopup().querySelector('#slipRequired');

            const toggleLogic = (method) => {
                if (method === 'bank_transfer') {
                    slipGroup.style.display = 'block'; slipInput.required = true; slipRequired.style.display = 'inline';
                } else {
                    slipGroup.style.display = 'none'; slipInput.required = false; slipRequired.style.display = 'none';
                }
            };
            cashRadio.addEventListener('change', () => toggleLogic('cash'));
            bankRadio.addEventListener('change', () => toggleLogic('bank_transfer'));
            toggleLogic('cash');
        } catch (e) { console.error('Swal Toggle Error:', e); }
    };

    Swal.fire({
        title: '💵 บันทึกการชำระเงิน',
        html: `
        <div class="swal-info-box">
            <p style="margin: 0;"><strong>ผู้ยืม:</strong> ${studentName}</p>
            <p style="margin: 5px 0 0 0;"><strong>ยอดค้างชำระ:</strong> ${amountDue.toFixed(2)} บาท</p>
        </div>
        <form id="swalPaymentForm" style="text-align: left; margin-top: 20px;" enctype="multipart/form-data">
            <input type="hidden" name="fine_id" value="${fineId}">
            
            <div style="margin-bottom: 15px;">
                <label for="swal_amount_paid" style="font-weight: bold; display: block; margin-bottom: 5px;">จำนวนเงินที่รับ: <span style="color:red;">*</span></label>
                <input type="number" name="amount_paid" id="swal_amount_paid" value="${amountDue.toFixed(2)}" step="0.01" required 
                       style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
            </div>

            <div style="margin-bottom: 15px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;">วิธีการชำระเงิน: <span style="color:red;">*</span></label>
                <div style="display: flex; gap: 1rem;">
                    <label style="font-weight: normal;">
                        <input type="radio" name="payment_method" id="swal_pm_cash_2" value="cash" checked> เงินสด
                    </label>
                    <label style="font-weight: normal;">
                        <input type="radio" name="payment_method" id="swal_pm_bank_2" value="bank_transfer"> บัญชีธนาคาร
                    </label>
                </div>
            </div>

            <div id="slipUploadGroup" style="display: none; margin-bottom: 15px;">
                <label for="swal_payment_slip" style="font-weight: bold; display: block; margin-bottom: 5px;">แนบสลิปการโอน: <span id="slipRequired" style="color:red; display: none;">*</span></label>
                <input type="file" name="payment_slip" id="swal_payment_slip" accept="image/*"
                       style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
            </div>
        </form>`,
        didOpen: () => {
            setupPaymentMethodToggle_Record();
        },
        showCancelButton: true,
        confirmButtonText: 'ยืนยันการชำระเงิน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: 'var(--color-success)',
        focusConfirm: false,
        preConfirm: () => {
            const form = document.getElementById('swalPaymentForm');
            const formData = new FormData(form);

            const paymentMethod = formData.get('payment_method');
            const slipFile = formData.get('payment_slip');

            if (paymentMethod === 'bank_transfer' && (!slipFile || slipFile.size === 0)) {
                Swal.showValidationMessage('กรุณาแนบสลิปการโอน');
                return false;
            }

            if (!form.checkValidity()) {
                Swal.showValidationMessage('กรุณากรอกจำนวนเงิน');
                return false;
            }
            return fetch('process/record_payment_process.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'success') throw new Error(data.message);
                    return data; 
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'ชำระเงินสำเร็จ!',
                text: 'บันทึกการชำระเงินเรียบร้อย',
                icon: 'success',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-print"></i> พิมพ์ใบเสร็จ',
                cancelButtonText: 'ปิดหน้าต่าง',
            }).then((finalResult) => {
                if (finalResult.isConfirmed) {
                    const newPaymentId = result.value.new_payment_id;
                    window.open(`admin/print_receipt.php?payment_id=${newPaymentId}`, '_blank');
                }
                
                if (onSuccessCallback) {
                    onSuccessCallback(); 
                } else {
                    location.reload(); 
                }
            });
        }
    });
}

function openFineAndReturnPopup(transactionId, studentId, studentName, equipName, daysOverdue, calculatedFine, equipmentId) {
    const returnCallback = () => {
        openReturnPopup(equipmentId);
    };
    openDirectPaymentPopup(transactionId, studentId, studentName, equipName, daysOverdue, calculatedFine, returnCallback);
}

// =========================================
// ✅ 2. ฟังก์ชันสำหรับ "จัดการอุปกรณ์" และ "ยืมของ"
// =========================================

function openBorrowPopup(typeId) {
    Swal.fire({ title: 'กำลังโหลดข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    
    fetch(`ajax/get_borrow_form_data.php?type_id=${typeId}`) 
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') throw new Error(data.message);
            
            let borrowerOptions = '<option value="">--- กรุณาเลือกผู้ยืม ---</option>';
            if (data.borrowers.length > 0) {
                data.borrowers.forEach(b => { 
                    borrowerOptions += `<option value="${b.id}">${b.full_name} (${b.contact_info || 'N/A'})</option>`;
                });
            } else {
                borrowerOptions = '<option value="" disabled>ยังไม่มีข้อมูลผู้ใช้งานในระบบ</option>';
            }
            
            Swal.fire({
                title: '📝 ฟอร์มยืมอุปกรณ์',
                html: `
                <div class="swal-info-box">
                    <p style="margin: 0;"><strong>ประเภทอุปกรณ์:</strong> ${data.equipment_type.name}</p>
                </div>
                <form id="swalBorrowForm" style="text-align: left; margin-top: 20px;">
                    <input type="hidden" name="type_id" value="${data.equipment_type.id}">
                    <div style="margin-bottom: 15px;">
                        <label for="swal_borrower_id" style="font-weight: bold; display: block; margin-bottom: 5px;">ผู้ยืม:</label>
                        <select name="borrower_id" id="swal_borrower_id" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                            ${borrowerOptions}
                        </select>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="swal_due_date" style="font-weight: bold; display: block; margin-bottom: 5px;">วันที่กำหนดคืน:</label>
                        <input type="date" name="due_date" id="swal_due_date" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                </form>`,
                width: '600px',
                showCancelButton: true,
                confirmButtonText: 'ยืนยันการยืม',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-success, #28a745)',
                focusConfirm: false,
                preConfirm: () => {
                    const form = document.getElementById('swalBorrowForm');
                    const borrowerId = form.querySelector('#swal_borrower_id').value;
                    const dueDate = form.querySelector('#swal_due_date').value;
                    if (!borrowerId || !dueDate) {
                         Swal.showValidationMessage('กรุณากรอกข้อมูลให้ครบถ้วน');
                         return false;
                    }
                    return fetch('process/borrow_process.php', { method: 'POST', body: new FormData(form) })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status !== 'success') throw new Error(data.message);
                            return data;
                        })
                        .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('ยืมสำเร็จ!', 'บันทึกข้อมูลการยืมเรียบร้อย', 'success').then(() => location.reload());
                }
            });
        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}

function openAddEquipmentTypePopup() { 
    Swal.fire({
        title: '➕ เพิ่มประเภทอุปกรณ์ใหม่',
        html: `
            <form id="swalAddTypeForm" style="text-align: left; margin-top: 20px;" enctype="multipart/form-data">
                <div style="margin-bottom: 15px;">
                    <label for="swal_type_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อประเภทอุปกรณ์:</label>
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
        confirmButtonText: 'บันทึก',
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
            return fetch('process/add_equipment_type_process.php', { method: 'POST', body: new FormData(form) }) 
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'success') throw new Error(data.message);
                    return data;
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire('เพิ่มสำเร็จ!', 'เพิ่มประเภทอุปกรณ์ใหม่เรียบร้อย', 'success').then(() => location.reload());
        }
    });
}

function openEditTypePopup(typeId) { 
    Swal.fire({ title: 'กำลังโหลดข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    
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
                imagePreviewHtml = `
                    <img src="${type.image_url}?t=${new Date().getTime()}" 
                         alt="รูปตัวอย่าง" 
                         style="width: 100%; height: 150px; object-fit: cover; border-radius: 6px; margin-bottom: 15px;"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                    <div class="equipment-card-image-placeholder" style="display: none; width: 100%; height: 150px; font-size: 3rem; margin-bottom: 15px; justify-content: center; align-items: center; background-color: #f0f0f0; color: #cccccc; border-radius: 6px;"><i class="fas fa-image"></i></div>`;
            }

            Swal.fire({
                title: '🔧 แก้ไขประเภทอุปกรณ์',
                html: `
                <form id="swalEditTypeForm" style="text-align: left; margin-top: 20px;" enctype="multipart/form-data">
                    
                    ${imagePreviewHtml} <input type="hidden" name="type_id" value="${type.id}">
                    
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
                showDenyButton: true, 
                denyButtonText: `<i class="fas fa-trash"></i> ลบประเภทนี้`,
                denyButtonColor: 'var(--color-danger)',

                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-primary, #0B6623)',
                focusConfirm: false,
                preConfirm: () => {
                    const form = document.getElementById('swalEditTypeForm');
                    const name = form.querySelector('#swal_name').value;
                    if (!name) {
                        Swal.showValidationMessage('กรุณากรอกชื่อประเภทอุปกรณ์');
                        return false;
                    }
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
                    Swal.fire('บันทึกสำเร็จ!', 'แก้ไขข้อมูลประเภทอุปกรณ์เรียบร้อย', 'success').then(() => location.reload());
                }
                if (result.isDenied) {
                    confirmDeleteType(typeId, type.name); 
                }
            });
        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}

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
            const formData = new FormData();
            formData.append('id', typeId);

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

function openApproveSelectionModal(transId, currentItemId, equipName) {
    // 1. แสดง Loading Popup ก่อน
    Swal.fire({
        title: 'กำลังโหลดรายการอุปกรณ์...',
        text: 'กรุณารอสักครู่',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    // 2. โหลดรายการ Serial Number ที่ "ว่าง" หรือ "ถูกจองโดยคำขอนี้" จาก AJAX
    // (ใช้ currentItemId เพื่อให้มั่นใจว่าของที่ระบบจองไว้เดิมแสดงในรายการเสมอ)
    fetch(`ajax/get_items_for_approve.php?transaction_id=${transId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') {
                throw new Error(data.message || 'ไม่สามารถโหลดรายการอุปกรณ์ได้');
            }

            let selectOptions = '';
            let defaultId = currentItemId || (data.items.length > 0 ? data.items[0].id : '');
            
            data.items.forEach(item => {
                let label = item.serial_number ? `${item.serial_number} (ID: ${item.id})` : `ID: ${item.id} (ไม่มี Serial)`;
                let isSelected = (item.id == defaultId) ? 'selected' : '';
                selectOptions += `<option value="${item.id}" ${isSelected}>${label}</option>`;
            });

            if (selectOptions === '') {
                 throw new Error('ไม่พบอุปกรณ์ที่พร้อมสำหรับประเภทนี้');
            }

            // 3. ปิด Loading และเปิด Modal เลือกอุปกรณ์ด้วย SweetAlert2
            Swal.close(); 
            
            Swal.fire({
                title: `เลือกอุปกรณ์สำหรับ: ${equipName}`,
                html: `
                    <form id="approveSelectForm" action="process/approve_request_process.php" method="POST" style="text-align: left;">
                        <input type="hidden" name="transaction_id" value="${transId}">
                        <input type="hidden" name="original_item_id" value="${currentItemId}">
                        
                        <div class="swal-info-box" style="margin-bottom: 15px;">
                            <p style="margin: 0;">อุปกรณ์ที่ขอ: <strong>${equipName}</strong></p>
                            <p style="margin: 0;">(ชิ้นที่ระบบจองไว้เดิม: ${currentItemId || 'N/A'})</p>
                        </div>

                        <div class="form-group mb-3">
                            <label for="approve_item_select" style="font-weight: bold; display: block; margin-bottom: 5px;">หมายเลขเครื่อง (Serial Number):</label>
                            <select class="form-select swal2-select" name="selected_item_id" id="approve_item_select" required style="width: 100%; margin: 0;">
                                ${selectOptions}
                            </select>
                            <small class="text-muted">*สามารถเลือกชิ้นอื่นที่ว่างอยู่ได้</small>
                        </div>
                        
                        <div class="form-group mt-4">
                            <label for="scan_barcode_input" style="font-weight: bold; display: block; margin-bottom: 5px;">สแกนบาร์โค้ด (ถ้ามี):</label>
                            <input type="text" class="form-control swal2-input" id="scan_barcode_input" placeholder="ยิงบาร์โค้ด Item ID หรือ Serial..." autocomplete="off" style="margin: 0;">
                            <small class="text-muted">*รหัสที่สแกนจะถูกเลือกใน Dropdown ด้านบน</small>
                        </div>
                    </form>`,
                width: '500px',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check-circle"></i> ยืนยันอนุมัติ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-success)',
               preConfirm: () => {
                    const selectedId = document.getElementById('approve_item_select').value;
                    if (!selectedId) {
                         Swal.showValidationMessage('กรุณาเลือกอุปกรณ์');
                         return false;
                    }
                    
                    const form = document.getElementById('approveSelectForm');
                    
                    // ✅ แก้ไข: คาดหวัง JSON response
                    return fetch(form.action, { method: 'POST', body: new FormData(form) })
                        .then(response => response.json()) // <--- เปลี่ยนเป็น .json()
                        .then(data => {
                            if (data.status !== 'success') {
                                // ถ้าสถานะไม่ใช่ success ให้ถือเป็น Error และแสดงข้อความจาก PHP
                                throw new Error(data.message);
                            }
                            return data; // ส่งต่อข้อมูลสำเร็จ
                        })
                        .catch(error => {
                            // แสดง Error จาก PHP
                            Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
                            return false; // สำคัญ: ต้อง return false เพื่อป้องกัน Modal ปิด
                        });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // result.value คือ object { status: 'success', message: '...' } จาก PHP
                    Swal.fire('อนุมัติสำเร็จ!', result.value.message, 'success')
                    // ✅ เพิ่ม .then() เพื่อสั่งรีโหลดหน้าจอเมื่อ Pop-up ปิด
                    .then(() => location.reload()); 
                }
            });
            
            // 4. เพิ่ม Logic สำหรับการสแกน Barcode (หลังเปิด Modal)
            const scanInput = document.getElementById('scan_barcode_input');
            if (scanInput) {
                scanInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        // รหัสที่สแกนอาจเป็น EQ-ID หรือ ID ธรรมดา
                        const barcodeValue = this.value.trim().replace('EQ-', '');
                        const select = document.getElementById('approve_item_select');
                        let found = false;
                        for (let i = 0; i < select.options.length; i++) {
                            // เช็คจาก value (ซึ่งเป็น Item ID)
                            if (select.options[i].value == barcodeValue) {
                                select.selectedIndex = i; // เลือก Option นั้น
                                found = true;
                                break;
                            }
                        }
                        if (found) {
                            Swal.showValidationMessage(`เลือก Serial: ${select.options[select.selectedIndex].text}`);
                            this.value = '';
                            document.querySelector('.swal2-confirm').focus(); // โฟกัสปุ่มยืนยัน
                        } else {
                            Swal.showValidationMessage('ไม่พบหมายเลขเครื่องนี้ในรายการที่เลือกได้');
                            this.value = '';
                        }
                    }
                });
            }

        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}

function openAddItemPopup(typeId, typeName) {
    Swal.fire({
        title: `➕ เพิ่มชิ้นอุปกรณ์ใหม่`,
        html: `
            <p style="text-align: left;">กำลังเพิ่มอุปกรณ์เข้าไปในประเภท: <strong>${typeName}</strong></p>
            <form id="swalAddItemForm" style="text-align: left; margin-top: 20px;">
                <input type="hidden" name="type_id" value="${typeId}">
                <div style="margin-bottom: 15px;">
                    <label for="swal_item_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อเฉพาะ (ถ้ามี):</label>
                    <input type="text" name="name" id="swal_item_name" value="${typeName}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    <small>ปกติจะใช้ชื่อเดียวกับประเภท แต่สามารถตั้งชื่อเฉพาะได้ เช่น 'รถเข็น A-01'</small>
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_item_serial" style="font-weight: bold; display: block; margin-bottom: 5px;">เลขซีเรียล (Serial Number):</label>
                    <input type="text" name="serial_number" id="swal_item_serial" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_item_desc" style="font-weight: bold; display: block; margin-bottom: 5px;">รายละเอียด/หมายเหตุ:</label>
                    <textarea name="description" id="swal_item_desc" rows="2" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;"></textarea>
                </div>
            </form>`,
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        preConfirm: () => {
            const form = document.getElementById('swalAddItemForm');
            if (!form.checkValidity()) {
                Swal.showValidationMessage('กรุณากรอกข้อมูลให้ครบถ้วน');
                return false;
            }
            return fetch('process/add_item_process.php', { method: 'POST', body: new FormData(form) })
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'success') throw new Error(data.message);
                    return data;
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire('เพิ่มสำเร็จ!', 'เพิ่มอุปกรณ์ชิ้นใหม่เรียบร้อย', 'success').then(() => {
                Swal.close();
                openManageItemsPopup(typeId); 
            });
        }
    });
}

function openEditItemPopup(itemId) {
    Swal.fire({ title: 'กำลังโหลดข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

    fetch(`ajax/get_item_data.php?id=${itemId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') throw new Error(data.message);
            const item = data.item;

            const formHtml = `
                <form id="swalEditItemForm" style="text-align: left; margin-top: 20px;">
                    <input type="hidden" name="item_id" value="${item.id}">
                    <div class="swal-info-box">
                        <p style="margin: 0;"><strong>สถานะปัจจุบัน:</strong> <span style="color: ${item.status === 'borrowed' ? 'var(--color-danger)' : 'var(--color-primary)'};">${item.status}</span></p>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_item_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อเฉพาะ:</label>
                        <input type="text" name="name" id="swal_item_name" value="${item.name}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_item_serial" style="font-weight: bold; display: block; margin-bottom: 5px;">เลขซีเรียล (S/N):</label>
                        <input type="text" name="serial_number" id="swal_item_serial" value="${item.serial_number || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        <small style="color: #6c757d;">(กรอกเมื่อต้องการเปลี่ยนหรือเพิ่ม)</small>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_item_desc" style="font-weight: bold; display: block; margin-bottom: 5px;">รายละเอียด/หมายเหตุ:</label>
                        <textarea name="description" id="swal_item_desc" rows="2" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">${item.description || ''}</textarea>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_new_status" style="font-weight: bold; display: block; margin-bottom: 5px;">เปลี่ยนสถานะเป็น: <span style="color:red;">*</span></label>
                        <select name="status" id="swal_new_status" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                            <option value="available" ${item.status === 'available' ? 'selected' : ''}>พร้อมใช้งาน (Available)</option>
                            <option value="maintenance" ${item.status === 'maintenance' ? 'selected' : ''}>ซ่อมบำรุง (Maintenance)</option>
                            <option value="borrowed" ${item.status === 'borrowed' ? 'selected' : ''} disabled>ถูกยืม (Borrowed - แก้ไม่ได้)</option>
                        </select>
                    </div>
                </form>`;

            Swal.fire({
                title: `🔧 แก้ไขอุปกรณ์ชิ้นที่: ${item.id}`,
                html: formHtml,
                showCancelButton: true,
                confirmButtonText: 'บันทึกการเปลี่ยนแปลง',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-primary, #0B6623)',
                focusConfirm: false,
                preConfirm: () => {
                    const form = document.getElementById('swalEditItemForm');
                    if (form.querySelector('#swal_new_status').value === 'borrowed') {
                        Swal.showValidationMessage('ไม่สามารถเปลี่ยนเป็นสถานะ "ถูกยืม" จากหน้านี้ได้');
                        return false;
                    }
                    if (!form.checkValidity()) {
                        Swal.showValidationMessage('กรุณากรอกข้อมูลให้ครบถ้วน');
                        return false;
                    }
                    return fetch('process/edit_item_process.php', { method: 'POST', body: new FormData(form) })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status !== 'success') throw new Error(data.message);
                            return data;
                        })
                        .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('บันทึกสำเร็จ!', 'แก้ไขอุปกรณ์ชิ้นใหม่เรียบร้อย', 'success').then(() => {
                        Swal.close();
                        openManageItemsPopup(item.type_id);
                    });
                }
            });
        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}

function confirmDeleteItem(itemId, typeId, forceDelete = false) {
    const title   = forceDelete ? "ยืนยันการลบพร้อมประวัติ?" : "คุณแน่ใจหรือไม่?";
    const text    = forceDelete
        ? "การดำเนินการนี้จะลบอุปกรณ์และประวัติการยืมทั้งหมดอย่างถาวร ไม่สามารถกู้คืนได้!"
        : "คุณกำลังจะลบอุปกรณ์ชิ้นนี้ (ID: " + itemId + ") ออกจากระบบอย่างถาวร";
    const confirmText = forceDelete ? "ลบทั้งหมดเลย" : "ใช่, ลบเลย";

    Swal.fire({
        title: title,
        text: text,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: confirmText,
        cancelButtonText: "ยกเลิก"
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('item_id', itemId);
            formData.append('type_id', typeId);
            if (forceDelete) formData.append('force_delete', '1');

            fetch('process/delete_item_process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire('ลบสำเร็จ!', data.message, 'success').then(() => {
                        Swal.close();
                        openManageItemsPopup(typeId);
                    });
                } else if (data.status === 'has_history') {
                    // มีประวัติการยืม — ถามว่าจะ force delete ไหม
                    Swal.fire({
                        title: "พบประวัติการยืม!",
                        text: data.message,
                        icon: "warning",
                        showCancelButton: true,
                        confirmButtonColor: "#d33",
                        cancelButtonColor: "#6c757d",
                        confirmButtonText: "ลบทั้งหมด (รวมประวัติ)",
                        cancelButtonText: "ยกเลิก"
                    }).then((forceResult) => {
                        if (forceResult.isConfirmed) {
                            confirmDeleteItem(itemId, typeId, true);
                        }
                    });
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

// =========================================
// ✅ 3. ฟังก์ชันสำหรับ "รับคืน" และ "อนุมัติ/ปฏิเสธ" คำขอ
// =========================================

function openReturnPopup(equipmentId) {
    Swal.fire({ title: 'กำลังโหลดข้อมูล...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    
    fetch(`ajax/get_return_form_data.php?id=${equipmentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') throw new Error(data.message);
            const t = data.transaction;
            
            const formHtml = `
                <form id="swalReturnForm" style="text-align: left; margin-top: 20px;">
                    <input type="hidden" name="equipment_id" value="${equipmentId}">
                    <input type="hidden" name="transaction_id" value="${t.transaction_id}">
                    
                    <div class="swal-info-box">
                        <p><strong>อุปกรณ์:</strong> ${t.equipment_name} (${t.equipment_serial || 'N/A'})</p>
                        <p><strong>ผู้ยืม:</strong> ${t.borrower_name || '[ผู้ใช้ถูกลบ]'} (${t.borrower_contact || 'N/A'})</p>
                        <p style="margin-top: 10px;">
                            <strong>วันที่ยืม:</strong> ${new Date(t.borrow_date).toLocaleDateString()}
                        </p>
                        <p style="color: ${new Date(t.due_date) < new Date() ? 'var(--color-danger)' : 'var(--color-text-normal)'};">
                            <strong>กำหนดคืน:</strong> ${new Date(t.due_date).toLocaleDateString()}
                        </p>
                    </div>
                    
                    <div style="margin-top: 20px; text-align: center;">
                        <p style="font-weight: bold; font-size: 1.1em;">คุณแน่ใจว่าได้รับอุปกรณ์คืนแล้ว?</p>
                    </div>
                </form>`;

            Swal.fire({
                title: '✅ ยืนยันการรับคืนอุปกรณ์',
                html: formHtml,
                showCancelButton: true,
                confirmButtonText: 'ใช่, รับคืน',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-success)',
                focusConfirm: false,
                preConfirm: () => {
                    const form = document.getElementById('swalReturnForm');
                    return fetch('process/return_process.php', { method: 'POST', body: new FormData(form) })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status !== 'success') throw new Error(data.message);
                            return data;
                        })
                        .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('รับคืนสำเร็จ!', 'บันทึกการคืนอุปกรณ์เรียบร้อย', 'success').then(() => location.reload());
                }
            });
        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}

function openApprovePopup(transactionId) {
    Swal.fire({
        title: "ยืนยันการอนุมัติคำขอ?",
        text: "คุณแน่ใจที่จะอนุมัติคำขอนี้ใช่หรือไม่? (สถานะจะเปลี่ยนเป็น 'อนุมัติแล้ว')",
        icon: "question",
        showCancelButton: true,
        confirmButtonColor: 'var(--color-success)',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'ใช่, อนุมัติ',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('transaction_id', transactionId);

            fetch('process/approve_request_process.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('อนุมัติสำเร็จ!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                    }
                })
                .catch(error => { Swal.fire('เกิดข้อผิดพลาด AJAX', error.message, 'error'); });
        }
    });
}

function openRejectPopup(transactionId) {
    Swal.fire({
        title: "ยืนยันการปฏิเสธคำขอ?",
        text: "คุณแน่ใจที่จะปฏิเสธคำขอนี้ใช่หรือไม่? (อุปกรณ์จะถูกคืนเข้าสต็อกทันที)",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: 'var(--color-danger)',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'ใช่, ปฏิเสธ',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('transaction_id', transactionId);

            fetch('process/reject_request_process.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('ปฏิเสธสำเร็จ!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                    }
                })
                .catch(error => { Swal.fire('เกิดข้อผิดพลาด AJAX', error.message, 'error'); });
        }
    });
}

function showReasonPopup(reason) {
    Swal.fire({
        title: 'เหตุผลการยืม',
        html: `<p style="white-space: pre-wrap; text-align: left; background: #f4f4f4; padding: 15px; border-radius: 8px;">${reason}</p>`,
        confirmButtonText: 'ปิด'
    });
}

// =========================================
// ✅ 4. ฟังก์ชันสำหรับ "จัดการผู้ใช้" (Manage Students/Staff)
// =========================================

function openAddStudentPopup() {
    Swal.fire({
        title: '➕ เพิ่มผู้ใช้งาน (โดย Admin)',
        html: `
            <form id="swalAddStudentForm" style="text-align: left; margin-top: 20px;">
                <p>ผู้ใช้งานที่เพิ่มโดย Admin จะไม่มี LINE ID เชื่อมโยง</p>
                <div style="margin-bottom: 15px;">
                    <label for="swal_full_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อ-สกุล: <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="swal_full_name" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_phone_number" style="font-weight: bold; display: block; margin-bottom: 5px;">เบอร์โทร:</label>
                    <input type="text" name="phone_number" id="swal_phone_number" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
            </form>`,
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: 'var(--color-success, #28a745)',
        focusConfirm: false,
        preConfirm: () => {
            const form = document.getElementById('swalAddStudentForm');
            const fullName = form.querySelector('#swal_full_name').value;
            if (!fullName) {
                Swal.showValidationMessage('กรุณากรอก ชื่อ-สกุล ผู้ใช้งาน');
                return false;
            }
            return fetch('process/add_student_process.php', { method: 'POST', body: new FormData(form) })
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'success') throw new Error(data.message);
                    return data;
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire('บันทึกสำเร็จ!', 'เพิ่มผู้ใช้งานใหม่เรียบร้อยแล้ว', 'success').then(() => location.reload());
        }
    });
}

function openAddStaffPopup() {
    Swal.fire({
        title: '➕ เพิ่มบัญชีพนักงานใหม่',
        html: `
            <p style="text-align: left;">บัญชีนี้จะใช้สำหรับ Login ในหน้า Admin/Employee (จะไม่ถูกผูกกับ LINE)</p>
            <form id="swalAddStaffForm" style="text-align: left; margin-top: 20px;">
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_username" style="font-weight: bold; display: block; margin-bottom: 5px;">Username: <span style="color:red;">*</span></label>
                    <input type="text" name="username" id="swal_s_username" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_password" style="font-weight: bold; display: block; margin-bottom: 5px;">Password: <span style="color:red;">*</span></label>
                    <input type="text" name="password" id="swal_s_password" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_fullname" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อ-สกุล: <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="swal_s_fullname" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_s_role" style="font-weight: bold; display: block; margin-bottom: 5px;">สิทธิ์ (Role): <span style="color:red;">*</span></label>
                    <select name="role" id="swal_s_role" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        <option value="employee">พนักงาน (Employee)</option>
                        <option value="editor">พนักงาน (Editor - จัดการอุปกรณ์)</option>
                        <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                    </select>
                </div>
            </form>`,
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: 'var(--color-success, #28a745)',
        focusConfirm: false,
        preConfirm: () => {
            const form = document.getElementById('swalAddStaffForm');
            if (!form.checkValidity()) {
                Swal.showValidationMessage('กรุณากรอกข้อมูล * ให้ครบถ้วน');
                return false;
            }
            return fetch('process/add_staff_process.php', { method: 'POST', body: new FormData(form) })
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'success') throw new Error(data.message);
                    return data;
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire('บันทึกสำเร็จ!', 'เพิ่มบัญชีพนักงานใหม่เรียบร้อย', 'success').then(() => location.reload());
        }
    });
}

function checkOtherStatusPopup(value) {
    var otherGroup = document.getElementById('other_status_group_popup');
    var otherInput = document.getElementById('swal_edit_status_other');
    if (value === 'other') {
        otherGroup.style.display = 'block';
        otherInput.required = true;
    } else {
        otherGroup.style.display = 'none';
        otherInput.required = false;
    }
}

function openEditStudentPopup(studentId) {
    Swal.fire({
        title: 'กำลังโหลดข้อมูล...',
        didOpen: () => { Swal.showLoading(); }
    });
    
    fetch(`ajax/get_student_data.php?id=${studentId}`) 
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') throw new Error(data.message);
            const student = data.student;

            const otherStatusDisplay = (student.status === 'other') ? 'block' : 'none';

            const formHtml = `
            <form id="swalEditStudentForm" style="text-align: left; margin-top: 20px;">
                <input type="hidden" name="student_id" value="${student.id}">
                <div style="margin-bottom: 15px;">
                    <label for="swal_edit_full_name" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อ-สกุล: <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="swal_edit_full_name" value="${student.full_name}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_edit_department" style="font-weight: bold; display: block; margin-bottom: 5px;">คณะ/หน่วยงาน:</label>
                    <input type="text" name="department" id="swal_edit_department" value="${student.department || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_edit_status" style="font-weight: bold; display: block; margin-bottom: 5px;">สถานภาพ: <span style="color:red;">*</span></label>
                    <select name="status" id="swal_edit_status" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;" onchange="checkOtherStatusPopup(this.value)">
                        <option value="student" ${student.status === 'student' ? 'selected' : ''}>นักศึกษา</option>
                        <option value="teacher" ${student.status === 'teacher' ? 'selected' : ''}>อาจารย์</option>
                        <option value="staff" ${student.status === 'staff' ? 'selected' : ''}>เจ้าหน้าที่</option>
                        <option value="other" ${student.status === 'other' ? 'selected' : ''}>อื่นๆ</option>
                    </select>
                </div>
                <div class="form-group" id="other_status_group_popup" style="display: ${otherStatusDisplay}; margin-bottom: 15px;">
                    <label for="swal_edit_status_other" style="font-weight: bold; display: block; margin-bottom: 5px;">โปรดระบุ "อื่นๆ":</label>
                    <input type="text" name="status_other" id="swal_edit_status_other" value="${student.status_other || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_edit_student_id" style="font-weight: bold; display: block; margin-bottom: 5px;">รหัสผู้ใช้งาน/บุคลากร:</label>
                    <input type="text" name="student_personnel_id" id="swal_edit_student_id" value="${student.student_personnel_id || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_edit_phone_number" style="font-weight: bold; display: block; margin-bottom: 5px;">เบอร์โทร:</label>
                    <input type="text" name="phone_number" id="swal_edit_phone_number" value="${student.phone_number || ''}" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
            </form>`;

            Swal.fire({
                title: '🔧 แก้ไขข้อมูลผู้ใช้งาน',
                html: formHtml,
                showCancelButton: true,
                confirmButtonText: 'บันทึกการเปลี่ยนแปลง',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-primary, #0B6623)',
                focusConfirm: false,
                preConfirm: () => {
                    const form = document.getElementById('swalEditStudentForm');
                    const fullName = form.querySelector('#swal_edit_full_name').value;
                    const status = form.querySelector('#swal_edit_status').value;
                    const statusOther = form.querySelector('#swal_edit_status_other').value;

                    if (!fullName || !status) {
                        Swal.showValidationMessage('กรุณากรอกช่องที่มีเครื่องหมาย * ให้ครบถ้วน');
                        return false;
                    }
                    if (status === 'other' && !statusOther) {
                        Swal.showValidationMessage('กรุณาระบุสถานภาพ "อื่นๆ"');
                        return false;
                    }
                    return fetch('process/edit_student_process.php', { method: 'POST', body: new FormData(form) })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status !== 'success') throw new Error(data.message);
                            return data;
                        })
                        .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('บันทึกสำเร็จ!', 'แก้ไขข้อมูลผู้ใช้งานเรียบร้อย', 'success').then(() => location.reload());
                }
            });
        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}

function openEditStaffPopup(userId) {
    Swal.fire({
        title: 'กำลังโหลดข้อมูล...',
        didOpen: () => { Swal.showLoading(); }
    });

    fetch(`ajax/get_staff_data.php?id=${userId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') throw new Error(data.message);
            const staff = data.staff;
            const is_linked = staff.linked_line_user_id ? true : false;
            const disabled_attr = is_linked ? 'disabled' : '';
            const linked_warning = is_linked ? '<p style="color: var(--color-success); text-align: left;">(บัญชีนี้ผูกกับ LINE จึงไม่สามารถแก้ไขชื่อและสิทธิ์ได้จากหน้านี้)</p>' : '';

            const formHtml = `
            <form id="swalEditStaffForm" style="text-align: left; margin-top: 20px;">
                <input type="hidden" name="user_id" value="${staff.id}">
                ${linked_warning}
                <div style="margin-bottom: 15px;">
                    <label for="swal_e_username" style="font-weight: bold; display: block; margin-bottom: 5px;">Username: <span style="color:red;">*</span></label>
                    <input type="text" name="username" id="swal_e_username" value="${staff.username}" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_e_fullname" style="font-weight: bold; display: block; margin-bottom: 5px;">ชื่อ-สกุล: <span style="color:red;">*</span></label>
                    <input type="text" name="full_name" id="swal_e_fullname" value="${staff.full_name}" required ${disabled_attr} style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd; background-color: ${is_linked ? 'var(--border-color)' : 'var(--color-content-bg)'};">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_e_role" style="font-weight: bold; display: block; margin-bottom: 5px;">สิทธิ์ (Role): <span style="color:red;">*</span></label>
                    <select name="role" id="swal_e_role" required ${disabled_attr} style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd; background-color: ${is_linked ? 'var(--border-color)' : 'var(--color-content-bg)'};">
                        <option value="employee" ${staff.role == 'employee' ? 'selected' : ''}>พนักงาน (Employee)</option>
                        <option value="editor" ${staff.role == 'editor' ? 'selected' : ''}>พนักงาน (Editor - จัดการอุปกรณ์)</option>
                        <option value="admin" ${staff.role == 'admin' ? 'selected' : ''}>ผู้ดูแลระบบ (Admin)</option>
                    </select>
                </div>
                <hr style="margin: 20px 0;">
                <div style="margin-bottom: 15px;">
                    <label for="swal_e_password" style="font-weight: bold; display: block; margin-bottom: 5px;">Reset รหัสผ่าน (กรอกเฉพาะเมื่อต้องการเปลี่ยน):</label>
                    <input type="text" name="new_password" id="swal_e_password" placeholder="กรอกรหัสผ่านใหม่" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
            </form>`;

            Swal.fire({
                title: '🔧 แก้ไขบัญชีพนักงาน',
                html: formHtml,
                showCancelButton: true,
                confirmButtonText: 'บันทึกการเปลี่ยนแปลง',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-primary, #0B6623)',
                focusConfirm: false,
                preConfirm: () => {
                    const form = document.getElementById('swalEditStaffForm');
                    if (!form.checkValidity()) {
                        Swal.showValidationMessage('กรุณากรอกข้อมูล * ให้ครบถ้วน');
                        return false;
                    }
                    return fetch('process/edit_staff_process.php', { method: 'POST', body: new FormData(form) })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status !== 'success') throw new Error(data.message);
                            return data;
                        })
                        .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('บันทึกสำเร็จ!', 'แก้ไขข้อมูลบัญชีเรียบร้อย', 'success').then(() => location.reload());
                }
            });
        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}

function openPromotePopup(studentId, studentName, lineId) {
    Swal.fire({
        title: 'เลื่อนขั้นผู้ใช้งาน',
        html: `
            <p style="text-align: left;">คุณกำลังจะเลื่อนขั้น <strong>${studentName}</strong> (ที่มี LINE ID) ให้เป็น "พนักงาน"</p>
            <p style="text-align: left;">กรุณาสร้างบัญชีสำหรับ Login (เผื่อกรณีที่ไม่ได้เข้าผ่าน LINE):</p>
            <form id="swalPromoteForm" style="text-align: left; margin-top: 20px;">
                <input type="hidden" name="student_id_to_promote" value="${studentId}">
                <input type="hidden" name="line_user_id_to_link" value="${lineId}">
                <div style="margin-bottom: 15px;">
                    <label for="swal_username" style="font-weight: bold; display: block; margin-bottom: 5px;">1. Username (สำหรับ Login): <span style="color:red;">*</span></label>
                    <input type="text" name="new_username" id="swal_username" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_password" style="font-weight: bold; display: block; margin-bottom: 5px;">2. Password (ชั่วคราว): <span style="color:red;">*</span></label>
                    <input type="text" name="new_password" id="swal_password" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label for="swal_role" style="font-weight: bold; display: block; margin-bottom: 5px;">3. สิทธิ์ (Role): <span style="color:red;">*</span></label>
                    <select name="new_role" id="swal_role" required style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                        <option value="employee">พนักงาน (Employee)</option>
                        <option value="editor">พนักงาน (Editor - จัดการอุปกรณ์)</option>
                        <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                    </select>
                </div>
            </form>`,
        showCancelButton: true,
        confirmButtonText: 'ยืนยันการเลื่อนขั้น',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: 'var(--color-warning, #ffc107)',
        focusConfirm: false,
        preConfirm: () => {
            const form = document.getElementById('swalPromoteForm');
            const username = form.querySelector('#swal_username').value;
            const password = form.querySelector('#swal_password').value;
            if (!username || !password) {
                Swal.showValidationMessage('กรุณากรอก Username และ Password');
                return false;
            }
            return fetch('process/promote_student_process.php', { method: 'POST', body: new FormData(form) })
                .then(response => response.json())
                .then(data => {
                    if (data.status !== 'success') throw new Error(data.message);
                    return data;
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`); });
        }
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire('เลื่อนขั้นสำเร็จ!', 'ผู้ใช้งานนี้กลายเป็นพนักงานแล้ว', 'success').then(() => location.reload());
        }
    });
}

function confirmDemote(userId, staffName) {
    Swal.fire({
        title: `คุณแน่ใจหรือไม่?`,
        text: `คุณกำลังจะลดสิทธิ์ ${staffName} กลับไปเป็น "ผู้ใช้งาน" บัญชีพนักงานจะถูกลบ (แต่ยัง Login LINE ได้)`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "ใช่, ลดสิทธิ์เลย",
        cancelButtonText: "ยกเลิก"
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('user_id_to_demote', userId);
            fetch('process/demote_staff_process.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('ลดสิทธิ์สำเร็จ!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                    }
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด AJAX`); });
        }
    });
}

function confirmDeleteStaff(userId, staffName) {
    Swal.fire({
        title: `คุณแน่ใจหรือไม่?`,
        text: `คุณกำลังจะลบบัญชีพนักงาน [${staffName}] ออกจากระบบอย่างถาวร (จะลบได้ต่อเมื่อไม่มีประวัติการทำรายการค้างอยู่)`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "ใช่, ลบบัญชี",
        cancelButtonText: "ยกเลิก"
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('user_id_to_delete', userId);
            fetch('process/delete_staff_process.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('ลบสำเร็จ!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                    }
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด AJAX`); });
        }
    });
}

function confirmToggleStaffStatus(userId, staffName, newStatus) {
    const actionText = (newStatus === 'disabled') ? 'ระงับบัญชี' : 'เปิดใช้งาน';
    const actionIcon = (newStatus === 'disabled') ? 'warning' : 'info';
    const actionConfirmColor = (newStatus === 'disabled') ? '#dc3545' : '#17a2b8';

    Swal.fire({
        title: `ยืนยันการ${actionText}?`,
        text: `คุณกำลังจะ${actionText}บัญชีของ ${staffName}`,
        icon: actionIcon,
        showCancelButton: true,
        confirmButtonColor: actionConfirmColor,
        cancelButtonColor: "#3085d6",
        confirmButtonText: `ใช่, ${actionText}`,
        cancelButtonText: "ยกเลิก"
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('new_status', newStatus);
            fetch('process/toggle_staff_status.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        Swal.fire('สำเร็จ!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                    }
                })
                .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด AJAX`); });
        }
    });
}

function confirmDeleteStudent(event, id) {
    event.preventDefault();
    Swal.fire({
        title: "คุณแน่ใจหรือไม่?",
        text: "คุณกำลังจะลบผู้ใช้งานนี้ (เฉพาะที่ Admin เพิ่มเอง)",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#3085d6",
        confirmButtonText: "ใช่, ลบเลย",
        cancelButtonText: "ยกเลิก"
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('id', id);
            fetch('process/delete_student_process.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire('ลบสำเร็จ!', 'ผู้ใช้งานถูกลบเรียบร้อยแล้ว', 'success').then(() => location.reload());
                } else {
                    Swal.fire('เกิดข้อผิดพลาด!', data.message, 'error');
                }
            })
            .catch(error => { Swal.showValidationMessage(`เกิดข้อผิดพลาด AJAX`); });
        }
    });
}

// =========================================
// ✅ 5. ฟังก์ชันสำหรับ Barcode Printing (Bulk)
// =========================================

// [assets/js/admin_app.js]

// 1. แก้ไขฟังก์ชัน openBulkBarcodeForm (เพิ่มปุ่ม Deny เป็นปุ่ม Download ZIP)
function openBulkBarcodeForm() {
    let options = '<option value="">-- เลือกประเภทอุปกรณ์ --</option>';
    if (typeof equipmentTypesData !== 'undefined') {
        equipmentTypesData.forEach(type => {
            options += `<option value="${type.id}" data-name="${type.name}" data-max="${type.total_quantity}">${type.name} (ทั้งหมด: ${type.total_quantity})</option>`;
        });
    }

    Swal.fire({
        title: '🖨️ พิมพ์/ดาวน์โหลด บาร์โค้ด',
        html: `
            <div style="text-align: left;">
                <div class="swal-section-box">
                    <label style="font-weight: bold;">1. เลือกประเภทอุปกรณ์:</label>
                    <div style="display: flex; gap: 10px; margin-top: 5px;">
                        <select id="bulk_type_id" class="swal2-select" style="margin: 0; flex: 1;">
                            ${options}
                        </select>
                        <button type="button" class="btn btn-primary" onclick="addTypeToCart()" style="width: 80px;">เพิ่ม</button>
                    </div>
                </div>

                <div style="border-top: 1px solid var(--border-color); padding-top: 15px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 10px;">2. รายการที่จะทำ:</label>
                    <div id="cart-display" class="swal-cart-display">
                        ${renderCartHtml()}
                    </div>
                </div>
            </div>
        `,
        width: '650px',
        showCancelButton: true,
        showDenyButton: true, // เปิดปุ่มที่ 3
        confirmButtonText: '<i class="fas fa-print"></i> พิมพ์หน้าเว็บ',
        denyButtonText: '<i class="fas fa-file-archive"></i> ดาวน์โหลด (PNG)', // ปุ่ม ZIP
        cancelButtonText: 'ปิด',
        confirmButtonColor: 'var(--color-info)',
        denyButtonColor: 'var(--color-success)', // สีเขียว
        
        preConfirm: () => validateCart(), // เช็คความถูกต้อง (พิมพ์)
        preDeny: () => { // เช็คความถูกต้อง (ZIP) และทำงาน
            const items = validateCart();
            if(!items) return false;
            
            // เรียกฟังก์ชันดาวน์โหลด ZIP และหยุดการปิด Popup ชั่วคราว
            handleZipDownload(items); 
            return false; // return false เพื่อไม่ให้ popup ปิดทันที
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // โหมดพิมพ์ (เปิดหน้าใหม่)
            const cartData = encodeURIComponent(JSON.stringify(result.value));
            window.open(`admin/print_barcode_bulk.php?data=${cartData}`, '_blank');
        }
    });
}

// Helper: ตรวจสอบตะกร้า
function validateCart() {
    if (printCart.length === 0) {
        Swal.showValidationMessage('กรุณาเพิ่มรายการอย่างน้อย 1 รายการ');
        return false;
    }
    const validItems = printCart.filter(i => i.selected_ids.length > 0);
    if (validItems.length === 0) {
        Swal.showValidationMessage('กรุณากดปุ่ม "เลือกระบุชิ้น" และเลือกอุปกรณ์อย่างน้อย 1 ชิ้น');
        return false;
    }
    return validItems;
}

// 2. ฟังก์ชันสร้าง PDF และ ZIP (ทำงานเบื้องหลัง)
function handleZipDownload(cartItems) {
    Swal.fire({
        title: 'กำลังสร้างไฟล์ ZIP...',
        html: 'กำลังสร้างรูปภาพ PNG สำหรับอุปกรณ์แต่ละชิ้น',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    // 1. รวบรวม ID ทั้งหมด
    let allIds = [];
    cartItems.forEach(item => {
        allIds = allIds.concat(item.selected_ids);
    });

    // 2. ดึงข้อมูลรายละเอียด
    const formData = new FormData();
    formData.append('ids', JSON.stringify(allIds));

    fetch('ajax/get_bulk_item_details.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(async data => {
            if(data.status !== 'success') throw new Error(data.message);

            const items = data.items;
            const zip = new JSZip(); // สร้าง Object ZIP
            const folder = zip.folder("barcodes_png"); // เปลี่ยนชื่อโฟลเดอร์เป็น barcodes_png
            
            // 3. วนลูปสร้าง PNG ทีละชิ้น
            for (let item of items) {
                const pngBlob = await createSingleBarcodePNG(item); // เรียกฟังก์ชันสร้าง PNG
                
                // ตั้งชื่อไฟล์: barcode_101_ชื่ออุปกรณ์.png
                const cleanName = item.name.replace(/[\/\\:*?"<>|]/g, "_").substring(0, 20); 
                const fileName = `barcode_${item.id}_${cleanName}.png`; // นามสกุล .png
                
                folder.file(fileName, pngBlob);
            }

            // 4. บีบอัดและดาวน์โหลด
            zip.generateAsync({type:"blob"}).then(function(content) {
                saveAs(content, "barcodes_images.zip"); // ชื่อไฟล์ ZIP ใหม่
                Swal.fire('เรียบร้อย', 'ดาวน์โหลดไฟล์ ZIP (PNG) สำเร็จ', 'success');
            });

        })
        .catch(err => {
            console.error(err);
            Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถสร้างไฟล์ ZIP ได้: ' + err.message, 'error');
        });
}

// Helper: สร้าง PNG 1 รูป (ใช้ Canvas วาดแล้วแปลงเป็น Blob)
function createSingleBarcodePNG(item) {
    return new Promise((resolve) => {
        // สร้าง Canvas
        const canvas = document.createElement("canvas");
        canvas.width = 400;
        canvas.height = 200;
        const ctx = canvas.getContext("2d");

        // 1. ถมพื้นหลังสีขาว (สำคัญมาก ไม่งั้นพื้นหลังจะโปร่งใส)
        ctx.fillStyle = "#FFFFFF";
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // 2. วาดกรอบเส้นขอบ (Optional: ถ้าอยากให้มีกรอบ)
        // ctx.strokeStyle = "#ddd";
        // ctx.lineWidth = 1;
        // ctx.strokeRect(0, 0, canvas.width, canvas.height);

        // 3. วาดชื่ออุปกรณ์
        ctx.fillStyle = "#000000";
        ctx.font = "bold 22px sans-serif";
        ctx.textAlign = "center";
        ctx.fillText(item.name, 200, 40);

        // 4. วาด Serial Number (ถ้ามี)
        if(item.serial_number) {
            ctx.font = "16px sans-serif";
            ctx.fillStyle = "#555555";
            ctx.fillText(`S/N: ${item.serial_number}`, 200, 65);
        }

        // 5. วาด Barcode
        const barcodeCanvas = document.createElement("canvas");
        try {
            JsBarcode(barcodeCanvas, "EQ-" + item.id, {
                format: "CODE128",
                displayValue: true,
                fontSize: 18,
                margin: 0,
                width: 2,
                height: 70
            });
            // นำภาพ Barcode มาแปะลง Canvas หลัก (จัดกึ่งกลาง)
            ctx.drawImage(barcodeCanvas, (400 - barcodeCanvas.width) / 2, 85);
        } catch (e) {
            console.error("Barcode Error", e);
        }

        // 6. แปลง Canvas เป็น Blob (ไฟล์รูปภาพ)
        canvas.toBlob((blob) => {
            resolve(blob);
        }, 'image/png');
    });
}

// assets/js/admin_app.js (ส่วนท้ายไฟล์ - เพิ่มฟังก์ชันเข้าสู่ Global Scope)

// =========================================
// ✅ 6. ฟังก์ชัน Barcode & History (Global Scope)
// =========================================

function openItemBarcodePopup(itemId, itemName, serialNumber) {
    // 1. เตรียมค่า Barcode
    const barcodeValue = "EQ-" + itemId; 
    const serialText = serialNumber && serialNumber !== '-' ? `(S/N: ${serialNumber})` : '';

    // 2. แสดง Popup
    Swal.fire({
        title: '🏷️ บาร์โค้ดอุปกรณ์',
        html: `
            <div style="margin-bottom: 10px;">
                <strong>${itemName}</strong> ${serialText}
            </div>
            <div style="background: white; padding: 20px; border-radius: 8px; display: inline-block; border: 1px solid #eee;">
                <canvas id="barcode-canvas-${itemId}"></canvas>
            </div>
            <p style="margin-top: 15px; font-size: 0.9em; color: var(--color-text-muted);">
                รหัส Item ID: <strong>${itemId}</strong>
            </p>
        `,
        didOpen: () => {
            try {
                // วาดบาร์โค้ดลงบน Canvas ใน Popup (เพื่อแสดงผล)
                JsBarcode(`#barcode-canvas-${itemId}`, barcodeValue, {
                    format: "CODE128",
                    lineColor: "#000",
                    width: 2,
                    height: 80,
                    displayValue: true,
                    fontSize: 18,
                    margin: 10,
                    background: "#ffffff" // พื้นหลังขาว
                });
            } catch (e) {
                console.error("Barcode Error:", e);
                document.getElementById(`barcode-canvas-${itemId}`).outerHTML = '<p style="color:red;">เกิดข้อผิดพลาดในการสร้างบาร์โค้ด</p>';
            }
        },
        confirmButtonText: '<i class="fas fa-times"></i> ปิด', 
        showCancelButton: true,
        cancelButtonText: '<i class="fas fa-download"></i> ดาวน์โหลด PNG', // เปลี่ยนปุ่มเป็นดาวน์โหลด
        cancelButtonColor: 'var(--color-success)', // เปลี่ยนสีปุ่มเป็นสีเขียว
        reverseButtons: true // สลับตำแหน่งปุ่มให้ดาวน์โหลดอยู่ขวา (แล้วแต่ชอบ)
    }).then((result) => {
        // 3. เมื่อกดปุ่ม "ดาวน์โหลด PNG" (ซึ่งคือปุ่ม Cancel เดิม)
        if (result.dismiss === Swal.DismissReason.cancel) {
            
            // สร้าง Canvas จำลองในหน่วยความจำเพื่อสร้างรูปสำหรับดาวน์โหลด
            // (วิธีนี้ชัวร์กว่าการดึงจาก DOM ที่กำลังจะปิด)
            const canvas = document.createElement("canvas");
            
            try {
                JsBarcode(canvas, barcodeValue, {
                    format: "CODE128",
                    lineColor: "#000",
                    width: 2,
                    height: 80,
                    displayValue: true,
                    fontSize: 18,
                    margin: 10,
                    background: "#ffffff"
                });

                // แปลงเป็นไฟล์รูปภาพ Base64
                const imgURL = canvas.toDataURL("image/png");

                // สร้างลิงก์สำหรับดาวน์โหลดและกดคลิกอัตโนมัติ
                const downloadLink = document.createElement('a');
                // ตั้งชื่อไฟล์: barcode_ID_ชื่อ.png
                downloadLink.download = `barcode_${itemId}_${itemName.replace(/\s+/g, '_')}.png`;
                downloadLink.href = imgURL;
                
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);

                // แจ้งเตือนว่าดาวน์โหลดแล้ว (Optional)
                const Toast = Swal.mixin({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 3000
                });
                Toast.fire({ icon: 'success', title: 'ดาวน์โหลดบาร์โค้ดเรียบร้อย' });

            } catch (err) {
                Swal.fire('Error', 'ไม่สามารถสร้างไฟล์รูปภาพได้', 'error');
            }
        }
    });
}

// (ฟังก์ชัน History)
function openItemHistoryPopup(itemId, itemName) {
    Swal.fire({
        title: 'กำลังโหลดประวัติ...',
        text: `สำหรับอุปกรณ์: ${itemName}`,
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    fetch(`ajax/get_item_history.php?item_id=${itemId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') {
                throw new Error(data.message);
            }

            let historyHtml = '';
            
            if (data.history.length === 0) {
                historyHtml = '<p style="text-align: center; padding: 1rem 0; color: var(--color-text-muted);">ยังไม่มีประวัติการยืมสำหรับอุปกรณ์ชิ้นนี้</p>';
            } else {
                historyHtml = `
                    <div style="text-align: left; max-height: 40vh; overflow-y: auto; margin-top: 1rem;">
                        <table class="section-card" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>ผู้ยืม</th>
                                    <th>วันที่ยืม</th>
                                    <th>วันที่คืน</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.history.map(row => {
                                    const borrowDate = new Date(row.borrow_date).toLocaleDateString('th-TH', {
                                        day: 'numeric', month: 'short', year: 'numeric'
                                    });
                                    
                                    const returnDate = row.return_date 
                                        ? new Date(row.return_date).toLocaleDateString('th-TH', {
                                            day: 'numeric', month: 'short', year: 'numeric'
                                          }) 
                                        : '<span style="color: var(--color-danger);">(ยังไม่คืน)</span>';

                                    return `
                                        <tr class="cart-table-row">
                                            <td style="color: var(--color-text-dark);">${row.borrower_name}</td>
                                            <td style="color: var(--color-text-dark);">${borrowDate}</td>
                                            <td style="color: var(--color-text-dark);">${returnDate}</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }

            Swal.fire({
                title: `ประวัติการยืม: ${itemName}`,
                html: historyHtml,
                width: '600px',
                confirmButtonText: 'ปิด',
                confirmButtonColor: 'var(--color-primary)'
            });

        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}

