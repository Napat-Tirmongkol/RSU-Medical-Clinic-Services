// [แก้ไขไฟล์: assets/js/student_app.js]

// =========================================
// 1. โค้ดสำหรับ borrow.php (Live Search & Popup)
// =========================================
document.addEventListener('DOMContentLoaded', function() {
    
    // (ตรวจสอบก่อนว่า element ของ borrow.php มีอยู่จริงหรือไม่)
    const searchInput = document.getElementById('liveSearchInput');
    
    // (ถ้าไม่เจอ element เหล่านี้ = ไม่ได้อยู่หน้า borrow.php ก็ให้หยุด)
    if (searchInput) {
        const resultsContainer = document.getElementById('search-results-container');
        const gridContainer = document.getElementById('equipment-grid-container');
        const clearBtn = document.getElementById('clearSearchBtn');
        let searchTimeout; 

        searchInput.addEventListener('keyup', () => {
            clearTimeout(searchTimeout);
            const query = searchInput.value.trim();
            
            if (query.length === 0) {
                hideResults();
                return;
            }

            if (query.length < 2) { 
                 resultsContainer.style.display = 'none';
                 return; 
            }
            
            searchTimeout = setTimeout(() => { performSearch(query); }, 300);
        });

        function performSearch(query) {
            clearBtn.style.display = 'flex';
            gridContainer.style.display = 'none';
            resultsContainer.style.display = 'block';
            resultsContainer.innerHTML = '<p style="padding: 1rem; text-align: center;">กำลังค้นหา...</p>';

            fetch(`ajax/live_search_equipment.php?term=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.results.length > 0) {
                        displayResults(data.results);
                    } else {
                        resultsContainer.innerHTML = '<p style="padding: 1rem; text-align: center;">ไม่พบอุปกรณ์ที่ตรงกับคำค้นหา</p>';
                    }
                })
                .catch(error => {
                    resultsContainer.innerHTML = `<p style="padding: 1rem; text-align: center; color: red;">เกิดข้อผิดพลาด: ${error.message}</p>`;
                });
        }

        function displayResults(results) {
            resultsContainer.innerHTML = ''; 
            results.forEach(item => {
                let imageHtml = ''; 
                if (item.image_url) {
                    imageHtml = `<img src="${escapeJS(item.image_url)}" alt="${escapeJS(item.name)}" class="search-result-image" onerror="this.parentElement.innerHTML = '<div class=\'search-result-image-placeholder\'><i class=\'fas fa-image\'></i></div>'">`;
                } else {
                    imageHtml = `<div class="search-result-image-placeholder"><i class="fas fa-camera"></i></div>`;
                }
                
                const itemHtml = `
                    <div class="search-result-item" role="button" onclick="openRequestPopup(${item.id}, '${escapeJS(item.name)}')">
                        ${imageHtml} <div class="search-result-info">
                            <h4>${item.name}</h4>
                            <p>ว่าง: ${item.available_quantity || 0} ชิ้น</p> 
                        </div>
                    </div>`;
                resultsContainer.innerHTML += itemHtml;
            });
        }

        function hideResults() {
            clearBtn.style.display = 'none';
            resultsContainer.style.display = 'none';
            resultsContainer.innerHTML = '';
            gridContainer.style.display = 'grid'; 
        }

        clearBtn.addEventListener('click', () => {
            searchInput.value = ''; 
            hideResults(); 
        });

        function escapeJS(str) {
            if (!str) return '';
            return str.replace(/'/g, "\\'").replace(/"/g, '\\"');
        }
    } // (จบ if (searchInput))

});

// (JS สำหรับ Popup ยืมของ - เอาไว้นอก DOMContentLoaded ให้อยู่ใน Global Scope)
function openRequestPopup(typeId, typeName) { 
    Swal.fire({
        title: 'กำลังโหลดข้อมูล...',
        text: 'กรุณารอสักครู่',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    }); 
    
    fetch(`ajax/get_staff_list.php`)
        .then(response => response.json())
        .then(data => {
            if (data.status !== 'success') {
                throw new Error(data.message || 'ไม่สามารถดึงรายชื่อพนักงานได้');
            }
            let staffOptions = '<option value="">--- กรุณาเลือก ---</option>';
            if (data.staff.length > 0) {
                data.staff.forEach(staff => {
                    staffOptions += `<option value="${staff.id}">${staff.full_name}</option>`;
                });
            } else {
                staffOptions = '<option value="" disabled>ไม่มีข้อมูลพนักงาน</option>';
            }
            
            // ✅ (1) แก้ไข formHtml 
            const formHtml = `
                <form id="swalRequestForm" style="text-align: left; margin-top: 20px;" enctype="multipart/form-data"> <input type="hidden" name="type_id" value="${typeId}">
                    
                    <div style="margin-bottom: 15px;">
                        <label for="swal_reason" style="font-weight: bold; display: block; margin-bottom: 5px;">1. เหตุผลการยืม: <span style="color:red;">*</span></label>
                        <textarea name="reason_for_borrowing" id="swal_reason" rows="3" required 
                                  style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;"></textarea>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="swal_staff_id" style="font-weight: bold; display: block; margin-bottom: 5px;">2. ระบุพนักงานผู้ให้ยืม (ผู้อนุมัติ): <span style="color:red;">*</span></label>
                        <select name="lending_staff_id" id="swal_staff_id" required 
                                style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                            ${staffOptions}
                        </select>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label for="swal_due_date" style="font-weight: bold; display: block; margin-bottom: 5px;">3. วันที่กำหนดคืน: <span style="color:red;">*</span></label>
                        <input type="date" name="due_date" id="swal_due_date" required 
                               style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 15px; text-align: left;">
        <label for="swal_attachment" style="font-weight: bold; display: block; margin-bottom: 8px;">
            <i class="fas fa-paperclip"></i> แนบไฟล์เอกสาร (ถ้ามี):
        </label>
        
        <input type="file" 
               name="attachment" 
               id="swal_attachment" 
               class="custom-file-input" 
               accept=".pdf, .doc, .docx, .xls, .xlsx, .ppt, .pptx">
               
        <div class="file-help-text">
            <i class="fas fa-info-circle"></i> รองรับเฉพาะ PDF, Word, Excel, PowerPoint
        </div>
    </div>
                </form>`;

            Swal.fire({
                title: `📝 ส่งคำขอยืม: ${typeName}`, 
                html: formHtml,
                width: '600px',
                showCancelButton: true,
                confirmButtonText: 'ยืนยันส่งคำขอ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: 'var(--color-success, #16a34a)',
                focusConfirm: false,
                preConfirm: () => {
                    const form = document.getElementById('swalRequestForm');
                    const reason = form.querySelector('#swal_reason').value;
                    const staffId = form.querySelector('#swal_staff_id').value;
                    const dueDate = form.querySelector('#swal_due_date').value;
                    const typeIdHidden = form.querySelector('input[name="type_id"]').value;
                    
                    if (!reason || !staffId || !dueDate || !typeIdHidden || typeIdHidden == 0) {
                        Swal.showValidationMessage('กรุณากรอกข้อมูลที่มีเครื่องหมาย * ให้ครบถ้วน');
                        return false;
                    }
                    
                    // (โค้ด fetch นี้ไม่ต้องแก้ เพราะ new FormData(form) จะดึงไฟล์ไปเองอัตโนมัติ)
                    return fetch('process/request_borrow_process.php', {
                        method: 'POST',
                        body: new FormData(form)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') {
                            throw new Error(data.message);
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`เกิดข้อผิดพลาด: ${error.message}`);
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire('ส่งคำขอสำเร็จ!', 'คำขอของคุณถูกส่งไปให้ Admin พิจารณาแล้ว', 'success')
                    .then(() => location.href = 'history.php'); 
                }
            });
        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', error.message, 'error');
        });
}
// =========================================
// 2. โค้ดสำหรับ create_profile.php (Validation & Terms)
// =========================================

// (ฟังก์ชันนี้ต้องอยู่ใน Global Scope เพื่อให้ <select onchange="..."> เรียกได้)
function checkOtherStatus(value) {
    var otherGroup = document.getElementById('other_status_group');
    // (ตรวจสอบก่อนว่า otherGroup มีจริงหรือไม่)
    if (!otherGroup) return; 

    if (value === 'other') {
        otherGroup.style.display = 'block';
        document.getElementById('status_other').required = true;
    } else {
        otherGroup.style.display = 'none';
        document.getElementById('status_other').required = false;
    }
}

// (ฟังก์ชันนี้ต้องอยู่ใน Global Scope เพื่อให้ <a href="..."> เรียกได้)
function openTermsPopup() {
    Swal.fire({
        title: 'กำลังโหลดข้อตกลง...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
            // (ใช้ fetch ดึงเนื้อหาจากไฟล์ PHP)
            fetch('terms.php?ajax=1')
                .then(response => response.text())
                .then(htmlContent => {
                    Swal.fire({
                        title: ' ', // (เราใช้ H2 ใน HTML แทน)
                        html: htmlContent,
                        width: '80%', // (กว้าง 80% ของจอ)
                        showCloseButton: true,
                        showConfirmButton: false, // (ไม่มีปุ่ม OK)
                        focusConfirm: false
                    });
                })
                .catch(error => {
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อตกลงการใช้งานได้', 'error');
                });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    
    // ✅ (1) (แก้ไข) เราจะตรวจสอบจาก 'terms_agree' ซึ่งมีแค่ในหน้า create_profile.php
    const termsCheck = document.getElementById('terms_agree');

    // (ถ้าไม่เจอ = เราอยู่หน้า profile.php หรือหน้าอื่น)
    if (termsCheck) {
        
        // (ถ้าโค้ดทำงานต่อ แสดงว่าเราอยู่หน้า create_profile.php แน่นอน)
        const profileForm = document.getElementById('profileForm');
        const submitBtn = document.getElementById('submitBtn'); // <-- ตัวนี้จะไม่ null แล้ว

        submitBtn.disabled = true; // (เริ่มแรกให้ปุ่มกดไม่ได้)

        termsCheck.addEventListener('change', function() {
            if (this.checked) {
                submitBtn.disabled = false; // (เปิดปุ่ม)
            } else {
                submitBtn.disabled = true; // (ปิดปุ่ม)
            }
        });

        submitBtn.addEventListener('click', function(event) {
            event.preventDefault(); // (ป้องกันการ submit จริงก่อน)
            confirmSaveProfile();
        });
    } // (จบ if (termsCheck))
    
});

function confirmSaveProfile() {
    var form = document.getElementById('profileForm');
    if (!form) return; // (Safety check)

    if (!form.checkValidity()) {
        Swal.fire('ข้อมูลไม่ครบ', 'กรุณากรอกช่องที่มีเครื่องหมาย * ให้ครบถ้วน', 'error');
        return;
    }
    
    const termsGroup = document.getElementById('terms_agree_group'); 
    
    if (!document.getElementById('terms_agree').checked) {
        Swal.fire('ข้อผิดพลาด', 'กรุณากดยอมรับข้อตกลงการใช้งานก่อน', 'error');

        termsGroup.classList.add('shake-animation');
        
        setTimeout(() => {
            termsGroup.classList.remove('shake-animation');
        }, 500); 

        return; 
    }

    Swal.fire({
        title: "ยืนยันข้อมูล?",
        text: "กรุณาตรวจสอบข้อมูลของคุณให้ถูกต้อง",
        icon: "info",
        showCancelButton: true,
        confirmButtonColor: "var(--color-success, #28a745)",
        cancelButtonColor: "#d33",
        confirmButtonText: "ใช่, ยืนยัน",
        cancelButtonText: "ยกเลิก"
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });
}


// =========================================
// 3. โค้ดสำหรับ history.php (Cancel Request)
// =========================================
function confirmCancelRequest(transactionId) {
    Swal.fire({
        title: "ยืนยันการยกเลิก?",
        text: "คุณต้องการยกเลิกคำขอยืมนี้ใช่หรือไม่? (อุปกรณ์จะถูกคืนเข้าสต็อก)",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33", // (สีแดง)
        cancelButtonColor: "#3085d6",
        confirmButtonText: "ใช่, ยกเลิกเลย",
        cancelButtonText: "ไม่"
    }).then((result) => {
        if (result.isConfirmed) {
            
            // (แสดง Loading)
            Swal.fire({
                title: 'กำลังยกเลิก...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            // (ส่งข้อมูลไปที่ API ใหม่ที่เราสร้าง)
            const formData = new FormData();
            formData.append('transaction_id', transactionId);

            fetch('process/cancel_request_process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire('ยกเลิกสำเร็จ!', data.message, 'success')
                    .then(() => location.reload()); // (รีโหลดหน้า)
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